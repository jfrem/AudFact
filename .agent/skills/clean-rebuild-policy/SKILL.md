---
name: clean-rebuild-policy
description: "Gobierna módulos nuevos, reconstrucciones limpias y decisiones explícitas entre rebuild, refactorización o parche. Úsala para delimitar un MVP, retirar código obsoleto, evaluar compatibilidad legacy o revisar una refactorización profunda. No la actives solo porque una tarea modifica código ni para mantenimiento ordinario sin impacto arquitectónico."
---

# Clean Rebuild Policy

## Resultado esperado

Aplicar una política de reconstrucción limpia sin confundir alcance MVP con baja calidad ni romper contratos activos. Toda revisión debe concluir con una decisión explícita: `APROBAR`, `RECHAZAR` o `APROBAR CON EXCEPCIÓN`.

## Cuándo aplica

Aplicar esta política cuando la tarea incluya al menos una de estas condiciones:

- crear o rediseñar un módulo desde una base limpia;
- decidir entre reconstrucción, refactorización incremental o parche;
- eliminar código obsoleto, adaptadores o rutas de compatibilidad;
- definir o auditar los límites funcionales de un MVP;
- revisar una refactorización profunda con impacto arquitectónico.

No activarla automáticamente por mantenimiento ordinario, correcciones locales o cualquier modificación de código. En sistemas productivos con datos o consumidores activos, la opción predeterminada es la evolución limpia e incremental; una reconstrucción requiere alcance, migración, validación y rollback explícitos.

## Invariantes

- El alcance funcional se limita al comportamiento validado por el ticket, especificación o usuario.
- Lo implementado debe quedar listo para producción según los requisitos y riesgos actuales: seguro, comprobable y mantenible. No exigir diseños “definitivos” ni anticipar variaciones futuras no demostradas.
- Mantener límites proporcionales y explícitos entre dominio, datos, presentación e integraciones. No crear capas ceremoniales sin una responsabilidad actual.
- No introducir deuda temporal con la promesa informal de corregirla después.
- No ampliar la tarea para limpiar hallazgos ajenos al cambio. Eliminar únicamente residuos introducidos, reemplazados o expresamente incluidos en el alcance autorizado.
- No tratar como obsoleto un contrato todavía consumido. Una API interna entre componentes desplegados independientemente también es un contrato activo.

## Compuerta de decisión

| Contexto comprobado                                                                                        | Decisión predeterminada     | Condiciones                                                             |
| ---------------------------------------------------------------------------------------------------------- | --------------------------- | ----------------------------------------------------------------------- |
| Módulo nuevo, aislado y sin consumidores previos                                                           | Clean rebuild               | Implementar solo el comportamiento MVP validado.                        |
| Componente productivo con API, datos o consumidores activos                                                | Refactorización incremental | Preservar el contrato o definir migración, cutover y rollback.          |
| Componente reemplazado y sin consumidores verificables                                                     | Reconstruir o eliminar      | Demostrar ausencia de referencias estáticas, dinámicas y operativas.    |
| Incidente o vulnerabilidad que no admite una corrección estructural segura dentro de la ventana disponible | Parche excepcional          | Debe ser mínimo, reversible y quedar registrado con retiro verificable. |

Rechazar la reconstrucción cuando su radio de impacto, migración de datos, compatibilidad o reversibilidad no estén resueltos.

## Clasificación de legacy

- **Contrato legacy activo**: antiguo, pero consumido por usuarios, datos, APIs, jobs o servicios. Preservarlo o migrarlo explícitamente; no eliminarlo por estética arquitectónica.
- **Código obsoleto**: reemplazado, sin consumidores y cubierto por evidencia de eliminación segura. Eliminarlo dentro del alcance.
- **Adaptador temporal**: permitirlo solo cuando un contrato activo impida el corte directo. Registrar superficie, motivo, responsable, condición o fecha de retiro, validación y rollback.
- **Nueva compatibilidad especulativa**: rechazarla cuando no exista un consumidor o requisito vigente demostrado.

## Compuertas tácticas

Aplicar únicamente las compuertas cuya condición se cumpla.

### Modelado de dominio

- **CUÁNDO**: un valor de negocio representa un conjunto cerrado, estable y controlado por la aplicación.
- **EXIGIR**: enum, tipo suma o value object validado; convertir primitivas en las fronteras HTTP, SQL, colas o archivos.
- **PROHIBIR**: strings o enteros mágicos repetidos que controlen el flujo en servicios.
- **EXCEPCIÓN**: valores abiertos, configurables o extensibles por terceros, o primitivas usadas exclusivamente para transporte.
- **EVIDENCIA**: búsqueda de comparaciones duplicadas y pruebas de valores válidos, inválidos y desconocidos.

Ubicar la normalización de transporte en el adaptador o factory, las invariantes en el tipo y las decisiones que combinan contexto en una policy o servicio. No acumular reglas contextuales en un enum.

### Contratos y jerarquías

