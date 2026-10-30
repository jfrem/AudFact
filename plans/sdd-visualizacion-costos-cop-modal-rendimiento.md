# SDD — Visualización de Costos en Pesos Colombianos (COP) en el Modal de Rendimiento

> **Fecha:** 2026-10-30  
> **Estado:** Aprobado para Implementación  
> **Prioridad:** P2 (Experiencia de Usuario / Visibilidad Financiera)  
> **Componentes Afectados:** `frontend/lib/schemas/domain.ts`, `frontend/lib/api/endpoints.ts`, `frontend/lib/api/audfact.ts`, `frontend/lib/formatters/index.ts`, `frontend/components/results/audit-result-detail-modal.tsx`, `frontend/components/audit/audit-timings-panel.tsx`.

---

## 1. Contexto y Justificación

El backend de AudFact expone el endpoint `GET /audit/costs/{facNro}` (`AuditCostController::byInvoice`), el cual persiste y calcula en tiempo real el costo en USD y en Pesos Colombianos (COP) de las auditorías IA aplicando la TRM oficial del día.

Sin embargo, en el modal de detalle de auditoría (`AuditResultDetailModal`), la pestaña **Rendimiento** (`AuditTimingsPanel`) actualmente solo visualiza latencias de fase, recuentos de tokens de Gemini y tasas de acierto en caché, omitiendo el costo financiero en COP generado por los intentos del modelo.

Esta especificación define la integración desacoplada para consultar, formatear y desplegar el costo en COP dentro del panel de rendimiento con trazabilidad de TRM y consumo en USD.

---

## 2. Contrato de Datos

### 2.1 Endpoint Backend Existente

- **Método:** `GET`
- **Ruta:** `/audit/costs/{facNro}`
- **Controlador:** `App\Controllers\AuditCostController::byInvoice`
- **Payload de Respuesta:**
  ```json
  {
    "success": true,
    "message": "Operación exitosa",
    "data": {
      "fac_nro": "V06261002254",
      "total_intentos": 1,
      "gran_total_tokens": 6306,
      "costo_total_acumulado_usd": 0.0071475,
      "costo_total_acumulado_cop": 22.6355,
      "intentos": [
        {
          "aud_costo_id": 242086,
          "fac_nro": "V06261002254",
          "fac_nit_sec": "2624",
          "cliente": "NUEVA EPS SA",
          "fac_sec": "DIS26-10-30-10-7-4-4459483AA9E995A7E3B6D1187445F2D6268",
          "intento": 1,
          "tokens_entrada": 5500,
          "tokens_salida": 806,
          "tokens_pensamiento": 0,
          "total_tokens": 6306,
          "costo_usd": 0.0071475,
          "trm_aplicada": 3166.91,
          "costo_cop": 22.6355,
          "docs_procesados": 3,
          "docs_cache_hit": 0,
          "es_cache_hit_total": false,
          "modelo_ia": "gemini-3.8-flash",
          "fecha_auditoria": "2026-10-30 15:11:27"
        }
      ]
    }
  }
  ```

### 2.2 Esquema Zod en Frontend (`frontend/lib/schemas/domain.ts`)

```typescript
export const AuditCostAttemptSchema = z.object({
  aud_costo_id: z.number().int().optional(),
  fac_nro: z.string(),
  fac_nit_sec: z.string().nullish(),
  cliente: z.string().nullish(),
  fac_sec: z.string().nullish(),
  intento: z.number().int(),
  tokens_entrada: z.number().int(),
  tokens_salida: z.number().int(),
  tokens_pensamiento: z.number().int().nullish().default(0),
  total_tokens: z.number().int(),
  costo_usd: z.number(),
  trm_aplicada: z.number(),
  costo_cop: z.number(),
  docs_procesados: z.number().int().optional(),
  docs_cache_hit: z.number().int().optional(),
  es_cache_hit_total: z.boolean().optional(),
  modelo_ia: z.string().nullish(),
  fecha_auditoria: z.string().nullish(),
});

export const AuditCostInvoiceSchema = z.object({
  fac_nro: z.string(),
  total_intentos: z.number().int(),
  gran_total_tokens: z.number().int(),
  costo_total_acumulado_usd: z.number(),
  costo_total_acumulado_cop: z.number(),
  intentos: z.array(AuditCostAttemptSchema).default([]),
});

export type AuditCostInvoice = z.infer<typeof AuditCostInvoiceSchema>;
```

---

## 3. Arquitectura de Componentes e Interfaz de Usuario

```
[AuditResultDetailModal]
      │
      ├─► useQuery(["audit-cost", facNro]) ──► getAuditCostByInvoice(facNro)
      │
      └─► <AuditTimingsPanel timings={detail.timings} cost={costQ.data} />
                │
                ├─► Barra Forense Superior (4 Métricas Simétricas - "El Escritorio del Forense"):
                │     ├─► [1] Ejecución (Activo + cola integrado en detalle y tooltip)
                │     ├─► [2] Costo IA (Formato COP primario + USD secundario + tooltip TRM)
                │     ├─► [3] Tokens Gemini (Total consumido + STOP reasons)
                │     └─► [4] Caché (Tasa global % + hits semánticos)
                │
                ├─► Tabla 1: "Fases del pipeline" (con tag "Mayor tiempo" en fila dominante)
                │
                └─► Tabla 2: "Consumo Gemini" + Pie de Liquidación Técnica (TRM y Totales)
```

### 3.1 Formateador de Moneda COP (`frontend/lib/formatters/index.ts`)

- Función: `formatCurrencyCop(value?: number | null): string`
- Comportamiento:
  - Si es null/undefined: retorna `"N/D"`.
  - Si es 0: retorna `"$0,00 COP"`.
  - Si es menor a $10 COP: formatea con 4 decimales para micropagos precisos (ej. `"$0,0071 COP"`).
  - Si es >= $10 COP: formatea con 2 decimales estándar (ej. `"$22,64 COP"`).

### 3.2 Refinamiento Forense Impeccable (Erradicación de Redundancias)

- **Erradicación de duplicidad**: Suprimida la métrica doble de `Costo IA`.
- **Descarte de métricas redundantes**:
  - `Documentos`: Eliminada de la barra superior ya que se expone en la cabecera modal (`Docs: X`) y en cada fila de fase.
  - `Fase dominante`: Removida como métrica independiente y convertida en un tag contextual `Mayor tiempo` dentro de la tabla de fases.
  - `Total transcurrido`: Unificada con `Procesamiento activo` bajo `Ejecución`, reflejando la espera en cola de forma proporcional.
- **Doctrina No-Nesting**: El pie de tabla de Gemini no anida tarjetas pesadas; se despliega como franja tipográfica ligera de auditoría.

---

## 4. Plan de Pruebas y Validación

1. **Compilación TypeScript:** Ejecutar `npx tsc --noEmit` en `frontend/` verificando 0 errores de tipos.
2. **Pruebas Unitarias de Formateadores:** Validar `formatCurrencyCop` con valores nulos, cero, enteros y decimales.
3. **Pruebas de Regresión:** Certificar `vendor/bin/phpunit` para asegurar que el backend permanezca al 100% en verde.
