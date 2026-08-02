# Architecture

---

## 1. Repository layout

Monorepo ([ADR-0001](adr/0001-monorepo-layout.md)).

```
idle-game/
├── backend/            Symfony 7 / PHP 8.4 application
│   ├── src/
│   │   ├── Feature/            One directory per business domain
│   │   ├── Platform/           Cross-cutting technical capability
│   │   └── Kernel.php
│   ├── config/
│   ├── migrations/
│   └── tests/
├── frontend/           React 19 + TypeScript + Vite
│   └── src/
│       ├── features/           Mirrors backend feature names
│       ├── components/         Shared presentational primitives
│       ├── lib/                API client, query setup, formatting
│       └── routes/
├── content/            Game data — the content designer's workspace
│   ├── items/
│   ├── monsters/
│   ├── quests/
│   ├── disciplines/
│   ├── droptables/
│   ├── affixes/
│   ├── locales/
│   └── config/
├── docker/             Dockerfiles, nginx config
├── docs/               This directory
└── compose.yaml
```

`content/` sits at the root, not inside `backend/`, because it is authored by
designers, validated in its own CI job, and will eventually be consumed by
tooling other than the game server. It is data, not backend code that happens to
be in YAML.

---

## 2. Layers

```
HTTP Controller        Parses the request, calls one handler, serialises the result.
      │                No logic. No entity access. No calculation.
      ▼
Application            Use-case orchestration. Transactions. Authorisation.
      │                Loads aggregates, calls domain, persists, dispatches events.
      ▼
Domain                 Entities, value objects, domain services, domain events.
      │                All business rules. No framework, no persistence, no I/O.
      ▼
Infrastructure         Doctrine repositories, content loaders, message transport,
                       clock, RNG seeding, external services.
```

Dependencies point **inward only**. The Domain layer imports nothing from
Symfony or Doctrine. This is enforced by a `deptrac` ruleset in CI rather than
by discipline, because layering conventions decay under deadline pressure unless
a build fails.

**Controllers coordinate, they never calculate.** A controller that computes
damage, XP, or price is a defect regardless of how small the calculation is.

---

## 3. Feature map

Each feature is a self-contained vertical slice:

```
backend/src/Feature/Combat/
├── Domain/
│   ├── Engine/            CombatEngine, pure resolution (docs/combat.md)
│   ├── Model/             CombatInput, CombatLog, Participant, BattlePlan
│   ├── Event/             EncounterResolved
│   └── Rng/               Counter-based deterministic RNG
├── Application/
│   ├── Command/           StartEncounterHandler
│   └── Query/             GetEncounterLogHandler
├── Infrastructure/
│   └── Doctrine/          EncounterRepository
└── Http/
    └── EncounterController.php
```

Planned features: `Account`, `Character`, `Inventory`, `Combat`, `Encounter`,
`Quest`, `Holding`, `Crafting`, `Progression`, `Guild`, `Leaderboard`, `Shop`.

Cross-cutting concerns live in `Platform/` — `Platform/Content` (loading,
caching, validating `content/`), `Platform/Outbox`, `Platform/Audit`,
`Platform/Clock`, `Platform/Idempotency`. These are technical capabilities, not
business domains, and the distinction is what keeps `Platform/` from becoming
the `Utils/` directory that `CLAUDE.md` forbids: **if it encodes a game rule it
is a Feature; if it would be recognisable in a non-game application it is
Platform.**

### 3.1 Cross-feature communication

Features never call each other's application services directly. Combat does not
call Quest. Combat emits `EncounterResolved`; Quest subscribes.

The one permitted direct dependency is on another feature's **read model** —
a published, stable query interface. Quest may ask Character for a level. It may
not mutate it.

---

## 4. Domain events

Two delivery paths, chosen per handler ([ADR-0004](adr/0004-transactional-outbox.md)):

| Path | Use for | Guarantee |
|---|---|---|
| **Synchronous, in-transaction** | Effects that must be atomic with the action: XP, gold, loot, inventory, quest counters | All-or-nothing with the originating command |
| **Transactional outbox → Messenger** | Everything else: achievements, guild feed, leaderboard refresh, analytics, notifications | At-least-once, eventually |

Getting this split wrong is a rewrite, not a refactor, which is why it is decided
before any handler exists. The test: **would a player notice, and consider it a
bug, if this effect were missing for thirty seconds?** If yes, it is synchronous.

Async handlers must be **idempotent**, because at-least-once delivery means
duplicates will happen.

