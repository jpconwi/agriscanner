#!/usr/bin/env bash
# Container entrypoint. Deliberately does NOT run migrations: run them once, by hand, against Neon's DIRECT
# connection (see README). Destructive or schema-changing commands never run automatically on startup.
set -euo pipefail

PORT="${PORT:-10000}"
sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Caching config is safe and fast. It reads env vars at container start.
php artisan config:cache
php artisan route:cache || true

exec apache2-foreground
