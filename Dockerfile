FROM composer:2 AS php-dependencies

WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

FROM php:8.3-apache-bookworm

ENV APP_NAME="AI Article Summarizer" \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost:10000 \
    SESSION_NAME=LIGHT_SESSID \
    LOCAL_PYTHON_BIN=/opt/venv/bin/python \
    LOCAL_PYTHON_SCRIPT=python-engine/local_cli.py \
    LOCAL_PYTHON_TIMEOUT=180 \
    NLTK_DATA=/usr/local/share/nltk_data \
    TESSERACT_CMD=/usr/bin/tesseract \
    POPPLER_PATH=/usr/bin \
    SUMMARIZER_ENGINE_VERSION=legacy \
    SUMMARIZER_LOCAL_EMBEDDING_MODEL="" \
    STORAGE_ROOT=storage \
    STORAGE_UPLOADS=storage/uploads \
    STORAGE_AUDIO=storage/audio \
    STORAGE_LOGS=storage/logs \
    STORAGE_TMP=storage/tmp \
    TRANSLATION_PROVIDER=deep-translator \
    TRANSLATION_TARGET_LANG=tl \
    TTS_MAX_CHARS=0 \
    TTS_MAX_TEXT_LENGTH=0 \
    TTS_REQUEST_TIMEOUT_SECONDS=600 \
    TTS_OUTPUT_DIR=storage/audio \
    AUDIO_OUTPUT_DIR=storage/audio \
    AUDIO_PUBLIC_URL=tts_audio.php \
    PIPER_BIN="" \
    PIPER_VOICE=en_US-lessac-medium \
    PIPER_MODEL_EN="" \
    PIPER_MODEL_FIL="" \
    PIPER_CONFIG_EN="" \
    PIPER_CONFIG_FIL="" \
    PIPER_CACHE_DIR=storage/audio \
    PIPER_DATA_DIR=python-engine/models/piper \
    PIPER_DOWNLOAD_TIMEOUT_SECONDS=0 \
    PIPER_USE_CUDA=false \
    DB_HOST=127.0.0.1 \
    DB_PORT=3306 \
    DB_NAME=ai_summarizer \
    DB_USERNAME=root \
    DB_CHARSET=utf8mb4 \
    SMTP_HOST=localhost \
    SMTP_PORT=25 \
    SMTP_USER="" \
    SMTP_PASS="" \
    SMTP_FROM=noreply@example.com \
    PATH="/opt/venv/bin:${PATH}"

ENV APACHE_DOCUMENT_ROOT=/var/www/html/app/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential \
        ca-certificates \
        libonig-dev \
        libpq-dev \
        libzip-dev \
        poppler-utils \
        python3 \
        python3-dev \
        python3-pip \
        python3-venv \
        tesseract-ocr \
    && docker-php-ext-install -j"$(nproc)" mbstring opcache pdo_mysql pdo_pgsql zip \
    && a2enmod expires headers rewrite \
    && python3 -m venv /opt/venv \
    && rm -rf /var/lib/apt/lists/*

COPY python-engine/requirements-render.txt /tmp/requirements-render.txt
RUN python -m pip install --no-cache-dir --upgrade pip setuptools wheel \
    && python -m pip install --no-cache-dir -r /tmp/requirements-render.txt \
    && python -m nltk.downloader -d /usr/local/share/nltk_data \
        punkt punkt_tab stopwords wordnet averaged_perceptron_tagger averaged_perceptron_tagger_eng

WORKDIR /var/www/html
COPY --from=php-dependencies /build/vendor ./vendor
COPY . .

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/render-entrypoint.sh /usr/local/bin/render-entrypoint
RUN chmod 0755 /usr/local/bin/render-entrypoint \
    && mkdir -p \
        /var/www/html/storage/uploads \
        /var/www/html/storage/audio \
        /var/www/html/storage/logs \
        /var/www/html/storage/tmp \
        /var/www/html/python-engine/output/tts \
        /var/www/html/python-engine/.tmp \
    && chown -R www-data:www-data \
        /var/www/html/storage \
        /var/www/html/python-engine/output \
        /var/www/html/python-engine/.tmp

EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/render-entrypoint"]
CMD ["apache2-foreground"]
