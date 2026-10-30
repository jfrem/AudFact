/**
 * Antigravity PreInvocation Hook: Injects the active SDD & Engineering Lifecycle Directive
 */
function main() {
  const reminderMessage =
    "📋 PROTOCOLO SDD AUDFACT: Si tu tarea actual implica desarrollo, refactor o modificación de código, debes cumplir el ciclo obligatorio:\n" +
    "1. Línea Base en Verde: Ejecutar pruebas unitarias ('vendor/bin/phpunit') antes de codificar.\n" +
    "2. Especificación Técnica: Elaborar la spec SDD en 'plans/sdd-<tema>.md' o artefacto de plan (/write-sdd-spec).\n" +
    "3. Aclarar Supuestos: Resolver incógnitas con el usuario mediante ask_question antes de codificar.\n" +
    "4. Implementación (TDD): Modificar el código siguiendo pruebas y contratos.\n" +
    "5. Reconstrucción Limpia: Validar compuerta de decisión arquitectónica (/clean-rebuild-policy).\n" +
    "6. Pruebas Finales en Verde: Re-ejecutar 'vendor/bin/phpunit' tras las modificaciones para asegurar 0 regresiones.\n" +
    "7. Sincronización Documental: Registrar en 'plans/changelog.md' y actualizar planes afectados (audfact-docs-sync).\n" +
    "8. Reporte Final: Generar el artefacto de cierre con el resumen de la implementación.";

  const output = {
    injectSteps: [
      {
        ephemeralMessage: reminderMessage,
      },
    ],
  };

  console.log(JSON.stringify(output));
}

main();

