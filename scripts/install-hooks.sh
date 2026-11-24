#!/usr/bin/env bash
# ==============================================================================
# AudFact Git Hooks Installer / Uninstaller
# Configura core.hooksPath a .husky de forma idempotente con soporte de rollback
# ==============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PREV_HOOKS_FILE="$ROOT/.husky/.previous-hooks-path"
ACTION="${1:---install}"

# Capturar el estado inmediatamente anterior al inicio de esta ejecución
STATE_BEFORE_THIS_RUN=$(git -C "$ROOT" config --local core.hooksPath 2>/dev/null || true)

on_error() {
  local code=$?
  echo "❌ Error durante la ejecución de install-hooks.sh (código $code). Restaurando estado previo..."
  if [ -n "$STATE_BEFORE_THIS_RUN" ]; then
    git -C "$ROOT" config core.hooksPath "$STATE_BEFORE_THIS_RUN"
  else
    git -C "$ROOT" config --unset core.hooksPath 2>/dev/null || true
  fi
  exit "$code"
}

case "$ACTION" in
  --install)
    trap on_error ERR INT TERM

    # Si había un hooksPath previo diferente a .husky y no existe respaldo histórico, guardarlo
    if [ -n "$STATE_BEFORE_THIS_RUN" ] && [ "$STATE_BEFORE_THIS_RUN" != ".husky" ] && [ ! -f "$PREV_HOOKS_FILE" ]; then
      echo "$STATE_BEFORE_THIS_RUN" > "$PREV_HOOKS_FILE"
    fi

    git -C "$ROOT" config core.hooksPath .husky
    chmod +x "$ROOT/.husky/pre-commit" "$ROOT/.husky/pre-push" 2>/dev/null || true
    git -C "$ROOT" update-index --chmod=+x .husky/pre-commit .husky/pre-push 2>/dev/null || true

    trap - ERR INT TERM
    echo "✅ Git hooks instalados en .husky y configurados en core.hooksPath con éxito."
    ;;

  --uninstall|--rollback)
    if [ -f "$PREV_HOOKS_FILE" ]; then
      PREV=$(cat "$PREV_HOOKS_FILE")
      git -C "$ROOT" config core.hooksPath "$PREV"
      rm -f "$PREV_HOOKS_FILE"
      echo "🔄 core.hooksPath restaurado al valor anterior: '$PREV'."
    else
      git -C "$ROOT" config --unset core.hooksPath 2>/dev/null || true
      echo "🔄 core.hooksPath desvinculado (restaurado al valor por defecto .git/hooks)."
    fi
    ;;

  *)
    echo "Uso: $0 [--install|--uninstall]"
    exit 1
    ;;
esac
