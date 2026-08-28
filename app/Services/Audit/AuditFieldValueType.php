<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Tipos de dato para campos auditables.
 *
 * Centraliza los tipos de dato permitidos en el audit-config, separándolos
 * del "tipo de comparación" (AuditComparisonType: E/S/B/V).
 *
 * @see AuditComparisonType para la estrategia de comparación (E/S/B/V).
 */
enum AuditFieldValueType: string
{
    case TEXT                = 'text';
    case DATE                = 'date';
    case QUANTITY            = 'quantity';
    case MONEY               = 'money';
    case IDENTITY_DOC_TYPE   = 'identity_doc_type';
    case IDENTITY_DOC_NUMBER = 'identity_doc_number';
    case CODE                = 'code';
    case TRACE_TOKEN         = 'trace_token';
    case PERSON_NAME         = 'person_name';
    case INSTITUTION_NAME    = 'institution_name';
    case ARTICLE_NAME        = 'article_name';
    case NIT                 = 'nit';
    case AUTH_NUMBER         = 'auth_number';

    /**
     * Resuelve el tipo de dato explícito recibido desde audit-config.
     */
    public static function fromInput(string $tipoDato): self
    {
        $normalized = strtolower(trim($tipoDato));
        $valueType = self::tryFrom($normalized);
        if ($valueType === null) {
            throw new \InvalidArgumentException("TipoDato inválido: {$tipoDato}");
        }

        return $valueType;
    }

    /**
     * @return array<int,string>
     */
    public static function allowedValuesForTipoCampo(string $tipoCampo): array
    {
        return array_map(
            static fn(self $case): string => $case->value,
            self::allowedTypesForTipoCampo($tipoCampo)
        );
    }

    public function isAllowedForTipoCampo(string $tipoCampo): bool
    {
        return in_array($this, self::allowedTypesForTipoCampo($tipoCampo), true);
    }

    /**
     * ¿El documento puede traer múltiples valores para este campo?
     *
     * CODE permite listas documentales (ej: "S202, S273, S224" para CodigoDiagnostico).
     * El policy engine debe tokenizar el valor documental antes de comparar.
     */
    public function allowsMultiValueDocument(): bool
    {
        return $this === self::CODE
            || $this === self::TRACE_TOKEN
            || $this === self::ARTICLE_NAME;
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
     * Resuelve CAT-4: reducir llamadas innecesarias a ArticleSemanticMatchJudge.
     */
    public function requiresTokenSortComparison(): bool
    {
        return $this === self::PERSON_NAME;
    }

    /**
     * ¿Puede usar Gemini como desempate semántico?
     */
    public function allowsSemanticGeminiFallback(): bool
    {
        return $this === self::ARTICLE_NAME
            || $this === self::PERSON_NAME;
    }

    /**
     * ¿El prompt debe activar instrucciones para separar identidad?
     */
    public function isIdentityPromptValue(): bool
    {
        return in_array($this, [
            self::IDENTITY_DOC_TYPE,
            self::IDENTITY_DOC_NUMBER,
            self::PERSON_NAME,
        ], true);
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

    /**
     * ¿La comparación debe evaluar sets completos de artículos con matching biyectivo?
     *
     * ARTICLE_NAME: FDV = {Med A, Med B}, Doc = {Med A, Med B} → COINCIDE
     *               FDV = {Med A, Med B}, Doc = {Med A}       → NO_CONCLUYENTE (falta Med B)
     *
     * Usa cascada léxica (normalización, substring, similitud) y semántica
     * (ArticleSemanticMatchJudge) para emparejar artículos 1:1 sin repetición.
     */
    public function requiresArticleSetComparison(): bool
    {
        return $this === self::ARTICLE_NAME;
    }

    /**
     * ¿El tipo de dato pertenece intrínsecamente al nivel de ítem/línea de detalle?
     *
     * Solo tipos que son SIEMPRE ítems independientemente del contexto.
     * Nota: Campos como CODE, DATE, TEXT pueden ser header o ítem según
     * el campo específico, por lo que NO están incluidos aquí.
     */
    public function isItemScoped(): bool
    {
        return match ($this) {
            self::ARTICLE_NAME,
            self::TRACE_TOKEN => true,
            default           => false,
        };
    }

    /**
     * Descripción fallback genérica para el schema de Gemini.
     *
     * Se usa SOLO cuando la BD (AudDispCampoCatalogo.Descripcion) no tiene
     * una descripción configurada para el campo. Las descripciones de BD
     * siempre tienen precedencia y permiten especificidad por campo
     * (ej: "prescriptor" vs "paciente").
     */
    public function fieldDescriptionFallback(): ?string
    {
        return match ($this) {
            self::IDENTITY_DOC_NUMBER => 'Solo numero del documento; transcribe cada digito individualmente de izquierda a derecha sin tipo ni nombre; verifica con cuidado la distincion entre 8, 6, 5 y 0.',
            self::PERSON_NAME         => 'Solo nombres y apellidos completos; sin tipo ni numero de documento.',
            self::IDENTITY_DOC_TYPE   => 'Solo tipo de documento: CC, CE, TI, RC, PA, PE, PPT, MS, AS, NUIP o SC.',
            self::DATE                => 'Fecha visible; transcribe exactamente el año y fecha impresa.',
            self::AUTH_NUMBER         => 'Solo numero de autorizacion/radicado; transcribe cada digito individualmente en orden posicional estricto de izquierda a derecha sin tipo ni texto adicional; verifica con cuidado la distincion entre 8, 6, 5, 0 y 9.',
            self::NIT                 => 'Solo numero de NIT sin digito de verificacion a menos que se solicite; transcribe cada digito con exactitud posicional; verifica con cuidado la distincion entre 8, 6, 5 y 0.',
            self::TRACE_TOKEN         => 'Lista de lotes o seriales de trazabilidad visibles (ej: ["LOTE1", "LOTE2"] o ["LOTE1"]).',
            default                   => null,
        };
    }

    /**
     * @return array<int,self>
     */
    private static function allowedTypesForTipoCampo(string $tipoCampo): array
    {
        return match (strtoupper(trim($tipoCampo))) {
            'B' => [self::QUANTITY],
            'S' => [
                self::TEXT,
                self::PERSON_NAME,
                self::INSTITUTION_NAME,
                self::ARTICLE_NAME,
            ],
            'E' => self::cases(),
            default => [],
        };
    }
}
