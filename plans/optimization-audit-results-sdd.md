# Especificación SDD: Optimización de Rendimiento en Listado de Auditorías

## Clasificación del Cambio (Triage)

| Dimensión | Valores Posibles |
| --- | --- |
| Tipo | Refactor / Optimización |
| Riesgo | Medio |
| Persistencia afectada | No |
| Contrato externo afectado | No (el frontend no recibe cambios en el payload final) |
| Cambio arquitectónico | Sí (delegación de decodificación de JSON a SQL Server) |
| Producción afectada | Sí |
| Requiere 0.3.1 (cobertura de abstracciones) | No |

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| --- | --- | --- | --- | --- | --- |
| AuditStatusModel.php | `app/Models/AuditStatusModel.php` | MODIFIED | Modelo de lectura de estado de auditorías | 214-223, 338-345, 372-396, 402-416 | Sí |
| AuditController.php | `app/Controllers/AuditController.php` | INSPECTED | Controlador REST del endpoint `/audit/results` | 184-259 | Sí |
| audit-results-table.tsx | `frontend/components/results/audit-results-table.tsx` | INSPECTED | Componente UI que lista los resultados | 1-258 | Sí |
| audit-result-detail-modal.tsx | `frontend/components/results/audit-result-detail-modal.tsx` | INSPECTED | Modal UI que muestra el detalle (ya usa segunda petición) | 1-438 | Sí |

**Criterio de Cierre del Perímetro:**
- Búsqueda textual: `searchAuditSummaries` rastreado hasta el controlador.
- Búsqueda por símbolo: `AuditStatusModel`, `AuditResultRecord` rastreado hasta la UI (Componente `AuditResultsTable`).

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| --- | --- | --- | --- | --- | --- | --- |
| `AuditStatusModel.php` | `AuditController.php` | `app/Controllers/AuditController.php` | 237 | Directa | Estática (`->searchAuditSummaries`) | Repositorio local |
| `AuditController.php` | API Endpoint | `frontend/lib/api/endpoints.ts` | 28 | Directa | Contractual (`/audit/results`) | Repositorio local (Frontend) |

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| --- | --- | --- | --- | --- |
| Eliminar `[Hallazgos]` del `SELECT` en `searchAuditSummaries` | `AuditStatusModel.php::normalizeAuditSummary` | `app/Models/AuditStatusModel.php`:338 | Runtime (Falla decodificación) | Eliminar llamado a `decodeAuditPayload` en listado. Usar columnas virtuales extraídas por SQL. |
| Eliminar `$payload` de `buildAuditSummary` | `AuditStatusModel.php::buildAuditSummary` | `app/Models/AuditStatusModel.php`:372 | Runtime (Pérdida de conteos) | Reemplazar conteos en PHP por `JSON_VALUE(..., '$.metrics.*')` en SQL Server. |

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| --- | --- | --- | --- | --- |
| SQL Server | `JSON_VALUE` retorna escalar string extraído del JSON | Empírica | Query ejecutada: `SELECT JSON_VALUE([Hallazgos], '$.metrics.total_campos')` | Sí, extrae conteos. |

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| --- | --- | --- | --- | --- |
| Desarrollo local | Backend API Call | UI Listado -> API -> Model | Sí | [CONFIRMADO] SQL Server 2017+ local |
| CI (GitHub Actions) | Pruebas Unitarias | PHPUnit | Sí | [CONFIRMADO] Uso de mocks en PHPUnit |
| Producción | Backend API Call | UI Listado -> Nginx -> PHP-FPM -> DB | Sí | [CONFIRMADO] SQL Server en prod soporta JSON |
| Testing aislado | Tests | PHPUnit en Docker | Sí | N/A |

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia (ruta:línea) |
| --- | --- | --- |
| UI ya hace segunda petición para el modal | Confirmado | `frontend/components/results/audit-result-detail-modal.tsx`:75 |
| UI tabla requiere `findingsCount` | Confirmado | `frontend/components/results/audit-results-table.tsx`:201 |
| Payload gigante `[Hallazgos]` decodificado en PHP para cada row de la tabla | Confirmado | `app/Models/AuditStatusModel.php`:341 |

### 0.11 Clasificación de Completitud Inicial
`Nivel A — Implementable`. El problema está aislado al modelo de lectura, se verificó el soporte de `JSON_VALUE` en la base de datos y no se altera el contrato REST.

---

## FASE 1 — Especificación

### 1. Objetivo
Aligerar el tiempo de respuesta del endpoint `/audit/results` (Listado paginado) mediante la exclusión de la columna pesada `[Hallazgos]` de la consulta SQL y delegando la extracción de métricas necesarias a la base de datos (`JSON_VALUE`). Esto reducirá el consumo de memoria en PHP y el tiempo de transferencia/decodificación.

### 2. Alcance
**Incluido:**
- Modificación de consulta SQL en `searchAuditSummaries`.
- Refactor de `normalizeAuditSummary` y `buildAuditSummary` en `AuditStatusModel.php`.

**Excluido:**
- No se modifica la persistencia de datos.
- No se modifica el frontend (el payload final JSON retiene su forma).
- No se modifica `getAuditDetailByFacNro` (Modal).

### 3. Non Goals
- Migrar el esquema de la base de datos para crear columnas reales de métricas (se usarán columnas virtuales calculadas al vuelo).

### 4. Estado Actual
El controlador pide los resúmenes a `searchAuditSummaries`. Este selecciona `[Hallazgos]` y ejecuta un costoso `json_decode()` por fila en PHP (hasta 50 por página) solo para extraer y contar `total_campos`, `discrepancias`, etc., para la UI. 

