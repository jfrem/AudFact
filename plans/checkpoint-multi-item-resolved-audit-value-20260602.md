# Checkpoint: Resolucion multi-item robusta

Fecha: 2026-06-02

## Objetivo

Implementar un boundary limpio para que FDV y documento se comparen con el mismo contrato estructurado en el motor de auditoria.

## Alcance protegido

- Corregir falsos positivos multi-item como `D13260500540` (`Lote` `{5D03364, 5G00989}`).
- Preservar a Gemini como extractor: sin valores FDV en prompt y sin decisiones de negocio en IA.
- No cambiar schema SQL ni endpoints.
- No tocar cambios ajenos, especialmente `frontend/package-lock.json`.

## Archivos esperados

- `app/Services/Audit/Pipeline/ResolvedAuditValue.php`
- `app/Services/Audit/Pipeline/FieldValueResolver.php`
- `app/Services/Audit/Pipeline/DocumentPolicyEngine.php`
- `app/Models/AuditStatusModel.php`
- `tests/Services/Audit/Events/DocumentPolicyEngineTest.php`
- `plans/changelog.md`
- `.agent/skills/audfact-audit-gemini/SKILL.md`

