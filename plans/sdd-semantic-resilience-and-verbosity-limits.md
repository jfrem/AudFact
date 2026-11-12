# SDD: Resiliencia Semántica y Control Estricto de Verbosidad en Salida LLM

## Clasificación del Cambio (Triage)

| Dimensión | Valor | Justificación |
| --- | --- | --- |
| Tipo de cambio | Refactorización de robustez / Bugfix arquitectónico | Previene caídas en el worker semántico por verbosidad o contradicciones menores del LLM, limitando salida y garantizando degradación elegante sin envío a DLQ. |
| Perfil de rigor | Perfil 1 (Crítico de Runtime / IA) | Afecta el pipeline de ejecución semántica, validación de Structured Outputs y tolerancia a fallos del pipeline event-driven. |
| Superficie afectada | `app/Services/Audit/SemanticBatchContract.php`, `app/Services/Audit/SemanticMatchPromptBuilder.php`, `tests/Services/Audit/SemanticBatchContractTest.php` | Contrato agrupado de homologación semántica, generador de prompts y su suite unitaria. |
| Impacto en BD | Ninguno | No altera tablas ni esquemas SQL Server. |
| Impacto en API REST | Ninguno | Mantiene el contrato de respuesta JSON hacia el motor de políticas y frontend. |

---

## 0. Descubrimiento y Perímetro de Impacto

### 0.1 Perímetro de impacto
- `[CONFIRMADO]` `app/Services/Audit/SemanticBatchContract.php`: Construye el prompt/esquema y valida la respuesta del LLM para el arbitraje semántico de productos y personas.
- `[CONFIRMADO]` `app/Services/Audit/SemanticMatchPromptBuilder.php`: Genera contratos individuales para modo fallback.
- `[CONFIRMADO]` `tests/Services/Audit/SemanticBatchContractTest.php`: Suite unitaria que certifica el contrato.

### 0.2 Grafo de dependencias acopladas
- `[CONFIRMADO]` `app/Services/Audit/Pipeline/SemanticEvaluationWorker.php` $\rightarrow$ consume `SemanticMatchJudge::evaluateBatch()`, el cual invoca `SemanticBatchContract::body()` y `validate()`.
- `[CONFIRMADO]` `app/Services/Audit/Pipeline/DocumentPolicyEngine.php` $\rightarrow$ consume `SemanticMatchJudge::evaluateBatch()` para resolver pares huérfanos.
- `[CONFIRMADO]` `app/Services/Audit/SemanticMatchJudge.php` $\rightarrow$ orquesta la llamada contra `GeminiGateway` e interpreta las respuestas vía `SemanticBatchContract`.

### 0.3 Impacto inverso
- `[CONFIRMADO]` Regresiones evitadas: Los consumidores esperan un array asociativo `[pair_id => ['is_match' => bool, 'reasoning' => string, 'source' => 'remote']]`. Cualquier cambio en la estructura del array rompería `SemanticEvaluationWorker:109`. El formato de retorno debe preservarse intacto.
- `[CONFIRMADO]` Contradicción lógica: Si el LLM devuelve `is_match = true` pero sus subcriterios indican inconsistencia o diferencias no resueltas, la respuesta NO debe lanzar `GeminiOperationException` (que causaba 3 reintentos y muerte en DLQ). En su lugar, debe resolver de forma segura y conservadora como `is_match = false`, con su justificación correspondiente.

### 0.4 Semántica de herramientas
- `[CONFIRMADO]` Google Gemini Structured Outputs (`responseSchema` + `responseMimeType: application/json`).
- `[CONFIRMADO]` Límites de tokens: `maxOutputTokens` debe ajustarse para evitar salidas desmedidas.
- `[CONFIRMADO]` Validación multibyte en PHP (`mb_strlen`, `mb_substr`) con UTF-8 seguro.

### 0.5 Matriz de entornos de ejecución
- Local / CI: PHP 8.2 CLI + PHPUnit 10.
- Producción LAN: PHP 8.2-FPM en contenedores Docker + Redis 7 + Nginx 1.25.

### 0.6 Inventario de Supuestos
- `[CONFIRMADO]` S1: Un `reasoning` mayor a 512 caracteres no invalida la veracidad semántica de la respuesta; truncarlo a 512 caracteres es seguro para base de datos y retiene el veredicto clínico.
- `[CONFIRMADO]` S2: Ante contradicción interna en los flags del LLM (`is_match: true` con `same_dimensions_or_dose: false` o `unresolved_differences: true`), la regla de auditoría médica exige rechazar la equivalencia (`is_match = false`), no abortar el proceso ni enviar el documento a la Dead Letter Queue.

