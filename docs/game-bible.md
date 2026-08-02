# Game Bible

## 1. Vision

Emberwatch is a browser MMORPG for players who want a role-playing game that
respects their time. A session should be satisfying in ten minutes and should
not punish a player for missing a day. Depth comes from *decisions* — how a
character is built, equipped and instructed — not from how many hours are spent
repeating an action.

The game is accessible in the tradition of classic browser RPGs, but it is not
a reskin of one. Its identity rests on three things that its inspirations do not
do:

1. **Combat is instructed, not watched.** The player does not press buttons
   during a fight, but the player writes the plan the character fights by.
2. **Gear is cultivated, not replaced.** A good item is a long-term project.
3. **Offline time builds, online time spends.** The two halves of play are
   different activities that need each other.

## 2. Player fantasy

You are a *Warden* — one of a standing order that maintains the beacon-line
holding back an encroaching blight. You do not personally win the war. You hold
a stretch of it, you improve your equipment and your methods, and you grow
capable enough that the stretch you hold gets longer.

This framing does deliberate work:

- It justifies **repeatable content** without a narrative contradiction. You are
  holding a line, so patrolling it again is the job, not a grind loop excuse.
- It justifies **passive accrual**. Your holding produces while you are away
  because it is a place, staffed by people, not a slot machine.
- It justifies **guilds** as beacon-chains — a natural cooperative unit.
- It leaves room for years of content: new stretches of the line, new blight
  variants, new orders.

## 3. Core pillars

| Pillar | What it means in practice | What it forbids |
|--------|---------------------------|-----------------|
| **Long-term progression** | Power grows on several slow axes at once (level, gear, refinement, disciplines) so no single axis has to inflate | Exponential stat curves; content that invalidates last month's effort |
| **Meaningful equipment** | Items have identity and are improved over time | Linear upgrade treadmills where item N+1 strictly dominates item N |
| **Strategic character building** | Attribute allocation and discipline loadouts create real, respec-able archetypes | Fixed classes; one correct build |
| **Idle-friendly** | Absence accrues value, up to a cap | Offline play that outpaces active play, or vice versa |
| **Deterministic systems** | Same inputs, same outputs, forever, verifiably | Client-side randomness; unreproducible outcomes |
| **Fair monetization** | Money buys convenience, cosmetics and space | Money buys stats, attempts, or time-gated power |
| **Modular architecture** | Content is data; features are isolated | Hardcoded items, monsters, quests, or drop tables |

## 4. The core loop

### 4.1 Session loop (5–15 minutes, the common case)

```
Log in
  │
  ├─▶ Collect Holding output          (materials + gold accrued while away, capped)
  │
  ├─▶ Spend it                        (refine gear, craft, repair — the main power axis)
  │
  ├─▶ Adjust                          (attributes, equipment, discipline loadout, battle plan)
  │
  ├─▶ Spend Vigor on encounters       (patrols, dungeons, arena — the action currency)
  │
  ├─▶ Bank the results                (XP, gold, materials, item drops, quest progress)
  │
  └─▶ Log out                         (Holding keeps producing, Vigor keeps regenerating)
```

The loop is deliberately a cycle of **accrue → invest → test → learn**. The
"test" step is where the battle plan is validated; the "learn" step is where the
player reads the combat log and changes the plan. That read-and-adjust beat is
the game's signature interaction.

### 4.2 Meta loop (days to weeks)

```
Reach a level or item-level threshold
  → unlock a new stretch of the beacon-line
  → face enemies with a new mechanic
  → the current battle plan fails
  → acquire/slot a discipline that answers it, and refine gear toward it
  → the stretch becomes routine
  → repeat one tier up
```

Content difficulty steps are **mechanical**, not merely numerical. A new tier
should introduce an enemy behaviour that a well-built character of the previous
tier genuinely handles badly, so that progression is felt as *learning*, not as
a bigger number.

### 4.3 Long loop (months)

Seasonal beacon-line campaigns; guild-scale objectives; collection and mastery
of item sets and disciplines. Detailed in a later document — deliberately not
designed yet, so it can respond to how players actually play.

## 5. The signature mechanic: the Battle Plan

Combat resolves on the server without player input. What the player controls is
the **battle plan**: an ordered list of rules the character follows.