Core events: `CharacterCreated`, `EncounterResolved`, `MonsterKilled`,
`PlayerLeveledUp`, `ItemEquipped`, `ItemRefined`, `QuestProgressed`,
`QuestCompleted`, `HoldingClaimed`, `CurrencyChanged`, `DungeonFinished`.

Events are **immutable value objects containing ids and primitives** — never
Doctrine entities. An entity in an event is a reference to mutable state that
may have changed by the time an async handler reads it.

---

## 5. Server authority

Every gameplay-relevant value originates on the server. The client is a
rendering and input surface.

Enforced at the application boundary, checked on every mutating request:

1. **Authentication** — is there a valid session?
2. **Ownership** — does this character belong to this account? Does this item
   belong to this character? Never inferred from the request body.
3. **State validity** — is the transition legal from the current state?
4. **Resource sufficiency** — enough Vigor, gold, materials?
5. **Idempotency** — has this request already been applied?

A request that fails any check returns a structured error and is logged if the
failure suggests tampering rather than a race.

---

## 6. Content pipeline

```
content/*.yaml
    │  JSON Schema validation          (CI — malformed content fails the build)
    │  Referential integrity check     (CI — every referenced id exists)
    │  Balance assertions              (CI — progression simulator tolerances)
    ▼
Platform/Content loader → compiled, cached, immutable in-memory registry
    ▼
Domain services read definitions through typed repositories
```

Content is **never read from disk during a request**. It is compiled at deploy
time and served from a warm cache. Content ids are **append-only**: definitions
may be retired but never deleted or renumbered, because live item instances,
stored combat logs and audit records reference them indefinitely.

---

## 7. Frontend boundary

See [frontend-architecture.md](frontend-architecture.md). The rules that matter
architecturally:

- **Server state → TanStack Query. Local UI state → Zustand.** No duplication,
  and specifically: no copying server data into a Zustand store.
- The frontend contains **no authoritative gameplay logic**. It may *display* a
  predicted value (an estimated repair cost, a projected accrual) but must label
  it as an estimate and must never submit a computed value as fact.
- The combat replay renders a **server-produced log**. It does not simulate.
- API types are **generated** from the backend's OpenAPI schema, not hand-written.
  Hand-maintained duplicates of a contract drift, silently, and the divergence is
  discovered by players.

---

## 8. Testing strategy

| Layer | Tooling | Requirement |
|---|---|---|
| Domain | PHPUnit, no kernel | Every rule. Fast — the whole suite in seconds |
| Combat engine | PHPUnit + golden replays + property tests | See [combat.md](combat.md) §8 |
| Application | PHPUnit with kernel, real Postgres | Every use case, including authorisation failures |
| HTTP | Functional tests | Contract shape and error codes |
| Content | Schema + integrity + balance | Runs on every content change |
| Frontend | Vitest + Testing Library | Component logic and hooks |

Two guard tests that protect the architecture itself:

- **Layering:** `deptrac` fails the build on an inward-dependency violation.
- **Combat purity:** fails if the combat engine namespace acquires a dependency
  on Doctrine, the container, or the clock.

Both exist because these properties are the ones that erode invisibly, one
reasonable-looking commit at a time.

---

## 9. First implementation milestone

Design phase is complete when the documents in [README.md](README.md) are agreed.
The first code milestone — **Vertical Slice 0** — is deliberately narrow and is
chosen to exercise every architectural claim at least once:

1. **Infrastructure.** `compose.yaml` bringing up php-fpm, nginx, Postgres 17 and
   Vite. `make up` works from a clean checkout on Windows, macOS and Linux.
2. **Content pipeline.** Loader, JSON Schema validation, CI job. Three items,
   two monsters, one drop table as the initial corpus.
3. **Combat engine.** The pure engine, the counter-based RNG, the battle plan
   evaluator, the damage pipeline, and the full test regime from
   [combat.md](combat.md) §8. **No HTTP, no database.** This is built first
   because it is the hardest thing to retrofit purity into.
4. **Account + Character.** Registration, login, character creation, attribute
   allocation.
5. **One encounter endpoint.** `POST /encounters` — validates Vigor, resolves a
   fight through the engine, persists seed + ruleset + snapshot + log, grants XP
   and gold in one transaction, emits `EncounterResolved` through the outbox.
6. **Minimal frontend.** Login, character sheet, a fight button, and a combat log
   replay that shows which battle plan rule fired each turn.

What is deliberately **excluded** from Slice 0: the Holding, refinement,
quests, guilds, the shop, and all art. Those are additive; the six items above
are the load-bearing ones. If Slice 0 is right, the rest is content and features.
If Slice 0 is wrong, everything built on it has to move.
