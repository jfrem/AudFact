<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Tipos de dato para campos auditables.
 *
 * Centraliza la detección del "tipo de dato" de un campo, separándola
 * del "tipo de comparación" (AuditComparisonType: E/S/B/V).
 *
 * Reemplaza las heurísticas dispersas anteriores:
 * - AuditComparisonType::isDateField()
 * - AuditComparisonType::isQuantityField()
 * - AuditComparisonType::isNumberField()
 * - DocumentPolicyEngine::isIdentityDocumentTypeField()
 *
 * --- AUDIT-016 ---
 * Agrega CODE y PERSON_NAME para resolver los gaps sistémicos CAT-2, CAT-3 y CAT-4.
 *
 * @see AuditComparisonType para la estrategia de comparación (E/S/B/V).
 */
enum AuditFieldValueType: string
{
    case TEXT              = 'text';              // default — comparación as-is
    case DATE              = 'date';              // Fecha* — normalización ISO
    case QUANTITY          = 'quantity';          // Cantidad* — sumatoria de items
    case MONEY             = 'money';             // Vlr* — numérico no-sumable
    case IDENTITY_DOC_TYPE = 'identity_doc_type'; // TipoDocumento* — alias CC/CE/TI/etc
    case CODE              = 'code';              // Codigo*, CUM — subset comparison, multi-valor documental
    case PERSON_NAME       = 'person_name';       // NombrePaciente, Medico, Cliente — token-sort antes de semántico

    // ─── Mapeo desde nombre de campo ─────────────────────────────────────────

    /**
     * Detecta el tipo de dato a partir del nombre del campo.
     *
     * Orden de evaluación (primero el más específico):
     * 1. Campos de identidad de documento (lista exacta)
     * 2. Campos de nombre de persona (lista exacta)
     * 3. Campos de código médico (lista exacta + prefijo 'Codigo')
     * 4. Prefijo 'Fecha' → DATE
     * 5. Prefijo 'Cantidad' → QUANTITY
     * 6. Prefijo 'Vlr' → MONEY
     * 7. Default → TEXT
     */
    public static function fromFieldName(string $field): self
    {
        // 1. Tipos de documento — comparación con alias normalizados
        if (in_array($field, ['TipoDocumentoPaciente', 'TipoDocumentoMedico'], true)) {
            return self::IDENTITY_DOC_TYPE;
        }

        // 2. Nombres de persona — token-sort antes de fallback semántico
        if (in_array($field, ['NombrePaciente', 'Medico', 'Cliente', 'IPS'], true)) {
            return self::PERSON_NAME;
        }

        // 3. Códigos médicos — subset comparison, permite lista documental
        if (in_array($field, ['CodigoDiagnostico', 'CUM'], true)) {
            return self::CODE;
        }
        if (str_starts_with($field, 'Codigo')) {
            return self::CODE;
        }

        // 4. Fechas — normalización ISO
        if (str_starts_with($field, 'Fecha')) {
            return self::DATE;
        }

        // 5. Cantidades — sumable en multi-item
        if (str_starts_with($field, 'Cantidad')) {
            return self::QUANTITY;
        }

        // 6. Valores monetarios — numérico no-sumable
        if (str_starts_with($field, 'Vlr')) {
            return self::MONEY;
        }

        return self::TEXT;
    }

    // ─── Comportamientos para el PolicyEngine ─────────────────────────────────

    /**
     * ¿El documento puede traer múltiples valores para este campo?
     *
     * CODE permite listas documentales (ej: "S202, S273, S224" para CodigoDiagnostico).
     * El policy engine debe tokenizar el valor documental antes de comparar.
     */
    public function allowsMultiValueDocument(): bool
    {
        return $this === self::CODE;
    }

    /**
     * ¿La comparación FDV vs documento usa lógica de subconjunto?
     *
     * CODE: la FDV (ej: "S202") debe ser subconjunto del set documental
     * (ej: ["S202", "S273", "S224", "S325"]) → COINCIDE si S202 ∈ set.
     *
     * Resuelve CAT-3: falsos MISMATCH en listas de diagnósticos.
     */
    public function requiresSubsetComparison(): bool
    {
        return $this === self::CODE;
    }

    /**
     * ¿La comparación ordena los tokens alfabéticamente antes de comparar?
     *
     * PERSON_NAME: "GARCIA ABSALON" y "ABSALON GARCIA" tienen los mismos tokens
     * → COINCIDE sin necesidad de llamar a Gemini.
     *
     * Resuelve CAT-4: reducir llamadas innecesarias a SemanticMatchJudge.
     */
    public function requiresTokenSortComparison(): bool
    {
        return $this === self::PERSON_NAME;
    }

    /**
     * ¿El campo debería representarse como `number` en el schema de Gemini?
     *
     * Reemplaza AuditComparisonType::isNumberField().
     */
    public function isNumericForSchema(): bool
    {
        return $this === self::QUANTITY || $this === self::MONEY;
    }

    /**
     * ¿El campo multi-item debería sumarse numéricamente?
     *
     * Solo campos tipo QUANTITY (Cantidad*) se agregan por suma.
     * MONEY (Vlr*) es numérico pero no sumable por esta lógica.
     *
     * Reemplaza AuditComparisonType::isQuantityField() en resolveDocumentValue().
     */
    public function isQuantitySummable(): bool
    {
        return $this === self::QUANTITY;
    }
}
