# Especificación de Implementación SDD: Depuración de Dashboard y Rendimiento Mensual por EPS

**Fecha**: 2026-08-20  
**Autor**: Software Architect & AI Agent (Antigravity)  
**Estado**: LISTO PARA IMPLEMENTACIÓN (`Nivel A — Implementable`)  
**Políticas**: `write-sdd-spec`, `clean-rebuild-policy`, `impeccable`

---

## FASE 0 — Triage y Descubrimiento

### 0.1 Triage del Cambio

| Dimensión | Valor | Justificación / Evidencia |
| :--- | :--- | :--- |
| **Tipo** | Feature & Refactor | Incorpora analítica mensual por cliente y depura componentes redundantes del Dashboard. `[CONFIRMADO]` |
| **Riesgo** | Medio | No altera modelos de escritura ni la tabla transaccional `AudDispEst`. Nueva consulta `SELECT` agregada y reestructuración de la vista `/dashboard`. `[CONFIRMADO]` |
| **Persistencia afectada** | No | Solo lectura sobre `Discolnet.dbo.AudDispEst` y `DiscolmetsGx2QA.dbo.nit`. Cero cambios DDL. `[CONFIRMADO]` |
| **Contrato externo afectado** | No | Nuevo endpoint REST `GET /audit/stats/monthly` sin romper contratos existentes. `[CONFIRMADO]` |
| **Cambio arquitectónico** | No | Respeta la arquitectura MVC en backend (PHP 8.2) y App Router en frontend (Next.js 14+). `[CONFIRMADO]` |
| **Producción afectada** | No | Despliegue estándar vía CI/CD sin migraciones de datos ni downtime. `[CONFIRMADO]` |
| **Requiere 0.3.1 (abstracciones)** | No | No reemplaza mapeos estáticos por polimorfismo dinámico. `[CONFIRMADO]` |

---

### 0.2 Perímetro de Impacto

| Archivo | Propósito en el Sistema | Clasificación | Líneas Afectadas |
| :--- | :--- | :---: | :---: |
| `app/Models/AuditStatusModel.php` | Acceso a datos de auditoría en SQL Server | `MODIFIED` | Agregar método `getMonthlyPerformanceStats(?int $year)`. |
| `app/Controllers/AuditController.php` | Controlador de endpoints de auditoría | `MODIFIED` | Agregar método `monthlyPerformance()`. |
| `app/Routes/web.php` | Router central de la API | `MODIFIED` | Registrar ruta `GET /audit/stats/monthly`. |
| `tests/Models/AuditStatusModelTest.php` | Tests unitarios del modelo de auditorías | `MODIFIED` | Cobertura de la consulta agregada y formateo. |
| `tests/Controllers/AuditControllerTest.php` | Tests unitarios de endpoints | `MODIFIED` | Cobertura de respuesta HTTP 200/422/503 del nuevo endpoint. |
| `frontend/lib/schemas/domain.ts` | Esquemas Zod del dominio | `MODIFIED` | Esquemas `AuditMonthlyPerformanceItemSchema` y array. |
| `frontend/lib/api/endpoints.ts` | Catálogo de rutas del backend | `MODIFIED` | Agregar función `auditStatsMonthly: () => string`. |
| `frontend/lib/api/audfact.ts` | Cliente API tipado | `MODIFIED` | Agregar función `getAuditMonthlyPerformance({ year })`. |
| `frontend/components/dashboard/monthly-client-performance.tsx` | Componente interactivo de rendimiento mensual | `NEW` | Nuevo componente estrella de analítica y desglose por EPS. |
| `frontend/app/(dashboard)/dashboard/page.tsx` | Página principal del Dashboard | `MODIFIED` | Depurar widgets redundantes e integrar nuevo módulo. |
| `frontend/components/dashboard/async-queue-summary.tsx` | Widget legado de resumen de cola | `DELETED` | Erradicado bajo Clean Rebuild Policy (superado por `/audit/jobs`). |
| `frontend/components/dashboard/state-distribution-chart.tsx` | Gráfico circular estático no segmentado | `DELETED` | Erradicado bajo Clean Rebuild Policy (reemplazado por analítica mensual). |

---

### 0.3 Análisis de Impacto Inverso (Anti-Regresiones)

1. **¿Qué sucede al eliminar `AsyncQueueSummary`?**
   - *Verificación*: Solo se importaba en `dashboard/page.tsx`. Ningún otro componente o test lo referencia (`grep_search` verificado: 0 referencias adicionales).
   - *Regresión*: Ninguna. La nueva pantalla `/audit/jobs` provee toda la información con mayor detalle.
2. **¿Qué sucede al eliminar `StateDistributionChart`?**
   - *Verificación*: Solo se importaba en `dashboard/page.tsx` (`grep_search` verificado: 0 referencias externas).
   - *Regresión*: Ninguna. El nuevo componente `MonthlyClientPerformance` abarca estados, volúmenes, porcentajes de conformidad y meses.
3. **¿La consulta SQL afecta el rendimiento de la BD en producción?**
   - *Verificación*: La consulta filtra por `year(FechaCreacion)` y agrupa por `month(FechaCreacion), FacNitSec`. Para garantizar 0 sobrecarga, se envuelve en `Cache::remember('audit:stats:monthly:...' , 60s)`.
   - *Regresión*: Ninguna.

---

## FASE 1 — Especificación Técnica Determinista

### 1.1 Backend: SQL Server & API REST

