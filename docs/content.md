# Content Map

What exists in the content library today, what each piece of it is *for*, and
the rules a new stretch of the beacon-line has to follow to be accepted.

This document describes authored content. The systems it is authored against are
specified in [combat.md](combat.md) (resolution), [progression.md](progression.md)
(levels, derived stats, gating) and [items.md](items.md) (drops).

---

## 1. Why content is organised in waves

`content/<type>/<wave>.yaml`. Every content type is loaded from a directory and
indexed by id, so file names carry no meaning to the loader — they exist for the
people editing them. Files are named after the **wave** that shipped them
(`core`, `stretch1`, `stretch2`, …) rather than after the type of thing inside
them, because the unit of design work is a wave: a stretch of the beacon-line
introduces monsters, the abilities those monsters use, the effects those
abilities apply, and the encounters that arrange them, all at once. Splitting by
kind instead would spread one design decision across five files.

Ids are global and permanent. A live `item_instance` row, a stored combat log
and a persisted battle plan all reference content by id, so **content is
append-only**: ids are never reused and never renamed. Retiring content means
ceasing to reference it, not deleting it.

Player abilities and monster abilities share one pool. The engine draws no
distinction between them — one implementation, one set of rules — so a
`monster_abilities` directory would be a fiction the code does not believe.

---

## 2. The beacon-line

Each stretch is a difficulty step, and per
[game-bible.md](game-bible.md) §4.2 the step is **mechanical, not numerical**:
it introduces an enemy behaviour a well-built character of the previous stretch
handles badly, so progression is felt as learning rather than as a bigger number.

| Stretch | Levels | Mechanical step | What the player has to change |
|---------|--------|-----------------|-------------------------------|
| **1 — Beacon Patrol** | 1–6 | Groups. One strong enemy and three weak ones are different problems | Slot an area ability; learn that a plan needs a fallback |
| **2 — The Sundered Causeway** | 7–12 | **Sustain.** Enemies undo progress: the Rot Chanter heals its allies, the Causeway Ravager buffs itself the longer it lives | Kill order and pace. A plan that wins slowly stops winning |
| **2.5 — The Drowned Span** | 13–14 | **The clock.** The Tide Herald's threat is a function of the round, not of its health, and its buff never lapses once it starts | Pace. Open harder and finish before round six; there is nothing to wait out |
| **3 — The Ashen Vault** | 15–22 | **Phases.** The Warden of Ash changes behaviour with its health and the round number, and brings adds | Conditional rules — `target.health_percent`, `round` — instead of a priority list |
| **4 — The Cinder Reach** | 23–30 | **The tell.** A pyrebinder marks the player; two rounds later an executioner cashes the mark in for the hardest hit in the game | `self.has_effect` — healing *before* the spike rather than after it |

Each step is a rung, and the order is load-bearing. Stretch 2.5 exists because
`round` is a subject the Warden of Ash relies on and nothing before it had ever
used, so a player met the boss's round gate having never seen a fight where the
round number mattered. Stretch 4 is the first content of any kind to use a
presence subject: the Reach's executioner reads `target.has_effect` about the
player, and the answer the wave is built around is the player reading
`self.has_effect` about themselves — a rule the plan editor has always been able
to express and that no fight had ever given a reason to write.

### 2.1 Encounters

| Encounter | Level | Tier | Gate | Vigor | Composition |
|-----------|-------|------|------|-------|-------------|
| `stretch1.patrol` | 2 | patrol | 1 | 10 | Blightling |
| `stretch1.swarm` | 4 | patrol | 4 | 10 | Blightling ×3 |
| `stretch1.stalker` | 5 | elite | 4 | 25 | Blight Stalker |
| `stretch2.causeway` | 7 | patrol | 7 | 10 | Causeway Husk |
| `stretch2.chanters` | 9 | patrol | 8 | 12 | Rot Chanter, Causeway Husk |
| `stretch2.lurkers` | 10 | patrol | 9 | 12 | Marsh Lurker ×2 |
| `stretch2.warren` | 11 | elite | 10 | 22 | Rot Chanter, Marsh Lurker ×2 |
| `stretch2.ravager` | 12 | elite | 11 | 25 | Causeway Ravager |
| `stretch2_5.span` | 13 | patrol | 12 | 13 | Span Leech ×2 |
| `stretch2_5.herald` | 14 | elite | 13 | 26 | Tide Herald, Span Leech |
| `stretch3.vault_watch` | 16 | patrol | 15 | 14 | Vault Sentinel |
| `stretch3.revenants` | 18 | patrol | 17 | 16 | Ash Revenant, Emberbound Thrall |
| `stretch3.sentinels` | 19 | elite | 18 | 28 | Vault Sentinel ×2, Emberbound Thrall |
| `stretch3.warden_of_ash` | 22 | **boss** | 20 | 40 | Warden of Ash, Emberbound Thrall ×2 |
| `stretch4.reach_watch` | 23 | patrol | 22 | 16 | Reach Pyrebinder |
| `stretch4.slag_line` | 25 | patrol | 24 | 18 | Slagborn Hulk, Reach Pyrebinder |
| `stretch4.execution` | 27 | elite | 26 | 32 | Slagborn Hulk, Reach Pyrebinder ×2, Reach Executioner |
| `stretch4.cinder_sovereign` | 30 | **boss** | 28 | 45 | Cinder Sovereign, Reach Pyrebinder, Reach Executioner |

