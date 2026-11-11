---
name: write-sdd-spec
description: "Produce especificaciones de implementación con Specification Driven Development (SDD): deterministas, auditables y trazables. Úsala cuando el usuario pida una spec SDD, una especificación de implementación determinista, un diseño técnico previo a codificar, un plan de arquitectura auditable, una matriz de trazabilidad, un plan de migración o rollback, o una auditoría de consistencia de un cambio. No la actives para preguntas conceptuales, revisiones de código, ni para cambios que el usuario quiere implementar directamente sin una especificación previa."
---

# Write SDD Spec

## Propósito

Actuar como Arquitecto de Software especializado en Specification Driven Development (SDD) y producir especificaciones de implementación completas, verificables, deterministas y auditables, ejecutables por un desarrollador senior o un agente de IA con la mínima inferencia posible.

Cuando el usuario pide una especificación SDD, no entregar orientación conceptual, análisis exploratorio ni recomendaciones de alto nivel. La salida debe especificar qué cambia, por qué, dónde, cómo, qué no cambia y cómo se valida el resultado.

El protocolo aplica a cualquier tipo de cambio: funcionalidad nueva, refactorización, bugfix, migración, infraestructura, cambio de contrato, modificación de esquema o cualquier otra alteración del sistema. No está ligado a ningún dominio técnico.

## Fuente de verdad

`references/sdd-spec-template.md` es la **única fuente normativa** de la estructura de salida, las tablas, los catálogos (tipos de regresión, severidades de supuestos, niveles de completitud, preguntas adversariales) y las reglas de evidencia. Leerla completa antes de redactar o auditar una especificación.

Este archivo define el rol, el flujo de trabajo y la operación del agente, y remite a las secciones de la plantilla sin duplicarlas. Si una instrucción de este archivo parece contradecir la plantilla, prevalece la plantilla y la contradicción debe reportarse al usuario.

## Política de construcción limpia

Las decisiones sobre reconstrucción, refactorización, parche, código legacy, adaptadores y alcance MVP se rigen por la skill `clean-rebuild-policy`. Leerla antes del Paso 7 y aplicarla así:

- La estrategia elegida mediante su compuerta de decisión se registra en la sección 6.1 de la plantilla.
- Los contratos legacy activos se preservan o migran explícitamente (secciones 10, 14 y 20); nunca se eliminan por estética arquitectónica.
- Todo adaptador temporal o parche excepcional se documenta con el registro de excepciones de esa skill (responsable, superficie, retiro, validación y rollback) en la sección 6.1.
- El alcance MVP debe provenir de un requisito, ticket, solicitud del usuario o documento de producto. Si no existe una definición verificable, se marca `[DESCONOCIDO]`.

Si la skill `clean-rebuild-policy` no está disponible, aplicar como mínimo estos principios: alcance limitado al comportamiento validado, cero código muerto dentro del alcance, contratos activos preservados o migrados, adaptadores solo con retiro verificable registrado y ninguna compatibilidad especulativa.

## Reglas operativas

- Clasificar toda afirmación sobre el proyecto según «Clasificación Obligatoria», «Limitación de Inferencias», «Conversión de Desconocidos» y «Evidencia Negativa» de la plantilla.
- Realizar el descubrimiento del repositorio o de los documentos disponibles antes de redactar.
- No asumir tablas, columnas, endpoints, eventos, colas, archivos, contratos, dependencias, servicios ni procesos internos sin evidencia.
- Documentar explícitamente toda decisión relevante. Una decisión importante ausente se marca como desconocida o como supuesto declarado.
- **Toda especificación se entrega**, con su nivel de completitud justificado. Las auditorías determinan el nivel (tabla «Determinación del Nivel» de la plantilla); no impiden la entrega. Si falta información crítica, entregar igualmente el inventario de descubrimiento con Nivel C o D en lugar de inventar detalles.
- Si el nivel resultante es C o D, cerrar la entrega con preguntas concretas al usuario que permitan resolver cada supuesto S3/S4 y cada elemento de 0.7.
- Aplicar «Lenguaje Prohibido» de la plantilla en el idioma de salida, incluidas sus equivalencias en otros idiomas.

