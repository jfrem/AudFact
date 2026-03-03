<?php

namespace Tests\Services\Audit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Services\Audit\AuditPreValidator;
use App\Services\Audit\AuditFileManager;
use App\Services\Audit\AuditPersistenceService;
use App\Models\AttachmentsModel;
use App\Models\DispensationModel;

/**
 * Tests unitarios para AuditPreValidator.
 *
 * Valida las 7 reglas de pre-validación antes de enviar a Gemini.
 */
class AuditPreValidatorTest extends TestCase
{
    private MockObject&DispensationModel $dispensationModel;
    private MockObject&AttachmentsModel $attachmentsModel;
    private MockObject&AuditFileManager $fileManager;
    private MockObject&AuditPersistenceService $persistence;
    private AuditPreValidator $validator;

    protected function setUp(): void
    {
        $this->dispensationModel = $this->createMock(DispensationModel::class);
        $this->attachmentsModel = $this->createMock(AttachmentsModel::class);
        $this->fileManager = $this->createMock(AuditFileManager::class);
        $this->persistence = $this->createMock(AuditPersistenceService::class);

        $this->validator = new AuditPreValidator(
            $this->dispensationModel,
            $this->attachmentsModel,
            $this->fileManager,
            $this->persistence
        );
    }

    // ── 1. Dispensación vacía ───────────────────────────────

    public function testReturnsErrorWhenDispensationNotFound(): void
    {
        $this->dispensationModel
            ->method('getDispensationData')
            ->willReturn([]);

        $result = $this->validator->validate('FAC-001', 'DIS-001');

        $this->assertNotNull($result['result']);
        $this->assertEquals('error', $result['result']['response']);
        $this->assertStringContainsString('no encontrada', $result['result']['message']);
    }

    // ── 2. Tipo servicio human_review ──────────────────────

    public function testReturnsHumanReviewForTutelaServiceType(): void
    {
        $this->dispensationModel
            ->method('getDispensationData')
            ->willReturn([['Tipo' => 'TUTELA', 'NumeroFactura' => 'FAC-001']]);

        $this->persistence
            ->expects($this->once())
            ->method('saveResponse');

        $result = $this->validator->validate('FAC-001', 'DIS-001');

        $this->assertNotNull($result['result']);
        $this->assertEquals('human_review', $result['result']['response']);
    }

    // ── 3. Campos MIPRES incompletos ──────────────────────

    public function testReturnsErrorWhenMipresFieldsMissing(): void
    {
        $this->dispensationModel
            ->method('getDispensationData')
            ->willReturn([[
                'Tipo' => 'MIPRES',
                'NumeroFactura' => 'FAC-001',
                'NitSec' => '123',
                'Mipres' => '',
                'IdPrincipal' => '',
                'IdDirec' => '0',
                'IdProg' => null,
                'IdEntr' => '1',
                'IdRepEnt' => '1',
            ]]);

        $result = $this->validator->validate('FAC-001', 'DIS-001');

        $this->assertNotNull($result['result']);
        $this->assertStringContainsString('MIPRES incompleta', $result['result']['message']);
        $this->assertStringContainsString('Mipres', $result['result']['message']);
        $this->assertStringContainsString('IdPrincipal', $result['result']['message']);
    }

    public function testPassesMipresValidationWhenAllFieldsPresent(): void
    {
        $dispensation = [
            'Tipo' => 'MIPRES',
            'NumeroFactura' => 'FAC-001',
            'NitSec' => '123',
            'Mipres' => 'MP-100',
            'IdPrincipal' => '10',
            'IdDirec' => '20',
            'IdProg' => '30',
            'IdEntr' => '40',
            'IdRepEnt' => '50',
        ];

        $this->dispensationModel
            ->method('getDispensationData')
            ->willReturn([$dispensation]);

        $this->attachmentsModel
            ->method('getAttachmentsByInvoiceId')
            ->willReturn([['id' => 'att-1']]);

        $this->fileManager
            ->method('getMissingRequiredAttachments')
            ->willReturn([]);

        $this->fileManager
            ->method('prepareAttachments')
            ->willReturn([['label' => 'factura', 'pages' => 1]]);

        $result = $this->validator->validate('FAC-001', 'DIS-001');

        $this->assertNull($result['result'], 'Pre-validation should pass for complete MIPRES');
        $this->assertNotEmpty($result['files']);
    }

    // ── 4. Invoice ID vacío ───────────────────────────────

