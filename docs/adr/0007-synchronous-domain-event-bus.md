# ADR-0007 — A synchronous in-process bus for atomic cross-feature effects

**Status:** Accepted · 2026-08-07

## Context

[ADR-0004](0004-transactional-outbox.md) split domain events into two delivery
paths and built only one of them. The outbox exists, is relayed and is tested.
The *synchronous* path was named — "effects that must be atomic with the action"
— but never given a mechanism, so every handler that needed one reached for the
only thing available: a direct call into another feature's application service.

Three of those exist:

- `ClaimHoldingHandler` → `GrantMaterialsHandler`
- `ResolveEncounterHandler` → `ResolveDropsHandler`
- `RespecHandler` → `UnequipUnmetRequirementsHandler`

Each is correct and each commits atomically, but collectively they contradict
[architecture.md](../architecture.md) §3.1, which says features never call each
other's application services. A rule the codebase breaks three times is not a
rule; it is a comment. Either the rule goes or the mechanism arrives, and the
rule is load-bearing — it is what stops the feature graph from becoming a mesh
where every new system reaches into three others and can no longer be reasoned
about, tested, or removed in isolation.

The immediate trigger was respec: an attribute reset can invalidate equipped
gear, which is Inventory's business, but the strip must commit with the
reallocation or a player is charged for a respec that left illegal gear on.

## Decision

Introduce a **synchronous in-process domain event dispatcher** in `Platform/`,
for effects that must be atomic with the originating action. Subscribers run
inside the caller's transaction, on the caller's thread, before the command
handler returns.

It is built on **Symfony's EventDispatcher**, which is already in the container.
`Platform\Event\DomainEventDispatcher` is a one-method interface over it, so the
Application layer expresses intent ("this happened") without importing the
framework's dispatcher directly and without the door being open to Symfony's
kernel events.

**A synchronous subscriber may not return a value to the emitter.** Where the
emitter needs to know what a subscriber did — respec needs the list of stripped
items for its response — it reads the other feature's published read model after
dispatching, which §3.1 already permits. A collector passed through the event
would be a return value wearing a costume, and would make the emitter depend on
the subscriber existing.

Failure is not swallowed. A throwing subscriber propagates and rolls back the
originating transaction, because that is the definition of the path: if the
effect could be allowed to fail independently, it belonged on the outbox.

## Alternatives considered

**`patchlevel/event-sourcing`.** Rejected. It is a full event-sourcing framework
— aggregates whose state *is* the event stream, an event store, upcasting,
snapshots, versioned subscriptions. Every aggregate here is a state-stored
Doctrine ORM entity, and ADR-0004 already chose an outbox for the asynchronous
half. Adopting it would mean either rewriting the aggregates as event-sourced or
running a second persistence model beside Doctrine to serve one call site. The
problem being solved is "dispatch a call without naming the callee", which is
one interface and one adapter; this is several orders of magnitude more
machinery than that, and it is machinery that would then own the domain model.
Worth revisiting only if event sourcing is ever wanted *for its own sake* —
auditability of every state transition, temporal queries — which is a different
decision with different reasoning, not an implementation detail of this one.

**Symfony Messenger with a sync transport.** Rejected. It works, but it routes
an in-transaction effect through the same abstraction as the asynchronous ones,
and the distinction between the two paths is the thing ADR-0004 decided. Making
them look identical at the call site invites exactly the mistake that ADR warns
is a rewrite to correct.

**Keep the direct calls and amend §3.1 instead.** Rejected, but it was close.
Direct calls are explicit, trivially debuggable and type-safe; a bus makes the
control flow harder to follow from the call site. The deciding argument is
directional: direct calls make the *emitter* depend on the *effect*, so
Character has to know Inventory strips gear. As the number of features grows
that coupling is the one that compounds, and this project expects to add quests
and more on top of what exists.

## Consequences

**Accepted:**

- §3.1 becomes true for new work rather than aspirational.
- A feature can react to another's events without either one being modified,
  which is what makes quests addable without touching combat.
- The two paths are visibly different at the call site: `dispatch()` for atomic,
  `record()` for deferred.

**Costs:**

- **Control flow is no longer visible at the call site.** Reading
  `RespecHandler` no longer tells you gear comes off. This is the real price,
  and it is paid every time someone debugs a cross-feature effect. Mitigated
  only by documentation and by keeping the synchronous subscriber set small.
- **Synchronous subscribers are in the request path.** Every one added is
  latency the originating command pays, and a bug in any of them fails the
  command. The outbox test from ADR-0004 still governs which effects are
  allowed here at all.
- **The three existing direct calls are not migrated by this ADR.** Respec uses
  the bus; Holding claim and encounter drops still call directly, and both feed
  their return value into an outbox event, so converting them means solving the
  no-return-value rule for each. They are a deliberate follow-up, not an
  oversight — recorded here so the inconsistency is a known debt with an owner
  rather than a puzzle for the next reader.
- **Quest and Dungeon (2026-08-10) grew this list rather than shrinking it.**
  Both call `CharacterParticipantFactory` (Encounter/Application) to build a
  combat participant, and both grant rewards through `GrantMaterialsHandler`
  and, for Dungeon, `ResolveDropsHandler` (Inventory/Application) — the same
  shape as the original three, and rejected as bus subscribers for the same
  reason: the caller needs the granted amounts back in its own response, which
  is the no-return-value rule biting again. Docs/architecture.md section 3.1
  points here rather than repeating the list, so this paragraph is the one
  place that has to stay current as the exception set grows.
- **Dungeon's discipline-pool pick (2026-08-10) adds one more.**
  `PickDungeonDisciplineHandler` (Dungeon/Application) calls
  `GrantDisciplineHandler` (Character/Application) directly for the same
  reason as the rest of this list — the caller needs to know whether the
  grant actually happened (already-owned is a valid no-op) to decide what to
  report back, which a fire-and-forget event can't answer synchronously. See
  docs/dungeons.md section 3.
- Ordering between subscribers of the same event is Symfony's priority, which is
  a global number. If two subscribers ever need a defined order, that is a
  smell worth examining before reaching for the priority argument.

## Notes

The no-return-value rule is the part most likely to be argued with under
deadline pressure, and it is the part doing the most work. A bus that can return
values is a service locator, and a service locator over features is the mesh
this ADR exists to prevent.