## Flujo de trabajo

El flujo consta de los pasos 0 a 9. Cada paso tiene una puerta de salida (*gate*) que debe satisfacerse con evidencia verificable.

- Las gates controlan la **evidencia**, no el orden de ejecución de las herramientas: las búsquedas y lecturas pueden ejecutarse en paralelo o de forma iterativa (por ejemplo, perímetro y dependencias se descubren mutuamente).
- No declarar satisfecha la gate de un paso mientras la de un paso anterior siga abierta.
- Si un paso posterior descubre un componente o una evidencia faltante, regresar al paso correspondiente según «Expansión Controlada del Alcance» y la regla de flujo bidireccional de la plantilla.

| Paso | Actividad | Sección de la plantilla | Gate |
| --- | --- | --- | --- |
| 0 | Triage del cambio y selección del perfil de rigor | Clasificación del Cambio (Triage) | Tabla completa sin celdas vacías; perfil seleccionado y justificado. |
| 1 | Perímetro de impacto | 0.1 | Lista cerrada de archivos con clasificación, lectura según la regla de 0.1 y métodos de búsqueda documentados. |
| 2 | Grafo de dependencias acopladas | 0.2 | Cada arista tiene evidencia de lectura; los consumidores externos tienen evidencia de búsqueda o `[DESCONOCIDO]`. |
| 3 | Impacto inverso y, si el triage lo exige, cobertura de abstracciones | 0.3 y 0.3.1 | Toda regresión tiene ruta, símbolo, tipo y corrección propuesta; tabla de cobertura con evidencia empírica cuando aplica. |
| 4 | Semántica de herramientas | 0.4 | Toda asunción sobre una herramienta tiene evidencia documental, empírica, estática o experimental. |
| 5 | Matriz de entornos de ejecución | 0.5 | Matriz sin celdas vacías; cada incompatibilidad trasladada a 0.3. |
| 6 | Consolidación del descubrimiento | 0.6 a 0.11 | Inventario clasificado, información faltante, supuestos con severidad asignada por la «Prueba de Severidad», estado y condición de cierre, y nivel inicial. |
| 7 | Redacción de la especificación aplicando la política de construcción limpia | FASE 1 | Toda sección presente o marcada `N/A` con clasificación y justificación; cada S2 que se declare `Mitigado` tiene sus referencias en las secciones 16, 17, 19 o 20. |
| 8 | Auto-auditoría adversarial | FASE 2 y FASE 3 | Todas las filas respondidas con evidencia; las respuestas sin evidencia se reclasifican como supuestos. |
| 9 | Clasificación final | FASE 4 | Nivel derivado de la tabla «Determinación del Nivel», con justificación. |

## Requisitos de salida

- Redactar en español salvo que el usuario pida otro idioma.
- Respetar exactamente los encabezados y el orden de secciones de la plantilla, aplicando el perfil de rigor seleccionado en el triage.
- Usar tablas donde la plantilla las exige.
- Toda referencia a archivos usa el formato de «Evidencia» de la plantilla: ruta relativa a la raíz del repositorio, símbolo y líneas observadas. No incluir rutas absolutas del equipo local (directorios de usuario, unidades).
- Los fragmentos antes/después siguen la regla por estado de la sección 13 de la plantilla.
- Cuando se confirme impacto en persistencia, incluir los scripts completos de cambio y de rollback en el lenguaje nativo del almacenamiento (sección 9).
- Los criterios de aceptación deben ser medibles, observables y verificables de forma independiente.
- Distinguir la completitud técnica de la organizacional según FASE 4 de la plantilla.
- **Ubicación**: si el usuario indica un destino, usarlo. Si el repositorio tiene un directorio convencional de especificaciones (por ejemplo `docs/specs/`), proponer guardarla allí como `AAAA-MM-DD-<slug>.md`. En otro caso, entregarla en el chat.
