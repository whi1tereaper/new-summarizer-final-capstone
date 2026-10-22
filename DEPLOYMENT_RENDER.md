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

## Required environment values

Set these in Render's Environment page. Do not commit them to `.env` or the
repository:

- `APP_URL`: the final HTTPS service URL.
- Either `DATABASE_URL` (PostgreSQL URI) or the complete `DB_HOST`, `DB_PORT`,
  `DB_NAME`, `DB_USERNAME`, and `DB_PASSWORD` set for the existing database.
- `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, and `SMTP_FROM` for
  password-reset and account email delivery.

Keep `APP_ENV=production`, `APP_DEBUG=false`, and
`SUMMARIZER_ENGINE_VERSION=legacy` for the first deployment. The local model
path is intentionally blank so the worker uses its TF-IDF fallback and does
not download model files during a request.

Before serving user traffic, apply the ordered SQL migrations in
`database/migrations/` to the target database and verify the existing data and
collations. The app supports the repository's PostgreSQL path through
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
