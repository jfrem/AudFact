# Feature: Pipeline de Auditoría con Gemini Flash

## Descripción

Pipeline automatizado que audita facturas de dispensación farmacéutica comparando documentos escaneados (Actas de Entrega) contra datos del sistema mediante análisis multimodal de IA. Detecta fraude, discrepancias administrativas y faltantes documentales.

## Archivos Involucrados

| Archivo | Rol |
|---|---|
| `app/Controllers/AuditController.php` | Orquestador HTTP (recibe batch, valida, despacha) |
| `app/worker/GeminiAuditService.php` | Worker principal (coordina todo el pipeline) |
| `app/Services/Audit/AuditFileManager.php` | Resuelve archivos: BLOB → memoria (optimizado), URL → Drive download |
| `app/Services/Audit/AuditPromptBuilder.php` | Ingeniería de prompts: Philosophy + System Instruction v3.0 (4 capas con axiomas) |
| `app/Services/Audit/AuditResponseSchema.php` | Define el JSON schema esperado de Gemini |
| `app/Services/Audit/AuditResultValidator.php` | Valida respuesta contra schema |
| `app/Services/Audit/JsonRepairHelper.php` | Repara JSON truncado o malformado |
| `app/Services/Audit/JsonResponseParser.php` | Parseo robusto de respuestas Gemini |
| `app/Services/GoogleDriveAuthService.php` | Autenticación JWT + streaming desde Drive |
| `app/Models/DispensationModel.php` | Fuente de verdad (datos de dispensación) |
| `app/Models/AttachmentsModel.php` | Documentos adjuntos (BLOB + URL) |

## Flujo de Operación

1. **Recepción**: `AuditController` recibe `POST /api/audit/batch` con `clientId`, `date`, y lista de facturas
2. **Validación**: Se validan parámetros de entrada
3. **Iteración por factura**:
   - Obtiene datos de dispensación de `vw_discolnet_dispensas`
   - Obtiene lista de adjuntos de `AdjuntosDispensacion`
    - Resuelve archivos:
      - **BLOB**: Lectura directa de stream SQL a memoria → base64 (sin disco).
      - **URL**: Descarga de Google Drive vía JWT → memoria → base64.
    - Detección MIME: Magic numbers (PDF, JPEG, PNG, WEBP) + finfo buffer + fallback por extensión.
   - Construye prompt con instrucciones + schema + datos + archivos
   - Envía a Gemini Flash API (`generateContent`)
   - Parsea respuesta JSON (con reparación si está truncado)
   - Valida resultado contra schema esperado
4. **Respuesta**: Retorna resultados agregados con resumen

## Dependencias

- **Google Gemini Flash API**: Motor de análisis multimodal
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

## Notas Técnicas

- **Rate Limiting**: Gemini impone límites de quota (HTTP 429). El sistema implementa reintentos con backoff exponencial.
- **JSON Truncado**: Gemini puede truncar respuestas largas. `JsonRepairHelper` intenta cerrar estructuras JSON abiertas.
- **Modelo no disponible**: HTTP 503 de Gemini causa reintento automático.
- **Arquitectura del Prompt (v3.0)**: `AuditPromptBuilder` utiliza un diseño de 4 capas altamente determinista:
  - **Capa 1: Identity & Role**: Define al auditor como una entidad objetiva y técnica.
  - **Capa 2: Axioms (A1-A4)**: Principios de razonamiento (Primacy of Data, Exhaustive Observation, Inference without Assumption, Derivable Severity).
  - **Capa 3: Reasoning Engine (6 Dimensions)**: Protocolo mandatorio de evaluación: Identidad, Cuantitativa, Temporal, Descriptiva, Integridad Documental, Análisis Forense Visual.
  - **Capa 4: Output Format**: Estricto JSON con validación de severidad y campos técnicos.
- **Cognitive Accelerator**: Se integra `$philosophy` como un bloque de aceleración cognitiva para reducir latencia manteniendo precisión.
- **Dual Storage Optimizado**: El flujo BLOB ya no escribe archivos temporales en `/tmp` (excepto en fallos o URL), procesando directamente en memoria para reducir I/O.
- **Dual Storage**: El sistema maneja transparentemente documentos almacenados como BLOB en BD o como URLs en Google Drive.
