# Items

Everything in this system is data. There are no hardcoded items, no hardcoded
affixes, and no `switch` statements on item name anywhere in the codebase. A
content designer adds an item by writing YAML.

---

## 1. Definition vs instance

The single most important distinction in the item system:

| | **ItemDefinition** | **ItemInstance** |
|---|---|---|
| What | The template: "Warden's Halberd" | One specific rolled copy owned by a player |
| Where | `content/items/*.yaml`, loaded and cached | `item_instance` table |
| Mutable | Only by content release | Yes — refinement, durability, binding |
| Count | Thousands | Millions |

An instance stores a **reference to its definition plus its rolled state** — it
never copies the definition's static data. If instances duplicated base stats,
every balance change to a base item would require a migration over millions of
rows, and old and new copies of the same item would silently diverge.

The exception is items whose definition is *retired*: retired definitions are
never deleted, only flagged, so existing instances stay valid. Content is
append-only.

---

## 2. Slots

Ten equipment slots (structural):

```
Head    Chest   Legs    Hands   Feet
MainHand   OffHand   Amulet   Ring1   Ring2
```

`Ring1` and `Ring2` accept the same definitions; two-handed weapons occupy
`MainHand` and lock `OffHand`. Slot legality is declared in the definition and
validated server-side on every equip.

---

## 3. Item level

`itemLevel` (`ilvl`) drives all magnitude. It is the only knob that scales an
item's power with content tier.

```
weaponBaseDamage = (8 + 4 * ilvl) * weaponClassBp  / 10000
armourValue      = (5 + 3 * ilvl) * slotWeightBp   / 10000
```

| Weapon class | `weaponClassBp` | Scales with | Speed |
|---|---|---|---|
| Heavy (halberd, maul) | 13000 | STR | Slow — higher coefficient, lower initiative |
| Light (blade, dagger) | 9000 | DEX | Fast |
| Focus (rod, sigil) | 10000 | INT | Medium, ability-oriented |

| Slot | `slotWeightBp` |
|---|---|
| Chest | 13000 |
| Legs | 11000 |
| Head | 9000 |
| Hands, Feet | 7000 |
| OffHand (shield) | 12000 |
| Amulet, Rings | 0 (affix-only) |

Rings and amulets carry no base stats at all — they are pure affix vehicles.
This gives them a distinct role (build customisation rather than raw power) and
keeps the number of stat sources bounded.

---

## 4. Rarity and affixes

| Rarity | Affixes | Drop weight (tunable) |
|---|---|---|
| Common | 0 | 5500 |
| Uncommon | 1–2 | 3000 |
| Rare | 3 | 1200 |
| Epic | 4 | 280 |
| Legendary | 4 + one **unique property** | 20 |

Rarity affects **affix count**, not base stats. A Common and a Legendary of the
same base at the same ilvl have identical base damage. This keeps the base-item
curve clean and means an early Legendary is exciting without being a tier skip.

### 4.1 Affix structure

Affixes are drawn from **prefix** and **suffix** pools, filtered by slot and
gated by ilvl. Each affix has tiers; higher tiers unlock at higher ilvl and roll
larger values.

```yaml
# content/affixes/prefixes.yaml
- id: prefix.tempered
  localisationKey: affix.prefix.tempered
  slots: [Head, Chest, Legs, Hands, Feet, OffHand]
  modifier: { stat: armourValue, mode: flat }
  tiers:
    - { tier: 1, minIlvl: 1,  roll: [2, 5]   }
    - { tier: 2, minIlvl: 15, roll: [6, 14]  }
    - { tier: 3, minIlvl: 30, roll: [15, 28] }
    - { tier: 4, minIlvl: 45, roll: [29, 48] }
```

Rules:

- An affix appears **at most once** per item.
- Prefix/suffix split exists so that "more damage" and "more survivability"
  cannot both be maximised on one item without cost.
- Rolled values are stored on the instance (`affixes` JSONB: id, tier, value).
  The roll is stored, not re-derived, because the drop RNG is not part of the
  combat ruleset and must not shift when drop tables are rebalanced.
