# Test Cases - audfact-audit-gemini

## Plantilla GWT
```text
Given: datos de dispensación/adjuntos y workers activos
When: se ejecuta POST /audit/single o POST /audit/async
Then: respuesta 202, eventos procesados, resultado persistido o DLQ controlada
```

## Casos
1. Auditoria simple exitosa
   - Given: dispensación con adjuntos disponibles.
   - When: `POST /audit/single` con `DisDetNro`.
   - Then: `202`, `audit_id` creado y persistencia final en `AudDispEst`.
2. Sin API key
   - Given: `GEMINI_API_KEY` vacia.
   - When: `DocumentExtractionWorker` intenta invocar Gemini.
   - Then: error controlado, reintentos y DLQ al agotar `AUDIT_EVENT_MAX_RETRIES`.
3. Documento requerido faltante
   - Given: adjunto obligatorio con `SIN_DOCUMENTOS`.
   - When: el orquestador registra documentos.
   - Then: falla con error controlado y evento DLQ si no se recupera.
