<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Tipos de dato para campos auditables.
 *
 * Centraliza la detección del "tipo de dato" de un campo, separándola
 * del "tipo de comparación" (AuditComparisonType: E/S/B/V).
 *
 * Reemplaza las heurísticas dispersas:
 * - AuditComparisonType::isDateField()
 * - AuditComparisonType::isQuantityField()
 * - AuditComparisonType::isNumberField()
 * - DocumentPolicyEngine::isIdentityDocumentTypeField()
 */
enum AuditFieldValueType: string
{
    case TEXT              = 'text';              // default — comparación as-is
    case DATE             = 'date';              // Fecha* — normalización ISO
    case QUANTITY         = 'quantity';           // Cantidad* — sumatoria de items
    case MONEY            = 'money';             // Vlr* — numérico no-sumable
    case IDENTITY_DOC_TYPE = 'identity_doc_type'; // TipoDocumento* — alias CC/CE/TI/etc

    /**
     * Detecta el tipo de dato a partir del nombre del campo.
     *
     * Consolida las 4 heurísticas anteriores en un único punto de decisión.
     * No agrega ni quita casos — réplica exacta del comportamiento disperso.
     */
    public static function fromFieldName(string $field): self
    {
        if (in_array($field, ['TipoDocumentoPaciente', 'TipoDocumentoMedico'], true)) {
            return self::IDENTITY_DOC_TYPE;
        }

        if (str_starts_with($field, 'Fecha')) {
            return self::DATE;
        }

        if (str_starts_with($field, 'Cantidad')) {
            return self::QUANTITY;
        }

        if (str_starts_with($field, 'Vlr')) {
            return self::MONEY;
        }

        return self::TEXT;
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
