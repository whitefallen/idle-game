# Architecture Decision Records

Significant, hard-to-reverse decisions live here. Each records the context, the
decision, the alternatives rejected, and the consequences we accept.

| ADR | Decision |
|-----|----------|
| [0001](0001-monorepo-layout.md) | Monorepo layout |
| [0002](0002-integer-deterministic-combat.md) | Integer-only combat math with seeded, versioned randomness |
| [0003](0003-passive-accrual-idle-model.md) | Passive resource accrual instead of offline combat simulation |
| [0004](0004-transactional-outbox.md) | Transactional outbox for cross-feature domain events |
| [0005](0005-uuidv7-primary-keys.md) | Time-ordered UUIDv7 primary keys |
| [0006](0006-denormalised-power-score.md) | A named exception to "never store derived values" |
| [0007](0007-synchronous-domain-event-bus.md) | A synchronous in-process bus for atomic cross-feature effects |