The Reach's four encounters are a teaching sequence and the order is the design:
the mark alone, the mark with time to work, the mark with somebody to use it,
and one opponent doing all three at once.

`requiredLevel` sits one or two levels below the encounter's own level
throughout. A gate the player reaches slightly under-levelled is a challenge;
the level-difference falloff in [progression.md](progression.md) §1 already makes
over-levelled farming unrewarding, so no second gate is needed to forbid it.

---

## 3. Balance targets

Declared as tolerances in `EncounterBalanceTest` and enforced in CI, so a
content change that moves the difficulty curve fails the build rather than
reaching players. The win rates are measured against `CanonicalBuild` — a
competent but unlucky player: full attribute budget spent, a complete set of
**unaffixed** gear at item level equal to character level, and only as many
abilities as the level's loadout slots allow.

| Tier | At its gate | Two levels later |
|------|-------------|------------------|
| Patrol | 90–100% | 100% |
| Elite | 35–92% | 92–100% |
| Boss | 45–78% | 90–100% |

Patrols are near-certain because they are the content a player farms, and losing
a farm run to variance teaches nothing. Elites are where the stretch's mechanic
is examined. The boss sits with the elites on win rate but takes far longer, so
a loss reads as a fight that went wrong rather than one that was never winnable.

**No authored encounter may reach the 50-round round cap** at any level it can
legally be fought at. That is the one outcome the design calls a failure of the
encounter rather than of the player. See [combat.md](combat.md) §4.1 for why this
is narrower than "no draws".

---

## 4. Authoring rules

Enforced by JSON Schema (`content/schema/*.schema.json`), by each repository's
`validateContent()`, and by `bin/console content:validate` in CI:

1. **A player ability needs a discipline.** An ability is player-usable exactly
   when some discipline grants it, so a new player ability without an entry in
   `content/disciplines/` is unreachable, and a new *monster* ability must not
   have one. `DisciplineAvailabilityTest` pins unlock levels against the
   canonical builds the balance tolerances are declared against.
2. **Every id matches its type's pattern** and is unique across the whole
   library, not merely within a file.
3. **Every cross-reference resolves.** An ability's effect, a monster's
   abilities, an encounter's monsters and drop table, a drop table's item pool.
   Schema can check that an id is well-formed; only the repositories can check
   that the thing exists.
4. **A monster's battle plan may only name abilities that monster knows**, and
   its **final rule must be unconditional and use a zero-cost, no-cooldown
   ability**. Otherwise a monster can reach a state where it cannot act, and it
   will do so mid-fight, in front of a player.
5. **Every definition declares a localisation key.** A missing key renders as a
   raw id in the client, which players report as a bug.
6. **An encounter's `requiredLevel` may not exceed its own `level`.**
7. **No hardcoding.** If a change to a monster, ability, effect, encounter or
   drop table needs a PHP change, the vocabulary is missing something — extend
   the vocabulary rather than special-casing the content.

### 4.1 Adding a stretch

1. Decide the **mechanical step** first. A stretch whose step is "bigger
   numbers" is rejected: that is stat inflation, and it is what the stat budget
   discipline in [progression.md](progression.md) §2.2 exists to prevent.
2. Author effects, then abilities, then monsters, then encounters, then drop
   tables — each layer only references the one below it.
3. Add a discipline for every new *player* ability, with an unlock level at or
   before the content that needs it. Monster abilities get none.
4. Add a localisation entry for every new id in `frontend/src/lib/i18n.ts`.
5. Add tolerances to `EncounterBalanceTest` for each new encounter at its gate,
   and for elites and bosses at the level they should become routine.
6. Run `make content-validate` and the backend suite.

---

## 5. Known gaps

Recorded rather than quietly tolerated:

- **Off-hand items cannot be authored.** `EquipmentSlot::OffHand` carries an
  armour weight of 12000 — a shield's share of the curve — but every off-hand
  item is rejected by `YamlItemDefinitionRepository::validateContent()`, which
  requires a weapon class on any hand slot. The rule conflates "hand slot" with
  "weapon"; `ItemDefinition` itself is happy with an off-hand that has none. The
  fix is to narrow that rule to `MainHand`, and it is a PHP change rather than a
  content one, which is why no wave has quietly worked around it.
- **A new player ability is not exercised by the balance suite until the
  loadout has room for it.** `CanonicalBuild::PRIORITY` is append-only on
  purpose — reordering it re-tunes every tolerance declared against the old
  order — so `ability.warding_flame` sits eighth and is not slotted until level
  36, well past the content it answers. The tolerances for stretch 4 therefore
  describe a player fighting the Cinder Reach *without* the Reach's own answer,
  which is a conservative floor rather than a wrong one. Revisiting the priority
  list is a deliberate re-tuning exercise, not a side effect of a content wave.
- **The beacon-line stops at level 31**, and the level-source discipline
  catalogue stops at 22: `loadoutSlotsAt` keeps granting a slot every six
  levels, so a character past level 30 accumulates room faster than the game
  gives them anything to put in it. The dungeon discipline pool
  ([progression.md](progression.md) §4.2) partly answers this, but it is a
  collection mechanic rather than a level curve, so it cannot be relied on to
  fill a slot at a particular level.
- **`self.focus` and `self.has_effect` are the two condition subjects no
  authored content uses.** `self.has_effect` is deliberate for now — it is the
  *player's* half of stretch 4's mechanic, and nothing in the bestiary has yet
  wanted to read an effect on itself — but a subject no content demonstrates is
  a subject most players never discover.
