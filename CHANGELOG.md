## [2026-05-05]

### feat
- **Frontend auditoría**: Se agregan timings persistidos del pipeline en el detalle de resultados de auditoría.
  - Archivos modificados: `frontend/lib/schemas/domain.ts`, `frontend/components/audit/audit-timings-panel.tsx`, `frontend/components/audit/audit-single-workspace.tsx`, `frontend/components/audit/status-badge.tsx`, `frontend/components/results/audit-result-detail-modal.tsx`, `frontend/components/results/audit-results-table.tsx`, `frontend/app/(dashboard)/audit/results/[facSec]/page.tsx`
  - Hallazgo resuelto: ninguno
  - Impacto: Los auditores pueden revisar duración total, fase dominante, cache, desglose por fase y consumo Gemini sin llamadas adicionales al backend.
