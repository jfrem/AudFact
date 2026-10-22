# Auditoría línea por línea del `.env` local

Fecha: 2026-10-22. Repositorio: AudFact. Archivo revisado: `.env` de la raíz. Valores de configuración y secretos omitidos.

**Dictamen: el archivo contiene líneas sin efecto y configuraciones que no controlan el comportamiento que sus nombres sugieren. No puede certificarse que todas las variables se apliquen.** Se revisaron 134 líneas físicas: 118 asignaciones únicas y 16 líneas vacías; no hay comentarios, claves duplicadas ni líneas de sintaxis no reconocida.

La evidencia corresponde al código del workspace y a la configuración resuelta de Compose. La revisión cubre carga, lectura, transporte al proceso, precedencia y uso posterior. No constituye una validación de imágenes actualmente desplegadas, conectividad SQL/Redis ni disponibilidad de modelos o credenciales en Google. No se invocaron servicios de negocio ni se modificó `.env`, código de aplicación, Docker o configuración remota.

## Resumen de clasificación

Cada variable tiene un estado principal. Las condiciones adicionales se explican en su fila. Una clave opcional vacía, una clave exclusiva de pruebas y una clave de build no son por sí mismas código muerto.

| Estado | Variables | Criterio |
|---|---:|---|
| ACTIVA | 76 | Tiene un consumidor conectado y un efecto identificable en su ámbito. |
| CONDICIONAL | 16 | Aplica al usar un perfil, comando, integración o rama de ejecución determinados. |
| DESCONECTADA | 7 | Existe lector, pero el valor de este archivo no llega al proceso/build correspondiente. |
| SIN_EFECTO | 7 | Sin consumidor funcional del runtime actual; puede sobrevivir en plantillas, métodos sin callers o metadatos. |
| SOBRESCRITA | 4 | El valor es leído o registrado y posteriormente reemplazado en las rutas actuales. |
| VACÍA | 4 | Opcional vacía; se explica si basta asignarla para activarla o si otra opción la anula. |
| PARCIAL | 2 | Se presenta/usa en parte, pero no impone el límite o timeout anunciado. |
| SOLO_VALIDACIÓN | 1 | Se exige presencia, sin utilizar el valor para configurar el servicio anunciado. |
| SOLO_PRUEBAS | 1 | Tiene consumidores en testing, sin controlar el runtime de negocio. |

Los estados no deben sumarse como incidentes independientes: un hallazgo puede agrupar varias variables.

## Hallazgos y acciones recomendadas

### F01 · Media · Dos claves huérfanas y ausentes de la plantilla

- `.env:121` — `AUDIT_CONSUMER_LEASE_TTL_SECONDS`: cero referencias operativas. La ejecución durable usa `LEASE_MS = 600000` en `app/Services/Audit/Pipeline/AuditExecutionConfig.php:12` y ese valor se consume en `app/Services/Audit/Pipeline/AuditEventConsumer.php:184`.
- `.env:126` — `AUDIT_BLOB_TTL_SECONDS`: cero referencias operativas. `app/Services/Audit/Pipeline/AttachmentDownloadWorker.php:37` toma el TTL del constructor o de `AuditDocumentCache::DEFAULT_TTL_SECONDS`, constante de `app/Services/Audit/Pipeline/AuditDocumentCache.php:14`; se pasa a la caché en `AttachmentDownloadWorker.php:40`.
- Ambas claves faltan en `.env.example`. La comparación se realizó por nombres, sin volcar valores. Al no existir consumidor, agregarlas a la plantilla por sí solo no les daría efecto.

Acción: retirar las claves obsoletas o implementar explícitamente el contrato deseado. Si se decide conectarlas, sincronizar plantilla, catálogo y GitHub conforme a AGENTS.md. Esta auditoría no realiza ese cambio.

### F02 · Media · La escala legacy de extracción no escala servicios

`.env:102` — `AUDIT_WORKER_EXTRACTION_REPLICAS` solo sobrevive como escritura de configuración en `.github/workflows/deploy-production.yml:437`. Los servicios usan `AUDIT_WORKER_EXTRACTION_VIP_REPLICAS` en `docker-compose.yml:188` y `AUDIT_WORKER_EXTRACTION_BATCH_REPLICAS` en `docker-compose.yml:215`.

Acción: retirar la clave legacy de los contratos que la anuncian y conservar VIP/BATCH como controles de escala. Editar la clave legacy no cambia la cantidad de extractores.

### F03 · Baja · DB_TYPE no selecciona el driver

`.env:27` — `DB_TYPE` se escribe en `.github/workflows/deploy-production.yml:344`, pero `core/Database.php:107` construye directamente un DSN `sqlsrv:`. El resolvedor de configuración de `core/Database.php:234` no lee un tipo. Las otras 16 claves DB/DB2 de este archivo sí tienen lectura dinámica y se verificaron con marcadores sintéticos en memoria.

Acción: describir el driver como fijo y retirar el selector ficticio, o definir una selección real si el producto la requiere. No confundir este hallazgo con las lecturas válidas por prefijo de DB/DB2.

### F04 · Media · AUDIT_CACHE_TTL tiene lector, pero ese lector no se ejecuta

`.env:124` — `AUDIT_CACHE_TTL` se lee en `core/Cache.php:218`, dentro de `storeDispensationHash()` definido en `core/Cache.php:216`. La búsqueda de llamadas en `app/`, `core/`, `bin/` y `public/` solo encuentra esa definición; tampoco hay callers del método que consulta esos hashes (`core/Cache.php:198`).

Las consultas actuales usan TTL explícito: 30 segundos en `app/Controllers/AuditController.php:178` y 60 segundos en `app/Controllers/AuditController.php:300`, pasados a `Cache::remember()`. Cambiar esta variable no altera esos TTL.

Acción: retirar el contrato huérfano o conectar un caso de uso real, diferenciando TTL de hashes, resultados SQL y extracción.

### F05 · Alta · Siete valores del .env raíz no configuran el frontend

Variables: `.env:6` y `.env:16` a `.env:21` (`INTERNAL_API_URL` y las seis `NEXT_PUBLIC_*`). Los lectores existen en `frontend/lib/api/config.ts:8`, `frontend/lib/api/server-config.ts:19` y `frontend/lib/api/client.ts:29`.

La cadena se interrumpe en dos puntos verificables:

1. El build usa `context: frontend` en `.github/workflows/publish-images.yml:71`. `docker/frontend.Dockerfile:17` copia ese contexto y `docker/frontend.Dockerfile:18` compila; el job no proporciona estas claves como argumentos/entorno de build. `frontend/.dockerignore:10` también excluye archivos `.env`. El archivo raíz queda fuera del contexto.
2. `docker-compose.yml:14` solo inyecta el entorno explícito del servicio frontend. `docker-compose.yml:18` fija `INTERNAL_API_URL` como literal, y no existe `env_file` en ese servicio. Las seis claves públicas tampoco se inyectan.

Verificaciones ejecutadas: Compose resuelto confirma ausencia de las seis claves públicas y valor interno literal. El cargador instalado `@next/env`, ejecutado sobre el directorio `frontend`, carga cero archivos y no incorpora ninguna de las siete claves. Actualmente las seis públicas coinciden con sus defaults; esa coincidencia oculta el problema. El valor local de `INTERNAL_API_URL` es distinto del literal de Compose.

