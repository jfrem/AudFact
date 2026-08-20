#!/usr/bin/env bash
# ==============================================================================
# setup-production-cron.sh — Configuración Automatizada e Idempotente del Cron
# ==============================================================================
# Instala o actualiza el cron job diario para la ejecución de auditorías batch
# en el servidor de producción AudFact.
#
# Horarios por defecto (4 ejecuciones diarias):
#   - 06:00 AM Colombia (11:00 UTC)
#   - 10:00 AM Colombia (15:00 UTC)
#   - 02:00 PM Colombia (19:00 UTC)
#   - 06:00 PM Colombia (23:00 UTC)
#
# Parámetros / Variables de entorno:
#   DEPLOY_DIR     Ruta base de AudFact (default: $HOME/audfact-prod)
#   CRON_SCHEDULE  Expresión cron (default: "0 11,15,19,23 * * *")
#   CRON_LIMIT     Límite de facturas por lote (default: 3000)
# ==============================================================================

set -euo pipefail

DEPLOY_DIR="${DEPLOY_DIR:-$HOME/audfact-prod}"
CRON_SCHEDULE="${CRON_SCHEDULE:-0 11,15,19,23 * * *}"
CRON_LIMIT="${CRON_LIMIT:-3000}"
LOGS_DIR="$DEPLOY_DIR/logs"
LOG_FILE="$LOGS_DIR/cron-batch.log"

echo "=== Configuración de Cron Jobs — AudFact Producción ==="
echo "Directorio de deploy: $DEPLOY_DIR"
echo "Límite por ejecución: $CRON_LIMIT facturas"
echo "Expresión cron:       $CRON_SCHEDULE"
echo "Archivo de log:       $LOG_FILE"
echo ""

# 1. Asegurar existencia de directorios
mkdir -p "$LOGS_DIR"
touch "$LOG_FILE"

# 2. Localizar binario de Docker Compose
DOCKER_BIN="$(command -v docker || echo "/usr/bin/docker")"

# 3. Construir la nueva línea de crontab
CRON_COMMAND="cd $DEPLOY_DIR && $DOCKER_BIN compose exec -T php php bin/schedule-daily-batches.php --limit=$CRON_LIMIT >> $LOG_FILE 2>&1"
CRON_ENTRY="$CRON_SCHEDULE $CRON_COMMAND"

# 4. Actualizar crontab de forma idempotente (eliminar versiones previas de schedule-daily-batches)
CURRENT_CRON="$(crontab -l 2>/dev/null || true)"
CLEANED_CRON="$(echo "$CURRENT_CRON" | grep -v 'schedule-daily-batches.php' || true)"

TMP_CRON="$(mktemp)"
if [ -n "$CLEANED_CRON" ]; then
    echo "$CLEANED_CRON" > "$TMP_CRON"
    echo "$CRON_ENTRY" >> "$TMP_CRON"
else
    echo "$CRON_ENTRY" > "$TMP_CRON"
fi

crontab "$TMP_CRON"
rm -f "$TMP_CRON"

echo "✓ Crontab actualizado exitosamente."
echo ""
echo "=== Crontab Activo para $(whoami) ==="
crontab -l
echo ""
echo "=== Horarios de Ejecución ==="
echo " • 06:00 AM (Colombia) -> 11:00 UTC"
echo " • 10:00 AM (Colombia) -> 15:00 UTC"
echo " • 02:00 PM (Colombia) -> 19:00 UTC"
echo " • 06:00 PM (Colombia) -> 23:00 UTC"
echo "========================================================"
