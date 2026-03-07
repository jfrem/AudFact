## [2026-03-07]

### Tipo (fix)
- **Ambito**: Compatibilidad de tests de persistencia con PHPUnit 10.
  - Archivos modificados: `tests/Services/Audit/AuditPersistenceServiceTest.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se reemplazó `withConsecutive()` por callbacks con aserciones por invocación para evitar errores de método eliminado en PHPUnit 10 y destrabar la etapa de `lint` en CI.

### Tipo (infra)
- **Ambito**: Trazabilidad del workspace en deploy self-hosted para diagnóstico de checkout.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el job de despliegue ahora imprime `GITHUB_WORKSPACE`, `pwd` y listado del workspace real para validar en logs dónde se realiza el checkout durante la ejecución.

### Tipo (security)
- **Ambito**: Endurecimiento del pipeline de despliegue en runner self-hosted.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el `.env` ahora se crea con permisos `600`, el deploy queda serializado con `concurrency` para evitar ejecuciones simultáneas, y el paso de purga incorpora guardas para reducir riesgo de borrados fuera del workspace.

## [2026-03-06]

### Tipo (infra)
- **Ambito**: Corrección del pipeline de despliegue para preservar directorios compartidos vitales.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: El paso `Zero-Source Host Purge` purgaba la carpeta `docker/` y `public/`, los cuales son montados como binarios o plantillas durante la recreación del contenedor provocando un bloqueo silencioso al fallar el entrypoint.
  - Impacto: Los contenedores volverán a levantar post-merge automáticamente y el `healthcheck` pasará de nuevo asegurando un CD funcional.

### Tipo (fix)
- **Ambito**: Inclusión de facturas auditadas con errores en la respuesta a pesar de tener un registro en `AudDispEst`.
  - Archivos modificados: `app/Models/InvoicesModel.php`, `CHANGELOG.md`
  - Hallazgo resuelto: Las facturas que ya tenían un intento de auditoría fallido no volvían a aparecer en el listado de "pendientes" porque el cruce `INNER JOIN` (implícito) omitía los registros. 
  - Impacto: Se cambió el cruce restrictivo por un `LEFT JOIN` a `AudDispEst` permitiendo procesar o re-procesar facturas que sufrieron errores previos (e.g. "Adjunto supera el máximo", fallos de Gemini).

## [2026-03-05]

### Tipo (refactor)
- **Ambito**: Blindaje de gobernanza de skills para agentes (skill-gate estricto + triggers + sync de compatibilidad).
  - Archivos modificados: `AGENTS.md`, `.agent/skills/CATALOG.md`, `CLAUDE.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se fuerza detección/carga de skill antes de análisis técnico, se añade checklist pre-ejecución y se reducen omisiones por ambigüedad con triggers por skill.

### Tipo (infra)
- **Ambito**: Endurecimiento de Despliegue en Producción (Lean Production 3.0).
  - Archivos modificados: `docker-compose.yml`, `.dockerignore`, `.github/workflows/ci.yml`, `docker/nginx.Dockerfile`, `docker/Dockerfile`
  - Hallazgo resuelto: Archivos de desarrollo, repositorios .git y herramientas de auditoría se estaban filtrando u hospedando innecesariamente en el servidor de Producción.
  - Impacto: El host es ahora **Zero-Source** (purgado post-deploy). Nginx es un **bundle inmutable** con assets integrados (sin bind mount). PHP purga artefactos de orquestación en build. Sincronización de timeouts Nginx/FPM para procesos IA largos (3600s).

### Tipo (fix)
- **Ambito**: Endurecimiento de sintaxis YAML en `ci.yml` para generación dinámica de `.env`.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: Error de sintaxis de heredoc Bash / YAML en Runner.
  - Impacto: El heredoc que genera el `.env` retira los bloqueos de parsing para asegurar inyección de variables dinámicas al tiempo que provee despliegue fluido a los Runner Nodes.

