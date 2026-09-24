# SDD — Vigencia de entrega configurable por cliente

## Revisión Clean Rebuild Policy

- **Decisión**: `APROBAR` para la refactorización local; no constituye validación de despliegue.
- **Estrategia**: evolución incremental sobre API, modelo y pipeline activos.
- **MVP**: evidencia visual completa > plazo del cliente > 60 días. Fuente: diff de vigencia dinámica y solicitud de clean code del 2026-09-24.
- **Contratos activos**: se preservan `evaluate(array, array)`, los primeros cuatro argumentos de `saveConfig`, las rutas REST, eventos y payloads existentes. `[CONFIRMADO]`
- **Compuertas**: origen tipado, nulabilidad coherente entre SQL/HTTP/Redis/UI, pruebas por API pública y eliminación de ramas imposibles/duplicación. `[CONFIRMADO]`
- **Excepciones**: Ninguna. `DeliveryValiditySource` elimina la excepción anterior de strings internos.

## Clasificación del cambio

| Dimensión | Resultado |
| --- | --- |
| Tipo | Feature existente en el diff + refactorización incremental |
| Riesgo | Medio para implementación; el despliegue exige verificar esquema SQL |
| Persistencia | Columna nullable `AudDisp.DiasVigencia`; sin actualización masiva de datos |
| Contrato externo | Campo nuevo `diasVigencia`, aún dentro del diff local |
| Arquitectura | Se conservan MVC, fachada de datos y pipeline Redis |
| Producción | No consultada ni modificada durante esta revisión |

## FASE 0 — Descubrimiento y alcance

### 0.1 Componentes y consumidores

| Componente | Responsabilidad comprobada |
| --- | --- |
| `AuditConfigController` | Valida POST y entrega GET mediante `AuditConfigModel`. `[CONFIRMADO]` |
| `AuditConfigModel` | Lee `DiasVigencia`, enlaza parámetros PDO y conserva valores en UPDATE. `[CONFIRMADO]` |
| `AuditDataService` | Lleva la configuración del modelo al orquestador. `[CONFIRMADO]` |
| `DocumentAuditOrchestrator` | Copia `diasVigencia` al estado `dias_vigencia`. `[CONFIRMADO]` |
| `RulesEvaluationWorker` | Pasa el estado completo a `DeliveryValidityEvaluator`. `[CONFIRMADO]` |
| `DeliveryValidityEvaluator` / `DeliveryValiditySource` | Calculan plazo y origen; fechas provenientes de FDV. `[CONFIRMADO]` |
| `frontend/lib/schemas/domain.ts`, `frontend/lib/api/audfact.ts` | Contratos de lectura Zod y escritura TypeScript. `[CONFIRMADO]` |
| `audit-config-editor.tsx` / `audit-config-page-client.tsx` | Edición, selección de cliente, errores y cambios visuales del diff. `[CONFIRMADO]` |
| `create-config-dialog.tsx` | Guarda sin `diasVigencia`; debe conservar compatibilidad. `[CONFIRMADO]` |
| Migración 004 | Agrega columna si falta mediante `COL_LENGTH`. `[CONFIRMADO]` |

Búsqueda de consumidores: `rg` sobre `app`, `frontend` y `tests` por
`saveConfig`, `saveAuditConfig`, `diasVigencia`, `dias_vigencia` y
`DeliveryValidityEvaluator::evaluate`. Se inspeccionaron fachada, orquestador,
StateStore y worker de reglas. `[CONFIRMADO]`

### 0.2 Decisiones

- Días configurables son un entero abierto dentro del rango HTTP 1..365; no son un enum. El origen sí es un conjunto cerrado y usa enum interno. `[CONFIRMADO]`
- `null` se conserva desde SQL hasta Redis y el estado del editor: significa ausencia de plazo configurado. El fallback no debe convertirse en configuración por guardar otro campo. `[CONFIRMADO]`
- El enum no cruza fronteras HTTP/Redis; los hallazgos conservan su formato. `[CONFIRMADO]`
- No hay variables de entorno nuevas en este cambio. `[CONFIRMADO: revisión del diff]`

### 0.3 Información faltante

- Estado actual de la columna y valores en SQL Server productivo: `[DESCONOCIDO]` en esta revisión. Los ejemplos de clientes del borrador anterior no son evidencia de una consulta ejecutada aquí.
- La verificación de esquema es un requisito operativo antes del despliegue, no un bloqueo para refactorizar o ejecutar pruebas unitarias locales.

## FASE 1 — Contrato implementado

### 1.1 SQL y API

