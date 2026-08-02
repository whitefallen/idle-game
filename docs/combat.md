# Combat

Combat is the most correctness-critical system in the project. It decides
rewards, it is the basis of PvP, and players will dispute its outcomes. It is
therefore specified as a **pure, reproducible function** and held to a stricter
standard than any other subsystem.

---

## 1. The contract

```
resolve(CombatInput $input, Seed $seed, RulesetVersion $version): CombatLog
```

The combat engine:

- has **no access to the database**, the clock, the container, the filesystem,
  the network, superglobals, or any static mutable state;
- takes a fully materialised snapshot of every participant as input;
- returns a complete, self-describing log of everything that happened;
- given identical `(input, seed, version)`, returns a byte-identical log
  **forever**, including after balance patches.

Everything else in this document exists to make that sentence true.

### 1.1 Why this shape

Three properties fall out of it, and each one is worth the constraint on its own:

1. **Testability.** The engine can be exercised in microseconds with no Symfony
   kernel, no database and no fixtures. Thousands of balance scenarios run in CI.
2. **Disputability.** When a player asks why they lost, the stored seed and
   ruleset version reproduce the fight exactly, on any machine, years later.
3. **Reusability.** Active encounters, arena defence, guild battles and the
   balance simulator all call the same function. There is exactly one combat
   implementation, so there is exactly one place for combat bugs to live.

### 1.2 What is persisted

Every resolved encounter stores:

| Field | Why |
|-------|-----|
| `seed` | Reproduces the randomness |
| `ruleset_version` | Reproduces the *rules*, which change over time |
| `input_snapshot` (JSONB) | Reproduces the participants as they were, not as they are now |
| `log` (JSONB, compressed) | Serves replay without re-simulating |
| `outcome`, `rounds`, `rewards` | Queryable summary |

Storing the input snapshot is non-negotiable. Without it, re-equipping an item
silently invalidates every past fight, and "deterministic combat" becomes a
claim that cannot be checked.

---

## 2. Determinism rules

These are hard requirements. A violation is a defect at any severity level.

**R1 — Integer arithmetic only.** No floating point anywhere in the engine, not
even transiently. Floats are not reliably identical across platforms, PHP
builds, or JIT states, and `0.1 + 0.2` problems in damage calculation are
undebuggable. All fractional values are basis points (bp); 10000 bp = 100%.

**R2 — Truncating division at every step.** Every `/` in a formula truncates
toward zero, immediately, before the next operation. Intermediate precision is
*not* carried. This is stricter than necessary for correctness but it makes the
formulas mean exactly what they say, and it means a reimplementation in another
language matches without a rounding-convention appendix.

**R3 — No unordered iteration.** Every collection iterated by the engine is an
ordered list. No `array_key` iteration over hash maps whose order depends on
insertion, no `foreach` over Doctrine collections, no set semantics anywhere.
Where a natural order does not exist, sort by entity UUID ascending.

**R4 — No ambient input.** No `time()`, `rand()`, `uniqid()`, `spl_object_hash`,
locale, or timezone. The current time, if a rule needs it, is an explicit field
on `CombatInput`.

**R5 — Overflow safety.** All intermediate values must fit in a signed 64-bit
integer. Given the stat budget in [progression.md](progression.md) §2.2, the
theoretical maximum intermediate is under 2^40, so this holds with a wide margin
— but the engine asserts it in debug builds rather than assuming it.

**R6 — Ruleset versioning.** The engine is versioned. Old logs are replayed with
the engine version that produced them. Balance changes bump the version; they do
not rewrite history.

---

## 3. Randomness

### 3.1 Counter-based, not sequential

The engine does **not** hold a stateful PRNG that is drawn from in sequence.
Instead every random value is derived independently from its coordinates:

```
value = splitmix64(
            encounterSeed
          ⊕ mix(roundIndex)
          ⊕ mix(actorOrdinal)
          ⊕ mix(purposeId)
          ⊕ mix(rollIndex)
        )
```

This is the most important design decision in the randomness layer. With a
sequential PRNG, adding a single new roll — a new ability, an extra check —
shifts every subsequent draw, so a minor feature silently changes the outcome of
every existing replay and every balance test. With counter-based derivation,
each roll site is independent: new roll sites can be added without disturbing
existing ones, and tests stay stable across development.