### Tipo (refactor)
- **Ambito**: Estandarización de variables de entorno para BD de consulta usando solo prefijo `DB2_*` (sin alias `SECONDARY_DB_*`).
  - Archivos modificados: `core/Database.php`, `app/Models/Model.php`, `.env.example`, `AGENTS.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la conexión de lectura de modelos usa exclusivamente `DB2_*` con conexión nombrada `db2`; se elimina la dependencia de aliases `SECONDARY_DB_*`.

## [2026-03-04]

### Tipo (refactor)
- **Ambito**: Enrutamiento por tipo de sentencia en capa de modelos: lecturas en `secondary` (`DB2_*` fallback) y escrituras en `default`.
  - Archivos modificados: `app/Models/Model.php`, `app/Models/AuditStatusModel.php`, `.env.example`, `AGENTS.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: todas las consultas de modelos pasan a BD secundaria y las operaciones de escritura (`INSERT/UPDATE/DELETE/MERGE`) se ejecutan en BD principal sin duplicar lógica de conexión.

### Tipo (refactor)
- **Ambito**: Enrutamiento centralizado de modelos a conexiones nombradas con caché PDO aislado por fingerprint de configuración.
  - Archivos modificados: `core/Database.php`, `app/Models/Model.php`, `.env.example`, `AGENTS.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: `Database` evita mezclar conexiones por colisión de caché al cachear por configuración efectiva y mantiene fallback legado `DB2_*` para `secondary`.

### Tipo (refactor)
- **Ambito**: Soporte multi-BD por modelo con conexión nombrada `secondary` y compatibilidad de prefijos legados.
  - Archivos modificados: `core/Database.php`, `app/Models/Model.php`, `.env.example`, `AGENTS.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: cada modelo puede seleccionar su conexión (`default` o `secondary`) mediante propiedad interna; `Database` elimina colisiones de caché y acepta configuración `SECONDARY_DB_*` con fallback a `DB2_*`.

### Tipo (fix)
- **Ambito**: Hardening de logging en runtime para evitar warnings por permisos en `logs/` durante despliegues remotos.
  - Archivos modificados: `core/Logger.php`, `docker/docker-entrypoint.sh`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: cuando el path primario de logs no es escribible, el logger usa fallback en `/tmp/audfact-logs` y el endpoint HTTP evita exponer warnings; el entrypoint reporta en arranque si `logs/` no es escribible por `www-data`.

### Tipo (fix)
- **Ambito**: Logging robusto de producción orientado a contenedor para eliminar dependencia de permisos en `./logs`.
  - Archivos modificados: `core/Logger.php`, `docker-compose.yml`, `docker-compose.ha.yml`, `docker/docker-entrypoint.sh`, `README.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: en `APP_ENV=production` los logs se emiten por `stderr`, por lo que `/health` y endpoints no exponen warnings de `file_put_contents`; en dev se mantiene logging a archivos con rotación.

### Tipo (fix)
- **Ambito**: Alineación de perfiles Docker Compose para UID/GID parametrizable en build de PHP.
  - Archivos modificados: `docker-compose.ha.yml`, `docker-compose.dev.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: los perfiles `ha` y `dev` ahora usan los mismos build args `WWWUSER_ID/WWWGROUP_ID` que el compose base, evitando diferencias de permisos/ownership entre entornos.

## [2026-03-03]

### Tipo (fix)
- **Ambito**: Hardening del deploy para estabilidad de runtime en self-hosted runner.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el deploy ahora asegura permisos de `logs/` para rate limiter/logger, configura `safe.directory` para Composer en contenedor, valida existencia de `vendor/autoload.php` y usa `GET /health` como check funcional.

### Tipo (security)
- **Ambito**: Endurecimiento del deploy en runner self-hosted para generar `.env` desde GitHub Secrets.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: cada deploy ahora crea `.env` con secretos requeridos antes de `docker compose up`, evitando arranques con variables demo o ausencia total del archivo.

