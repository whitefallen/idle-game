# ADR-0009 — Registry-built images with a manually triggered deploy

**Status:** Accepted · 2026-08-14

## Context

Until now the project had no production path. `compose.yaml` describes a
development stack — bind-mounted source, a Vite dev server, Xdebug, a Postgres
port published to the host — and the PHP `Dockerfile` had a `prod` target that
nothing built and nothing ran. Shipping needed three decisions made together:
where images are built, how they reach the server, and what causes a deployment
to happen.

The target is a single Hetzner Cloud host that already runs other applications.
That constrains the answer more than it first appears: the box is shared, it is
small, and anything this stack does to the host globally — daemon settings, port
80, a `docker image prune` — affects tenants that have nothing to do with the
game.

## Decision

**Images are built in GitHub Actions and pushed to GHCR; the server only
pulls.** Two images per commit on `main`: `emberwatch-php` (the `prod` target,
with the content library baked in) and `emberwatch-web` (nginx serving the built
frontend and fronting PHP-FPM). The server holds no checkout of this repository,
never runs `composer`, and never runs `npm`.

**Deployment is manual — `workflow_dispatch` with an explicit image tag.** Every
push to `main` publishes; nothing deploys until someone names a tag and presses
the button. Moving tags (`main`, `latest`) are rejected by the workflow.

**Caddy terminates TLS as part of the stack**, and is the only container that
binds a public port.

## Alternatives rejected

**Build on the server.** No registry to configure, and the server would only
need `git pull` — but a Vite build plus a `composer install` on a small shared
CX instance takes minutes of CPU that the host's other tenants are also paying
for, and it makes deployment failure modes and build failure modes the same
incident. Rolling back would mean rebuilding an old commit rather than starting
a container that already exists.

**Deploy automatically on every green `main`.** The usual continuous-delivery
argument applies and it is a good one, but the game is pre-launch and there is
no staging environment. The manual gate is what buys room to run a build before
players see it. Publishing still happens on every commit, so the gate delays
exposure, never the artefact — choosing to ship is never blocked on a build.

**Deploy on `v*` tags.** Explicit and auditable, but it makes "deploy the thing
I just merged so I can look at it" a two-step ceremony with a tag that means
nothing yet. Reconsider at launch, when releases start needing names.

**nginx plus certbot at the edge instead of Caddy.** Keeps one web server
technology across the whole stack. Rejected because certificate renewal becomes
a timer that can silently stop working, and the failure is invisible until the
certificate expires. Caddy's renewal is not a thing that can be forgotten to be
set up.

**Kubernetes, Swarm, or a PaaS.** All solve problems this project does not have.
One host, one stack, no autoscaling requirement.

## Consequences

**Accepted:**

- A deployed tag is reproducible from its name alone. The content library is
  copied into the image rather than mounted from the host precisely so that
  `sha-1a2b3c4` cannot mean different monsters and prices depending on when the
  server last saw the repository.
- Rollback is redeploying the previous tag, which is already on the host — a
  pull that hits cache and a container restart, with no build involved.
- The server stores no registry credential. The deploy job logs in with its own
  `GITHUB_TOKEN`, which expires when the run ends, so a compromised host does
  not yield standing pull access.
- The stack takes nothing global from the host: log rotation is configured
  per service rather than in `daemon.json`, image pruning is filtered to this
  project's own labels, and the edge ports are variables.

**Costs:**

- Nothing is deployed until a person does it. A fix merged on Friday sits in the
  registry until someone deploys it, and that is a decision, not an accident —
  but it does mean "merged" and "live" are different states that have to be
  tracked separately.
- Migrations run against the new image before the new containers serve traffic,
  which means a backwards-incompatible migration has a window where the old code
  is briefly running against the new schema. Acceptable at one host with a
  restart measured in seconds; it becomes a real constraint the moment a
  zero-downtime requirement appears, and the answer then is expand/contract
  migrations rather than a different deployment topology.
- A single host is a single point of failure, with the database on a Docker
  volume beside the application. Backups are the operator's responsibility and
  are documented as a manual procedure in
  [deployment.md](../deployment.md), not automated here.
- Sessions are files on a volume, so exactly one PHP container may serve
  traffic. Horizontal scaling needs a shared session store first.
