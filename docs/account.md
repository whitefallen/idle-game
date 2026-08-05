# Account & Authentication

Registration, login and the session that everything else in this API depends
on. `backend/src/Feature/Account/` owns the account itself; the security
integration around it lives in `Infrastructure/Security/`.

---

## 1. Registration

`POST /api/v1/auth/register` — `{ email, password }`.

**Email** is normalised before anything else touches it: lower-cased, trimmed,
and validated with `FILTER_VALIDATE_EMAIL`. Max **180 characters**. The
normalisation happens in a value object (`Email`) rather than relying on
Postgres `CITEXT`, so two registrations differing only in case are the *same*
string by the time either reaches the database — the unique index is a real
guarantee, not an application check a future code path could skip.

**Password** has one rule: **at least 10 characters.** No required digit,
symbol or capital. Composition rules measurably push people toward predictable
substitutions and away from password managers, so length is the requirement
that actually helps; that reasoning is recorded in code, not just here.
Hashed with Symfony's `auto` algorithm (currently Argon2id where available),
behind a `PasswordHasher` interface — the concrete algorithm is named in
exactly one place and can be upgraded without touching a handler.

**Duplicate email** is rejected twice, on purpose: an `existsByEmail` check
before any work is done (the common case, reported cleanly), and the
database's unique constraint as the actual guarantee, converted to the same
`EMAIL_ALREADY_REGISTERED` error if two registrations for the same address
race each other past the first check.

On success: an `Account` is created with `status = Active`, the registration
is audited (`AuditAction::AccountRegistered`, staged with the transaction —
if the commit fails, no record claims an account that does not exist), and
the response is `201` with the new account's id and email. Nothing is
auto-logged-in; registration and login are separate requests.

---

## 2. Login

`POST /api/v1/auth/login` — `{ email, password }`, handled entirely by
Symfony's `json_login` firewall listener, not by application code. The
`AuthController::login` method exists only so the route resolves before the
firewall runs; the request never reaches it.

Two things are true regardless of *why* a login fails, and both are
deliberate:

- **The error is identical for an unknown email and a wrong password** —
  `INVALID_CREDENTIALS`, always. Distinguishing them would let a client
  enumerate which addresses are registered.
- **A suspended or deleted account fails the same way.** `AccountUserProvider`
  refuses to load a user whose `status` cannot authenticate
  (`AccountStatus::canAuthenticate()`, true only for `Active`), and the
  provider raises the same "user not found" the security component would
  raise for an email that was never registered.

The account is **reloaded from storage on every request**, not trusted from
the session — so suspending an account takes effect on its very next request,
not at its next login.

On success the response is `200` with the account's id and email, and
`AuditAction::AuthenticationSucceeded` is recorded immediately (`recordNow`,
not staged — there is no transaction to join, and login is a security event
regardless of what else happens in the request). On failure,
`AuditAction::AuthenticationFailed` is recorded immediately with the
exception class as `reason` — the account is deliberately *not* resolved
first, because doing so to write a log line would itself confirm which
addresses are registered.

`POST /api/v1/auth/logout` is likewise handled by the firewall
(`invalidate_session: true`) rather than by a controller action.

---

## 3. Rate limiting

Two independent limiters, both required by [api.md](api.md) §5:

| | Scope | Limit | Window | On the endpoint |
|---|---|---|---|---|
| **Registration** | Client IP | 5 | 1 hour, sliding | `POST /auth/register` |
| **Login** | Client IP *and* the submitted email, independently | 5 attempts | 15 minutes | `POST /auth/login` |

Login throttling is Symfony's built-in `login_throttling`, keyed on both
dimensions so one account cannot be brute-forced from many addresses and many
accounts cannot be probed from one.

**The registration limiter is consumed before any other work happens** —
before the email is even parsed. Password hashing is deliberately expensive;
if the limiter were checked after it, an attacker could force a hash
computation on every request, which would be the cheapest denial of service
available against this endpoint. A blocked request returns `429` with a
`Retry-After` header and a `retry_after` seconds figure in the error details.

**Known asymmetry:** a login throttle is audited
(`AuditAction::RateLimitExceeded`) because repeated throttling is itself a
signal worth alerting on. A registration throttle is not — `AuthController`
throws the `429` directly with no corresponding audit call. Worth closing if
registration abuse ever needs the same aggregate visibility login abuse
already has.

---

## 4. Error contract

| Code | HTTP | When |
|---|---|---|
| `VALIDATION_FAILED` | 422 | Malformed email, or a password under 10 characters |
| `EMAIL_ALREADY_REGISTERED` | 409 | That address already has an account |
| `INVALID_CREDENTIALS` | 401 | Any login failure — unknown email, wrong password, or an account that cannot authenticate |
| `RATE_LIMITED` | 429 | Registration or login limiter tripped |
| `AUTHENTICATION_REQUIRED` | 401 | No session, on any endpoint other than register/login |
| `FORBIDDEN` | 403 | A session exists but the action is not permitted — never used for "not logged in" |

`AUTHENTICATION_REQUIRED` and `FORBIDDEN` are deliberately different codes
from different handlers (`ApiEntryPoint` vs. `ApiAccessDeniedHandler`): a
client needs to know whether to show a login screen or not, and collapsing
both into one "access denied" response — the security component's default —
loses that distinction. See [api.md](api.md) §3.

---

## 5. What is public

Every route under `/api` requires a session (`ROLE_USER`) except two, listed
explicitly in `security.yaml`'s `access_control`:

- `POST /api/v1/auth/register`
- `POST /api/v1/auth/login`
- `GET /api/v1/battle-plan/grammar` — static content with no user data, public
  so it can genuinely be cached rather than merely happening to look the same
  for everyone.

`GET /api/v1/auth/me` requires a session and returns the current account's
id, email and `created_at` — it is how the frontend answers "am I logged in"
on load (`useSession`, which treats `AUTHENTICATION_REQUIRED` as "signed out",
not as an error).

---

## 6. Known gaps

Recorded rather than quietly tolerated, matching [content.md](content.md) §5's
convention:

- **No email verification.** `Account.emailVerifiedAt` exists and is read
  nowhere else; nothing ever sets it. Registration succeeds on a syntactically
  valid address alone.
- **No self-service password reset or change.** `Account::changePassword()`
  exists on the entity; no endpoint calls it.
- **No admin or moderation surface.** `Account::suspend()` exists and
  `AccountStatus::Suspended` is fully honoured by login and by every
  already-authenticated request — but nothing in the API can put an account
  into that state. It is reachable only by hand, against the database.
- **Registration throttling is not audited**, unlike login throttling (§3).

None of these are blocked; they simply have not been needed yet.