### Tipo (fix)
- **Ambito**: Endurecimiento del deploy CI/CD para garantizar dependencias PHP en runtime con bind mount activo.
  - Archivos modificados: `.github/workflows/ci.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el job `deploy` ahora ejecuta `composer install` dentro del contenedor `php` despues de `docker compose up --build -d`, evitando el error fatal por `vendor/autoload.php` inexistente en servidor.

### Tipo (feat)
- **Ambito**: Creacion de nuevo CLI en PHP (Symfony Console) en directorio `cli/` con modo interactivo, presets y soporte multi-base de datos.
  - Archivos modificados: `cli/composer.json`, `cli/bin/php-init`, `cli/src/Application.php`, `cli/src/Command/NewProjectCommand.php`, `cli/src/Command/MakeControllerCommand.php`, `cli/src/Command/MakeModelCommand.php`, `cli/src/Command/MakeMiddlewareCommand.php`, `cli/src/Command/MakeCrudCommand.php`, `cli/src/Command/ListRoutesCommand.php`, `cli/src/Command/DbMigrateCommand.php`, `cli/src/Command/DbFreshCommand.php`, `cli/src/Command/InitDockerCommand.php`, `cli/src/Support/ProjectScaffolder.php`, `cli/src/Support/ScaffoldTemplates.php`, `cli/src/Support/SafeWriter.php`, `cli/src/Support/NameSanitizer.php`, `cli/src/Support/EnvReader.php`, `cli/src/Support/ProjectContext.php`, `cli/README.md`, `plans/TODO/checkpoints/cli-migration-20260302-215142.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se habilita una base de migracion desde el CLI Node.js hacia PHP con comandos `new`, `make:*`, `list:routes`, `db:migrate`, `db:fresh` e `init:docker`, generando scaffolding agnostico de dominio.

### Tipo (feat)
- **Ambito**: Expansion del flujo interactivo de `new` con configuracion avanzada y post-acciones.
  - Archivos modificados: `cli/src/Command/NewProjectCommand.php`, `cli/src/Support/ProjectScaffolder.php`, `cli/src/Support/ScaffoldTemplates.php`, `cli/src/Support/NameSanitizer.php`, `cli/src/Command/MakeCrudCommand.php`, `cli/README.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el comando `new` ahora soporta `APP_ENV`, `ALLOWED_ORIGINS`, expiraciones JWT, generacion opcional de tests, y ejecucion opcional de `composer install` / `db:migrate`; ademas `make:crud` corrige generacion integral de modelo+controlador+rutas.

### Tipo (feat)
- **Ambito**: Preparacion de `cli/` como repositorio independiente listo para GitHub.
  - Archivos modificados: `cli/.gitignore`, `cli/LICENSE`, `cli/CHANGELOG.md`, `cli/README.md`, `cli/composer.json`, `cli/.github/workflows/ci.yml`, `cli/.github/workflows/release-drafter.yml`, `cli/.github/release-drafter.yml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el modulo CLI queda con metadatos de publicacion, CI automatizado, y release draft para ciclo de entrega independiente.

