---
description: Generación de Endpoint Estandarizado (Uniforme)
---

Sigue estos pasos para crear un nuevo endpoint en AudFact garantizando la coherencia técnica y funcional.

### 1. Definición de la Ruta
Registra el endpoint en `app/Routes/web.php` usando el método HTTP apropiado.
- Si es para listar/buscar: `GET`.
- Si es para crear/procesar: `POST`.
- Si es para actualizar: `PUT`/`PATCH`.

### 2. Preparación del Modelo
Asegúrate de que el modelo en `app/Models/` tenga los métodos necesarios que acepten un array `$filters`.
- Implementa `countX(array $filters): int`.
- Implementa `getX(int $page, int $pageSize, array $filters): array`.
- Usa el patrón de construcción dinámica de `WHERE` definido en `audfact-sqlsrv-models`.

### 3. Implementación del Controlador
Crea o actualiza el controlador en `app/Controllers/`.
- Usa `validateQuery(rules)` para endpoints GET.
- Usa `validate(rules)` para endpoints POST/PUT.
- Orquesta la llamada al modelo pasando el array de filtros validado.
- Retorna la respuesta usando `Response::success` con la estructura uniforme:
```json
{
    "success": true,
    "message": "...",
    "data": {
        "items": [...],
        "total": 0,
        "page": 1,
        "pageSize": 20,
        "totalPages": 0,
        "filters": { ... }
    }
}
```

### 4. Documentación y Sincronización
- Registra el nuevo endpoint en `plans/api-endpoints.md`.
- Actualiza el `README.md` si es un endpoint principal.
- Ejecuta `audfact-docs-sync` para sincronizar las skills.

### Ejemplo de Referencia
Consulta `AuditController::results` para un ejemplo real de este patrón en funcionamiento.
