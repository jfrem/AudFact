<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\AuditConfigController;
use App\Models\AuditConfigModel;
use Core\Exceptions\HttpResponseException;
use PHPUnit\Framework\TestCase;

final class AuditConfigControllerTest extends TestCase
{
    private AuditConfigModel $modelMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modelMock = $this->createMock(AuditConfigModel::class);
    }

    public function testSaveAcceptsValidAplicaServicio(): void
    {
        $this->modelMock->method('catalogFieldExists')->willReturn(true);
        $this->modelMock->expects($this->once())
            ->method('saveConfig')
            ->with(
                '2624',
                $this->callback(function (array $fields): bool {
                    $this->assertCount(1, $fields);
                    $this->assertSame('FirmaPrescriptor', $fields[0]['campoNombre']);
                    $this->assertSame('POS', $fields[0]['aplicaServicio']);
                    return true;
                }),
                null,
                false
            )
            ->willReturn(true);

        $controller = new class($this->modelMock) extends AuditConfigController {
            public function __construct($model)
            {
                $this->model = $model;
            }

            protected function getBody(): array
            {
                return [
                    'systemPrompt' => null,
                    'factorConv' => false,
                    'fields' => [
                        [
                            'docId' => 3,
                            'campoNombre' => 'FirmaPrescriptor',
                            'orden' => 13,
                            'severity' => 'alta',
                            'aplicaServicio' => 'POS',
                        ],
                    ],
                ];
            }
        };

        try {
            $controller->save('2624');
            $this->fail('HttpResponseException esperada');
        } catch (HttpResponseException $e) {
            $decoded = json_decode($e->getMessage(), true);
            $this->assertTrue($decoded['success']);
            $this->assertSame(1, $decoded['data']['fieldCount']);
        }
    }

    public function testSaveRejectsInvalidAplicaServicioCharacters(): void
    {
        $this->modelMock->method('catalogFieldExists')->willReturn(true);

        $controller = new class($this->modelMock) extends AuditConfigController {
            public function __construct($model)
            {
                $this->model = $model;
            }

            protected function getBody(): array
            {
                return [
                    'systemPrompt' => null,
                    'factorConv' => false,
                    'fields' => [
                        [
                            'docId' => 3,
                            'campoNombre' => 'FirmaPrescriptor',
                            'orden' => 13,
                            'severity' => 'alta',
                            'aplicaServicio' => 'INVALID CHARS *#$',
                        ],
                    ],
                ];
            }
        };

        try {
            $controller->save('2624');
            $this->fail('HttpResponseException con error 422 esperada');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getCode());
            $decoded = json_decode($e->getMessage(), true);
            $this->assertFalse($decoded['success']);
            $this->assertStringContainsString('aplicaServicio', $decoded['errors'][0]);
        }
    }
}