### Tipo (refactor)
- **Ambito**: Mejora del template de validacion para nuevos scaffolds generados por el CLI.
  - Archivos modificados: `cli/src/Support/ScaffoldTemplates.php`, `cli/CHANGELOG.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: `core/Validator.php` generado ahora soporta reglas mas completas (`nullable`, `string`, `email`, `numeric`, `integer`, `boolean`, `alpha`, `date`, `in`, `min/max`, `min_length/max_length`, `min_value/max_value`) con mensajes consistentes.

## [2026-03-02]

### Tipo (feat)
- **Ambito**: Instalacion del framework de auditoria tecnica por dominios en `.agent/skills`.
  - Archivos modificados: `.agent/skills/_shared/*`, `.agent/skills/audit-skill-router/*`, `.agent/skills/architecture-assessment/*`, `.agent/skills/code-quality-assessment/*`, `.agent/skills/security-assessment/*`, `.agent/skills/technical-governance-assessment/*`, `.agent/skills/README.md`, `.agent/skills/SMOKE-TESTS.md`, `.agent/skills/CATALOG.md`, `.agent/skills/catalog.json`, `AGENTS.md`, `CLAUDE.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: el repositorio ahora tiene routing de auditorias y evaluaciones especializadas con scoring determinista, evidencia obligatoria y salida estructurada.

## [2026-03-02]

### Tipo (refactor)
- **Ambito**: Endurecimiento de gobernanza para auditorías técnicas con skill gate obligatorio.
  - Archivos modificados: `AGENTS.md`, `CLAUDE.md`, `.agent/skills/project-audit-framework/SKILL.md`, `.agent/skills/CATALOG.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: toda solicitud de auditoría/review/evaluación queda forzada a usar `project-audit-framework` con fases 0-6, scoring ponderado, clasificación global y plan 30/60/90.

### Tipo (refactor)
- **Ambito**: Estandarización de metadata OpenAI para la skill de auditoría.
  - Archivos modificados: `.agent/skills/project-audit-framework/agents/openai.yaml`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la skill `project-audit-framework` queda alineada con el formato `agents/openai.yaml` usado por el resto de skills del repositorio.

## [2026-02-28]

### Tipo (refactor)
- **Ambito**: Rotacion real de logs por tamaño con backups numerados.
  - Archivos modificados: `core/Logger.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: cuando un archivo alcanza `LOG_MAX_SIZE_MB`, ahora se rota a `app-...log.1` (hasta `.5`) en lugar de truncarse, preservando trazabilidad del mismo dia.

### Tipo (security)
- **Ambito**: Xdebug condicional por entorno de build Docker.
  - Archivos modificados: `docker/Dockerfile`, `docker-compose.yml`, `docker-compose.dev.yml`, `docker-compose.ha.yml`, `README.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: imágenes `HA/prod` construyen con `ENABLE_XDEBUG=0` (sin Xdebug en runtime), mientras `dev` mantiene `ENABLE_XDEBUG=1` para depuración local.

### Tipo (fix)
- **Ambito**: Corrección de manejo de excepciones para bloqueo CORS en bootstrap.
  - Archivos modificados: `public/index.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: solicitudes con `Origin` no permitido ahora responden JSON `403` consistente (incluyendo `OPTIONS`), evitando salida HTML/fatal con código `200`.

### Tipo (security)
- **Ambito**: Hardening de bootstrap HTTP para configuracion productiva.
  - Archivos modificados: `public/index.php`, `.env.example`, `README.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: CORS en produccion valida allowlist estricta (`ALLOWED_ORIGINS`) y rechaza origenes no permitidos con `403`, se agregan headers base de seguridad y se elimina bypass silencioso ante fallos del rate limiter.

### Tipo (fix)
- **Ambito**: Ajuste de cadena SQL Server para evitar fallo TLS con certificado autofirmado en ODBC 18.
  - Archivos modificados: `core/Database.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la conexión PDO `sqlsrv` usa `Encrypt=no;TrustServerCertificate=yes`, eliminando el error `certificate verify failed:self-signed certificate` en runtime Docker local.

### Tipo (fix)
- **Ambito**: Reconstruccion Docker compatible con PHP 8.2 para drivers SQL Server.
  - Archivos modificados: `docker/Dockerfile`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: `docker compose up --build -d` vuelve a completar el build de `audfact-php` fijando `sqlsrv/pdo_sqlsrv` a `5.11.1` por URL directa de PECL, evitando resolver releases incompatibles con PHP 8.2.

## [2026-02-27]

### Tipo (refactor)
- **Ambito**: Unificacion de validacion de query/body en controladores para consultas de auditoria.
  - Archivos modificados: `app/Controllers/Controller.php`, `app/Controllers/AuditController.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: `GET /audit/results` deja validaciones manuales y usa `Validator` central mediante `validateQuery()`, con reglas consistentes, paginacion validada y saneo uniforme de query params.

### Tipo (fix)
- **Ambito**: Alineación de documentación de endpoints con el router real (sin prefijo `/api`).
  - Archivos modificados: `README.md`, `plans/data-flows.md`, `plans/features/mcp-integration.md`, `plans/overview.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se corrigen tablas y flujos operativos para usar rutas reales (`/clients`, `/invoices`, `/audit`, `/audit/single`, etc.) y base URL local `http://localhost:8080`.

### Tipo (refactor)
- **Ambito**: Expansión de tipado estricto en capas core/controllers/models (fase segura).
  - Archivos modificados: `core/Router.php`, `core/Route.php`, `core/Middleware.php`, `core/Response.php`, `app/Controllers/Controller.php`, `app/Controllers/ClientsController.php`, `app/Controllers/InvoicesController.php`, `app/Controllers/DispensationController.php`, `app/Controllers/HealthController.php`, `app/Controllers/ConfigController.php`, `app/Models/Model.php`, `app/Models/ClientsModel.php`, `app/Models/InvoicesModel.php`, `app/Models/DispensationModel.php`, `app/Models/AttachmentsModel.php`, `app/Models/AuditStatusModel.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: mayor seguridad de tipos en tiempo de ejecución y mejor mantenibilidad sin cambio funcional de endpoints.

### Tipo (fix)
- **Ambito**: Endurecimiento del enrutador para no aceptar parámetros vacíos en segmentos requeridos.
  - Archivos modificados: `core/Router.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: rutas con parámetros dinámicos obligatorios ahora requieren al menos un carácter (`+`), evitando matcheos inválidos y validaciones tardías.

### Tipo (security)
- **Ambito**: Hardening de TLS para integración con Google Drive.
  - Archivos modificados: `app/Services/GoogleDriveAuthService.php`, `.env.example`, `AGENTS.md`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la verificación TLS en conexiones HTTPS de Google Drive queda habilitada por defecto y controlada por `GOOGLE_DRIVE_TLS_VERIFY` (solo desactivable en desarrollo controlado).

### Tipo (fix)
- **Ambito**: Health check funcional con estado global real en lugar de valor fijo.
  - Archivos modificados: `app/Controllers/HealthController.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: `GET /health` ahora reporta `healthy` solo cuando la BD responde y retorna detalle estructurado por servicio (`status`, `message`, `latency_ms`), evitando falsos positivos.

### Tipo (security)
- **Ambito**: Sanitización de logs del pipeline Gemini para evitar exposición de contenido sensible en trazas operativas.
  - Archivos modificados: `app/Services/Audit/AuditOrchestrator.php`, `app/Services/Audit/AuditResultValidator.php`, `core/Logger.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se eliminan logs de contenido crudo/parseado de Gemini y se reemplazan por métricas técnicas seguras (longitud de respuesta, tipo de respuesta, cantidad de items, intentos).

### Tipo (refactor)
- **Ambito**: Retiro de fachada `GeminiAuditService` y consumo directo de `AuditOrchestrator` desde el controlador de auditoría.
  - Archivos modificados: `app/Controllers/AuditController.php`, `app/worker/GeminiAuditService.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se elimina capa legacy de compatibilidad y se mantiene el comportamiento funcional de `/audit` y `/audit/single` delegando directamente en la nueva arquitectura de servicios.

### Tipo (refactor)
- **Ambito**: Desacople del pipeline de auditoría Gemini para reducir acoplamiento y facilitar escalabilidad.
  - Archivos modificados: `app/worker/GeminiAuditService.php`, `app/Services/Audit/AuditOrchestrator.php`, `app/Services/Audit/GeminiGateway.php`, `app/Services/Audit/AuditPersistenceService.php`, `app/Services/Audit/AuditTelemetryService.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: `GeminiAuditService` queda como fachada de compatibilidad y la lógica se distribuye por responsabilidades (orquestación, gateway HTTP, persistencia y telemetría) sin cambiar el contrato de `POST /audit` y `POST /audit/single`.

### Tipo (refactor)
- **Ambito**: Estandarizacion de configuracion de entorno y cliente HTTP interno para MCP.
  - Archivos modificados: `app/Controllers/Controller.php`, `app/Services/GoogleDriveAuthService.php`, `app/wrap/core/ApiClient.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se elimina uso directo de `getenv()` en modulos clave y `ApiClient` migra de cURL a Guzzle manteniendo contrato de respuesta.

## [2026-02-26]

### Tipo (fix)
- **Ambito**: Correccion del retorno en `getSystemInstruction` para evitar `TypeError` por retorno `null`.
  - Archivos modificados: `app/Services/Audit/AuditPromptBuilder.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la auditoria individual ya no falla al construir la instruccion de sistema y puede continuar al llamado de Gemini.

### Tipo (fix)
- **Ambito**: Bloqueo de adjuntos con mas de 2 paginas antes de invocar Gemini y persistencia de estado en BD.
  - Archivos modificados: `app/Services/Audit/AuditFileManager.php`, `app/worker/GeminiAuditService.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: cuando un adjunto excede 2 paginas se aborta la auditoria y queda persistido `Adjunto supera el maximo de páginas permitidas` en `AudDispEst`.

### Tipo (fix)
- **Ambito**: Correccion de severidad por defecto en respuestas de error para evitar persistencia con `Severidad=ninguna`.
  - Archivos modificados: `app/worker/GeminiAuditService.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: inconsistencias de negocio (como MIPRES incompleto) quedan persistidas con severidad alta en BD.

### Tipo (fix)
- **Ambito**: Abort early para dispensaciones `MIPRES` con campos obligatorios vacios antes de invocar Gemini.
  - Archivos modificados: `app/worker/GeminiAuditService.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: se evita auditoria IA invalida y se persiste resultado de error en `AudDispEst` y `responseIA`.

### Tipo (fix)
- **Ambito**: Alineacion del schema de auditoria para incluir bloques `metrics` y `config_used` en salida de Gemini y validacion interna.
  - Archivos modificados: `app/Services/Audit/AuditResponseSchema.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: la API ahora exige y expone metrica estructurada y configuracion efectiva de riesgo en la respuesta de auditoria.

## [2026-02-23]

### Tipo (fix)
- **Ambito**: Correccion de previsualizacion de adjuntos para evitar respuesta JSON mezclada con stream binario.
  - Archivos modificados: `app/Controllers/AttachmentsController.php`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: los botones "Previsualizar" en auditoria individual y por lote vuelven a abrir el documento sin error.

## [2026-02-23]

### Tipo (fix)
- **Ambito**: Restauracion de `envsubst` en imagen PHP para evitar reinicios por entrypoint.
  - Archivos modificados: `docker/Dockerfile`, `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: `docker-entrypoint.sh` vuelve a generar `www.conf` y el contenedor `php` deja de reiniciar con codigo 127.

## [2026-02-23]

### Tipo (refactor)
- **Ambito**: Limpieza de archivos legacy y temporales no referenciados tras separacion dev/ha.
  - Archivos modificados: `CHANGELOG.md`
  - Hallazgo resuelto: ninguno
  - Impacto: menor ruido en repositorio sin afectar runtime.

## [2026-02-23]

### Tipo (refactor)
- **Ambito**: Separacion explicita del modo HA en archivo dedicado.
  - Archivos modificados: `docker-compose.ha.yml`, `README.md`
  - Hallazgo resuelto: ninguno
  - Impacto: operacion mas clara con perfiles separados para desarrollo y stress/HA.

## [2026-02-23]

### Tipo (refactor)
- **Ambito**: Separacion de entorno de desarrollo con `docker-compose.dev.yml` para ejecucion estable (1 php + 1 nginx).
  - Archivos modificados: `docker-compose.dev.yml`, `README.md`
  - Hallazgo resuelto: ninguno
  - Impacto: flujo local mas predecible y healthcheck PHP simplificado para evitar falsos `unhealthy`.

## [2026-02-23]

### Tipo (fix)
- **Ambito**: Correccion de plantilla Nginx HA para evitar fallo de arranque por sintaxis invalida.
  - Archivos modificados: `docker/nginx-ha.conf.template`
  - Hallazgo resuelto: ninguno
  - Impacto: `audfact-nginx` inicia correctamente y vuelve a publicar `:8080`.
