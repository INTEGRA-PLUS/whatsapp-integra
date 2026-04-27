# syntax=docker/dockerfile:1.7

# ---------------------------------------------------------------------------
# Stage 1 — Frontend assets (Vite + React + Tailwind)
# ---------------------------------------------------------------------------
FROM node:22-alpine AS frontend
WORKDIR /app

COPY package.json package-lock.json* ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public

RUN npm run build


# ---------------------------------------------------------------------------
# Stage 2 — PHP dependencies (Composer)
# ---------------------------------------------------------------------------
FROM composer:2.7 AS vendor
WORKDIR /app

COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader


# ---------------------------------------------------------------------------
# Stage 3 — Runtime base (PHP-FPM + Nginx + Supervisor in one image)
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS runtime

# OCI labels
LABEL org.opencontainers.image.title="whatsapp-integra" \
      org.opencontainers.image.description="WhatsApp API Manager (Laravel 12 + Inertia/React)" \
      org.opencontainers.image.source="https://github.com/" \
      org.opencontainers.image.licenses="MIT"

ENV TZ=America/Bogota \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_MEMORY_LIMIT=256M \
    PHP_UPLOAD_MAX_FILESIZE=32M \
    PHP_POST_MAX_SIZE=32M \
    APP_HOME=/var/www/html

WORKDIR ${APP_HOME}

# System packages + PHP extensions required by Laravel + this app
RUN apk add --no-cache \
        nginx \
        supervisor \
        bash \
        curl \
        tini \
        tzdata \
        icu-data-full \
        ca-certificates \
        fcgi \
        gettext \
    && cp /usr/share/zoneinfo/${TZ} /etc/localtime \
    && echo "${TZ}" > /etc/timezone \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        autoconf \
        oniguruma-dev \
        libxml2-dev \
        icu-dev \
        libzip-dev \
        zlib-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        sockets \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del --no-network .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*

# PHP / FPM / Nginx / Supervisor configs
COPY docker/php/php.ini       /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/opcache.ini   /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/www.conf      /usr/local/etc/php-fpm.d/www.conf
COPY docker/nginx/nginx.conf  /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh     /usr/local/bin/entrypoint.sh
COPY docker/healthcheck.sh    /usr/local/bin/healthcheck.sh

RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/healthcheck.sh \
    && rm -f /etc/nginx/http.d/default.conf.bak \
    && mkdir -p /run/nginx /var/log/supervisor

# Application source — copy *after* deps so vendor changes don't bust this layer
COPY --chown=www-data:www-data . ${APP_HOME}

# Inject built artifacts from previous stages
COPY --from=vendor   --chown=www-data:www-data /app/vendor          ${APP_HOME}/vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build    ${APP_HOME}/public/build

# Create runtime directories Laravel expects + tighten permissions
RUN set -eux; \
    mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/testing \
        storage/logs \
        bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod -R ug+rwX storage bootstrap/cache; \
    # Pre-warm autoloader for the prod dependency set
    composer dump-autoload --classmap-authoritative --no-dev --working-dir=${APP_HOME} || true

# Expose Nginx (PHP-FPM stays internal on 9000)
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD /usr/local/bin/healthcheck.sh || exit 1

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
