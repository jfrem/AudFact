# Especificación SDD: Visualización y Monitoreo de Jobs Activos en la UI y Backend

## Triage de Clasificación del Cambio

| Dimensión | Valor |
| --- | --- |
| Tipo | Feature |
| Riesgo | Medio |
| Persistencia afectada | No |
| Contrato externo afectado | No |
| Cambio arquitectónico | No |
| Producción afectada | Sí |
| Requiere 0.3.1 (cobertura de abstracciones) | No |

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| --- | --- | --- | --- | --- | --- |
| `web.php` | `c:\Users\USER\Desktop\AudFact\app\Routes\web.php` | MODIFIED | Registro de la ruta `GET /audit/jobs` | 39-40 | Sí |
| `AuditController.php` | `c:\Users\USER\Desktop\AudFact\app\Controllers\AuditController.php` | MODIFIED | Controlador para listar jobs con metadatos y clientes | 410-435 | Sí |
| `BatchJobStore.php` | `c:\Users\USER\Desktop\AudFact\app\Services\Audit\Pipeline\BatchJobStore.php` | MODIFIED | Método `listJobs(int $limit)` e indexación en Sorted Set | 60-105, 490-499 | Sí |
| `ClientsModel.php` | `c:\Users\USER\Desktop\AudFact\app\Models\ClientsModel.php` | INSPECTED | Consulta de catálogo de EPS para enriquecer nombres | 44-70 | Sí |
| `domain.ts` | `c:\Users\USER\Desktop\AudFact\frontend\lib\schemas\domain.ts` | MODIFIED | Esquema `AuditJobSummarySchema` y `AuditJobsListSchema` | 272-300 | Sí |
| `endpoints.ts` | `c:\Users\USER\Desktop\AudFact\frontend\lib\api\endpoints.ts` | MODIFIED | Generador de endpoint `auditJobs()` | 24 | Sí |
| `audfact.ts` | `c:\Users\USER\Desktop\AudFact\frontend\lib\api\audfact.ts` | MODIFIED | Función cliente `getAuditJobs()` | 120-125 | Sí |
| `page.tsx` (Jobs) | `c:\Users\USER\Desktop\AudFact\frontend\app\(dashboard)\audit\jobs\page.tsx` | MODIFIED | Vista principal de Jobs con feed en vivo | 1-16 | Sí |
| `jobs-list-live.tsx` | `c:\Users\USER\Desktop\AudFact\frontend\components\jobs\jobs-list-live.tsx` | NEW | Componente interactivo de listado y polling de jobs | 1-180 | Sí |
| `job-tracker.tsx` | `c:\Users\USER\Desktop\AudFact\frontend\components\jobs\job-tracker.tsx` | INSPECTED | Buscador manual por UUID que se mantiene como subcomponente | 1-78 | Sí |
| `AuditControllerTest.php` | `c:\Users\USER\Desktop\AudFact\tests\Controllers\AuditControllerTest.php` | MODIFIED | Pruebas unitarias para endpoint `GET /audit/jobs` | 550-608 | Sí |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| --- | --- | --- | --- |
| Búsqueda por símbolo | `jobStatus` | Localizado en `AuditController.php:412` y `web.php:39` | [`AuditController.php:412`](file:///c:/Users/USER/Desktop/AudFact/app/Controllers/AuditController.php#L412) |
| Búsqueda por símbolo | `BatchJobStore` | Localizado en `app/Services/Audit/Pipeline/BatchJobStore.php` | [`BatchJobStore.php:13`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/BatchJobStore.php#L13) |
| Búsqueda textual | `auditJob` | Localizado en `frontend/lib/api/endpoints.ts:24` | [`endpoints.ts:24`](file:///c:/Users/USER/Desktop/AudFact/frontend/lib/api/endpoints.ts#L24) |
| Búsqueda en frontend | `AuditJobsPage` | Localizado en `frontend/app/(dashboard)/audit/jobs/page.tsx:4` | [`page.tsx:4`](file:///c:/Users/USER/Desktop/AudFact/frontend/app/%28dashboard%29/audit/jobs/page.tsx#L4) |

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| --- | --- | --- | --- | --- | --- | --- |
| `BatchJobStore.php` | `RedisClient` | `core/RedisClient.php` | 9, 30 | Directa | Inyección | Repositorio local |
| `AuditController.php` | `BatchJobStore` | `app/Services/Audit/Pipeline/BatchJobStore.php` | 13, 419 | Directa | Instanciación | Repositorio local |
| `AuditController.php` | `ClientsModel` | `app/Models/ClientsModel.php` | 10 | Directa | Inyección/Factory | Repositorio local |
| `web.php` | `AuditController` | `app/Controllers/AuditController.php` | 39 | Directa | Router Dispatch | Repositorio local |
| `audfact.ts` | `endpoints.ts` | `frontend/lib/api/endpoints.ts` | 23 | Directa | Import | Repositorio local |
| `page.tsx` | `jobs-list-live.tsx` | `frontend/components/jobs/jobs-list-live.tsx` | 2 | Directa | Import JSX | Repositorio local |

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| --- | --- | --- | --- | --- |
| Agregar método `listJobs` en `BatchJobStore` | `BatchJobStore` | `BatchJobStore.php:490` | Ninguna | Método aditivo sin efectos colaterales en métodos existentes. |
| Agregar ruta `GET /audit/jobs` en `web.php` | Router | `web.php:39` | Contract | Se coloca antes de `/audit/jobs/{jobId}` para evitar conflicto de comodín. |
| Reemplazar vista vacía en `audit/jobs/page.tsx` | UI | `audit/jobs/page.tsx:10` | DX | Se mantiene `JobTracker` como colapsable/búsqueda manual directa. |

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| --- | --- | --- | --- | --- |
| `Core\Router` | Orden de resolución de rutas dinámicas `{jobId}` | Empírica | `app/Routes/web.php:39` | Sí: `/audit/jobs` se evalúa antes que `/audit/jobs/{jobId}` |
| `RedisClient` | Soporte de comandos `KEYS` / `ZREVRANGE` | Empírica | `core/RedisClient.php` | Sí: Redis 7.0 soporta sorted sets y pipelines |
| Next.js App Router | Componentes Client (`"use client"`) para polling reactivo | Documental | `frontend/components/jobs/jobs-list-live.tsx` | Sí: Polling aislado sin bloquear Server Components |

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| --- | --- | --- | --- | --- |
| Desarrollo local | Backend PHP + Next.js Dev | `npm run dev` / `localhost:8080` | Sí | `[CONFIRMADO]` Entorno operativo |
| CI (GitHub Actions) | PHPUnit + Linters | `vendor/bin/phpunit` | Sí | `[CONFIRMADO]` 481 tests passing |
| Producción LAN | Docker Compose | `http://172.16.0.3:8080/audit/jobs` | Sí | `[CONFIRMADO]` Redis 24GB activo |

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia (ruta:línea) |
| --- | --- | --- |
| Estructura de estado en Redis `audfact:job:{id}:state` | `[CONFIRMADO]` | `app/Services/Audit/Pipeline/BatchJobStore.php:70-89` |
| Formato de respuesta `/audit/jobs/{jobId}` | `[CONFIRMADO]` | `app/Controllers/AuditController.php:435-471` |
| Existencia de `ClientsModel::getAllClients()` | `[CONFIRMADO]` | `app/Models/ClientsModel.php:44-75` |
| Interfaz de cliente frontend `requestJson` | `[CONFIRMADO]` | `frontend/lib/api/client.ts:15-45` |

### 0.7 Información Faltante Crítica
- `[CONFIRMADO]` Ninguna. Toda la estructura de datos en Redis y APIs está verificada por lectura empírica.

### 0.8 Información Faltante Importante
- `[CONFIRMADO]` Ninguna.

### 0.9 Información Faltante Opcional
- `[CONFIRMADO]` Ninguna.

### 0.10 Supuestos Declarados
- `[CONFIRMADO]` Cero supuestos abiertos. Todos los datos han sido confirmados en código y base de datos.

### 0.11 Clasificación de Completitud Inicial
- `Nivel A — Implementable` (Completa, determinista, verificada empíricamente).

---

## FASE 1 — Especificación Técnica

### 1. Objetivo
Permitir a los operadores, auditores y líderes técnicos monitorear visualmente todos los jobs batch (asíncronos y del cron diario) directamente desde la UI de AudFact, visualizando su progreso en tiempo real, cliente asociado, estado y métricas, con navegación de un clic hacia el detalle y telemetría de cada job.

### 2. Alcance

#### Incluido
1. Creación del endpoint `GET /audit/jobs` en el backend para retornar el listado de jobs batch en Redis ordenados cronológicamente (`created_at DESC`), enriquecidos con el nombre de la EPS/Cliente.
2. Extensión de `BatchJobStore` con el método `listJobs(int $limit = 50)`.
3. Adición de indexación $O(\log N)$ en Redis Sorted Set `jobs:index` para listar jobs instantáneamente sin escanear todas las llaves en cada request, con fallback dinámico para jobs previos.
4. Esquemas Zod `AuditJobSummarySchema` y endpoints en `frontend/lib/api/`.
5. Componente interactivo `JobsListLive` con polling automático cada 5s, badges de estado, barra de progreso y botones de acción.
6. Actualización de la página `frontend/app/(dashboard)/audit/jobs/page.tsx`.

#### Excluido
- Modificación de la estructura interna del estado de los jobs en Redis (se mantiene inmutable).
- Modificación del flujo de procesamiento de workers o eventos en Redis Streams.

### 3. Non Goals
- No se creará persistencia histórica de jobs en SQL Server (la vida útil de los jobs batch reside en Redis con TTL de 7 días).

### 4. Estado Actual vs. Estado Objetivo

#### Estado Actual
- `frontend/app/(dashboard)/audit/jobs/page.tsx` solo contiene un formulario `JobTracker` que exige conocer de antemano el UUID de 36 caracteres del job para poder consultarlo.
- No existe ningún endpoint en el backend que liste los jobs existentes en Redis.

#### Estado Objetivo
- `GET /audit/jobs` retorna una lista JSON optimizada de jobs recientes/activos.
- La página `/audit/jobs` renderiza una tabla/tarjetas vivas con actualización continua en tiempo real, permitiendo seleccionar cualquier job para ver su telemetría.

### 5. Decisiones Arquitectónicas (Clean Rebuild Policy)

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| ADR-01 | Proyección ligera de jobs en `listJobs()` excluyendo el diccionario `audits` | Devolver el payload completo con 5.000 auditorías por job | Previene sobrecarga de red y CPU; `listJobs` solo necesita totales, estados y metadatos. |
| ADR-02 | Indexación en Redis Sorted Set `jobs:index` con timestamp Unix | `KEYS audfact:job:*:state` en cada petición | Complejidad $O(\log N + M)$ en lugar de escaneo $O(N)$ en Redis, garantizando latencias < 1ms. |
| ADR-03 | Componente React cliente con Polling usando `useInterval` configurable | Conexión WebSocket o SSE dedicada para la lista | Sencillez arquitectónica, resiliencia ante desconexiones y cero overhead de sockets de larga duración. |

### 6. Contratos de API

#### Endpoint: `GET /audit/jobs`
- **Método**: `GET`
- **Ruta**: `/audit/jobs`
- **Query Params**: `limit` (opcional, entero entre 1 y 100, default: 50)

##### Respuesta `200 OK`:
```json
{
  "success": true,
  "message": "Lista de jobs de auditoría",
  "data": [
    {
      "job_id": "85fabb86-d563-4d1c-bb49-659b728d0126",
      "fac_nit_sec": 2624,
      "client_name": "NUEVA EPS SA",
      "status": "processing",
      "total": 2987,
      "done": 450,
      "failed": 2,
      "pending": 2535,
      "progress_percent": 15,
      "avg_duration_ms": 28450,
      "accumulated_duration_ms": 12802500,
      "throughput_per_sec": 1.25,
      "created_at": "2026-08-20T15:00:14Z",
      "updated_at": "2026-08-20T15:06:35Z"
    }
  ]
}
```

---

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Todas las entidades persistentes mencionadas están definidas | PASS | Redis `jobKey()` verificado en `BatchJobStore.php:14` |
| Todos los contratos documentados con clasificación | PASS | Sección 6 completa con tipos y ejemplo JSON |
| Todos los requisitos tienen trazabilidad | PASS | Sección 1 y 2 mapeadas a componentes específicos |
| Todos los consumidores analizados | PASS | Frontend Next.js App Router verificado |
| Todas las referencias a archivos y clases existen | PASS | `BatchJobStore`, `AuditController`, `ClientsModel` verificados por lectura |
| Todos los criterios son verificables | PASS | Pruebas automatizadas y endpoints testeables |

---

## FASE 3 — Auditoría Arquitectónica y Adversarial

| # | Pregunta Adversarial | Regresión que Previene | Resultado | Evidencia |
| --- | --- | --- | --- | --- |
| 1 | ¿Existe colisión de rutas en el router de backend? | Runtime | NO | `/audit/jobs` se coloca antes que `/audit/jobs/{jobId}` en `web.php` |
| 2 | ¿El endpoint de listado degrada el rendimiento de Redis con miles de jobs? | Performance | NO | Usa Sorted Set `jobs:index` limitado a top 50 elementos |
| 3 | ¿Se introduce código muerto o capas legacy? | Clean Architecture | NO | Implementación directa sin adaptadores híbridos ni código comentado |
| 4 | ¿El componente frontend soporta estados de carga y error sin romper SSR? | UX / Hydration | NO | Componente `"use client"` con skeletons y manejo Zod seguro |

---

## FASE 4 — Resultado Final

### Nivel de Completitud
**Nivel A — Implementable** (Especificación técnica determinista, auditable y lista para ejecución).
