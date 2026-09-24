<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\AuditConfigController;
use App\Models\AuditConfigModel;
use Core\Exceptions\HttpResponseException;
use PHPUnit\Framework\Attributes\DataProvider;
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
                false,
                null
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

    #[DataProvider('validValidityDays')]
    public function testSaveAcceptsValidDiasVigencia(array $validityPayload, ?int $expectedDays): void
    {
        // Arrange:
        $this->modelMock->expects($this->once())
            ->method('saveConfig')
            ->with(
                '2624',
                $this->isType('array'),
                null,
                false,
                $expectedDays
            )
            ->willReturn(true);

        $controller = $this->controllerWithBody($validityPayload);

        // Act:
        $response = self::captureResponse(fn() => $controller->save('2624'));

        // Assert:
        $this->assertSame(200, $response->getCode());
        $this->assertTrue($response->getData()['success']);
    }

    public static function validValidityDays(): iterable
    {
        yield 'minimum' => [['diasVigencia' => 1], 1];
        yield 'custom days' => [['diasVigencia' => 45], 45];
        yield 'maximum' => [['diasVigencia' => 365], 365];
        yield 'integer string' => [['diasVigencia' => '45'], 45];
        yield 'omitted preserves configuration' => [[], null];
        yield 'null preserves configuration' => [['diasVigencia' => null], null];
    }

    #[DataProvider('invalidValidityDays')]
    public function testSaveRejectsInvalidDiasVigencia(mixed $days): void
    {
        // Arrange:
        $this->modelMock->expects($this->never())->method('saveConfig');
        $this->modelMock->expects($this->never())->method('catalogFieldExists');
        $controller = $this->controllerWithBody(['diasVigencia' => $days]);

        // Act:
        $response = self::captureResponse(fn() => $controller->save('2624'));

        // Assert:
        $this->assertSame(422, $response->getCode());
        $this->assertFalse($response->getData()['success']);
        $this->assertStringContainsString('diasVigencia', $response->getData()['message']);
    }

    public static function invalidValidityDays(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above maximum' => [366];
        yield 'fraction' => [1.5];
        yield 'float' => [1.0];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'empty' => [''];
        yield 'text' => ['treinta'];
        yield 'array' => [[30]];
    }

    private function controllerWithBody(array $validityPayload): AuditConfigController
    {
        $body = $validityPayload + ['systemPrompt' => null, 'fields' => []];
        return new class($this->modelMock, $body) extends AuditConfigController {
            public function __construct(AuditConfigModel $model, private array $body)
            {
                $this->model = $model;
            }

            protected function getBody(): array
            {
                return $this->body;
            }
        };
    }

    private static function captureResponse(callable $action): HttpResponseException
    {
        try {
            $action();
        } catch (HttpResponseException $response) {
            return $response;
        }

        self::fail('Se esperaba una respuesta HTTP.');
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
