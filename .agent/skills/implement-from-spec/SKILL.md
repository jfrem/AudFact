---
name: implement-from-spec
description: "Implementa una especificación SDD aprobada de forma determinista y autónoma: pruebas primero, cambios solo dentro del perímetro de la especificación, checkpoints con git, reintentos limitados con recuperación al último estado verde, revisión independiente y reporte de trazabilidad criterio por criterio. Úsala siempre que el usuario pida implementar, ejecutar o llevar a código una spec de docs/specs/, «lo que se aprobó», un plan SDD o un cambio que ya tiene especificación, aunque no nombre la skill. No la actives para redactar o corregir especificaciones (usar write-sdd-spec), para revisar código ya escrito (usar rigorous-code-review) ni para cambios sin especificación: en ese caso, propón redactarla primero."
---

# Implement From Spec

## Propósito

Convertir una especificación SDD aprobada en código **sin añadir ni omitir nada**, validando cada paso y recuperándose sola de los fallos, de modo que el usuario solo tenga que intervenir en dos momentos: aprobar la especificación (antes) y aprobar el merge (después).

La especificación es el contrato. Implementar «algo mejor» que lo especificado es tan incorrecto como implementar menos: rompe la trazabilidad y la revisión que el usuario ya hizo. Cuando la especificación es insuficiente, la respuesta correcta es detenerse y proponer el cambio de especificación, no improvisar.

## Piezas del sistema

| Pieza | Papel |
| --- | --- |
| `write-sdd-spec` | Produce la especificación. Esta skill la consume sin reinterpretarla. |
| `clean-rebuild-policy` | Rige la estrategia declarada en la sección 6.1 y sus excepciones. |
| `rigorous-code-review` | Revisión independiente del resultado (fase 5). |
| Hooks de `.agents/hooks/` | Bloquean escrituras fuera del perímetro aprobado y ejecutan la validación al cerrar el turno. |
| `scripts/check-spec-compliance.mjs` | Comprobación determinista de que el diff está dentro del perímetro y de qué criterios hay que trazar. |

Los hooks son la red de seguridad, no el plan. No uses un bloqueo del hook como forma de descubrir el perímetro: compruébalo antes de escribir. Si un hook bloquea algo, trátalo como una señal de que te has desviado de la especificación.

## Reglas inquebrantables

1. **Solo el perímetro.** Escribir únicamente los archivos `MODIFIED` de 0.1 y los `[NEW]`/`[MODIFY]`/`[DELETE]` de la sección 13. Nunca esquivar la restricción: ni con comandos de shell, ni moviendo la lógica a otro archivo autorizado para no tocar el que hace falta.
2. **No debilitar la validación.** No modificar, omitir, marcar como `skip` ni relajar pruebas, tipos o lint para que algo pase. Las pruebas de la sección 16 son parte del contrato.
3. **No editar la especificación ni las aprobaciones.** Editar la especificación invalida su aprobación; cualquier cambio necesario se propone al usuario.
4. **Nada fuera del contrato.** Sin funcionalidades, refactorizaciones, dependencias ni cambios de contrato (sección 10) que la especificación no declare.
5. **Nunca devolver el control en rojo.** Al terminar o al escalar, el árbol está en verde o restaurado al último checkpoint verde.
6. **Sin acciones externas.** No ejecutar migraciones contra bases reales, despliegues, `push` ni merge. El rollout (sección 20) es del usuario.

## Flujo

Cada fase tiene una gate. No avanzar con una gate abierta. Si una fase revela que la especificación no basta, ir a «Escalado».

### Fase 0 — Preflight

1. Leer la especificación completa y `AGENTS.md`.
2. Confirmar, leyendo el archivo:
   - FASE 4 declara un único nivel, A o B;
   - `docs/specs/approvals.json` contiene una entrada para la especificación. El hook verifica el hash; si la escritura se bloquea por «modificada después de su aprobación», escalar.
   - Supuestos S2 en estado `Abierto` (Nivel B): anotarlos para el reporte; no los resuelve esta skill.
