<?php

declare(strict_types=1);

namespace Tests\Core;

use Core\AuthMiddleware;
use Core\Env;
use Core\Exceptions\HttpResponseException;
use Core\Middleware;
use PHPUnit\Framework\TestCase;

final class AuthMiddlewareTest extends TestCase
{
    private ?string $previousSecret = null;
    private array $previousServer = [];

    protected function setUp(): void
    {
        $this->previousSecret = Env::get('MCP_WEBHOOK_SECRET', null);
        $this->previousServer = $_SERVER;
        unset(
            $_SERVER['HTTP_X_API_KEY'],
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        );
    }

    protected function tearDown(): void
    {
        Env::set('MCP_WEBHOOK_SECRET', $this->previousSecret);
        $_SERVER = $this->previousServer;
    }

    public function testRejectsWith401WhenSecretIsNotConfigured(): void
    {
        Env::set('MCP_WEBHOOK_SECRET', '');
        $_SERVER['HTTP_X_API_KEY'] = 'any-token';

        $exception = $this->captureAuthFailure(static fn () => AuthMiddleware::handle());

        self::assertSame(401, $exception->getCode());
        self::assertSame('No autorizado', $exception->getData()['message']);
    }

    public function testRejectsWith401WhenHeadersAreMissing(): void
    {
        Env::set('MCP_WEBHOOK_SECRET', 'valid-secret-123');

        $exception = $this->captureAuthFailure(static fn () => AuthMiddleware::handle());

        self::assertSame(401, $exception->getCode());
    }

    public function testRejectsWith401WhenApiKeyDoesNotMatch(): void
    {
        Env::set('MCP_WEBHOOK_SECRET', 'valid-secret-123');
        $_SERVER['HTTP_X_API_KEY'] = 'wrong-secret';

        $exception = $this->captureAuthFailure(static fn () => AuthMiddleware::handle());

        self::assertSame(401, $exception->getCode());
    }

    public function testAllowsRequestWhenXApiKeyMatches(): void
    {
        Env::set('MCP_WEBHOOK_SECRET', 'valid-secret-123');
        $_SERVER['HTTP_X_API_KEY'] = 'valid-secret-123';

        AuthMiddleware::handle();

        $this->addToAssertionCount(1);
    }

    public function testAllowsRequestWhenAuthorizationBearerMatchesViaMiddlewareRunner(): void
    {
        Env::set('MCP_WEBHOOK_SECRET', 'valid-secret-123');
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer valid-secret-123';

        Middleware::register('auth', 'Core\AuthMiddleware::handle');
        Middleware::run(['auth']);

        $this->addToAssertionCount(1);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dlqRoutesProvider')]
    public function testWebRoutesEnforceAuthMiddlewareOnDlqEndpoints(string $method, string $uri): void
    {
        Env::set('MCP_WEBHOOK_SECRET', 'valid-secret-123');
        Middleware::register('auth', 'Core\AuthMiddleware::handle');

        $router = new \Core\Router();
        require __DIR__ . '/../../app/Routes/web.php';

        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;

        $exception = $this->captureAuthFailure(static fn () => $router->dispatch());

        self::assertSame(401, $exception->getCode());
        self::assertSame('No autorizado', $exception->getData()['message']);
    }

    public static function dlqRoutesProvider(): iterable
    {
        yield 'GET /audit/dlq' => ['GET', '/audit/dlq'];
        yield 'POST /audit/dlq/reprocess' => ['POST', '/audit/dlq/reprocess'];
    }

    public function testWebRoutesAllowAuthorizedDlqReprocessToReachControllerValidation(): void
    {
        Env::set('MCP_WEBHOOK_SECRET', 'valid-secret-123');
        Middleware::register('auth', 'Core\AuthMiddleware::handle');

        $router = new \Core\Router();
        require __DIR__ . '/../../app/Routes/web.php';

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/audit/dlq/reprocess';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_X_API_KEY'] = 'valid-secret-123';

        $exception = $this->captureAuthFailure(static fn () => $router->dispatch());

        self::assertSame(422, $exception->getCode());
    }

    private function captureAuthFailure(callable $callback): HttpResponseException
    {
        try {
            $callback();
        } catch (HttpResponseException $e) {
            return $e;
        }

        self::fail('Se esperaba HttpResponseException con código 401');
    }
}

