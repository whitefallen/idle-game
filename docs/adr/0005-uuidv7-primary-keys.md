# ADR-0005 — Time-ordered UUIDv7 primary keys

**Status:** Accepted · 2026-08-02

## Context

`CLAUDE.md` mandates UUID primary keys. The reasons are sound: ids can be
generated before persistence, they are globally unique across environments and
future services, and they are not trivially enumerable in URLs.

The schema includes several very-high-insert tables — `encounter`, `audit_log`,
`outbox` — which will accumulate hundreds of millions of rows.

The mandate says "UUID" but not which version, and the difference matters more
than it appears.

## Decision

**UUIDv7** (time-ordered) for all primary keys, stored in Postgres's native
`uuid` column type, generated in PHP via `symfony/uid`.

## Alternatives considered

**UUIDv4 (random).** Rejected, and this is the whole point of the ADR. Random
primary keys insert at uniformly distributed positions in the primary key
B-tree. Every insert dirties a different page, so the working set becomes the
whole index rather than its right edge, page splits are frequent, index bloat
grows, and write throughput degrades as the table grows. On append-heavy tables
this is the difference between an index that stays in memory and one that does
not. It is also a problem that appears months into production, when migrating
the primary key of the largest tables is at its most expensive.

**`bigserial`.** Best raw performance and smallest index, but sequential integers
are enumerable — `/characters/1234` invites walking the id space — and they
cannot be generated before insert, which complicates the outbox pattern and
test fixtures. Rejected on the enumeration property alone.

**UUID stored as `text` or `varchar`.** Rejected outright. 36 bytes instead of
16, no type validation, slower comparisons, larger indexes. This is a common
mistake in Doctrine projects and is worth naming so nobody re-introduces it.

**ULID.** Functionally equivalent to UUIDv7 for our purposes and also supported
by `symfony/uid`. UUIDv7 is preferred only because it is the RFC 9562 standard
and maps to the native `uuid` type without a custom Doctrine type.

## Consequences

**Accepted:**

- Inserts land at the right edge of the index, so write performance stays flat
  as tables grow and index bloat is minimal.
- Ids sort chronologically, which makes `ORDER BY id` a valid and free proxy for
  insertion order, and makes range-partitioning behave well.
- Ids can be generated in application code before persistence, which the outbox
  pattern and test fixtures both rely on.
- 16-byte native storage.

**Costs:**

- A UUIDv7 embeds a millisecond timestamp, so it **leaks creation time**. This
  is acceptable for game entities and is in fact useful for debugging, but it
  means UUIDv7 must never be used where an id needs to be opaque — password
  reset tokens, session identifiers, invite codes. Those use
  cryptographically random values, not primary keys.
- Ids remain non-sequential to an observer, but adjacency is inferable from
  timestamps. Enumeration protection therefore still requires authorisation
  checks on every request; the id type is defence in depth, never the defence.
  See [api.md](../api.md) §5.

## Enforcement

A single Doctrine base mapping supplies the id strategy so no entity chooses its
own. A migration review checklist item rejects any new `uuid` column that is not
generated this way.
