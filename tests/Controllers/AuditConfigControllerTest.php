<?php

declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\AuditConfigController;
use Core\Exceptions\HttpResponseException;
use PHPUnit\Framework\TestCase;

final class AuditConfigControllerTest extends TestCase
{
    public function testSaveRejectsWhenSystemPromptKeyIsMissing(): void
    {
        $body = [
            'fields' => []
        ];

        $model = new AuditConfigControllerFakeModel();
        $controller = new TestableAuditConfigController($model, $body);

        $response = $this->captureHttpResponse(static fn() => $controller->save('1165'));

        $this->assertSame(422, $response->getCode());
        $this->assertFalse($response->getData()['success']);
        $this->assertStringContainsString('El campo "systemPrompt" es requerido', $response->getData()['message']);
        $this->assertSame('unset', $model->lastSavedSystemPrompt);
        $this->assertFalse($model->saveCalled);
    }

    public function testSaveAcceptsNullSystemPromptForExplicitDeletion(): void
    {
        $body = [
            'fields' => [],
            'systemPrompt' => null
        ];

        $model = new AuditConfigControllerFakeModel();
        $controller = new TestableAuditConfigController($model, $body);

        $response = $this->captureHttpResponse(static fn() => $controller->save('1165'));

        $this->assertSame(200, $response->getCode());
        $this->assertTrue($response->getData()['success']);
        $this->assertNull($model->lastSavedSystemPrompt);
        $this->assertTrue($model->saveCalled);
    }

    public function testSaveAcceptsEmptyStringAndTransformsToNull(): void
    {
        $body = [
            'fields' => [],
            'systemPrompt' => '   ',
        ];

        $model = new AuditConfigControllerFakeModel();
        $controller = new TestableAuditConfigController($model, $body);

        $response = $this->captureHttpResponse(static fn() => $controller->save('1165'));

        $this->assertSame(200, $response->getCode());
        $this->assertTrue($response->getData()['success']);
        $this->assertNull($model->lastSavedSystemPrompt);
        $this->assertTrue($model->saveCalled);
    }

    public function testSaveAcceptsValidSystemPromptString(): void
    {
        $body = [
            'fields' => [],
            'systemPrompt' => 'Instruccion de prueba',
        ];

        $model = new AuditConfigControllerFakeModel();
        $controller = new TestableAuditConfigController($model, $body);

        $response = $this->captureHttpResponse(static fn() => $controller->save('1165'));

        $this->assertSame(200, $response->getCode());
        $this->assertTrue($response->getData()['success']);
        $this->assertSame('Instruccion de prueba', $model->lastSavedSystemPrompt);
        $this->assertTrue($model->saveCalled);
    }

    public function testSaveRejectsInvalidSystemPromptType(): void
    {
        $body = [
            'fields' => [],
            'systemPrompt' => false,
        ];

        $model = new AuditConfigControllerFakeModel();
        $controller = new TestableAuditConfigController($model, $body);

        $response = $this->captureHttpResponse(static fn() => $controller->save('1165'));

        $this->assertSame(422, $response->getCode());
        $this->assertFalse($response->getData()['success']);
        $this->assertStringContainsString('El campo "systemPrompt" debe ser null o un string', $response->getData()['message']);
        $this->assertSame('unset', $model->lastSavedSystemPrompt);
        $this->assertFalse($model->saveCalled);
    }

    private function captureHttpResponse(callable $callback): HttpResponseException
    {
        try {
            $callback();
        } catch (HttpResponseException $exception) {
            return $exception;
        }

        $this->fail('Se esperaba HttpResponseException');
    }
}

final class TestableAuditConfigController extends AuditConfigController
{
    public function __construct(AuditConfigControllerFakeModel $fakeModel, private array $body = [])
    {
        $this->model = $fakeModel;
    }

    protected function getBody(): array
    {
        return $this->body;
    }
}

final class AuditConfigControllerFakeModel
{
    public bool $saveCalled = false;
    public ?string $lastSavedSystemPrompt = 'unset';

    public function saveConfig(string $nitSec, array $fields, ?string $systemPrompt = null): bool
    {
        $this->saveCalled = true;
        $this->lastSavedSystemPrompt = $systemPrompt;
        return true;
    }
}