`purposeId` values are **stable integers from a registry** (`RollPurpose`), never
renumbered and never reused. Removing an ability retires its ids permanently.

### 3.2 Algorithm

**splitmix64**, implemented with explicit 64-bit wrapping arithmetic built from
32-bit lanes. PHP's native integers silently promote to float on overflow, which
would violate R1, so the multiply and add steps are implemented manually rather
than written as `*` and `+`.

The client never needs this algorithm: the browser replays the **recorded log**,
it does not re-simulate. That is a deliberate choice — it removes any need for
bit-exact PRNG parity between PHP and TypeScript, which is a notorious source of
desync bugs, and it means the client cannot learn future rolls.

### 3.3 Bounded integers

Uniform values in `[0, n)` use **rejection sampling**, not modulo. Modulo bias is
small but it is real, it is measurable by players over thousands of drops, and it
is embarrassing to have to fix in a live economy. Rejection sampling remains
deterministic because the rejection loop is a function of the same coordinates.

Probability checks are of the form `roll(10000) < chanceBp`.

---

## 4. Turn structure

```
Encounter start
  ├─ Snapshot participants, compute derived stats once
  ├─ Compute initiative order  (ties → ascending entity UUID)
  └─ Emit EncounterStarted

Round loop  (max 50 rounds — structural)
  │
  ├─ Round start
  │    ├─ Tick periodic effects  (in application order, then entity UUID)
  │    ├─ Decrement cooldowns
  │    ├─ Regenerate Focus
  │    └─ Expire finished effects
  │
  ├─ For each living actor in initiative order:
  │    ├─ Evaluate battle plan → selected ability (§5)
  │    ├─ Resolve ability (§6)
  │    └─ Emit action events
  │
  └─ Check terminal condition

Encounter end
  └─ Emit EncounterEnded (victory | defeat | draw)
```

**The 50-round cap is structural, not tunable.** Without it, two defensive builds
with sustain can loop forever, which is an availability risk on a server that
resolves fights synchronously inside a request. Reaching the cap is a **draw**:
no rewards, and the Vigor cost is refunded, because a draw is a design failure
rather than a player failure.

Initiative order is computed **once at encounter start**. Effects that modify
initiative apply from the following round and re-sort deterministically.

---

## 5. Battle plan evaluation

A plan is an ordered list of rules:

```
Rule := { condition: ConditionExpr, abilityId: string }
```

Each turn, rules are evaluated top-down. The first rule whose condition is true
**and** whose ability is off cooldown **and** whose Focus cost is affordable
**and** which has a legal target is executed. The final rule is required to be
unconditional (`ALWAYS`) with a zero-cost, no-cooldown ability, so evaluation is
guaranteed to terminate with an action.

### 5.1 Condition expressions

Conditions are a small, closed, non-Turing-complete expression language:

```
self.health   < | <= | > | >=   <percent>
self.focus    < | <= | > | >=   <value>
enemy.count   < | <= | > | >=   <value>
enemy.health  < | <= | > | >=   <percent>
target.hasEffect(<effectId>)
self.hasEffect(<effectId>)
round         < | <= | > | >=   <value>
<expr> AND <expr>     (max 3 terms)
ALWAYS
```

Closed by design. A general scripting language here would be a security problem
(untrusted code on the server), a performance problem (unbounded evaluation in
the request path) and a support problem. A fixed grammar is validated once on
save, stored as structured JSON, and evaluated in constant time.

Plans are validated **server-side on save** and rejected with specific errors.
The client's validation is a convenience, never the authority.

### 5.2 Targeting

Each ability declares a target selector: `lowestHealthEnemy`, `highestThreat`,
`self`, `allEnemies`, `randomEnemy`, `lowestHealthAlly`. Selectors resolve
deterministically; `randomEnemy` draws from the counter-based RNG with its own
`purposeId`.

### 5.3 Legibility requirement

Every executed action logs **which rule index fired**. This turns the combat log
into a teaching tool: a player who loses can see that rule 1 never fired because
Focus was exhausted by rule 3, and fix the plan. Without this, the battle plan is
an opaque black box and the game's signature mechanic fails.

