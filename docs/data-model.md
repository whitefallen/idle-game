# Data Model

PostgreSQL 17. Doctrine ORM. Schema changes only through migrations — never
`doctrine:schema:update`, in any environment, including development, because a
schema that was never expressed as a migration cannot be deployed.

---

## 1. Conventions

| Rule | Rationale |
|---|---|
| `snake_case` for tables and columns | Postgres folds unquoted identifiers to lower case; camelCase forces quoting everywhere |
| **UUIDv7** primary keys, column type `uuid` | Globally unique, non-enumerable, and time-ordered — see [ADR-0005](adr/0005-uuidv7-primary-keys.md) |
| `timestamptz`, never `timestamp` | A naive timestamp in a game with a global player base is a defect waiting for a DST boundary |
| Foreign keys always declared, with explicit `ON DELETE` | Referential integrity belongs in the database, not in application hope |
| Money and quantities as `bigint` | Never `numeric`, never `float`. See [economy.md](economy.md) §1 |
| No derived values stored | One documented exception: [ADR-0006](adr/0006-denormalised-power-score.md) |
| Every table has `created_at`; mutable tables have `updated_at` | Non-negotiable for incident investigation |

**Naming:** tables are singular (`character`, not `characters`), join tables are
`a_b`, indexes are `idx_<table>_<columns>`, uniques are `uq_<table>_<columns>`.

---

## 2. Core tables

### `account`
```
id                uuid            PK
email             citext          UNIQUE NOT NULL
password_hash     text            NOT NULL
status            text            NOT NULL   -- active | suspended | deleted
email_verified_at timestamptz     NULL
created_at        timestamptz     NOT NULL
updated_at        timestamptz     NOT NULL
```
`citext` avoids the classic duplicate-account-by-case bug. Passwords are Argon2id
via Symfony's hasher; the algorithm is never pinned in application code.

### `game_character`

Named `game_character` rather than `character`, which is a reserved word in
several SQL dialects and a type name in Postgres.

```
id              uuid    PK
account_id      uuid    FK → account(id) ON DELETE CASCADE
name            citext  UNIQUE NOT NULL
level           int     NOT NULL DEFAULT 1
experience      bigint  NOT NULL DEFAULT 0
gold            bigint  NOT NULL DEFAULT 0
emberdust       bigint  NOT NULL DEFAULT 0
unspent_points  int     NOT NULL DEFAULT 10
strength, dexterity, intelligence, constitution, luck
                int         NOT NULL DEFAULT 5        -- allocated only
vigor_current   int         NOT NULL
vigor_ticked_at timestamptz NOT NULL
vigor_spent_at  timestamptz NULL                      -- the activity gate's anchor
battle_plan     json        NOT NULL
ability_ids     json        NOT NULL                  -- the slotted loadout
power_score     int     NOT NULL DEFAULT 0            -- denormalised; ADR-0006
created_at, updated_at
```

Attribute columns are spelled out rather than abbreviated. An earlier draft used
`str`/`int_` and noted that `int` needed escaping; the full names avoid the
problem instead of working around it.

Attribute columns store the **allocated** values only; equipment contributions
are computed on read, so unequipping can never leave an invalid state.

`battle_plan` and `ability_ids` are the character's own configuration rather
than a separate aggregate: both are always read and written whole, with the
character, and neither is ever queried into. See
[progression.md](progression.md) §4 and [combat.md](combat.md) §5.

Constraints: `CHECK (gold >= 0)`, `CHECK (emberdust >= 0)`,
`CHECK (vigor_current >= 0)`. Currency going negative is a class of bug that must
be impossible at the storage layer, not merely unlikely at the application layer.

Indexes: `idx_character_account_id`, `idx_character_power_score` (leaderboard),
`idx_character_level`.

### `item_instance`
```
id              uuid    PK
character_id    uuid    FK → game_character(id) ON DELETE CASCADE
definition_id   text    NOT NULL          -- content id, e.g. item.wardens_halberd
item_level      int     NOT NULL
rarity          text    NOT NULL
affixes         jsonb   NOT NULL
refine_level    int     NOT NULL DEFAULT 0
equipped_slot   text    NULL              -- NULL = in inventory
created_at
```

No `bound` column. Every item is bound, because there is no trading
([economy.md](economy.md) §7) — ownership is `character_id`, and a flag that is
`true` on every row in the table answers no question. It becomes a column when
some items can be unbound, not before.

No `updated_at` either. An item's mutable state is refinement and its slot, and
both are audited events with their own timestamps — a row-level modified time
would be a second, less precise answer to a question already answered.

`definition_id` is a **content id string, not a foreign key** — definitions live
in `content/`, not in the database. Integrity is enforced by the content
pipeline's CI check ([architecture.md](architecture.md) §6).

