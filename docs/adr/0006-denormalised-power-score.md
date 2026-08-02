# ADR-0006 — A named exception to "never store derived values"

**Status:** Accepted · 2026-08-02

## Context

`CLAUDE.md` states that derived values should be calculated rather than stored.
This is correct and it is the default throughout the schema: health, damage,
critical chance, armour and resistances are all computed from attributes and
equipment on read ([progression.md](../progression.md) §3).

The rule collides with two features that are certain to exist.

**Leaderboards.** Ranking players by power requires ordering hundreds of
thousands of characters. Computing power per row means, for each character,
loading every equipped item, resolving each item's content definition, applying
affixes and refinement, and running the derived-stat formulas. There is no query
plan that makes this acceptable, and no amount of caching helps the *ordering*
step.

**Matchmaking.** Arena opponent selection needs to find characters within a
power band. That is a range query, which requires an indexed column.

Left unresolved, this rule gets quietly broken under deadline pressure by
whoever implements leaderboards, and the exception ends up undocumented,
unbounded, and copied.

## Decision

A **single, explicitly enumerated** set of denormalised derived columns:

| Column | Table | Purpose |
|---|---|---|
| `power_score` | `character` | Leaderboard ordering, arena matchmaking |

`power_score` is a weighted, deliberately lossy summary of a character's
strength. It is **advisory**: it is used for ordering and matchmaking only, and
never as an input to combat, rewards, or any gameplay calculation. Nothing a
player receives depends on it being exactly right.

It is recomputed by a synchronous handler on `ItemEquipped`, `ItemRefined`,
`PlayerLeveledUp` and attribute allocation — the complete set of events that can
change it.

Adding a column to this table requires a new ADR. The set is closed by default.

## Alternatives considered

**Compute on read.** The default rule. Rejected for these two features only, for
the reasons in the context.

**A materialised view refreshed periodically.** Rejected: refresh cost grows with
player count regardless of how many characters actually changed, staleness is
hard to reason about, and Postgres materialised view refresh takes a lock that
is awkward on a table this central.

**A separate `character_power` table.** Considered and rejected as ceremony
without benefit — it is a 1:1 table with the same update pattern, and it adds a
join to the leaderboard query that the design exists to make fast.

**A cache (Redis) instead of a column.** Rejected: ordering and range queries
over hundreds of thousands of entries are what a B-tree index is for, and a cache
introduces a second source of truth with its own invalidation failure modes.

## Consequences

**Accepted:**

- Leaderboards and matchmaking are single indexed queries.
- The exception is documented, bounded and discoverable, so future contributors
  extend it deliberately rather than by precedent.

**Costs:**

- `power_score` can drift from truth if a recomputation handler is missed when a
  new power source is added. Mitigated by a nightly reconciliation job that
  recomputes and reports mismatches, and by an integration test asserting that
  every event which changes power triggers recomputation.
- Recomputation adds work to the equip path. It is bounded (ten items, one
  character) and synchronous, which is the correct trade for a value read far
  more often than it is written.
- Weighting is a balance decision that will need revisiting; because the value
  is advisory, changing the weights is safe and requires no migration beyond a
  backfill.
