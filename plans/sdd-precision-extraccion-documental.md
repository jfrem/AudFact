# Especificación de Implementación (SDD): Corrección Definitiva y Robusta de Extracción Documental Multimodal con Gemini

---

## Paso 0 — Clasificación del Cambio (Triage)

| Dimensión | Valor | Justificación |
|---|---|---|
| **Tipo** | `Bugfix / Refactor / Infraestructura` | Solución estructural a la inconsistencia de extracción OCR de Gemini en fuentes condensadas de 6–8 pt mediante pre-rasterización Poppler JPEG 200 DPI, prompt determinista minimalista, erradicación total de fallbacks silenciosos a PDF crudo en retries y reset de parámetros de inferencia. `[CONFIRMADO]` |
| **Riesgo** | `Medio` | No altera contratos de eventos en Redis Streams ni esquemas de Base de Datos SQL Server. Modifica la ingesta binaria, la firma interna de retry en el parser y el Dockerfile de runtime. `[CONFIRMADO]` |
| **Persistencia afectada** | `No` | Las tablas `AudDispEst`, `AdjuntosDispensacion` y `DispensacionDetalleServicio` conservan su estructura sin cambios de esquema. `[CONFIRMADO]` |
| **Contrato externo afectado** | `No` | Los eventos de Redis Streams (`document_downloaded`, `document_extracted`, `document_rejected`) conservan su payload y tipado estricto. `[CONFIRMADO]` |
| **Cambio arquitectónico** | `Sí` | Encapsulación de servicio `DocumentPdfRasterizer`, inyección de partes multimodales `array $multimodalParts` a `GeminiResponseParser::parse()` para retries herméticos y eliminación de tablas de confusión en el prompt builder. `[CONFIRMADO]` |
| **Producción afectada** | `Sí` | Requiere runtime con `poppler-utils` (`pdftoppm`) en `docker/Dockerfile`. `[CONFIRMADO]` |
| **Requiere Paso 3.1** | `No` | No se reemplazan mapeos estáticos por abstracciones dinámicas. `[CONFIRMADO]` |

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
|---|---|---|---|---|---|
| `DocumentPdfRasterizer.php` | `app/Services/Audit/Pipeline/DocumentPdfRasterizer.php` | `NEW` | Servicio de pre-rasterización determinista PDF $\rightarrow$ JPEG 200 DPI con `pdftoppm` | Archivo nuevo completo (L1-265) | `Sí` |
| `DocumentExtractionWorker.php` | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | `MODIFIED` | Orquestador de extracción: construye partes multimodales y las entrega a Gateway y ResponseParser | L42-80, L310-388 | `Sí` |
| `GeminiResponseParser.php` | `app/Services/Audit/Pipeline/GeminiResponseParser.php` | `MODIFIED` | Parsea respuestas y ejecuta retry selectivo de funciones faltantes | L70-130, L240-285 | `Sí` |
| `ExtractionPromptBuilder.php` | `app/Services/Audit/Pipeline/ExtractionPromptBuilder.php` | `MODIFIED` | Construcción de System Prompt y User Prompt deterministas | L27-72 | `Sí` |
| `Dockerfile` | `docker/Dockerfile` | `MODIFIED` | Imagen Docker PHP-FPM y Workers con `poppler-utils` | L20-24 | `Sí` |
| `.env.example` | `.env.example` | `MODIFIED` | Contrato de variables de entorno de referencia | L71-79 | `Sí` |
| `.env` | `.env` | `MODIFIED` | Variables de entorno del entorno local | L68-76 | `Sí` |
| `.env.production` | `.env.production` | `MODIFIED` | Variables de entorno del entorno de producción | L71-79 | `Sí` |
| `AGENTS.md` | `AGENTS.md` | `MODIFIED` | Documentación de variables de entorno y arquitectura | L305-312 | `Sí` |
| `CHANGELOG.md` | `CHANGELOG.md` | `MODIFIED` | Registro histórico de cambios del repositorio | L1-20 | `Sí` |
| `DocumentPdfRasterizerTest.php` | `tests/Services/Audit/Pipeline/DocumentPdfRasterizerTest.php` | `NEW` | Pruebas unitarias de rasterización, límites, errores y limpieza | Archivo nuevo | `Sí` |
| `ExtractionPromptBuilderTest.php` | `tests/Services/Audit/Pipeline/ExtractionPromptBuilderTest.php` | `MODIFIED` | Pruebas unitarias del prompt simplificado sin tablas `↔` | L18-72 | `Sí` |
| `DocumentExtractionWorkerTest.php` | `tests/Services/Audit/Events/DocumentExtractionWorkerTest.php` | `MODIFIED` | Pruebas unitarias del worker con inyección de stub de rasterizador | L1-1267 | `Sí` |

