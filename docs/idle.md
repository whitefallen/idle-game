# Idle Layer

The idle model is **passive resource accrual**: offline time produces materials
and gold. Combat is active-only and is never simulated while a player is away.
See [ADR-0003](adr/0003-passive-accrual-idle-model.md) for the decision record.

---

## 1. The honest trade-off

Passive accrual is the cheapest and most robust of the idle models, and it is the
thinnest by default. If offline time simply deposits gold, idle play feels
disconnected from the game and offline players describe the mechanic as "a
button I press."

This design compensates structurally rather than by making the numbers bigger:

**The passive layer produces the inputs to the game's largest power sink.**
Refinement (`items.md` §5) is the main long-horizon power axis, and refinement
materials come predominantly from the Holding. So offline time is not a side
channel — it is the supply line for the progression project the player is
actively working on. The accrued resource is *spent with intent*, in the session
loop, on a decision the player made.

Two accepted consequences:

- Idle play is **not self-sufficient**. A player who never fights accrues
  materials they cannot fully use, because refinement also costs gold and
  because gear must be acquired through encounters. This is intentional and is
  what keeps the two playstyles interdependent rather than parallel.
- The idle layer will need **an activity of its own eventually** — Holding
  upgrades, staff assignment, production choices — to stay interesting past the
  first month. That work is scoped in §6 rather than built now.

---

## 2. The Holding

Each character owns a **Holding**: a fortified waystation on the beacon-line with
a fixed number of **production slots**.

- Slots at level 1: **2**. One additional slot every 10 levels, to **7** at
  level 60 (tunable).
- Each slot is assigned a **production line** producing one material type at a
  fixed hourly rate. Reassignment is free but resets that slot's accrual.
- Production lines unlock through progression, never through payment.

```
ratePerHour(slot) = baseRate(materialTier) * slotTierBp / 10000        (tunable)
```

| Material tier | Base rate/hour | Unlock |
|---|---|---|
| 1 — Emberash | 12 | Level 1 |
| 2 — Slagiron | 7 | Level 15 |
| 3 — Verdigris | 4 | Level 30 |
| 4 — Cinderglass | 2 | Level 45 |

Gold accrues from a separate, non-slotted **tithe** line at
`4 + 2 * characterLevel` per hour (tunable) — deliberately modest, because gold
from encounters must remain the dominant faucet ([economy.md](economy.md) §2).

---

## 3. Accrual and the cap

```
elapsedSeconds = min(now - lastClaimedAt, capSeconds)
produced       = elapsedSeconds * ratePerHour / 3600
```

Closed form, O(1), no iteration, no simulation. A player returning after six
months costs exactly the same to serve as one returning after an hour.

**Accrual cap:** 12 hours at level 1, extended to a maximum of 24 hours through
progression milestones (tunable). The cap **cannot be extended by payment** —
that would make paying strictly more productive, which is the definition of
pay-to-win in an idle game.

The cap serves two purposes. It bounds the value of absence, keeping the parity
target in [game-bible.md](game-bible.md) §7 achievable. And it gives a returning
player a reason to come back tomorrow rather than in a month.

Production **stops at the cap** rather than overflowing into a secondary
resource. Overflow mechanics defeat the purpose of the cap.

---

## 4. Vigor

**Vigor** is the action currency. It gates encounters and is the counterweight
that stops active play from dominating.

| Property | Value (tunable) |
|---|---|
| Cap | 120 |
| Regeneration | 1 per 6 minutes (10/hour) |
| Time to fill from empty | 12 hours |
| Cost per patrol encounter | 10 |
| Cost per dungeon attempt | 25 |
| Cost per arena attack | 15 |

At cap this is 12 patrol encounters banked, and 240 Vigor per day of
regeneration — about 24 encounters daily for a player who spends promptly.

Vigor regenerates using the same closed-form calculation as the Holding, sharing
the accrual infrastructure.

**Vigor is not purchasable, ever.** Selling attempts sells progression; it is
the most common way games in this genre become pay-to-win while claiming not to
be. This is recorded as a monetisation constraint in
[economy.md](economy.md) §6, not merely a current preference.