---

## 6. Damage pipeline

The canonical pipeline. It refines the eight-stage outline in `CLAUDE.md` by
splitting attribute scaling out of "base stats" — scaling depends on the equipped
weapon, so it cannot be evaluated before equipment is known.

```
1. base       = weaponBaseDamage                                  (from item data)
2. equipped   = base + flatDamageFromAffixes
3. scaled     = equipped * scalingBp        / 10000               (attributes)
4. buffed     = scaled   * (10000 + Σ buffBp) / 10000             (buffs/debuffs)
5. skilled    = buffed   * abilityCoefficientBp / 10000           (ability)
6. crit       = isCrit ? skilled * critPowerBp / 10000 : skilled
7. mitigated  = crit     * (10000 - damageReductionBp) / 10000    (armour)
8. resisted   = mitigated * (10000 - resistanceBp)     / 10000    (school resist)
9. final      = max(1, resisted)
```

| `CLAUDE.md` stage | Pipeline step |
|---|---|
| Base stats | 1, 3 |
| Equipment | 2 |
| Buffs | 4 |
| Skills | 5 |
| Critical chance | 6 |
| Armor | 7 |
| Resistance | 8 |
| Final damage | 9 |

**Order matters and is fixed.** Buffs are additive among themselves and
multiplicative against the rest of the chain; this keeps stacking legible and
prevents the multiplicative-buff explosion that makes late-game builds
unbalanceable. **The floor of 1** guarantees that no fight can stall against an
over-armoured target, which combined with the round cap guarantees termination.

Hit resolution precedes the pipeline:

```
effectiveDodgeBp = max(0, defender.dodgeChanceBp - attacker.accuracyBp)
if roll(10000) < effectiveDodgeBp    → miss
if roll(10000) < attacker.critChanceBp → critical
```

---

## 7. Combat log format

The log is a versioned, ordered array of typed events. It is the API contract
for the replay UI, so it is designed for consumption, not for debugging:

```jsonc
{
  "logVersion": 1,
  "rulesetVersion": "1.0.0",
  "seed": "…",
  "participants": [ /* id, name, maxHealth, appearance refs */ ],
  "events": [
    { "t": "round.start",  "round": 1 },
    { "t": "plan.matched", "actor": "…", "rule": 2, "ability": "sweeping_arc" },
    { "t": "damage",       "source": "…", "target": "…",
      "amount": 214, "crit": true, "school": "physical" },
    { "t": "effect.applied", "target": "…", "effect": "burning", "rounds": 3 },
    { "t": "encounter.end", "outcome": "victory", "rounds": 7 }
  ]
}
```

Rules:

- Events carry **entity ids and numbers**, never localised strings. All display
  text is resolved client-side from localisation keys.
- The log is **complete**: the client can render the entire fight, including
  health bars at every point, without asking the server anything further.
- `logVersion` is independent of `rulesetVersion`. The format can evolve without
  implying a balance change, and old logs remain renderable.

Logs are stored compressed. Retention is finite — see
[data-model.md](data-model.md) §6 — because unbounded log growth is the most
predictable capacity problem this project has.

---

## 8. Testing requirements

Combat is exempt from the usual "test where appropriate" latitude. Required:

1. **Golden replays.** A corpus of stored `(input, seed, version) → log` fixtures.
   Any change in output fails CI. Intentional balance changes require a version
   bump and a regenerated corpus in the same commit, which makes every balance
   change visible in review.
2. **Property tests.** Invariants that must hold for arbitrary generated inputs:
   health never negative; damage never below 1; every encounter terminates within
   50 rounds; total damage dealt equals total health lost; no effect outlives its
   duration.
3. **Determinism test.** The same input resolved 1000 times produces 1000
   identical logs, and the same input resolved after `shuffle()`-ing every input
   collection still produces an identical log (catches R3 violations).
4. **Balance scenarios.** For each content tier, canonical builds are run against
   the tier's encounter set; win rates must fall inside declared tolerances.
   These are the tests that catch a balance patch breaking a build archetype.
5. **No-I/O test.** A test that fails if the engine's namespace gains a
   dependency on Doctrine, the container, or the clock. This is the guard that
   keeps the purity property from eroding one convenient injection at a time.
