# The public web tier: the built frontend, plus the nginx that fronts PHP-FPM.
#
# Frontend and API are served from one origin in production for the same reason
# the Vite dev server proxies /api locally — the browser sees a single origin,
# so there is no CORS configuration anywhere and session cookies need no
# SameSite exceptions. See compose.yaml and frontend/vite.config.ts.

FROM node:24-alpine AS frontend

WORKDIR /app

# ci, not install: it installs exactly the committed lockfile and fails if
# package.json and the lockfile disagree — the same guarantee CI relies on.
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./
RUN npm run build

# ---------------------------------------------------------------------------

FROM nginx:1.27-alpine

COPY docker/web/default.conf /etc/nginx/conf.d/default.conf
COPY --from=frontend /app/dist /srv/app

# nginx never executes this file; it resolves $realpath_root from it so PHP-FPM
# receives an absolute SCRIPT_FILENAME. The path must match the PHP image's
# WORKDIR, and both copies come from the same commit, so they cannot disagree.
COPY backend/public/ /app/public/

HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=5 \
    CMD wget -qO- http://127.0.0.1/healthz > /dev/null || exit 1
