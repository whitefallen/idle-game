# ADR-0002 — Integer-only combat math with seeded, versioned randomness

**Status:** Accepted · 2026-08-02

## Context

Combat decides rewards, progression and PvP outcomes. Players dispute results.
Support needs to answer "why did I lose?" with evidence. Balance changes ship
regularly, and each one changes the rules that past fights were resolved under.

"Deterministic combat" is stated as a project pillar, but the naive reading —
"the code has no `rand()` in the request path" — does not deliver any of the
properties actually wanted. A fight is only genuinely reproducible if the
randomness, the rules and the inputs are all recoverable.

## Decision

Four coupled commitments:

1. **The combat engine is a pure function**
   `resolve(CombatInput, Seed, RulesetVersion) → CombatLog`, with no database,
   clock, container, filesystem or static mutable state.
2. **All arithmetic is 64-bit integer**, with fractional values in basis points
   and truncating division at every step. No floating point anywhere in the
   engine.
3. **Randomness is counter-based**, derived per roll site from
   `(seed, round, actor, purposeId, rollIndex)` via splitmix64 — not drawn
   sequentially from a stateful generator.
4. **Every encounter persists** `seed`, `ruleset_version`, `input_snapshot` and
   the resulting `log`.

## Alternatives considered

**Floating-point math.** Rejected. Float results are not reliably identical
across platforms, PHP builds and optimisation states, so a "deterministic"
system built on them is deterministic only until the hosting changes. Rounding
artefacts in damage calculation are also unusually hard to debug because the
symptom is a value that is almost right.

**A stateful sequential PRNG seeded per encounter.** Rejected, and this is the
subtlest of the four decisions. With a sequential generator, adding any new roll
site — one new ability, one extra check — shifts every subsequent draw. The
consequence is that a minor feature silently changes the outcome of every stored
replay and every balance test. Counter-based derivation makes each roll site
independent, so new roll sites can be added without disturbing existing ones.

**Storing only the seed, not the input snapshot.** Rejected. Without the
snapshot, re-equipping an item invalidates every past fight involving it, and
the reproducibility claim cannot actually be checked.

**Re-simulating fights on the client for the replay animation.** Rejected. It
would require bit-exact PRNG parity between PHP and TypeScript — a well-known
source of desync bugs — and it would let a client compute future rolls. The
client renders the recorded log instead.

## Consequences

**Accepted:**

- Combat is testable in microseconds with no framework, so thousands of balance
  scenarios run in CI.
- Any fight is reproducible on any machine, years later, for support and audit.
- Active encounters, arena defence, guild battles and the balance simulator all
  share one implementation, so there is one place for combat bugs to exist.
- The client cannot see future randomness.

**Costs:**

- Integer basis-point math is more verbose and less obvious to read than decimal
  formulas. Mitigated by keeping all formulas in one documented place
  ([combat.md](../combat.md) §6).
- splitmix64 requires manual 64-bit wrapping arithmetic in PHP, since native
  integer overflow promotes to float. Roughly fifteen lines, written once,
  exhaustively tested against reference vectors.
- Storing input snapshots and logs costs significant storage. Addressed by
  compression, partitioning and a 90-day retention policy
  ([data-model.md](../data-model.md) §6).
- Every balance change requires a ruleset version bump and regenerated golden
  fixtures. This is a feature — it makes balance changes visible in review — but
  it is friction.

## Enforcement

A CI test fails the build if the combat engine namespace acquires a dependency
on Doctrine, the container or the clock. Purity is not preserved by intent; it
erodes one convenient injection at a time unless a build fails.
