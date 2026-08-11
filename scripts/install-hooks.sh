#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOOK="$ROOT/.git/hooks/pre-push"

cat > "$HOOK" << 'HOOK_SCRIPT'
#!/usr/bin/env bash
# Verifica si .env.example fue modificado en los commits que se van a subir
REMOTE="$1"
RANGE="$(git merge-base HEAD "@{u}")..HEAD" 2>/dev/null || RANGE="HEAD~1..HEAD"
if git diff --name-only "$RANGE" | grep -q "^\.env\.example$"; then
  echo ""
  echo "⚠️  .env.example fue modificado en este push."
  echo "   Asegúrate de ejecutar:"
  echo "   bash scripts/sync-github-production-env.sh --apply"
  echo ""
  echo "   Presiona Ctrl+C para cancelar, o espera 5 segundos para continuar..."
  sleep 5
fi
HOOK_SCRIPT

chmod +x "$HOOK"
echo "Git hook pre-push instalado en $HOOK"