- **CUÁNDO**: cambia una interfaz, clase base o contrato compartido.
- **EXIGIR**: actualizar atómicamente implementadores, adaptadores, dobles de prueba y consumidores afectados con tipos compatibles.
- **PROHIBIR**: firmas desalineadas o parámetros opcionales ficticios agregados a componentes que no comparten el contrato.
- **EXCEPCIÓN**: migración gradual entre componentes desplegados independientemente, documentada con compatibilidad acotada y retiro.
- **EVIDENCIA**: búsqueda de implementaciones y consumidores, análisis estático o compilación, y suite contractual.

### Pruebas y encapsulación

- **CUÁNDO**: una prueba necesita alterar estado privado, global, ambiental o singleton.
- **EXIGIR**: probar primero mediante comportamiento observable; después preferir dependencias inyectadas, estado por instancia o un ciclo de vida legítimo también útil en producción.
- **PROHIBIR**: Reflection, monkey-patching o hacks como estrategia predeterminada; también APIs públicas creadas únicamente para manipulación de tests.
- **EXCEPCIÓN**: límites de framework, serialización o legacy no reemplazable, con justificación y alcance documentados.
- **EVIDENCIA**: pruebas deterministas e independientes del orden. Todo estado global mutado debe aislarse o restaurarse incluso ante fallo, incluyendo ejecución paralela.

### Observabilidad operativa

- **CUÁNDO**: se crea o modifica un worker, listener, consumidor, daemon u otro proceso persistente.
- **EXIGIR**: log estructurado de inicio con identidad de proceso o instancia, modos o canales activos y límites o timeouts relevantes.
- **PROHIBIR**: secretos, credenciales, datos sensibles o volcados completos de configuración en logs.
- **EVIDENCIA**: prueba o ejecución controlada que muestre los campos permitidos y su redacción.

Ningún error debe desaparecer silenciosamente. Cada excepción capturada debe resolverse, traducirse, clasificarse, relanzarse o registrarse. El límite que asume responsabilidad operacional registra el error una sola vez con correlación; las capas inferiores pueden relanzarlo sin duplicar logs. Prohibidos los `catch` vacíos.

### Higiene del cambio

- **CUÁNDO**: cualquier reconstrucción o refactorización deja elementos reemplazados dentro del alcance.
- **EXIGIR**: eliminar imports sin uso, variables no leídas, código comentado, configuración obsoleta y rutas internas reemplazadas.
- **PROHIBIR**: conservar código “por si acaso” o marcar `deprecated` código interno sin consumidores.
- **EXCEPCIÓN**: contratos externos o componentes con despliegue independiente que requieren una ventana de migración registrada.
- **EVIDENCIA**: diff final, búsqueda de referencias, análisis estático y pruebas pertinentes. El historial de Git conserva el código retirado.

## Flujo de revisión

1. Identificar el comportamiento MVP y su fuente de validación.
2. Delimitar archivos, datos, APIs, consumidores y despliegues afectados.
3. Clasificar la estrategia mediante la compuerta de decisión.
4. Aplicar solo las compuertas tácticas activadas por el cambio.
5. Revisar el diff sin incorporar limpieza ajena al alcance.
6. Validar con los comandos oficiales del proyecto y registrar resultados reales; no declarar verificaciones no ejecutadas.
7. Emitir la decisión y, cuando corresponda, la alternativa limpia más pequeña que satisfaga el MVP.

## Registro de excepciones

Toda excepción debe indicar:

- regla exceptuada y motivo verificable;
- responsable de resolverla;
- superficie exacta de compatibilidad;
- condición o fecha de retiro;
- método de validación;
- migración y rollback cuando afecte contratos o datos activos.

Una excepción sin retiro verificable es deuda permanente y debe rechazarse.

## Checklist de cierre

- ¿El cambio implementa únicamente comportamiento validado?
- ¿La solución es production-ready para los requisitos y riesgos actuales sin abstracciones especulativas?
- ¿Se clasificaron correctamente los contratos legacy activos, el código obsoleto y los adaptadores temporales?
- ¿Los valores de dominio cerrados evitan literales mágicos repetidos?
- ¿Los contratos modificados conservan consistencia entre implementadores y consumidores?
- ¿Las pruebas respetan la encapsulación y aíslan todo estado mutable?
- ¿Los procesos persistentes son diagnosticables sin revelar información sensible?
- ¿El diff queda sin imports huérfanos, variables muertas, configuración obsoleta ni código comentado dentro del alcance?
- ¿Se documentaron comandos, resultados, excepciones, migración y rollback aplicables?

## Formato de salida

Al revisar una propuesta o implementación, informar:

1. `Decisión`: `APROBAR`, `RECHAZAR` o `APROBAR CON EXCEPCIÓN`.
2. `Alcance MVP`: comportamiento validado y fuente.
3. `Contratos activos`: qué debe preservarse o migrarse.
4. `Compuertas activadas`: hallazgos y evidencia por compuerta.
5. `Excepciones`: responsable, retiro, validación y rollback; escribir `Ninguna` si no existen.
6. `Validación`: comandos o comprobaciones ejecutadas y resultado real.
