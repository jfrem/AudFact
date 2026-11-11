#!/usr/bin/env bash
# Copia el fixture al directorio de trabajo de la ejecución, quitando el sufijo
# .fixture. En el repositorio los archivos llevan ese sufijo para que ningún agente,
# glob, compilador ni ejecutor de tests los confunda con código del proyecto.
set -eu
src="$(cd "$(dirname "$0")/files" && pwd)"
(cd "$src" && find . -type f -name '*.fixture') | while IFS= read -r f; do
  dest="./${f%.fixture}"
  mkdir -p "$(dirname "$dest")"
  cp "$src/$f" "$dest"
done
