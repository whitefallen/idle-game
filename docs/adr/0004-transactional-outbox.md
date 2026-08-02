# ADR-0004 — Transactional outbox for cross-feature domain events

**Status:** Accepted · 2026-08-02

## Context

Features communicate through domain events rather than direct service calls. A
single `EncounterResolved` fans out to XP, gold, loot, quest counters,
achievements, the guild activity feed, leaderboard refresh and analytics.

These effects do not have the same requirements. Losing XP is a support ticket.
Losing an achievement notification is a shrug. Treating them identically means
either making everything synchronous — so a slow analytics call sits in the
request path and a failing one rolls back the fight — or making everything
asynchronous, so a crashed worker leaves a player who fought a monster and
gained nothing.

## Decision

Two delivery paths, chosen explicitly per handler.

**Synchronous, inside the originating transaction:** effects that must be atomic
with the action. XP, gold, loot, inventory changes, quest counters, Vigor
deduction.

**Transactional outbox, then Messenger:** everything else. The handler writes an
`outbox` row in the same transaction as the state change; a relay polls
unpublished rows and dispatches them to the message bus.

The test for which path an effect belongs on: **would a player notice, and
consider it a bug, if this effect were missing for thirty seconds?** If yes, it
is synchronous.

## Alternatives considered

**Everything synchronous.** Rejected. The request path becomes as slow as its
slowest subscriber and as reliable as its least reliable one, and adding a
subscriber becomes a latency regression. It also makes the fight's success
depend on analytics being up.

**Dispatch directly to a message broker inside the handler.** Rejected — this is
the dual-write problem. The database transaction and the broker publish are not
atomic, so a crash between them either loses the event (published after commit
fails) or emits an event for a rolled-back state change (published before
commit). The outbox exists precisely to make the event a *part of* the
transaction.

**Symfony Messenger's Doctrine transport alone.** This is close to an outbox and
is a reasonable implementation substrate. Rejected as the whole answer because
it does not by itself give a clean split between in-transaction and deferred
handlers, and the split is the actual decision here. The Doctrine transport may
still be used as the relay's transport.

## Consequences

**Accepted:**

- No dual-write inconsistency. An event exists if and only if its state change
  committed.
- Request latency is bounded by the synchronous set, which is small and owned.
- New subscribers can be added without touching the request path or its
  performance profile.

**Costs:**

- **Async handlers must be idempotent.** At-least-once delivery guarantees
  duplicates will occur. This is a real constraint on every handler author and
  is the most likely source of bugs in this design.
- Events are eventually consistent, so the UI must not assume an achievement or
  feed entry appears in the same response as the action.
- The outbox table needs a relay process, monitoring for publish lag, and a
  cleanup policy ([data-model.md](../data-model.md) §6).
- Ordering is not guaranteed across event types. Handlers that require ordering
  must derive it from the event payload rather than from delivery order.

## Notes

The split is decided before any handler exists because reversing it later is a
rewrite rather than a refactor: moving an effect from async to synchronous means
auditing every place that assumed eventual consistency, and moving the other way
means auditing every place that assumed atomicity.
