# Reporte — Clean code del diff PHP

Fecha: 2026-11-09. Solicitud: aplicar clean code al diff PHP pensando en su mantenimiento futuro.

## Decisión

**APROBAR — refactorización incremental del diff local.**

**Alcance MVP:** conservar el CB en ejecución v2 y la rasterización por bloques,
corrigiendo regresiones introducidas en el diff. Fuente: solicitud del usuario y
SDD-CB-RASTER-002. No se realizó commit, push ni despliegue.

**Contratos activos:** firmas públicas del gateway y rasterizador, estructura
multimodal, límite de 50 páginas, taxonomía de excepciones, claves CB por carril y
contratos de eventos. La ruta directa del gateway sigue consumida y se conserva.

**Excepciones a clean-rebuild-policy:** Ninguna. La validación con motores PDF reales
queda pendiente por ausencia de binarios; esta aprobación es local, no una
certificación del despliegue.

## Resumen de implementación

- `app/Services/Audit/GeminiGateway.php`: comentarios reducidos a intención;
  conservada la política CB del diff para red y errores HTTP distintos de 400.
- `app/Services/Audit/Pipeline/DocumentPdfRasterizer.php`: se retiró la captura
  genérica que convertía errores en EOF. Limpieza única en `finally`, rutas ordenadas
  como retorno interno, eliminación de compatibilidad privada sin consumidores y
  constante de tamaño privada. El servicio tiene 104 líneas netas menos que el
  diff recibido.
- `tests/Services/Audit/GeminiGatewayTest.php`: providers para generación y conteo;
  verificación de HTTP bloqueado, clave Redis, código, operación, Retry-After y
  resultado desconocido. Las pruebas nuevas no mutan entorno ni consultan el reloj.
- `tests/Services/Audit/Pipeline/DocumentPdfRasterizerTest.php`: BOM retirado y fake
  finito con ambas numeraciones de motor; cobertura de límites, bytes, etiquetas,
  errores posteriores, fallback parcial y eliminación de temporales propios.

El chunking usa rangos **1–25, 25–49 y 49–51**. Cada bloque comparte una página con
el anterior, que se descarta al acumular. El bloque corto exitoso indica fin del PDF;
un resultado vacío o un fallo del proceso sigue siendo error. Así se evita depender
de cómo cada motor falla cuando todo el rango está fuera del documento.

El sondeo de la página 51 es necesario para rechazar documentos mayores a 50.
Se corrigió el criterio original de dos procesos para 50 páginas: ahora son tres,
con un máximo de 25 páginas por proceso y dos páginas de trabajo redundante.

## Compuertas y evidencia

| Compuerta | Aplicación y evidencia |
| --- | --- |
| Higiene | Eliminados `rasterizeChunk`, `collectAndValidateImages`, motor no leído y tres ramas de cleanup; búsqueda de referencias y diff final |
| Contratos | Solo cambian helpers privados; se conserva `buildCommand`, su parámetro opcional y sus nombres de argumentos |
| Pruebas y encapsulación | API pública y seam de proceso existente; sin Reflection ni APIs productivas creadas para tests; suite afectada también en orden aleatorio |
| Errores observables | Timeout, exit fallido, salida vacía y diagnóstico de contenido se propagan; no hay éxito parcial silencioso |
| Observabilidad de procesos persistentes | No aplica: no se crean ni modifican workers o daemons; se preservan logs del CB y fallback |
| Modelado de dominio | No se introducen nuevos valores de dominio cerrados; motores y excepciones conservan sus contratos |

Los tests del adaptador PDF conservan el seam existente y escriben temporales de
su propia invocación para verificar el cleanup real. Doblan el proceso externo;
no acceden a Redis, Gemini ni motores instalados. La prueba real ya existente se omite
cuando no existe un motor. No se ampliaron las reglas funcionales de BUSINESS.md.

## Validación ejecutada

