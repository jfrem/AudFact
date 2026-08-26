# ── Stage 1: Vendor Builder ──────────────────────────────────────
FROM composer:2 AS vendor-builder
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install \
    --no-interaction --no-scripts --no-progress \
    --prefer-dist --no-dev --optimize-autoloader

# ── Stage 2: Runtime PHP-FPM ────────────────────────────────────
FROM php:8.2.33-fpm-bookworm

ARG WWWGROUP_ID=33
ARG WWWUSER_ID=33
RUN usermod -u ${WWWUSER_ID} www-data && groupmod -g ${WWWGROUP_ID} www-data

ARG ENABLE_XDEBUG=0

# Dependencias del sistema y drivers SQL Server
# gnupg2: requerido por gpg --dearmor (línea siguiente)
# git: requerido por Composer para resolución de paquetes VCS
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    git curl unzip gnupg2 ca-certificates libzip-dev unixodbc-dev gettext-base poppler-utils \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && pecl install sqlsrv-5.11.1 pdo_sqlsrv-5.11.1 apcu \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv apcu \
    && if [ "$ENABLE_XDEBUG" = "1" ]; then pecl install xdebug && docker-php-ext-enable xdebug; fi \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

WORKDIR /var/www/html

# Configuración PHP-FPM y entrypoint
COPY docker/xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
COPY docker/php-fpm-pool.conf.template /usr/local/etc/php-fpm.d/www.conf.template
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint-custom.sh
COPY docker/healthcheck.php /usr/local/bin/audfact-healthcheck.php
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint-custom.sh \
    && sed -i 's/\r$//' /usr/local/bin/audfact-healthcheck.php \
    && chmod +x /usr/local/bin/docker-entrypoint-custom.sh \
    && if [ "$ENABLE_XDEBUG" != "1" ]; then rm -f /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; fi

# CICD-002: Composer en runtime (requerido por docker-entrypoint.sh:L40 para auto-install)
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Dependencias PHP del stage vendor-builder
COPY --from=vendor-builder --chown=www-data:www-data /app/vendor ./vendor
COPY --chown=www-data:www-data composer.json composer.lock* ./

# Código fuente — solo directorios del autoload PSR-4 (composer.json:L6-9)
COPY --chown=www-data:www-data app/ ./app/
COPY --chown=www-data:www-data core/ ./core/
COPY --chown=www-data:www-data public/ ./public/
COPY --chown=www-data:www-data bin/ ./bin/

# Directorios de runtime temporales
RUN mkdir -p /tmp/audfact-runtime/ratelimit /var/www/html/logs \
    && chown -R www-data:www-data /tmp/audfact-runtime /var/www/html/logs

ENTRYPOINT ["docker-entrypoint-custom.sh"]