### 4.1 One activity at a time

A character runs **one Vigor-spending activity at a time**. A new one may begin
only once the previous has resolved *and* a short gate interval has elapsed
(`VigorRules::ACTIVITY_GATE_SECONDS`, 3 seconds, tunable).

Two separate guarantees, and they are not the same thing:

| | Mechanism | Protects against |
|---|---|---|
| **Exclusivity** | `SELECT … FOR UPDATE` on the character row for the whole transaction | Two concurrent requests both reading the same Vigor balance and both spending it — the double-tap that turns one cost into two encounters |
| **Pacing** | `vigor_spent_at` plus the gate interval | A full pool being emptied in a burst of clicks |

Exclusivity alone is not pacing. An encounter resolves synchronously in tens of
milliseconds, so "wait until the previous one is resolved" permits many
encounters per second; only the interval spaces them out.

The gate does **not** reduce daily throughput — the cap in §4 remains the only
ceiling, and the parity contract in [game-bible.md](game-bible.md) §7 is
unaffected. What it buys:

- The read-and-adjust beat the design is built on. Reading a combat log and
  changing the plan is impossible if twelve fights resolve before the first log
  is open.
- A bound on the server cost one account can impose. Every encounter is a full
  combat simulation inside its request.
- A real, inspectable "am I busy?" state, which the longer-running activities
  the design anticipates — dungeons, expeditions, arena defence — need in order
  to be exclusive against each other at all.

Refused attempts cost nothing: no Vigor, no encounter record. The refusal
carries `seconds_remaining` and `ready_at`, and the same state is readable
ahead of time from `GET /characters/{id}/encounters/available`, so the client
disables the action with a countdown instead of letting the player discover the
rule by being refused.

A **refund does not lift the gate**. A draw returns the Vigor because the cost
was not the player's fault, but the activity still ran and still consumed the
work; clearing the gate would also make a draw the cheapest route to fighting
twice in quick succession.

---

## 5. Anti-exploit rules

Idle games are attacked through time. These rules are requirements, not
suggestions.

**T1 — The server owns the clock.** No client-supplied timestamp is ever read.
`lastClaimedAt` and `lastVigorTickAt` are server-set columns.

**T2 — Claims are transactional and locked.** A claim executes as:

```sql
SELECT … FROM holding WHERE character_id = ? FOR UPDATE
```

inside a transaction, with the recalculation and the `lastClaimedAt` update in
the same transaction. Without the row lock, two concurrent requests — a
double-clicked button, or a deliberate attack — both read the same
`lastClaimedAt` and both award the full amount.

**T3 — Claims are idempotent per request.** Every mutating claim carries a
client-generated idempotency key; replays return the original result rather than
producing a second award.

**T4 — Elapsed time is clamped on both ends.** Negative elapsed time (from clock
adjustment or replication lag) clamps to zero and is logged as a security event.
It must never produce a negative or wrapped award.

**T5 — Accrual is derived, not incremented.** There is no scheduled job adding
resources to players. State is `(lastClaimedAt, rate)` and the balance is
computed on read. A cron-based incrementer would be a correctness liability
(missed runs, double runs, drift) and an O(players) cost for no benefit.

**T6 — Every claim is audited.** Amount, elapsed time and resulting balance are
written to the audit log. Economy exploits are found in aggregate data, and that
data has to exist before the exploit does.

---

## 6. Deferred: making the Holding interesting

Explicitly out of scope for the first implementation, recorded so it is designed
rather than bolted on:

1. **Holding upgrades** — gold sink that raises slot tier, a natural long-term
   goal for accumulated gold.
2. **Staff** — assignable NPCs with traits that bias production, giving the idle
   layer a build-craft dimension of its own.
3. **Supply runs** — an active encounter type whose reward is a temporary
   production multiplier, explicitly tying the active loop back into the idle one.

The design constraint on all three: none may raise the accrual cap, and none may
be purchasable.
