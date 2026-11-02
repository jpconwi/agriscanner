# AgriScan — AI crop disease detection & farm advisory (Laravel)

One Laravel 12 application: server-rendered Blade UI (Tailwind + Alpine) for farmers and admins, plus a secondary JSON API under `/api/v1`. Deploys as a single Docker web service on Render's free plan.

> **Verification status — please read.** The sandbox this was written in could not reach Packagist, so Composer, `artisan` and PHPUnit were **never run**. Every PHP file passes `php -l`, the CSS/JS bundle was built, but **the application and its tests have not been executed**. Run `composer install && php artisan test` first and expect to fix small integration issues.

## What's in it

| Area | Status |
|---|---|
| Register / login / logout / password reset, profile, delete account | Complete (reset email needs a mail provider; otherwise links go to the log) |
| Scan upload → AI analysis → saved result with reference-based advice, history, delete | Complete; **needs Hugging Face token + model** and an R2 bucket |
| Dashboard, advisories, per-user storage quota meter, upload error / pending notices | Complete |
| Admin: users & roles, quotas, crop/disease CRUD, advisory CRUD, scan stats | Complete |
| Admin monitoring: AI / DB / storage health, pending retries, failed jobs, audit log, thresholds | Complete; DB/R2 capacity shows **"Unknown"** unless you supply the limit |
| Grok fallback | Off. Needs `GROK_ENABLED`, `GROK_ACCESS_AUTHORIZED=true`, key and model |
| Secondary S3 storage / secondary Postgres | Optional; off by default |
| Tests with mocked providers/storage | Written (API + Blade flows); not yet run |

## Design guarantees
- **AI chain**: Hugging Face → Grok (only if explicitly authorised) → clear failure. Timeouts, bounded exponential-backoff retries, no retry on invalid input. Results are matched only against your curated disease library; unknown conditions get no invented treatment. Confidence is `null` when the model gives none.
- **Storage**: R2 → optional secondary S3 → controlled failure. Private buckets, signed URLs, read-back verified uploads, replica-aware deletion. Nothing is written to Render's disk.
- **Database**: Neon is primary. The secondary is for explicit recovery/journal only and is never read automatically. A scan is reported saved only if the DB write succeeded; idempotency keys prevent duplicates on retry.
- **Quotas** are separate from R2/Neon limits, enforced server-side under row locks, and never auto-delete files.
- **Security**: policies + middleware enforce roles (not just hidden links), rate limits on login/upload/AI, IDOR → 404, sanitised errors, encrypted cookie sessions, secrets only in server env.

## Local setup
```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate
# set DB_URL (Neon direct string, or local Postgres), then:
php artisan migrate --seed
php artisan agriscan:create-admin you@example.com
php artisan serve
php artisan test
```
CSS/JS are pre-built and committed. To change styles: `npm install && npm run build`.

## Deploy on Render (free)
1. Push the repo; create a Blueprint from `render.yaml` (Docker, free plan).
2. Fill the `sync:false` env vars: `APP_KEY` (`php artisan key:generate --show`), `APP_URL`, `DB_URL`, `HUGGINGFACE_*`, `R2_*`, `INTERNAL_CRON_TOKEN`.
3. **Neon**: use the *pooled* string for `DB_URL` with `DB_POOLED=true`. Run migrations once with the *direct* (non-pooler) string from your machine: `DB_URL=<direct> php artisan migrate --force --seed`. The container does not migrate on boot.
4. Create the first admin: `php artisan agriscan:create-admin` (run locally against the same DB).
5. **Scheduler**: the free plan has no workers. Point a free cron service (e.g. cron-job.org) at `POST https://<app>/api/v1/internal/schedule` with header `Authorization: Bearer <INTERNAL_CRON_TOKEN>` every 5 minutes. This also keeps the service awake.

## Providers
- **Hugging Face — choosing a model**: set `HUGGINGFACE_MODEL` to a plant-disease *image-classification* model that is actually served by your inference provider (check its page for "Inference Providers"), with `HUGGINGFACE_TASK=image-classification` for real class probabilities. Its labels (e.g. `Tomato___Early_blight`) are normalised and matched to your Diseases; add diseases whose names match. The admin AI tab shows whether the model responds. A vision-chat model works with `HUGGINGFACE_TASK=chat` but yields no calibrated confidence.
- **Cloudflare R2**: create a **private** bucket and an S3 API token (Object Read & Write); set endpoint `https://<account>.r2.cloudflarestorage.com`. No public access or CORS is needed because the server uploads and hands out signed URLs.
- **Grok**: stays disabled unless `GROK_ENABLED=true` **and** `GROK_ACCESS_AUTHORIZED=true`, with key and model set. Confirm your account's pricing first.

## Known limits
- No zero-data-loss failover claim: if Neon is down, scans are not saved; where possible a pending journal entry is written to secondary DB/object storage and replayed later.
- Free tiers sleep when idle (first request is slow) and have provider-side caps that AgriScan can only display if you configure them.
- Seed data has no pesticide products or doses on purpose; add them only from verified labels or extension services. Results carry a "not a substitute for expert diagnosis" disclaimer.
