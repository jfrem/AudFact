# Checkpoint - Gemini semantic thinking level

Fecha: 2026-04-28

Objetivo:
- Corregir el perfil semántico para `gemini-3.1-pro-preview`.
- Evitar persistir errores técnicos de Gemini como detalle funcional de auditoría.

Estado previo observado:
- `GEMINI_SEMANTIC_THINKING_BUDGET=0` genera HTTP 400 en Gemini 3 Pro preview.
- `/audit/results?facNro=T38250701547&page=1&pageSize=1` persiste `Error de evaluación semántica:` en `NombreArticulo/AUTORIZACION`.

Archivos previstos:
- `app/Services/Audit/GeminiConfig.php`
- `app/Services/Audit/SemanticMatchJudge.php`
- `.env.example`
- `AGENTS.md`
- `CHANGELOG.md`
- `tests/Services/Audit/GeminiConfigTest.php`
- Test focalizado de `SemanticMatchJudge` si aplica.
