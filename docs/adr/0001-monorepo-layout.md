# ADR-0001 — Monorepo layout

**Status:** Accepted · 2026-08-02

## Context

The project spans a Symfony backend, a React frontend, a content library authored
as YAML, and Docker infrastructure. These need to evolve together: an API change
touches backend and frontend simultaneously, and a content schema change touches
the content library and its loader.

The team is small and expected to stay small.

## Decision

A single repository containing `backend/`, `frontend/`, `content/`, `docker/`
and `docs/`.

## Alternatives considered

**Separate backend and frontend repositories.** Rejected. Every API change
becomes a cross-repository coordination problem: two PRs, two reviews, an
ordering constraint on merge, and a window where `main` on one side is
incompatible with `main` on the other. For a small team this cost is paid
continuously and buys ownership boundaries nobody needs.

**Backend repo with the frontend as a subdirectory of it.** Rejected as a matter
of framing — it implies the frontend is a backend concern and tends to produce a
frontend build wired into the PHP toolchain.

## Consequences

**Accepted:**

- Atomic cross-stack commits. An API change and its client update land together,
  so `main` is always internally consistent.
- One CI pipeline, with path filters so a frontend change does not run PHPUnit.
- One place for the API contract and the generated types.
- One issue tracker and one version history for the whole product.

**Costs:**

- CI must use path filtering or it will run everything on every commit.
- The repository will grow large once art assets arrive. Binary assets go
  through Git LFS from the first asset, not retroactively.
- Coarser access control. Acceptable now; would need revisiting if external
  contractors work on art or content only.

## Notes

`content/` deliberately sits at the repository root rather than inside
`backend/`. It is authored by designers, validated by its own CI job, and will
be consumed by tooling beyond the game server. Nesting it under `backend/`
would frame game data as an implementation detail of PHP.
