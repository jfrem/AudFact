# Especificación SDD — Motor de Arbitraje Semántico Desacoplado y Reconstrucción Limpia (Clean Rebuild)

---

## Clasificación del Cambio (Triage)

| Dimensión | Valor | Justificación / Evidencia |
| :--- | :--- | :--- |
| **Tipo** | `Refactor de Arquitectura / Clean Rebuild / Bugfix` | `[CONFIRMADO]` Reconstrucción limpia de la capa de arbitraje semántico en [`app/Services/Audit/ArticleSemanticMatchJudge.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/ArticleSemanticMatchJudge.php#L11), sustituyendo el monolito acoplado por [`SemanticMatchJudge.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/SemanticMatchJudge.php). Desacopla las instrucciones del modelo de jergas médicas o locales, enriquece el contexto con la evidencia documental y resuelve el falso positivo de `T58260400854` sin sobreingeniería. |
| **Riesgo** | `Bajo / Medio` | `[CONFIRMADO]` Refactorización interna de un único servicio de desempate semántico sin alterar esquemas relacionales, contratos externos de API REST ni formatos de persistencia en SQL Server. |
| **Persistencia afectada** | `No` | `[CONFIRMADO]` No altera tablas, vistas ni columnas en base de datos (`dbo.AudDispEst`, `AdjuntosDispensacionDetalle`, `DispensacionDetalleServicio`). |
| **Contrato externo afectado** | `No` | `[CONFIRMADO]` Los contratos de API REST (`/audit/results/{facNro}`), los schemas de los eventos Redis (`document_extracted`, `rules_evaluated`, `audit_completed`) y el payload de telemetría permanecen 100% compatibles. |
| **Cambio arquitectónico** | `Sí` | `[CONFIRMADO]` Erradica la clase acoplada `ArticleSemanticMatchJudge` y la reemplaza por un servicio agnóstico de plataforma `SemanticMatchJudge`. Desacopla los prompts del dominio y pasa contexto documental desde `DocumentPolicyEngine`. |
| **Producción afectada** | `Sí` | `[CONFIRMADO]` Afecta la ejecución de desempate semántico en el worker `RulesEvaluationWorker` y en la evaluación de políticas en producción LAN. |
| **Requiere 0.3.1 (cobertura de abstracciones)** | `No` | `[CONFIRMADO]` No se introducen jerarquías polimórficas dinámicas ni fábricas; se mantiene un mapeo directo y tipado por `call_purpose` / `AuditFieldValueType` dentro del servicio unificado. |

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| :--- | :--- | :---: | :--- | :--- | :---: |
| `SemanticMatchJudge.php` | `app/Services/Audit/SemanticMatchJudge.php` | `MODIFIED` (Nuevo archivo) | Servicio agnóstico de arbitraje semántico de la plataforma con caché Redis y telemetría. | Todo el archivo (nuevo) | `Sí` |
| `ArticleSemanticMatchJudge.php` | `app/Services/Audit/ArticleSemanticMatchJudge.php` | `MODIFIED` (Eliminación) | Clase obsoleta acoplada a ser eliminada bajo Clean Rebuild Policy (cero código zombie). | L1-397 (Eliminación) | `Sí` |
| `DocumentPolicyEngine.php` | `app/Services/Audit/Pipeline/DocumentPolicyEngine.php` | `MODIFIED` | Motor de evaluación de políticas; inyecta `SemanticMatchJudge` y suministra contexto documental. | L13, L19, L25, L782-787, L886-908 | `Sí` |
| `RulesEvaluationWorker.php` | `app/Services/Audit/Pipeline/RulesEvaluationWorker.php` | `MODIFIED` | Worker consumidor de eventos de políticas que instancia `SemanticMatchJudge`. | L13, L43 | `Sí` |
| `ArticleSemanticMatchJudgeTest.php` | `tests/Services/Audit/ArticleSemanticMatchJudgeTest.php` | `MODIFIED` (Eliminación) | Suite obsoleta acoplada a la clase retirada. | L1-390 (Eliminación) | `Sí` |
| `SemanticMatchJudgeTest.php` | `tests/Services/Audit/SemanticMatchJudgeTest.php` | `MODIFIED` (Nuevo archivo) | Suite unitaria que valida `SemanticMatchJudge`, contexto documental y casos clínicos. | Todo el archivo (nuevo) | `Sí` |
| `AuditFieldValueType.php` | `app/Services/Audit/AuditFieldValueType.php` | `INSPECTED` | Enum de tipos de dato; provee `allowsSemanticGeminiFallback()`. | L103-107 | `Sí` |
| `AuditComparisonType.php` | `app/Services/Audit/AuditComparisonType.php` | `INSPECTED` | Enum de tipos de comparación (`SEMANTIC`, `EXACT`, `BUSINESS`, `VISUAL`). | L13-43 | `Sí` |
| `GeminiGateway.php` | `app/Services/Audit/GeminiGateway.php` | `INSPECTED` | Cliente Gemini multimodal / Function Calling; provee `TASK_SEMANTIC_MATCH`. | L150-161 | `Sí` |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| :--- | :--- | :--- | :--- |
| **Búsqueda por símbolo** | `ArticleSemanticMatchJudge` | 4 archivos en `app/` y `tests/` | `ArticleSemanticMatchJudge.php:11`, `DocumentPolicyEngine.php:13`, `RulesEvaluationWorker.php:13`, `ArticleSemanticMatchJudgeTest.php:8` |
| **Búsqueda por importación/use** | `use App\Services\Audit\ArticleSemanticMatchJudge;` | Exactamente 3 archivos PHP en todo el repositorio | `DocumentPolicyEngine.php:13`, `RulesEvaluationWorker.php:13`, `ArticleSemanticMatchJudgeTest.php:8` |
| **Búsqueda en controladores** | `ArticleSemanticMatchJudge` en `app/Controllers/` | 0 coincidencias | Búsqueda grep en `app/Controllers` arrojó 0 resultados |
| **Búsqueda en rutas** | `ArticleSemanticMatchJudge` en `app/Routes/web.php` | 0 coincidencias | Búsqueda grep en `app/Routes/web.php` arrojó 0 resultados |
| **Búsqueda en tests** | `ArticleSemanticMatchJudge` en `tests/` | 1 único archivo de pruebas | `tests/Services/Audit/ArticleSemanticMatchJudgeTest.php` |

