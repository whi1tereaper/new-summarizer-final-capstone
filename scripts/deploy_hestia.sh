#!/usr/bin/env bash
# ==============================================================================
# DEPENDED Summarizer - HestiaCP Production Deployment Script
# Target database: article_rizer_depended_db
# ==============================================================================
set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${YELLOW}>>> Starting DEPENDED Deployment on HestiaCP...${NC}"

# 1. Verify working directory
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"
echo "Working directory: $PROJECT_ROOT"

# 2. Check for .env file
if [[ ! -f ".env" ]]; then
    echo -e "${RED}[ERROR] .env file not found in $PROJECT_ROOT!${NC}"
    echo "Please copy .env.example to .env and configure your DB credentials (DB_NAME=article_rizer_depended_db)."
    exit 1
fi
chmod 600 .env
echo -e "${GREEN}[OK] .env file present with secure permissions (600).${NC}"

# 3. Check PHP CLI
if ! command -v php &>/dev/null; then
    echo -e "${RED}[ERROR] PHP CLI is not installed or not in PATH.${NC}"
    exit 1
fi
PHP_VER=$(php -r 'echo PHP_VERSION;')
echo -e "${GREEN}[OK] PHP CLI detected: version ${PHP_VER}${NC}"

# 4. Install / Update Composer dependencies
if ! command -v composer &>/dev/null; then
    echo -e "${YELLOW}[WARN] Composer not found globally, checking for local composer.phar...${NC}"
    if [[ ! -f "composer.phar" ]]; then
        curl -sS https://getcomposer.org/installer | php
    fi
    php composer.phar install --no-dev --prefer-dist --optimize-autoloader
else
    composer install --no-dev --prefer-dist --optimize-autoloader
fi
echo -e "${GREEN}[OK] PHP dependencies installed successfully.${NC}"

# 5. Create / Update Python virtual environment
PYTHON_BIN="python3"
if ! command -v "$PYTHON_BIN" &>/dev/null; then
    echo -e "${RED}[ERROR] Python 3 is not installed on this system.${NC}"
    exit 1
fi

if [[ ! -d ".venv" ]]; then
    echo "Creating Python virtual environment (.venv)..."
    "$PYTHON_BIN" -m venv .venv
fi

echo "Installing production Python dependencies (requirements-render.txt)..."
.venv/bin/python -m pip install --upgrade pip --quiet
.venv/bin/python -m pip install -r python-engine/requirements-render.txt --quiet
echo -e "${GREEN}[OK] Python virtual environment ready.${NC}"

# 6. Ensure runtime storage directories exist and have proper permissions
echo "Configuring runtime storage directories..."
mkdir -p storage/uploads storage/audio storage/logs storage/tmp
chmod 770 storage storage/uploads storage/audio storage/logs storage/tmp
echo -e "${GREEN}[OK] Storage directories verified.${NC}"

# 7. Apply database schema and migrations
echo "Executing database initialization / migrations..."
php tools/import_schema.php

# 8. Run full environment diagnostic
echo "Running system pre-flight verification..."
php tools/check_native_environment.php

echo -e "${GREEN}>>> DEPENDED deployment completed successfully!${NC}"