- GET retorna `diasVigencia: number | null`. El modelo convierte enteros SQL representados como string a int. No sustituye NULL por 60. `[CONFIRMADO]`
- POST acepta enteros 1..365 o strings enteros normalizados; booleanos, floats, arrays, texto y valores fuera de rango producen HTTP 422 antes de guardar. `[CONFIRMADO]`
- Ausencia o null en POST conserva el valor existente mediante `ISNULL(:dvU, target.DiasVigencia)`; no permite borrar un plazo existente. `[CONFIRMADO]`
- INSERT enlaza NULL explícito cuando no hay plazo; el DEFAULT SQL no se aplica a NULL explícito. `[CONFIRMADO]`
- Lecturas continúan por `db2` y escrituras por `default`, con transacción existente para cabecera y campos. `[CONFIRMADO]`

### 1.2 Resolución de vigencia

1. Evidencia visual completa: `presente=true`, `valor` entero positivo, `unidad=dias`, `fecha_base` no vacía.
2. Sin evidencia completa: entero positivo de `audit.dias_vigencia`.
3. Sin plazo válido: 60 días y `FechaAutorizacion` como fecha base.

El límite es inclusivo: `FechaEntrega <= fecha_base + días`. Sin fechas FDV,
el resultado sigue siendo `NO_CONCLUYENTE`; sin check activo no hay hallazgo.
`DeliveryValiditySource::{VISUAL, CLIENT_CONFIG, SYSTEM_DEFAULT}` permite un
`match` exhaustivo para el texto del origen. `[CONFIRMADO]`

El rango 1..365 limita la configuración HTTP; no recorta evidencia visual
válida a 365 días ni añade reglas nuevas al cálculo histórico. `[CONFIRMADO]`

### 1.3 Frontend

- Zod valida entero 1..365 o null; ausencia se normaliza a null para consumidores de respuestas anteriores. `[CONFIRMADO]`
- El editor muestra 60 si no hay plazo, pero conserva null en el payload hasta que el usuario seleccione uno. Un plazo explícito de 60 mantiene su origen de cliente. `[CONFIRMADO]`
- Opciones de selección: 30, 60, 90, 120, 180 y 365; también muestra cualquier valor configurado válido fuera de esos presets. `[CONFIRMADO]`
- El plazo pertenece al cliente y aplica a sus documentos sin vigencia explícita. `[CONFIRMADO]`
- Checks guardados ausentes del catálogo siguen visibles y pueden desactivarse; la búsqueda y edición usan la misma comparación de nombres. `[CONFIRMADO]`
- El error de carga de clientes es visible también con configuración seleccionada. `[CONFIRMADO]`

## FASE 2 — Validación y trazabilidad

| Requisito | Evidencia ejecutable |
| --- | --- |
| Rango, tipos, omisión/null y ausencia de escrituras inválidas | `AuditConfigControllerTest` |
| Lectura nullable, normalización SQL y binds tipados | `AuditConfigModelTest` |
| Precedencia, fallback, fecha límite y día siguiente | `DeliveryValidityEvaluatorTest` |
| Propagación de plazo, 60 explícito, null y ausencia | `DocumentAuditOrchestratorTest::testOrchestratorPatchesDiasVigencia` |
| Tipos y compilación de UI | `npm.cmd run typecheck`, `npm.cmd run lint`, `npm.cmd run build` en `frontend` |

Las pruebas de modelo verifican SQL y binds mediante PDO falso; no prueban
el motor SQL Server real. El resultado de la validación completa se registra
en `plans/changelog.md`. `[CONFIRMADO]`

## FASE 3 — Operación y rollback

1. Antes del despliegue, comprobar que `Discolnet.dbo.AudDisp.DiasVigencia` existe en los destinos de lectura y escritura y que los valores no nulos cumplen 1..365.
2. Si falta, ejecutar la migración 004 con el procedimiento autorizado de base de datos. La migración no altera valores históricos cuando la columna ya existe.
3. Desplegar imágenes inmutables de backend, workers y frontend mediante el procedimiento normal. No cambiar archivos dentro de contenedores.
4. Verificar GET, guardado de un plazo y una auditoría controlada con evidencia visual y otra sin evidencia explícita. Comprobar el origen en `detalle`.
5. Ante regresión, volver a las imágenes del SHA anterior verificado conservando columna y datos. El código anterior ignora la columna; no hacer DROP ni sobrescribir configuraciones como rollback.

El consumidor base ya registra identidad, grupo, carril, streams y límites de
consumo/reclaim. Se preserva ese inicio y no se agregan logs de documentos o
secretos. `[CONFIRMADO: AuditEventConsumer::run]`

La revisión local no ejecuta migración, llamadas Gemini, cambios de datos ni
despliegue. No se afirma un tiempo de recuperación sin medirlo. `[CONFIRMADO]`
