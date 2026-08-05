# Ejemplos Extendidos - audfact-audit-gemini

## Happy path: auditoría individual de una dispensación
1. Ejecutar endpoint:
```bash
curl -X POST http://localhost:8080/audit/single ^
  -H "Content-Type: application/json" ^
  -d "{\"DisDetNro\":\"T38250701547\"}"
```
2. Verificar respuesta HTTP `202` con `data.audit_id` y `data.status = pending`.
3. Verificar snapshot en `logs/responseIA/<DisDetNro>_*.json` únicamente cuando `APP_ENV=development` y `AUDIT_RESPONSE_IA_ENABLED=1`.

## Failure path: GEMINI_API_KEY faltante
Condicion: variable de entorno vacia.

Resultado esperado:
```json
{
  "response": "error",
  "message": "GEMINI_API_KEY no configurada",
  "documento": "MULTIPLE",
  "data": {
    "items": []
  }
}
```

## Failure path: documentos requeridos sin adjunto
Condicion: `TipoAlmacenamiento = SIN_DOCUMENTOS` en documento obligatorio.

Resultado esperado: auditoria termina con mensaje `Documentos requeridos sin archivo adjunto`.
