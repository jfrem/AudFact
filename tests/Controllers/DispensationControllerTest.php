<?php
declare(strict_types=1);

namespace Tests\Controllers;

use PHPUnit\Framework\TestCase;
use App\Controllers\DispensationController;
use App\Models\DispensationModel;
use Core\Response;

class DispensationControllerTest extends TestCase
{
    private $controller;
    private $modelMock;

    protected function setUp(): void
    {
        $this->modelMock = $this->createMock(DispensationModel::class);
        $this->controller = new class($this->modelMock) extends DispensationController {
            public function __construct($model) {
                $this->model = $model;
            }
            protected function getBody(): array {
                return ['DisDetNro' => 'D14260600440'];
            }
            protected function sanitizeData(array $data): array {
                return $data; // Mock simple para sanitize
            }
        };
    }

    public function testLookupResolvesDisIdWhenMissing(): void
    {
        // Expect resolveIdentityByDisDetNro to be called with 'D14260600440'
        $this->modelMock->expects($this->once())
            ->method('resolveIdentityByDisDetNro')
            ->with('D14260600440')
            ->willReturn('RESOLVED-123');

        // Expect getDispensationData to be called with the resolved ID
        $this->modelMock->expects($this->once())
            ->method('getDispensationData')
            ->with(['DisId' => 'RESOLVED-123', 'Dispensa' => 'D14260600440'])
            ->willReturn([
                ['DisId' => 'RESOLVED-123', 'NumeroFactura' => 'D14260600440', 'NombrePaciente' => 'Test Patient']
            ]);

        try {
            $this->controller->lookup();
        } catch (\Core\Exceptions\HttpResponseException $e) {
            $output = $e->getMessage();
            $decoded = json_decode($output, true);
            $this->assertTrue($decoded['success']);
            $this->assertEquals('RESOLVED-123', $decoded['data']['header']['DisId']);
            return;
        }

        $this->fail('HttpResponseException no fue lanzada por Response::success');
    }
}
