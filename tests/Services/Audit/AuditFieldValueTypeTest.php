<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\AuditFieldValueType;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios para AuditFieldValueType (AUDIT-016).
 *
 * Cubre los dos tipos nuevos (CODE, PERSON_NAME) y los métodos de
 * comportamiento que el DocumentPolicyEngine necesita para resolver
 * CAT-2, CAT-3 y CAT-4.
 */
final class AuditFieldValueTypeTest extends TestCase
{
    // ─── fromFieldName — tipos existentes ─────────────────────────────────────

    public function testFromFieldNameReturnsDATEForFechaFields(): void
    {
        $this->assertSame(AuditFieldValueType::DATE, AuditFieldValueType::fromFieldName('FechaEntrega'));
        $this->assertSame(AuditFieldValueType::DATE, AuditFieldValueType::fromFieldName('FechaFormula'));
        $this->assertSame(AuditFieldValueType::DATE, AuditFieldValueType::fromFieldName('FechaAutorizacion'));
        $this->assertSame(AuditFieldValueType::DATE, AuditFieldValueType::fromFieldName('FechaVencimiento'));
    }

    public function testFromFieldNameReturnsQUANTITYForCantidadFields(): void
    {
        $this->assertSame(AuditFieldValueType::QUANTITY, AuditFieldValueType::fromFieldName('CantidadEntregada'));
        $this->assertSame(AuditFieldValueType::QUANTITY, AuditFieldValueType::fromFieldName('CantidadPrescrita'));
    }

    public function testFromFieldNameReturnsMONEYForVlrFields(): void
    {
        $this->assertSame(AuditFieldValueType::MONEY, AuditFieldValueType::fromFieldName('VlrCobrado'));
        $this->assertSame(AuditFieldValueType::MONEY, AuditFieldValueType::fromFieldName('VlrTotal'));
    }

    public function testFromFieldNameReturnsIDENTITY_DOC_TYPEForTipoDocumentoFields(): void
    {
        $this->assertSame(AuditFieldValueType::IDENTITY_DOC_TYPE, AuditFieldValueType::fromFieldName('TipoDocumentoPaciente'));
        $this->assertSame(AuditFieldValueType::IDENTITY_DOC_TYPE, AuditFieldValueType::fromFieldName('TipoDocumentoMedico'));
    }

    public function testFromFieldNameReturnsTEXTAsDefaultFallback(): void
    {
        // Campos que no tienen tipo especializado → TEXT genérico
        $this->assertSame(AuditFieldValueType::TEXT, AuditFieldValueType::fromFieldName('NumeroFactura'));
        $this->assertSame(AuditFieldValueType::TEXT, AuditFieldValueType::fromFieldName('NumeroAutorizacion'));
        $this->assertSame(AuditFieldValueType::TEXT, AuditFieldValueType::fromFieldName('Laboratorio'));
        $this->assertSame(AuditFieldValueType::TEXT, AuditFieldValueType::fromFieldName('DocumentoPaciente'));
    }

    public function testFromFieldNameReturnsPERSON_NAMEForIPS(): void
    {
        // IPS es institución de salud — se compara con token-sort antes de semántico
        $this->assertSame(AuditFieldValueType::PERSON_NAME, AuditFieldValueType::fromFieldName('IPS'));
    }

    // ─── fromFieldName — tipos nuevos CAT-2 ───────────────────────────────────

    public function testFromFieldNameReturnsCODEForCodigoDiagnostico(): void
    {
        $this->assertSame(AuditFieldValueType::CODE, AuditFieldValueType::fromFieldName('CodigoDiagnostico'));
    }

    public function testFromFieldNameReturnsCODEForCodigo(): void
    {
        // Campos que empiezan con 'Codigo' son tratados como CODE
        $this->assertSame(AuditFieldValueType::CODE, AuditFieldValueType::fromFieldName('CodigoArticulo'));
        $this->assertSame(AuditFieldValueType::CODE, AuditFieldValueType::fromFieldName('CodigoProducto'));
    }

    public function testFromFieldNameReturnsCODEForCUM(): void
    {
        // CUM es un código de medicamento — tipo CODE explícito
        $this->assertSame(AuditFieldValueType::CODE, AuditFieldValueType::fromFieldName('CUM'));
    }

    public function testFromFieldNameReturnsPERSON_NAMEForNombrePaciente(): void
    {
        $this->assertSame(AuditFieldValueType::PERSON_NAME, AuditFieldValueType::fromFieldName('NombrePaciente'));
    }

