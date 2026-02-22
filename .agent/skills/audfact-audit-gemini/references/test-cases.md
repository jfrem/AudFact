# Test Cases - audfact-audit-gemini

## Plantilla GWT
```text
Given: datos de factura/dispensacion/adjuntos
When: se ejecuta POST /audit o auditInvoice()
Then: respuesta valida, archivos limpiados, resultado persistido
```

## Casos
1. Auditoria simple exitosa
   - Given: factura con adjuntos disponibles.
   - When: `POST /audit` con `limit=1`.
   - Then: `200`, item con `response` valido y archivo en `responseIA/`.
2. Sin API key
   - Given: `GEMINI_API_KEY` vacia.
   - When: instanciar `GeminiAuditService`.
   - Then: error controlado y respuesta `response=error`.
3. Documento requerido faltante
   - Given: adjunto obligatorio con `SIN_DOCUMENTOS`.
   - When: ejecutar auditoria.
   - Then: termina con mensaje de documentos faltantes.
