# Checkpoint Sprint 3 - 2026-04-23

## Contexto
- Sprint 1 cerrado con rollback/cleanup de eventos y tests en controllers/events.
- Sprint 2 cerrado con:
  - `DocumentAuditOrchestrator`
  - `SchemaBuilder`
  - `InternalAuditApiClient`
  - contrato Gemini `extract_document_data`

## Estado previo al cambio
- No existe worker de extracción documental por `document_registered`.
- No existe cache dedicado por `document_hash`.
- No existe descarga explícita del adjunto JSON desde `InternalAuditApiClient`.
- No existe persistencia en Redis del resultado de extracción por documento.

## Objetivo inmediato
Implementar Sprint 3 del pipeline event-driven:
- worker extractor
- cache por hash
- publicación de `document_extracted`
- retry/DLQ vía `AuditEventConsumer`
- pruebas del dominio `Events`
