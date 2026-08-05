# Economy

The design target is a currency economy that is still legible after **five
years** of operation: prices a veteran and a newcomer can both reason about, and
no point at which the team has to announce a currency reset.

---

## 1. Currencies

| Currency | Code | Source | Tradeable | Purpose |
|---|---|---|---|---|
| **Gold** | `gold` | Gameplay only | No (see §7) | Universal sink currency |
| **Emberdust** | `emberdust` | Purchase, plus a small gameplay trickle | No | Convenience, cosmetics, space |

**Materials** (Emberash, Slagiron, Verdigris, Cinderglass) are inventory items,
not currencies. They are the refinement input and deliberately have no direct
gold price from a vendor, so material scarcity cannot be bypassed with gold.

Emberdust has a **gameplay trickle** — a small guaranteed amount from level
milestones and quest chains. This exists so that non-paying players participate
in the premium economy rather than being locked out of it, which measurably
improves both retention and eventual conversion, and it keeps the team honest
about premium features being genuinely optional.

All currency amounts are stored as **64-bit integers**, never decimals, never
floats. There are no fractional currencies.

---

## 2. Faucets

| Source | Scale | Notes |
|---|---|---|
| Encounter rewards | `5 * encLevel + roll(0 … 2 * encLevel)` | Dominant faucet; activity-proportional |
| Dungeon completion | ~8× a patrol | Weekly-cadence content |
| Quest rewards | Fixed per quest | One-time, front-loaded for new players |
| Vendor sales | `vendorValue` of item | Recycles unwanted drops |
| Holding tithe | `4 + 2 * charLevel` per hour, capped | Deliberately minor — see [idle.md](idle.md) §2 |

**Encounter gold must remain the dominant faucet.** If passive gold ever rivals
active gold, the Vigor cap stops constraining income and the parity contract in
[game-bible.md](game-bible.md) §7 breaks. This ratio is a monitored metric, not
an assumption.

---

## 3. Sinks

| Sink | Scale | Character |
|---|---|---|
| **Refinement** | `40 * ilvl * (refine+1)^2` | Primary. Unbounded appetite, escalating |
| **Vendor purchases** | `vendorValue * markup(offer ilvl, gear ilvl, rarity)` | Continuous, priced against how far ahead of current gear an offer sits — see [items.md](items.md) §5 |
| **Respec** | `150 * L + 5 * L^2` | Recurring, voluntary, scales with progression |
| **Crafting** | Recipe-defined | Scales with content tier |
| **Holding upgrades** | Tier-defined | Long-horizon (deferred, see [idle.md](idle.md) §6) |

### 3.1 The sink design rule

**Every faucet must have a sink that scales with it.** A faucet that scales with
level or tier and drains into a fixed-price sink produces inflation with
certainty; it is only a question of how many months.

The two structural sinks are built for this:

- **Vendor purchases** replace the durability/repair sink an earlier draft of
  this document specified (see [items.md](items.md) §6 for why that was
  dropped). It scales with activity indirectly rather than per-encounter: a
  player who plays more re-rolls the vendor's daily stock more often and sees
  more offers worth the markup, so spend still tracks engagement without
  penalising a player who stops.
- **Refinement** scales quadratically in refine level and linearly in item level,
  which gives it an effectively unbounded appetite. It is the pressure valve that
  absorbs accumulated wealth at every tier.

**Target: sinks absorb 80–95% of gross gold income at steady state**, measured
per level band. Below 80% and wealth accumulates until prices lose meaning;
above 100% and players feel they are running to stand still. This is a monitored
metric with an alert, checked by the progression simulator in CI
([progression.md](progression.md) §6) and against live data.

### 3.2 What is deliberately *not* a sink

- **Death costs.** Losing an encounter costs Vigor and durability, nothing more.
  Punishing failure discourages the experimentation the battle plan mechanic
  depends on.
- **Consumable-gated progression.** No item required to attempt content.
- **Inventory expansion for gold.** Space is an Emberdust convenience purchase;
  making it a gold sink would put a paid and an earned currency in competition
  for the same need, which is the structure that makes free players feel taxed.

---

## 4. Respec pricing

```
attributeRespecCost = 150 * L + 5 * L^2         (tunable)
```

Level 20: 5,000. Level 40: 14,000. Level 60: 27,000.

Priced as a **routine expense, not a penalty** — roughly an hour of level-appropriate
income. Discipline loadout changes are **free** outside combat, and battle plan
edits are always free. The design intent is that build iteration is the fun part
of the game; the gold cost exists to make attribute respec a considered decision
and to provide a wealth sink, not to discourage it.

---

## 5. Inflation control

Monitored continuously; these are the metrics that reveal an economic problem
months before players do:

| Metric | Healthy | Action if breached |
|---|---|---|
| Sink/faucet ratio per level band | 0.80–0.95 | Adjust sink coefficients |
| Median gold held per level band | Stable ± 20% over 30 days | Investigate faucet |
| P95 / median gold held | < 8 | Investigate accumulation or exploit |
| Gold per active hour, by band | Flat across bands ± 30% | A tier is over-rewarding |

Structural protections beyond tuning:

- **Gold is not tradeable between players** (§7), so wealth cannot concentrate
  through trade or be sold for real money.
- **Vigor caps income per day**, giving gold generation a hard ceiling per
  account that no amount of play or automation can exceed. This is the strongest
  anti-inflation and anti-botting property in the design, and it is a direct
  consequence of the idle model.
- **Every currency mutation is audited** with source, amount and resulting
  balance. Economy investigations are impossible without this and it cannot be
  added retroactively.

---

## 6. Monetisation constraints

Binding rules. A feature proposal that violates one is rejected, not negotiated.

**Emberdust may buy:**

- Cosmetics — appearance, dyes, pets, titles, guild banners
- Stash and inventory space
- Additional battle plan and loadout *presets* (not additional slots)
- Character slots
- Name and appearance changes
- Convenience automation that saves clicks, never time

**Emberdust may never buy:**

- Stats, gear, or gear with stats
- Vigor, or any encounter attempt
- Accrual cap extension, or Holding production rate
- Refinement materials, or refinement success
- XP boosts of any kind
- Randomised rewards of any kind — no loot boxes, no gacha, no keys

The line: **money may buy convenience and expression; it may not buy power or
time.** "Additional presets, not additional slots" is the precise expression of
this — a paying player can store more battle plans, but cannot field more
abilities in a fight.

The single largest revenue risk this creates is that there is no whale mechanic.
That is accepted deliberately. A game intended to run for years in a genre with
a poor monetisation reputation competes on trust, and trust is not recoverable
once spent.

---

## 7. Player trading — deferred, with reasoning

**Not in the initial design.** Gold and items are bound to the character.

An auction house is attractive: it is an excellent gold sink (fees), it makes
drops more meaningful, and players ask for it. It is deferred because it is also
the single largest source of economic risk in the project:

- It is the enabling mechanism for **real-money trading**, which converts every
  gold exploit into a cash exploit and every bot into a business.
- It requires **bot detection** as a hard dependency, which is a substantial
  ongoing operational commitment.
- It makes the economy **coupled**: after an auction house exists, every drop
  rate change is a market intervention, and mistakes are much harder to reverse.

The right sequence is to launch bound, gather real data on drop rates and gold
flow, then design trading against that data with the anti-abuse work budgeted
in advance. Adding trading to a stable economy is tractable; removing it from a
broken one is not.

**Guild-scoped item donation** is the likely first step: most of the social
benefit, a bounded and auditable graph, far less RMT surface.
