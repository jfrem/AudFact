# Feature: Pipeline de Auditoría con Gemini Flash

## Descripción

Pipeline automatizado que audita facturas de dispensación farmacéutica comparando documentos escaneados (Actas de Entrega, Fórmulas Médicas, Validadores de Derechos, Autorizaciones) contra datos del sistema (Fuente de Verdad) mediante análisis multimodal de IA. Detecta fraude, discrepancias administrativas y faltantes documentales.

## Archivos Involucrados

| Archivo | Rol |
|---|---|
| `app/Controllers/AuditController.php` | Orquestador HTTP (recibe batch/single/async, valida, despacha) |
| `app/Services/Audit/AuditOrchestrator.php` | Orquestador principal — coordina todo el flujo de auditoría por factura |
| `app/Services/Audit/ExtractionPromptBuilder.php` | Prompt de extracción v4: campos, visual checks y hints sin lógica de negocio |
| `app/Services/Audit/AuditFileManager.php` | Resuelve archivos: BLOB → memoria (optimizado), URL → Drive download |
| `app/Services/Audit/ExtractionResponseSchema.php` | Function Calling schema para `report_extraction` |
| `app/Services/Audit/EmbeddingGateway.php` | Cliente HTTP para Gemini Embedding API |
| `app/Services/Audit/SemanticComparator.php` | Comparación semántica de campos extraídos vs Fuente de Verdad |
| `app/Services/Audit/FieldClassifier.php` | Clasificación de campos por tipo y documento autoritativo |
| `app/Services/Audit/RuleEngine.php` | Evaluación determinista de discrepancias, severidades y risk score |
| `app/Services/Audit/AuditResponseSchema.php` | Define el schema de respuesta final del pipeline |
| `app/Services/Audit/AuditPreValidator.php` | Pre-validación de datos y archivos antes de enviar a Gemini |
| `app/Services/Audit/AuditPersistenceService.php` | Persistencia de resultados: `AudDispEst` (upsert) + `AdjuntosDispensacion` (UPDATE baseline + rechazo individual) |
| `app/Services/Audit/AuditTelemetryService.php` | Métricas y telemetría del pipeline |
| `app/Services/Audit/AuditQueueService.php` | Orquesta colas de auditoría Redis (Jobs async) |
| `app/Services/Audit/AuditOrchestratorFactory.php` | Factory para construcción y reutilización de servicios |
| `bin/audit-worker.php` | Consumidor CLI de cola Redis para auditoría async |
| `app/Services/GoogleDriveAuthService.php` | Autenticación JWT + streaming desde Drive |
| `app/Models/DispensationModel.php` | Fuente de verdad (datos de dispensación desde `vw_discolnet_dispensas`) |
| `app/Models/AttachmentsModel.php` | Documentos adjuntos (BLOB + URL, JOIN por `DisId` + `DisDetId`, filtro `AdjDisOpc='N'` para requeridos) |
| `app/Models/AuditConfigModel.php` | Configuración dinámica por cliente (`NitSec`): documentos, campos y visual checks |
| `app/Models/AuditStatusModel.php` | Persistencia de resultados en `AudDispEst` (upsert) |

## Endpoints

| Método | Ruta | Controlador | Descripción |
|---|---|---|---|
| `POST` | `/audit` | `AuditController::run` | Auditoría batch síncrona (múltiples facturas) |
| `POST` | `/audit/single` | `AuditController::single` | Auditoría individual (una dispensación por `DisDetNro`) |
| `POST` | `/audit/async` | `AuditController::async` | Auditoría batch asíncrona (Redis Queue) → 202 |
| `GET` | `/audit/jobs/{jobId}` | `AuditController::jobStatus` | Estado y progreso de job asíncrono |
| `GET` | `/audit/results` | `AuditController::results` | Historial persistido de auditorías |
| `GET` | `/audit/documents-history` | `AuditController::documentsHistory` | Historial de documentos auditados por IA |

> [!IMPORTANT]
> Las rutas **NO** llevan prefijo `/api/`. El puerto del servidor de desarrollo es `8080`.

