<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Tipos de dato para campos auditables.
 *
 * Centraliza la detección del "tipo de dato" de un campo, separándola
 * del "tipo de comparación" (AuditComparisonType: E/S/B/V).
 *
 * @see AuditComparisonType para la estrategia de comparación (E/S/B/V).
 */
enum AuditFieldValueType: string
{
    case TEXT                = 'text';                // default — comparación as-is
    case DATE                = 'date';                // Fecha* — normalización ISO
    case QUANTITY            = 'quantity';            // Cantidad* — sumatoria de items
    case MONEY               = 'money';               // Vlr* — numérico no-sumable
    case IDENTITY_DOC_TYPE   = 'identity_doc_type';   // TipoDocumento* — alias CC/CE/TI/etc
    case IDENTITY_DOC_NUMBER = 'identity_doc_number'; // Documento* — número/token de identificación
    case CODE                = 'code';                // Codigo*, CUM — subset comparison, multi-valor documental
    case PERSON_NAME         = 'person_name';         // NombrePaciente, Medico, Cliente — token-sort antes de semántico
    case TRACE_TOKEN         = 'trace_token';         // Lote — trazabilidad de producto, comparación por set completo

    private const TRACE_TOKEN_FIELDS = [
        'Lote',
    ];

    private const IDENTITY_DOCUMENT_NUMBER_FIELDS = [
        'DocumentoPaciente',
        'DocumentoMedico',
    ];

    private const IDENTITY_DOCUMENT_TYPE_FIELDS = [
        'TipoDocumentoPaciente',
        'TipoDocumentoMedico',
    ];

    private const IDENTITY_PERSON_NAME_FIELDS = [
        'NombrePaciente',
        'Medico',
    ];

    private const PERSON_NAME_FIELDS = [
        'NombrePaciente',
        'Medico',
        'Cliente',
        'IPS',
    ];

    /**
     * Detecta el tipo de dato a partir del nombre del campo.
     *
     * Orden de evaluación (primero el más específico):
     * 1. Trazabilidad de producto (lista exacta)
     * 2. Números de documento de identidad (lista exacta)
     * 3. Tipos de documento de identidad (lista exacta)
     * 4. Campos de nombre de persona (lista exacta)
     * 5. Campos de código médico (lista exacta + prefijo 'Codigo')
     * 6. Prefijo 'Fecha' → DATE
     * 7. Prefijo 'Cantidad' → QUANTITY
     * 8. Prefijo 'Vlr' → MONEY
     * 9. Default → TEXT
     */
    public static function fromFieldName(string $field): self
    {
        // 1. Trazabilidad de producto — comparación por set completo
        if (self::isTraceTokenField($field)) {
            return self::TRACE_TOKEN;
        }

        // 2. Números de documento — extrae token de identificación sin nombres
        if (self::isIdentityDocumentNumberField($field)) {
            return self::IDENTITY_DOC_NUMBER;
        }

        // 3. Tipos de documento — comparación con alias normalizados
        if (self::isIdentityDocumentTypeField($field)) {
            return self::IDENTITY_DOC_TYPE;
        }

        // 4. Nombres de persona — token-sort antes de fallback semántico
        if (self::isPersonNameField($field)) {
            return self::PERSON_NAME;
        }

        // 5. Códigos médicos — subset comparison, permite lista documental
        if (in_array($field, ['CodigoDiagnostico', 'CUM'], true)) {
            return self::CODE;
        }
        if (str_starts_with($field, 'Codigo')) {
            return self::CODE;
        }

        // 6. Fechas — normalización ISO
        if (str_starts_with($field, 'Fecha')) {
            return self::DATE;
        }

        // 7. Cantidades — sumable en multi-item
        if (str_starts_with($field, 'Cantidad')) {
            return self::QUANTITY;
        }

        // 8. Valores monetarios — numérico no-sumable
        if (str_starts_with($field, 'Vlr')) {
            return self::MONEY;
        }

        return self::TEXT;
    }

    /**
     * Indica si el campo contiene número/token de identificación documental.
     */
    public static function isIdentityDocumentNumberField(string $field): bool
    {
        return in_array($field, self::IDENTITY_DOCUMENT_NUMBER_FIELDS, true);
    }

    /**
     * Indica si el campo contiene tipo de documento de identidad.
     */
    public static function isIdentityDocumentTypeField(string $field): bool
    {
        return in_array($field, self::IDENTITY_DOCUMENT_TYPE_FIELDS, true);
    }

    /**
     * Indica si el campo contiene nombre de persona asociado a identidad documental.
     */
    public static function isIdentityPersonNameField(string $field): bool
    {
        return in_array($field, self::IDENTITY_PERSON_NAME_FIELDS, true);
    }

    /**
     * Indica si el campo pertenece al grupo de identidad documental.
     */
    public static function isIdentityField(string $field): bool
    {
        return self::isIdentityDocumentNumberField($field)
            || self::isIdentityDocumentTypeField($field)
            || self::isIdentityPersonNameField($field);
    }

    /**
     * Indica si una lista de campos contiene al menos un campo de identidad documental.
     *
     * @param  array<int,string> $fields
     */
    public static function hasIdentityField(array $fields): bool
    {
        foreach ($fields as $field) {
            if (self::isIdentityField($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indica si el campo representa un nombre comparable por tokens.
     */
    public static function isPersonNameField(string $field): bool
    {
        return in_array($field, self::PERSON_NAME_FIELDS, true);
    }

    /**
     * ¿El documento puede traer múltiples valores para este campo?
     *
     * CODE permite listas documentales (ej: "S202, S273, S224" para CodigoDiagnostico).
     * El policy engine debe tokenizar el valor documental antes de comparar.
     */
    public function allowsMultiValueDocument(): bool
    {
        return $this === self::CODE || $this === self::TRACE_TOKEN;
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
     */
    public function isQuantitySummable(): bool
    {
        return $this === self::QUANTITY;
    }

    /**
     * ¿El campo es un token de trazabilidad (Lote, serial, etc.)?
     */
    public static function isTraceTokenField(string $field): bool
    {
        return in_array($field, self::TRACE_TOKEN_FIELDS, true);
    }

    /**
     * ¿La comparación debe evaluar sets completos de trazabilidad?
     *
     * TRACE_TOKEN: FDV = {A, B}, Doc = {A, B} → COINCIDE
     *              FDV = {A, B}, Doc = {A}    → NO_CONCLUYENTE (evidencia parcial)
     *              FDV = {A, B}, Doc = {A, C} → VALOR_DISTINTO (C no está en FDV)
     *              FDV = {A},    Doc = {A, B} → VALOR_DISTINTO (extra no registrado)
     */
    public function requiresTraceSetComparison(): bool
    {
        return $this === self::TRACE_TOKEN;
    }
}
