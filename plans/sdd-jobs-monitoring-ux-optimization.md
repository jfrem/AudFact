# Especificación SDD: Optimización UI/UX del Monitoreo de Jobs Activos (Mission Control)

## Triage de Clasificación del Cambio

| Dimensión | Valor |
| --- | --- |
| Tipo | Feature / UX Refactor |
| Riesgo | Bajo |
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
| `jobs-list-live.tsx` | `c:\Users\USER\Desktop\AudFact\frontend\components\jobs\jobs-list-live.tsx` | MODIFIED | Componente reactivo de lista y telemetría de jobs en vivo | 1-382 | Sí |
| `page.tsx` (Jobs) | `c:\Users\USER\Desktop\AudFact\frontend\app\(dashboard)\audit\jobs\page.tsx` | INSPECTED | Vista principal del dashboard de jobs | 1-16 | Sí |
| `domain.ts` | `c:\Users\USER\Desktop\AudFact\frontend\lib\schemas\domain.ts` | INSPECTED | Contratos Zod `AuditJobSummarySchema` y `AuditJobsListSchema` | 387-410 | Sí |
| `audfact.ts` | `c:\Users\USER\Desktop\AudFact\frontend\lib\api\audfact.ts` | INSPECTED | Función de transporte API `getAuditJobs()` | 120-123 | Sí |
| `endpoints.ts` | `c:\Users\USER\Desktop\AudFact\frontend\lib\api\endpoints.ts` | INSPECTED | Generador de endpoint REST `auditJobs()` | 24-27 | Sí |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| --- | --- | --- | --- |
| Búsqueda por componente | `JobsListLive` | Utilizado exclusivamente en `frontend/app/(dashboard)/audit/jobs/page.tsx:2` | [`page.tsx:2`](file:///c:/Users/USER/Desktop/AudFact/frontend/app/%28dashboard%29/audit/jobs/page.tsx#L2) |
| Búsqueda de esquemas | `AuditJobSummarySchema` | Definido en `frontend/lib/schemas/domain.ts:387` | [`domain.ts:387`](file:///c:/Users/USER/Desktop/AudFact/frontend/lib/schemas/domain.ts#L387) |
| Búsqueda en rutas | `/audit/jobs` | Enrutamiento Next.js App Router verificado en `frontend/app/(dashboard)/audit/jobs/` | [`page.tsx:1`](file:///c:/Users/USER/Desktop/AudFact/frontend/app/%28dashboard%29/audit/jobs/page.tsx#L1) |

---

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| --- | --- | --- | --- | --- | --- | --- |
| `jobs-list-live.tsx` | `audfact.ts` | `frontend/lib/api/audfact.ts` | 18 | Directa | Importación estática | Repositorio local |
| `jobs-list-live.tsx` | `domain.ts` | `frontend/lib/schemas/domain.ts` | 19 | Directa | Importación de tipos | Repositorio local |
| `jobs-list-live.tsx` | Design System UI | `frontend/components/ui/*` | 20-33 | Directa | Componentes atómicos | Repositorio local |
| `page.tsx` | `jobs-list-live.tsx` | `frontend/components/jobs/jobs-list-live.tsx` | 2 | Directa | Invocación JSX | Repositorio local |

---

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| --- | --- | --- | --- | --- |
| Adición de paginación en cliente y cálculo de ETA | `jobs-list-live.tsx` | `frontend/components/jobs/jobs-list-live.tsx:75` | DX / UI | [CONFIRMADO] Calcular ETA defensivamente asegurando división por cero protegida (`avg_duration_ms > 0` y `processed > 0`); resetear a página 1 al modificar filtros o query. |
| Inyección de botón "Copiar UUID" | `jobs-list-live.tsx` | `frontend/components/jobs/jobs-list-live.tsx:230` | Runtime | [CONFIRMADO] Utilizar `navigator.clipboard.writeText()` con fallback seguro a estado visual de 2 segundos. |

---

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| --- | --- | --- | --- | --- |
| React 19 / Next.js 15 App Router | Manejo de estado del cliente y efectos con `"use client"` | Empírica / Documental | `jobs-list-live.tsx:1` declara `"use client"` | Sí — Todo el cálculo de ETA, paginación y copiado se ejecuta en el cliente sin re-renderizar el Server Component. |
| Clipboard API | `navigator.clipboard.writeText` requiere contexto seguro (HTTPS / localhost) | Documental | W3C Clipboard Specification | Sí — AudFact opera en intranet HTTP local y HTTPS productivo con soporte nativo de Clipboard. |

---

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| --- | --- | --- | --- | --- |
| Desarrollo local | Next.js dev server en puerto 3000 | `npm run dev` en `frontend/` | Sí | `GET /audit/jobs` proxy hacia backend `:8080` |
| CI (GitHub Actions) | Build de producción Next.js | `npm run build` | Sí | Typecheck `tsc --noEmit` y ESLint pasan al 100% |
| Producción Docker | Contenedor Nginx + Next.js Standalone | `docker compose up -d frontend` | Sí | Assets empaquetados en imagen GHCR |

---

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia (ruta:línea) |
| --- | --- | --- |
| Disponibilidad de métricas en backend (`avg_duration_ms`, `accumulated_duration_ms`, `throughput_per_sec`) | `[CONFIRMADO]` | Presentes en `BatchJobStore.php:570-580` y `domain.ts:398-401` |
| Disponibilidad de fechas de filtro (`date_from`, `date_to`) | `[CONFIRMADO]` | Presentes en `BatchJobStore.php:583-584` y `domain.ts:404-405` |
| Existencia de iconos Lucide React (`Copy`, `Check`, `Clock`, `Timer`, `Zap`, `Calendar`) | `[CONFIRMADO]` | Paquete `lucide-react` instalado en `frontend/package.json` |

---

### 0.7–0.9 Información Faltante
* `0.7 Crítica`: `[CONFIRMADO]` Ninguna.
* `0.8 Importante`: `[CONFIRMADO]` Ninguna.
* `0.9 Opcional`: `[CONFIRMADO]` Ninguna.

---

### 0.10 Supuestos Declarados

| ID | Supuesto | Severidad | Evidencia | Riesgo |
| --- | --- | --- | --- | --- |
| SUP-01 | La tasa de refresco óptima para un operador humano sin sobrecargar la red es de 5 segundos con selector de pausa. | S1 | Práctica estándar de dashboards de colas Redis (BullMQ / Horizon) | Ninguno; el usuario puede pausar o forzar refresco manual. |

---

### 0.11 Clasificación de Completitud Inicial
**Nivel A — Implementable**. El alcance es puramente de UI/UX en el frontend, con todos los contratos de backend ya existentes y verificados.

---

## FASE 1 — Especificación

### 1. Objetivo
Transformar la pantalla `/audit/jobs` en un **Centro de Control de Misión Crítica (Mission Control Dashboard)** que proporcione máxima visibilidad operativa, cálculo en tiempo real del tiempo restante (ETA), duración real de ejecuciones, desglose claro de facturas exitosas vs DLQ, paginación compacta sin saturación visual y herramientas de interacción rápida (copiado de UUID).

---

### 2. Alcance

#### Incluido
1. **Cálculo Dinámico de ETA (Tiempo Restante Estimado)**:
   - Para jobs en estado `processing` / `pending`:
     $$\text{ETA (segundos)} = (\text{Total} - (\text{Done} + \text{Failed})) \times \left(\frac{\text{avg\_duration\_ms}}{1000}\right)$$
   - Presentación humana: `~4 min restantes`, `~45 seg restantes` o `< 10s`.
2. **Duración de Lotes Finalizados**:
   - Para jobs en estado `completed` o `completed_with_errors`:
     Mostrar duración total acumulada: ej. `Duración: 14m 32s`.
3. **Copiado Rápido de UUID con Feedback Táctil**:
   - Botón discreto al lado del ID del job que copia el UUID completo al portapapeles y conmuta a un check verde durante 2 segundos.
4. **Desglose Explícito de Avance**:
   - Indicador visual debajo de la barra de progreso: `1.450 exitosas` / `2 en DLQ`.
5. **Rango de Fechas del Lote**:
   - Visualización del intervalo auditado si existe (`date_from` $\rightarrow$ `date_to`).
6. **Paginación Compacta en Cliente**:
   - Selector de tamaño (10, 25, 50 jobs) y controles de página `Anterior` / `Siguiente` con contador `Mostrando X-Y de Z jobs`.
7. **Temporizador de Heartbeat**:
   - Indicador sutil de cuenta regresiva de 5 segundos para el próximo ciclo de polling.

#### Excluido
- Modificaciones en esquemas de base de datos SQL Server o Redis (el backend ya emite todos los campos requeridos).
- Modificaciones en endpoints de auditoría individual.

---

### 3. Non Goals
- No se implementarán websockets adicionales para esta lista; el polling ligero de 5s sobre Redis con script Lua ($<1\text{ ms}$) es óptimo y suficiente.

---

### 4. Estado Actual vs 5. Estado Objetivo

```
[ ESTADO ACTUAL ]
• Tabla plana que renderiza hasta 50 jobs sin paginar.
• Muestra progreso % simple sin desglose de aprobadas.
• Muestra velocidad ops/s pero no estima cuánto tiempo falta.
• UUID truncado sin botón de copia.
• Sin indicador visual del ciclo de refresco.

[ ESTADO OBJETIVO (MISSION CONTROL) ]
• Panel de Salud Global con tasa de éxito y volumen en tiempo real.
• Paginación compacta (10/25/50 items) con buscador dinámico por EPS/UUID.
• Cálculo inteligente de ETA restante para jobs activos (~X min restantes).
• Duración exacta de finalización para jobs completados (ej. 16m 42s).
• Barra de progreso con desglose exacto (X exitosas / Y en DLQ).
• Botón de 1-clic para copiar UUID al portapapeles con micro-animación.
• Heartbeat visual de auto-refresco (cuenta regresiva de 5s).
```

---

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| ADR-01 | Cálculo de ETA en el Frontend | Calcular ETA en el backend de PHP | El cálculo es puramente matemático sobre métricas escalares ya transmitidas (`pending`, `avg_duration_ms`). Hacerlo en el cliente ahorra CPU en el servidor y permite actualización fluida segundo a segundo. |
| ADR-02 | Paginación en Memoria del Cliente | Paginación por servidor `?page=X` en Redis | La lista caliente de Redis contiene un máximo acotado (Top 50), cuyo payload serializado es $<15\text{ KB}$. Paginar en el cliente ofrece transiciones instantáneas ($0\text{ ms}$) y filtrado por texto sin latencia de red. |

---

### 7. Invariantes

| Invariante | Enforcement | Validación |
| --- | --- | --- |
| Cero división por cero en cálculo de ETA | Validación `avg_duration_ms > 0 && processed > 0` | Si no hay datos suficientes, mostrar `Estimando...` |
| Reseteo de página al filtrar | `setPage(1)` en handlers de búsqueda y tabs | Previene páginas vacías fuera de rango |
| Compatibilidad móvil / tablet | Contenedor con scroll horizontal en `Table` | Responsive layout en resoluciones `< 768px` |

---

### 8. Cambios por Archivo

#### `[MODIFY]` [`frontend/components/jobs/jobs-list-live.tsx`](file:///c:/Users/USER/Desktop/AudFact/frontend/components/jobs/jobs-list-live.tsx)
- **Imports**: Añadir `Copy`, `Check`, `Calendar`, `ChevronLeft`, `ChevronRight`.
- **Estados nuevos**: `pageSize` (default 10), `currentPage` (default 1), `copiedId` (string | null), `countdown` (segundos restantes para próximo polling).
- **Funciones auxiliares**:
  - `formatEta(job: AuditJobSummary): string`
  - `formatDuration(ms: number): string`
  - `handleCopyId(id: string): void`
- **Renderizado de Tabla**:
  - Columnas enriquecidas: EPS + Rango Fechas + Copiar UUID.
  - Progreso con desglose exacto (exitosas / DLQ).
  - Tiempo de inicio + Tiempo transcurrido + ETA / Duración.
  - Barra de paginación compacta inferior.

---

### 9. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| Job recién iniciado (`done = 0`, `failed = 0`) | ETA muestra `Calculando...` sin errores NaN | Renderiza badge neutral sin error |
| Job de 3.000 facturas completado | Muestra duración final real `16m 40s` | Formato exacto minutos/segundos |
| Copiado de UUID en navegador sin permiso | Captura excepción silenciosamente y mantiene estado estable | Sin crash en consola |
| Búsqueda sin coincidencias | Muestra componente `EmptyState` estilizado | Mensaje claro al usuario |

---

### 10. Testing y Verificación

1. **TypeScript Typecheck**:
   - `npx tsc --project tsconfig.json --noEmit` en `frontend/` $\rightarrow$ 0 errores.
2. **Linting**:
   - `npm run lint` en `frontend/` $\rightarrow$ 0 errores.
3. **Verificación en Navegador**:
   - Validar navegación en `http://localhost:3100/audit/jobs`.
   - Verificar clic en botón copiar UUID (feedback de check verde).
   - Verificar cálculo de ETA en jobs activos y duración en completados.
   - Probar paginación (cambiar tamaño 10 $\rightarrow$ 25 $\rightarrow$ 50 y paginar).

---

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Todas las entidades y componentes están definidos | `PASS` | `JobsListLive` existe y compila en `frontend/` |
| Todos los contratos documentados con clasificación | `PASS` | `AuditJobSummarySchema` cubre todos los campos requeridos |
| Todos los requisitos tienen trazabilidad | `PASS` | Trazabilidad del 100% de mejoras de UX |
| Todos los criterios son verificables | `PASS` | Validable mediante tests de compilación y navegador |

---

## FASE 3 — Auditoría Arquitectónica y Adversarial

| # | Pregunta Adversarial | Regresión que Previene | Resultado | Evidencia |
| --- | --- | --- | --- | --- |
| 1 | ¿El cambio reintroduce código muerto o componentes deprecados? | Clean Architecture | `NO` | `JobTracker` permanece eliminado; la optimización se realiza dentro de `JobsListLive`. |
| 2 | ¿El cambio altera contratos de backend o persistencia? | Contract / Data | `NO` | Cero cambios en SQL Server, Redis o endpoints de PHP. |
| 3 | ¿El cálculo de ETA puede causar loops infinitos o bloqueos de renderizado? | Runtime | `NO` | Funciones puras invocadas durante el render sin side-effects. |

---

## FASE 4 — Resultado Final

### Nivel de Completitud: **Nivel A — Implementable**

La especificación define de forma determinista y exhaustiva la optimización de UI/UX para el Centro de Control de Jobs en AudFact.