#### 1.1.1 Modelo: `app/Models/AuditStatusModel.php`
```php
public function getMonthlyPerformanceStats(int $year): array
{
    $sql = "SELECT 
                MONTH(a.FechaCreacion) AS mes,
                a.FacNitSec AS fac_nit_sec,
                COALESCE(n.NitCom, CONCAT('Cliente #', a.FacNitSec)) AS tercero,
                COUNT(CASE WHEN a.EstAud = 1 THEN a.FacNro END) AS aud_conf,
                COUNT(CASE WHEN a.EstAud = 0 THEN a.FacNro END) AS aud_rech,
                COUNT(a.FacNro) AS total,
                SUM(CASE WHEN a.EstAud = 1 THEN a.DocumentosProcesados ELSE 0 END) AS aud_conf_doc,
                SUM(CASE WHEN a.EstAud = 0 THEN a.DocumentosProcesados ELSE 0 END) AS aud_rech_doc,
                SUM(a.DocumentosProcesados) AS total_doc
            FROM dbo.AudDispEst a WITH (NOLOCK)
            LEFT JOIN DiscolmetsGx2QA.dbo.nit n WITH (NOLOCK) ON n.NitSec = a.FacNitSec
            WHERE YEAR(a.FechaCreacion) = :year
            GROUP BY MONTH(a.FechaCreacion), a.FacNitSec, n.NitCom
            ORDER BY mes ASC, total DESC";

    $stmt = $this->getConnection()->prepare($sql);
    $stmt->execute([':year' => $year]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    return array_map(function (array $row): array {
        $total = (int) ($row['total'] ?? 0);
        $conf = (int) ($row['aud_conf'] ?? 0);
        $rech = (int) ($row['aud_rech'] ?? 0);
        $confDoc = (int) ($row['aud_conf_doc'] ?? 0);
        $rechDoc = (int) ($row['aud_rech_doc'] ?? 0);
        $totalDoc = (int) ($row['total_doc'] ?? 0);

        return [
            'mes'           => (int) $row['mes'],
            'fac_nit_sec'   => (int) $row['fac_nit_sec'],
            'tercero'       => trim((string) $row['tercero']),
            'aud_conf'      => $conf,
            'aud_rech'      => $rech,
            'total'         => $total,
            'rate_conf'     => $total > 0 ? round(($conf / $total) * 100, 1) : 0.0,
            'aud_conf_doc'  => $confDoc,
            'aud_rech_doc'  => $rechDoc,
            'total_doc'     => $totalDoc,
        ];
    }, $rows);
}
```

#### 1.1.2 Endpoint: `GET /audit/stats/monthly`
* **Query Params**: `year` (opcional, integer, default: año actual `2026`).
* **Respuesta Exitosa (200 OK)**:
```json
{
  "success": true,
  "message": "Rendimiento mensual de auditoría",
  "data": {
    "year": 2026,
    "summary": {
      "total_facturas": 27891,
      "total_conformes": 19503,
      "total_rechazadas": 8388,
      "global_rate_conf": 69.9,
      "total_documentos": 86049
    },
    "items": [
      {
        "mes": 8,
        "fac_nit_sec": 2624,
        "tercero": "NUEVA EPS SA",
        "aud_conf": 2898,
        "aud_rech": 3995,
        "total": 6893,
        "rate_conf": 42.0,
        "aud_conf_doc": 9263,
        "aud_rech_doc": 13784,
        "total_doc": 23047
      }
    ]
  }
}
```

---

### 1.2 Frontend: Mission Control Dashboard (/impeccable)

#### 1.2.1 Componente `MonthlyClientPerformance`:
* **Barra de Control Superior**:
  * Título: *Rendimiento y Producción por EPS*.
  * Selector de Año estilizado con `<Select>` Radix UI (Tema oscuro `bg-slate-900 border-slate-700`).
* **Hero Badges de Impacto del Año**:
  * Total Facturas Auditadas en el Año (`27.891`).
  * Total Soportes Evaluados por IA (`86.049`).
  * Tasa de Conformidad Global (`69.9%`).
* **Evolución por Mes (Gráfico de Barras y Tarjetas)**:
  * Desglose mensual (Mayo $\rightarrow$ Agosto).
  * Barras bicolores esmeralda (`Conformes`) vs ámbar/coral (`Rechazadas`).
* **Tabla Detallada con Filtro**:
  * Búsqueda reactiva por EPS.
  * Columnas: Mes, EPS / NIT, Facturas OK, Facturas Obs/Rech, Total Facturas, % Éxito, Total Docs.

#### 1.2.2 Depuración en `dashboard/page.tsx`:
* Se eliminan los componentes `AsyncQueueSummary` y `documentItemsWithIssues`.
* Se sustituye `StateDistributionChart` por `MonthlyClientPerformance`.
* Se preserva el triage crítico: `PriorityAuditTable` (Bandeja prioritaria) y las tarjetas KPI principales.

---

## FASE 2 — Plan de Verificación y Criterios de Aceptación

### 2.1 Pruebas Automatizadas
1. **PHPUnit**:
   - `tests/Models/AuditStatusModelTest.php`: Verifica que `getMonthlyPerformanceStats(2026)` retorna array estructurado y mapeado.
   - `tests/Controllers/AuditControllerTest.php`: Verifica que `GET /audit/stats/monthly` responde 200 OK con JSON Schema válido.
   - Ejecución: `.\vendor\bin\phpunit.bat`.
2. **Frontend Typecheck & Lint**:
   - `npx tsc --project tsconfig.json --noEmit` en `frontend/` (0 errores).
   - `npm --prefix frontend run lint` (0 errores).

### 2.2 Verificación Visual / UX
* Cargar `http://localhost:3100/dashboard`.
* Verificar que la vista carga de forma instantánea y limpia.
* Cambiar de año en el selector y verificar que los datos se recalculan y refrescan con fluidez.
* Comprobar la ausencia de widgets huérfanos o código muerto en la consola del navegador.
