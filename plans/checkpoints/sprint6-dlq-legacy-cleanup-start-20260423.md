# Checkpoint Sprint 6 - 2026-04-23

- Objetivo:
  - retirar referencias operativas al pipeline legacy basado en Redis Lists
  - implementar administración mínima de DLQ para `dead_letter`
- Acciones previstas:
  - eliminar `AuditQueueService`, `AuditOrchestratorFactory`, `bin/audit-worker.php` y tests asociados
  - agregar endpoint REST para listar y reprocesar eventos de `audit.dlq`
  - actualizar documentación del pipeline para dejar solo Redis Streams como arquitectura vigente