    public function testFromFieldNameReturnsPERSON_NAMEForMedico(): void
    {
        $this->assertSame(AuditFieldValueType::PERSON_NAME, AuditFieldValueType::fromFieldName('Medico'));
    }

    public function testFromFieldNameReturnsPERSON_NAMEForCliente(): void
    {
        $this->assertSame(AuditFieldValueType::PERSON_NAME, AuditFieldValueType::fromFieldName('Cliente'));
    }

    // ─── allowsMultiValueDocument — CAT-3 ─────────────────────────────────────

    /**
     * CODE permite que el documento traiga múltiples valores (lista CIE-10, múltiples CUM, etc.)
     */
    public function testCODEAllowsMultiValueDocument(): void
    {
        $this->assertTrue(AuditFieldValueType::CODE->allowsMultiValueDocument());
    }

    public function testTEXTDoesNotAllowMultiValueDocument(): void
    {
        $this->assertFalse(AuditFieldValueType::TEXT->allowsMultiValueDocument());
    }

    public function testDATEDoesNotAllowMultiValueDocument(): void
    {
        $this->assertFalse(AuditFieldValueType::DATE->allowsMultiValueDocument());
    }

    public function testQUANTITYDoesNotAllowMultiValueDocument(): void
    {
        $this->assertFalse(AuditFieldValueType::QUANTITY->allowsMultiValueDocument());
    }

    public function testPERSON_NAMEDoesNotAllowMultiValueDocument(): void
    {
        $this->assertFalse(AuditFieldValueType::PERSON_NAME->allowsMultiValueDocument());
    }

    // ─── requiresSubsetComparison — CAT-3 ─────────────────────────────────────

    /**
     * CODE usa comparación de subconjunto: FDV debe estar contenida en el set documental.
     */
    public function testCODERequiresSubsetComparison(): void
    {
        $this->assertTrue(AuditFieldValueType::CODE->requiresSubsetComparison());
    }

    public function testTEXTDoesNotRequireSubsetComparison(): void
    {
        $this->assertFalse(AuditFieldValueType::TEXT->requiresSubsetComparison());
    }

    public function testPERSON_NAMEDoesNotRequireSubsetComparison(): void
    {
        // PERSON_NAME usa token-sort, no subset
        $this->assertFalse(AuditFieldValueType::PERSON_NAME->requiresSubsetComparison());
    }

    // ─── requiresTokenSortComparison — CAT-4 ──────────────────────────────────

    /**
     * PERSON_NAME compara tokens ordenados alfabéticamente antes de delegar a Gemini.
     */
    public function testPERSON_NAMERequiresTokenSortComparison(): void
    {
        $this->assertTrue(AuditFieldValueType::PERSON_NAME->requiresTokenSortComparison());
    }

    public function testCODEDoesNotRequireTokenSortComparison(): void
    {
        $this->assertFalse(AuditFieldValueType::CODE->requiresTokenSortComparison());
    }

    public function testTEXTDoesNotRequireTokenSortComparison(): void
    {
        $this->assertFalse(AuditFieldValueType::TEXT->requiresTokenSortComparison());
    }

    // ─── isQuantitySummable — comportamiento existente ────────────────────────

    public function testQUANTITYIsQuantitySummable(): void
    {
        $this->assertTrue(AuditFieldValueType::QUANTITY->isQuantitySummable());
    }

    public function testMONEYIsNotQuantitySummable(): void
    {
        $this->assertFalse(AuditFieldValueType::MONEY->isQuantitySummable());
    }

    public function testCODEIsNotQuantitySummable(): void
    {
        $this->assertFalse(AuditFieldValueType::CODE->isQuantitySummable());
    }

    // ─── isNumericForSchema ───────────────────────────────────────────────────

    public function testQUANTITYIsNumericForSchema(): void
    {
        $this->assertTrue(AuditFieldValueType::QUANTITY->isNumericForSchema());
    }

    public function testMONEYIsNumericForSchema(): void
    {
        $this->assertTrue(AuditFieldValueType::MONEY->isNumericForSchema());
    }

    public function testCODEIsNotNumericForSchema(): void
    {
        $this->assertFalse(AuditFieldValueType::CODE->isNumericForSchema());
    }

    public function testPERSON_NAMEIsNotNumericForSchema(): void
    {
        $this->assertFalse(AuditFieldValueType::PERSON_NAME->isNumericForSchema());
    }
}
