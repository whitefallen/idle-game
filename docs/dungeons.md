# Dungeons

The "hard avenue": a multi-encounter run resolved live in one request,
gated by consuming a key material a kill quest can reward. Unlike Quest,
which resolves against a frozen snapshot after a wait (see
[ADR-0008](adr/0008-quest-snapshot-resolution.md)), a dungeon run is fought
with the character's current stats, stage by stage, in real time — it's
occasional, sit-down content rather than an idle expedition.

`backend/src/Feature/Dungeon/` owns it. See [architecture.md](architecture.md)
section 3 for the feature-first layout.

---

## 1. Built

One archetype today: a `DungeonDefinition` names an ordered list of existing
`EncounterDefinition`s (2-6 stages), a single key material consumed on entry,
and a completion bonus (flat XP/gold) plus an optional drop-table roll on a
full clear. A run stops at the first non-Victory stage; rewards already
granted for cleared stages stand. Entry is not currently capped — nothing
stops repeated clears of the same dungeon, given a fresh key each time.

- `GET /api/v1/characters/{characterId}/dungeons`
- `POST /api/v1/characters/{characterId}/dungeons/{dungeonId}/enter`

Content: `content/dungeons/`, schema `content/schema/dungeon.schema.json`.

---

## 2. Proposed: two dungeon archetypes

Design conversation following the Quest/Dungeon build settled on splitting
Dungeon into two distinct shapes, both reusing the same underlying feature
rather than becoming separate systems:

| | Key-gated | Material-gated |
|---|---|---|
| Entry cost | A key material, quest-earned | A quantity of ordinary materials |
| Repeatable | **No** — one clear per character, ever | Yes — a proper grind |
| Signature reward | A discipline, picked from a pool (section 3) | Normal XP/gold/materials/items, same shape as today |
| Purpose | Build-defining, permanent character divergence | The second materials sink refinement alone doesn't provide |

Mechanically this is one generalization on `DungeonDefinition`, not a fork:

- `keyMaterialId` (single material, implicit quantity 1) widens to a **cost
  map** (`{materialId: quantity, ...}`). A key is the degenerate case,
  `{material.dungeon_key: 1}`; a material-sink dungeon might be
  `{material.emberash: 10, material.slagiron: 3}`.
- A new `repeatable: bool`. When `false`, entry is refused if the character
  has already cleared this dungeon — checked against `DungeonRun` history for
  `(character_id, dungeon_id)`, not inferred from key scarcity. Key scarcity
  today makes repeat entry to the one existing dungeon impossible almost by
  accident (one quest grants one key); an explicit flag stops that becoming
  false the moment a second key-granting quest is authored.
- The discipline-pick reward (section 3) is exclusive to `repeatable: false`
  dungeons. Repeatable ones keep today's reward shape unchanged.

---

## 3. Proposed: the discipline collection pool

The mechanism for player-to-player build divergence, replacing the two
rejected shapes that came up along the way: quest chains (scratched — quests
stay standalone) and a random per-encounter drop chance (scratched — see
[progression.md](progression.md) section 4.1's "no random discipline drops"
principle, which this is designed to respect rather than violate).

**The shape:**

- A **per-character** pool of dungeon-tier disciplines. Not shared or
  competitive across players — two characters can end up with the identical
  set, or completely different ones, purely from the choices each one made.
- Clearing any key-gated (`repeatable: false`) dungeon draws
  `min(3, remaining pool size)` disciplines from that character's remaining
  pool and presents them as a choice.
- The player **must explicitly pick and confirm** — even when there is only
  one option on offer. No auto-grant, ever. Consistency of the ritual matters
  more than saving a click, and the one time there's truly only one option is
  the most significant pick a player makes in this system (see below).
- The chosen discipline leaves the pool permanently. The declined ones
  **return to the pool** — not lost, just not guaranteed to reappear next
  time either.
- **One clear grants exactly one discipline, always.** There is no
  larger-pool-therefore-more-picks scaling; the ratio is fixed.
- **Discipline-granting dungeons are their own category, not a flag on
  regular ones.** They are the *only* source of these disciplines — nothing
  else grants them, and a regular (material-gated, repeatable) dungeon never
  does either, even though both share the same underlying `Dungeon` feature.
- **Supply ships exact, every time, not "roughly matched."** Every batch of
  content adds *N* discipline-dungeons and *exactly N* new disciplines to the
  pool — never more dungeons than disciplines, never more disciplines than
  dungeons. This is a hard authoring rule, not a target to aim near.
- **The terminal case follows from that exactness, not from luck.** With
  supply always exact, a character's *last* discipline-dungeon clear — of
  whichever ones exist at the time, in whatever order the player chose to
  clear them — always lands on exactly one remaining option. Not "usually,"
  not "if the numbers work out": guaranteed, by construction. That is the
  intended, narratively marked end of that character's *current* collection,
  until a new content wave adds the next matched batch.

**Why this doesn't reopen "no random discipline drops."** The rejected
version was a chance to receive nothing. This is never that — a clear always
grants a discipline, deterministically, once the player confirms a pick. The
only randomness is in *which* options are on the table, not *whether* the
player is rewarded, which is closer to a raid-style "choose one of three
drops" pattern than to a lottery.

---

## 4. Settled during design

- **Content math.** Not "roughly matched" — exact. Every content wave ships
  the same number of new discipline-dungeons as new disciplines. See section
  3.
- **Tier/rarity flavour.** Dropped for this design. It existed only to
  solve running low on options before a pick, and the exact-supply,
  shrink-to-one shape already solves that on its own — a tier axis would add
  a second content-authoring dimension with no mechanical job left to do.
  Revisit only as pure presentation (a badge on the pick card) if wanted
  later; it shouldn't touch the pool logic.
- **Persistence shape.** No separate pool table. A character's offered three
  are drawn from (all discipline-dungeon disciplines minus the ones this
  character already owns) — reusing the same `character_discipline`
  ownership row `progression.md`'s "Ownership is derived, not stored"
  section already anticipated. The "pool" is a query, not stored state.
- **Reward shape.** The discipline pick is a bonus layer on the existing
  completion-bonus-plus-drop-table reward, not a replacement — a
  discipline-dungeon clear still pays normal XP/gold/materials/items on top.
  Kept mainly for consistency with how every other dungeon clear already
  pays out, now that exact supply means there's no undersupply risk left to
  actively defend against.
- **Copy for the last pick.** No special-casing — the picker looks and reads
  exactly the same whether it's offering one option or three. Consistency of
  the ritual wins over marking the moment; the significance is already
  carried by the mechanic (this is the one they didn't get to decline), not
  by the UI calling attention to itself.

---

## 5. Status

Nothing in sections 2-4 is built. Section 1 is the entirety of what exists
today.

This supersedes half of a decision recorded in
[progression.md](progression.md) section 4.2: dungeon-granted disciplines
were marked "dropped, not merely deferred" after the initial Quest/Dungeon
build, on the grounds that the existing flat reward shape (XP, gold,
materials) was sufficient on its own. This document is that decision being
deliberately reopened for the *dungeon* half specifically, per direction to
give players real build-to-build uniqueness. **Quest-granted disciplines
remain out of scope** — quests stay standalone, flat-reward, one-time; only
Dungeon's reward shape is gaining this.
