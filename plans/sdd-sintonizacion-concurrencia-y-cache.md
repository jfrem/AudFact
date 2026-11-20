# SDD-SCALE-001 — Sintonización de Concurrencia de Workers y Capacidad de Caché Documental

- **Identificador**: `SDD-SCALE-001`
- **Fecha**: 2026-11-20
- **Estado**: APROBADO / EN IMPLEMENTACIÓN
- **Prioridad**: ALTA (Optimización directa de throughput y eliminación de cuellos de botella en lotes masivos)
- **Autor / Origen**: Solicitud del Usuario tras Diagnóstico Forense RCA del Lote `685ebaf6`
- **Nivel de Rigor**: Nivel A (Implementable, Determinista y Verificable)

---

## 1. Contexto y Justificación

### 1.1 Hallazgos Forenses en Producción
Durante el procesamiento de 2,100 facturas de NUEVA EPS en producción LAN (`172.16.0.3`):
1. **Embudo en Extracción**: Los 16 workers de descarga saturaron la cola `audit.documents.batch` con un lag de más de 7,500 eventos porque los 24 workers de extracción tardaban ~7.5s por documento, provocando un tiempo medio de espera en cola de 10.2 minutos por factura.
2. **Embudo en Semántica**: Solo existían 6 réplicas de `worker-semantic-batch`, provocando un tiempo de espera en cola semántica de 8.6 minutos por factura.
3. **Pausas por Saturación de Caché**: La caché documental de Redis estaba limitada a 1.5 GB (`1610612736` bytes), lo que provocó 5,565 pausas del prefetch gate (`prefetch_wait_ms > 1,600,000s`) al alcanzar el 91% de capacidad.
4. **Capacidad del Servidor**: El servidor host de producción cuenta con 15.55 GB de RAM total y actualmente solo consume ~2.5 GB en reposo, disponiendo de más de 12 GB de holgura.

---

## 2. Decisiones de Arquitectura y Configuración

| Parámetro | Valor Anterior | Valor Nuevo | Justificación Técnica |
| :--- | :---: | :---: | :--- |
| `AUDIT_WORKER_EXTRACTION_BATCH_REPLICAS` | `24` | `36` | Aumenta la capacidad de procesamiento paralelo de visión de Gemini en un 50%, reduciendo drásticamente el lag en `audit.documents.batch`. |
| `AUDIT_WORKER_SEMANTIC_BATCH_REPLICAS` | `6` | `12` | Duplica la capacidad de evaluación semántica paralela, eliminando los 8.6 minutos de espera pasiva en `audit.semantic.batch`. |
| `AUDIT_DOCUMENT_CACHE_MAX_BYTES` | `1610612736` (1.5 GB) | `3221225472` (3.0 GB) | Duplica el techo de memoria de la caché en Redis. Con 3 GB, la compuerta de histéresis no se cierra durante la ingesta de lotes de hasta 3,000 facturas. |
| `app/Services/Audit/Pipeline/AuditDocumentCache.php` | Default `1610612736` | Default `3221225472` | Mantiene la coherencia del valor por defecto en el código si la variable de entorno no está explícitamente inyectada. |

---

## 3. Perímetro de Impacto

1. `docker-compose.yml`:
   - `worker-extraction-batch.deploy.replicas`: de `${AUDIT_WORKER_EXTRACTION_BATCH_REPLICAS:-24}` a `${AUDIT_WORKER_EXTRACTION_BATCH_REPLICAS:-36}`.
   - `worker-semantic-batch.deploy.replicas`: de `${AUDIT_WORKER_SEMANTIC_BATCH_REPLICAS:-6}` a `${AUDIT_WORKER_SEMANTIC_BATCH_REPLICAS:-12}`.
2. `app/Services/Audit/Pipeline/AuditDocumentCache.php`:
   - `AUDIT_DOCUMENT_CACHE_MAX_BYTES`: actualizar el default de `1610612736` a `3221225472`.
3. `.env` y `.env.example`:
   - `AUDIT_DOCUMENT_CACHE_MAX_BYTES=3221225472`.
   - `AUDIT_WORKER_EXTRACTION_BATCH_REPLICAS=36`.
   - `AUDIT_WORKER_SEMANTIC_BATCH_REPLICAS=12`.
4. Tests:
   - `tests/Unit/Services/Audit/Pipeline/AuditDocumentCacheTest.php`: validar que el default y los límites correspondan a 3 GB cuando no se especifique override.

---

## 4. Criterios de Aceptación Verificables

- [ ] **CA-1**: `docker compose config` resuelve 36 réplicas para `worker-extraction-batch` y 12 réplicas para `worker-semantic-batch`.
- [ ] **CA-2**: `AuditDocumentCache::maxBytes` reporta `3221225472` por defecto cuando no hay variable de entorno.
- [ ] **CA-3**: El contrato de variables en `.env.example` incluye las claves con los nuevos valores por defecto.
- [ ] **CA-4**: La suite unitaria PHPUnit ejecuta al 100% en verde con 0 regresiones.