| Comprobación | Resultado |
| --- | --- |
| Suite entrante | Bloqueada por BOM antes de cargar tests |
| Suite entrante después de quitar BOM | 1035 pruebas, 3 errores, 3 fallos, 79 omitidas |
| HEAD aislado, PHPUnit completo | Código 0; 1027 pruebas, 3604 aserciones, 79 omitidas |
| `php vendor/bin/phpunit --configuration phpunit.xml --no-progress` | Código 0; 1060 pruebas, 5525 aserciones, 79 omitidas |
| Suite afectada, filtro `GeminiGatewayTest\|DocumentPdfRasterizerTest` | Código 0; 53 pruebas, 1993 aserciones, 1 omitida |
| Mismo filtro con `--order-by=random --random-order-seed=20261109 --do-not-cache-result` | Código 0; mismos conteos |
| `php -l` en los cuatro PHP modificados | Sin errores de sintaxis |
| `git diff --check` | Código 0 |
| `node .agent/skills/_shared/scripts/validate-skills.mjs` | PASS; 22 skills, 9 bundles |
| `npm.cmd run build` en `website` con los documentos actualizados | Código 0; archivos estáticos generados |
| Hook `.agent/scripts/check-docs-updated.mjs` con directorio de artefactos `plans/reports` | Código 0, respuesta `{}`: cierre permitido |

El primer build detectó un literal menor-que-10% sin escapar en la SDD original;
se corrigió su redacción y el siguiente build terminó correctamente. Los avisos
del comprobador de actualizaciones y localStorage no impidieron compilar.

Consulta documental: Context7, resolución y documentación de
`/artifexsoftware/ghostpdl`; [referencia oficial](https://github.com/artifexsoftware/ghostpdl/blob/master/doc/src/Use.rst)
sobre rangos y numeración de archivos. Se confirmó que la numeración de salida de
Ghostscript no equivale al número original de página.

Límite práctico: las 79 omisiones incluyen integraciones opt-in y el test con motor
PDF real. Windows no tiene Ghostscript/Poppler; WSL tampoco los resolvió y no había
contenedores Docker activos. Antes de desplegar, validar PDFs reales de 25, 26, 50
y 51 páginas con ambos motores y comprobar bytes, orden y limpieza.

## Matriz de impacto documental

He modificado los cuatro PHP enumerados arriba. Según la Matriz de Impacto
Documental, los documentos afectados son la SDD, el changelog y este reporte.
Según la Matriz de Impacto en Skills, la skill afectada es audfact-audit-gemini.

| Archivo | ¿Requirió cambio? | Razón | Estado |
| --- | --- | --- | --- |
| `plans/sdd-cb-v2-rasterizer-chunks.md` | Sí | Criterios y contratos finales; propuesta anterior identificada como histórica | Hecho |
| `plans/changelog.md` | Sí | Comportamiento corregido y evidencia real | Hecho |
| Este reporte | Sí | Decisión, evidencia, matrices y límites | Hecho |
| `plans/api-endpoints.md` | No | Sin cambios REST | N/A |
| `plans/architecture.md` | No | Sin componentes ni límites arquitectónicos nuevos | N/A |
| `AGENTS.md` | No | Sin variables, datos, endpoints ni flujo principal nuevos | N/A |
| `README.md` | No | Sin cambios de estructura o conteos de componentes | N/A |

## Matriz de impacto en skills

| Skill / registro | ¿Requirió cambio? | Razón | Estado |
| --- | --- | --- | --- |
| `audfact-audit-gemini` | Sí | Documentar bloques, límite y fallos visibles del rasterizador | Hecho |
| `phpunit-test-architect` | No | Se aplican sus convenciones; no cambia el framework de pruebas | Verificado |
| `audfact-api-rest` | No | Sin cambios HTTP públicos | N/A |
| `CATALOG.md` | No | Los archivos siguen cubiertos por los mapeos existentes | Verificado |

## Resguardo y reversión

Se respaldaron los seis archivos entrantes antes de editar en
`C:\Users\USER\AppData\Local\Temp\audfact-clean-php-cdca77ccc0384a3a902b5b41e434460f`.
La línea base HEAD se ejecutó en una copia aislada dentro del mismo checkpoint.
El índice Git se conserva; los cambios de esta revisión quedan en el working tree.
Los cambios ajenos en hooks, recordatorios y mensaje de commit permanecen intactos.
Para revertir, restaurar únicamente la revisión propia desde ese checkpoint, sin
resetear el repositorio ni descartar trabajo ajeno. No hay migración de datos.