Acción: establecer un contrato explícito para frontend. Las claves públicas deben llegar al momento de compilación o publicarse mediante configuración runtime diseñada para ello; solo agregarlas al entorno de un contenedor con bundle ya compilado no resuelve la configuración del navegador. La URL interna debe tener un transporte coherente hasta el proceso Next.js. No se cambió el build ni se reconstruyeron imágenes.

### F06 · Media · Las URLs públicas son recalculadas y no gobiernan CORS

`.env:5` y `.env:7`: el script de sincronización revisa estos nombres en `scripts/sync-github-production-env.sh:416`, pero el deploy calcula las URLs desde el nodo en `.github/workflows/deploy-production.yml:292` y `.github/workflows/deploy-production.yml:293`. Escribe esos resultados en las líneas 322 y 324, reemplazando la configuración de entrada. Los orígenes permitidos se construyen en las líneas 297 y 299 a partir de nodos/puerto y `ALLOWED_ORIGINS`.

Acción: unificar la fuente de verdad. No anunciar estas dos claves como controles efectivos de publicación o CORS mientras su valor sea recalculado.

### F07 · Baja · WEBHOOK_URL y CAPABILITIES_URL son metadatos sin consumidor interno

`.env:23` y `.env:24`: se componen en `.github/workflows/deploy-production.yml:294` y `.github/workflows/deploy-production.yml:295`, y se escriben en las líneas 340 y 341. No hay lectura funcional de esos nombres en el código MCP ni frontend del repositorio. `app/wrap/capabilities.php:4` devuelve una estructura estática; `app/wrap/webhook.php:34` registra herramientas directamente.

Acción: documentar su carácter informativo o retirar el contrato si no existe una integración externa que lo necesite. La ausencia de un consumidor en este repositorio no demuestra que ningún operador o cliente externo lea esos metadatos.

### F08 · Media · AUDIT_INTERNAL_API_BASE solo impone una validación de arranque

`.env:97`: `bin/audit-worker.php:23`, `bin/audit-worker.php:27` y `bin/audit-worker.php:35` la declaran obligatoria. `bin/audit-worker.php:117` verifica que no esté vacía. No hay consumidor que use su valor como base HTTP del pipeline. El cliente MCP usa otra clave, `WRAP_API_BASE`, en `app/wrap/core/ApiClient.php:18`.

Acción: retirar la validación obsoleta o conectar el valor a un cliente real si aún se necesita. Hoy una cadena no vacía permite pasar el guard, aunque no configure ningún destino.

### F09 · Media · Dos parámetros globales de Gemini quedan eclipsados

`.env:66` y `.env:69`: `GEMINI_MAX_OUTPUT_TOKENS` y `GEMINI_THINKING_LEVEL` se leen en `app/Services/Audit/GeminiConfig.php:42` y `app/Services/Audit/GeminiConfig.php:45`. Sin embargo, extracción siempre proporciona overrides/defaults en `app/Services/Audit/Pipeline/DocumentExtractionWorker.php:430`; semántica unitaria los proporciona en `app/Services/Audit/SemanticMatchJudge.php:186`, y semántica agrupada en `app/Services/Audit/SemanticBatchContract.php:40` y su línea 44.

`GeminiConfig.php:80` mezcla el override sobre el límite global y `GeminiConfig.php:73` prioriza thinkingLevel del override. Aunque se retiren las claves por tarea del archivo, los callers conservan sus defaults. La búsqueda de los métodos de envío identifica esas rutas como los consumidores de negocio actuales. Las comprobaciones en memoria confirmaron que los perfiles sustituyen valores base sintéticos distintos.

Acción: documentar una única precedencia y retirar controles globales que no deban servir como fallback, o cambiar los defaults por tarea si se desea que hereden del global.

### F10 · Media · Los tres thinkingBudget no vencen a thinkingLevel

`.env:68`, `.env:71` y `.env:75` están vacías. Se convierten en null con `app/Services/Audit/GeminiConfig.php:146` y se omiten. Además, no basta llenarlas: `GeminiConfig.php:82` elige thinkingLevel si no es null y solo usa budget en el elseif de la línea 84. `fromEnv()` siempre aporta un thinkingLevel no nulo en la línea 45, y los perfiles actuales también añaden low por defecto.

La prueba con budget sintético no vacío confirmó que el payload conserva solo thinkingLevel. Es una anulación por la lógica local, independientemente de la compatibilidad de cada modelo en Google. En cambio, `GEMINI_SEED` de `.env:76` es una opción conectada legítimamente vacía: asignar un entero sí la incorpora al payload (`GeminiConfig.php:64`).

Acción: definir una elección coherente entre nivel y presupuesto. No comunicar que editar las claves budget basta para afectar las llamadas actuales.

### F11 · Media · AUDIT_BATCH_MAX_LIMIT no es el máximo efectivo

`.env:78`: se expone en `app/Controllers/ConfigController.php:19`. La UI lo usa como valor inicial en `frontend/components/audit/audit-batch-console.tsx:70` y lo muestra como máximo en su línea 253. Sin embargo, el esquema Zod fija 100 en la línea 29 y el backend fija 10000 en `app/Controllers/AuditController.php:402`, con default 100 en su línea 416. Ninguno de esos límites consulta la variable.

Acción: separar máximo, default y tope del endpoint o aplicar el mismo contrato de límites en ambos lados. Variar el .env no cambia los topes actuales.

### F12 · Media · AUDIT_BATCH_TIMEOUT solo se muestra

`.env:80`: `app/Controllers/ConfigController.php:20` lo convierte a milisegundos. El componente recibe `timeoutMs` en `frontend/components/audit/audit-batch-console.tsx:43` y lo usa para la etiqueta de la línea 254. La mutación de la línea 77 no pasa ese timeout y `frontend/lib/api/client.ts:49` ejecuta fetch sin derivar de él una cancelación. No hay lector de esa variable que imponga un deadline de job en el pipeline.

Acción: renombrarla como información de UI o implementar un contrato de deadline/cancelación. Los timeouts reales de Nginx, FPM y Gemini sí tienen consumidores distintos y están identificados en la matriz.

### F13 · Baja · El límite semántico unitario no gobierna el modo agrupado v2

`.env:73` — `GEMINI_SEMANTIC_MAX_OUTPUT_TOKENS` sí aplica a la ruta unitaria de `app/Services/Audit/SemanticMatchJudge.php:186`. La ruta agrupada lee el perfil en `app/Services/Audit/SemanticBatchContract.php:40` y reemplaza el límite por 8192 en su línea 44. El archivo local no define `AUDIT_PIPELINE_EXECUTION_VERSION`, por lo que el código tiene default v1 (`app/Services/Audit/Pipeline/AuditExecutionConfig.php:27`); el entorno externo podría cambiarlo.

Acción: dejar explícito que la clave es de la semántica unitaria y que v2 tiene un límite fijo independiente, o parametrizar cada contrato si se necesita.

### F14 · Informativa · Condiciones legítimas de aplicación

- `.env:57` — `ALLOWED_ORIGINS` no se consulta en la rama local de desarrollo: `public/index.php:35` devuelve `*`. La lectura efectiva ocurre en la rama alternativa, línea 38. No es una clave huérfana.
- `.env:128` — `RUN_REDIS_INTEGRATION` se usa en tests (`tests/Integration/AuditDocumentCacheRedisTest.php:20`), y CI la establece aparte (`.github/workflows/ci.yml:146`). `phpunit.xml:4` carga Composer, sin bootstrap dotenv. La línea local por sí sola no garantiza activar las suites: hay que cargar/exportar el entorno del proceso de pruebas. No es un control de producción.
- `.env:3` y `.env:4` — UID/GID son argumentos del build local (`docker-compose.yml:35` y `docker-compose.yml:36`); el publish fija 33 (`.github/workflows/publish-images.yml:52`). Cambiar runtime no reescribe usuarios de una imagen publicada.

