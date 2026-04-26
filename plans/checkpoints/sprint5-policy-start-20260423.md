# Checkpoint Sprint 5 - 2026-04-23

- Objetivo: implementar policy determinística, agregación final y cierre de auditoría del pipeline event-driven.
- Base tomada: Sprint 4 corregido con `document_normalized` alineado al planning.
- Riesgo principal identificado antes del cambio:
  - `docs_done` todavía representa extracción y no documentos listos para policy.
  - no existe `rules_evaluated`.
  - no existe agregación/cierre final con `audit_completed`.
