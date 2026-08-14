# Deployment

How Emberwatch reaches production. The reasoning behind this shape — why images
are built in CI, why deployment is manual — is
[ADR-0009](adr/0009-registry-built-images-manual-deploy.md); this document is the
operational record and the runbook.

## 1. Topology

One Hetzner Cloud host runs one Docker Compose stack, defined by
[`compose.prod.yaml`](https://github.com/whitefallen/idle-game/blob/main/compose.prod.yaml).

```
                    :443
                      │
                 ┌────▼────┐
                 │  caddy  │  TLS, Let's Encrypt, the only public port
                 └────┬────┘
                      │ http
                 ┌────▼────┐
                 │   web   │  nginx: built SPA + /api → FastCGI
                 └────┬────┘
                      │ fastcgi :9000
                 ┌────▼────┐      ┌──────────────┐   ┌───────────┐
                 │   php   │      │ outbox-relay │   │ messenger │
                 │  fpm    │      │  5s loop     │   │  consume  │
                 └────┬────┘      └──────┬───────┘   └─────┬─────┘
                      └────────────┬─────┴─────────────────┘
                              ┌────▼─────┐
                              │ postgres │  volume, no published port
                              └──────────┘
```

`php`, `outbox-relay` and `messenger` are the same image with different
commands. The relay drains the outbox onto the bus and the worker consumes it —
both out of the request path, per [ADR-0004](adr/0004-transactional-outbox.md).

Only `caddy` publishes a port. Postgres is reachable from the compose network
alone; administrative access goes through an SSH session on the host.

## 2. Images

| Image | Built from | Contains |
|-------|-----------|----------|
| `ghcr.io/whitefallen/idle-game/emberwatch-php` | `docker/php/Dockerfile`, target `prod` | PHP 8.4-FPM, production dependencies, the application, the content library at `/content` |
| `ghcr.io/whitefallen/idle-game/emberwatch-web` | `docker/web/Dockerfile` | nginx, the built frontend at `/srv/app`, `backend/public` at `/app/public` |

Both are built by the **Release** workflow on every push to `main` and tagged
`sha-<short>`. A `main` tag is also published as a convenience pointer and is
**refused by the deploy workflow** — it moves, so deploying it would mean the
artefact that was tested and the artefact that shipped are only probably the
same.

The content library is baked into the image, not mounted. That is what makes a
tag reproducible: the same tag always means the same monsters, drops and prices.

Build both locally before pushing a `Dockerfile` change:

```bash
make prod-build
```

## 3. One-time server setup

Docker is assumed to be installed and other applications are assumed to be
running on the host. Nothing below changes a global Docker or system setting.

**3.1 — Create the deploy user and directory.**

```bash
sudo useradd -m -s /bin/bash deploy && sudo usermod -aG docker deploy
sudo install -d -o deploy -g deploy /opt/emberwatch
```

**3.2 — Give CI an SSH key.** Generate it on your workstation, not the server,
so the private half never exists on the host:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/emberwatch_deploy -C "github-actions-deploy" -N ""
```

Append the public half to `/home/deploy/.ssh/authorized_keys` on the server. The
private half becomes the `DEPLOY_SSH_KEY` secret in step 4.

**3.3 — Write the environment file.** Copy
[`.env.prod.example`](https://github.com/whitefallen/idle-game/blob/main/.env.prod.example)
to `/opt/emberwatch/.env`, fill it in, and lock it down:

```bash
chmod 600 /opt/emberwatch/.env
```

Generate the two secrets it needs:

```bash
openssl rand -base64 32   # POSTGRES_PASSWORD
openssl rand -hex 32      # APP_SECRET
```

This file is never written by CI except for the `PHP_IMAGE` and `WEB_IMAGE`
lines the deploy appends. Production secrets deliberately do not travel through
a workflow.

**3.4 — Point DNS at the host before the first deploy.** Caddy requests a
certificate on startup; a failed ACME challenge counts against Let's Encrypt's
rate limits, and repeated failures lock you out for hours.

**3.5 — Decide who owns port 80 and 443.** The host already runs other
containers, so there are two cases:

- **Caddy owns the edge** (the default, and what `.env.prod.example` assumes).
  Nothing else may bind 80 or 443. Verify with `sudo ss -lntp | grep -E ':(80|443)\b'`.
- **Something else already owns the edge.** Set `CADDY_HTTP_PORT` and
  `CADDY_HTTPS_PORT` in `/opt/emberwatch/.env` to free ports and configure the
  existing proxy to terminate TLS and forward to the HTTP one. Caddy will not be
  able to complete an ACME challenge in this arrangement, so TLS becomes that
  proxy's responsibility entirely.

**3.6 — Firewall.** Only the edge ports and SSH need to be reachable. If the
host uses `ufw`, note that Docker's published ports bypass it by default —
`ufw` rules alone will not close a published port. Hetzner Cloud Firewalls
operate outside the host and do not have this problem; prefer them.

## 4. Repository configuration

Under **Settings → Secrets and variables → Actions**:

| Secret | Value |
|--------|-------|
| `DEPLOY_HOST` | The server's hostname or IP |
| `DEPLOY_USER` | `deploy` |
| `DEPLOY_SSH_KEY` | The private key from step 3.2, whole file including header and footer lines |
| `DEPLOY_KNOWN_HOSTS` | Output of `ssh-keyscan -H <host>`, run from a machine you trust |

| Variable | Value |
|----------|-------|
| `EMBERWATCH_DOMAIN` | The public hostname, e.g. `play.example.com` |
| `DEPLOY_DIR` | Optional. Defaults to `/opt/emberwatch` |
| `DEPLOY_SSH_PORT` | Optional. Defaults to `22` |

`DEPLOY_KNOWN_HOSTS` is not optional convenience — without a pinned host key the
deploy would trust whatever answers at that address.

Under **Settings → Environments**, create an environment named `production`. Add
required reviewers there if deploys should need a second pair of eyes; the
workflow already references it.

No registry credential is stored on the server. The deploy job logs in with its
own `GITHUB_TOKEN` and logs out when it finishes.

## 5. Deploying

1. Merge to `main`. The **Release** workflow builds and pushes both images, and
   prints the deployable tag in its run summary.
2. Run the **Deploy** workflow (Actions → Deploy → Run workflow) and enter that
   tag, e.g. `sha-1a2b3c4`.

The workflow then, in order: verifies both images exist in the registry, copies
`compose.prod.yaml` and the `Caddyfile` to the host, pulls the images, starts
Postgres, runs `doctrine:migrations:migrate`, brings the stack up, and polls
`https://<domain>/healthz` until it answers 200. A deployment that starts
containers but leaves the site unreachable fails the run rather than reporting
success.

`skip_migrations` exists for one case only: rolling back to a tag that predates
a migration, where re-running the migration step would be meaningless. It is not
a way past a failing migration.

## 6. Rolling back

Re-run the Deploy workflow with the previously deployed tag. The image is
already on the host, so this is a container restart, not a build.

Rollback does **not** revert migrations. Doctrine migrations in this project are
expected to be additive; if a release requires a destructive schema change,
split it into expand and contract steps across two releases so that the
intermediate state is deployable in both directions.

To find what is currently deployed:

```bash
grep IMAGE /opt/emberwatch/.env
```

## 7. Operating the host

All commands run from `/opt/emberwatch` as the `deploy` user.

```bash
docker compose -f compose.prod.yaml ps
docker compose -f compose.prod.yaml logs -f --tail 100 php
docker compose -f compose.prod.yaml exec postgres psql -U emberwatch -d emberwatch
```

**Retention.** `db:retention:prune` deletes rows past their retention window
([data-model.md](data-model.md)). It is not scheduled by this stack; add a host
cron entry for the `deploy` user:

```bash
0 4 * * * cd /opt/emberwatch && docker compose -f compose.prod.yaml run --rm php php bin/console db:retention:prune >> /var/log/emberwatch-prune.log 2>&1
```

Check what it would remove first with `--dry-run`.

**Backups.** Not automated. The database is the only irreplaceable state — the
images are rebuildable and the Caddy volume only costs a certificate re-issue.

```bash
docker compose -f compose.prod.yaml exec -T postgres \
  pg_dump -U emberwatch -Fc emberwatch > "emberwatch-$(date +%F).dump"
```

Copy the dump **off the host**. A backup that lives on the machine it is
protecting is not a backup. Restore with `pg_restore` into a stopped stack.

**Logs** are capped at 10 MB per file, three files per service, so six services
cannot exceed roughly 180 MB regardless of uptime.

## 8. Known limits

- **One PHP container.** Sessions are files on a volume, so a second replica
  would see a different set of them. Horizontal scaling requires a shared
  session store first.
- **Single host.** The database sits on a Docker volume beside the application.
  There is no failover; recovery is the backup in section 7.
- **A migration window exists.** Migrations apply before the new containers
  start, so the old code briefly runs against the new schema. Seconds at this
  size, and the reason section 6 asks for additive migrations.
- **No staging environment.** The manual deploy gate is what stands in for one.
