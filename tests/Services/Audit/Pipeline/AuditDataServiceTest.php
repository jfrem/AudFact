<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Models\AttachmentsModel;
use App\Models\AuditConfigModel;
use App\Models\ClientsModel;
use App\Models\DispensationModel;
use App\Services\Audit\Pipeline\AuditDataService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

        $this->expectException(RuntimeException::class);
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

    private function buildService(SpyDispensationModel $dispensation): AuditDataService
    {
        // Crear stubs sin conexión a BD usando ReflectionClass
        $clientsStub = (new \ReflectionClass(ClientsModel::class))->newInstanceWithoutConstructor();
        $configStub  = (new \ReflectionClass(AuditConfigModel::class))->newInstanceWithoutConstructor();
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
