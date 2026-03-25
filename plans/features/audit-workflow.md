# Feature: Pipeline de Auditoría con Gemini Flash

## Descripción

Pipeline automatizado que audita facturas de dispensación farmacéutica comparando documentos escaneados (Actas de Entrega, Fórmulas Médicas, Validadores de Derechos, Autorizaciones) contra datos del sistema (Fuente de Verdad) mediante análisis multimodal de IA. Detecta fraude, discrepancias administrativas y faltantes documentales.

## Archivos Involucrados

| Archivo | Rol |
|---|---|
| `app/Controllers/AuditController.php` | Orquestador HTTP (recibe batch/single/async, valida, despacha) |
| `app/Services/Audit/AuditOrchestrator.php` | Orquestador principal — coordina todo el flujo de auditoría por factura |
| `app/Services/Audit/AuditPromptBuilder.php` | Ingeniería de prompts: System Instruction v3.1 (§01–§09) con contexto dinámico e iteración multi-medicamento |
| `app/Services/Audit/AuditFileManager.php` | Resuelve archivos: BLOB → memoria (optimizado), URL → Drive download |
| `app/Services/Audit/AuditResponseSchema.php` | Define el JSON schema dinámico esperado de Gemini |
| `app/Services/Audit/AuditResultValidator.php` | Valida respuesta contra schema |
| `app/Services/Audit/JsonRepairHelper.php` | Repara JSON truncado o malformado |
| `app/Services/Audit/JsonResponseParser.php` | Parseo robusto de respuestas Gemini (con sanitización XSS) |
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
# Feature: Pipeline de Auditoría con Gemini Flash

## Descripción

Pipeline automatizado que audita facturas de dispensación farmacéutica comparando documentos escaneados (Actas de Entrega, Fórmulas Médicas, Validadores de Derechos, Autorizaciones) contra datos del sistema (Fuente de Verdad) mediante análisis multimodal de IA. Detecta fraude, discrepancias administrativas y faltantes documentales.

## Archivos Involucrados

| Archivo | Rol |
|---|---|
| `app/Controllers/AuditController.php` | Orquestador HTTP (recibe batch/single/async, valida, despacha) |
| `app/Services/Audit/AuditOrchestrator.php` | Orquestador principal — coordina todo el flujo de auditoría por factura |
| `app/Services/Audit/AuditPromptBuilder.php` | Ingeniería de prompts: System Instruction v3.1 (§01–§09) con contexto dinámico e iteración multi-medicamento |
| `app/Services/Audit/AuditFileManager.php` | Resuelve archivos: BLOB → memoria (optimizado), URL → Drive download |
| `app/Services/Audit/AuditResponseSchema.php` | Define el JSON schema dinámico esperado de Gemini |
| `app/Services/Audit/AuditResultValidator.php` | Valida respuesta contra schema |
| `app/Services/Audit/JsonRepairHelper.php` | Repara JSON truncado o malformado |
| `app/Services/Audit/JsonResponseParser.php` | Parseo robusto de respuestas Gemini (con sanitización XSS) |
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
   - 4. Construir prompt → `AuditPromptBuilder` (v3.2 con Axiomas A1-A4 + iteración multi-medicamento + entregas parciales).
   s los ítems de dispensación generando nodos `<medication item="N">` XML exhaustivos
   - Envía a Gemini Flash API (`generateContent`) con documentos como inline_data
   - Parsea respuesta JSON (con reparación si está truncado)
   - Valida resultado contra schema esperado
4. **Persistencia**: `AuditPersistenceService` persiste en tabla `AudDispEst` (upsert) + actualiza `AdjuntosDispensacion`
5. **Respuesta**: Retorna resultado con métricas de auditoría y tiempos de procesamiento

## Arquitectura del Prompt (v3.2)

`AuditPromptBuilder` utiliza un diseño determinista basado en inyección de contexto dinámico. Los datos de la dispensación se inyectan **una sola vez** en el System Prompt (no se duplican en el User Prompt), reduciendo tokens y latencia. Para dispensaciones multi-medicamento, se genera un nodo `<medication item="N">` por cada ítem con datos completos (nombre, CUM, lote, laboratorio, vencimiento, cantidades). Incluye soporte para **entregas parciales**.


### Secciones del System Instruction

| Sección | Contenido |
|---|---|
| **Rol** | Define el motor de validación y el workflow: Lee → Calibra → Compara → Auto-audita → Entrega |
| **Fuente de Verdad** | ~24 campos dinámicos inyectados por PHP (paciente, médico, facturación, fechas, medicamento) |
| **§01** | Documentos válidos: ACTA, AUTORIZACION, FORMULA, VALIDADOR. Exclusión de judiciales |
| **§02** | Mapa autoritativo por campo (quién manda sobre qué) con fallback a alternativo |
| **§03** | Reglas de comparación: exacta, tokens críticos, equivalencia de cero, semántica de régimen, IPS parcial, días de tratamiento |
| **§04** | Severidades fijas por campo (alta/media/baja) |
| **§05** | Reglas de negocio: cantidades (parcial OK, sobreentrega = fraude), orden de fechas, MIPRES, multi-línea, firma obligatoria |
| **§06** | Clasificación: COINCIDE / VALOR_DISTINTO / NO_ENCONTRADO / ILEGIBLE |
| **§07** | Cálculo de risk_score con pesos configurables |
| **§08** | Auto-auditoría: checklist de 12 puntos pre-entrega |
| **§09** | Formato JSON de salida (items vacío en success, solo discrepancias en warning/error) |

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
- **JSON Truncado**: Gemini puede truncar respuestas largas. `JsonRepairHelper` intenta cerrar estructuras JSON abiertas.
- **Modelo no disponible**: HTTP 503 de Gemini causa reintento automático.
- **Dual Storage Optimizado**: El flujo BLOB ya no escribe archivos temporales en `/tmp`, procesando directamente en memoria para reducir I/O.
- **Dual Storage**: El sistema maneja transparentemente documentos almacenados como BLOB en BD o como URLs en Google Drive.
- **Persistencia en Error**: El método `terminate()` propaga `$dispensation` a `saveToDatabase()` para que el `FacSec` real se persista correctamente incluso en flujos de error.
- **Validación MIPRES**: `AuditPreValidator` valida campos obligatorios MIPRES (`Mipres`, `IdPrincipal`, `IdDirec`, `IdProg`, `IdEntr`, `IdRepEnt`) antes de enviar a Gemini. `IdFact` fue excluido de la lista obligatoria.
- **Schema Dinámico de Documentos**: `AuditResponseSchema::getGeminiSchema()` acepta pasarse dinámicamente los nombres reales de la BD, limitando las respuestas en el campo `documento` a una variante de un enum específico exacto extraído de `AdjuntosDispensacion`, mejorando radicalmente la conciliación en `AuditStatusModel`.
- **Filtrado de Adjuntos**: Solo se procesan documentos con `AdjDisOpc='N'` (requeridos). Documentos opcionales se excluyen intencionalmente por lógica de negocio.
- **Iteración Multi-Medicamento**: `AuditPromptBuilder` itera sobre todos los ítems de `$dispensationData` generando nodos `<medication item="N">` XML individuales, asegurando que la IA valide todos los medicamentos de una dispensación multi-línea.
- **Entregas Parciales (v3.2)**: El sistema permite que la Fuente de Verdad registre cantidades menores o iguales a las prescritas/autorizadas, clasificándolas como `COINCIDE` para evitar falsos positivos en dispensaciones fragmentadas.