3. Ejecutar `node .agents/skills/implement-from-spec/scripts/check-spec-compliance.mjs <spec>` para obtener el perímetro autorizado y la lista de criterios.
4. Git:
   - si no hay repositorio o no tiene commits, detenerse: inicializarlo es decisión del usuario;
   - si hay cambios sin confirmar ajenos a la tarea, detenerse y preguntar;
   - crear la rama `impl/<slug-de-la-spec>` y registrar el commit base.
   Los commits de checkpoint en esa rama forman parte del procedimiento que el usuario pidió al invocar esta skill; no se hace `push`.

**Gate:** nivel A/B, aprobación presente, perímetro no vacío, rama creada con árbol limpio.

### Fase 1 — Plan de ejecución

Construir, antes de escribir código, la matriz que guiará todo lo demás:

| Criterio (18) / Requisito (11) | Prueba (16) | Archivos (13) | Paso |
| --- | --- | --- | --- |

- El orden de los pasos sale de la especificación: «Orden de Ejecución» de la sección 9, el plan de la sección 14 y las dependencias de la sección 13. Sin orden declarado: primero tipos y contratos, luego lógica y por último el cableado.
- Cada criterio verificable por código necesita al menos una prueba de la sección 16. Los que solo se verifican manualmente se marcan como pendientes para el usuario.
- **Gate:** ningún criterio sin prueba ni sin archivo; ningún archivo de la matriz fuera del perímetro. Si hay un criterio sin prueba diseñada, un paso ambiguo o un archivo necesario que no está autorizado, es un defecto de la especificación: escalar.

### Fase 2 — Pruebas en rojo

1. Escribir las pruebas de la sección 16 tal como están especificadas.
2. Ejecutarlas y confirmar que **cada una falla por el motivo esperado**: una aserción sobre el comportamiento ausente, o un símbolo inexistente que la especificación declara como `[NEW]`. Un fallo por sintaxis, ruta o configuración es un error de la prueba: corregirlo.
3. Una prueba que pasa antes de implementar indica que el comportamiento ya existe (incoherencia con el «Estado Actual», sección 4) o que la prueba no prueba nada. Revisarla; si el comportamiento realmente existe, escalar.
4. Checkpoint: `test(<slug>): pruebas en rojo para <criterios>`. Es el único checkpoint en rojo y sirve para demostrar que las pruebas detectan la ausencia del comportamiento; no termines el turno en esta fase (el hook de validación lo bloquearía). Si hay que escalar desde aquí, restaura el commit base.

**Gate:** todas las pruebas nuevas fallan por el motivo correcto; las existentes siguen pasando.

### Fase 3 — Implementación en verde

Para cada paso del plan:

1. Hacer el cambio mínimo de la sección 13 que pone en verde las pruebas de ese paso.
2. Ejecutar las pruebas del paso y después la validación completa de `AGENTS.md` (por ejemplo, `npm run typecheck` y `npm test`).
3. Checkpoint al quedar en verde: `feat(<slug>): <paso>`.

**Política de reintentos** (por paso):

- Antes de cada intento, escribir la hipótesis de la causa raíz a partir del error real. No repetir un arreglo que ya falló.
- Máximo **3 intentos** por paso. Agotados, restaurar el último checkpoint verde (`git restore --source=<checkpoint> --staged --worktree -- <archivos del paso>` y borrar los archivos nuevos del paso) y escalar.
- Un fallo en una prueba ajena al paso es una regresión: tiene prioridad sobre seguir avanzando.

**Gate:** todas las pruebas pasan, incluidas las preexistentes, y la validación completa pasa.

### Fase 4 — Verificación de cumplimiento