- **Modifier application order** is fixed: flat modifiers first, then percentage
  modifiers, both sorted by affix id. Determinism applies here too.

### 4.2 Unique properties

Legendary items carry one **unique property** — a named effect, not a stat, drawn
from the same effect-primitive vocabulary as disciplines
([progression.md](progression.md) §4). Example: *"Rupture also applies Burning."*

Unique properties are the intended source of build-defining surprise. They are
constrained by the stat budget rule: a unique property may change *how* a build
works, but may not multiply its output beyond the budget. Any proposed unique
property that reads as "+X% damage" is rejected — that is what affixes are for.

---

## 5. Refinement

The primary sink for passively accrued materials and the main reason offline
time matters. See [idle.md](idle.md).

- Refinement level: **+0 to +10** (structural).
- Effect: each level adds **4% of the item's base stats** (tunable). At +10 an
  item has +40% base — significant, bounded, and inside the stat budget.
- Refinement **always succeeds.** There is no failure chance, no destruction, no
  protection item.

That last point is a deliberate rejection of the genre norm. Random enhancement
failure is a gambling mechanic; it converts a player's accumulated effort into a
coin flip, it is the standard hook for predatory monetisation (sold protection
scrolls), and it makes progression impossible to plan. Escalating deterministic
cost achieves the same pacing without any of that.

```
goldCost      = 40 * ilvl * (refineLevel + 1)^2                    (tunable)
materialCost  = ceil(ilvl / 5) * (refineLevel + 1)  of tier-matched material
```

Refining a level-30 item from +0 to +10 costs roughly 460,000 gold and a
sustained material supply — a multi-week project for a mid-game character, and
exactly the kind of long-horizon goal the passive layer is designed to feed.

Refinement is **bound to the instance** and lost if the item is destroyed. It
does not transfer.

---

## 6. Durability

Every equipped item has durability, reduced by 1 per encounter (0 on a draw).
At 0 durability the item contributes **no stats** but is never destroyed.

Repair cost:

```
repairCost = 3 * ilvl * (maxDurability - currentDurability)         (tunable)
```

Durability exists as a **continuous gold sink proportional to activity**, which
is the property a stable economy most needs (see [economy.md](economy.md) §3).
Item destruction is deliberately excluded: it punishes inattention rather than
bad decisions, and it is a common cause of account abandonment.

---

## 7. Definition schema

```yaml
# content/items/weapons/heavy.yaml
- id: item.wardens_halberd
  localisationKey: item.wardens_halberd
  slot: MainHand
  twoHanded: true
  weaponClass: heavy
  ilvl: 24
  icon: items/weapons/wardens_halberd
  vendorValue: 1450
  requirements:
    level: 22
    attributes: { STR: 40 }
  allowedAffixPools: [weapon_prefix, weapon_suffix]
  tags: [warden, halberd, tier2]
```

Required on every definition: `id`, `localisationKey`, `slot`, `ilvl`, `icon`,
`vendorValue`, `requirements`. `localisationKey` is mandatory from day one —
retrofitting localisation onto a content library is far more expensive than
carrying the key from the start, even while there is only one language.

Content files are validated against a JSON Schema in CI. A malformed or
duplicate-id item fails the build, never reaches runtime.

---

## 8. Drops

Drop tables are data, referenced by encounter definitions:

```yaml
- id: droptable.stretch2.patrol
  rolls: 1
  entries:
    - { weight: 6000, type: nothing }
    - { weight: 3000, type: item,     pool: pool.stretch2, ilvlRange: [18, 24] }
    - { weight: 1000, type: material, id: material.emberash, qty: [1, 3] }
```

Rarity is rolled independently of the item pool, so rarity distribution is tuned
in one place rather than duplicated across every table.

**LUK affects the rarity roll's floor, not its ceiling** — high Luck raises the
minimum rarity band rather than the chance of a Legendary. This makes Luck a
reliable, plannable investment instead of a lottery ticket, consistent with the
game's stance against gambling mechanics.

All drop rolls happen server-side and are logged for audit. The client is told
what it received, never what it could have received.

---

## 9. Implementation status

