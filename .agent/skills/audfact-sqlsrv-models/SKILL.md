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

| Archivo | Rol |
|---|---|
| `core/Database.php` | Construye conexiones PDO nombradas; ofrece acceso cacheado y apertura fresca |
| `core/SqlServerConnectionExecutor.php` | Ejecuta callbacks con PDO fresco y retry por fase/modo |
| `core/SqlServerOperationMode.php` | Clasifica lectura, escritura idempotente y escritura no reproducible |
| `core/SqlServerOperationException.php` | Propaga contexto SQL sanitizado sin credenciales ni DSN |
| `app/Models/Model.php` | Base con helpers `read`, `idempotentWrite` y `nonReplayableWrite`; no retiene PDO |
| `app/Models/ClientsModel.php` | Búsqueda/lookup de clientes |
| `app/Models/InvoicesModel.php` | Búsqueda de facturas por facNitSec/fecha |
| `app/Models/DispensationModel.php` | Source of truth: datos de dispensación |
| `app/Models/AttachmentsModel.php` | Contrato público histórico de adjuntos y enumeración física interna URL/BLOB para reconciliación del pipeline |
| `app/Models/AuditConfigModel.php` | Lectura/escritura de configuración dinámica (`AudDisp`, `AudDispCampo`) |
| `app/Models/AuditStatusModel.php` | Lectura de resultados, detalle y timings de auditoría |
| `app/Models/AuditResultPersistenceModel.php` | Escritura dual transaccional idempotente y timings no reproducibles |
| `database/migrations/optimize_audit_indexes.sql` | Índices non-clustered para búsquedas de facturas |

## Modelos y tablas

| Modelo | Tabla BD | Responsabilidad |
|---|---|---|
| `ClientsModel` | Clientes | Búsqueda por ID o criterios |
| `InvoicesModel` | `Factura` + dispensación/kardex | Facturas de dispensación por NIT/fecha con paginación estándar; selecciona `vw_discolnet_dispensas.DisId` como llave canónica de auditoría |
| `DispensationModel` | `vw_discolnet_dispensas` | FDV; expone `DisId` y `Dispensa AS NumeroFactura`; pipeline selecciona por `DisId` |
| `AttachmentsModel` | `AdjuntosDispensacion` | Expone `AdjDisId` como `id_adjunto_fisico` para aislar los archivos. Cruza con el catálogo mediante la llave compuesta `NitMedDocCod` + `AdjDisNom` para evitar productos cartesianos (NO se puede cruzar `NitMedDocId = AdjDisId`). |
| `AuditConfigModel` | `Discolnet.dbo.AudDisp` + `Discolnet.dbo.AudDispCampo` | Configuración dinámica por cliente; lee y reemplaza campos activos, severidad, descripción visual, `TipoDato`, `CodigoCampo` y aplicabilidad por servicio `AplicaServicio` |
| `AuditStatusModel` | `Discolnet.dbo.AudDispEst` | Lectura de resumen, detalle, estadísticas, historial y timings persistidos. |
| `AuditResultPersistenceModel` | `Discolnet.dbo.AudDispEst` + `AdjuntosDispensacion` + `DispensacionDetalleServicio` | Upsert serializable por `FacNro`, resultados documentales set-based y trazabilidad en una transacción. Conserva el fallback de hallazgos sintéticos o huérfanos hacia la `DISPENSA`. |
| `Model` (base) | — | Ejecuta callbacks SQL por nombre y modo; nunca conserva una conexión PDO |

`AuditStatusModel` expone `auditExecuted` como campo derivado para resultados públicos: una auditoría terminal con payload persistido (`findings`, `timings` o documentos procesados) cuenta como ejecutada aunque `EstAud=0` por requerir revisión humana.

## Contrato de identidad de auditoría

