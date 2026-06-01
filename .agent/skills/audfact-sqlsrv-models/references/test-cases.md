# Test Cases - audfact-sqlsrv-models

## Plantilla GWT
```text
Given: parametros de consulta
When: se ejecuta metodo del modelo
Then: SQL parametrizado, shape esperado, sin excepciones inesperadas
```

## Casos
1. Límite superior de búsqueda interactiva
   - Given: `pageSize=5000`.
   - When: `searchInvoices(...)`.
   - Then: se aplica tope `100`.
2. Límite superior de batch interno
   - Given: `limit=5000`.
   - When: `getInvoicesForAuditBatch(...)`.
   - Then: se aplica tope `1000`.
3. Tipado PDO
   - Given: `facNitSec` entero y `date` string.
   - When: bind de parametros.
   - Then: `PDO::PARAM_INT` y `PDO::PARAM_STR` correctos.
4. Stream BLOB inexistente
   - Given: `attachmentId` no encontrado.
   - When: leer stream BLOB.
   - Then: retorna stream `null` y cierre seguro del cursor.