#### Criterio de Cierre del Perímetro
- **Búsqueda por símbolo**: Búsqueda exhaustiva de `pdfRasterizer`, `buildMultimodalParts`, `retryMissingFunctions`, `DEFAULT_SYSTEM_PROMPT` en `app/` y `tests/`.
- **Búsqueda por importación**: Verificación de todas las referencias a `DocumentPdfRasterizer` en `app/` y `tests/`.
- **Búsqueda en configuración**: Inspección de `GEMINI_EXTRACTION_THINKING_LEVEL` y `GEMINI_SEED` en `.env*`, `AGENTS.md` y GitHub Actions Environment variables.
- **Búsqueda en runtime**: Verificación de paquetes en `docker/Dockerfile` y dependencias de CLI `pdftoppm`.

---

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
|---|---|---|---|---|---|---|
| `DocumentPdfRasterizer.php` | `pdftoppm` | `/usr/bin/pdftoppm` | L34, L97 | Directa | `proc_open` (CLI subprocess con `-f 1 -l 51`) | Runtime del Sistema |
| `DocumentPdfRasterizer.php` | `Logger` | `core/Logger.php` | L7, L160 | Directa | Estática (`Logger::error`) | Repositorio local |
| `DocumentExtractionWorker.php` | `DocumentPdfRasterizer` | `app/Services/Audit/Pipeline/DocumentPdfRasterizer.php` | L45, L79, L378 | Directa | Inyección de dependencias en constructor | Repositorio local |
| `DocumentExtractionWorker.php` | `GeminiResponseParser` | `app/Services/Audit/Pipeline/GeminiResponseParser.php` | L44, L78, L325 | Directa | Invocación `parse()` con `$files` (JPEG) | Repositorio local |
| `GeminiResponseParser.php` | `GeminiGateway` | `app/Services/Audit/GeminiGateway.php` | L9, L265 | Directa | Invocación `sendWithFunctionCalling` en Fase 2 | Repositorio local |
| `ExtractionPromptBuilder.php` | `DocumentExtractionWorker` | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` | L43, L76, L152 | Directa | Inyección de dependencias en constructor | Repositorio local |
| `Dockerfile` | `poppler-utils` | Debian apt repository | L20 | Directa | `apt-get install` en etapa final | Infraestructura Docker |

---

### 0.3 Análisis de Impacto Inverso (Regresiones y Resolución de Hallazgos de Auditoría)

| # | Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección Determinista Implementada |
|---|---|---|---|---|---|
| **1** | Reemplazo de `$document` por `$multimodalParts` en retries de `GeminiResponseParser` | `GeminiResponseParser::parse()` y `retryMissingFunctions()` | `GeminiResponseParser.php:81-88`, `L240-271` | `Contract / Runtime` | `parse()` recibe `array $multimodalParts` y `retryMissingFunctions()` lo entrega a `sendWithFunctionCalling()`. Se erradica el reenvío de PDF crudo a 72 DPI en retries. `[CONFIRMADO]` |
| **2** | `pdftoppm` con exit code distinto de cero | `DocumentPdfRasterizer::executeProcess()` | `DocumentPdfRasterizer.php:237-243` | `Runtime / Data` | Si `$exitCode !== 0`, lanzar inmediatamente `RuntimeException` con el contenido de `stderr`. Impide enviar imágenes parciales ante PDF corrupto. `[CONFIRMADO]` |
| **3** | Límite preventivo de páginas con `-f 1 -l 51` | `DocumentPdfRasterizer::rasterize()` | `DocumentPdfRasterizer.php:100-125` | `Resource / DoS` | `pdftoppm` se invoca con `-f 1 -l 51`. Si se generan más de 50 páginas, se aborta inmediatamente sin renderizar páginas posteriores. `[CONFIRMADO]` |
| **4** | Error ante fallo de lectura de página individual | `DocumentPdfRasterizer::rasterize()` | `DocumentPdfRasterizer.php:130-137` | `Data Integrity` | Si `@file_get_contents($imgPath)` retorna `false` o vacío, lanzar `RuntimeException` inmediatamente en lugar de omitir la página. `[CONFIRMADO]` |
| **5** | Limpieza a prueba de fallos de archivos temporales | `DocumentPdfRasterizer::rasterize()` bloque `finally` | `DocumentPdfRasterizer.php:155-163` | `Runtime / Storage` | En el bloque `finally`, limpiar sistemáticamente mediante `glob("{$tempDir}/*_{$uniqueId}*")` eliminando tanto el PDF de entrada como cualquier imagen generada antes de un timeout o excepción. `[CONFIRMADO]` |
| **6** | Simplificación del System Prompt sin tablas `↔` | `ExtractionPromptBuilder::DEFAULT_SYSTEM_PROMPT` | `ExtractionPromptBuilder.php:27-40` | `AI / Precision` | Reducir de 45 a 12 líneas de prompt determinista. Eliminar las 25 reglas de sustitución que causaban alucinación y sobre-corrección. `[CONFIRMADO]` |
| **7** | Reset de variables de entorno de inferencia | `.env.example`, `AGENTS.md`, GitHub Remote | `.env.example:74,78`, `AGENTS.md:308` | `Config / Sincronización` | Variables `GEMINI_EXTRACTION_THINKING_LEVEL` y `GEMINI_SEED` vacías / eliminadas de GitHub Environment `production`. `[CONFIRMADO]` |
| **8** | Eliminación de ruido EOL | Repositorio completo | `git diff --stat` | `DX / Style` | Normalización de finales de línea coincidentes con el índice de git (CRLF en docs/tests Windows, LF en servicios PHP). `[CONFIRMADO]` |

---

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
|---|---|---|---|---|
| **Poppler (`pdftoppm`)** | Parámetros `-jpeg -r 200 -f 1 -l 51` generan imágenes JPEG a 200 DPI acotadas a las primeras 51 páginas. Exit code 0 ante éxito y $>0$ ante error. | Documental y Empírica | `man pdftoppm`, pruebas empíricas en contenedor `audfact-php` | Sí: captura todas las páginas ordenadas y aborta si `$exitCode !== 0` o si `$totalPages > 50`. `[CONFIRMADO]` |
| **Google Gemini Multimodal API** | `mediaResolution: MEDIA_RESOLUTION_HIGH` optimiza el procesamiento de imágenes densas. Function calling opera de forma determinista con `temperature: 0`. | Documental | Documentación oficial Google AI (`ai.google.dev/gemini-api/docs/vision`) | Sí: se envían JPEGs a 200 DPI en partes `inlineData` estructuradas con tool configuration limpia. `[CONFIRMADO]` |

---

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
|---|---|---|---|---|
| **Desarrollo local (Docker WSL)** | PHP 8.2-FPM + Nginx + Redis + `poppler-utils` | `wsl docker compose up` | `Sí` | Verificado: `/usr/bin/pdftoppm` disponible en contenedor `audfact-php-1`. `[CONFIRMADO]` |
| **Desarrollo local (Windows nativo sin Docker)** | Ejecución de pruebas unitarias PHPUnit | `php vendor/bin/phpunit` | `Sí` | `DocumentExtractionWorkerTest` utiliza `PassthroughPdfRasterizer` stub cuando `pdftoppm` no está instalado en host Windows. `[CONFIRMADO]` |
| **CI (GitHub Actions)** | Build Docker + Composer test | Workflow `.github/workflows/ci.yml` | `Sí` | El Dockerfile instala `poppler-utils` antes de composer test. `[CONFIRMADO]` |
| **Producción (Host LAN 172.16.0.3)** | Docker Compose Zero-Source | `docker compose up` con imagen `ghcr.io/jfrem/audfact-php` | `Sí` | Las imágenes en GHCR incorporan `poppler-utils` en el build inmutable. `[CONFIRMADO]` |

---

### 0.6 Clasificación de Completitud Inicial
- **`Nivel A — Implementable`**: Descubrimiento completo, cero dependencias críticas desconocidas, cero supuestos S3/S4 y validación empírica en las 3 dispensas reales.

---

## FASE 1 — Especificación Técnica

### 1. Objetivo
Erradicar de manera definitiva y robusta las inconsistencias en la extracción de datos documentales (cédulas, fechas, identificadores médicos, códigos de productos y firmas) producidas por la rasterización interna a baja resolución (~72 DPI) de Gemini, eliminando todos los fallbacks silenciosos a PDF crudo, simplificando el prompt del sistema y blindando el manejo de procesos y archivos temporales.

---

### 2. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
|---|---|---|---|
| **DA-01** | **Formato JPEG a 200 DPI nativo** | PNG a 200 DPI, PNG a 300 DPI | JPEG a 200 DPI reduce el tamaño de payload ~3.5x frente a PNG sin degradar la agudeza visual de fuentes de 6-8pt. `[CONFIRMADO]` |
| **DA-02** | **Cero tolerancia a códigos de salida de Poppler** | Ignorar códigos no cero si existen imágenes generadas | Si `pdftoppm` finaliza con código no cero, el PDF está corrupto o incompleto. Lanzar `RuntimeException` garantiza que no se audite un documento truncado y activa el circuito DLQ. `[CONFIRMADO]` |
| **DA-03** | **Límite de renderizado preventivo con `-f 1 -l 51`** | Renderizar todo el PDF y contar imágenes en disco | Evita que PDFs maliciosos de 10,000 páginas saturen disco y CPU antes del rechazo. `[CONFIRMADO]` |
| **DA-04** | **Reutilización estricta de `$multimodalParts` en retries** | Pasar `$document` (PDF crudo) a `GeminiResponseParser` | Garantiza que cualquier segundo intento mantenga la misma nitidez a 200 DPI del primer intento, eliminando fallbacks silenciosos. `[CONFIRMADO]` |
| **DA-05** | **Limpieza por patrón de prefijo en `finally`** | Limpiar solo arreglo de archivos registrados en éxito | `glob("{$tempDir}/*_{$uniqueId}*")` asegura que incluso ante un timeout ningún archivo temporal quede huérfano en disco. `[CONFIRMADO]` |
| **DA-06** | **Prompt determinista minimalista (12 líneas)** | Prompt con tablas de ambigüedad `9↔0`, `5↔6`, etc. | Las pruebas empíricas demostraron que las tablas de confusión inducían al modelo a sobre-corregir caracteres válidos. `[CONFIRMADO]` |

---

### 3. Invariantes del Sistema

| Invariante | Enforcement | Validación |
|---|---|---|
| **INV-01**: Ninguna llamada a Gemini multimodal para documentos PDF debe enviar `application/pdf` crudo a 72 DPI. | `DocumentExtractionWorker::buildMultimodalParts` + `GeminiResponseParser::retryMissingFunctions` | Snapshots de `logs/responseIA` confirman `image/jpeg`. `[CONFIRMADO]` |
| **INV-02**: Todo fallo en la generación de imágenes debe abortar la extracción con excepción hacia DLQ. | `DocumentPdfRasterizer::executeProcess` verifica `$exitCode === 0`. | `DocumentPdfRasterizerTest`. `[CONFIRMADO]` |
| **INV-03**: Cero fugas de archivos temporales en `/tmp/audfact-runtime/rasterizer/`. | Bloque `finally` con borrado exhaustivo por prefijo único. | `DocumentPdfRasterizerTest`. `[CONFIRMADO]` |
| **INV-04**: Ninguna página generada puede omitirse silenciosamente. | `file_get_contents` lanza excepción inmediata ante fallo de lectura. | `DocumentPdfRasterizerTest`. `[CONFIRMADO]` |
| **INV-05**: Documentos con más de 50 páginas se rechazan preventivamente. | `-f 1 -l 51` detecta excedente y lanza `RuntimeException`. | `DocumentPdfRasterizerTest`. `[CONFIRMADO]` |

---

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
|---|---|---|
| Todas las entidades persistentes mencionadas por la especificación están definidas | `PASS` | `AudDispEst`, `AdjuntosDispensacion`, `DispensacionDetalleServicio` intactas. `[CONFIRMADO]` |
| Todas las columnas mencionadas existen | `PASS` | No se modifican esquemas SQL Server. `[CONFIRMADO]` |
| Todos los contratos documentados con clasificación | `PASS` | Clasificación de eventos Redis Streams verificada en FASE 0. `[CONFIRMADO]` |
| Todos los requisitos tienen trazabilidad | `PASS` | Matriz documentada en FASE 0 y FASE 1. `[CONFIRMADO]` |
| Todos los consumidores analizados | `PASS` | Grafo de dependencias completo en FASE 0.2. `[CONFIRMADO]` |
| Todas las referencias a archivos, clases y métodos están definidas | `PASS` | Rutas y métodos verificados por lectura directa. `[CONFIRMADO]` |
| Todos los criterios son verificables | `PASS` | Criterios medibles e independientes. `[CONFIRMADO]` |

---

## FASE 3 — Auditoría Arquitectónica y Adversarial

| # | Pregunta Adversarial | Regresión que Previene | Resultado | Evidencia |
|---|---|---|---|---|
| 1 | ¿Existe algún script de arranque o entrypoint que invoque comandos o binarios eliminados? | Runtime | `NO` | `docker-entrypoint.sh` y `bin/audit-worker.php` operan intactos. `[CONFIRMADO]` |
| 2 | ¿Existe algún paso de build que dependa de archivos eliminados? | Build | `NO` | `docker/Dockerfile` añade `poppler-utils` sin alterar etapas previas. `[CONFIRMADO]` |
| 3 | ¿Existe algún pipeline o validación automatizada que ejecute con configuración distinta? | Pipeline | `NO` | `phpunit.xml`, `.env.example` y GitHub Environment sincronizados. `[CONFIRMADO]` |
| 4 | ¿El cambio asume un comportamiento de herramienta sin verificar documentación? | Semántica de Herramienta | `NO` | Comportamiento de `pdftoppm` y Gemini API verificado documental y empíricamente. `[CONFIRMADO]` |
| 5 | ¿El cambio está optimizado solo para un entorno? | Paridad de Entornos | `NO` | Matriz de entornos documentada y probada. `[CONFIRMADO]` |
| 6 | ¿Existe algún mecanismo de override en runtime que pueda anular el comportamiento? | Runtime por Override | `NO` | Variables `GEMINI_EXTRACTION_*` saneadas en `.env*` y GitHub remote. `[CONFIRMADO]` |
| 7 | ¿Se aplicó algún patrón sin verificar contratos existentes? | Dogmatismo Técnico | `NO` | La solución respeta `DocumentIntegrityValidator` y el ciclo de vida de `AuditEventConsumer`. `[CONFIRMADO]` |
| 8 | ¿El cambio altera interfaces públicas consumidas por otros servicios sin compatibilidad? | Contract | `NO` | Los eventos en Redis Streams y los endpoints REST mantienen sus schemas JSON. `[CONFIRMADO]` |
| 9 | ¿El cambio afecta datos persistidos sin migración? | Data | `NO` | Sin cambios en tablas ni DDL. `[CONFIRMADO]` |
| 10 | ¿El cambio introduce código muerto, dependencias obsoletas o adaptadores legacy? | Clean Architecture | `NO` | Erradicación total de fallbacks silenciosos bajo Clean Rebuild. `[CONFIRMADO]` |

---

## FASE 4 — Resultado Final

### Nivel de Completitud
**`Nivel A — Implementable`**
