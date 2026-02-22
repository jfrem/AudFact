# Test Cases - audfact-sqlsrv-models

## Plantilla GWT
```text
Given: parametros de consulta
When: se ejecuta metodo del modelo
Then: SQL parametrizado, shape esperado, sin excepciones inesperadas
```

## Casos
1. Limite superior
   - Given: `limit=5000`.
   - When: `getInvoices(...)`.
   - Then: se aplica tope `1000`.
2. Tipado PDO
   - Given: `facNitSec` entero y `date` string.
   - When: bind de parametros.
   - Then: `PDO::PARAM_INT` y `PDO::PARAM_STR` correctos.
3. Stream BLOB inexistente
   - Given: `attachmentId` no encontrado.
   - When: leer stream BLOB.
   - Then: retorna stream `null` y cierre seguro del cursor.