## Flujo de Operación

1. **Recepción**: `AuditController` recibe la solicitud (batch, single o async)
2. **Validación**: Se validan parámetros de entrada (`DisDetNro` para single, `facNitSec`/`date`/`limit` para batch)
3. **Procesamiento por factura**:
   - Obtiene datos de dispensación de `vw_discolnet_dispensas` (puede devolver múltiples filas si hay multi-línea)
# Feature: Pipeline de Auditoría con Gemini Flash

## Descripción

Pipeline automatizado que audita facturas de dispensación farmacéutica comparando documentos escaneados (Actas de Entrega, Fórmulas Médicas, Validadores de Derechos, Autorizaciones) contra datos del sistema (Fuente de Verdad) mediante análisis multimodal de IA. Detecta fraude, discrepancias administrativas y faltantes documentales.

## Archivos Involucrados

| Archivo | Rol |
|---|---|
| `app/Controllers/AuditController.php` | Orquestador HTTP (recibe batch/single/async, valida, despacha) |
| `app/Services/Audit/AuditOrchestrator.php` | Orquestador principal — coordina todo el flujo de auditoría por factura |
| `app/Services/Audit/ExtractionPromptBuilder.php` | Prompt de extracción v4: campos, visual checks y hints sin lógica de negocio |
| `app/Services/Audit/AuditFileManager.php` | Resuelve archivos: BLOB → memoria (optimizado), URL → Drive download |
| `app/Services/Audit/ExtractionResponseSchema.php` | Function Calling schema para `report_extraction` |
| `app/Services/Audit/EmbeddingGateway.php` | Cliente HTTP para Gemini Embedding API |
| `app/Services/Audit/SemanticComparator.php` | Comparación semántica de campos extraídos vs Fuente de Verdad |
| `app/Services/Audit/FieldClassifier.php` | Clasificación de campos por tipo y documento autoritativo |
| `app/Services/Audit/RuleEngine.php` | Evaluación determinista de discrepancias, severidades y risk score |
| `app/Services/Audit/AuditResponseSchema.php` | Define el schema de respuesta final del pipeline |
| `app/Services/Audit/AuditPreValidator.php` | Pre-validación de datos y archivos antes de enviar a Gemini |
| `app/Services/Audit/AuditPersistenceService.php` | Persistencia de resultados: `AudDispEst` (upsert) + `AdjuntosDispensacion` (UPDATE baseline + rechazo individual) |
| `app/Services/Audit/AuditTelemetryService.php` | Métricas y telemetría del pipeline |
| `app/Services/Audit/AuditQueueService.php` | Orquesta colas de auditoría Redis (Jobs async) |
| `app/Services/Audit/AuditOrchestratorFactory.php` | Factory para construcción y reutilización de servicios |
| `bin/audit-worker.php` | Consumidor CLI de cola Redis para auditoría async |
| `app/Services/GoogleDriveAuthService.php` | Autenticación JWT + streaming desde Drive |
| `app/Models/DispensationModel.php` | Fuente de verdad (datos de dispensación desde `vw_discolnet_dispensas`) |
| `app/Models/AttachmentsModel.php` | Documentos adjuntos (BLOB + URL, JOIN por `DisId` + `DisDetId`, filtro `AdjDisOpc='N'` para requeridos) |
| `app/Models/AuditStatusModel.php` | Persistencia de resultados en `AudDispEst` (upsert) |

## Endpoints

| Método | Ruta | Controlador | Descripción |
|---|---|---|---|
| `POST` | `/audit` | `AuditController::run` | Auditoría batch síncrona (múltiples facturas) |
| `POST` | `/audit/single` | `AuditController::single` | Auditoría individual (una dispensación por `DisDetNro`) |
| `POST` | `/audit/async` | `AuditController::async` | Auditoría batch asíncrona (Redis Queue) → 202 |
| `GET` | `/audit/jobs/{jobId}` | `AuditController::jobStatus` | Estado y progreso de job asíncrono |
| `GET` | `/audit/results` | `AuditController::results` | Historial persistido de auditorías |
| `GET` | `/audit/documents-history` | `AuditController::documentsHistory` | Historial de documentos auditados por IA |