### 5. Estado Objetivo
`searchAuditSummaries` no selecciona `[Hallazgos]`. Extrae `TotalCampos`, `Discrepancias` y `NoConcluyentes` utilizando `JSON_VALUE` a nivel del motor de base de datos SQL Server. PHP solo mapea tipos de datos sin invocar `json_decode()`.

### 6. Decisiones Arquitectónicas
| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| D1 | Usar `JSON_VALUE` en SQL Server | Modificar el schema de `AudDispEst` para persistir métricas en columnas | Evita alterar un esquema legacy y migraciones, logrando el mismo objetivo de rendimiento en la lectura. |

### 7. Dependencias
- **Base de Datos:** SQL Server 2016+ (soporte nativo para `JSON_VALUE`).

#### 7.1 Fuentes de Verdad
| Artefacto | Fuente de Verdad | Evidencia | ¿Conflicto Detectado? |
| --- | --- | --- | --- |
| Modelo `AuditStatusModel` | Código | `app/Models/AuditStatusModel.php` | No |

### 8. Invariantes
| Invariante | Enforcement | Validación |
| --- | --- | --- |
| El payload REST (`items`) retiene las mismas keys. | Mapeo en PHP | Tests Unitarios / Runtime |

### 9. Modelo de Datos
`[CONFIRMADO] Sin impacto en persistencia` (la consulta es de lectura (SELECT) y usa proyección virtual).

### 10. Contratos
El contrato REST (`/audit/results`) se mantiene 100% retrocompatible.

### 11. Trazabilidad de Requisitos
| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
| REQ-1 | Mejorar rendimiento del listado en UI | Excluir `[Hallazgos]` en `searchAuditSummaries` | API más rápida, UI carga instantáneo |

### 12. Impact Analysis
| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
| `AuditStatusModel.php` | Funciones normalizadoras | Rompe decodificación JSON si falta `Hallazgos` | Parametrizar lógica de `buildAuditSummary` | Análisis de dependencias (FASE 0.2) |

### 13. Cambios por Archivo

#### [MODIFY] `app/Models/AuditStatusModel.php`
- **Símbolo:** `AuditStatusModel::searchAuditSummaries()`, líneas observadas: 214-223
  - **Reemplazar query:** Remover `[Hallazgos]`, `[DetalleError]` (no se usa en el resumen). Añadir casteos `JSON_VALUE`.
  ```sql
  SELECT
      [FacSec] AS [DisId], [FacNro], [EstAud], [EstadoDetallado],
      [RequiereRevisionHumana], [Severidad], 
      [DocumentosProcesados], [DocumentoFallido],
      [DuracionProcesamientoMs], [FacNitSec],
      [FechaCreacion], [FechaActualizacion],
      CAST(JSON_VALUE([Hallazgos], '$.metrics.total_campos') AS INT) AS [TotalCampos],
      CAST(JSON_VALUE([Hallazgos], '$.metrics.discrepancias') AS INT) AS [Discrepancias],
      CAST(JSON_VALUE([Hallazgos], '$.metrics.no_concluyentes') AS INT) AS [NoConcluyentes]
  FROM Discolnet.dbo.AudDispEst WITH (NOLOCK)
  ```
- **Símbolo:** `AuditStatusModel::normalizeAuditSummary()`, líneas observadas: 338-345
  - Eliminar el uso de `decodeAuditPayload` para resúmenes. Llamar a `buildAuditSummary` directamente con un arreglo de métricas simulado basado en el `$row`.
- **Símbolo:** `AuditStatusModel::buildAuditSummary()`, líneas observadas: 372-396
  - Cambiar cálculos. Extraer directamente de `$row['TotalCampos']`, `$row['Discrepancias']` y `$row['NoConcluyentes']` en lugar del payload.

### 14. Plan de Migración
N/A (No hay migración de datos).

### 15. Casos Límite
| Condición | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| `[Hallazgos]` es NULL | `JSON_VALUE` devuelve NULL. Casteado a `(int)` en PHP da 0. | `findingsCount` = 0 |

### 16. Testing
- **Validaciones Manuales:** Abrir el frontend en `/audit/results`, recargar y verificar carga instantánea. Los conteos numéricos de "Campos" deben permanecer intactos. El modal debe abrir y mostrar todo correctamente.

### 17. Riesgos
| Riesgo | Tipo | Severidad | Mitigación |
| --- | --- | --- | --- |
| Sintaxis SQL `JSON_VALUE` incorrecta | Técnico | S3 | Probado localmente mediante MCP query a la base de datos de Dev. |

### 18. Criterios de Aceptación
1. El endpoint `/audit/results` responde de manera sustancialmente más rápida.
2. El contrato JSON en el frontend no cambia (incluye `findingsCount`).

### 19. Observabilidad
`[CONFIRMADO] Sin impacto en observabilidad`.

### 20. Estrategia de Rollout
`[CONFIRMADO] Sin estrategia de rollout requerida` por ser un cambio de Riesgo Medio que no altera contratos externos.

---

## FASE 2 — Auditoría de Consistencia
| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Todas las entidades persistentes definidas | PASS | `AudDispEst` |
| Todos los contratos documentados | PASS | Retrocompatible |
| Todos los requisitos tienen trazabilidad | PASS | REQ-1 |

## FASE 3 — Auditoría Arquitectónica
| Pregunta | Resultado | Evidencia |
| --- | --- | --- |
| ¿Existe alguna afirmación sin evidencia? | No | Testeado en BD. |

**Auditoría Adversarial Anti-Regresión:** Todas resultan en `NO` o `SÍ-CORREGIDO`. (La regresión Runtime de `normalizeAuditSummary` se soluciona excluyendo el decode, `SÍ-CORREGIDO`).

## FASE 4 — Resultado Final
**Nivel de Completitud:** `Nivel A — Implementable`