`affixes` is JSONB because affixes are a variable-length, read-mostly, always-read-
whole structure. A normalised `item_affix` table would triple the row count of
the largest table in the schema and require a join on every inventory read, for
query flexibility that nothing needs. This is a deliberate trade: JSONB is the
right choice **because** affixes are never queried individually.

Indexes: `idx_item_instance_character_id`,
`uq_item_instance_equipped` — a partial unique index on
`(character_id, equipped_slot) WHERE equipped_slot IS NOT NULL`. That index is
what makes double-equipping a slot impossible under concurrency; application
checks alone lose to a race.

### `encounter`
```
id                uuid        PK
character_id      uuid        FK → game_character(id) ON DELETE CASCADE
definition_id     text        NOT NULL
seed              bigint      NOT NULL
ruleset_version   text        NOT NULL
input_snapshot    jsonb       NOT NULL
log               bytea       NOT NULL      -- gzip-compressed JSON (ext-zlib)
outcome           text        NOT NULL      -- victory | defeat | draw
rounds            int         NOT NULL
rewards           jsonb       NOT NULL
created_at        timestamptz NOT NULL
```

The four columns `seed`, `ruleset_version`, `input_snapshot` and `log` are what
make combat auditable years later ([combat.md](combat.md) §1.2).

This is the **highest-growth table in the schema**. It is an ordinary table;
retention is handled by batched deletion rather than partitioning, for the
reasons in §6.

### `character_material`
```
id              uuid        PK
character_id    uuid        FK → game_character(id) ON DELETE CASCADE
material_id     text        NOT NULL          -- content id, e.g. material.emberash
quantity        bigint      NOT NULL
created_at, updated_at
```

Constraints: `UNIQUE (character_id, material_id)`, `CHECK (quantity >= 0)`. The
unique index is the guarantee, not an application check: two concurrent
first-time grants would otherwise each insert a row and half the balance would
be invisible to every later read.

Materials are fungible, so this is a **counted stack** rather than one row per
unit — a single refinement consumes hundreds. A row per (character, material)
rather than a JSON column on the character, because both writers — encounter
drops and Holding claims — read-modify-write under a row lock, and a JSON column
would serialise every material against every other one.

### `character_discipline`
```
id              uuid        PK
character_id    uuid        FK → game_character(id) ON DELETE CASCADE
discipline_id   text        NOT NULL          -- content id, e.g. discipline.stonebreaker
granted_at      timestamptz NOT NULL
```

Constraints: `UNIQUE (character_id, discipline_id)`.

The stored half of discipline ownership — see [progression.md](progression.md)
§4.1, "Ownership is derived, not stored." Every level-milestone discipline is
still a pure function of level and needs no row here; this table exists only
for the disciplines that have no level to derive from. Existence of a row *is*
ownership: no quantity, no revocation, nothing to update once granted. First
(and currently only) writer is the dungeon discipline-pool pick
([dungeons.md](dungeons.md) §2-3).

### `holding`
```
id                uuid        PK
character_id      uuid        UNIQUE FK → game_character(id) ON DELETE CASCADE
slots             jsonb       NOT NULL   -- [{ index, materialId, accruedAt }]
last_claimed_at   timestamptz NOT NULL   -- the gold tithe's anchor
created_at, updated_at
```

Claims lock this row with `SELECT … FOR UPDATE`
([idle.md](idle.md) §5, rule T2).

Two departures from this table's original design, both recorded in
[idle.md](idle.md) §7.2. **Each slot carries its own `accruedAt`**, because
lines produce at different rates and because reassignment must reset one slot
without touching the others; `last_claimed_at` remains as the non-slotted
tithe's anchor. And **`cap_seconds` is gone** — the cap is derived from
character level on read, since a stored copy is a derived value that every
level-up would have to remember to rewrite.

`slots` is JSONB because it is a small, fixed-width structure belonging to
exactly one Holding and always read whole. It is never queried by slot, which is
the property that makes the JSON column right rather than merely convenient.

### `vendor_stock`
```
id                    uuid        PK
character_id          uuid        FK → game_character(id) ON DELETE CASCADE
date_key              text        NOT NULL   -- the UTC day, as YYYY-MM-DD
reference_item_level  int         NOT NULL
luck                  int         NOT NULL
created_at
```

Constraints: `UNIQUE (character_id, date_key)`. Indexed on `created_at` for the
retention prune.

