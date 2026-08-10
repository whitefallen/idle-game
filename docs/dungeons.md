# Dungeons

The "hard avenue": a multi-encounter run resolved live in one request. Unlike
Quest, which resolves against a frozen snapshot after a wait (see
[ADR-0008](adr/0008-quest-snapshot-resolution.md)), a dungeon run is fought
with the character's current stats, stage by stage, in real time — it's
occasional, sit-down content rather than an idle expedition.

`backend/src/Feature/Dungeon/` owns it, with one cross-feature dependency:
discipline grants call into `App\Feature\Character\Application\
GrantDisciplineHandler` directly, the same documented-debt pattern as every
other reward-granting call in this codebase — see
[ADR-0007](adr/0007-synchronous-domain-event-bus.md)'s Costs section. See
[architecture.md](architecture.md) section 3 for the feature-first layout.

---

## 1. Two dungeon archetypes

One `DungeonDefinition` content type, two shapes, distinguished by
`repeatable`:

| | Key-gated (`repeatable: false`) | Material-gated (`repeatable: true`) |
|---|---|---|
| Entry cost | A key material, quest-earned | A quantity of ordinary materials |
| Repeatable | **No** — one clear per character, ever | Yes — a proper grind |
| Signature reward | A discipline, picked from a pool (section 2) | Normal XP/gold/materials/items |
| Purpose | Build-defining, permanent character divergence | The second materials sink refinement alone doesn't provide |

`cost` is a material-id-to-quantity map (`{materialId: quantity, ...}`),
checked in full and consumed in full or not at all — a key-gated dungeon's
cost is just the one-material degenerate case. `repeatable: false` entry is
refused once `DungeonRunRepository::hasCleared()` shows a prior **cleared**
run for that `(character, dungeon)` pair — a failed attempt does not lock the
player out, only a clear does.

A run stops at the first non-Victory stage; rewards already granted for
cleared stages stand. A repeatable dungeon has no cap beyond affording the
cost again.

- `GET /api/v1/characters/{characterId}/dungeons`
- `POST /api/v1/characters/{characterId}/dungeons/{dungeonId}/enter`

Content: `content/dungeons/`, schema `content/schema/dungeon.schema.json`.
`content/dungeons/stretch1.yaml` has one of each archetype:
`dungeon.stretch1.blight_hollow` (repeatable, material sink) and
`dungeon.stretch1.sealed_vault` (one-time, discipline-granting).

---

## 2. The discipline collection pool

The mechanism for player-to-player build divergence, in place of the two
rejected shapes that came up along the way: quest chains (scratched — quests
stay standalone) and a random per-encounter drop chance (scratched — see
[progression.md](progression.md) section 4.1's "no random discipline drops"
principle, which this respects rather than violates: a clear always grants a
discipline, deterministically, once the player confirms a pick — the only
randomness is in *which* options are on the table, closer to a raid-style
"choose one of three drops" than to a lottery).

**The shape, as built:**

- A **per-character** pool. Not shared or competitive across players — two
  characters can end up with the identical set, or completely different
  ones, purely from the choices each one made. There is no stored "pool"
  table: `EnterDungeonHandler::offerDisciplines()` computes it live, as all
  `source: dungeon` disciplines minus this character's
  `CharacterDisciplineRepository::idsForCharacter()` — the stored half
  `DisciplineRepository`'s own docblock already anticipated.
- On a full clear of a `repeatable: false` dungeon, `min(3, remaining)` are
  drawn and stored on the `DungeonRun` row as `offeredDisciplineIds`. An
  empty list is a valid, distinct state — this character's pool was already
  exhausted, and the clear's other rewards (completion bonus, drop table)
  still apply unchanged.
- Confirming a pick is a **separate request** —
  `POST /characters/{characterId}/dungeons/runs/{runId}/discipline` — from
  entering the dungeon, always required even when only one option was
  offered. No auto-grant. `DungeonRun::pickDiscipline()` refuses an id that
  was not actually offered, and refuses a second pick on the same run.
- The chosen discipline leaves the pool permanently (a `character_discipline`
  row is inserted via `GrantDisciplineHandler`). The declined ones are simply
  never removed — they remain part of the live-computed pool, available at
  the next clear.
- **One clear grants at most one discipline, always** — never more, per the
  fixed `min(3, remaining)` draw and the one-time nature of the dungeons that
  offer it.
- **Supply ships exact.** `content/dungeons/stretch1.yaml` +
  `content/disciplines/dungeon.yaml` shipped together: one discipline-granting
  dungeon (`sealed_vault`), one discipline (`discipline.stonebreaker`). Every
  future content wave adding a discipline-granting dungeon should add an
  equal number of new disciplines alongside it — a hard authoring rule, not a
  target to aim near.
- **The terminal case follows from that exactness, not from luck.** A
  character's last discipline-dungeon clear always lands on exactly one
  remaining option, by construction, and that is the intended, narratively
  marked end of that character's *current* collection — not a degraded state
  to route around.

---

## 3. Ownership: the stored half

`DisciplineRepository::availableAtLevel()` / `grantedAbilityIdsAtLevel()`
each take an `$ownedIds` parameter (default `[]`, so every pre-existing
level-only call site needed no change) and return the union of the
level-derived set and the ids passed in. `CharacterPresenter` and
`UpdateLoadoutHandler` are the two call sites that now fetch a character's
owned ids from `CharacterDisciplineRepository` and pass them through — the
first is what makes a dungeon-granted discipline show `unlocked: true` in the
API, the second is what makes its ability actually slottable.

`character_discipline` (migration `Version20260810170000`) is a row-per-grant
table: existence of a row *is* ownership, no quantity, no revocation. This is
the first source to use it; `DisciplineSource::Quest` and `::Reputation`
remain unused in content — see [progression.md](progression.md) section 4.2
for why quest-granted disciplines stay out of scope while dungeon-granted
ones don't.

---

## 4. Settled during design

Recorded here as the reasoning behind choices in sections 1-3, in case any of
it needs revisiting later:

- **Content math.** Exact, not "roughly matched" — see section 2.
- **Tier/rarity flavour.** Dropped. It existed only to solve running low on
  options before a pick, and the exact-supply, shrink-to-one shape already
  solves that on its own — a tier axis would add a second content-authoring
  dimension with no mechanical job left to do. Revisit only as pure
  presentation (a badge on the pick card) if wanted later; it shouldn't touch
  the pool logic.
- **Reward shape.** The discipline pick is a bonus layer on the existing
  completion-bonus-plus-drop-table reward, not a replacement — kept mainly
  for consistency with how every other dungeon clear already pays out.
- **Copy for the last pick.** No special-casing — the picker looks and reads
  exactly the same whether it's offering one option or three. Consistency of
  the ritual wins over marking the moment.

---

## 5. Status

Built: everything in sections 1-3. `dungeon.stretch1.blight_hollow` (the
original dungeon) was repurposed from key-gated to the repeatable,
material-gated archetype as part of this work — its encounters and
completion bonus are unchanged, only its cost and repeatability.

This closes out a decision recorded in [progression.md](progression.md)
section 4.2: dungeon-granted disciplines were marked "dropped, not merely
deferred," then deliberately reopened, and are now built — the union
mechanism `DisciplineRepository`'s own docblock anticipated from the start is
finally exercised. **Quest-granted disciplines remain out of scope** — quests
stay standalone, flat-reward, one-time; only Dungeon's reward shape gained
this.
