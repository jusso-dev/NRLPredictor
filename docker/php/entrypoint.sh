#!/usr/bin/env bash
set -euo pipefail

# Generate APP_KEY if the host hasn't provided one.
if [ -z "${APP_KEY:-}" ]; then
  # Write a minimal .env just so artisan key:generate has something to write to,
  # then export the key for this process tree.
  if [ ! -f /var/www/html/.env ]; then
    printf 'APP_KEY=\n' > /var/www/html/.env
  fi
  php artisan key:generate --force --ansi >/dev/null
  export APP_KEY="$(grep -E '^APP_KEY=' /var/www/html/.env | cut -d= -f2-)"
  echo "[entrypoint] generated APP_KEY"
fi

# Wait for MySQL to accept connections.
until php artisan db:show --database=mysql >/dev/null 2>&1; do
  echo "[entrypoint] waiting for mysql…"
  sleep 2
done
echo "[entrypoint] mysql is up"

exec "$@"