**The offers are not here, and never will be.** They stay derived from
`(character_id, date_key, reference_item_level, luck)` through
`VendorStockGenerator`, per §4. What is stored is only the part of that tuple a
player can change during the day: seeding the roll on the character and the date
already made a page refresh harmless, but the roll also read the character's
*live* level, Luck and average equipped item level — so unequipping a weapon or
spending an attribute point re-rolled the day's eight offers, as often as a
player cared to click ([vendor.md](vendor.md) §2). A row is written the first
time a character resolves stock on a given day and never updated afterwards.

The write is an `INSERT … ON CONFLICT (character_id, date_key) DO NOTHING`
followed by a read, rather than a check-then-insert: every vendor request of the
day races to freeze, and the losing side must adopt the winner's snapshot rather
than fail. This is also why the unique index here is the mechanism rather than a
backstop — unlike `holding`, there is no character row lock upstream to
serialise the two.

### `quest_run`
```
id              uuid        PK
character_id    uuid        FK → game_character(id) ON DELETE CASCADE
quest_id        text        NOT NULL
status          text        NOT NULL   -- active | failed | claimed
accepted_at     timestamptz NOT NULL
completes_at    timestamptz NOT NULL
snapshot        jsonb       NOT NULL   -- frozen Participant + rulesetVersion
seed            bigint      NULL       -- set at claim
outcome         text        NULL       -- set at claim
log             bytea       NULL       -- gzip'd combat log, set at claim
rewards         jsonb       NULL       -- set at claim, Victory only
resolved_at     timestamptz NULL
```

Constraints: `UNIQUE (character_id, quest_id)`.

One row per `(character, quest)`, **reused across attempts** rather than
accumulating a history row per attempt: a `failed` row is overwritten in
place by the next accept, because nothing was spent to reach `failed` — no
Vigor, only time. `claimed` is terminal; the fixed one-time reward already
paid out. See [ADR-0008](adr/0008-quest-snapshot-resolution.md) for why
`snapshot` exists at all — a quest resolves against a frozen character state,
possibly days after it was accepted.

### `dungeon_run`
```
id                        uuid        PK
character_id              uuid        FK → game_character(id) ON DELETE CASCADE
dungeon_id                text        NOT NULL
stages                    jsonb       NOT NULL   -- per-stage outcome, in order
logs                      bytea       NOT NULL   -- gzip'd, index-aligned with stages
cleared                   boolean     NOT NULL
rewards                   jsonb       NOT NULL
created_at                timestamptz NOT NULL
offered_discipline_ids    jsonb       NULL       -- set at clear, repeatable:false only
picked_discipline_id      text        NULL       -- set by a later, separate request
```

Indexed on `character_id`. One row **per attempt** — unlike `quest_run`,
nothing is reused, since dungeon runs are occasional rather than a
duration-gated one-time thing.

`offered_discipline_ids` / `picked_discipline_id` are the two-step "offer,
then confirm" state for the discipline collection pool
([dungeons.md](dungeons.md) §2): the offer is computed and stored the moment
a `repeatable: false` dungeon fully clears; the pick is a deliberately
separate, later write.

### `outbox`
```
id             uuid        PK
event_type     text        NOT NULL
payload        jsonb       NOT NULL
occurred_at    timestamptz NOT NULL
published_at   timestamptz NULL
attempts       int         NOT NULL DEFAULT 0
```
Index: `idx_outbox_unpublished` — partial on `(occurred_at) WHERE published_at IS NULL`.
A partial index keeps the relay's polling query cheap regardless of how large the
table's history grows.

### `audit_log`
```
id            uuid          PK
action        varchar(60)   NOT NULL
account_id    uuid          NULL
character_id  uuid          NULL
context       jsonb         NOT NULL
ip_hash       varchar(64)   NULL
occurred_at   timestamptz   NOT NULL
```
Append-only; the entity exposes no mutator and no repository offers an update or
delete path. An audit record that can be edited is not evidence.

`ip_hash` is a salted SHA-256 digest, never the address. An address identifies a
person and this table is long-lived; the hash still answers "how many failures
from one source" without retaining the source.

`action` is a stable enum (`App\Platform\Audit\AuditAction`), not free text — a
typo in a string would create a second, silently separate category and quietly
break the aggregate queries the table exists to serve.

**Granularity.** An encounter writes one record carrying every mutation it
caused, each with its amount and resulting balance, rather than one record per
mutation. Both satisfy the requirement in [economy.md](economy.md) §5; one row
per mutation would mean roughly a hundred rows per player per day at the Vigor
cap for no extra investigative power, since the mutations of a single fight are
only ever read together.

Indexes lead with the column an investigation filters on and end with
`occurred_at`, so a time-bounded query over one account, character or action
scans a range rather than the table:
`(account_id, occurred_at)`, `(character_id, occurred_at)`, `(action, occurred_at)`.

The primary key is `id` alone. An earlier draft used `(id, occurred_at)`,
which a partitioned table would have required — §6 records why partitioning
was reverted.

