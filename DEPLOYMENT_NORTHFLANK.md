# Northflank deployment preparation

This document prepares the existing Apache/PHP application and local Python
worker for Northflank. It does not create a Northflank project, service,
database, volume, or billing relationship.

The Render files remain supported and are intentionally not removed.

## Current compatibility

The existing `Dockerfile` is compatible with Northflank's Dockerfile build
flow:

- Dockerfile: `/Dockerfile`
- Build context: `/`
- Apache listens on `0.0.0.0:${PORT}` through
  `docker/render-entrypoint.sh`; the default is `10000`.
- Docker image port: `10000/tcp`
- Application health endpoint: `/health.php`
- PHP extensions: MySQL and PostgreSQL PDO drivers, mbstring, zip, and opcache
- Python worker: `/opt/venv/bin/python python-engine/local_cli.py`

The entrypoint filename contains `render` for historical compatibility; its
behavior is platform-neutral and is safe to reuse on Northflank.

## Important Northflank limitations and blockers

Northflank advertises a free Developer Sandbox with two services, one addon,
and two jobs. Its current billing documentation also states that every user
must add a payment method before creating resources, including on the free
plan. This conflicts with the no-card requirement, so no Northflank resource
has been created.

The public resource list includes `nf-compute-20` (0.2 vCPU, 512 MiB) and
`nf-compute-50` (0.5 vCPU, 1024 MiB), but Northflank does not publish which
memory plan is included in the Developer Sandbox. Treat the free Sandbox
memory allocation as **unconfirmed** until it is visible in an authorized
dashboard.

The complete CPU semantic profile was tested locally under a 512 MiB limit.
The model and dependencies imported successfully, but long-PDF summarization
reached 505.4 MiB and was OOM-killed with exit code 137. The current committed
image is therefore the measured lean profile with TF-IDF fallback. It retains
document extraction, summarization, translation, OCR, and Piper support, but
the dense embedding model is not enabled by default.

Do not claim the full semantic profile is Northflank-ready until it has been
built with its local model provisioned and tested at the exact authorized
memory allocation. A 1 GiB or larger approved plan is the minimum candidate;
that result is not yet locally verified because the temporary CPU Torch build
was not completed.

## Dashboard settings after approval

Use these settings only after confirming the payment-method requirement and
receiving approval to create the resource:

### Project

- Plan: Developer Sandbox, if the card requirement is accepted
- Region: choose the nearest available region to users and database
- Do not create a Northflank-managed database unless its cost and data model
  are separately approved

### Combined service

- Service type: **Combined**
- Name: `ai-article-summarizer`
- Repository: `https://github.com/whi1tereaper/new-summarizer-final-capstone`
- Branch: `main`
- Dockerfile path: `/Dockerfile`
- Docker build context: `/`
- Build engine: BuildKit
- Continuous deployment: keep disabled for the first controlled test

### Networking

- Add public port: `10000`
- Protocol: HTTP
- Accessibility: Public
- Port name: `web`
- Set runtime variable `PORT=10000`

Northflank routes its public HTTPS URL to the configured internal port. Do not
change Apache to listen on 80; the entrypoint already adapts Apache to
`PORT`.

### Health checks

Configure an HTTP readiness probe:

- Port: `10000`
- Path: `/health.php`
- Initial delay: `10` seconds
- Interval: `30` seconds
- Timeout: `10` seconds
- Failure threshold: `3`
- Success threshold: `1`

For a liveness probe, use the same endpoint only after the first deployment
has demonstrated stable startup. The health endpoint is intentionally cheap
and does not require a database query.

### Resources

For the currently verified lean profile, use the smallest allocation that is
actually available to the authorized project, then run the PDF smoke test.

For the complete semantic profile, do not use a 512 MiB plan. The safer
approved target is `nf-compute-100-2` (1 vCPU, 2048 MiB); `nf-compute-50`
(1024 MiB) is only a candidate and requires a fresh local and cloud memory
test. These plans are not asserted to be free.

## Runtime environment variables