Fuente completa: [`plans/audit-identity-contract.md`](file:///c:/Users/USER/Desktop/AudFact/plans/audit-identity-contract.md).

```text
vw_discolnet_dispensas.DisId == AudDispEst.FacSec (columna legacy)
DisDetNro == vw_discolnet_dispensas.Dispensa == AudDispEst.FacNro
```

`AudDispEst.FacNro` es la PK operativa de resultados persistidos; `AuditResultPersistenceModel` hace `UPDATE WITH (UPDLOCK, SERIALIZABLE)` e `INSERT` cuando no existe, mientras `AuditStatusModel` consulta detalle y timings por `FacNro`. `FacSec` conserva `DisId`.

`vw_discolnet_dispensas.facsec` es legacy/de agrupación y no debe mapearse como `DisId`.

## Conexiones y ejecución

| Método | Descripción |
|---|---|
| `getConnection($name)` | Conexión cacheada por fingerprint; usar para health y consumidores puntuales, no en modelos de workers largos |
| `openConnection($name)` | PDO nuevo no registrado en el cache; punto de entrada del executor |
| `closeConnection($name)` | Cierra una o todas las conexiones |
| `hasConnection($name)` | Verifica si una conexión está activa |
| `getActiveConnections()` | Lista conexiones activas |
| `SqlServerConnectionExecutor::execute()` | Abre PDO por intento, ejecuta callback y descarta la referencia |

Política del executor:

- Intentos máximos: 4; pausas antes de los intentos 2/3/4: 1, 5 y 30 segundos.
- `READ` e `IDEMPOTENT_WRITE`: replay de errores de conexión `08*`,
  `SHUTDOWN` y `HYT00` solo durante apertura.
- Durante statement/commit, solo se repiten desconexiones `08*` y `SHUTDOWN`.
- `HYT00` de statement, deadlock, error de datos y
  `NON_REPLAYABLE_WRITE` no se reproducen automáticamente.
- Cada intento recibe un objeto PDO distinto. Nunca guardar ese PDO en una
  propiedad, singleton de modelo o closure de larga vida.

**DSN**: `sqlsrv:Server={host},{port};Database={db};TrustServerCertificate=yes;ConnectionPooling={0|1};LoginTimeout={timeout}`

**Opciones PDO**:
- `ERRMODE_EXCEPTION` — siempre lanza excepciones
- `FETCH_ASSOC` — arrays asociativos por defecto
- `EMULATE_PREPARES` = false — queries nativos
- `STRINGIFY_FETCHES` = false — tipos nativos

## Model base — Ejecución por callback

```php
class MiModel extends Model
{
    public function find(string $id): ?array
    {
        return $this->read(function (\PDO $pdo) use ($id): ?array {
            $stmt = $pdo->prepare('SELECT * FROM mi_tabla WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            return $row === false ? null : $row;
        });
    }
}
```

## Flujo de trabajo
1. Identificar modelo afectado en `app/Models/`.
2. Validar tipo de parámetros (`PDO::PARAM_INT`, `PDO::PARAM_STR`, `PDO::PARAM_LOB`).
3. Mantener consultas parametrizadas.
4. Registrar logs técnicos solo con contexto útil.
5. Elegir explícitamente el modo de replay y probar ruta/worker que consume el modelo.

## Reglas de implementación
1. **No concatenar valores de usuario en SQL** — siempre parametrizar.
2. Mantener límites de negocio (`pageSize` de búsqueda interactiva entre `1..100`; batch interno entre `1..1000`).
3. Preservar shape de columnas consumidas por controladores/servicios.
4. **En streams BLOB, cerrar cursor y recurso siempre**.
5. No mover lógica de negocio al SQL si rompe mantenibilidad.
6. **Estandarización de Modelos**: Los métodos de búsqueda deben aceptar un array `$filters` (proveniente de `validateQuery` del controlador) para construir cláusulas `WHERE` dinámicas.
7. Encapsular toda transacción multi-statement dentro de un único callback `idempotentWrite()` o `nonReplayableWrite()`; rollback best-effort sin ocultar la excepción primaria.
8. Para persistencia concurrente de resultados, evitar `MERGE` y read-before-write; usar bloqueo serializable por la PK operativa.
9. Actualizar hallazgos documentales de una auditoría de forma set-based, no con un `UPDATE` por adjunto.
10. En el pipeline BLOB, obtener bytes y `DATALENGTH` en la misma consulta y exigir igualdad exacta antes de publicar el documento.

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

    return $this->read(function (\PDO $pdo) use ($sql, $params): array {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':offset', $params['offset'], \PDO::PARAM_INT);
        $stmt->bindValue(':pageSize', $params['pageSize'], \PDO::PARAM_INT);
        // Bindear los filtros escalares restantes según su tipo.
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $rows;
    });
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

    return $this->read(function (\PDO $pdo) use ($sql, $params): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return (int) ($row['total'] ?? 0);
    });
}
```

## Anti-patterns ⚠️
1. **No usar `Database::getConnection()` en controladores** — acceder siempre vía modelo.
2. **No olvidar `PDO::PARAM_LOB` para columnas BLOB** — sin esto el stream no funciona.
3. **No crear conexiones nombradas sin documentarlas** — agregar prefix `{NAME}_DB_*` en `.env.example`.
4. **No ignorar `TrustServerCertificate=yes`** — requerido para SQL Server con certificados auto-firmados.
5. **No retener PDO en propiedades de modelos/workers** — cada operación debe recibir la conexión desde `SqlServerConnectionExecutor`.
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

return $this->read(function (\PDO $pdo) use ($sql, $facNitSec, $date, $offset, $pageSize): array {
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':facNitSec', $facNitSec, \PDO::PARAM_INT);
    $stmt->bindValue(':date', $date, \PDO::PARAM_STR);
    $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
    $stmt->bindValue(':pageSize', $pageSize, \PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    return $rows;
});
```

### Ejemplo 2: lectura de BLOB como stream
```php
return $this->read(function (\PDO $pdo) use ($attachmentId, $disDetNro): array {
    $stmt = $pdo->prepare(
        'SELECT a.AdjDisDoc, DATALENGTH(a.AdjDisDoc) AS BlobSize
         FROM AdjuntosDispensacion a
         INNER JOIN DispensacionDetalleServicio d ON d.DisId = a.DisId
         WHERE a.AdjDisId = :id AND d.DisDetNro = :disDetNro'
    );
    $stmt->execute([':id' => $attachmentId, ':disDetNro' => $disDetNro]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    return ['bytes' => (string) $row['AdjDisDoc'], 'expected_size' => (int) $row['BlobSize']];
});
```

### Ejemplo 3: transacción
```php
return $this->idempotentWrite(function (\PDO $pdo) use ($data): string {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE tabla SET col = :val WHERE id = :id');
        $stmt->execute([':val' => $data['val'], ':id' => $data['id']]);
        $pdo->commit();

        return $data['id'];
    } catch (\Throwable $error) {
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (\Throwable) {
                // El rollback secundario no reemplaza el error primario.
            }
        }
        throw $error;
    }
});
```

## Checklist rápido
1. Query parametrizada (no concatenación).
2. Tipos PDO correctos (`PARAM_INT`, `PARAM_STR`, `PARAM_LOB`).
3. Manejo de null/vacío definido.
4. Compatible con controladores actuales.
5. Sin regresión en endpoints relacionados.
6. BLOB streams cerrados correctamente.
7. Modo de operación (`READ`, idempotente o no reproducible) justificado.
8. Ningún PDO almacenado después del callback.

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
