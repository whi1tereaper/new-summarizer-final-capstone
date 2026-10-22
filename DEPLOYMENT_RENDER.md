# Render Docker deployment

This repository now contains a Docker image for the existing Apache/PHP app
and its local Python worker. No Render service was created or deployed by this
change.

## Render service settings

Create a **Web Service** from the GitHub repository
`whi1tereaper/new-summarizer-final-capstone`, branch `main`, and select
**Docker** as the runtime. The checked-in `render.yaml` mirrors the non-secret
defaults and uses `/health.php` for the HTTP health check.

The container listens on `0.0.0.0:$PORT`; do not hard-code a different Render
port. The Docker image installs PHP extensions for both MySQL and PostgreSQL,
the Python summarization runtime, Poppler, Tesseract, and the bundled Piper
voice.

## Free-plan memory blocker

The Render Free instance has 512 MB RAM. The committed image is the measured
lean profile: it retains document extraction, TF-IDF retrieval, summarization,
translation, OCR, and Piper support. The optional dense semantic profile was
tested separately with the complete CPU NLP dependencies and the bundled
MiniLM embedding model. Under a 512 MB container limit, long-PDF processing
reached 505.4 MiB and was killed with exit code 137. Therefore the complete
dense NLP engine is not verified as safe on Render Free; use a larger-memory
service or obtain approval for a different free host before enabling that
profile. No NLP dependency was removed from the application architecture.

## Required environment values

Set these in Render's Environment page. Do not commit them to `.env` or the
repository:

- `APP_URL`: the final HTTPS service URL.
- Either `DATABASE_URL` (PostgreSQL URI) or the complete `DB_HOST`, `DB_PORT`,
  `DB_NAME`, `DB_USERNAME`, and `DB_PASSWORD` set for the existing database.
  For a MySQL-compatible TiDB Cloud Starter database, set `DB_PORT=4000`,
  `DB_SSL_ENABLED=true`, and keep `DB_SSL_CA=/etc/ssl/certs/ca-certificates.crt`.
- `SMTP_USER`, `SMTP_PASS`, and `SMTP_FROM` for password-reset and account
  email delivery. The checked-in non-secret defaults use Brevo's SMTP relay on
  port `2525`, because Render Free blocks outbound ports 25, 465, and 587.

Keep `APP_ENV=production`, `APP_DEBUG=false`, and
`SUMMARIZER_ENGINE_VERSION=legacy` for the first deployment. The local model
path is intentionally blank so the worker uses its TF-IDF fallback and does
not download model files during a request. This is a deliberate free-plan
compatibility setting, not a claim that the dense model fits within 512 MB.

Before serving user traffic, inspect the ordered SQL migrations in
`database/migrations/` against a backup of the target database. Run
`php tools/migrate.php --status` and `php tools/migrate.php --dry-run` first.
The runner now refuses to execute pending destructive SQL by default and does
not execute any migration when such SQL is present. In particular,
`001_normalize_summaries.sql` copies text into `summary_artifacts` and then
contains `DROP COLUMN`; it requires explicit review and approval before any
production use. The app supports the repository's PostgreSQL path through
`DATABASE_URL`; the existing MySQL path remains available through `DB_*`.

## Persistent data requirement

The app writes user uploads, generated audio, logs, and translation cooldown
state under `storage/`. A default Render filesystem is not durable. For a
production deployment, attach a paid Render persistent disk mounted at
`/var/www/html/storage`, or change the storage implementation to durable
object storage. Free Render services are suitable only for a stateless smoke
test; uploads and audio will be lost after restart, redeploy, or spin-down.

The bundled Piper model is read-only image content. Generated TTS files are
runtime cache files and are not part of the Git repository.

## Local checks

```powershell
docker build -t ai-summarizer-render .
docker run --rm -e PORT=10000 -p 10000:10000 ai-summarizer-render
```

In a second terminal, request `http://localhost:10000/health.php` and expect a
JSON response with `"status":"ok"`. A full authenticated smoke test still
requires a configured database and SMTP values.