---

## FASE 1: Especificación de la Solución

### 1. Objetivos y No-Objetivos
- **Objetivo 1:** Limitar la salida del LLM desde la raíz (prompts del sistema y descripciones de esquema) forzando justificaciones breves (máximo 2 oraciones, menos de 250 caracteres).
- **Objetivo 2:** Implementar resiliencia y tolerancia a fallos en `SemanticBatchContract::validate()`: sanitizar y truncar de forma segura razonamientos extensos (`mb_substr`) en lugar de arrojar excepciones fatales.
- **Objetivo 3:** Resolver contradicciones de flags de manera determinista: ante discrepancias clínicas o diferencias no resueltas, resolver como `is_match: false` garantizando la continuidad de la auditoría.
- **No-Objetivo:** No modificar el almacenamiento en SQL Server ni alterar el motor de reglas de dispensación.

### 2. Arquitectura y Componentes
- `SemanticBatchContract`:
  1. `body()`: Agrega en el system instruction la restricción explícita de concisión (`CONCISE_REASONING_DIRECTIVE`). Presupuesto desacoplado para llamadas en lote mediante `GeminiConfig::generationOverridesFromEnv('GEMINI_SEMANTIC_BATCH', ['thinkingLevel' => 'low', 'maxOutputTokens' => 8192])`, previniendo truncados por cuota baja en lotes de hasta 16 pares y desacoplándolo del tope individual.
  2. `schema()`: Define en la propiedad `reasoning` la descripción restrictiva de longitud (máximo 250 caracteres).
  3. `validate()`:
     - Trunca limpiamente el reasoning si supera 512 caracteres (`mb_substr($reasoning, 0, 509) . '...'`).
     - Si el reasoning es una cadena vacía o espacios, asigna `'Sin justificación técnica reportada.'`.
     - Si `$result['is_match'] === true` pero algún flag de equivalencia es falso (`!$result['same_clinical_use'] || !$result['same_dimensions_or_dose'] || !$result['same_material_or_technology'] || !$result['presentation_compatible'] || $result['unresolved_differences']`), convierte `$isMatch = false;`, incorpora al razonamiento el prefijo de inconsistencia y emite telemetría preventiva mediante `Logger::warning('semantic_clinical_contradiction_degraded', ...)`.
- `SemanticMatchPromptBuilder`:
  1. Actualiza `buildProductContract()` y `buildPersonNameContract()` alineando la instrucción de máxima brevedad y limitación a 250 caracteres.

### 3. Contratos de Datos y Schemas
```json
{
  "pair_id": "string",
  "is_match": "boolean",
  "same_clinical_use": "boolean",
  "same_dimensions_or_dose": "boolean",
  "same_material_or_technology": "boolean",
  "presentation_compatible": "boolean",
  "unresolved_differences": "boolean",
  "reasoning": "string (máximo 250 caracteres instruidos, truncado defensivo a 512 si el LLM se excede)"
}
```

### 4. Flujo de Datos
```
[LLM Response]
      │
      ▼
GeminiResponseGuard::assertUsable()
      │
      ▼
json_decode(results)
      │
      ▼
Para cada resultado:
  ├─ Validar campos requeridos y tipos
  ├─ Sanitizar y truncar reasoning: mb_substr(trim($r['reasoning']), 0, 512)
  ├─ Verificar flags clínicos de coherencia:
  │    ¿is_match == true Y (algún flag false O unresolved_differences == true)?
  │       ├── SÍ ──► Degradar a is_match = false (Decisión conservadora)
  │       └── NO ──► Mantener is_match según evaluación
  └─ Retornar resultado a SemanticEvaluationWorker
```

### 5. Política de Construcción Limpia (6.1)
- **Estrategia:** Refactorización incremental.
- **Justificación:** `SemanticBatchContract` es un componente productivo consumido por `SemanticEvaluationWorker` y `DocumentPolicyEngine`. Los consumidores esperan exactamente la firma y el formato de datos existentes. Se optimiza el comportamiento interno manteniendo compatibilidad total.

### 6. Estrategia de Testing (TDD)
- Modificar y ampliar `tests/Services/Audit/SemanticBatchContractTest.php`:
  1. Test para truncamiento seguro de razonamientos excesivos (> 512 caracteres) certificando que ya NO lanza excepción y trunca correctamente.
  2. Test para resolución conservadora de contradicciones clínicas: cuando `is_match: true` viene acompañado de `same_dimensions_or_dose: false` o `unresolved_differences: true`, el resultado final debe ser `is_match: false` con preservación del reasoning.
  3. Ejecución de la suite completa con 0 regresiones.

