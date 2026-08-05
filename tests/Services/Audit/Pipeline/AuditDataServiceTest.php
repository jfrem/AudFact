<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Models\AttachmentsModel;
use App\Models\AuditConfigModel;
use App\Models\ClientsModel;
use App\Models\DispensationModel;
use App\Services\Audit\Pipeline\AuditDataService;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * Prueba de integración ligera: AuditDataService → DispensationModel stub.
 * Verifica que los filtros snake_case del pipeline llegan al modelo correctamente.
 */
final class AuditDataServiceTest extends TestCase
{
    public function testGetDispensationPassesSnakeCaseFiltersToModel(): void
    {
        $stub = new SpyDispensationModel();
        $stub->nextRows = [
            [
                'DisId'          => '877',
                'NumeroFactura'  => 'T38250701547',
                'NitSec'         => '2426',
                'NombrePaciente' => 'Test',
            ],
        ];

        $service = $this->buildService($stub);
        $result = $service->getDispensation(['dis_id' => '877']);

        $this->assertSame(['dis_id' => '877'], $stub->receivedFilters);
        $this->assertArrayHasKey('header', $result);
        $this->assertSame('877', $result['header']['DisId']);
    }

    public function testGetDispensationThrowsWhenModelReturnsEmpty(): void
    {
        $stub = new SpyDispensationModel();
        $stub->nextRows = [];

        $service = $this->buildService($stub);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FDV vacía para filtros');

        $service->getDispensation(['dis_id' => 'nonexistent']);
    }

    public function testGetDispensationWithCombinedFilters(): void
    {
        $stub = new SpyDispensationModel();
        $stub->nextRows = [
            [
                'DisId'          => '877',
                'NumeroFactura'  => 'T38250701547',
                'NitSec'         => '2426',
                'NombrePaciente' => 'Test',
            ],
        ];

        $service = $this->buildService($stub);
        $result = $service->getDispensation([
            'dis_id' => '877',
            'dis_det_nro' => 'T38250701547',
        ]);

        $this->assertSame([
            'dis_id' => '877',
            'dis_det_nro' => 'T38250701547',
        ], $stub->receivedFilters);
        $this->assertArrayHasKey('header', $result);
    }

    public function testGetAuditConfigThrowsDomainExceptionWhenConfigDoesNotExist(): void
    {
        $config = new StubAuditConfigModel();
        $config->nextConfig = null;

        $service = $this->buildService(new SpyDispensationModel(), $config);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("audit-config no existe para NitSec '2426'");

        $service->getAuditConfig('2426');
    }

    public function testGetAuditConfigThrowsDomainExceptionWhenConfigIsInactive(): void
    {
        $config = new StubAuditConfigModel();
        $config->nextConfig = ['activo' => false];

        $service = $this->buildService(new SpyDispensationModel(), $config);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("audit-config inactiva para NitSec '2426'");

        $service->getAuditConfig('2426');
    }

    public function testGetAttachmentsUsesPhysicalQueryOnlyInsideAuditPipeline(): void
    {
        // Arrange:
        $attachments = new SpyAttachmentsModel();
        $attachments->physicalResult = [[
            'attachment_id' => '6',
            'physical_document_name' => 'AUTORIZACION',
            'storage_type' => 'BLOB',
        ]];
        $service = $this->buildService(new SpyDispensationModel(), attachmentsModel: $attachments);

        // Act:
        $result = $service->getAttachments('T38250701547', '2624');

        // Assert:
        $this->assertSame($attachments->physicalResult, $result);
        $this->assertSame([['T38250701547', '2624']], $attachments->physicalCalls);
        $this->assertSame([], $attachments->publicCalls);
    }

    private function buildService(
        SpyDispensationModel $dispensation,
        ?AuditConfigModel $configModel = null,
        ?AttachmentsModel $attachmentsModel = null
    ): AuditDataService {
        // Crear stubs sin conexión a BD usando ReflectionClass
        $clientsStub = (new \ReflectionClass(ClientsModel::class))->newInstanceWithoutConstructor();
        $configStub  = $configModel ?? (new \ReflectionClass(AuditConfigModel::class))->newInstanceWithoutConstructor();
        $attachStub  = $attachmentsModel
            ?? (new \ReflectionClass(AttachmentsModel::class))->newInstanceWithoutConstructor();

        return new AuditDataService(
            dispensationModel: $dispensation,
            clientsModel:      $clientsStub,
            auditConfigModel:  $configStub,
            attachmentsModel:  $attachStub,
        );
    }
}

/**
 * Stub que captura los filtros recibidos sin conectarse a la BD.
 */
final class SpyDispensationModel extends DispensationModel
{
    public ?array $receivedFilters = null;
    public array $nextRows = [];

    public function __construct()
    {
    }

    public function getDispensationData(array $filters): array
    {
        $this->receivedFilters = $filters;
        return $this->nextRows;
    }
}

final class StubAuditConfigModel extends AuditConfigModel
{
    /** @var array<string,mixed>|null */
    public ?array $nextConfig = null;

    public function __construct()
    {
    }

    public function getConfig(string $nitSec): ?array
    {
        return $this->nextConfig;
    }
}

final class SpyAttachmentsModel extends AttachmentsModel
{
    /** @var array<int,array<string,mixed>> */
    public array $physicalResult = [];
    /** @var array<int,array{0:string,1:string}> */
    public array $physicalCalls = [];
    /** @var array<int,array{0:string,1:string}> */
    public array $publicCalls = [];

    public function __construct()
    {
    }

    public function getPhysicalAttachmentsByDisDetNro(string $disDetNro, string $nitSec): array
    {
        $this->physicalCalls[] = [$disDetNro, $nitSec];
        return $this->physicalResult;
    }

    public function getAttachmentsByDisDetNro(string $disDetNro, string $nitSec): array
    {
        $this->publicCalls[] = [$disDetNro, $nitSec];
        return [];
    }
}
