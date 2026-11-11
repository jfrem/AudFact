<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\GoogleDriveAuthService;
use Core\Env;
use Core\RedisClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GoogleDriveAuthServiceTest extends TestCase
{
    private string $originalEmail;
    private string $originalKey;

    private const DUMMY_RSA_KEY = "-----BEGIN PRIVATE KEY-----\n" .
        "MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDjHWIkE/r45uhY\n" .
        "Poq4fPKI2erBg4DJfWS0eOneexadAdWbij8PI12FHaE+yhKlrxeYT87Z2ceR/052\n" .
        "e5UowM+nZ2ZSM3Hqz0F8BqTbHj0WEqcARLScXfccOTZDsOENxwDryySlf4brZV3+\n" .
        "OgGDsBjqJgfOIKbxK8Lffgf/2xzjBg+FGIPv5jTIQq3z8dSqwGq+YEmFvG+RPGOL\n" .
        "c/yqyvD1KHoRkp006cnbpPuYZ+He8otp0CTXf1f6VhJMkClLYTB5lKJcKR8yBpxj\n" .
        "wHY/VsqqXF6qfnuJyo9nyeWe2TskF60TvrZcftUcj8W2hFuhpw8UitMdgiLmtEz5\n" .
        "9/5/hAzHAgMBAAECggEAQcMR1lGEw8qUr2yYnVfsoGy9GYB665zn6ovyaA3rG9Za\n" .
        "pDmRDdCXNULBnF1Frfz3j45YXmM9csxiy1L4ez7tQdNiYN3tl+igVQnVdW8ZkAkh\n" .
        "myXJyFc7BMyaJlqSp/seDKibOWKmTgXhhAoGwztb03zkmK6CuGWL/UvQhCjNgfyF\n" .
        "HFFZYviOUw3RTAo8W8m4rIXLFabdanPB70bcm4xnGFST82egGBxVAdFWV+oMY+iJ\n" .
        "STLna4LiuaqDSqQKoeYDRr5NksD6jjiyqcul/tZf4WttG85TnKwPQ6OJfhCR7gOE\n" .
        "oWD/vGnapiCiSYbpdNL6znlcFs6cpDS2HyBOA6NkRQKBgQD1IOOcoF3x0KWLTuCS\n" .
        "irvs9zfoEqXLgzGL9XFukoT3wzEvCSl9w+HtdmSRK5PGuPu+1TA6SouO1dIvfMEn\n" .
        "HCN2zst5qfn3NUyZaDaoi4ivHEBUp+MQaBibgYu8QoXDlYdXl+OeJAyUqPZFaMtq\n" .
        "+iMknAZN3fZcHL+xCnOynME69QKBgQDtL/j00t5YrbK6iqHxomvOzfiHZFgaGRok\n" .
        "SPZlIezxGQpKXYU1tEZCUDBCQsmJ+qCSpJuy6SQvm6iueUpXLnF3GG824xs4UkP/\n" .
        "3BFbilE1+pFl7+rFGwx+hFpGEtKq9D3N/OjjEBhrJhmxz6OEf7Z7mBN9lxbLpCFn\n" .
        "/j4VetpLSwKBgBaUigMCjsTYuHcls/Ar0XXZrA+3sAgYNLc0y9corhD4rQ4tJOJN\n" .
        "6gDq2Aa7Yeh7X81LbB7igQqOTcdMEardn7KxB/NUIg+QnFbBT8+k0tl2m8JwmK0J\n" .
        "OcMRYuLbqeDDZG1AM5p8raAAFHd+WMao9hXIPZhsquX/S/UuBkxcvjwFAoGABHKq\n" .
        "24R80PLo8CCbatmd5fHs4DUchdjEqbuIi1r4g4jxrXVVgy1PR/0S+psgBmthyX42\n" .
        "wUMn/5COuD7kCwB+9jnKFCBsKUE5rzmkRbAxV67MPTojj/Cy6kh3HOtLchNEeXFt\n" .
        "uJxbqN69Cp2XNmvisyyl6OwFbGo2iim1Zle4ckMCgYEAo4oTKTNzzPfEuYNLHO9h\n" .
        "vs5bVzpUKf6wS1l0RmLZDerDbIROTVJ1SWxU8LZP7qtl16CHr+7JerJoIwV8b3wF\n" .
        "6fev1R+zp5uETO+adgUiPmkbI1ivVOIHqs/3jatZenm/PHxTJ+4PGA+reg+fX3HL\n" .
        "kJsXtsIB0hkPNjM44QTlBuo=\n" .
        "-----END PRIVATE KEY-----\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEmail = (string) Env::get('GOOGLE_DRIVE_CLIENT_EMAIL', '');
        $this->originalKey = (string) Env::get('GOOGLE_DRIVE_PRIVATE_KEY', '');

        Env::set('GOOGLE_DRIVE_CLIENT_EMAIL', 'test-sa@audfact.iam.gserviceaccount.com');
        Env::set('GOOGLE_DRIVE_PRIVATE_KEY', self::DUMMY_RSA_KEY);
    }

    protected function tearDown(): void
    {
        Env::set('GOOGLE_DRIVE_CLIENT_EMAIL', $this->originalEmail);
        Env::set('GOOGLE_DRIVE_PRIVATE_KEY', $this->originalKey);
        parent::tearDown();
    }

    public function testGetAccessTokenUsesCachedTokenFromRedisWhenAvailable(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->expects(self::once())
            ->method('get')
            ->with(GoogleDriveAuthService::REDIS_TOKEN_KEY)
            ->willReturn('cached-redis-token-12345');
        $redis->expects(self::once())
            ->method('ttl')
            ->with(GoogleDriveAuthService::REDIS_TOKEN_KEY)
            ->willReturn(2500);

        // Guzzle solo debe recibir la llamada de metadata con el header Authorization del token en caché
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode(['id' => 'file-123', 'name' => 'doc.pdf'])),
        ]);
        $httpClient = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $service = new GoogleDriveAuthService(httpClient: $httpClient, redis: $redis);
        $metadata = $service->getFileMetadata('file-123');

        self::assertSame('file-123', $metadata['id']);
        $lastRequest = $mockHandler->getLastRequest();
        self::assertNotNull($lastRequest);
        self::assertSame('Bearer cached-redis-token-12345', $lastRequest->getHeaderLine('Authorization'));
    }

    public function testGetAccessTokenIgnoresRedisTokenWhenTtlTooLow(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->expects(self::once())
            ->method('get')
            ->with(GoogleDriveAuthService::REDIS_TOKEN_KEY)
            ->willReturn('near-expired-token');
        // TTL de 20s es menor al margen de seguridad (30s), debe ignorarse y refrescar
        $redis->expects(self::once())
            ->method('ttl')
            ->with(GoogleDriveAuthService::REDIS_TOKEN_KEY)
            ->willReturn(20);

        $redis->expects(self::once())
            ->method('setnx')
            ->with(GoogleDriveAuthService::REDIS_LOCK_KEY, self::anything(), 10)
            ->willReturn(true);

        $redis->expects(self::once())
            ->method('set')
            ->with(GoogleDriveAuthService::REDIS_TOKEN_KEY, 'fresh-google-token', self::equalTo(3300))
            ->willReturn(true);

        $redis->expects(self::once())
            ->method('eval')
            ->willReturn(1);

        $mockHandler = new MockHandler([
            // 1. Intercambio OAuth de token con Google
            new Response(200, [], json_encode([
                'access_token' => 'fresh-google-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR)),
            // 2. Consulta de metadatos de Drive
            new Response(200, [], json_encode(['id' => 'file-456'])),
        ]);
        $httpClient = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $service = new GoogleDriveAuthService(httpClient: $httpClient, redis: $redis);
        $metadata = $service->getFileMetadata('file-456');

        self::assertSame('file-456', $metadata['id']);
        $lastRequest = $mockHandler->getLastRequest();
        self::assertNotNull($lastRequest);
        self::assertSame('Bearer fresh-google-token', $lastRequest->getHeaderLine('Authorization'));
    }

    public function testGetAccessTokenFetchesFromGoogleWhenRedisMissAndStoresInRedis(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->expects(self::once())
            ->method('get')
            ->with(GoogleDriveAuthService::REDIS_TOKEN_KEY)
            ->willReturn(null);

        $redis->expects(self::once())
            ->method('setnx')
            ->with(GoogleDriveAuthService::REDIS_LOCK_KEY, self::anything(), 10)
            ->willReturn(true);

        $redis->expects(self::once())
            ->method('set')
            ->with(GoogleDriveAuthService::REDIS_TOKEN_KEY, 'new-google-token-67890', self::equalTo(3300))
            ->willReturn(true);

        $redis->expects(self::once())
            ->method('eval')
            ->willReturn(1);

        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'new-google-token-67890',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['id' => 'file-789'])),
        ]);
        $httpClient = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $service = new GoogleDriveAuthService(httpClient: $httpClient, redis: $redis);
        $metadata = $service->getFileMetadata('file-789');

        self::assertSame('file-789', $metadata['id']);
        $lastRequest = $mockHandler->getLastRequest();
        self::assertNotNull($lastRequest);
        self::assertSame('Bearer new-google-token-67890', $lastRequest->getHeaderLine('Authorization'));
    }

    public function testWaitOnLockWhenAnotherProcessHoldsIt(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        // Primer get: no hay token
        // Segundo get (durante la espera de lock): el otro proceso ya guardó el token
        $redis->expects(self::exactly(2))
            ->method('get')
            ->with(GoogleDriveAuthService::REDIS_TOKEN_KEY)
            ->willReturnOnConsecutiveCalls(null, 'token-from-concurrent-worker');

        // setnx devuelve false: otro proceso tiene el lock
        $redis->expects(self::once())
            ->method('setnx')
            ->with(GoogleDriveAuthService::REDIS_LOCK_KEY, self::anything(), 10)
            ->willReturn(false);

        $redis->expects(self::once())
            ->method('ttl')
            ->with(GoogleDriveAuthService::REDIS_TOKEN_KEY)
            ->willReturn(3200);

        // No debe llamar a Google OAuth, solo a Drive metadata con el token del worker concurrente
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode(['id' => 'concurrent-file'])),
        ]);
        $httpClient = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $service = new GoogleDriveAuthService(httpClient: $httpClient, redis: $redis);
        $metadata = $service->getFileMetadata('concurrent-file');

        self::assertSame('concurrent-file', $metadata['id']);
        $lastRequest = $mockHandler->getLastRequest();
        self::assertNotNull($lastRequest);
        self::assertSame('Bearer token-from-concurrent-worker', $lastRequest->getHeaderLine('Authorization'));
    }

    public function testClearCachedTokenPurgesMemoryAndRedis(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->expects(self::once())
            ->method('del')
            ->with(GoogleDriveAuthService::REDIS_TOKEN_KEY)
            ->willReturn(true);

        // Primer get() devuelve token-1 (para poblar memoria)
        // Segundo get() (tras clearCachedToken) devuelve token-2 (demuestra que la memoria fue purgada)
        $redis->expects(self::exactly(2))
            ->method('get')
            ->with(GoogleDriveAuthService::REDIS_TOKEN_KEY)
            ->willReturnOnConsecutiveCalls('token-1', 'token-2');

        $redis->expects(self::exactly(2))
            ->method('ttl')
            ->with(GoogleDriveAuthService::REDIS_TOKEN_KEY)
            ->willReturn(3000);

        $mockHandler = new MockHandler([
            new Response(200, [], json_encode(['id' => 'f1'])),
            new Response(200, [], json_encode(['id' => 'f2'])),
        ]);
        $httpClient = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $service = new GoogleDriveAuthService(httpClient: $httpClient, redis: $redis);

        // 1. Primera llamada: consume 'token-1' de Redis y lo retiene en memoria
        $meta1 = $service->getFileMetadata('f1');
        self::assertSame('f1', $meta1['id']);
        self::assertSame('Bearer token-1', $mockHandler->getLastRequest()->getHeaderLine('Authorization'));

        // 2. Purgar caché: invalida tanto en Redis como en la propiedad en memoria
        $service->clearCachedToken();

        // 3. Segunda llamada: al estar purgada la memoria, DEBE consultar Redis de nuevo y usar 'token-2'
        $meta2 = $service->getFileMetadata('f2');
        self::assertSame('f2', $meta2['id']);
        self::assertSame('Bearer token-2', $mockHandler->getLastRequest()->getHeaderLine('Authorization'));
    }

    public function testDegradesToDirectOAuthWhenRedisUnavailable(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(false);

        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'direct-fallback-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['id' => 'degrade-file'])),
        ]);
        $httpClient = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $service = new GoogleDriveAuthService(httpClient: $httpClient, redis: $redis);
        $meta = $service->getFileMetadata('degrade-file');

        self::assertSame('degrade-file', $meta['id']);
        self::assertSame('Bearer direct-fallback-token', $mockHandler->getLastRequest()->getHeaderLine('Authorization'));
    }

    public function testFallsThroughToDirectOAuthWhenLockWaitTimesOut(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->method('isAvailable')->willReturn(true);

        // 1. Primer get en paso 2: miss
        // 2. setnx: false (otro worker retiene el lock)
        // 3. 10 intentos de espera en el bucle: todos devuelven null
        $redis->expects(self::exactly(11))
            ->method('get')
            ->with(GoogleDriveAuthService::REDIS_TOKEN_KEY)
            ->willReturn(null);

        $redis->expects(self::once())
            ->method('setnx')
            ->with(GoogleDriveAuthService::REDIS_LOCK_KEY, self::anything(), 10)
            ->willReturn(false);

        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'timeout-fallback-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['id' => 'timeout-file'])),
        ]);
        $httpClient = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $service = new GoogleDriveAuthService(httpClient: $httpClient, redis: $redis);
        $meta = $service->getFileMetadata('timeout-file');

        self::assertSame('timeout-file', $meta['id']);
        self::assertSame('Bearer timeout-fallback-token', $mockHandler->getLastRequest()->getHeaderLine('Authorization'));
    }
}
