# Ejemplos Extendidos - audfact-audit-gemini

## Happy path: auditoria de una factura
1. Ejecutar endpoint:
```bash
curl -X POST http://localhost:8080/audit ^
  -H "Content-Type: application/json" ^
  -d "{\"facNitSec\":1165,\"date\":\"2025-12-30\",\"limit\":1}"
```
2. Verificar archivo generado en `responseIA/<DisDetNro>.json`.

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
