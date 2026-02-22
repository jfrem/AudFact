# Test Cases - audfact-docs-sync

## Plantilla GWT
```text
Given: Modificación exitosa de código en AudFact.
When: Agente de IA intenta finalizar sesión o pedir feedback.
Then: Obligatoriedad técnica de consultar esta skill, validar impacto y rendir la matriz.
```

## Casos
1. Cambio estructural con impacto
   - Given: Modificaste `app/Models/User.php`.
   - When: Se invoca `<PROTOCOL:VALIDATE>` de `GEMINI.md`.
   - Then: Actualizar `plans/database-schema.md` y documentar impacto.
2. Fixes sin impacto arquitectónico (ej. typo o console.log)
   - Given: Corrección simple en `index.php`.
   - When: Se evalúa requerimiento de documentos.
   - Then: Completar la matriz con valor "No" para todos los campos y justificar.
3. Elusión del Sistema Ocultando Respuestas
   - Given: IA termina trabajo escribiendo excusas como "Todo completado".
   - When: Verificación pre-commit actua en el loop.
   - Then: Abortar proceso requiriendo que la "Matriz de Impacto" se rellene textualmente con sus celdas.
