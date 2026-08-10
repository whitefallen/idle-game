# Progression

All formulas here are **integer arithmetic**, evaluated left to right with
truncating (toward zero) division at every step. Fractional quantities are
expressed in basis points (bp), where 10000 bp = 100%. See
[ADR-0002](adr/0002-integer-deterministic-combat.md).

Every constant marked `(tunable)` lives in `content/config/progression.yaml`,
not in PHP. Structural constants are noted as such.

---

## 1. Levels

**Level cap: 60** at launch (structural — raising it is a content release, and
the curve below is designed to extend without a discontinuity).

```
xpToNext(L) = 60 * L^2 + 140 * L          (tunable coefficients)
```

| L | XP to reach L+1 | Cumulative XP to reach L |
|---|-----------|-----------|
| 1 | 200 | 0 |
| 5 | 2,200 | 3,200 |
| 10 | 7,400 | 23,400 |
| 20 | 26,800 | 174,800 |
| 30 | 58,200 | 574,200 |
| 45 | 127,800 | 1,900,800 |
| 60 | — (cap) | 4,460,400 |

These figures are pinned by `ProgressionRulesTest`, so the table and the
implementation cannot drift apart. At the level cap there is no next level and
surplus experience is discarded rather than banked: a hidden buffer that
silently drains into the next level on a cap raise is impossible for a player
to reason about.

The curve is quadratic rather than exponential on purpose. Exponential XP curves
force exponential reward curves, which force exponential stat curves, and within
two years the numbers stop fitting in a player's head and start stressing the
database. Quadratic growth keeps the ratio of "time to level N+1" over "time to
level N" approaching 1, so late levels feel like steady work rather than a wall.

**Level-difference falloff.** XP awarded for an encounter is scaled by the gap
between character level and encounter level:

```
delta       = encounterLevel - characterLevel
scaleBp     = clamp(10000 + 500 * delta, 1000, 12500)      (tunable)
xpAwarded   = baseXp * scaleBp / 10000
```

So fighting 5 levels below yields 75%, 10 below yields 50%, and the floor of 10%
is reached at 18 levels below. This makes farming trivial content
deliberately inefficient without forbidding it — players who want to help a
friend or complete a low-level quest are not blocked, merely not rewarded for
staying there.

**Base encounter XP:**

```
baseXp = (12 * encounterLevel + 40) * tierMultiplierBp / 10000      (tunable)
```

Tier multipliers: patrol 10000, elite 25000, dungeon boss 60000.

---

## 2. Attributes

Five primary attributes, fixed by design (structural):

| Attribute | Abbrev | Primary role |
|-----------|--------|--------------|
| Strength | STR | Scales heavy weapons; carrying capacity |
| Dexterity | DEX | Scales light weapons; dodge; initiative |
| Intelligence | INT | Scales focus weapons; ability potency; resource pool |
| Constitution | CON | Health; effect resistance |
| Luck | LUK | Critical chance and power; loot quality roll floor |

### 2.1 Allocation

- A new character begins at level 1 with **5 in each attribute** and **10
  unspent points**.
- Each level grants **5 points** (tunable).
- Total allocatable at level 60: `10 + 59 * 5 = 305`, on top of the 25 base.
- Points may be freely reallocated via **respec** for gold (see
  [economy.md](economy.md) §4). There is no limit on respec frequency and no
  cooldown; the cost is the only brake.

Equipment adds to attributes on top of allocation. Allocated and equipped
attribute values are tracked separately so that unequipping never puts a
character into an invalid state.

Only **allocated** attributes count toward an item's requirements. Equipment
bonuses deliberately do not: letting one item's attribute grant satisfy another
item's requirement makes a set of items mutually load-bearing, so removing any
one of them can cascade through the rest.

