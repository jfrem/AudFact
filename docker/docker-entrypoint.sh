#!/bin/sh
# Entrypoint: Auto-configura dependencias, permisos y PHP-FPM antes de arrancar
set -e

# ── 1. PHP-FPM Config ────────────────────────────────────────────
export AUDIT_FPM_TERMINATE_TIMEOUT="${AUDIT_FPM_TERMINATE_TIMEOUT:-3600}"

envsubst '${AUDIT_FPM_TERMINATE_TIMEOUT}' \
    < /usr/local/etc/php-fpm.d/www.conf.template \
    > /usr/local/etc/php-fpm.d/www.conf

echo "[entrypoint] PHP-FPM request_terminate_timeout = ${AUDIT_FPM_TERMINATE_TIMEOUT}s"

# ── 2. Auto-install Composer dependencies ─────────────────────────
# El volumen del host sobreescribe /var/www/html, ocultando el vendor/
# generado en el build de la imagen. Detectamos y reparamos aquí.
LOCKFILE="/var/www/html/composer.lock"
STAMP="/var/www/html/vendor/.composer.lock.stamp"

needs_install=0

if [ ! -f /var/www/html/vendor/autoload.php ]; then
  echo "[entrypoint] vendor/autoload.php NOT found — composer install required"
  needs_install=1
elif [ -f "$LOCKFILE" ] && [ -f "$STAMP" ]; then
  # Comparar hash del lockfile actual vs. el del último install
  current_hash=$(md5sum "$LOCKFILE" 2>/dev/null | cut -d' ' -f1)
  stamp_hash=$(cat "$STAMP" 2>/dev/null)
  if [ "$current_hash" != "$stamp_hash" ]; then
    echo "[entrypoint] composer.lock changed — updating dependencies"
    needs_install=1
  fi
elif [ -f "$LOCKFILE" ] && [ ! -f "$STAMP" ]; then
  echo "[entrypoint] No stamp file found — composer install required"
  needs_install=1
fi

if [ "$needs_install" = "1" ]; then
  echo "[entrypoint] Running composer install..."
  composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader \
    --working-dir=/var/www/html 2>&1
  # Guardar hash del lockfile para futuras comparaciones
  md5sum "$LOCKFILE" 2>/dev/null | cut -d' ' -f1 > "$STAMP"
  echo "[entrypoint] ✅ Composer dependencies installed"
else
  echo "[entrypoint] ✅ vendor/ is up to date"
fi

# ── 3. Fix runtime permissions ────────────────────────────────────
# Asegurar que www-data pueda leer .env.
if [ -f /var/www/html/.env ]; then
  chmod 644 /var/www/html/.env 2>/dev/null || true
fi

if [ "${APP_ENV}" = "production" ]; then
  echo "[entrypoint] APP_ENV=production -> logger uses stderr"
else
  mkdir -p /var/www/html/logs
  chown -R www-data:www-data /var/www/html/logs 2>/dev/null || true
  chmod -R 775 /var/www/html/logs 2>/dev/null || true

  if su -s /bin/sh -c 'touch /var/www/html/logs/.write-test && rm -f /var/www/html/logs/.write-test' www-data 2>/dev/null; then
    echo "[entrypoint] ✅ logs/ writable for www-data"
  else
    echo "[entrypoint] ⚠️ logs/ is not writable for www-data. Logger fallback will be used."
  fi
fi

# ── 4. Launch PHP-FPM ─────────────────────────────────────────────
exec docker-php-entrypoint php-fpm
