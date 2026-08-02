# Emberwatch — Design Documentation

> **Emberwatch** is a working codename. It appears only in prose, never in code
> identifiers or database names, so renaming is a single find/replace.

This directory is the authoritative design record. Code is expected to follow
these documents; where code and documentation disagree, that is a defect in one
of them and must be resolved, not ignored.

## Reading order

For someone new to the project, read in this order:

| # | Document | What it answers |
|---|----------|-----------------|
| 1 | [game-bible.md](game-bible.md) | What the game is, who it is for, what the core loop is, how it differs from its inspirations |
| 2 | [progression.md](progression.md) | Levels, attributes, derived stats, disciplines, content gating |
| 3 | [combat.md](combat.md) | The deterministic combat specification — formulas, randomness, replay |
| 4 | [items.md](items.md) | Item taxonomy, rarity, affixes, refinement, data schema |
| 5 | [idle.md](idle.md) | The passive accrual layer and its anti-exploit rules |
| 6 | [economy.md](economy.md) | Currencies, faucets, sinks, long-term stability, monetization |
| 7 | [architecture.md](architecture.md) | Layers, feature map, domain events, repository layout |
| 8 | [data-model.md](data-model.md) | Database schema and indexing strategy |
| 9 | [api.md](api.md) | REST conventions, error contract, versioning |
| 10 | [frontend-architecture.md](frontend-architecture.md) | State ownership, replay rendering, UI principles |

## Architecture Decision Records

Significant, hard-to-reverse decisions live in [adr/](adr/). Each records the
context, the decision, the alternatives rejected, and the consequences we accept.

| ADR | Decision |
|-----|----------|
| [0001](adr/0001-monorepo-layout.md) | Monorepo layout |
| [0002](adr/0002-integer-deterministic-combat.md) | Integer-only combat math with seeded, versioned randomness |
| [0003](adr/0003-passive-accrual-idle-model.md) | Passive resource accrual instead of offline combat simulation |
| [0004](adr/0004-transactional-outbox.md) | Transactional outbox for cross-feature domain events |
| [0005](adr/0005-uuidv7-primary-keys.md) | Time-ordered UUIDv7 primary keys |
| [0006](adr/0006-denormalised-power-score.md) | A named exception to "never store derived values" |

## Status

Design phase. No application code exists yet. The first implementation
milestone is defined at the end of [architecture.md](architecture.md).

## Conventions used in these documents

- **bp** means *basis points*: 1 bp = 0.01%, so 10000 bp = 100%. All fractional
  gameplay values are expressed and stored in basis points as integers. See
  [ADR-0002](adr/0002-integer-deterministic-combat.md) for why.
- Numbers marked `(tunable)` live in configuration files, not in code, and are
  expected to change during balancing. Numbers not so marked are structural and
  changing them implies a design change.
- `L` always denotes character level.
