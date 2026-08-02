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

### `character`
```
id              uuid    PK
account_id      uuid    FK → account(id) ON DELETE CASCADE
name            citext  UNIQUE NOT NULL
level           int     NOT NULL DEFAULT 1
experience      bigint  NOT NULL DEFAULT 0
gold            bigint  NOT NULL DEFAULT 0
emberdust       bigint  NOT NULL DEFAULT 0
unspent_points  int     NOT NULL DEFAULT 10
str, dex, int_, con, luk   int  NOT NULL DEFAULT 5   -- allocated only
vigor_current   int         NOT NULL
vigor_ticked_at timestamptz NOT NULL
power_score     int     NOT NULL DEFAULT 0            -- denormalised; ADR-0006
created_at, updated_at
```

`int_` is escaped because `int` is reserved. Attribute columns store the
**allocated** values only; equipment contributions are computed on read, so
unequipping can never leave an invalid state.

Constraints: `CHECK (gold >= 0)`, `CHECK (emberdust >= 0)`,
`CHECK (vigor_current >= 0)`. Currency going negative is a class of bug that must
be impossible at the storage layer, not merely unlikely at the application layer.

Indexes: `idx_character_account_id`, `idx_character_power_score` (leaderboard),
`idx_character_level`.

### `item_instance`
```
id              uuid    PK
character_id    uuid    FK → character(id) ON DELETE CASCADE
definition_id   text    NOT NULL          -- content id, e.g. item.wardens_halberd
ilvl            int     NOT NULL
rarity          text    NOT NULL
affixes         jsonb   NOT NULL DEFAULT '[]'
refine_level    int     NOT NULL DEFAULT 0
durability      int     NOT NULL
max_durability  int     NOT NULL
equipped_slot   text    NULL              -- NULL = in inventory
bound           bool    NOT NULL DEFAULT true
created_at, updated_at
```

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
character_id      uuid        FK → character(id) ON DELETE CASCADE
definition_id     text        NOT NULL
seed              bigint      NOT NULL
ruleset_version   text        NOT NULL
input_snapshot    jsonb       NOT NULL
log               bytea       NOT NULL      -- zstd-compressed JSON
outcome           text        NOT NULL      -- victory | defeat | draw
rounds            int         NOT NULL
rewards           jsonb       NOT NULL
created_at        timestamptz NOT NULL
```

The four columns `seed`, `ruleset_version`, `input_snapshot` and `log` are what
make combat auditable years later ([combat.md](combat.md) §1.2).

This is the **highest-growth table in the schema** and is planned for
**monthly range partitioning on `created_at`** from the first migration.
Partitioning added later requires rewriting the table; adding it up front is
nearly free. Retention in §6.

### `holding`
```
id                uuid        PK
character_id      uuid        UNIQUE FK → character(id) ON DELETE CASCADE
slots             jsonb       NOT NULL   -- [{ index, materialId, unlockedAt }]
last_claimed_at   timestamptz NOT NULL
cap_seconds       int         NOT NULL
created_at, updated_at
```

Claims lock this row with `SELECT … FOR UPDATE`
([idle.md](idle.md) §5, rule T2).

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
id            uuid        PK
account_id    uuid        NULL
character_id  uuid        NULL
action        text        NOT NULL
context       jsonb       NOT NULL
ip_hash       text        NULL
occurred_at   timestamptz NOT NULL
```
Append-only; no update or delete path exists in application code. Covers every
currency mutation, item creation and destruction, claim, purchase, and every
authorisation failure. Also range-partitioned monthly.

### `idempotency_key`
```
key           text        PK
account_id    uuid        NOT NULL
request_hash  text        NOT NULL
response      jsonb       NOT NULL
created_at    timestamptz NOT NULL
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

| Table | Retention | Mechanism |
|---|---|---|
| `encounter` | Full log 90 days; summary retained indefinitely | Drop partition, after writing the summary row |
| `audit_log` | 400 days (covers a full year plus investigation lag) | Drop partition |
| `outbox` | Published rows deleted after 7 days | Scheduled cleanup |
| `idempotency_key` | 24 hours | Scheduled cleanup |

Dropping a partition is a metadata operation. Deleting rows from a
hundred-million-row table is an incident. That difference is the entire reason
these tables are partitioned from day one.
