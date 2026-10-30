<#
.SYNOPSIS
    Antigravity PreInvocation Hook: Injects the active SDD & Engineering Lifecycle Directive (PowerShell)
#>

$reminderMessage = @"
📋 PROTOCOLO SDD AUDFACT: Si tu tarea actual implica desarrollo, refactor o modificación de código, debes cumplir el ciclo obligatorio:
1. Línea Base en Verde: Ejecutar pruebas unitarias ('vendor/bin/phpunit') antes de codificar.
2. Especificación Técnica: Elaborar la spec SDD en 'plans/sdd-<tema>.md' o artefacto de plan (/write-sdd-spec).
3. Aclarar Supuestos: Resolver incógnitas con el usuario mediante ask_question antes de codificar.
4. Implementación (TDD): Modificar el código siguiendo pruebas y contratos.
5. Reconstrucción Limpia: Validar compuerta de decisión arquitectónica (/clean-rebuild-policy).
6. Pruebas Finales en Verde: Re-ejecutar 'vendor/bin/phpunit' tras las modificaciones para asegurar 0 regresiones.
7. Sincronización Documental: Registrar en 'plans/changelog.md' y actualizar planes afectados (audfact-docs-sync).
8. Reporte Final: Generar el artefacto de cierre con el resumen de la implementación.
"@

$output = @{
    injectSteps = @(
        @{
            ephemeralMessage = $reminderMessage
        }
    )
}

$output | ConvertTo-Json -Compress
exit 0

