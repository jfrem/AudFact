# Ajustes de confiabilidad para lotes de alto volumen

Fecha: 2026-10-08. Estrategia: **refactorización incremental**.
Fuente del alcance: solicitud del usuario de aplicar ajustes considerando lotes
productivos grandes y diagnóstico HTTP/SSH del 2026-10-08.
Estado: cambios locales implementados; despliegue y medición productiva pendientes.

## Diagnóstico que fundamenta el cambio

El corte productivo de las 08:43 America/Bogota registró 11.260 entradas DLQ.
11.089 correspondían a BLOB ausente en extracción: 98,48% de la DLQ.
La espera entre descarga y cierre fallido fue de 3.619 a 5.651 segundos,
superior al TTL fijo de 3.600 segundos. Redis reportó cero evicciones.
Este mecanismo explica las fallas por caducidad; no acredita que SQL sea el
cuello principal del lote ni que existan respuestas Gemini HTTP 429 en la DLQ.

El backlog observado sumaba 89.606 tareas de grupos: policy 67.478,
extracción 21.922 y otras etapas 206. Son mensajes/grupos, no documentos únicos.
Los tres jobs iniciados a las 06:00 sumaban 6.312 auditorías, cero completadas,
80 fallidas y 6.232 pendientes. Estos números pertenecen al corte histórico;
no representan una lectura realizada después de implementar los cambios.

## Alcance y contratos

El cambio elimina la dependencia de la extracción respecto de la vida del BLOB
cacheado, acota su memoria global, reduce trabajo ajeno en consumidores,
conserva eventos pendientes y permite un reproceso DLQ que sí ejecuta trabajo.

Se conservan carriles priority/batch, referencias `audit:blob:*`, SHA256 de
la representación base64, identidad DisId/DisDetNro, contratos de extracción,
hallazgos, rechazo documental y entrega de `rules_evaluated` al scheduler SQL.
Los constructores conservan sus argumentos anteriores; `blobTtl` sigue siendo
un argumento activo de `AttachmentDownloadWorker`. BLOBs y eventos en vuelo
producidos por la versión anterior pueden consumirse por extractores nuevos.

El protocolo predeterminado v1 conserva HTTP semántico dentro de policy.
El diff también implementa v2: scheduler justo de cuatro etapas, agrupación
por ondas, cuotas compartidas, fencing, artifacts y outbox. V2 permanece
desactivado en configuración local/remota hasta verificar cuotas/corpus/
restauración/canary. Ver [contrato y evidencia v2](sdd-homologacion-semantica-asincrona-ejecucion-justa.md).
Ninguna de las dos entregas promete una duración productiva por lote.

## Comportamiento implementado

| Componente | Cambio | Evidencia ejecutable |
| --- | --- | --- |
| `AuditDocumentCache` | Lua atómico compartido entre réplicas; 256 MiB de JSON y 1024 entradas por defecto; TTL 3600 s; capacidad agotada retorna bypass | Pruebas de Redis con 3000 admisiones y cuatro procesos independientes |
| `AuditDocumentInput` | Cache miss recupera el adjunto original durante el turno del extractor; exige el hash esperado antes de usarlo | Caché, expiración, fuente modificada, transferencia fallida y fallo Redis |
| Downloader/extractor | Evento conserva fuente/hash y agrega `blob_cache_stored`; extracción/rechazo publicado libera bytes | Pruebas contractuales de workers |
| Reentrega de extracción | Reutiliza extracción persistida con hashes de documento/contrato/prompt idénticos; evita descarga/Gemini adicionales | Publicación interrumpida en estados extracted/normalized/evaluated |
| `AuditEventConsumer` y siete implementadores | `eventTypes()` obligatorio; ACK de tipos ajenos sin lecturas de recibos/estado ni telemetría de ejecución | Pruebas de dispatch y suites de los implementadores |
| Estados terminales | Cierre DLQ recuperable se resuelve primero; luego se omite trabajo terminal ajeno a persistencia | Cuatro estados terminales y regresiones de slots |
| `AuditEventPublisher` | Streams de trabajo podan solo entradas anteriores al mínimo last-delivered-id y mínimo PEL de todos sus grupos; máximo 1000 por publicación | Redis real con consumidor lento, mensaje antiguo pendiente y stream sin grupos |
| Arranque de grupos | La poda espera a que existan todos los grupos obligatorios del stream; también conserva trabajo necesario por grupos adicionales | Redis real con solo downloaders creado y backlog mayor a la meta |
| DLQ | No aplica MAXLEN automático a evidencia no archivada | Redis real con DLQ mayor al límite configurado |
| `AuditStateStore` / API DLQ | Nueva raíz independiente por auditoría fallida; inicialización, recibo y XADD atómicos, con compensación ante fallo de publicación | Redis real con duplicados, cinco estados protegidos y WRONGTYPE |
| Deploy | Verifica capacidad de recuperación de la imagen y actualiza/verifica todos los extractores antes de cambiar productores | YAML y Bash validados localmente; despliegue no ejecutado |