### `idempotency_record`
```
idempotency_key text        PK   -- the client-supplied key is the key
account_id      uuid        NOT NULL
request_hash    text        NOT NULL
response        jsonb       NOT NULL
status          int         NOT NULL   -- replayed verbatim, headers included
created_at      timestamptz NOT NULL
```
Rows expire after 24 hours. `request_hash` is compared on replay: the same key
with a different body is a client bug and returns a 409 rather than silently
returning the wrong cached response.

---

## 3. Concurrency

The failure mode that matters in this game is the double-award: two concurrent
requests both reading pre-mutation state and both granting a reward.

| Operation | Protection |
|---|---|
| Holding claim | `SELECT … FOR UPDATE` on `holding` |
| Vigor spend | `UPDATE … WHERE vigor_current >= ?` — the guard is in the WHERE clause; zero rows affected means insufficient |
| Equip | Partial unique index on `(character_id, equipped_slot)` |
| Currency spend | `UPDATE … WHERE gold >= ?` plus the `CHECK` constraint as backstop |
| Any mutating endpoint | Idempotency key |

The pattern throughout: **make the invariant unviolatable in the database**,
then check it in the application for a good error message. Application-only
checks are advisory under concurrency.

---

## 4. What is deliberately not stored

- Derived stats (health, damage, crit, armour) — computed from attributes and
  equipment on read, per [progression.md](progression.md) §3.
- Item base stats on instances — read from the content definition.
- Anything the client sent that the server can compute itself.

---

## 5. Migrations

Forward-only. Every migration is reviewed for lock behaviour before merge:
`ALTER TABLE … ADD COLUMN NOT NULL DEFAULT` on a large table, index creation
without `CONCURRENTLY`, and any type change on a hot table are treated as
outages, not migrations. Destructive changes follow expand → migrate → contract
across separate releases.

---

## 6. Retention

Unbounded growth is this schema's most predictable operational problem, so the
policy exists before the data does:

The policy is declared in one place, `App\Platform\Retention\RetentionPolicy`,
so it is reviewable as a policy rather than scattered across whichever command
deletes each table. `RetentionPruneTest` asserts every declared column actually
exists, because a prune that targets a missing column fails at runtime on a
table nobody is watching.

| Table | Retention | Why |
|---|---|---|
| `encounter` | 90 days | Full combat logs are large and almost never read after the session that produced them |
| `audit_log` | 400 days | A full year plus investigation lag, so a dispute raised late still has evidence |
| `outbox` | 7 days after publication | Kept only long enough to debug a delivery problem |
| `vendor_stock` | 7 days | A frozen day of stock is unreadable once that day is over; a week is slack for investigating a purchase dispute |
| `idempotency_record` | 24 hours | Matches the replay window in [api.md](api.md) §4 |

### Why batched deletion rather than partitioning

Range partitioning makes deletion nearly free — dropping a partition is a
metadata operation, while deleting from a very large table is slow, generates
WAL and leaves bloat. That argument is real, and it is why the first
implementation here was partitioned.

It was reverted, for three reasons:

**It inverts the failure mode of the scheduled job.** Partitions must exist
before rows land in their range, so a missed partition-creation run means
inserts start failing — for `encounter`, players cannot fight. With batched
deletion a missed run costs disk and nothing else. Trading an outage risk for a
disk-usage risk is the right way round.

**It made the most common read slower.** A partitioned table's primary key must
contain the partition column, so a lookup by `id` alone probes every partition —
twelve more each year. `GET /encounters/{id}` is the replay endpoint and is the
hottest read on that table.

**The maintenance is real.** Partitioned tables cannot be described by Doctrine,
so they become hand-managed DDL excluded from `migrations:diff`, and both sides
of the schema comparison must be filtered or `schema:validate` goes permanently
red.

At the Vigor cap of 24 encounters per player per day and roughly 5 KB a row,
1,000 daily actives produce about 3.6 GB a month and 2 million rows to prune —
comfortably an overnight batch job. Partitioning earns its keep an order of
magnitude above that.

**Revisit when** a monthly prune stops finishing inside its window, or
`encounter` passes roughly 50 GB. At that point convert with measurements in
hand, and consider `pg_partman` rather than hand-rolled DDL.

### A note on `json` versus `jsonb`

`audit_log.context` is `jsonb`; the other JSON columns are `json`, which is what
Doctrine's `json` type maps to on PostgreSQL. `jsonb` is the better default — it
is parsed once and can be indexed — and the audit context needs it because
investigations query inside it. Doctrine can emit it via
`options: ['jsonb' => true]`, so converting the rest is a small migration that
has simply not been done yet.
