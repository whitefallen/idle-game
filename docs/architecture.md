# Architecture

---

## 1. Repository layout

Monorepo ([ADR-0001](adr/0001-monorepo-layout.md)).

```
idle-game/
├── backend/            Symfony 7.4 LTS / PHP 8.4 application
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
│   ├── dungeons/
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

### 1.1 A note on development I/O

The stack bind-mounts source into the containers so edits are live. On Linux
this is free; on Windows and macOS it is not, because every filesystem call
crosses the boundary into the Linux VM.

Measured in this project: **2.4 ms per `stat` across the bind mount against
0.002 ms on a native volume** — roughly 1200× slower. A Symfony dev request
touches on the order of a thousand files, which is why a request that takes
10 ms in-process can take seconds through php-fpm.

What the repository already does about it: `var/` lives on a named volume,
Xdebug is off unless `XDEBUG_MODE` says otherwise, and opcache revalidates at
most every two seconds. These help but cannot remove the cost.

One visible symptom: the **first** request after `cache:clear` pays the whole
container compile over that filesystem and can exceed nginx's 30-second
`fastcgi_read_timeout`, returning a 504 even though the request completed
server-side. Run `bin/console cache:warmup` after clearing, or simply issue the
first request and ignore it. The timeout is deliberately not raised — 30 seconds
is the right ceiling in production, where a request that slow is a fault.

**The durable fix is host-side**: keep the working copy on a filesystem the
Linux VM owns. On Windows that means cloning inside WSL2 (`\\wsl$\...`) rather
than under `C:\Users`. Production is unaffected — it runs on Linux with the
source baked into the image and `opcache.validate_timestamps=0`.

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

Built: `Account`, `Character`, `Inventory`, `Combat`, `Encounter`, `Holding`,
`Quest`, `Dungeon`.
Not yet built: `Leaderboard` — see §9.1
for what each system built so far covers and
[account.md](account.md) §6 / [items.md](items.md) §9.4 / [idle.md](idle.md)
§7.3 for what each one still does not.

Cross-cutting concerns live in `Platform/` — `Platform/Content` (loading,
caching, validating `content/`), `Platform/Outbox`, `Platform/Audit`,
`Platform/Clock`, `Platform/Idempotency`. These are technical capabilities, not
business domains, and the distinction is what keeps `Platform/` from becoming
the `Utils/` directory that `CLAUDE.md` forbids: **if it encodes a game rule it
is a Feature; if it would be recognisable in a non-game application it is
Platform.**

### 3.1 Cross-feature communication

Features never call each other's application services directly, in principle:
Combat does not call Quest, and a feature reacting to another's outcome does
so by subscribing to its event rather than being called into.

The one permitted direct dependency is on another feature's **read model** —
a published, stable query interface. Quest may ask Character for a level. It may
not mutate it.

In practice this rule already has named exceptions, carried as documented debt
rather than silently broken — see [ADR-0007](adr/0007-synchronous-domain-event-bus.md)'s
Costs section for the running list. Quest and Dungeon add to it deliberately:
both call `CharacterParticipantFactory` (Encounter/Application) directly to
build a combat participant, and Quest's and Dungeon's reward granting calls
`GrantMaterialsHandler` and `ResolveDropsHandler` (Inventory/Application)
directly, for the same reason the three original exceptions exist — the
alternative is an event whose subscriber has to hand a value back to the
emitter, which ADR-0007 already rejected as a return value wearing a costume.

This holds for **both** delivery paths in §4. The deferred path uses the outbox;
the atomic path uses `Platform\Event\DomainEventDispatcher`, which publishes
synchronously inside the caller's transaction ([ADR-0007](adr/0007-synchronous-domain-event-bus.md)).
A synchronous subscriber **may not return a value** to the emitter — where the
emitter needs to know what a subscriber did, it reads that feature's read model
afterwards. `RespecHandler` is the worked example: it announces the reset and
then diffs the equipped set, rather than being told what Inventory removed.

Two direct cross-feature calls predate the dispatcher and have not been migrated
— `ClaimHoldingHandler` → `GrantMaterialsHandler` and `ResolveEncounterHandler`
→ `ResolveDropsHandler`. Both feed the callee's return value into an outbox
event, so converting them means solving the no-return-value rule for each.
ADR-0007 records this as known debt; new work uses the dispatcher.

---

## 4. Domain events

Two delivery paths, chosen per handler ([ADR-0004](adr/0004-transactional-outbox.md)):

| Path | Use for | Guarantee |
|---|---|---|
| **Synchronous, in-transaction** (`DomainEventDispatcher`) | Effects that must be atomic with the action: XP, gold, loot, inventory, quest counters | All-or-nothing with the originating command |
| **Transactional outbox → Messenger** | Everything else: achievements, leaderboard refresh, analytics, notifications | At-least-once, eventually |

Getting this split wrong is a rewrite, not a refactor, which is why it is decided
before any handler exists. The test: **would a player notice, and consider it a
bug, if this effect were missing for thirty seconds?** If yes, it is synchronous.

Async handlers must be **idempotent**, because at-least-once delivery means
duplicates will happen.

Core events: `CharacterCreated`, `CharacterRespecced`, `EncounterResolved`,
`MonsterKilled`, `PlayerLeveledUp`, `ItemEquipped`, `ItemRefined`,
`QuestProgressed`, `QuestCompleted`, `HoldingClaimed`, `CurrencyChanged`,
`DungeonFinished`.

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
  predicted value (a projected accrual, an interpolated Vigor bar) but must label
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

### 8.1 Continuous integration

Three workflows in `.github/workflows/`, path-filtered per ADR-0001 so a
frontend-only change does not run PHPUnit.

**Backend** (`backend/**`, `content/**`, `docker/**`, `compose.yaml`) runs
inside the project's own PHP image rather than a runner-native PHP, so CI
exercises the same version, extensions and `php.ini` that development and
production use. In order: dependency audit, schema-matches-entities, content
validation, PHPStan, deptrac, then the full test suite — which carries the
golden combat replays, the engine purity guard and the balance simulator.

**Frontend** (`frontend/**`) runs Node directly, since there is no runtime image
to match. `npm ci` rather than `npm install`, so a lockfile that disagrees with
`package.json` fails rather than being silently reconciled. The production build
runs too: it catches what the dev server does not.

**Docs** (`docs/**`) builds this directory as a static site on every push and
pull request, and deploys it to GitHub Pages from `main` only — a pull request
proves the site still builds without publishing a preview no one asked for.

Content is treated as a backend change on purpose. A mistuned monster is caught
by the balance simulator, and a malformed drop table by the content validator —
both of which live in the backend job.

`make ci` runs the same commands locally in the same order. If the Makefile and
the workflows drift, running checks locally stops meaning anything, so they are
changed together.

A pull request touching only `docs/` now runs the docs workflow rather than
nothing — the gap this section used to record here closed when that workflow
was added. The general shape of the concern remains true of any future path
filter, though: if a branch protection rule ever requires checks that a given
change's paths do not trigger, the fix is an always-running gate job that
reports success when the filtered jobs are skipped, not removing the filtering
that keeps the pipeline fast.

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
quests, the shop, and all art. Those are additive; the six items above
are the load-bearing ones. If Slice 0 is right, the rest is content and features.
If Slice 0 is wrong, everything built on it has to move.

### 9.1 Status

Slice 0 shipped; Slice 0 was right — nothing it established has had to move.
Built since, additive as anticipated above: Vigor and its activity gate,
disciplines, the battle plan editor, equipment and affixes, the Holding
(idle.md), refinement (items.md §5), Quest and Dungeon
([ADR-0008](adr/0008-quest-snapshot-resolution.md)). Each system records its
own built / deviated / not-yet-built detail where it lives — [idle.md](idle.md)
§7, [items.md](items.md) §9 — rather than here, so this section stays a record
of the original plan instead of a second copy of a status that would drift the
moment either document changed without the other.

Of that exclusion list, "the shop" resolved into the Vendor rather than
shipping as planned — see [vendor.md](vendor.md) §1 for why a fixed-catalogue
shop was rejected in favour of stock priced and rolled against a character's
own gear. Still not built: art — content and items carry an `icon` id (see
[items.md](items.md) §7) but no asset exists behind any of them yet.

### 9.2 Cut from scope: crafting, guilds, and player trading

Crafting, guilds, and player trading are **out of scope**, not merely
deferred. Crafting and guilds were sized against the effort available and
judged too large to build well: crafting needs a recipe corpus, a material
economy tuned against refinement, and a second item-generation path that must
not undercut drops; guilds need membership, permissions, a social surface, and
cooperative content to be about. Player trading (an auction house or any
direct transfer) is cut for a different reason — not effort but risk: it is
the enabling mechanism for real-money trading and requires bot detection as a
hard ongoing dependency. See [economy.md](economy.md) §7 for the full
reasoning.

Half-built versions of any of the three would be worse than their absence, so
the design documents record their *rationale* — the beacon-chain framing,
guild feed outbox consumers, crafting as a material sink, the auction-house
risk analysis — without treating them as pending work. Where a document names
them as a motivating example, that example now reads as a hypothetical rather
than a plan. Reopening any of them is a scope decision, not a backlog pull.
