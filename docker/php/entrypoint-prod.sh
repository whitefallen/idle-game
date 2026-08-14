#!/bin/sh
# Production container entrypoint.
#
# opcache.validate_timestamps is off in production, so the Symfony container
# must be compiled before the first request rather than lazily during it. The
# warmup happens here rather than at build time because it needs the runtime
# environment — DATABASE_URL, APP_SECRET — which only exists once the container
# is started with its real configuration.
set -eu

php bin/console cache:warmup --no-interaction
chown -R www-data:www-data var

exec "$@"
