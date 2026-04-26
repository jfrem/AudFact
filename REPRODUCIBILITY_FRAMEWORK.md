# Framework de Fiabilidad y Reproducibilidad del Pipeline de Auditoría IA

## 1. Introducción

A medida que el sistema AudFact avanza hacia un entorno de producción, es imperativo evolucionar más allá de la simple funcionalidad para garantizar dos cualidades críticas en nuestro pipeline de Inteligencia Artificial: **Fiabilidad** y **Reproducibilidad**.

-   **Fiabilidad**: La capacidad del sistema para operar de manera robusta y predecible, manejando errores y variaciones de carga sin fallos catastróficos.
-   **Reproducibilidad**: La capacidad de obtener consistentemente el mismo resultado de auditoría cuando se procesa la misma entrada. Esto es fundamental para la validación, la depuración y la confianza en las decisiones automatizadas.

Este documento presenta un análisis del estado actual y propone un framework técnico para medir, fortalecer y mantener estas dos cualidades a lo largo del ciclo de vida del proyecto.

## 2. Análisis del Estado Actual

Una revisión del pipeline de auditoría event-driven revela una base sólida pero con áreas clave de mejora para alcanzar la madurez de producción.

### 2.1. Fiabilidad

| Puntos Fuertes | Puntos a Mejorar |
| :--- | :--- |
| ✅ **Manejo de Errores Robusto**: El `GeminiGateway` implementa reintentos con *exponential backoff* para fallos transitorios de la API. | ⚠️ **Observabilidad Limitada**: El monitoreo se basa en logs de texto. Carecemos de métricas agregadas (KPIs) para evaluar la salud del sistema a lo largo del tiempo. |
| ✅ **Circuit Breaker**: La implementación de un circuit breaker previene fallos en cascada, aislando el sistema de una API de Gemini degradada. | ⚠️ **Métricas de Rendimiento**: No se miden sistemáticamente los tiempos de procesamiento por documento ni la latencia de cada etapa del pipeline. |
| ✅ **Procesamiento Asíncrono con DLQ**: El uso de workers y una *Dead Letter Queue* garantiza que un evento fallido no detenga el procesamiento de un lote completo. | |

### 2.2. Reproducibilidad

| Puntos Fuertes | Puntos a Mejorar |
| :--- | :--- |
| ✅ **Trazabilidad de I/O**: La reciente implementación de logs en `responseIA/` nos proporciona un registro exacto de cada `request` y `response` con Gemini, el pilar para cualquier análisis de reproducibilidad. | ❌ **Falta de Semilla (Seed) Fija**: A pesar de usar una `temperatura: 0.0`, la ausencia de un `seed` en la llamada a la API de Gemini impide garantizar resultados 100% idénticos entre llamadas. Esta es la brecha más crítica para la reproducibilidad. |
| ✅ **Modelo Determinístico**: La configuración por defecto del sistema (`temperature: 0.0`) instruye al LLM para que se comporte de la manera más determinista posible. | |

## 3. Solución Propuesta: Un Framework en Dos Fases

Proponemos un enfoque iterativo: primero, establecer un sistema de medición objetivo y, segundo, implementar las mejoras técnicas y validarlas contra ese sistema.

### Fase 1: Creación de un "Golden Set" de Verificación

El "Golden Set" es un conjunto de casos de prueba curados que representan el resultado **correcto y esperado** del pipeline. Actuará como nuestra "fuente de la verdad" para detectar regresiones y validar mejoras.

**Tareas Técnicas:**

1.  **Definir el Conjunto de Pruebas**: Seleccionar entre 5 y 10 `DisDetNro` que cubran un espectro representativo de escenarios (ej: éxito simple, múltiples dispensaciones, documentos de baja calidad, casos límite).
2.  **Generar los Snapshots de Referencia**: Para cada caso, procesarlo una vez y guardar el JSON de `responseIA`. Este JSON será **revisado y validado manualmente** para asegurar que representa la extracción de datos *perfecta*. Este archivo se convierte en el "snapshot dorado".
3.  **Crear un Script de Verificación (`bin/verify-golden-set.php`)**:
    *   Este script automatizará la comparación entre los resultados actuales y los snapshots dorados.
    *   **Flujo del script**:
        1.  Itera sobre cada caso del "Golden Set".
        2.  Llama al endpoint `/audit/single` para procesar el `DisDetNro`.
        3.  Espera la generación del nuevo archivo en `responseIA/`.
        4.  Realiza una comparación (`diff`) entre el JSON recién generado y el snapshot dorado correspondiente.
        5.  Genera un reporte final: `Éxito: X/N casos coinciden. Fallo: Y/N casos presentan diferencias.`.

Este script se convierte en una **poderosa red de seguridad**. Cualquier cambio futuro en los prompts, el modelo de Gemini o la lógica de negocio puede ser validado instantáneamente contra nuestra línea base de calidad.

### Fase 2: Mejoras Técnicas de Reproducibilidad y Observabilidad

Una vez que podemos medir la consistencia, procederemos a fortalecerla.

**Tareas Técnicas:**

1.  **Implementar Semilla (Seed) en Gemini**:
    *   Se introducirá una nueva variable de entorno, `GEMINI_SEED`, que contendrá un valor entero fijo.
    *   El `GeminiGateway` será modificado para incluir este `seed` en todas las llamadas a la API. Esta acción, combinada con `temperature: 0.0`, es el paso técnico clave para alcanzar la reproducibilidad determinística.
2.  **Mejorar la Observabilidad con Métricas Estructuradas**:
    *   Enriqueceremos los logs con datos estructurados para facilitar el análisis y la creación de dashboards.
    *   **Métricas a Añadir**:
        *   `processing_time_ms`: En los workers, para medir la duración de cada etapa (extracción, normalización, etc.).
        *   `cache_hit_rate`: En `GeminiGateway`, para medir la efectividad del `ExtractionCache` y optimizar costos.
        *   `gemini_api_call_duration_ms`: Medir la latencia de las respuestas de la API.

## 4. Beneficios y Resultados Esperados

La implementación de este framework proporcionará beneficios tangibles a todo el equipo:

-   **Para el Equipo de Desarrollo**:
    *   **Seguridad en Refactorización**: Modificar prompts o lógica de negocio sin miedo a introducir regresiones silenciosas.
    *   **Depuración Acelerada**: Los fallos de reproducibilidad se pueden identificar y aislar rápidamente con el script del "Golden Set".
-   **Para Operaciones (Ops) y SRE**:
    *   **Visibilidad Clara del Pipeline**: Los logs estructurados permitirán crear dashboards de monitoreo para supervisar la salud, el rendimiento y la tasa de errores del sistema en tiempo real.
-   **Para los Stakeholders del Negocio**:
    *   **Mayor Confianza**: La capacidad de verificar consistentemente los resultados de la IA aumenta la confianza en las decisiones automatizadas.
    *   **Calidad Cuantificable**: Pasamos de una evaluación subjetiva a una medición objetiva y automatizada de la calidad del pipeline.

## 5. Próximos Pasos

La ejecución de este plan se llevará a cabo siguiendo las tareas técnicas descritas en las dos fases. El equipo procederá con la Fase 1 para establecer la línea base de medición antes de implementar los cambios técnicos de la Fase 2.
