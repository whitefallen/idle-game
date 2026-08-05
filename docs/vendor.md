# Vendor

The gold sink that replaced durability and repair (see [items.md](items.md)
section 6 for why that design was dropped), and the other side of the
inventory screen: an avenue to turn excess or outdated gear back into gold
without a durability system, and to spend that gold on real progression
rather than a fixed-price convenience purchase.

`backend/src/Feature/Inventory/` owns the Vendor — it is item generation and
gold exchange, the same domain refinement and equipping already live in,
rather than a feature of its own. See [architecture.md](architecture.md)
section 3 for why a system gets folded into an existing feature instead of
becoming a new one.

---

## 1. Why a Vendor, not a shop with a fixed catalogue

A vendor selling a hand-authored, unchanging list of items would be either
irrelevant (too far below a player's gear) or a shortcut past the loot chase
(too far above it) for most of a character's levels. Pricing and stock
against a character's *own current gear* — rather than a level or a global
catalogue — keeps the vendor relevant at every point in progression without
hand-tuning per-level offer tables.

---

## 2. Stock

`GET /api/v1/characters/{characterId}/vendor` — eight offers
(`VendorRules::STOCK_SIZE`), rolled fresh once per character per UTC day.

**Nothing is stored.** Stock is fully reproducible from
`(characterId, today's date, the character's current level, Luck and average
equipped item level)`, the same closed-form philosophy the Holding's accrual
uses ([idle.md](idle.md) section 7.2): a `GET` recomputes it, and a buy
recomputes the exact same offer it names to price and mint — never trusting
a price or a roll the client saw. The roll is counter-based
(`ItemRoll`/`ItemGenerator`, the same machinery combat drops use), seeded
from the character and the day rather than from any encounter, so vendor
rolls can never correlate with, or be influenced by, loot rolls
([items.md](items.md) section 9.3 explains why loot and combat RNG are kept
separate; the same reasoning applies a second time here).

**The item level band skews upward.** A character's *reference item level* is
the higher of their level and their average equipped item level (so a fresh
or unequipped character still sees sensible stock, not a floor of zero).
Stock rolls in `[reference - 5, reference + 15]` — five levels of affordable,
lateral backup options, and fifteen levels of real upside, because the
Vendor is meant to be a source of progression, not just a sideways option.

**Rarity is capped at Rare.** Epic and Legendary never appear in vendor
stock — the loot chase stays the only way to reach them. Rare is still a
meaningful, valuable roll, so gold buys real progress without buying the
ceiling. The cap is the mirror image of the Luck floor in
`ItemGenerator::rollRarity` ([items.md](items.md) section 9.2): that raises
a minimum band, this lowers a maximum, both expressed as a clamp on an index
into the same `ItemRarity::ascending()` ordering.

**The content corpus is thin** — the same known gap
[content.md](content.md) section 5 records for drop tables. When a stock
band has no exact item-level match, the Vendor falls back to the closest
available definitions rather than showing nothing.

---

## 3. Pricing

```
buyPrice = vendorValue(definition)
         x markup(1.2 + 0.06 per item level above reference)
         x rarityMultiplier(1.0 common, 1.3 uncommon, 1.8 rare)
```

`vendorValue` stays the one source of an item's base worth — it is already
required on every item definition ([items.md](items.md) section 7) — the
Vendor layers its own markup on top rather than inventing a second notion of
value. The markup never drops below 1.2x, even for an offer below the
reference level: the Vendor is a markup vendor, never a discount one. Price
grows with how far above a character's current gear an offer sits, so the
most valuable offers in each day's eight cost meaningfully more — the
property that makes the Vendor a real gold sink rather than a one-time
purchase (see [economy.md](economy.md) section 3.1).

---

## 4. Buying

`POST /api/v1/characters/{characterId}/vendor/buy` — `{ offer_index }`.

The offer is named by index only. `BuyVendorItemHandler` re-derives today's
stock through the same generator the read endpoint used, prices and mints
exactly the offer at that index, and rejects an out-of-range index
(`VALIDATION_FAILED`) or insufficient gold (`INSUFFICIENT_GOLD`) — the
client's copy of a price is never trusted, per [architecture.md](architecture.md)
section 5. Takes an idempotency key, since it spends real gold and grants a
real item, and audits every purchase (`AuditAction::ItemPurchased`).

A purchased item is a real `ItemInstance` — rolled affixes, rarity and item
level included — indistinguishable from a drop once minted.

**Known edge case, accepted rather than engineered around:** stock rotates
at UTC midnight. An offer displayed just before the boundary and bought just
after will buy whatever the new day's stock has at that index, not the offer
shown — the same way a real shop's stock can turn over between a customer
looking and paying. Rare, low-stakes, and self-correcting on the next
`GET`.

---

## 5. Selling

`POST /api/v1/items/{id}/sell` — no body.

Pays the item's `vendorValue` in gold (docs/economy.md section 2's existing
"Vendor sales" faucet — this is its first real implementation) and removes
the item. Rejected for an equipped item (`VALIDATION_FAILED`, "Unequip this
item before selling it") rather than unequipping it as a side effect: selling
gear a player is wearing is very likely a mistake, and silently unequipping
would also have to recompute the advisory power score
([ADR-0006](adr/0006-denormalised-power-score.md)) for a request that was not
really about equipment. Takes an idempotency key and audits every sale
(`AuditAction::ItemSold`).

---

## 6. Implementation status

### 6.1 Built

Everything in sections 2 through 5: stock generation, pricing, buying,
selling, both frontend affordances (a Vendor panel, and a Sell button on
each unequipped item in the inventory panel).

### 6.2 Not yet built

- **Holding upgrades** — the Vendor is the Vendor is the *replacement*
  structural sink for durability/repair, priced against active play. It does
  not cover idle-only players the way a passive-generation sink would; that
  remains [idle.md](idle.md) section 6's deferred Holding upgrades.
- **A "sold out" or limited-quantity stock** — every offer can be bought any
  number of times in one day; nothing marks an offer as taken. Real vendors
  in most games do not limit quantity either, so this is a deliberate match
  to genre expectation, not an oversight, but it is worth recording as a
  choice rather than an accident.
- **Vendor gear in the equip/unequip UI's absence** — items.md section 9.4
  already records that equip/unequip has no frontend screen; a bought item
  is equippable only through the endpoint, same as a dropped one.

None of these are blocked; they simply have not been needed yet.