Set non-secret values as ordinary variables and put credentials in a
Northflank secret group. Never commit the values.

Required non-secret values:

```text
APP_NAME=AI Article Summarizer
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<northflank-generated-hostname>
PORT=10000
SESSION_NAME=LIGHT_SESSID
LOCAL_PYTHON_BIN=/opt/venv/bin/python
LOCAL_PYTHON_SCRIPT=python-engine/local_cli.py
LOCAL_PYTHON_TIMEOUT=180
NLTK_DATA=/usr/local/share/nltk_data
TESSERACT_CMD=/usr/bin/tesseract
POPPLER_PATH=/usr/bin
SUMMARIZER_ENGINE_VERSION=legacy
SUMMARIZER_LOCAL_EMBEDDING_MODEL=
STORAGE_ROOT=storage
STORAGE_UPLOADS=storage/uploads
STORAGE_AUDIO=storage/audio
STORAGE_LOGS=storage/logs
STORAGE_TMP=storage/tmp
TRANSLATION_PROVIDER=deep-translator
TRANSLATION_TARGET_LANG=tl
DB_CHARSET=utf8mb4
DB_SSL_CA=/etc/ssl/certs/ca-certificates.crt
SMTP_HOST=smtp-relay.brevo.com
SMTP_PORT=2525
```

For TiDB Cloud Starter, provide these as secrets or protected variables:

```text
DATABASE_URL=disabled
DATABASE_URL_UNPOOLED=disabled
DB_HOST=<tidb-public-host>
DB_PORT=4000
DB_NAME=<database-name>
DB_USERNAME=<tidb-prefixed-username>
DB_PASSWORD=<database-password>
DB_SSL_ENABLED=true
SMTP_USER=<brevo-smtp-user>
SMTP_PASS=<brevo-smtp-key>
SMTP_FROM=<verified-sender-address>
```

The application now gives process-level Northflank variables precedence over
local `.env` values and enables verified MySQL TLS when `DB_SSL_ENABLED=true`.

## Persistent storage

If an approved Northflank persistent volume is used, mount it at:

```text
/var/www/html/storage
```

Use Single Read/Write mode and keep the service at one instance. This persists
uploads, generated audio, logs, temporary state, and the local embedding model
if it is copied into the volume. Volume pricing and the payment-method
requirement must be confirmed before creation.

Northflank's default container storage is ephemeral. If a no-card external
storage service is required instead, the application still needs an S3 adapter
before uploads and audio can be moved to object storage; setting environment
variables alone does not implement that adapter.

## Database migration safety

Before using a production database:

1. Export a verified backup.
2. Run `php tools/migrate.php --status`.
3. Run `php tools/migrate.php --dry-run`.
4. Review `001_normalize_summaries.sql`, which contains `DROP COLUMN` after
   copying legacy text into `summary_artifacts`.
5. Do not use `--allow-destructive` without explicit approval and a verified
   restore procedure.

## Local verification

```powershell
docker build -t ai-summarizer-northflank .
docker run --rm -e PORT=10000 -p 10000:10000 ai-summarizer-northflank
Invoke-WebRequest http://localhost:10000/health.php
```

The local checks must include PHP linting, Python worker self-test, a real PDF
summary, the configured memory limit, database failure handling, worker
failure handling, and a restart test proving whether storage is ephemeral.

No Northflank cloud deployment has been performed for this repository.

## Official references

- [Northflank pricing and Developer Sandbox](https://northflank.com/docs/v1/application/billing/pricing-on-northflank)
- [Northflank compute plans and quotas](https://northflank.com/docs/v1/application/billing/resource-plans-and-quotas)
- [Northflank Dockerfile builds](https://northflank.com/docs/v1/application/build/build-with-a-dockerfile)
- [Northflank ports](https://northflank.com/docs/v1/application/network/configure-ports)
- [Northflank health checks](https://northflank.com/docs/v1/application/observe/configure-health-checks)
- [Northflank persistent volumes](https://northflank.com/docs/v1/application/databases-and-persistence/add-a-volume)
