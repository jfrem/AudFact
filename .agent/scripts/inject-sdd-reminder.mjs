/**
 * Antigravity PreInvocation Hook: Injects the active SDD & Engineering Lifecycle Directive
 *
 * Uses systemMessage (persists across turns) instead of ephemeralMessage (dropped after current turn)
 * to ensure the agent keeps the protocol in context throughout the full implementation session.
 */
function main() {
  const protocol =
    "🚨 PROTOCOLO SDD AUDFACT — OBLIGATORIO Y NO NEGOCIABLE 🚨\n" +
    "Si tu tarea implica desarrollo, refactor o modificación de código, DEBES ejecutar SECUENCIALMENTE y SIN OMISIONES los siguientes 10 pasos. Cada paso es una COMPUERTA: no puedes avanzar al siguiente sin haber completado el anterior. Saltarse un paso es una VIOLACIÓN DEL PROTOCOLO.\n\n" +
    "PASO 1 — LÍNEA BASE EN VERDE ⛔ BLOQUEANTE\n" +
    "Ejecutar 'vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit' y verificar 0 fallos antes de cualquier edición. El hook 'ensure-tests-baseline' bloqueará toda edición si no hay línea base certificada.\n\n" +
    "PASO 2 — ESPECIFICACIÓN TÉCNICA ⛔ BLOQUEANTE\n" +
    "Crear la spec SDD en 'plans/sdd-<tema>.md' siguiendo [write-sdd-spec/SKILL.md](../skills/write-sdd-spec/SKILL.md). El hook 'ensure-tests-baseline' también bloqueará ediciones si no existe una spec reciente (< 4 h) o artefacto de plan.\n\n" +
    "PASO 3 — ACLARAR SUPUESTOS\n" +
    "Resolver toda incógnita [DESCONOCIDO] con el usuario vía ask_question antes de codificar. No inferir valores sin evidencia.\n\n" +
    "PASO 4 — VALIDACIÓN EMPÍRICA\n" +
    "Instanciar y ejecutar las clases, métodos y componentes definidos en la spec para verificar cumplimiento de objetivos, detectar regresiones y anticipar degradaciones de rendimiento antes de consolidar código.\n\n" +
    "PASO 5 — REVISIÓN INDEPENDIENTE DE SPEC ⛔ BLOQUEANTE PARA IMPLEMENTACIÓN\n" +
    "Construir prompt: rol='Eres un revisor técnico independiente', ruta .md de la spec, checks específicos, formato='APROBADO o RECHAZADO con lista concisa de defectos persistentes'.\n" +
    "CLIs (en orden de prioridad):\n" +
    "  (A) '$prompt=\"...\"; echo $prompt | codex exec --dangerously-bypass-approvals-and-sandbox -s danger-full-access'\n" +
    "  (B) '$prompt=\"...\"; & C:\\Users\\USER\\.gemini\\bin\\agy.exe --dangerously-skip-permissions --print $prompt'\n" +
    "  (C) 'gemini -y -p \"...\"' (@google/gemini-cli)\n" +
    "Ciclo revisión→corrección→reevaluación hasta 'APROBADO' explícito. Máx. 5 iteraciones; si se agota sin aprobación, reiniciar con agente distinto. IMPLEMENTACIÓN BLOQUEADA hasta 'APROBADO'.\n\n" +
    "PASO 6 — IMPLEMENTACIÓN (TDD)\n" +
    "Modificar el código siguiendo los contratos y pruebas definidos en la spec aprobada.\n\n" +
    "PASO 7 — RECONSTRUCCIÓN LIMPIA\n" +
    "Validar compuerta de decisión arquitectónica siguiendo [clean-rebuild-policy/SKILL.md](../skills/clean-rebuild-policy/SKILL.md).\n\n" +
    "PASO 8 — PRUEBAS FINALES EN VERDE ⛔ BLOQUEANTE\n" +
    "Re-ejecutar 'vendor/bin/phpunit' tras los cambios. 0 regresiones. El hook 'enforce-docs-sync' bloqueará el cierre si las pruebas post-cambio no constan en el transcript. Opcionalmente aplicar el patrón del Paso 5 sobre 'git diff HEAD -- <archivos>' para peer-review de implementación.\n\n" +
    "PASO 9 — SINCRONIZACIÓN DOCUMENTAL ⛔ BLOQUEANTE\n" +
    "Registrar en 'plans/changelog.md' y actualizar planes afectados siguiendo [audfact-docs-sync/SKILL.md](../skills/audfact-docs-sync/SKILL.md). El hook 'enforce-docs-sync' bloqueará el cierre si falta esta evidencia.\n\n" +
    "PASO 10 — REPORTE FINAL ⛔ BLOQUEANTE\n" +
    "Generar artefacto Markdown de cierre con resumen de implementación, componentes modificados y estado final de pruebas. El hook 'enforce-docs-sync' bloqueará el cierre si no existe el artefacto.";

  const output = {
    injectSteps: [
      {
        systemMessage: {
          systemMessage: protocol,
        },
      },
    ],
  };

  console.log(JSON.stringify(output));
}

main();