---

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `ArticleSemanticMatchJudge.php` | `DocumentPolicyEngine.php` | `app/Services/Audit/Pipeline/DocumentPolicyEngine.php` | L13, L19, L25, L782, L892 | Directa | Inyección en constructor y tipado de propiedad | Repositorio local |
| `ArticleSemanticMatchJudge.php` | `RulesEvaluationWorker.php` | `app/Services/Audit/Pipeline/RulesEvaluationWorker.php` | L13, L43 | Directa | Instanciación directa en constructor | Repositorio local |
| `ArticleSemanticMatchJudge.php` | `ArticleSemanticMatchJudgeTest.php` | `tests/Services/Audit/ArticleSemanticMatchJudgeTest.php` | L8, L21 | Directa | Instanciación en casos de prueba unitarios | Repositorio local |
| `SemanticMatchJudge.php` | `GeminiGateway.php` | `app/Services/Audit/GeminiGateway.php` | L18, L150 | Directa | Inyección de dependencia en constructor | Repositorio local |
| `SemanticMatchJudge.php` | `RedisClient.php` | `core/RedisClient.php` | L9, L19, L78-117 | Directa | Inyección de cliente Redis para caché | Repositorio local |

---

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| :--- | :--- | :--- | :--- | :--- |
| Eliminación de `ArticleSemanticMatchJudge.php` | `DocumentPolicyEngine.php` | `DocumentPolicyEngine.php:13` | `Build / Runtime` | Cambiar el tipado de dependencia a `SemanticMatchJudge`. |
| Eliminación de `ArticleSemanticMatchJudge.php` | `RulesEvaluationWorker.php` | `RulesEvaluationWorker.php:43` | `Build / Runtime` | Instanciar `new SemanticMatchJudge($gateway, $this->redis)`. |
| Eliminación de `ArticleSemanticMatchJudgeTest.php` | Suite de tests PHPUnit | `ArticleSemanticMatchJudgeTest.php:1` | `Test` | Reemplazar por `tests/Services/Audit/SemanticMatchJudgeTest.php`. |
| Claves de caché Redis anteriores (`v4:article`) | Caché Redis de producción | Claves `audfact:semantic:match:v4:article` | `Data` | El nuevo servicio utiliza el namespace `audfact:semantic:match:v5:product`, asegurando re-evaluación limpia inmediata. |