Because a respec can drop an allocated attribute below what a worn item
demands, **respec unequips gear it invalidates** and reports what came off. The
alternative — leaving it worn, since requirements are otherwise checked only at
the moment of equipping — would let a player allocate into a requirement, equip,
respec into a different build and keep the gear, which is a server-authority
hole rather than a convenience. The item is kept, only not worn.

### 2.2 Stat budget discipline

A hard design rule, not a suggestion: **no derived stat may exceed roughly 4× its
level-1 value at level 60 from allocation alone, or roughly 12× including
best-in-slot equipment.** Any proposed system that would breach this budget is a
design error and must be redesigned rather than accommodated.

This is the single most important balancing constraint in the project. Games in
this genre die of stat inflation: each content patch adds a tier, each tier
multiplies numbers, and eventually old content is meaningless, damage numbers
need scientific notation, and the combat formula's soft caps stop behaving. A
bounded budget forces new tiers to add *mechanics* instead of *zeroes*.

---

## 3. Derived values

Derived values are **computed, never persisted**, with exactly one documented
exception ([ADR-0006](adr/0006-denormalised-power-score.md)).

Let `L` = level. Attribute names refer to the total (allocated + equipment).

```
maxHealth        = 50 + 12 * CON + 8 * L

initiative       = 10 * DEX + 5 * L

critChanceBp     = min(5000,  500 + 25 * LUK)
critPowerBp      = min(25000, 15000 + 20 * LUK)

dodgeChanceBp    = min(2500,  200 + 12 * DEX)
accuracyBp       = 100 * (equipment accuracy affixes)

scalingBp        = 10000 + 70 * <weapon's scaling attribute>

armourRating     = sum of equipment armour values
damageReductionBp(attackerLevel) =
      min(7500, 10000 * armourRating / (armourRating + 60 * attackerLevel + 300))

resistanceBp(school) =
      min(7500, 10000 * resist / (resist + 60 * attackerLevel + 300))
```

Notes on the shape of these:

- **Health is linear in CON** so that survivability and damage stay comparable;
  a quadratic health term would make tank builds strictly dominant late.
- **Armour uses a hyperbolic curve with a level term in the denominator.** This
  gives diminishing returns (no immunity), and the `attackerLevel` term means
  armour naturally decays in relative value against higher-level enemies without
  any explicit rescaling patch. Both caps are 75%, chosen so that even a fully
  dedicated defensive build takes meaningful damage.
- **Dodge is capped at 25%** — much lower than armour, because dodge is
  all-or-nothing and high dodge produces wildly swingy, unsatisfying fights.
- **Initiative ties are broken by ascending entity UUID**, never by insertion
  order or wall-clock, so turn order is reproducible. This is a determinism
  requirement, not a gameplay choice.

---

## 4. Disciplines

A **discipline** is an unlockable ability or passive. Disciplines are the third
progression axis (after level and gear) and the primary source of build identity.

### 4.1 Structure

- Disciplines are **data**, defined in `content/disciplines/*.yaml`. No
  discipline is implemented as a bespoke PHP class; each is a composition of
  parameterised **effect primitives** (damage, heal, apply-effect, modify-stat,
  cleanse, shield, summon). Adding a discipline is a content change.
- A character has **loadout slots**: 2 at level 1, +1 every 6 levels, to a
  maximum of **11** at level 60 (tunable).
- Owning a discipline is permanent. Slotting is what is limited, and slotting is
  free to change outside combat.

A discipline is the **unlock**; the ability is what it grants. Keeping the two
separate is what will let a reputation vendor offer an alternative discipline
granting a variant of an ability the player already has, without either needing
a special case in code.

It is also what distinguishes a player ability from a monster one: **an ability
is player-usable exactly when some discipline grants it.** That test lives in
data rather than in a naming convention or an `is_monster` flag, so adding a
monster ability can never accidentally hand it to players.

