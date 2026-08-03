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
id            uuid          -- PK is (id, occurred_at); see partitioning below
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
`occurred_at`, so a time-bounded query prunes partitions:
`(account_id, occurred_at)`, `(character_id, occurred_at)`, `(action, occurred_at)`.

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