### Presupuesto y límites

Los límites cuentan bytes del JSON almacenado, no la memoria total Redis.
Metadata, streams, resultados de extracción y estado de auditorías requieren
memoria adicional. BLOBs antiguos no están en el ledger nuevo; se liberan al
consumirlos o caducan. El presupuesto acota productores nuevos, no certifica
instantáneamente toda la memoria existente durante el cutover.

La admisión elimina como máximo 64 reservas vencidas. Si todavía no cabe una
entrada, el extractor recupera desde origen; no se acumula otra cola de BLOBs.
El ledger no expira y conserva un contador conservador hasta su siguiente poda.
Una extracción persistida se reutiliza al reentregar el evento aunque su BLOB
o fuente ya no estén disponibles; conserva resultado y métricas originales.
Liberar una entrada es idempotente. Redis indisponible/corrupto es un fallo
técnico y no se disfraza como caché llena. Fuente distinta o transferencia
incompleta tampoco produce un rechazo funcional ni un resultado inventado.

`AUDIT_STREAM_MAXLEN` es una meta, no un límite duro para trabajo pendiente.
Un grupo detenido puede mantener un stream mayor a esa meta. La retención no
sustituye dimensionamiento, monitoreo ni control de nuevas admisiones.
V1 conserva TTL de auditorías/jobs de siete días. V2 protege raíces, reservas,
snapshots, tareas y outbox activos sin TTL; GC retira terminales después del
horizonte máximo. Recuperar bytes no reconstruye un estado ya desaparecido.
La retención nueva evita pérdidas posteriores; no reconstruye eventos que
la retención anterior ya hubiera eliminado. Un PEL sin payload requiere
reconciliación del estado/manifiesto, sin afirmar que su trabajo terminó.
Los Lua multillave utilizan el primario Redis del despliegue actual;
Redis Cluster no está certificado por este pipeline.

## Contrato de reproceso DLQ

`POST /audit/dlq/reprocess` recibe el mismo `streamId` y responde HTTP 200 con
`audit_id`, `reprocessed_event_id`, `event_type=audit_created`,
`original_audit_id`, `original_job_id` y `stream_id` de la entrada DLQ solicitada.
El nuevo `audit_id` se consulta en `/audit/status/{auditId}`. El intento no
pertenece al job histórico: conserva su referencia, sin cambiar sus totales,
fallas ni estados terminales. Conserva el carril original mediante prioridad
explícita. Reconstruye toda la auditoría, incluso si falló persistencia.

Un recibo `audit:recovery:{original_audit_id}` deduplica las solicitudes y
distintas entradas DLQ de una misma auditoría durante `AUDIT_STATE_TTL`.
El intento fallido permanece intacto. Repetir la solicitud devuelve el mismo
intento, incluso después de completarse; no crea una cascada de recuperaciones.

Conflictos de estado activo/exitoso/ausente o falta de identidad generan 409.
Entrada DLQ inexistente genera 404; streamId/evento original inválidos, 422;
fallo Redis, 503 registrado sin datos clínicos. Los DLQ de generación de jobs
sin `audit_id` necesitan diagnóstico administrativo y no se republican a ciegas.

## Configuración remota

Se sincronizaron con `gh variable set --env production --repo jfrem/AudFact`:

| Variable | Valor |
| --- | ---: |
| `AUDIT_DOCUMENT_CACHE_MAX_BYTES` | 268435456 |
| `AUDIT_DOCUMENT_CACHE_MAX_ENTRIES` | 1024 |

El entorno tenía 100 variables. Para conservar capacidad se sustituyeron
`AUDIT_BLOB_TTL_SECONDS=86400` y `AUDIT_CONSUMER_LEASE_TTL_SECONDS=900`,
sin referencias en código, workflows ni skills: búsqueda `rg` en el repositorio,
incluidas `.github` y `.agent`. Esas claves tampoco figuraban en `.env.example`.
No se alteraron secretos, `.env` local ni contenedores productivos.

