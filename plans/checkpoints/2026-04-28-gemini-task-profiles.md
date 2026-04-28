# Checkpoint: Gemini Task Profiles

Fecha: 2026-04-28
Estado Kanban: Ready -> In Dev

## Alcance aprobado

Agregar perfiles de generacion Gemini por tarea para reducir latencia/tokens sin cambiar la logica de decision.

## Invariantes Golden Case

- `EstadoDetallado=manual_review`
- `DocumentosProcesados=3`
- `coincidencias=34`
- `discrepancias=1`
- `no_concluyentes=1`
- `DocumentoFallido=AUTORIZACION`
- `NombreArticulo/AUTORIZACION=NO_CONCLUYENTE`
- `CodigoDiagnostico/FORMULA MEDICA=NO_ENCONTRADO`

El texto exacto de `detalle` semantico no es criterio de aceptacion.

## Archivos objetivo

- `app/Services/Audit/GeminiConfig.php`
- `app/Services/Audit/SemanticMatchJudge.php`
- `app/Services/Audit/Pipeline/DocumentExtractionWorker.php`
- `.env.example`
- `AGENTS.md`
- `CHANGELOG.md`
- Tests existentes de extraccion y semantica

## Cambios fuera de alcance

No tocar cambios ajenos detectados en:

- `.agent/skills/*`
- `app/Routes/web.php`
- `app/Controllers/ObservabilityController.php`
- `app/Services/Audit/Debug/ResponseIADiskStore.php`
- `plans/data-flows.md`
