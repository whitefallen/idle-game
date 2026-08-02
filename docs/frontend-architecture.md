# Frontend Architecture

React 19 · TypeScript · Vite · Tailwind CSS · TanStack Query · Zustand

The frontend is a **presentation and input surface**. It contains no
authoritative gameplay logic. Its hardest job is making a turn-based, server-
resolved game feel immediate.

---

## 1. Structure

```
frontend/src/
├── features/           Mirrors backend feature names exactly
│   ├── character/
│   │   ├── api/            Query and mutation hooks
│   │   ├── components/     Feature-owned UI
│   │   ├── model/          Feature-local types and pure helpers
│   │   └── routes/
│   ├── inventory/
│   ├── combat/
│   ├── holding/
│   └── quest/
├── components/         Shared presentational primitives (Button, Tooltip, …)
├── lib/                API client, query client, formatting, i18n
├── stores/             Zustand stores — UI state only
└── routes/             Route tree and layouts
```

Feature names match the backend's. When a bug report says "inventory", there is
exactly one directory on each side to open. Shared code moves into
`components/` or `lib/` only when a **third** consumer appears — two consumers
is a coincidence, three is a pattern, and premature extraction produces
abstractions shaped by the first two use cases.

---

## 2. State ownership

The rule that prevents most frontend bugs in this kind of application:

| State | Owner |
|---|---|
| Anything the server knows | TanStack Query |
| Anything only the browser knows | Zustand |

**Server state → TanStack Query.** Character, inventory, quests, encounters,
Holding. Query keys are structured and feature-scoped
(`['character', id, 'inventory']`). Mutations invalidate precisely; blanket
invalidation is treated as a defect because it produces loading flicker across
unrelated panels.

**Local UI state → Zustand.** Open panel, selected inventory tab, tooltip
anchor, combat replay playback position, unsaved battle plan draft.

**Never copy server data into Zustand.** This is the single most common
architectural mistake in this stack. The moment server data is mirrored into a
store, there are two sources of truth with independent staleness, and every
subsequent bug is a synchronisation bug. If a component needs server data, it
calls the query hook.

The one legitimate overlap is an **unsaved draft** — an edited battle plan that
has not been submitted. That is genuinely browser-only state and belongs in
Zustand until the mutation succeeds, at which point the query cache becomes the
truth again.

---

## 3. Types and the API contract

TypeScript types for API payloads are **generated** from the backend's OpenAPI
schema into `lib/api/generated/`, and that directory is never hand-edited. A
hand-written duplicate of a server contract drifts, and the drift is discovered
by players rather than by the compiler.

The generation step runs in CI; a schema change that has not been regenerated
fails the build.

---

## 4. Time

The server owns the clock ([api.md](api.md) §7). The client receives timestamps
and rates, and interpolates locally **for display only**.

A `useAccrual` hook takes `(currentValue, ratePerHour, max, servedAt)` and
produces a smoothly ticking display value. It never writes back, never submits
its computed value, and re-syncs from the server on every response.

Clock skew is handled by anchoring to `meta.server_time` from the most recent
response rather than to `Date.now()`. A player whose system clock is wrong
should see correct values, and a player whose system clock is *deliberately*
wrong should gain nothing.

---

## 5. Combat replay

The replay renders a **server-produced log**. It does not simulate, and it has
no combat rules in it.

- The log arrives complete in the `POST /encounters` response, so playback
  starts with no second request.
- Playback is a reducer over the event array, driven by a timer, producing view
  state at each step. Because it is a pure fold, it is trivially seekable and
  fully testable without a DOM.
- Speed controls and skip-to-end are required, not optional. Players run many
  encounters and will not watch a full animation each time; a skip button that
  jumps to the final state is the difference between a satisfying loop and an
  irritating one.
- Every action displays **which battle plan rule fired**. This is the mechanism
  that makes the game's signature mechanic learnable
  ([combat.md](combat.md) §5.3), and it is a product requirement rather than a
  debug affordance.

---

## 6. Feedback and perceived responsiveness

Every interactive element gives immediate feedback. Because gameplay actions are
server-resolved, the interaction pattern is fixed:

1. On click, disable the control and show in-progress state immediately.
2. Await the server response — **no optimistic updates on gameplay actions.**
   Optimistically showing loot or XP that the server then rejects is worse than
   a 200ms wait, because it teaches players not to trust the display.
3. On success, update from the response payload.
4. On failure, restore state and show the error resolved from `error.code`.

Optimistic updates are acceptable for genuinely local, reversible preferences —
reordering a battle plan before saving, toggling a UI setting.

---

## 7. Rendering and assets

HTML/CSS with sprite sheets, per the asset guidelines: 64×64 characters, 32×32
items, 24×24 icons.

- Sprites are served as **atlases with generated CSS classes**, not as individual
  image requests. An inventory screen renders dozens of icons and must not issue
  dozens of requests.
- `image-rendering: pixelated` throughout, and all sprite scaling is by integer
  factors. Fractional scaling of pixel art produces artefacts that look like
  rendering bugs.
- No canvas or WebGL. The visual design is deliberately within the reach of DOM
  rendering, which keeps the game accessible, inspectable and cheap on low-end
  devices.

---

## 8. Interface principles

- **Responsive**, mobile-first. A browser MMORPG that does not work on a phone
  is a browser MMORPG that is not played.
- **Keyboard-navigable.** Every interactive element is reachable and operable by
  keyboard, with visible focus. Modals trap focus and restore it on close.
- **Consistent tooltips.** One tooltip component, one interaction model,
  everywhere. Item comparison tooltips show the delta against the currently
  equipped item, since that is the actual decision the player is making.
- **Every error is actionable.** `error.details` carries structured context, so
  "Not enough Vigor" renders as "You need 6 more Vigor — ready in 36 minutes."
- **All player-facing text comes from localisation keys.** No literal strings in
  components, from the first component onward. Retrofitting localisation is far
  more expensive than carrying keys from the start, even with one language.

---

## 9. Performance

Measure before optimising. The specific things worth watching in this
application:

- **Bundle size**, route-split by feature. The login and character screens must
  not ship the combat replay engine.
- **Query cache growth** across a long session — this game is played in a tab
  left open for hours.
- **Replay playback**, which is the only animation-heavy surface and the only
  place where render cost is likely to matter.

`React.memo`, `useMemo` and `useCallback` are applied in response to a profile,
never speculatively. Speculative memoisation adds complexity, hides dependency
bugs, and is frequently a net loss.
