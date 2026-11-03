#!/usr/bin/env bash
# ==============================================================================
# DEPENDED Summarizer - Automated Server Setup
# Configures production .env for article_rizer_depended_db and runs full pipeline.
# ==============================================================================
set -euo pipefail

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${GREEN}=== DEPENDED Automated HestiaCP Setup ===${NC}"

# 1. Resolve project directory
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

# 2. Configure .env if missing
if [[ ! -f ".env" ]]; then
    echo -e "${YELLOW}Creating production .env from .env.example...${NC}"
    cp .env.example .env
    
    # Auto-populate target database and production flags
    sed -i "s|^DB_NAME=.*|DB_NAME=article_rizer_depended_db|" .env
    sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
    sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" .env
    sed -i "s|^SUMMARIZER_ENGINE_VERSION=.*|SUMMARIZER_ENGINE_VERSION=legacy|" .env
    
    # Set explicit absolute Python path to local venv
    sed -i "s|^LOCAL_PYTHON_BIN=.*|LOCAL_PYTHON_BIN=\"${PROJECT_DIR}/.venv/bin/python\"|" .env
    
    echo -e "${YELLOW}Please enter your MariaDB password for database 'article_rizer_depended_db':${NC}"
    read -r -s DB_PASS
    echo ""
    
    echo -e "${YELLOW}Please enter your MariaDB database username (default: article_rizer_depended_user):${NC}"
    read -r DB_USER
    if [[ -z "$DB_USER" ]]; then
        DB_USER="article_rizer_depended_user"
    fi
    
    sed -i "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" .env
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" .env
    
    chmod 600 .env
    echo -e "${GREEN}[OK] .env configured successfully.${NC}"
fi

# 3. Execute the core deployment script
chmod +x scripts/deploy_hestia.sh
bash scripts/deploy_hestia.sh

echo -e "${GREEN}=== Setup Complete! Your DEPENDED website is live. ===${NC}"