> [!IMPORTANT]
> Las rutas **NO** llevan prefijo `/api/`. El puerto del servidor de desarrollo es `8080`.

## Flujo de Operación

1. **Recepción**: `AuditController` recibe la solicitud (batch, single o async)
2. **Validación**: Se validan parámetros de entrada (`DisDetNro` para single, `facNitSec`/`date`/`limit` para batch)
3. **Procesamiento por factura**:
   - Obtiene datos de dispensación de `vw_discolnet_dispensas` (puede devolver múltiples filas si hay multi-línea)
   - Pre-valida datos → `AuditPreValidator` (incluye consulta de adjuntos requeridos con prefiltrado SQL `AdjDisOpc='N'`)
   - Obtiene lista de adjuntos de `AdjuntosDispensacion` (JOIN `DisId` + `DisDetId`)
   - Resuelve archivos:
     - **BLOB**: Lectura directa de stream SQL a memoria → base64 (sin disco)
     - **URL**: Descarga de Google Drive vía JWT → memoria → base64
   - Detección MIME: Magic numbers (PDF, JPEG, PNG, WEBP) + finfo buffer + fallback por extensión
   - Carga configuración de auditoría por `NitSec` → `AuditConfigModel`
   - Construye prompt de extracción dinámico → `ExtractionPromptBuilder`
   - Envía a Gemini Flash API (`generateContent`) con Function Calling y documentos como `inlineData`
   - Parsea la tool call `report_extraction` → `ExtractionResponseSchema`
   - Compara campos semánticos → `SemanticComparator` + `EmbeddingGateway`
   - Evalúa reglas deterministas → `RuleEngine`
4. **Persistencia**: `AuditPersistenceService` persiste en tabla `AudDispEst` (upsert) + actualiza `AdjuntosDispensacion`
5. **Respuesta**: Retorna resultado con métricas de auditoría y tiempos de procesamiento

## Arquitectura del Pipeline v4

`ExtractionPromptBuilder` limita a Gemini a extraer campos y visual checks definidos por `audit-config`. La lógica de negocio vive en PHP: `SemanticComparator` calcula similitudes con embeddings solo para campos configurados y `RuleEngine` decide discrepancias, severidades y `risk_score`. Esto mantiene el resultado auditable y reduce el acoplamiento entre prompt y reglas.


### Fases del pipeline

| Fase | Componente | Responsabilidad |
|---|---|
| **1. Extracción** | `ExtractionPromptBuilder`, `ExtractionResponseSchema`, `GeminiGateway` | Extraer campos y visual checks con Function Calling |
| **2. Semántica** | `SemanticComparator`, `EmbeddingGateway`, `FieldClassifier` | Comparar campos semánticos con embeddings |
| **3. Reglas** | `RuleEngine` | Evaluar discrepancias, severidad y score de riesgo |

### Extracción de datos clave en PHP

- **Régimen del cliente**: Extraído del campo estructurado `$ref['RegimenPaciente']` (no regex). Comparación semántica con tabla de equivalencias
- **IPS**: Limpiada con `preg_replace` para eliminar prefijo de régimen
- **Multi-medicamento**: PHP itera sobre todos los ítems de `$dispensationData` con `foreach`, generando nodos `<medication item="N">` XML por cada medicamento de la dispensación. La info de entrega (firma acta, total líneas) se separa en `<delivery_info>`

## Pruebas de Auditoría con curl

> [!CAUTION]
> El shell afecta la sintaxis de escape. Usar la variante correcta según el entorno.

### Auditoría Individual (`/audit/single`)

**PowerShell** (recomendado en Windows):
```powershell
$body = '{"FacNro":"U88260100225"}'
curl.exe -s -X POST http://localhost:8080/audit/single -H "Content-Type: application/json" -d $body
```