**Ownership is derived, not stored.** Every discipline today is granted by a
level milestone, which is a pure function of the character's level — so there is
no grant step, no column, no backfill, and no way for a stored set to drift from
the rules. When quest, dungeon and reputation sources arrive, ownership becomes
the union of the derived set and a stored one; only the non-derivable half needs
persisting.

**Unslotting an ability the battle plan still uses is refused**, not silently
repaired. A plan is authored, sometimes carefully, and quietly deleting a rule
from it is a worse outcome than being told which rule is in the way. The order
of operations is therefore: change the plan, then change the loadout.

The whole catalogue is exposed through the API, locked entries included, with
the level that grants each one. A progression axis the player cannot see ahead
of is one they cannot plan around — the same principle as §5.

### 4.2 Acquisition

| Source | Character of the disciplines gained |
|--------|-------------------------------------|
| Level milestones | The baseline kit; guarantees every character has options |
| Dungeon clears (proposed) | Build-defining, permanent per-character divergence — see [dungeons.md](dungeons.md) section 3 |
| Reputation vendors | Alternative versions of earlier disciplines, for specialisation |

**Quest-granted disciplines are dropped, not merely deferred.** Quest
([ADR-0008](adr/0008-quest-snapshot-resolution.md)) is built, and its reward
shape — fixed XP, gold and materials — was judged sufficient on its own;
quests stay standalone and flat-reward. `DisciplineSource::Quest` stays in
the enum rather than being removed, but no content authors against it, and
reopening this is a scope decision, not a backlog pull.

**Dungeon-granted disciplines were dropped and then deliberately reopened.**
The same reasoning applied to Dungeon initially, but a follow-up design pass
settled on a concrete, non-random mechanism aimed at giving players real
build-to-build uniqueness — a per-character discipline pool, drawn from on
a dungeon clear, never a chance of nothing. See
[dungeons.md](dungeons.md) sections 2-4 for the full shape — design is
settled, nothing is built. `DisciplineSource::Dungeon`'s ownership-union
support (see "Ownership is derived, not stored" above) is what it will build
on once it is.

Deliberately **no random discipline drops**. Build-defining progression must not
be gated behind a drop roll; that converts strategy into lottery participation
and is the primary complaint pattern in comparable games.

### 4.3 Costs

Abilities consume **Focus**, a per-encounter resource:

```
maxFocus       = 30 + 2 * INT                     (tunable)
focusPerTurn   = 5 + INT / 10                     (tunable, regenerated each turn)
```

Focus makes the battle plan interesting: an unaffordable ability falls through
to the next rule, so plans must be written with resource pressure in mind.

---

## 5. Content gating

Content unlocks on **explicit, inspectable requirements**, never on opaque
progress:

| Gate type | Example |
|-----------|---------|
| Level | Beacon-line stretch 4 requires L24 |
| Item level | Dungeon tier 3 requires average equipped item level 28 |
| Quest completion | Stretch 5 requires the stretch 4 chain |
| Reputation | Vendor tier 2 requires Honoured with the order |

The "quest completion" gate above describes chained content gating, which
needs the quest-prerequisite concept §4.2 also flags as not yet built. Today's
quests gate only on character level, the same as encounters and dungeons.

Every gate is exposed through the API as a structured, localisable requirement
object so the UI can always tell the player exactly what is missing and how far
away it is. "You cannot do this yet" without a reason is considered a bug.

**Item-level gates use the average of equipped items**, not the maximum, so
players cannot bypass a gate with one lucky drop, and not the minimum, so an
empty ring slot does not lock a player out.

---

## 6. Progression simulator

A required deliverable alongside the progression system, not an optional tool:
a headless PHP simulator that runs synthetic players (active / casual / idle)
through the content curve and reports time-to-level, gold flow, material flow
and encounter win rates per tier.

It exists because every number in this document is a guess until it is
simulated, and because balance regressions must be caught by CI rather than by
players. Balance changes to `content/config/*.yaml` should fail the build if
they push key metrics outside declared tolerances.
