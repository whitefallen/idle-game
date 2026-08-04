# API Design

REST over JSON. WebSockets only where polling is genuinely inadequate — at
present, nothing in the design qualifies, so there are none.

---

## 1. Conventions

- Base path `/api/v1`. The version is in the path, not a header, so that a URL in
  a log or a bug report is unambiguous.
- Resource paths are plural nouns: `/characters`, `/encounters`, `/items`.
- Verbs live in the HTTP method. Where an action genuinely is not CRUD, it is a
  sub-resource: `POST /characters/{id}/respec`, not `POST /doRespec`.
- `snake_case` in JSON bodies, matching the database and avoiding a translation
  layer whose only product is bugs.
- Timestamps are RFC 3339 UTC with an explicit offset.
- Integer money and quantities, always. Never a JSON float for a game value.

---

## 2. Response envelope

Success:

```json
{
  "data": { "…": "…" },
  "meta": { "server_time": "2026-08-02T10:14:33Z" }
}
```

Error:

```json
{
  "error": {
    "code": "INSUFFICIENT_VIGOR",
    "message": "Not enough Vigor to start this encounter.",
    "details": { "required": 10, "available": 4 }
  }
}
```

`code` is a stable machine-readable enum — the client branches on it and never
on `message`. `message` is a developer-facing English fallback; **player-facing
text is resolved client-side from a localisation key derived from the code**, so
the API never has to know the player's language.

`details` carries structured context the UI can render ("you need 6 more Vigor,
ready in 36 minutes"). This is what makes an error actionable instead of a dead
end.

**Internal exceptions are never exposed.** No stack traces, no SQL, no class
names, in any environment reachable by a player. Unhandled errors return
`INTERNAL_ERROR` with a correlation id, and the id appears in the server log.

---

## 3. Status codes

| Code | Used for |
|---|---|
| 200 | Successful read or update |
| 201 | Resource created (with `Location`) |
| 204 | Successful delete |
| 400 | Malformed request |
| 401 | Missing or invalid authentication |
| 403 | Authenticated but not permitted — including ownership failures |
| 404 | Not found, **or** not owned by the caller (see §5) |
| 409 | State conflict — already claimed, slot occupied, idempotency mismatch, an activity already in progress |
| 422 | Well-formed but semantically invalid — insufficient resources, unmet requirement |
| 429 | Rate limited, with `Retry-After` |

The 403/404 distinction is a security decision, covered in §5.

`ACTIVITY_IN_PROGRESS` is deliberately a 409 rather than a 429. It is a state
condition — this character is busy, and the response says for how long — not
throttling. 429 would invite the generic backoff-and-retry that HTTP clients
implement automatically, when the correct behaviour is to wait the stated
interval and show a countdown. Its `details` carry `seconds_remaining` and
`ready_at`; see [idle.md](idle.md) §4.1.

---

## 4. Idempotency

Every mutating request accepts `Idempotency-Key`. Required on requests that
grant or consume resources: encounters, claims, purchases, refinement, crafting.

A replay with the same key returns the original response with
`Idempotency-Replayed: true`. The same key with a different body returns 409.

This is not optional polish. Mobile browsers retry, players double-tap, and
networks drop responses after the server has committed. Without idempotency each
of those is a duplicate award or a lost one.

---

## 5. Authorisation

Every request touching a character verifies ownership from the **session**, never
from the request body. `GET /characters/{id}` for a character owned by another
account returns **404, not 403** — a 403 confirms the resource exists, which
turns any id endpoint into an enumeration oracle. UUIDv7 ids are not secrets, but
they should not be a directory either.

Rate limits are per account and per IP, tighter on mutating endpoints. Login and
registration are rate-limited aggressively and every failure is audited.

---

## 6. Representative endpoints

```
POST   /api/v1/auth/register
POST   /api/v1/auth/login
POST   /api/v1/auth/logout

GET    /api/v1/characters
POST   /api/v1/characters
GET    /api/v1/characters/{id}
POST   /api/v1/characters/{id}/attributes      allocate points
POST   /api/v1/characters/{id}/respec

GET    /api/v1/characters/{id}/inventory
POST   /api/v1/items/{id}/equip
POST   /api/v1/items/{id}/unequip
POST   /api/v1/items/{id}/refine
POST   /api/v1/items/{id}/repair

GET    /api/v1/characters/{id}/battle-plans
PUT    /api/v1/characters/{id}/battle-plans/{planId}

POST   /api/v1/encounters                      resolve a fight
GET    /api/v1/encounters/{id}                 full replay log

GET    /api/v1/characters/{id}/holding
POST   /api/v1/characters/{id}/holding/claim
PUT    /api/v1/characters/{id}/holding/slots/{index}

GET    /api/v1/quests
POST   /api/v1/quests/{id}/accept
POST   /api/v1/quests/{id}/complete

GET    /api/v1/leaderboards/power
```

`POST /encounters` returns the created encounter **including the full log**, so
the client can replay immediately without a second round trip.

---

## 7. Time and the client

Every response carries `meta.server_time`. Anything time-dependent — Vigor
regeneration, Holding accrual, cooldowns — is returned as **server timestamps
plus rates**, and the client interpolates for display only.

The client never sends a timestamp, and the server never reads one from a
request. See [idle.md](idle.md) §5, rule T1.

Responses that include an accruing resource also include its projected state
(`vigor_current`, `vigor_max`, `vigor_full_at`) so the UI can render a live
countdown from one payload, without polling.

---

## 8. Versioning and the schema contract

The API publishes an **OpenAPI 3.1 schema, generated from the code**, and the
frontend's TypeScript types are generated from that schema. Hand-written client
types drift from the server and the drift is discovered by players.

Within `v1`, changes must be additive: new optional fields and new endpoints are
fine; removing a field, narrowing a type, or changing an error code's meaning is
a breaking change and requires `v2`. A contract test in CI fails the build on a
breaking change to the published schema, so the decision to break is always
explicit.
