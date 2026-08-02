# ADR-0003 — Passive resource accrual instead of offline combat simulation

**Status:** Accepted · 2026-08-02

## Context

The game must be idle-friendly: absence should produce value. Three models were
available.

The tension is that combat is deliberately expensive to compute correctly
([ADR-0002](0002-integer-deterministic-combat.md)) and offline periods are
unbounded in length.

## Decision

Offline time accrues **materials and gold** through the Holding, using a
closed-form calculation over clamped elapsed time. Combat is **active-only** and
is never simulated while a player is away.

To keep the passive layer from being trivial, its output is the primary input to
**item refinement** — the game's largest long-horizon power sink — rather than
raw generic currency.

## Alternatives considered

**Offline combat simulation.** The same deterministic engine batch-runs
encounters on login. Numerically consistent with active play, which is its real
attraction. Rejected on cost and risk: a twelve-hour absence implies thousands
of simulations inside a login request, which is a latency and CPU problem that
grows with both player count and absence length, and which is worst precisely
at the daily peak when everyone logs in. Mitigating it means asynchronous jobs,
progress states and a "your results are being calculated" screen — substantial
complexity in the most-used path in the game. It is also the closest option to
genre convention and contributes least to the game's identity.

**Closed-form expected-value combat.** Offline yields approximate what fighting
would have produced. Rejected: the approximation visibly diverges from actual
combat results, players notice within days, and the divergence reads as the game
cheating them. It also requires maintaining a second model of combat that must
be kept in agreement with the first — a permanent balance liability.

## Consequences

**Accepted:**

- Claiming is O(1). A player returning after six months costs the same to serve
  as one returning after an hour.
- No background jobs, no per-player scheduled work, no drift, no missed runs.
  State is `(lastClaimedAt, rate)`; the balance is derived on read.
- Offline value is trivially auditable and hard to exploit, given the anti-exploit
  rules in [idle.md](../idle.md) §5.
- Combat stays a single implementation with a single performance profile.

**Costs — stated plainly:**

- **This is the thinnest of the three models by default.** Offline play risks
  feeling like a button rather than an activity. The mitigation is structural
  (accrual feeds refinement, so the resource is spent with intent on a project
  the player chose) but it is a mitigation, not an elimination.
- **Idle play is not self-sufficient.** A player who never fights accrues
  materials they cannot fully use. This is intentional — it is what makes the
  playstyles interdependent — but it means the game cannot be marketed as
  playable while idle.
- **The Holding will need its own depth within months.** Upgrades, staff and
  supply runs are sketched in [idle.md](../idle.md) §6 and should be treated as
  scheduled work, not as optional polish.

## Revisit criteria

Reconsider if retention data shows idle-leaning players churning at a materially
higher rate than active players, or if session length data shows players logging
in, claiming, and leaving without engaging the investment step. Either would
indicate the structural mitigation is not working.