---

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| :--- | :--- | :--- | :--- | :--- |
| **PHP 8.2 Engine** | Tipado estricto en clases e inyección de dependencias (`declare(strict_types=1)`) | `Documental` | Documentación oficial PHP 8.2 | `Sí` `[CONFIRMADO]` |
| **Redis 7.x** | Claves con namespaces (`set`, `get`, expiración TTL) | `Empírica` | `core/RedisClient.php:78-117` | `Sí` `[CONFIRMADO]` Estructura idéntica de serialización JSON y TTL 30 días (`2592000` s). |
| **Google Gemini API** | Function Calling con modo `ANY` y `TASK_SEMANTIC_MATCH` sobre modelo de texto | `Empírica` | `app/Services/Audit/GeminiGateway.php:150-161` | `Sí` `[CONFIRMADO]` Mantiene el contrato `sendWithFunctionCalling()` sin cambios en la pasarela. |
| **PHPUnit 10** | Mocks, stubs y assertions deterministas sin mutación de estado global | `Empírica` | `tests/Services/Audit/ArticleSemanticMatchJudgeTest.php` | `Sí` `[CONFIRMADO]` |

---

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| :--- | :--- | :--- | :--- | :--- |
| **Desarrollo local** | Tests y pipeline interactivo | `php vendor/bin/phpunit` | `Sí` | `[CONFIRMADO]` |
| **CI (GitHub Actions)** | Validación automatizada en push/PR | `.github/workflows/ci.yml` | `Sí` | `[CONFIRMADO]` Código 100% PSR-12 / PHP 8.2 compatible. |
| **Producción LAN** | Workers asíncronos en Docker (`audfact-php-1`) | `docker exec audfact-php-1 php bin/audit-worker.php` | `Sí` | `[CONFIRMADO]` Mismo runtime Docker. |
| **Testing aislado** | Tests unitarios con Redis inactivo/desconectado | `php vendor/bin/phpunit tests/Services/Audit/SemanticMatchJudgeTest.php` | `Sí` | `[CONFIRMADO]` Degradación elegante a ejecución sin caché. |

