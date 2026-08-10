# ADR-0008 — Quest resolution against an accept-time snapshot

**Status:** Accepted · 2026-08-10

## Context

Quest is the last core system the architecture docs anticipated but never
built (architecture.md section 9.1). Two designs were on the table for a kill
quest:

1. **Live-tracked**: the player fights ordinary encounters; a subscriber to
   `EncounterResolved` increments a counter until the quest's kill target is
   met.
2. **Expedition**: accepting a quest is a distinct action that starts a
   content-defined timer; claiming it, once the timer elapses, resolves the
   quest's own single fight.

Design 2 was chosen, and within it, a further question: what does the
character look like in that fight, given the player did not fight it live and
may claim minutes or days later? Three options:

- Use the character's **live** state at claim time.
- Use a **snapshot** of the character's state taken at accept time.
- No fight at all — the timer alone gates a guaranteed reward.

The no-fight option was rejected first: it turns "Kill quest" into a pure
countdown with no stakes, which is a worse fit for a combat game than either
alternative.

Between live and snapshot: using live state at claim time means a player could
accept a quest under-levelled, grind gear in the meantime, and claim with a
fight they could not have actually won when they accepted it — or the
opposite, accept strong and let a respec or an unequip make the claim-time
fight harder than intended. Either direction makes the outcome depend on
timing decisions unrelated to the quest itself, which is not a mechanic worth
having.

## Decision

**Accepting a quest snapshots the character as a combat participant
immediately** — the same `Participant` shape and the same
`CharacterParticipantFactory` that already builds a live Encounter's fighter
(see combat.md section 1.2). The snapshot, plus the ruleset version it was
frozen under, is persisted on the `quest_run` row.

**Claiming a quest, once its timer has elapsed, deserialises that snapshot and
runs it through exactly one `CombatEngine::resolve()` call** against the
quest's monster(s), with a freshly generated seed. `CombatEngine::resolve()` is
already a pure function with no database, clock or container access
(combat.md, ADR-0002), which is what makes calling it here — well outside the
request that started the quest — safe: nothing about the engine assumes it is
running inside a live encounter.

A win grants the quest's fixed reward (XP, gold, and optionally a material —
this is how a dungeon key gets granted). A loss or draw costs nothing beyond
the time already spent: the run is marked `failed`, and the same quest may be
accepted again, which re-freezes a fresh snapshot and restarts the timer.

**Vigor is not spent to accept a quest.** Vigor is the resource that paces
*active* play (encounters); a quest's pacing lever is its duration instead,
which is what makes it a genuinely idle-friendly activity rather than a second
Vigor sink.

### Ruleset drift

`CombatEngine::resolve()` already refuses to run an input whose
`rulesetVersion` does not match the engine's current one (a guard that exists
for combat.md's "reproducible forever" claim). An `Encounter` can never hit
this in practice — it resolves inside the same request that builds it — but a
`QuestRun` can sit accepted across a deploy that bumps the ruleset, which is a
failure mode instant-resolving combat never had to consider.

The claim handler catches exactly that case and treats it the same way a lost
fight is treated: the run is marked `failed`, no reward is granted, and the
player may accept again. Nothing was spent to reach this state (no Vigor, and
the time already waited is not recoverable either way), so there is nothing to
compensate and nothing to solve further. This is recorded here as a known,
accepted edge case rather than engineered around, because a more elaborate
fix (freezing the whole ability/effect catalogue per quest, or versioning
`quest_run` snapshots against historical engine versions) would add real
complexity to defend against an outcome that already resolves gracefully.

## Alternatives considered

**Live state at claim time.** Rejected above: makes the outcome depend on
timing games unrelated to the quest.

**No fight, timer-only reward.** Rejected above: removes the one thing that
makes it a *kill* quest rather than a delivery timer.

**Freeze the whole ability/effect catalogue per quest, not just the
character.** Rejected. `ResolveEncounterHandler` already fetches abilities and
effects fresh at resolve time rather than freezing them per encounter, and
Quest claim does the same — only the character's own derived stats need
freezing, because those are the values a gear change or level-up could
otherwise retroactively alter. Monsters are static content with no live state
to drift, so there is nothing to freeze on that side either.

## Consequences

**Accepted:**

- A quest's outcome is fixed the moment it is accepted, which is what makes
  "gear changed mid-quest cannot help or hurt it" true by construction rather
  than by a check someone has to remember to add.
- Quest and Dungeon deliberately use different resolution mechanisms: Dungeon
  resolves live, in one request, because it is occasional, sit-down content
  (economy.md's "weekly-cadence" framing) rather than an idle expedition, and
  giving it its own snapshot/timer machinery would solve a problem it does not
  have. Two features, two honestly different shapes, rather than one
  mechanism stretched to fit both.
- `quest_run` reuses one row per (character, quest) rather than accumulating a
  history row per attempt: a failed attempt cost nothing, so there is nothing
  about it worth keeping beyond what `AuditLogger` already records.

**Costs:**

- A `quest_run.snapshot` column that has to be kept in sync, by hand, with
  whatever fields `Participant` gains in the future — the same maintenance
  burden `Encounter.inputSnapshot` already carries, now duplicated in a second
  place. Acceptable because both are the same well-understood shape, not a
  new kind of coupling.
- The ruleset-drift edge case above is real, if rare: a balance patch landing
  while quests are in flight fails those specific claims. Flagged, not solved
  further, per the reasoning above.