    public function testReturnsErrorWhenInvoiceIdEmpty(): void
    {
        $this->dispensationModel
            ->method('getDispensationData')
            ->willReturn([['Tipo' => 'PBS', 'NumeroFactura' => '', 'NitSec' => '123']]);

        $result = $this->validator->validate('', 'DIS-001');

        $this->assertNotNull($result['result']);
        $this->assertStringContainsString('no encontrado', $result['result']['message']);
    }

    // ── 5. Adjuntos vacíos ────────────────────────────────

    public function testReturnsErrorWhenNoAttachmentsFound(): void
    {
        $this->dispensationModel
            ->method('getDispensationData')
            ->willReturn([['Tipo' => 'PBS', 'NumeroFactura' => 'FAC-001', 'NitSec' => '123']]);

        $this->attachmentsModel
            ->method('getAttachmentsByInvoiceId')
            ->willReturn([]);

        $result = $this->validator->validate('FAC-001', 'DIS-001');

        $this->assertNotNull($result['result']);
        $this->assertStringContainsString('no encontrado', $result['result']['message']);
    }

    // ── 6. Documentos faltantes ───────────────────────────

    public function testReturnsErrorWhenRequiredDocumentsMissing(): void
    {
        $this->dispensationModel
            ->method('getDispensationData')
            ->willReturn([['Tipo' => 'PBS', 'NumeroFactura' => 'FAC-001', 'NitSec' => '123']]);

        $this->attachmentsModel
            ->method('getAttachmentsByInvoiceId')
            ->willReturn([['id' => 'att-1']]);

        $this->fileManager
            ->method('getMissingRequiredAttachments')
            ->willReturn(['Fórmula médica']);

        $result = $this->validator->validate('FAC-001', 'DIS-001');

        $this->assertNotNull($result['result']);
        $this->assertStringContainsString('Documentos requeridos', $result['result']['message']);
        $this->assertStringContainsString('Fórmula médica', $result['result']['message']);
    }

    // ── 7. Max pages excedido ─────────────────────────────

    public function testReturnsErrorWhenFileExceedsMaxPages(): void
    {
        $this->dispensationModel
            ->method('getDispensationData')
            ->willReturn([['Tipo' => 'PBS', 'NumeroFactura' => 'FAC-001', 'NitSec' => '123']]);

        $this->attachmentsModel
            ->method('getAttachmentsByInvoiceId')
            ->willReturn([['id' => 'att-1']]);

        $this->fileManager
            ->method('getMissingRequiredAttachments')
            ->willReturn([]);

        $this->fileManager
            ->method('prepareAttachments')
            ->willReturn([['label' => 'factura', 'pages' => 5]]);

        $this->fileManager
            ->expects($this->once())
            ->method('cleanup');

        $result = $this->validator->validate('FAC-001', 'DIS-001');

        $this->assertNotNull($result['result']);
        $this->assertStringContainsString('maximo de páginas', $result['result']['message']);
    }

    // ── Happy path ────────────────────────────────────────

    public function testReturnsNullResultWhenAllValidationsPass(): void
    {
        $dispensation = [
            'Tipo' => 'PBS',
            'NumeroFactura' => 'FAC-001',
            'NitSec' => '123',
        ];

        $this->dispensationModel
            ->method('getDispensationData')
            ->willReturn([$dispensation]);

        $this->attachmentsModel
            ->method('getAttachmentsByInvoiceId')
            ->willReturn([['id' => 'att-1']]);

        $this->fileManager
            ->method('getMissingRequiredAttachments')
            ->willReturn([]);

        $preparedFiles = [
            ['label' => 'factura', 'pages' => 1, 'path' => '/tmp/file.pdf'],
        ];

        $this->fileManager
            ->method('prepareAttachments')
            ->willReturn($preparedFiles);

        $result = $this->validator->validate('FAC-001', 'DIS-001');

        $this->assertNull($result['result'], 'Pre-validation should pass');
        $this->assertEquals($preparedFiles, $result['files']);
        $this->assertEquals($dispensation, $result['dispensation']);
        $this->assertIsFloat($result['dataFetchMs']);
        $this->assertIsFloat($result['filePrepMs']);
    }

    // ── Error origin ──────────────────────────────────────

    public function testFailedValidationsMarkErrorOriginAsBusiness(): void
    {
        $this->dispensationModel
            ->method('getDispensationData')
            ->willReturn([]);

        $result = $this->validator->validate('FAC-001', 'DIS-001');

        $this->assertEquals('business', $result['result']['_errorOrigin']);
    }
}