1. Ejecutar `node .agents/skills/implement-from-spec/scripts/check-spec-compliance.mjs <spec> --base <commit-base>`. Debe terminar con código 0: ningún archivo de código cambiado fuera del perímetro ni ninguna ruta protegida tocada.
2. Revisar los archivos autorizados que el script marca como **no modificados**: un `[NEW]` o `[MODIFY]` sin tocar suele indicar trabajo incompleto.
3. Completar la matriz con la evidencia de cada criterio: el nombre de la prueba y su resultado real. Cubrir los casos límite de la sección 15.
4. Si la especificación declara observabilidad (sección 19) o mitigaciones de supuestos S2 (secciones 16, 17, 19 y 20), verificar que están implementadas.

**Gate:** script en verde y todos los criterios con evidencia o marcados como verificación manual pendiente.

### Fase 5 — Revisión independiente

- Lanzar un subagente con contexto limpio que aplique `rigorous-code-review` al diff `<commit-base>...HEAD`, con la especificación como requisito. Quien implementó no revisa su propio trabajo.
- Si no hay subagentes disponibles, hacer la revisión en una pasada separada y declararla **no independiente** en el reporte.
- Según el veredicto:
  - `APPROVE` o `APPROVE WITH NITS`: continuar. Los nits no se aplican salvo que sean triviales y estén dentro del perímetro.
  - `REQUEST CHANGES`: corregir los hallazgos dentro del perímetro (fase 3) y repetir la revisión. Máximo **2 rondas**; después, escalar.
  - `BLOCKED`, o un hallazgo que exige salir del perímetro o cambiar la especificación: escalar.

**Gate:** veredicto `APPROVE` o `APPROVE WITH NITS`.

### Fase 6 — Reporte

Usar el formato de la sección siguiente con `Estado: COMPLETADO`. No hacer merge ni `push`: el siguiente paso es del usuario.

## Escalado

Detenerse y escalar al usuario cuando:

- la especificación es ambigua, contradictoria o incompleta para un paso;
- hace falta un archivo fuera del perímetro, una dependencia nueva o un cambio de contrato no declarado;
- un hook bloquea una acción que el plan necesitaba;
- se agotan los reintentos de un paso o las rondas de revisión;
- el «Estado Actual» de la especificación no coincide con el código;
- una decisión pendiente de `AGENTS.md` o una decisión de producto afecta al paso.

Antes de escalar, restaurar el último checkpoint verde. Después, usar el formato de reporte con `Estado: ESCALADO`, indicando:

- qué bloquea, con evidencia (error, archivo y líneas, sección de la especificación);
- qué se intentó y por qué no funcionó;
- **el cambio de especificación propuesto** (sección y texto) cuando aplique, para que el usuario lo edite y vuelva a aprobar;
- en qué checkpoint quedó el árbol.

## Formato del reporte

```markdown
## Implementación: <título de la spec> — Estado: COMPLETADO | ESCALADO

**Especificación:** `docs/specs/<archivo>.md` (Nivel <A|B>) · **Rama:** `impl/<slug>` · **Base:** `<sha>` · **Último checkpoint verde:** `<sha>`

### Trazabilidad

| Criterio | Prueba | Resultado |
| --- | --- | --- |
| 18.1 <criterio> | `ruta/archivo.test.ts › nombre` | ✅ Pasa |
| 18.4 <criterio> | Verificación manual (16) | ⏳ Pendiente del usuario |

### Validación

| Comando | Resultado |
| --- | --- |
| `npm run typecheck` | ✅ / ❌ <detalle> |
| `npm test` | ✅ <n> pruebas / ❌ <detalle> |
| `check-spec-compliance` | ✅ Sin cambios fuera del perímetro |

### Revisión independiente

<Veredicto, rondas y hallazgos atendidos. Declarar si no fue independiente.>

### Supuestos y pendientes

<S2 abiertos de Nivel B, verificaciones manuales y pasos de rollout (sección 20) que quedan para el usuario.>

### Escalado (solo si aplica)

<Bloqueo, evidencia, intentos, cambio de especificación propuesto.>

### Siguiente paso del usuario

<Revisar la rama y hacer merge, o editar la especificación, volver a aprobarla y relanzar.>
```

Redactar el reporte en español salvo que el usuario pida otro idioma.
