FROM php:8.2-fpm
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

ARG ENABLE_XDEBUG=0

# Instalación de dependencias y drivers
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git curl unzip gnupg2 ca-certificates libzip-dev unixodbc-dev gettext-base \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && pecl install sqlsrv-5.11.1 pdo_sqlsrv-5.11.1 \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && if [ "$ENABLE_XDEBUG" = "1" ]; then pecl install xdebug && docker-php-ext-enable xdebug; fi \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

WORKDIR /var/www/html

# Configuración de PHP y entrypoint
COPY docker/xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
COPY docker/php-fpm-pool.conf.template /usr/local/etc/php-fpm.d/www.conf.template
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint-custom.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint-custom.sh \
    && chmod +x /usr/local/bin/docker-entrypoint-custom.sh \
    && if [ "$ENABLE_XDEBUG" != "1" ]; then rm -f /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; fi

# Composer
COPY composer.json composer.lock* ./
COPY .env.example ./
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm composer-setup.php \
    && composer install --no-interaction --no-scripts --no-progress --prefer-dist

COPY . .

# Ajuste de permisos
RUN chown -R www-data:www-data /var/www/html

ENTRYPOINT ["docker-entrypoint-custom.sh"]
