# AgriScan backend: Phase 1 (foundation)

This folder is an **overlay** for a fresh Laravel 11/12 project, not a complete skeleton.

## Install

```bash
composer create-project laravel/laravel agriscan-api
cd agriscan-api
php artisan install:api                 # installs Sanctum and routes/api.php
# copy this folder's contents over the project (overwrite when asked), then:
rm database/migrations/0001_01_01_000000_create_users_table.php   # replaced by ours
php artisan queue:table && php artisan cache:table && php artisan session:table
cp .env.example .env && php artisan key:generate
# edit .env: Neon credentials, SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD
php artisan migrate
php artisan db:seed
php artisan test
```

Set `config/cors.php` `allowed_origins` to `[env('FRONTEND_URL')]` (publish with `php artisan config:publish cors`).

## Status
- Written but **not executed**: no PHP or Composer was available where this was generated. Run `php artisan test` locally and send me any failures.
- Done: 16-table schema, models, Sanctum token auth, password reset, account deletion, role middleware, admin user and quota management, rate limiters, reference-data and admin seeders, auth and role tests.
- Not yet: AI adapters, storage adapters, scans, quota enforcement on upload, monitoring (phases 2-4).