This section records what the first inventory slice actually built, where it
departs from the design above, and what is deliberately still missing. It exists
because a design document that silently drifts from the code is worse than none.

### 9.1 Built

`backend/src/Feature/Inventory/` — item instances (`item_instance`), affix
rolling, equip/unequip, and equipment-driven derived stats.

- `GET /api/v1/characters/{characterId}/inventory`
- `POST /api/v1/items/{id}/equip`
- `POST /api/v1/items/{id}/unequip`

Equipping enforces ownership, slot compatibility, level and attribute
requirements, and two-handed exclusivity. The invariant "one item per slot" is
held by a **partial unique index** rather than by application code:

```sql
UNIQUE (character_id, equipped_slot) WHERE equipped_slot IS NOT NULL
```

The database is the authority because two concurrent equip requests would
otherwise both pass an application-level check. The handler consequently flushes
the displacement before the new equip, since Doctrine does not order UPDATEs
within a flush and either order is valid to it — only one satisfies the index.

Derived stats are computed in `DerivedStatsCalculator` from allocated attributes
plus `EquipmentBonuses`, never stored. The single exception remains `power_score`
(ADR-0006), refreshed after every equip and unequip so leaderboards cannot rank a
player by gear they have taken off.

**Refinement** (§5) — `POST /api/v1/items/{id}/refine`. `item_instance.refine_level`
is the only new state; cost and the resulting stat bonus are both derived from it
plus the item's level, never stored (`RefinementRules`). The bonus is folded into
`EquipmentCalculator` as another percent modifier on the item's own base stats, the
same accumulator affixes already use, rather than a second code path. The caller
chooses which material to spend, not the server: a tier can hold more than one
material (a drop-only one alongside a producible one, per [idle.md](idle.md) §1),
and the handler only enforces that the chosen material's tier matches the item —
the same division of responsibility `AssignProductionSlotHandler` uses for
production lines. Takes an idempotency key, since it is a real gold-and-material
spend, and audits every step (`AuditAction::ItemRefined`).

### 9.2 Deviations from the design above

**Affixes are gated by pool, not by slot.** §4.1 shows a `slots: [...]` list on
the affix; the implementation gives each affix a `pool` and each item definition
an `allowedAffixPools`. Pools compose: a designer adds "jewellery affixes" once
instead of editing a slot list on every existing affix. The gameplay result is
identical, the authoring cost is lower, and the content schema
(`content/schema/affix.schema.json`) enforces it.

**Affix selection alternates prefix and suffix** by slot index, falling back to
the other kind only when a pool is exhausted. §4.1 states the intent — that
offence and defence cannot both be maximised for free — but not the mechanism.
Alternating implements it without a separate budget system.

**Luck's floor is quantified.** 50 Luck raises the guaranteed rarity band by one,
capped at Rare (`LUCK_PER_FLOOR_STEP = 50`, `MAX_LUCK_FLOOR = 2`). Epic and
Legendary stay chance-only, so Luck can never be the shortest path to the best
item in the game.

### 9.3 Loot randomness is separate from combat randomness

`ItemRoll` is a distinct counter-based RNG from the combat engine's
`DeterministicRng`, with its own stream constants. They are not shared on
purpose: combat roll purposes are pinned to the combat ruleset version, and
coupling loot to that would mean a combat rebalance silently changes which items
historically dropped. The two must be able to evolve independently while each
stays reproducible.

Both share the property that a roll is derived from its coordinates rather than
drawn from a stream, so adding a new roll site cannot disturb existing ones.

### 9.4 Not yet built

- **Durability and repair** (§6) — items do not degrade, so the repair gold sink
  named in [economy.md](economy.md) is not yet collecting.
- **Unique properties** (§4.2) — Legendary items currently roll affixes only.
  This needs the effect-primitive vocabulary to be addressable from item data.
- **Vendors** — no buy, sell, or `vendorValue` redemption path.
- **Equip/unequip UI** — those endpoints exist and are tested; the React screens
  do not. The inventory panel added for refinement lists equipped and carried
  items and lets a player refine one, but does not yet let a player equip,
  unequip, or choose a slot from the browser.

None of these are blocked; they were cut to keep the slice reviewable.
