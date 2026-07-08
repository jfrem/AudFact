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

    private function buildService(SpyDispensationModel $dispensation, ?AuditConfigModel $configModel = null): AuditDataService
    {
        // Crear stubs sin conexión a BD usando ReflectionClass
        $clientsStub = (new \ReflectionClass(ClientsModel::class))->newInstanceWithoutConstructor();
        $configStub  = $configModel ?? (new \ReflectionClass(AuditConfigModel::class))->newInstanceWithoutConstructor();
        $attachStub  = (new \ReflectionClass(AttachmentsModel::class))->newInstanceWithoutConstructor();

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