### F15 · Informativa · PHP_CLI_MEMORY_LIMIT también alcanza PHP-FPM

`.env:134` sí aplica al launcher CLI en `bin/audit-worker.php:13`. Además, `docker/docker-entrypoint.sh:7` escribe el valor en un INI global de PHP, que también hereda FPM. No es una línea muerta, pero el nombre describe solo parte de su alcance.

Acción: documentar el alcance compartido al ajustar memoria. Este reporte no cambia límites de contenedores ni recomienda aumentar capacidad sin medición.

## Cadena de carga verificada

- PHP y workers reciben `.env` mediante `env_file` de Compose (`docker-compose.yml:38` y `docker-compose.yml:126`). El Dockerfile copia código, no el archivo local (`docker/Dockerfile:56`), y `.dockerignore:12` excluye secretos. En contenedor, `core/Env.php:10` tolera que el archivo no exista y `core/Env.php:36` resuelve desde getenv. FPM conserva el entorno con `docker/php-fpm-pool.conf.template:17`.
- En ejecución PHP directa del host, `public/index.php:16` y `bin/audit-worker.php:11` sí cargan el archivo. `core/Env.php:25` y `core/Env.php:26` escriben el valor en proceso y caché; esa carga puede reemplazar valores externos si el archivo está presente.
- Los nombres construidos dinámicamente se verificaron: DB/DB2 mediante `core/Database.php:282` más sus sufijos en las líneas 238 a 247; perfiles Gemini mediante `GeminiConfig::generationOverridesFromEnv()` en `app/Services/Audit/GeminiConfig.php:114`. Un grep literal por clave completa habría producido falsos positivos.
- Una asignación idéntica al default sigue siendo una configuración conectada cuando alcanza el consumidor; la coincidencia no se usa para clasificarla como muerta.

## Matriz de las 134 líneas

Las referencias `ruta:línea` son del workspace auditado. La columna inicial identifica la línea física de `.env`. Las filas vacías se incluyen para garantizar cobertura completa; no tienen un consumidor esperado. Los valores se omiten incluso cuando no son secretos.

