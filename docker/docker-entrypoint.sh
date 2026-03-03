#!/bin/sh
# Entrypoint: Inyecta variables de entorno en la configuración de PHP-FPM antes de arrancar
set -e

# Defaults si la variable no está definida
export AUDIT_FPM_TERMINATE_TIMEOUT="${AUDIT_FPM_TERMINATE_TIMEOUT:-3600}"

# Generar configuración final a partir del template
envsubst '${AUDIT_FPM_TERMINATE_TIMEOUT}' \
    < /usr/local/etc/php-fpm.d/www.conf.template \
    > /usr/local/etc/php-fpm.d/www.conf

echo "[entrypoint] PHP-FPM request_terminate_timeout = ${AUDIT_FPM_TERMINATE_TIMEOUT}s"

# Ejecutar PHP-FPM (lo que normalmente hace el contenedor)
exec docker-php-entrypoint php-fpm
