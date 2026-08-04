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
| **3 — The Ashen Vault** | 15–22 | **Phases.** The Warden of Ash changes behaviour with its health and the round number, and brings adds | Conditional rules — `target.health_percent`, `round` — instead of a priority list |

The gap between stretch 2 (ends at 12) and stretch 3 (opens at 15) is
deliberate: it is where a stretch 2.5 wave lands, and leaving it empty is
honest about the fact that it has not been designed yet.

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
| `stretch3.vault_watch` | 16 | patrol | 15 | 14 | Vault Sentinel |
| `stretch3.revenants` | 18 | patrol | 17 | 16 | Ash Revenant, Emberbound Thrall |
| `stretch3.sentinels` | 19 | elite | 18 | 28 | Vault Sentinel ×2, Emberbound Thrall |
| `stretch3.warden_of_ash` | 22 | **boss** | 20 | 40 | Warden of Ash, Emberbound Thrall ×2 |

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

1. **Every id matches its type's pattern** and is unique across the whole
   library, not merely within a file.
2. **Every cross-reference resolves.** An ability's effect, a monster's
   abilities, an encounter's monsters and drop table, a drop table's item pool.
   Schema can check that an id is well-formed; only the repositories can check
   that the thing exists.
3. **A monster's battle plan may only name abilities that monster knows**, and
   its **final rule must be unconditional and use a zero-cost, no-cooldown
   ability**. Otherwise a monster can reach a state where it cannot act, and it
   will do so mid-fight, in front of a player.
4. **Every definition declares a localisation key.** A missing key renders as a
   raw id in the client, which players report as a bug.
5. **An encounter's `requiredLevel` may not exceed its own `level`.**
6. **No hardcoding.** If a change to a monster, ability, effect, encounter or
   drop table needs a PHP change, the vocabulary is missing something — extend
   the vocabulary rather than special-casing the content.

### 4.1 Adding a stretch

1. Decide the **mechanical step** first. A stretch whose step is "bigger
   numbers" is rejected: that is stat inflation, and it is what the stat budget
   discipline in [progression.md](progression.md) §2.2 exists to prevent.
2. Author effects, then abilities, then monsters, then encounters, then drop
   tables — each layer only references the one below it.
3. Add a localisation entry for every new id in `frontend/src/lib/i18n.ts`.
4. Add tolerances to `EncounterBalanceTest` for each new encounter at its gate,
   and for elites and bosses at the level they should become routine.
5. Run `make content-validate` and the backend suite.

---

## 5. Known gaps

Recorded rather than quietly tolerated:

- **The item corpus has not kept pace.** `pool.stretch1` is still the only
  authored item pool, so stretch 2 and 3 drop tables draw from it at higher item
  levels. That works — item level, not the pool, is what scales an item's power
  ([items.md](items.md) §3) — but it means deeper content drops the same three
  bases. Adding items tagged `pool.stretch2` / `pool.stretch3` and repointing the
  `pool` field is the whole fix.
- **`material.blightcore` has no consumer yet.** It drops from stretch 2 elites
  and stretch 3 content and is intended for refinement; until the refinement
  system exists it accumulates.
- **Player abilities are authored ahead of the discipline system** that will
  grant them, exactly as the core kit was. A character currently begins with —
  and keeps — the starting loadout, so the abilities added for stretches 2 and 3
  are reachable in tests and in the balance simulator but not yet in play.
- **Levels 13–14 and 23+ have no content.**
