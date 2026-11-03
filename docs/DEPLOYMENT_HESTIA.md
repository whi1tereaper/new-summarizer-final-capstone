# Hestia deployment runbook

Target domain: `depended.poc-online.net`

This runbook prepares the existing PHP and Python application for Hestia. It
does not log in to the control panel, change DNS, create a database, or publish
the site.

## 1. Configure the web domain

The provided Hestia screenshot shows `depended.poc-online.net` with SSL not
enabled. In Hestia, use the existing web domain if it is already configured;
otherwise add it under **WEB**. Point the domain's DNS A record to the server
IP before requesting a Let's Encrypt certificate. Add an AAAA record only if
the server is correctly reachable over IPv6.

After DNS resolves to this server, enable SSL with Let's Encrypt and force
HTTPS. If the `dpnd.poc-online.net` alias shown in the panel is to remain active,
make sure its DNS and certificate coverage are configured too.

Use the standard Hestia document root:

```text
/home/<HESTIA_USER>/web/depended.poc-online.net/public_html
```

Deploy the repository contents into that directory, without copying `.git`,
local `.env`, local virtual environments, caches, or development artifacts.
The repository root belongs at the document root: its `index.php` redirects to
`app/public/`, and its root `.htaccess` denies web access to application source,
configuration, database, storage, scripts, and other private paths.

**Required server setup:** use Hestia's Nginx + Apache web template with Apache
`.htaccess` overrides enabled. Do not serve the repository root with an
Nginx-only/PHP-FPM configuration unless equivalent deny and routing rules have
been configured and verified in Nginx first. These protections are essential
because the project root contains files that must not be web-accessible.

## 2. Install and verify server requirements

- PHP 8.1 or later, Composer, and the PHP extensions `curl`, `dom`,
  `fileinfo`, `json`, `mbstring`, `openssl`, `pdo`, and `pdo_mysql`.
- PHP `proc_open()` enabled; it must not appear in `disable_functions`.
- Python 3 and pip, plus the system packages Tesseract OCR and Poppler for
  document extraction.
- A Hestia MySQL/MariaDB database and database user.

The website runs the Python worker from PHP for summarization and related
processing. Confirm the Hestia PHP-FPM user can execute the selected Python
environment and can write to the runtime storage directories.

## 3. Install application dependencies

From the deployed project root, install the locked PHP dependencies and create
a private Python virtual environment. For a first, lean deployment, use the
render runtime requirements, which omit the optional dense semantic model:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
python3 -m venv .venv
.venv/bin/python -m pip install --upgrade pip
.venv/bin/python -m pip install -r python-engine/requirements-render.txt
```

The full `python-engine/requirements-local.txt` profile includes significantly
heavier NLP/model dependencies. Do not enable that profile or configure a local
embedding model until available memory and a representative document-processing
test have been verified on the Hestia server.

Create the runtime directories and make them writable by the PHP-FPM domain
user:

```sh
mkdir -p storage/uploads storage/audio storage/logs storage/tmp
chmod 770 storage storage/uploads storage/audio storage/logs storage/tmp
```

If the Python environment is outside the deployed project root, use its
absolute path in the environment settings below. Keep generated files and
uploads in persistent server storage and include them in the backup plan.

## 4. Configure production settings

Create `.env` from `.env.example` in the project root. Keep `.env` out of Git
and restrict its filesystem permissions to the site owner and PHP-FPM group.
The existing root `.htaccess` denies HTTP access to `.env`; verify that the
server actually applies this rule before using real credentials.

At minimum, set the following values for production:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://depended.poc-online.net
LOCAL_PYTHON_BIN=/home/<HESTIA_USER>/web/depended.poc-online.net/public_html/.venv/bin/python
LOCAL_PYTHON_SCRIPT=python-engine/local_cli.py
TESSERACT_CMD=/usr/bin/tesseract
POPPLER_PATH=/usr/bin
```

Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USERNAME`, and `DB_PASSWORD` to the
database created for this site. Set working SMTP credentials and a verified
sender for account and password-reset email. Preserve the remaining required
settings from `.env.example`; do not leave placeholder database or SMTP
credentials. Keep `SUMMARIZER_ENGINE_VERSION=legacy` for the first deployment
unless the alternative engine has been separately accepted on this server.

Text-to-speech requires its executable and voice models to be installed and
configured; do not advertise that capability until it passes a production-like
test.

## 5. Initialize and verify the database

Back up any existing database before changing it. The application migrations
target MySQL/MariaDB. From the project root, inspect the migration plan first:

```sh
php tools/migrate.php --status
php tools/migrate.php --dry-run
```

Review the pending migrations and confirm the target schema is appropriate
before applying anything. The migration runner intentionally blocks pending
destructive SQL unless explicitly allowed; do not bypass that guard without a
verified backup and an approved restore plan. For an existing site, do not
import the base schema over its database.

**Production launch is blocked until database setup is validated in staging.**
The maintenance manual records a discrepancy between the fresh-install
password-reset schema and the service contract. Also, `tools/import_schema.php`
imports the base schema and then runs pending migrations automatically. Do not
run it against production as a shortcut. Verify both an empty-database install
and an upgrade from a protected database copy, including password-reset flows,
before approving a production migration plan. See the [maintenance manual](./capstone-manuscript/APPENDIX_G_MAINTENANCE_MANUAL.md).

## 6. Pre-launch checks

Run the environment diagnostic as the same user/environment used by PHP-FPM
where possible:

```sh
php tools/check_native_environment.php
```

It checks PHP extensions, `proc_open()`, storage permissions, database access,
and the Python worker self-test. Resolve failures before opening the site to
users. Then verify:

- `https://depended.poc-online.net/` loads the landing page.
- `https://depended.poc-online.net/app/public/health.php` returns JSON with
  `"status":"ok"`.
- `https://depended.poc-online.net/.env` and requests for private project
  paths are denied, not served as files.
- A small representative document can be summarized and its uploaded and
  generated files persist after a PHP-FPM restart.
- HTTPS is valid and HTTP redirects to HTTPS.

Set PHP upload and request time limits to values consistent with the
application's document limits and worker timeouts. Review server error logs
after each smoke test; keep `APP_DEBUG=false` and do not expose those logs via
the web root.

## 7. Operations

Back up the database and runtime storage separately. Deploy updates from a
reviewed Git revision, preserve `.env` and user data, and rerun the diagnostic
and smoke tests. Do not run migrations automatically as part of deployment.
