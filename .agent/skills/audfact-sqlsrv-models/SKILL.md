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
| `app/Models/AttachmentsModel.php` | 5.3 KB | Resolución de adjuntos (URL Drive o BLOB con stream optimizado) y consulta optimizada de requeridos (`AdjDisOpc='N'`) para pipeline IA |
| `app/Models/AuditConfigModel.php` | 10 KB | Lectura/escritura de configuración dinámica (`AudDisp`, `AudDispCampo`) incluyendo `CodigoCampo` |
| `app/Models/AuditStatusModel.php` | 17 KB | Persistencia de auditoría: `AudDispEst` (upsert MERGE) + `AdjuntosDispensacion` (updateAuditResult: aprobada masiva / rechazada puntual) |
| `database/migrations/optimize_audit_indexes.sql` | 1 KB | Contiene índices non-clustered esenciales para el rendimiento del Query en InvoicesController limitando timeouts (`FacNitSec`, `FacFec`, `DisId` cubriendo colas) |

## Modelos y tablas

| Modelo | Tabla BD | Responsabilidad |
|---|---|---|
| `ClientsModel` | Clientes | Búsqueda por ID o criterios |
| `InvoicesModel` | `Factura` + dispensación/kardex | Facturas de dispensación por NIT/fecha con paginación estándar; selecciona `vw_discolnet_dispensas.DisId` como llave canónica de auditoría |
| `DispensationModel` | `vw_discolnet_dispensas` | FDV; expone `DisId` y `Dispensa AS NumeroFactura`; pipeline selecciona por `DisId` |
| `AttachmentsModel` | `AdjuntosDispensacion` | Adjuntos URL Drive o BLOB (stream en memoria) + variante de consulta `getRequiredAttachmentsByDisDetNro` para prefiltrado en auditoría IA |
| `AuditConfigModel` | `Discolnet.dbo.AudDisp` + `Discolnet.dbo.AudDispCampo` | Configuración dinámica por cliente; lee y reemplaza campos activos, severidad, descripción visual, `TipoDato` y `CodigoCampo` |
| `AuditStatusModel` | `Discolnet.dbo.AudDispEst` + `AdjuntosDispensacion` | Estado de auditoría (upsert MERGE) + resultado en adjuntos (UPDATE aprobada/rechazada) |
| `Model` (base) | — | `$fillable`, `$table`, helpers CRUD |

`AuditStatusModel` expone `auditExecuted` como campo derivado para resultados públicos: una auditoría terminal con payload persistido (`findings`, `timings` o documentos procesados) cuenta como ejecutada aunque `EstAud=0` por requerir revisión humana.

## Contrato de identidad de auditoría