```
1. IF  self.health < 35%              THEN  Emberdraught
2. IF  enemy.count >= 3               THEN  Sweeping Arc
3. IF  enemy.hasEffect(Burning)       THEN  Rupture
4. ALWAYS                             THEN  Measured Strike
```

Rules are evaluated top-down each turn; the first whose condition holds and
whose ability is off cooldown and affordable is executed. The last rule must be
unconditional, so a plan always resolves.

Why this is the right core mechanic for this game:

- **It is idle-compatible.** No real-time input is required, so the game stays
  playable in a browser tab on a phone, and offline/asynchronous content (arena
  defence, guild battles) uses the exact same resolution path as active play.
- **It is strategic.** The interesting decision is anticipating situations, which
  rewards planning over reflexes and reading over grinding.
- **It is deterministic and replayable.** A plan is data. A fight is
  `(plan, gear, seed, ruleset) → log`. See [combat.md](combat.md).
- **It is legible.** The combat log can state *which rule fired and why*, which
  turns every loss into a lesson rather than a shrug.
- **It differentiates.** The genre norm is combat with zero player agency. This
  keeps the zero-input-during-combat property that makes browser RPGs work while
  restoring authorship of the outcome to the player.

**Accessibility requirement:** a new character starts with a sensible default
plan and can play the early game without ever opening the editor. Complexity is
opt-in. The editor unlocks with a tutorial at the first fight where the default
plan fails.

## 6. Character identity

There are **no fixed classes**. A character's identity is the combination of:

- **Attribute allocation** — Strength, Dexterity, Intelligence, Constitution, Luck
- **Equipment** — including which attribute the equipped weapon scales with
- **Discipline loadout** — a limited number of slotted abilities and passives
- **Battle plan** — how those abilities are actually used

All four are respec-able for gold (a designed sink; see [economy.md](economy.md)).
Respec being cheap and always available is a deliberate decision: it makes
experimentation the fun part rather than a punished mistake, and it removes the
single most common reason players abandon a character.

Archetypes emerge from the system rather than being enumerated by it. The
balance target is that at every tier there are **at least three viable
archetypes** with meaningfully different play patterns, verified by simulation
against the tier's encounter set.

## 7. Playstyle parity

The design contract is that **neither active nor idle play dominates**, enforced
structurally rather than by tuning:

- **Vigor**, the encounter currency, regenerates on a fixed schedule and is
  capped. An active player cannot buy or grind past the cap, so daily encounter
  throughput has a ceiling.
- **Holding output**, the passive resource, is capped by an accrual window. An
  absent player cannot bank a week of production, so absence has a floor of value
  but not an unbounded one.
- The two produce **different, non-substitutable resources**: encounters are the
  only source of XP, item drops and unlocks; the Holding is the dominant source
  of refinement materials. A player who does only one of the two stalls on the
  other axis.

Concrete parity target: a player logging in **twice a day for ten minutes**
should reach roughly **85–95%** of the weekly progress of a player logging in
six times a day, and roughly **200%** of a player who logs in once every three
days. These figures are validated by the progression simulator, not by intuition.

## 8. What this game is not

Stated explicitly so that future feature proposals can be measured against it:

- Not a real-time action game. No twitch input, ever.
- Not a gacha. No randomised paid rewards of any kind.
- Not a PvP-first game. PvP exists (arena, guild objectives) but is opt-in and
  never a required progression path.
- Not a wipe/prestige game. Progress is permanent. Seasons add content and
  optional parallel ladders; they never reset a character.
- Not infinitely scaling. Numbers stay in ranges a human can reason about.
  See the stat budget discipline in [progression.md](progression.md).

## 9. Open design questions

Recorded rather than answered, to be resolved before the systems they touch are
built:

1. **Death penalty.** Currently none beyond durability loss and a wasted Vigor
   cost. Whether losing an encounter should cost anything more is unresolved.
2. **Trading between players.** An auction house is a powerful economic sink
   (fees) but is also the single largest vector for real-money trading and bot
   farming. Deferred until the economy has real data. See [economy.md](economy.md) §7.
3. **Guild depth.** Guilds are in the pillar list but their mechanics are
   unspecified. Deliberately deferred.
4. **Season structure.** See §4.3.