| Línea .env | Clave / contenido | Estado | Efecto comprobado o limitación | Evidencia de código/configuración |
|---:|---|---|---|---|
| 1 | `APP_ENV` | ACTIVA | Selecciona errores, CORS y logging. El archivo local selecciona desarrollo; el deploy fuerza producción. | `public/index.php:27`; `public/index.php:35`; `core/Logger.php:72`; `.github/workflows/deploy-production.yml:241` |
| 2 | `WRAP_API_BASE` | CONDICIONAL | Base HTTP efectiva del cliente MCP; aplica al invocar sus herramientas. | `app/wrap/core/ApiClient.php:18`; `app/wrap/core/ApiClient.php:32` |
| 3 | `WWWUSER_ID` | CONDICIONAL | Argumento de build local que cambia www-data. El publish GHCR fija 33; editar el .env no cambia una imagen ya publicada. | `docker-compose.yml:35`; `docker/Dockerfile:13`; `docker/Dockerfile:14`; `.github/workflows/publish-images.yml:52` |
| 4 | `WWWGROUP_ID` | CONDICIONAL | Argumento de build local que cambia www-data. El publish GHCR fija 33; editar el .env no cambia una imagen ya publicada. | `docker-compose.yml:36`; `docker/Dockerfile:12`; `docker/Dockerfile:14`; `.github/workflows/publish-images.yml:52` |
| 5 | `AUDFACT_API_PUBLIC_URL` | SOBRESCRITA | Se valida en el script de sincronización, pero el deploy calcula otra URL desde el nodo y la escribe; no configura endpoints. F06. | `scripts/sync-github-production-env.sh:416`; `.github/workflows/deploy-production.yml:293`; `.github/workflows/deploy-production.yml:322` |
| 6 | `INTERNAL_API_URL` | DESCONECTADA | Sí existe lector Next.js, pero este .env no se carga en frontend y Compose fija una URL literal distinta del valor local. F05. | `frontend/lib/api/server-config.ts:19`; `frontend/lib/api/client.ts:29`; `docker-compose.yml:18` |
| 7 | `AUDFACT_FRONTEND_PUBLIC_URL` | SOBRESCRITA | Se valida en sincronización; deploy la calcula desde nodo/puerto. CORS se arma con lista de nodos y ALLOWED_ORIGINS. F06. | `scripts/sync-github-production-env.sh:416`; `.github/workflows/deploy-production.yml:292`; `.github/workflows/deploy-production.yml:297`; `.github/workflows/deploy-production.yml:324` |
| 8 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 9 | `AUDFACT_PHP_IMAGE` | ACTIVA | Selecciona la imagen PHP de API y workers. | `docker-compose.yml:29` |
| 10 | `AUDFACT_NGINX_IMAGE` | ACTIVA | Selecciona la imagen del proxy Nginx. | `docker-compose.yml:97` |
| 11 | `AUDFACT_FRONTEND_IMAGE` | CONDICIONAL | Selecciona la imagen Next.js al usar el perfil frontend. | `docker-compose.yml:6` |
| 12 | `AUDFACT_DOCS_IMAGE` | CONDICIONAL | Selecciona la imagen documental al usar el perfil docs. | `docker-compose.yml:319` |
| 13 | `AUDFACT_IMAGE_TAG` | ACTIVA | Selecciona la etiqueta de las imágenes en Compose; el deploy genera el tag de la release. | `docker-compose.yml:29` |
| 14 | `AUDFACT_FRONTEND_HOST_PORT` | CONDICIONAL | Publica el puerto del frontend al habilitar ese perfil. | `docker-compose.yml:13` |
| 15 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 16 | `NEXT_PUBLIC_APP_NAME` | DESCONECTADA | Nombre de aplicación, título y sidebar. El lector existe, pero el valor del .env raíz no llega al build ni al contenedor frontend; hoy coincide con el fallback. F05. | `frontend/lib/api/config.ts:8`; `frontend/components/layout/app-sidebar.tsx:49`; `.github/workflows/publish-images.yml:71`; `docker/frontend.Dockerfile:18`; `docker-compose.yml:14` |
| 17 | `NEXT_PUBLIC_DEFAULT_THEME` | DESCONECTADA | Tema inicial del proveedor de temas. El lector existe, pero el valor del .env raíz no llega al build ni al contenedor frontend; hoy coincide con el fallback. F05. | `frontend/lib/api/config.ts:9`; `frontend/providers/theme-provider.tsx:12`; `.github/workflows/publish-images.yml:71`; `docker/frontend.Dockerfile:18`; `docker-compose.yml:14` |
| 18 | `NEXT_PUBLIC_POLLING_JOBS_MS` | DESCONECTADA | Intervalo de actualización de jobs. El lector existe, pero el valor del .env raíz no llega al build ni al contenedor frontend; hoy coincide con el fallback. F05. | `frontend/lib/api/config.ts:10`; `frontend/lib/query/audit.ts:48`; `.github/workflows/publish-images.yml:71`; `docker/frontend.Dockerfile:18`; `docker-compose.yml:14` |
| 19 | `NEXT_PUBLIC_POLLING_HEALTH_MS` | DESCONECTADA | Intervalo de health y métricas. El lector existe, pero el valor del .env raíz no llega al build ni al contenedor frontend; hoy coincide con el fallback. F05. | `frontend/lib/api/config.ts:11`; `frontend/lib/query/system.ts:11`; `.github/workflows/publish-images.yml:71`; `docker/frontend.Dockerfile:18`; `docker-compose.yml:14` |
| 20 | `NEXT_PUBLIC_LOCALE` | DESCONECTADA | Locale de fechas en la lista de jobs; otros formatters tienen formatos propios. El lector existe, pero el valor del .env raíz no llega al build ni al contenedor frontend; hoy coincide con el fallback. F05. | `frontend/lib/api/config.ts:12`; `frontend/components/jobs/jobs-list-live.tsx:108`; `.github/workflows/publish-images.yml:71`; `docker/frontend.Dockerfile:18`; `docker-compose.yml:14` |
| 21 | `NEXT_PUBLIC_TIMEZONE` | DESCONECTADA | Zona horaria de jobs y formatos de instantes. El lector existe, pero el valor del .env raíz no llega al build ni al contenedor frontend; hoy coincide con el fallback. F05. | `frontend/lib/api/config.ts:13`; `frontend/lib/formatters/index.ts:93`; `.github/workflows/publish-images.yml:71`; `docker/frontend.Dockerfile:18`; `docker-compose.yml:14` |
| 22 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 23 | `WEBHOOK_URL` | SIN_EFECTO | El workflow genera/preserva este metadato; no hay lector funcional en aplicación, frontend ni puente MCP del repositorio. Posible consumidor externo no verificable. F07. | `.github/workflows/deploy-production.yml:340`; `app/wrap/capabilities.php:4`; `app/wrap/webhook.php:34` |
| 24 | `CAPABILITIES_URL` | SIN_EFECTO | El workflow genera/preserva este metadato; no hay lector funcional en aplicación, frontend ni puente MCP del repositorio. Posible consumidor externo no verificable. F07. | `.github/workflows/deploy-production.yml:341`; `app/wrap/capabilities.php:4`; `app/wrap/webhook.php:34` |
| 25 | `MCP_WEBHOOK_SECRET` | CONDICIONAL | Se compara con X-API-KEY para admitir/rechazar peticiones MCP. | `app/wrap/webhook.php:16`; `app/wrap/webhook.php:19` |
| 26 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 27 | `DB_TYPE` | SIN_EFECTO | No se lee para seleccionar driver. El DSN está fijado a sqlsrv; cambiar la clave no cambia el motor. F03. | `core/Database.php:107`; `core/Database.php:234`; `.github/workflows/deploy-production.yml:344` |
| 28 | `DB_HOST` | ACTIVA | Host del servidor SQL. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:238`; `core/Database.php:282`; `core/Database.php:85` |
| 29 | `DB_PORT` | ACTIVA | Puerto del DSN SQL. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:239`; `core/Database.php:282`; `core/Database.php:85` |
| 30 | `DB_NAME` | ACTIVA | Nombre de base de datos del DSN. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:240`; `core/Database.php:282`; `core/Database.php:107` |
| 31 | `DB_USER` | ACTIVA | Usuario pasado a PDO. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:241`; `core/Database.php:282`; `core/Database.php:112` |
| 32 | `DB_PASS` | ACTIVA | Credencial pasada a PDO; valor omitido. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:242`; `core/Database.php:282`; `core/Database.php:112` |
| 33 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 34 | `DB2_HOST` | ACTIVA | Host del servidor SQL. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:238`; `core/Database.php:282`; `core/Database.php:85` |
| 35 | `DB2_PORT` | ACTIVA | Puerto del DSN SQL. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:239`; `core/Database.php:282`; `core/Database.php:85` |
| 36 | `DB2_NAME` | ACTIVA | Nombre de base de datos del DSN. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:240`; `core/Database.php:282`; `core/Database.php:107` |
| 37 | `DB2_USER` | ACTIVA | Usuario pasado a PDO. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:241`; `core/Database.php:282`; `core/Database.php:112` |
| 38 | `DB2_PASS` | ACTIVA | Credencial pasada a PDO; valor omitido. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:242`; `core/Database.php:282`; `core/Database.php:112` |
| 39 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 40 | `DB_POOLING` | ACTIVA | Activa/desactiva ConnectionPooling del driver. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:244`; `core/Database.php:282`; `core/Database.php:108` |
| 41 | `DB2_POOLING` | ACTIVA | Activa/desactiva ConnectionPooling del driver. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:244`; `core/Database.php:282`; `core/Database.php:108` |
| 42 | `DB_ENCRYPT` | ACTIVA | Opción Encrypt del DSN. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:246`; `core/Database.php:282`; `core/Database.php:107` |
| 43 | `DB_TRUST_SERVER_CERT` | ACTIVA | Opción TrustServerCertificate del DSN. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:247`; `core/Database.php:282`; `core/Database.php:107` |
| 44 | `DB2_ENCRYPT` | ACTIVA | Opción Encrypt del DSN. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:246`; `core/Database.php:282`; `core/Database.php:107` |
| 45 | `DB2_TRUST_SERVER_CERT` | ACTIVA | Opción TrustServerCertificate del DSN. Lectura dinámica por prefijo verificada sin abrir conexión. | `core/Database.php:247`; `core/Database.php:282`; `core/Database.php:107` |
| 46 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 47 | `GOOGLE_DRIVE_CLIENT_EMAIL` | CONDICIONAL | Credencial de autenticación del servicio Drive; interviene al usar ese servicio. | `app/Services/GoogleDriveAuthService.php:39`; `app/Services/GoogleDriveAuthService.php:97` |
| 48 | `GOOGLE_DRIVE_PRIVATE_KEY` | CONDICIONAL | Clave de firma Drive; convierte \n a saltos de línea y valida presencia. Valor omitido. | `app/Services/GoogleDriveAuthService.php:40`; `app/Services/GoogleDriveAuthService.php:101` |
| 49 | `GOOGLE_DRIVE_TLS_VERIFY` | CONDICIONAL | Configura verify del cliente HTTP de autenticación Drive. | `app/Services/GoogleDriveAuthService.php:45`; `app/Services/GoogleDriveAuthService.php:50` |
| 50 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 51 | `REQUEST_TIMEOUT_MS` | CONDICIONAL | Configura timeout y connect_timeout del cliente MCP, convertido de ms a segundos. No es el timeout Gemini. | `app/wrap/core/ApiClient.php:19`; `app/wrap/core/ApiClient.php:23`; `app/wrap/core/ApiClient.php:24` |
| 52 | `LOG_LEVEL` | ACTIVA | Selecciona el umbral de mensajes del logger. | `core/Logger.php:26`; `core/Logger.php:40`; `core/Logger.php:84` |
| 53 | `LOG_RETENTION_DAYS` | ACTIVA | Retención de archivos del logger en desarrollo; producción usa stderr y omite esa limpieza. | `core/Logger.php:16`; `core/Logger.php:79`; `core/Logger.php:197` |
| 54 | `LOG_MAX_SIZE_MB` | ACTIVA | Límite de rotación de archivos en desarrollo; no configura retención del driver Docker de logs. | `core/Logger.php:21`; `core/Logger.php:173` |
| 55 | `AUDIT_RESPONSE_IA_ENABLED` | ACTIVA | Permite snapshots Gemini en desarrollo; producción impide escribirlos aunque se habilite. | `app/Services/Audit/ResponseIADiskStore.php:19`; `app/Services/Audit/ResponseIADiskStore.php:143`; `app/Services/Audit/ResponseIADiskStore.php:147` |
| 56 | `AUDIT_RESPONSE_IA_DIR` | ACTIVA | Directorio de snapshots cuando el guard de entorno y habilitación permite persistir. | `app/Services/Audit/ResponseIADiskStore.php:20`; `app/Services/Audit/ResponseIADiskStore.php:137`; `app/Services/Audit/ResponseIADiskStore.php:43` |
| 57 | `ALLOWED_ORIGINS` | CONDICIONAL | En este APP_ENV=development no restringe CORS: se responde *. En otros entornos sí se usa la lista. F14. | `public/index.php:35`; `public/index.php:38`; `public/index.php:41` |
| 58 | `MAX_JSON_SIZE` | ACTIVA | Limita bytes del JSON recibido por el controlador base. | `app/Controllers/Controller.php:33`; `app/Controllers/Controller.php:35` |
| 59 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 60 | `GEMINI_API_KEY` | ACTIVA | Clave común que usa el gateway si no hay clave específica por carril. | `app/Services/Audit/GeminiGateway.php:77`; `app/Services/Audit/GeminiGateway.php:83`; `app/Services/Audit/GeminiGateway.php:96` |
| 61 | `GEMINI_MODEL` | ACTIVA | Modelo asignado al config del gateway; auditoría local no confirma disponibilidad en proveedor. | `app/Services/Audit/GeminiConfig.php:38`; `app/Services/Audit/GeminiGateway.php:97` |
| 62 | `GEMINI_TEMPERATURE` | ACTIVA | Llega al campo temperature de generationConfig. | `app/Services/Audit/GeminiConfig.php:39`; `app/Services/Audit/GeminiConfig.php:60`; `app/Services/Audit/GeminiGateway.php:570` |
| 63 | `GEMINI_TIMEOUT` | ACTIVA | Timeout Guzzle del gateway; en ejecución durable además se acota por deadline. | `app/Services/Audit/GeminiGateway.php:92`; `app/Services/Audit/GeminiGateway.php:95`; `app/Services/Audit/GeminiGateway.php:293` |
| 64 | `GEMINI_TOP_P` | ACTIVA | Llega al campo topP de generationConfig. | `app/Services/Audit/GeminiConfig.php:40`; `app/Services/Audit/GeminiConfig.php:61`; `app/Services/Audit/GeminiGateway.php:570` |
| 65 | `GEMINI_TOP_K` | ACTIVA | Llega al campo topK de generationConfig. | `app/Services/Audit/GeminiConfig.php:41`; `app/Services/Audit/GeminiConfig.php:62`; `app/Services/Audit/GeminiGateway.php:570` |
| 66 | `GEMINI_MAX_OUTPUT_TOKENS` | SOBRESCRITA | Se lee como base, pero extracción y semántica actuales siempre asignan límites por tarea/default. No gobierna esas llamadas. F09. | `app/Services/Audit/GeminiConfig.php:42`; `app/Services/Audit/GeminiConfig.php:80`; `app/Services/Audit/Pipeline/DocumentExtractionWorker.php:430`; `app/Services/Audit/SemanticMatchJudge.php:186`; `app/Services/Audit/SemanticBatchContract.php:44` |
| 67 | `GEMINI_MEDIA_RESOLUTION` | CONDICIONAL | Se incorpora al payload únicamente en tareas de extracción multimodal. | `app/Services/Audit/GeminiConfig.php:43`; `app/Services/Audit/GeminiConfig.php:67`; `app/Services/Audit/GeminiGateway.php:572` |
| 68 | `GEMINI_THINKING_BUDGET` | VACÍA | Vacía se normaliza a null. Incluso al asignarla, thinkingLevel tiene prioridad y nunca queda null en fromEnv. F10. | `app/Services/Audit/GeminiConfig.php:44`; `app/Services/Audit/GeminiConfig.php:45`; `app/Services/Audit/GeminiConfig.php:82`; `app/Services/Audit/GeminiConfig.php:146` |
| 69 | `GEMINI_THINKING_LEVEL` | SOBRESCRITA | Se lee como base, pero las rutas actuales siempre agregan thinkingLevel por tarea o default low. F09. | `app/Services/Audit/GeminiConfig.php:45`; `app/Services/Audit/GeminiConfig.php:73`; `app/Services/Audit/Pipeline/DocumentExtractionWorker.php:432`; `app/Services/Audit/SemanticMatchJudge.php:188`; `app/Services/Audit/SemanticBatchContract.php:41` |
| 70 | `GEMINI_EXTRACTION_MAX_OUTPUT_TOKENS` | ACTIVA | Límite por tarea leído con prefijo dinámico. Se usa en extracción. | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php:430`; `app/Services/Audit/GeminiConfig.php:125`; `app/Services/Audit/GeminiConfig.php:80` |
| 71 | `GEMINI_EXTRACTION_THINKING_BUDGET` | VACÍA | Vacía se omite. Darle un valor tampoco vence thinkingLevel del perfil o su default; no llega como budget al payload actual. F10. | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php:430`; `app/Services/Audit/GeminiConfig.php:129`; `app/Services/Audit/GeminiConfig.php:82` |
| 72 | `GEMINI_EXTRACTION_THINKING_LEVEL` | ACTIVA | thinkingLevel por perfil leído con prefijo dinámico; prevalece sobre el global y sobre budget. | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php:430`; `app/Services/Audit/GeminiConfig.php:133`; `app/Services/Audit/GeminiConfig.php:83` |
| 73 | `GEMINI_SEMANTIC_MAX_OUTPUT_TOKENS` | CONDICIONAL | Límite por tarea leído con prefijo dinámico. Aplica en semántica v1; v2 agrupada lo reemplaza por 8192. F13. | `app/Services/Audit/SemanticMatchJudge.php:186`; `app/Services/Audit/GeminiConfig.php:125`; `app/Services/Audit/GeminiConfig.php:80`; `app/Services/Audit/SemanticBatchContract.php:44` |
| 74 | `GEMINI_SEMANTIC_THINKING_LEVEL` | CONDICIONAL | thinkingLevel por perfil leído con prefijo dinámico; prevalece sobre el global y sobre budget. | `app/Services/Audit/SemanticMatchJudge.php:186`; `app/Services/Audit/GeminiConfig.php:133`; `app/Services/Audit/GeminiConfig.php:83` |
| 75 | `GEMINI_SEMANTIC_THINKING_BUDGET` | VACÍA | Vacía se omite. Darle un valor tampoco vence thinkingLevel del perfil o su default; no llega como budget al payload actual. F10. | `app/Services/Audit/SemanticMatchJudge.php:186`; `app/Services/Audit/GeminiConfig.php:129`; `app/Services/Audit/GeminiConfig.php:82` |
| 76 | `GEMINI_SEED` | VACÍA | Vacía se convierte en null y se filtra; si se asigna un entero sí entra al payload. Opcional conectada, no huérfana. | `app/Services/Audit/GeminiConfig.php:46`; `app/Services/Audit/GeminiConfig.php:64`; `app/Services/Audit/GeminiConfig.php:146` |
| 77 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 78 | `AUDIT_BATCH_MAX_LIMIT` | PARCIAL | Solo valor inicial/etiqueta en UI. Zod limita a 100 y backend a 10000 sin leer esta variable. F11. | `app/Controllers/ConfigController.php:19`; `frontend/components/audit/audit-batch-console.tsx:70`; `frontend/components/audit/audit-batch-console.tsx:29`; `app/Controllers/AuditController.php:402` |
| 79 | `AUDIT_BATCH_CRON_LIMIT` | CONDICIONAL | Límite del scheduler CLI cuando no se pasa --limit; no prueba que exista un cron instalado. | `bin/schedule-daily-batches.php:54`; `bin/schedule-daily-batches.php:55` |
| 80 | `AUDIT_BATCH_TIMEOUT` | PARCIAL | Solo se convierte a ms en config pública y se muestra como Timeout. No cancela fetch ni fija un deadline del job. F12. | `app/Controllers/ConfigController.php:20`; `frontend/components/audit/audit-batch-console.tsx:77`; `frontend/components/audit/audit-batch-console.tsx:254`; `frontend/lib/api/client.ts:49` |
| 81 | `AUDIT_NGINX_READ_TIMEOUT` | ACTIVA | Compose lo inyecta en Nginx y la plantilla lo usa como timeout FastCGI. | `docker-compose.yml:109`; `docker/nginx-ha.conf.template:39` |
| 82 | `AUDIT_FPM_TERMINATE_TIMEOUT` | ACTIVA | Entrypoint sustituye la variable en request_terminate_timeout de FPM. | `docker/docker-entrypoint.sh:10`; `docker/docker-entrypoint.sh:12`; `docker/php-fpm-pool.conf.template:14` |
| 83 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 84 | `REDIS_HOST` | ACTIVA | Host de la conexión standalone, modo seleccionado en este .env. | `core/RedisClient.php:50`; `core/RedisClient.php:86`; `core/RedisClient.php:91` |
| 85 | `REDIS_PORT` | ACTIVA | Puerto de la conexión standalone. | `core/RedisClient.php:87`; `core/RedisClient.php:92` |
| 86 | `REDIS_PREFIX` | ACTIVA | Namespace de las claves operadas por RedisClient. | `core/RedisClient.php:27`; `core/RedisClient.php:197` |
| 87 | `REDIS_PASSWORD` | ACTIVA | Se usa tanto para requirepass del servidor como para autenticación del cliente. Valor omitido. | `docker-compose.yml:65`; `docker-compose.yml:79`; `core/RedisClient.php:51`; `core/RedisClient.php:98` |
| 88 | `REDIS_MAXMEMORY` | ACTIVA | Límite --maxmemory de redis-server. | `docker-compose.yml:66`; `docker-compose.yml:79` |
| 89 | `REDIS_MAXMEMORY_POLICY` | ACTIVA | Política Redis en v1. V2 o marcador durable existente fuerza noeviction, aunque cambie la variable. | `docker-compose.yml:67`; `docker-compose.yml:73`; `docker-compose.yml:76` |
| 90 | `REDIS_CONTAINER_MEMORY` | ACTIVA | Límite de memoria del contenedor Redis en Compose. | `docker-compose.yml:93` |
| 91 | `REDIS_MODE` | ACTIVA | Selecciona standalone, sentinel o cluster en el cliente. | `core/RedisClient.php:50`; `core/RedisClient.php:55` |
| 92 | `REDIS_PERSISTENT` | ACTIVA | Selecciona conexión persistente en el cliente; el valor local 0 desactiva la opción. | `core/RedisClient.php:52`; `core/RedisClient.php:100` |
| 93 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 94 | `CB_GEMINI_THRESHOLD` | ACTIVA | Umbral de fallas del circuit breaker Gemini. | `app/Services/Audit/GeminiGateway.php:488` |
| 95 | `CB_GEMINI_COOLDOWN` | ACTIVA | Espera de recuperación del circuit breaker Gemini. | `app/Services/Audit/GeminiGateway.php:489` |
| 96 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 97 | `AUDIT_INTERNAL_API_BASE` | SOLO_VALIDACIÓN | El launcher exige que no esté vacía, pero ningún servicio la usa para construir solicitudes. No selecciona el backend de workers. F08. | `bin/audit-worker.php:23`; `bin/audit-worker.php:27`; `bin/audit-worker.php:35`; `bin/audit-worker.php:117` |
| 98 | `AUDIT_WORKER_ORCHESTRATOR_REPLICAS` | ACTIVA | Controla deploy.replicas del servicio correspondiente al aplicar Compose; no prueba las réplicas actualmente desplegadas. | `docker-compose.yml:136` |
| 99 | `AUDIT_WORKER_DOWNLOADER_REPLICAS` | ACTIVA | Controla deploy.replicas del servicio correspondiente al aplicar Compose; no prueba las réplicas actualmente desplegadas. | `docker-compose.yml:240` |
| 100 | `AUDIT_WORKER_EXTRACTION_VIP_REPLICAS` | ACTIVA | Controla deploy.replicas del servicio correspondiente al aplicar Compose; no prueba las réplicas actualmente desplegadas. | `docker-compose.yml:188` |
| 101 | `AUDIT_WORKER_EXTRACTION_BATCH_REPLICAS` | ACTIVA | Controla deploy.replicas del servicio correspondiente al aplicar Compose; no prueba las réplicas actualmente desplegadas. | `docker-compose.yml:215` |
| 102 | `AUDIT_WORKER_EXTRACTION_REPLICAS` | SIN_EFECTO | Clave legacy escrita en deploy pero no referenciada por ningún servicio. La escala real depende de VIP y BATCH. F02. | `.github/workflows/deploy-production.yml:437`; `docker-compose.yml:188`; `docker-compose.yml:215` |
| 103 | `AUDIT_WORKER_NORMALIZER_REPLICAS` | ACTIVA | Controla deploy.replicas del servicio correspondiente al aplicar Compose; no prueba las réplicas actualmente desplegadas. | `docker-compose.yml:264` |
| 104 | `AUDIT_WORKER_POLICY_REPLICAS` | ACTIVA | Controla deploy.replicas del servicio correspondiente al aplicar Compose; no prueba las réplicas actualmente desplegadas. | `docker-compose.yml:288` |
| 105 | `AUDIT_WORKER_PERSISTENCE_REPLICAS` | ACTIVA | Controla deploy.replicas del servicio correspondiente al aplicar Compose; no prueba las réplicas actualmente desplegadas. | `docker-compose.yml:312` |
| 106 | `AUDIT_WORKER_BATCH_REPLICAS` | ACTIVA | Controla deploy.replicas del servicio correspondiente al aplicar Compose; no prueba las réplicas actualmente desplegadas. | `docker-compose.yml:161` |
| 107 | `AUDIT_IDEMPOTENCY_KEY_TTL` | ACTIVA | TTL pasado al reclamar la clave de idempotencia en POST /audit/async. | `app/Controllers/AuditController.php:427`; `app/Controllers/AuditController.php:430` |
| 108 | `AUDIT_JOB_TTL` | ACTIVA | TTL del estado de jobs v1; v2 preserva estados activos y usa la retención en mantenimiento. | `app/Services/Audit/Pipeline/BatchJobStore.php:39`; `app/Services/Audit/Pipeline/BatchJobStore.php:98`; `app/Services/Audit/Pipeline/AuditExecutionMaintenance.php:165` |
| 109 | `AUDIT_STATE_TTL` | ACTIVA | TTL del estado de auditoría v1; v2 conserva activos y aplica retención terminal en mantenimiento. | `app/Services/Audit/Pipeline/AuditStateStore.php:41`; `app/Services/Audit/Pipeline/AuditStateStore.php:77`; `app/Services/Audit/Pipeline/AuditExecutionMaintenance.php:164` |
| 110 | `AUDIT_PERSISTENCE_QUEUE_TTL` | ACTIVA | Retención de estructuras auxiliares de la cola de persistencia. | `app/Services/Audit/Pipeline/AuditPersistenceQueue.php:312`; `app/Services/Audit/Pipeline/AuditPersistenceQueue.php:163` |
| 111 | `AUDIT_PERSISTENCE_JOB_SLOTS` | ACTIVA | Slots de persistencia fijados para jobs nuevos; no reparticiona jobs ya fijados. | `app/Services/Audit/Pipeline/AuditPersistenceQueue.php:32`; `app/Services/Audit/Pipeline/AuditPersistenceQueue.php:260` |
| 112 | `AUDIT_RESERVATION_TTL` | ACTIVA | TTL de reservas por DisId cuando no se pasa un TTL explícito. | `app/Services/Audit/Pipeline/BatchJobStore.php:44`; `app/Services/Audit/Pipeline/BatchJobStore.php:376` |
| 113 | `AUDIT_PENDING_RECLAIM_IDLE_MS` | ACTIVA | Umbral de inactividad antes de reclamar mensajes pendientes; batch tiene override propio. | `app/Services/Audit/Pipeline/AuditEventConsumer.php:61`; `app/Services/Audit/Pipeline/AuditEventConsumer.php:318` |
| 114 | `AUDIT_PENDING_RECLAIM_INTERVAL_MS` | ACTIVA | Intervalo de barrido de pendientes; batch tiene override propio. | `app/Services/Audit/Pipeline/AuditEventConsumer.php:66`; `app/Services/Audit/Pipeline/AuditEventConsumer.php:288` |
| 115 | `AUDIT_BATCH_PENDING_RECLAIM_IDLE_MS` | ACTIVA | Override del umbral de reclamación exclusivo del worker batch. | `app/Services/Audit/Pipeline/BatchRequestedWorker.php:52`; `app/Services/Audit/Pipeline/AuditEventConsumer.php:318` |
| 116 | `AUDIT_BATCH_PENDING_RECLAIM_INTERVAL_MS` | ACTIVA | Override del intervalo de reclamación exclusivo del worker batch. | `app/Services/Audit/Pipeline/BatchRequestedWorker.php:53`; `app/Services/Audit/Pipeline/AuditEventConsumer.php:288` |
| 117 | `AUDIT_BATCH_CHUNK_SIZE` | ACTIVA | Tamaño del chunk salvo override explícito en el estado de ejecución. | `app/Services/Audit/AuditBatchOrchestrator.php:274`; `app/Services/Audit/AuditBatchOrchestrator.php:275` |
| 118 | `AUDIT_BATCH_LOCK_TTL_SECONDS` | ACTIVA | TTL enviado al lock distribuido de generación de jobs. | `app/Services/Audit/AuditBatchOrchestrator.php:108`; `app/Services/Audit/AuditBatchOrchestrator.php:109` |
| 119 | `AUDIT_EVENT_MAX_RETRIES` | ACTIVA | Presupuesto de intentos antes de DLQ; valores menores a uno se corrigen a uno. | `app/Services/Audit/Pipeline/AuditEventConsumer.php:50`; `app/Services/Audit/Pipeline/AuditEventConsumer.php:636` |
| 120 | `AUDIT_STREAM_BLOCK_MS` | ACTIVA | Tiempo de bloqueo al leer streams; se acota a un mínimo de 100 ms. | `app/Services/Audit/Pipeline/AuditEventConsumer.php:51`; `app/Services/Audit/Pipeline/AuditEventConsumer.php:249` |
| 121 | `AUDIT_CONSUMER_LEASE_TTL_SECONDS` | SIN_EFECTO | Sin coincidencias en código/configuración operativa ni en .env.example. La lease durable usa una constante. F01. | `app/Services/Audit/Pipeline/AuditExecutionConfig.php:12`; `app/Services/Audit/Pipeline/AuditEventConsumer.php:184` |
| 122 | `AUDIT_DLQ_STREAM` | ACTIVA | Nombre del stream usado por el publicador de dead letters. | `app/Services/Audit/Pipeline/AuditEventPublisher.php:242`; `app/Services/Audit/Pipeline/AuditEventPublisher.php:73` |
| 123 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 124 | `AUDIT_CACHE_TTL` | SIN_EFECTO | Solo se lee en storeDispensationHash(), método sin callers del runtime actual; no afecta caché de queries. F04. | `core/Cache.php:216`; `core/Cache.php:218`; `app/Controllers/AuditController.php:178`; `app/Controllers/AuditController.php:300` |
| 125 | `AUDIT_EXTRACTION_CACHE_TTL` | ACTIVA | TTL pasado al gestor de resultados de extracción y a la escritura Redis. | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php:71`; `app/Services/Audit/Pipeline/DocumentExtractionWorker.php:81`; `app/Services/Audit/Pipeline/ExtractionCacheManager.php:99` |
| 126 | `AUDIT_BLOB_TTL_SECONDS` | SIN_EFECTO | Sin lector ni registro en .env.example. La caché BLOB usa DEFAULT_TTL_SECONDS salvo inyección por constructor. F01. | `app/Services/Audit/Pipeline/AttachmentDownloadWorker.php:37`; `app/Services/Audit/Pipeline/AttachmentDownloadWorker.php:40`; `app/Services/Audit/Pipeline/AuditDocumentCache.php:14` |
| 127 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 128 | `RUN_REDIS_INTEGRATION` | SOLO_PRUEBAS | Gate de suites Redis, no del backend. PHPUnit no carga automáticamente el .env raíz; CI establece esta clave aparte. F14. | `tests/Integration/AuditDocumentCacheRedisTest.php:20`; `phpunit.xml:4`; `.github/workflows/ci.yml:146` |
| 129 | Línea vacía | ESTRUCTURAL | Separador vacío. No requiere consumidor. | — |
| 130 | `AUDIT_VERSION_EXTRACTOR` | ACTIVA | Metadato de eventos y componente de la versión usada por caché de extracción. | `app/Services/Audit/Pipeline/AuditEvent.php:110`; `app/Services/Audit/Pipeline/DocumentExtractionWorker.php:74`; `app/Services/Audit/Pipeline/DocumentExtractionWorker.php:81` |
| 131 | `AUDIT_VERSION_NORMALIZER` | ACTIVA | Etiqueta de trazabilidad serializada en eventos; no cambia la implementación del normalizador. | `app/Services/Audit/Pipeline/AuditEvent.php:111`; `app/Services/Audit/Pipeline/AuditEvent.php:202` |
| 132 | `AUDIT_VERSION_RULES` | ACTIVA | Etiqueta de trazabilidad serializada en eventos; no selecciona otro motor de reglas. | `app/Services/Audit/Pipeline/AuditEvent.php:112`; `app/Services/Audit/Pipeline/AuditEvent.php:203` |
| 133 | `AUDIT_STREAM_MAXLEN` | ACTIVA | Meta de retención de streams; trabajo respeta consumidores/PEL y DLQ no se poda con ese valor. | `app/Services/Audit/Pipeline/AuditEventPublisher.php:53`; `app/Services/Audit/Pipeline/AuditEventPublisher.php:102`; `app/Services/Audit/Pipeline/AuditEventPublisher.php:73` |
| 134 | `PHP_CLI_MEMORY_LIMIT` | ACTIVA | Fija memoria del launcher CLI y también del INI global de la imagen, incluido FPM. El nombre no limita su alcance al CLI. F15. | `bin/audit-worker.php:13`; `bin/audit-worker.php:15`; `docker/docker-entrypoint.sh:7` |

## Verificaciones y reproducción segura

Se inspeccionaron 505 archivos de texto del repositorio para el inventario inicial, con separación entre código, scripts, workflows, documentación y pruebas. Dependencias, cachés, build outputs y secretos quedan fuera del corpus de consumidores. Después se siguieron los lectores dinámicos y los callers. Una mención en README, plantilla o workflow de escritura no se aceptó como prueba de uso en runtime.

Resultados de las comprobaciones efectivamente ejecutadas:

| Comprobación | Resultado | Alcance |
|---|---|---|
| Cobertura física del archivo | 134/134 líneas; 118/118 variables únicas | Sin valores en artefactos |
| Claves frente a .env.example | Dos ausentes: las huérfanas de F01 | Comparación por nombres |
| Resolución dinámica DB/DB2 | 16/16 comprobaciones positivas | Marcadores sintéticos por reflexión, sin abrir PDO |
| Configuración Gemini y MCP | 11/11 comprobaciones positivas | Precedencia, omisión de opcionales y timeout; sin HTTP |
| Cargador instalado de Next.js | Cero archivos cargados; siete claves ausentes antes/después | Directorio frontend, sin variables heredadas para esas claves |
| Compose resuelto con frontend y docs | Seis claves públicas ausentes del frontend; URL interna literal; APP_ENV recibido en PHP | Config renderizada, sin iniciar/recrear servicios |

Los 27 checks PHP confirman la lectura/precedencia descrita, incluidos comportamientos defectuosos; no significan que los 15 hallazgos estén resueltos. Los resultados sanitizados y las huellas de los archivos citados están en `auditoria-env-2026-10-22-evidencia.json`. La matriz también se entrega en CSV UTF-8 con separador punto y coma.

Para repetir las búsquedas negativas, desde la raíz, sin leer `.env` ni imprimir secretos:

```powershell
rg -n --hidden -g '!*.md' -g '!*.mmd' -g '!node_modules' -g '!.next' -g '!*.tsbuildinfo' 'AUDIT_CONSUMER_LEASE_TTL_SECONDS|AUDIT_BLOB_TTL_SECONDS' app core bin public frontend scripts docker .github docker-compose.yml
rg -n 'storeDispensationHash\(|hasDispensationChanged\(' app core bin public
rg -n 'AUDIT_WORKER_EXTRACTION_REPLICAS|AUDIT_WORKER_EXTRACTION_VIP_REPLICAS|AUDIT_WORKER_EXTRACTION_BATCH_REPLICAS' docker-compose.yml .github/workflows/deploy-production.yml
rg -n 'WEBHOOK_URL|CAPABILITIES_URL|AUDFACT_API_PUBLIC_URL|AUDFACT_FRONTEND_PUBLIC_URL|AUDIT_INTERNAL_API_BASE' app core bin public frontend scripts docker .github docker-compose.yml -g '!node_modules' -g '!.next' -g '!*.tsbuildinfo'
rg -n 'generationOverridesFromEnv|thinkingLevel|thinkingBudget|array_merge' app/Services/Audit/GeminiConfig.php app/Services/Audit/Pipeline/DocumentExtractionWorker.php app/Services/Audit/SemanticMatchJudge.php app/Services/Audit/SemanticBatchContract.php
```

La primera búsqueda debe devolver cero coincidencias y código de salida 1. La segunda devuelve solo definiciones y un texto de log, sin llamadas desde la aplicación. Las búsquedas negativas prueban ausencia en el corpus inspeccionado, no ausencia de consumidores externos o de invocaciones construidas fuera del repositorio.

Para comprobar una referencia puntual sin volcar archivos de entorno, por ejemplo:

```powershell
rg -n 'sqlsrv:Server|resolveConnectionConfig' core/Database.php
rg -n 'INTERNAL_API_URL|NEXT_PUBLIC_|env_file' docker-compose.yml
```

No ejecutar `docker compose config` sin filtrar su salida: el documento completo contiene el entorno y puede exponer credenciales. En esta auditoría se capturó en memoria y solo se guardaron indicadores booleanos. No se validaron servicios en producción; una imagen antigua podría tener un contrato diferente del código revisado.

## Prioridad de remediación

1. Conectar el contrato frontend (F05) y alinear los límites/timeout de batch con su comportamiento anunciado (F11/F12).
2. Eliminar o conectar las claves huérfanas, métodos sin callers y validaciones sin efecto (F01/F02/F03/F04/F07/F08), confirmando posibles consumidores externos de los metadatos MCP.
3. Unificar precedencia Gemini y fuentes de URLs públicas (F06/F09/F10/F13).
4. Mantener explícitas las condiciones de build, entorno y testing, y el alcance compartido del límite PHP (F14/F15).

Este reporte propone acciones; ningún hallazgo de runtime se declara corregido. No se crearon ni modificaron variables, secretos o valores remotos.

## Registro documental de la auditoría

Cambio: reporte verificable del archivo local. Archivos de entrega: este Markdown, su CSV y el JSON de evidencia. Se registra la auditoría en `plans/changelog.md`. Las matrices siguientes reflejan el alcance documental; no se alteró comportamiento de la aplicación.

| Documento | ¿Requirió cambio? | Razón | Estado |
|---|---|---|---|
| Reporte Markdown, CSV y evidencia JSON | Sí | Entregables solicitados y trazabilidad de todas las líneas | Hecho |
| plans/changelog.md | Sí | Registro documental de esta revisión | Hecho |
| plans/api-endpoints.md | No | No cambió ningún endpoint | N/A |
| plans/architecture.md | No | No cambió la arquitectura | N/A |
| AGENTS.md y .env.example | No | No se crearon/modificaron variables ni se implementaron remediaciones | N/A |
| README.md | No | No cambió instalación o funcionalidad | N/A |

| Skill / registro | ¿Requirió cambio? | Razón | Estado |
|---|---|---|---|
| audfact-runtime-docker | Sí | Corregir contexto preexistente: versión PHP y defaults de réplicas, y nota v2 contradictoria | Hecho |
| audfact-project-overview | Sí | Corregir defaults de réplicas de Compose en el contexto consultado | Hecho |
| CATALOG.md | No | El mapeo vigente plans/* ya cubre el reporte; no se crearon dominios ni skills | N/A |
| audfact-api-rest / audfact-audit-gemini | No | Los hallazgos se documentan; no se implementaron cambios de contratos | N/A |

He modificado los entregables de auditoría y su registro documental. Según la Matriz de Impacto Documental, los documentos afectados son el reporte, sus anexos y plans/changelog.md. Según la Matriz de Impacto en Skills, solo se ajusta el contexto preexistente de audfact-runtime-docker y audfact-project-overview.

Validaciones finales: cobertura de 134 líneas y 118 claves comprobada en Markdown, CSV y JSON; 251 referencias de código verificadas y 58 huellas SHA-256 coincidentes; cero valores sensibles detectados en los entregables; 27 comprobaciones PHP correctas; configuración Compose y carga Next.js verificadas; `validate-skills.mjs` aprobado (22 skills, 9 bundles); `git diff --check` aprobado; build Docusaurus aprobado con el reporte incluido. La compilación emitió avisos no bloqueantes de Node y del actualizador de Docusaurus.
