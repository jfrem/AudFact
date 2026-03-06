---
name: audfact-sqlsrv-models
description: Modificar o depurar acceso a datos SQL Server en AudFact. Usar cuando se cambien consultas en app/Models/*, conexión en core/Database.php, parámetros PDO, rendimiento de queries o compatibilidad con datos BLOB.
---

# AudFact SQL Server Models

## Objetivo
Evolucionar consultas SQL sin degradar seguridad ni comportamiento funcional.

> [!IMPORTANT]
> Consulta el esquema detallado y las relaciones en [database-schema.md](file:///c:/Users/USER/Desktop/AudFact/plans/database-schema.md).

## Archivos clave

| Archivo | Tamaño | Rol |
|---|---|---|
| `core/Database.php` | 5.8 KB | Singleton PDO: conexiones, transacciones, queries |
| `app/Models/Model.php` | 4 KB | Base abstracta con `$fillable`, `$table`, CRUD |
| `app/Models/ClientsModel.php` | 1.6 KB | Búsqueda/lookup de clientes |
| `app/Models/InvoicesModel.php` | 1.1 KB | Búsqueda de facturas por facNitSec/fecha |
| `app/Models/DispensationModel.php` | 3.4 KB | Source of truth: datos de dispensación |
| `app/Models/AttachmentsModel.php` | 5.3 KB | Resolución de adjuntos (URL Drive o BLOB con stream optimizado) |
| `app/Models/AuditStatusModel.php` | 17 KB | Persistencia de auditoría: `AudDispEst` (upsert MERGE) + `AdjuntosDispensacion` (updateAuditResult: aprobada masiva / rechazada puntual) |

## Modelos y tablas

| Modelo | Tabla BD | Responsabilidad |
|---|---|---|
| `ClientsModel` | Clientes | Búsqueda por ID o criterios |
| `InvoicesModel` | `vw_discolnet_dispensas` | Facturas de dispensación por NIT, fecha, límite (LEFT JOIN `AdjDisOpc='N'` + `aud.c<aud.ca`) |
| `DispensationModel` | Dispensación | Datos de referencia (source of truth) |
| `AttachmentsModel` | `AdjuntosDispensacion` | Adjuntos URL Drive o BLOB (consumido como stream para procesamiento en memoria) |
| `AuditStatusModel` | `Discolnet.dbo.AudDispEst` + `AdjuntosDispensacion` | Estado de auditoría (upsert MERGE) + resultado en adjuntos (UPDATE aprobada/rechazada) |
| `Model` (base) | — | `$fillable`, `$table`, helpers CRUD |

## Database.php — Capacidades

| Método | Descripción |
|---|---|
| `getConnection($name)` | Singleton con pool estático, named connections (`DB_*` / `{PREFIX}_DB_*`) |
| `closeConnection($name)` | Cierra una o todas las conexiones |
| `hasConnection($name)` | Verifica si una conexión está activa |
| `getActiveConnections()` | Lista conexiones activas |
| `transaction(callable)` | Transacción con auto-rollback en excepción |
| `query($sql, $params)` | Query preparado con logging de errores |
| `lastInsertId()` | Último ID insertado |

**DSN**: `sqlsrv:Server={host},{port};Database={db};TrustServerCertificate=yes;ConnectionPooling={0|1};LoginTimeout={timeout}`

**Opciones PDO**:
- `ERRMODE_EXCEPTION` — siempre lanza excepciones
- `FETCH_ASSOC` — arrays asociativos por defecto
- `EMULATE_PREPARES` = false — queries nativos
- `STRINGIFY_FETCHES` = false — tipos nativos

## Model base — Herencia

```php
class MiModel extends Model
{
    protected $table = 'mi_tabla';
    protected $fillable = ['campo1', 'campo2'];
    // Hereda: $this->db (PDO connection)
}
```

## Flujo de trabajo
1. Identificar modelo afectado en `app/Models/`.
2. Validar tipo de parámetros (`PDO::PARAM_INT`, `PDO::PARAM_STR`, `PDO::PARAM_LOB`).
3. Mantener consultas parametrizadas.
4. Registrar logs técnicos solo con contexto útil.
5. Probar ruta/controlador que consume el modelo.

## Reglas de implementación
1. **No concatenar valores de usuario en SQL** — siempre parametrizar.
2. Mantener límites de negocio (`limit` entre `1..1000`).
3. Preservar shape de columnas consumidas por controladores/servicios.
4. **En streams BLOB, cerrar cursor y recurso siempre**.
5. No mover lógica de negocio al SQL si rompe mantenibilidad.
6. Usar `Database::transaction()` para operaciones multi-statement.

## Anti-patterns ⚠️
1. **No usar `Database::getConnection()` en controladores** — acceder siempre vía modelo.
2. **No olvidar `PDO::PARAM_LOB` para columnas BLOB** — sin esto el stream no funciona.
3. **No crear conexiones nombradas sin documentarlas** — agregar prefix `{NAME}_DB_*` en `.env.example`.
4. **No ignorar `TrustServerCertificate=yes`** — requerido para SQL Server con certificados auto-firmados.
5. **No dejar conexiones abiertas innecesariamente** — el Singleton las cache pero `closeConnection()` existe.

## Cross-references
- **`audfact-audit-gemini`**: `DispensationModel` y `AttachmentsModel` son consumidos por el Worker.
- **`audfact-api-rest`**: Controladores instancian modelos para resolver requests.

## Ejemplos

### Ejemplo 1: consulta parametrizada
```php
$sql = "SELECT TOP (:limit) FacSec, DisId
        FROM dbo.factura
        WHERE FacNitSec = :facNitSec AND FacFec = :date";

$stmt = $this->db->prepare($sql);
$stmt->bindParam(':facNitSec', $facNitSec, \PDO::PARAM_INT);
$stmt->bindParam(':date', $date, \PDO::PARAM_STR);
$stmt->bindParam(':limit', $limit, \PDO::PARAM_INT);
$stmt->execute();
return $stmt->fetchAll(\PDO::FETCH_ASSOC);
```

### Ejemplo 2: lectura de BLOB como stream
```php
$stmt = $this->db->prepare("SELECT a.AdjDisDoc FROM AdjuntosDispensacion a LEFT JOIN DispensacionDetalleServicio d ON d.DisId=a.DisId WHERE a.AdjDisId=:id AND d.DisDetNro=:invoiceId");
$stmt->bindParam(':id', $attachmentId, \PDO::PARAM_STR);
$stmt->bindParam(':invoiceId', $invoiceId, \PDO::PARAM_STR);
$stmt->execute();
$stmt->bindColumn(1, $stream, \PDO::PARAM_LOB);
```

### Ejemplo 3: transacción
```php
Database::transaction(function ($conn) use ($data) {
    $stmt = $conn->prepare("INSERT INTO tabla (col) VALUES (:val)");
    $stmt->execute([':val' => $data['val']]);
    return $conn->lastInsertId();
});
```

## Checklist rápido
1. Query parametrizada (no concatenación).
2. Tipos PDO correctos (`PARAM_INT`, `PARAM_STR`, `PARAM_LOB`).
3. Manejo de null/vacío definido.
4. Compatible con controladores actuales.
5. Sin regresión en endpoints relacionados.
6. BLOB streams cerrados correctamente.

## ⚠️ Auto-Sync (OBLIGATORIO post-implementación)

**Después de implementar cualquier cambio en los archivos gobernados por esta skill, DEBES:**

1. **Verificar si este SKILL.md sigue siendo preciso**:
   - ¿Los modelos y tablas listados siguen correctos?
   - ¿Hay modelos nuevos o eliminados?
   - ¿Las capacidades de `Database.php` siguen documentadas correctamente?
   - ¿Los ejemplos de queries siguen siendo válidos?
2. **Si detectas una desviación**: corregirla ANTES de ejecutar `audfact-docs-sync`.
3. **Ejecutar `audfact-docs-sync`**: esto es la segunda capa de validación.

> [!CAUTION]
> Ignorar este paso y dejar la skill desactualizada generará drift
> acumulativo que confundirá a futuros agentes.

## Referencias
1. Ver casos ampliados en `references/examples.md`.
2. Ver plantilla y suite en `references/test-cases.md`.