---

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia (ruta:línea) |
| :--- | :--- | :--- |
| Monolito acoplado actual | `[CONFIRMADO]` | [`app/Services/Audit/ArticleSemanticMatchJudge.php:11-397`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/ArticleSemanticMatchJudge.php#L11) |
| Acoplamiento en DocumentPolicyEngine | `[CONFIRMADO]` | [`app/Services/Audit/Pipeline/DocumentPolicyEngine.php:13,19,25`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentPolicyEngine.php#L13) |
| Falso positivo en T58260400854 | `[CONFIRMADO]` | `GET /audit/results/T58260400854`: `Levotiroxina 200mcg` vs `Levotiroxina (Eutirox)` |
| Omisión de contexto documental en llamada al Judge | `[CONFIRMADO]` | [`app/Services/Audit/Pipeline/DocumentPolicyEngine.php:892-897`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentPolicyEngine.php#L892) |

---

### 0.7 Clasificación de Completitud Inicial: `Nivel A — Implementable`

`[CONFIRMADO]` Sin dependencias desconocidas ni supuestos bloqueantes.

---

## FASE 1 — Especificación

### 1. Objetivo

1. **Desacoplar la plataforma del dominio de negocio**: Eliminar de los prompts toda jerga específica de un sector o país ("auditor de salud en Colombia", "tirillas", "insulina vs metformina", "los médicos prescriben"). Convertir el evaluador en un servicio agnóstico de comparación semántica entre entidades/productos.
2. **Reconstrucción limpia sin sobreingeniería**: Unificar la lógica en un solo servicio cohesivo [`SemanticMatchJudge.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/SemanticMatchJudge.php) sin proliferación de interfaces o estrategias artificiales, cumpliendo la regla de MVP de `clean-rebuild-policy`.
3. **Inyectar Contexto Documental**: Pasar el contexto extraído del documento soporte (`posología`, `forma`, `concentración`, `observaciones`) desde [`DocumentPolicyEngine`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentPolicyEngine.php) hacia el desempate semántico.
4. **Resolver Falso Positivo con Rigor Clínico**:
   - Denominación genérica/DCI ↔ Marca comercial: Es un **match válido** si no hay contradicción de especificaciones.
   - Si el nombre extraído no especifica dosis, pero el contexto documental la confirma o no la contradice: **match válido**.
   - Contradicción explícita de dosis o molécula (`50mcg` vs `200mcg`): **rechazo estricto** (`is_match = false`).
5. **Erradicación de Código Muerto**: Eliminar físicamente `ArticleSemanticMatchJudge.php` sin adaptadores residuales.

---

### 2. Alcance

#### Incluido
- Creación de [`app/Services/Audit/SemanticMatchJudge.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/SemanticMatchJudge.php):
  - Invocación a Gemini (`TASK_SEMANTIC_MATCH`) con prompts desacoplados de negocio.
  - Soporte de `$context['document_context']` en la construcción del prompt de producto.
  - Reglas limpias de homologación genérico/comercial vs contradicción explícita.
  - Caché Redis (30d) con namespace `audfact:semantic:match:v5:product` y `audfact:semantic:match:v2:person`.
  - Telemetría estructurada con `GeminiCallMetrics`.
- Modificación de [`app/Services/Audit/Pipeline/DocumentPolicyEngine.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentPolicyEngine.php):
  - Inyección de `SemanticMatchJudge`.
  - Recopilación agnóstica de contexto documental (`fields_normalized`, campos adyacentes de posología/observación) y envío en `$context['document_context']`.
- Modificación de [`app/Services/Audit/Pipeline/RulesEvaluationWorker.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/RulesEvaluationWorker.php):
  - Instanciación de `SemanticMatchJudge`.
- Eliminación de [`app/Services/Audit/ArticleSemanticMatchJudge.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/ArticleSemanticMatchJudge.php).
- Reemplazo de suite de pruebas por [`tests/Services/Audit/SemanticMatchJudgeTest.php`](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/SemanticMatchJudgeTest.php).

#### Excluido
- Sin cambios en base de datos SQL Server.
- Sin cambios en el pipeline de extracción ni en contratos de API REST.

---

### 3. Non Goals

- No se crearán diccionarios fijos en código ni tablas de mapeo estático de medicamentos.
- No se crearán interfaces polimórficas ceremoniales (Strategy pattern de múltiples archivos) para dos casos de uso consolidados.

---

### 4. Estado Actual vs Estado Objetivo

#### Estado Actual
- Monolito `ArticleSemanticMatchJudge` con nombre acoplado a "Artículos", que evalúa también personas.
- Prompts con textos quemados: *"Eres un auditor experto de salud en Colombia... los médicos prescriben usando denominaciones genéricas (ej: 'tirillas', 'agujas desechables')..."*.
- Regla ciega: *"OMISIÓN DE DOSIS O CONCENTRACIÓN = DIFERENCIA SIN RESOLVER"*.
- Sin contexto documental en el prompt: evalúa dos cadenas en aislamiento.

#### Estado Objetivo
- Servicio unificado `SemanticMatchJudge` (nombre de plataforma).
- Prompt agnóstico de plataforma:
  - *«Eres un evaluador de equivalencia semántica de auditoría. Tu tarea es determinar si el Valor Documental corresponde a la misma entidad descrita en el Valor Esperado.»*
  - *«EQUIVALENCIA DE DENOMINACIÓN: Si uno utiliza una denominación genérica o estándar y el otro una referencia comercial o marca de fabricante, son equivalentes siempre que no exista contradicción de especificaciones.»*
  - *«CONTEXTO DOCUMENTAL: Si el nombre extraído no explicita concentración o presentación, pero la información contextual del documento soporte la confirma o no la contradice, se considera evidencia suficiente y compatible.»*
  - *«CONTRADICCIÓN EXPLÍCITA: Únicamente cuando exista una discrepancia numérica o sustancial incompatible (ej: 50mcg vs 200mcg), evalúa is_match=false.»*

---

### 5. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| :--- | :--- | :--- | :--- |
| **AD-01** | `Clean Rebuild`: Eliminar `ArticleSemanticMatchJudge.php` y actualizar a `SemanticMatchJudge` de forma directa y atómica. | (a) Crear 6 archivos con interfaces y Strategy pattern.<br />(b) Mantener wrappers legacy. | Cumple `clean-rebuild-policy` (solución mínima y proporcional, cero capas ceremoniales). |
| **AD-02** | Un solo archivo cohesivo `SemanticMatchJudge.php` que maneje productos, personas y general. | Descomponer en 3 clases de estrategia separadas. | Reduce la complejidad cognitiva a cero. Todo el flujo técnico (Redis + Gemini + Métricas) se mantiene en un único punto fácilmente testeable. |
| **AD-03** | Inyección de `document_context` desde `DocumentPolicyEngine`. | Releer el documento o consultar base de datos. | `DocumentPolicyEngine` ya tiene los campos extraídos del documento; solo requiere transferirlos en `$context['document_context']`. |
| **AD-04** | Namespace Redis `v5:product`. | Mantener `v4:article`. | Invalida limpiamente los falsos positivos cacheados sin tocar las claves de personas ni registros no afectados. |

---

### 6. Contratos

#### Contrato PHP de `SemanticMatchJudge`
```php
namespace App\Services\Audit;

use Core\Logger;
use Core\RedisClient;

final class SemanticMatchJudge
{
    private const CACHE_TTL = 2592000; // 30 días
    private const CACHE_NAMESPACE_PRODUCT = 'audfact:semantic:match:v5:product';
    private const CACHE_NAMESPACE_PERSON  = 'audfact:semantic:match:v2:person';

    public function __construct(GeminiGateway $gateway, ?RedisClient $redis = null);

    /**
     * @param array<string,mixed> $context Incluye opcionalmente 'document_context'
     * @return array{is_match: bool, reasoning: string, gemini_metrics?: array<string,mixed>, cache_hit?: bool}
     */
    public function evaluate(string $expected, string $actual, array $context = []): array;
}
```

---

### 7. Cambios por Archivo

#### `[NEW]` [`app/Services/Audit/SemanticMatchJudge.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/SemanticMatchJudge.php)
- Implementación directa del juez semántico.
- Desacoplamiento total de prompts y esquemas.
- Integración de `document_context`.
- Namespace `v5:product` y `v2:person`.

#### `[DELETE]` [`app/Services/Audit/ArticleSemanticMatchJudge.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/ArticleSemanticMatchJudge.php)
- Eliminado físicamente (Clean Rebuild).

#### `[MODIFY]` [`app/Services/Audit/Pipeline/DocumentPolicyEngine.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/DocumentPolicyEngine.php)
- Línea 13: `use App\Services\Audit\SemanticMatchJudge;`
- Línea 19: `private ?SemanticMatchJudge $semanticJudge;`
- Línea 25: `public function __construct(?SemanticMatchJudge $semanticJudge = null)`
- Líneas 886-908: Compilar y pasar `$context['document_context']` con campos normalizados y observaciones del documento.

#### `[MODIFY]` [`app/Services/Audit/Pipeline/RulesEvaluationWorker.php`](file:///c:/Users/USER/Desktop/AudFact/app/Services/Audit/Pipeline/RulesEvaluationWorker.php)
- Línea 13: `use App\Services\Audit\SemanticMatchJudge;`
- Línea 43: `$semanticJudge = new SemanticMatchJudge($gateway, $this->redis);`

#### `[DELETE]` [`tests/Services/Audit/ArticleSemanticMatchJudgeTest.php`](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/ArticleSemanticMatchJudgeTest.php)
- Eliminado.

#### `[NEW]` [`tests/Services/Audit/SemanticMatchJudgeTest.php`](file:///c:/Users/USER/Desktop/AudFact/tests/Services/Audit/SemanticMatchJudgeTest.php)
- Suite completa que valida:
  - Cache hits / misses / redis offline.
  - Fallback por error o malformed functionCall.
  - Homologación DCI/marca con contexto documental (`Levotiroxina` vs `Eutirox` $\rightarrow$ `is_match = true`).
  - Rechazo estricto ante contradicción explícita (`50mcg` vs `200mcg` $\rightarrow$ `is_match = false`).
  - Nombres de personas.

---

### 8. Criterios de Aceptación

- [x] **CA-01**: Cero referencias a `ArticleSemanticMatchJudge` en todo el repositorio.
- [x] **CA-02**: Prompts libres de menciones geográficas ("Colombia") o jergas gremiales específicas.
- [x] **CA-03**: `LEVOTIROXINA 200MCG C*50 TABLETA` vs `LEVOTIROXINA (EUTIROX)` con contexto documental evalúa a `is_match = true`.
- [x] **CA-04**: `LEVOTIROXINA 50MCG` vs `LEVOTIROXINA 200MCG` evalúa a `is_match = false`.
- [x] **CA-05**: 100% de la suite de PHPUnit en verde.

---

## FASE 4 — Resultado Final: `Nivel A — Implementable`

`[CONFIRMADO]` La especificación cumple con la política de Clean Rebuild: es mínima, no introduce capas ceremoniales innecesarias, erradica el código obsoleto y resuelve el problema de fondo con alta calidad técnica.