Fuente completa: [`plans/audit-identity-contract.md`](file:///c:/Users/USER/Desktop/AudFact/plans/audit-identity-contract.md).

```text
vw_discolnet_dispensas.DisId == AudDispEst.FacSec (columna legacy)
DisDetNro == vw_discolnet_dispensas.Dispensa == AudDispEst.FacNro
```

`AudDispEst.FacNro` es la PK operativa de resultados persistidos; `AuditStatusModel` hace `MERGE`, detalle y timings por `FacNro`, mientras `FacSec` conserva `DisId`.

`vw_discolnet_dispensas.facsec` es legacy/de agrupación y no debe mapearse como `DisId`.

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
2. Mantener límites de negocio (`pageSize` de búsqueda interactiva entre `1..100`; batch interno entre `1..1000`).
3. Preservar shape de columnas consumidas por controladores/servicios.
4. **En streams BLOB, cerrar cursor y recurso siempre**.
5. No mover lógica de negocio al SQL si rompe mantenibilidad.
6. **Estandarización de Modelos**: Los métodos de búsqueda deben aceptar un array `$filters` (proveniente de `validateQuery` del controlador) para construir cláusulas `WHERE` dinámicas.
7. Usar `Database::transaction()` para operaciones multi-statement.

## Patrón de Consumo de Datos y Filtrado 💎

Para asegurar que los modelos consuman información de forma uniforme, los métodos de consulta deben seguir este esquema:

### 1. Construcción Dinámica de WHERE
```php
public function getItems(int $page, int $pageSize, array $filters = []): array
{
    $offset = ($page - 1) * $pageSize;
    $where = ["1=1"]; // Base para concatenar AND
    $params = [];

    // Mapeo uniforme de filtros (deben coincidir con nombres en validateQuery)
    if (!empty($filters['facNro'])) {
        $where[] = "v.Dispensa = :facNro";
        $params['facNro'] = $filters['facNro'];
    }

    if (!empty($filters['facNitSec'])) {
        $where[] = "a.FacNitSec = :facNitSec";
        $params['facNitSec'] = $filters['facNitSec'];
    }

    $whereSql = implode(" AND ", $where);

    $sql = "SELECT v.Dispensa as NroFactura, a.*
            FROM {$this->table} a WITH (NOLOCK)
            INNER JOIN vw_discolnet_dispensas v WITH (NOLOCK) ON a.DisId = v.FacSec
            WHERE {$whereSql}
            ORDER BY a.AdJDisFecAudi DESC
            OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY";

    $params['offset'] = $offset;
    $params['pageSize'] = $pageSize;

    return $this->db->query($sql, $params)->fetchAll();
}
```

### 2. Conteo Uniforme
Todo método `getItems` debe tener su pareja `countItems` que reciba el mismo array `$filters`.
```php
public function countItems(array $filters = []): int
{
    $where = ["1=1"];
    $params = [];

    if (!empty($filters['facNro'])) {
        $where[] = "v.Dispensa = :facNro";
        $params['facNro'] = $filters['facNro'];
    }
    // ... repetir misma lógica de filtros que en getItems

    $whereSql = implode(" AND ", $where);
    $sql = "SELECT COUNT(*) as total FROM {$this->table} a
            INNER JOIN vw_discolnet_dispensas v ON a.DisId = v.FacSec
            WHERE {$whereSql}";

    $row = $this->db->query($sql, $params)->fetch();
    return (int) ($row['total'] ?? 0);
}
```

## Anti-patterns ⚠️
1. **No usar `Database::getConnection()` en controladores** — acceder siempre vía modelo.
2. **No olvidar `PDO::PARAM_LOB` para columnas BLOB** — sin esto el stream no funciona.
3. **No crear conexiones nombradas sin documentarlas** — agregar prefix `{NAME}_DB_*` en `.env.example`.
4. **No ignorar `TrustServerCertificate=yes`** — requerido para SQL Server con certificados auto-firmados.
5. **No dejar conexiones abiertas innecesariamente** — el Singleton las cache pero `closeConnection()` existe.
6. **No hardcodear valores de filtros** — usar siempre el array `$filters` inyectado desde el controlador.
7. **No bindear arrays directamente a parámetros PDO** — El driver SQLSRV lo interpreta como *Table-Valued Parameter* y lanza error `SQLSTATE[IMSSP]`. Itera el array y mapea variables escalares con `bindValue`.
8. **No usar Expresiones de Tabla Comunes (CTEs / `WITH`) con placeholders nombrados en pdo_sqlsrv**: El driver de SQL Server para PDO falla al mapear y contar descriptores de parámetros nombrados dentro de CTEs, lanzando el error `SQLSTATE[07002]: COUNT field incorrect or syntax error`. En su lugar, usa subqueries convencionales derivadas en la cláusula `FROM`, las cuales son completamente compatibles con placeholders nombrados y semánticamente idénticas para el optimizador de SQL Server.

## Cross-references
- **`audfact-audit-gemini`**: `DispensationModel` y `AttachmentsModel` son consumidos por el Worker.
- **`audfact-api-rest`**: Controladores instancian modelos para resolver requests.

## Ejemplos

### Ejemplo 1: consulta parametrizada paginada
```php
$pageSize = min(max($pageSize, 1), 100);
$offset = max($page - 1, 0) * $pageSize;

$sql = "SELECT DisId, Dispensa
        FROM dbo.factura
        WHERE FacNitSec = :facNitSec AND FacFec = :date
        ORDER BY DisId ASC
        OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY";

$stmt = $this->db->prepare($sql);
$stmt->bindValue(':facNitSec', $facNitSec, \PDO::PARAM_INT);
$stmt->bindValue(':date', $date, \PDO::PARAM_STR);
$stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
$stmt->bindValue(':pageSize', $pageSize, \PDO::PARAM_INT);
$stmt->execute();
return $stmt->fetchAll(\PDO::FETCH_ASSOC);
```

### Ejemplo 2: lectura de BLOB como stream
```php
$stmt = $this->db->prepare("SELECT a.AdjDisDoc FROM AdjuntosDispensacion a LEFT JOIN DispensacionDetalleServicio d ON d.DisId=a.DisId WHERE a.AdjDisId=:id AND d.DisDetNro=:disDetNro");
$stmt->bindParam(':id', $attachmentId, \PDO::PARAM_STR);
$stmt->bindParam(':disDetNro', $disDetNro, \PDO::PARAM_STR);
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