GitHub CLI intenta crear antes de actualizar: con el entorno lleno rechazó
`gh variable set` aunque la clave existía. Se renombraron las claves por API,
se retiró temporalmente una clave nueva y se completaron ambos `gh variable set`
con un espacio disponible. La lectura final verificó los valores anteriores.
El límite está documentado por [GitHub](https://docs.github.com/en/actions/reference/workflows-and-actions/variables).

## Rollout y rollback

1. Publicar una imagen inmutable por SHA con CI completo. Registrar el SHA
   anterior, tamaños de streams, lag/PEL, DLQ, memoria y contadores de jobs.
2. Usar el workflow: comprueba imagen, recrea primero extractores VIP/batch y
   verifica sus IDs de imagen y estado running. Luego recrea el resto del stack.
3. Verificar startup: instancia/PID, carril, streams, tipos, límites de caché,
   recuperación desde origen, tiempos y límites de reclaim. No bajar reclaim
   para acelerar la cola ni multiplicar réplicas sin medir proveedor y SQL.
4. Medir por 15 minutos: backlog por grupo, pendientes, nuevos errores DLQ,
   fuente modificada, memoria/evicciones Redis y ritmo de finalización. Criterio
   causal: documentos recuperados y ausencia de nuevos fallos por BLOB expirado;
   errores de fuente reales siguen siendo técnicos y visibles.
5. Reprocesar una auditoría fallida de muestra y verificar el nuevo ID, estado
   original intacto y resultado SQL. Luego recuperar el histórico con admisión
   controlada, una llamada en vuelo y observación de backlog entre tandas;
   no disparar miles de recuperaciones simultáneamente.

Rollback preferido: otra imagen que conserve recuperación de caché. Antes de
volver a una imagen sin `AuditDocumentInput`, cortar cron/ingesta y productores,
drenar trabajo de extracción/policy/persistencia y comprobar lag/PEL cero en
todos sus grupos. El workflow bloquea ese downgrade; restaurar el SHA anterior
requiere operación manual aprobada y evidencia de drenaje. Conservar Redis,
DLQ y resultados; no FLUSHALL, borrado de contadores ni reclasificación clínica.
No reactivar productores hasta verificar consumidores compatibles.

Si la fuente desaparece antes de un cache miss, el resultado es un fallo técnico.
Si se necesita conservar evidencia independiente del origen por períodos mayores,
se requiere almacenamiento durable de objetos y un manifiesto con hash;
eso pertenece a una migración diferente y no se simula elevando TTL.

## Validación y decisión

La evidencia numérica de esta sección corresponde a la primera entrega de
confiabilidad. El estado actual v2 se registra en §19 de su SDD y en testing;
esa ampliación conserva v1 bajo excepción E01 hasta retirar contratos activos.

- Suite completa PHP 8.2.12: **797 pruebas, 2993 aserciones, 35 omisiones**.
  Las omisiones corresponden a integraciones opt-in/limitaciones del runtime.
- Integración local PHP 8.2.33 + Redis 7: **34 pruebas, 281 aserciones**, todas pasan;
  incluye cuatro productores simultáneos, 3000 admisiones, retención y recuperación.
- Comandos: `php vendor/bin/phpunit --configuration phpunit.xml --no-coverage`;
  Docker Compose one-shot con mount de código de solo lectura, sin reiniciar
  servicios; namespaces únicos de integración y limpieza solo de sus claves.
- Lint: 182 archivos PHP sin errores. Contrato de entorno: 57 claves literales
  y dos claves documentales dinámicas completas. Workflow: YAML válido y ocho
  bloques Bash pasan `bash -n`. Build Docusaurus generado correctamente.
- Skills: el paquete registrado de 22 skills y 9 bundles pasa el validador
  oficial en una copia aislada. La validación global del workspace falla por
  tres skills preexistentes no registradas: `docker-build-strategies`,
  `docker-compose-patterns`, `docker-destructive-guardrails`. Sus directorios
  y `skills-lock.json` se conservan; no forman parte de esta entrega.

Decisión de diseño: **APROBAR** la refactorización incremental delimitada.
Contratos activos: eventos y BLOBs históricos, carriles, reglas, identidad y
slots SQL; el cambio semántico de reproceso está documentado en la API.
Compuertas: implementadores alineados, presupuesto atómico, errores visibles,
pruebas aisladas, startup estructurado y migración/rollback explícitos.
Excepciones nuevas a `clean-rebuild-policy`: **Ninguna**.
La aprobación local no acredita despliegue ni rendimiento productivo nuevo.