**CMD** (Windows):
```cmd
curl.exe -X POST http://localhost:8080/audit/single -H "Content-Type: application/json" -d "{\"FacNro\":\"U88260100225\"}"
```

**Bash** (Linux/Mac/WSL):
```bash
curl -X POST http://localhost:8080/audit/single \
  -H "Content-Type: application/json" \
  -d '{"FacNro":"U88260100225"}'
```

**Parámetros del body**:

| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `DisDetNro` | string | ✅ | Identificador de dispensación (ej: `U88260100225`, `D02251213359`) |

### Obtener Fuente de Verdad antes de auditar

Para consultar los datos de la dispensación (útil para verificar qué campos se inyectarán al prompt):

```powershell
curl.exe -s http://localhost:8080/dispensation/U88260100225
```

### Auditoría Batch (`/audit`)

```powershell
$body = '{"facNitSec":1165,"date":"2025-12-30","limit":5}'
curl.exe -s -X POST http://localhost:8080/audit -H "Content-Type: application/json" -d $body
```

**Parámetros del body**:

| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `facNitSec` | integer | ✅ | NitSec del cliente (EPS) |
| `date` | string | ✅ | Fecha de dispensación (YYYY-MM-DD) |
| `limit` | integer | ✅ | Máximo de facturas a procesar (1–100) |

## Dependencias

- **Google Gemini API**: Motor de análisis multimodal
- **Google Drive API**: Descarga de documentos almacenados remotamente
- **SQL Server**: Datos de dispensación y adjuntos BLOB
- **Guzzle HTTP**: Cliente HTTP para APIs externas

## Configuración

| Variable | Descripción |
|---|---|
| `GEMINI_API_KEY` | API Key de Google Gemini |
| `GOOGLE_PROJECT_ID` | ID del proyecto Google Cloud |
| `GOOGLE_CLIENT_EMAIL` | Email de la cuenta de servicio |
| `GOOGLE_PRIVATE_KEY` | Clave privada de la cuenta de servicio |
| `AUDIT_BATCH_TIMEOUT` | Timeout máximo del batch en segundos (default: 3600) |
| `AUDIT_BATCH_MAX_LIMIT` | Máximo de facturas por batch (default: 100) |

## Notas Técnicas

- **Rate Limiting**: Gemini impone límites de quota (HTTP 429). El sistema implementa reintentos con backoff exponencial.
- **Function Calling inválido**: si Gemini no invoca `report_extraction`, el pipeline registra el fallo y retorna error controlado.
- **Modelo no disponible**: HTTP 503 de Gemini causa reintento automático.
- **Dual Storage Optimizado**: El flujo BLOB ya no escribe archivos temporales en `/tmp`, procesando directamente en memoria para reducir I/O.
- **Dual Storage**: El sistema maneja transparentemente documentos almacenados como BLOB en BD o como URLs en Google Drive.
- **Persistencia en Error**: El método `terminate()` propaga `$dispensation` a `saveToDatabase()` para que el `FacSec` real se persista correctamente incluso en flujos de error.
- **Validación MIPRES**: `AuditPreValidator` valida campos obligatorios MIPRES (`Mipres`, `IdPrincipal`, `IdDirec`, `IdProg`, `IdEntr`, `IdRepEnt`) antes de enviar a Gemini. `IdFact` fue excluido de la lista obligatoria.
- **Schema Dinámico de Documentos**: `ExtractionResponseSchema::getToolsBlock()` recibe los tipos documentales normalizados para restringir la extracción a documentos esperados.
- **Filtrado de Adjuntos**: Solo se procesan documentos con `AdjDisOpc='N'` (requeridos). Documentos opcionales se excluyen intencionalmente por lógica de negocio.
- **Multi-línea**: `RuleEngine` evalúa campos por ítem cuando aplica, usando la Fuente de Verdad completa de `$dispensationData`.
- **Entregas Parciales**: `RuleEngine` permite que la Fuente de Verdad registre cantidades menores o iguales a las prescritas/autorizadas, clasificándolas como `COINCIDE` para evitar falsos positivos en dispensaciones fragmentadas.
