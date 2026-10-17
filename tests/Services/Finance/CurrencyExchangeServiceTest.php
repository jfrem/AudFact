<?php

declare(strict_types=1);

namespace Tests\Services\Finance;

use App\Services\Finance\CurrencyExchangeService;
use Core\RedisClient;
use PHPUnit\Framework\TestCase;

final class CurrencyExchangeServiceTest extends TestCase
{
    public function testCacheMissQueriesDatosGovCoAndStoresInRedis(): void
    {
        $mockRedis = $this->createMock(RedisClient::class);

        // Redis cache miss inicial
        $mockRedis->expects($this->once())
            ->method('get')
            ->with(CurrencyExchangeService::REDIS_KEY_TRM)
            ->willReturn(null);

        // Debe almacenar en Redis con TTL de 24 horas (86400 segundos)
        $mockRedis->expects($this->once())
            ->method('set')
            ->with(
                CurrencyExchangeService::REDIS_KEY_TRM,
                $this->callback(function (string $json) {
                    $data = json_decode($json, true);
                    return is_array($data)
                        && isset($data['trm'])
                        && $data['trm'] === 3194.44
                        && $data['source'] === 'datos_gov_co'
                        && $data['vigencia_desde'] === '2026-10-10T00:00:00.000';
                }),
                86400
            )
            ->willReturn(true);

        $fakeDatosGovResponse = json_encode([
            [
                ':id' => 'row-sct9_pg3c~ak8a',
                'valor' => '3194.44',
                'unidad' => 'COP',
                'vigenciadesde' => '2026-10-10T00:00:00.000',
                'vigenciahasta' => '2026-10-13T00:00:00.000',
            ]
        ]);

        $httpCalls = [];
        $httpHandler = function (string $url) use (&$httpCalls, $fakeDatosGovResponse): ?string {
            $httpCalls[] = $url;
            return $fakeDatosGovResponse;
        };

        $service = new CurrencyExchangeService(
            redis: $mockRedis,
            datosGovUrl: 'https://test.datos.gov.co/api/v3/views/test/query.json',
            datosGovToken: 'test_token',
            dolarApiUrl: 'https://test.dolarapi.com/v1/usd',
            defaultTrm: 3200.00,
            httpHandler: $httpHandler
        );

        $result = $service->getUsdCopRate();

        $this->assertSame(3194.44, $result['trm']);
        $this->assertSame('datos_gov_co', $result['source']);
        $this->assertSame('2026-10-10T00:00:00.000', $result['vigencia_desde']);
        $this->assertCount(1, $httpCalls);
        $this->assertStringContainsString('app_token=test_token', $httpCalls[0]);
    }

    public function testCacheHitSkipsNetworkCall(): void
    {
        $mockRedis = $this->createMock(RedisClient::class);

        // Redis cache hit
        $cachedJson = json_encode([
            'trm' => 3194.44,
            'source' => 'datos_gov_co',
            'updated_at' => '2026-10-13 10:00:00',
            'vigencia_desde' => '2026-10-10T00:00:00.000',
            'vigencia_hasta' => '2026-10-13T00:00:00.000',
        ]);

        $mockRedis->expects($this->once())
            ->method('get')
            ->with(CurrencyExchangeService::REDIS_KEY_TRM)
            ->willReturn($cachedJson);

        // No debe intentar escribir en cache ni hacer llamadas HTTP
        $mockRedis->expects($this->never())->method('set');

        $networkCalled = false;
        $httpHandler = function (string $url) use (&$networkCalled): ?string {
            $networkCalled = true;
            return null;
        };

        $service = new CurrencyExchangeService(
            redis: $mockRedis,
            httpHandler: $httpHandler
        );

        $result = $service->getUsdCopRate();

        $this->assertSame(3194.44, $result['trm']);
        $this->assertSame('redis_cache', $result['source']);
        $this->assertFalse($networkCalled, 'No debe realizar llamadas de red ante un cache hit');
    }

    public function testFallbackToDolarApiWhenDatosGovCoFails(): void
    {
        $mockRedis = $this->createMock(RedisClient::class);

        // Cache miss
        $mockRedis->expects($this->once())
            ->method('get')
            ->with(CurrencyExchangeService::REDIS_KEY_TRM)
            ->willReturn(null);

        // Debe almacenar en cache el resultado de DólarAPI
        $mockRedis->expects($this->once())
            ->method('set')
            ->with(
                CurrencyExchangeService::REDIS_KEY_TRM,
                $this->callback(function (string $json) {
                    $data = json_decode($json, true);
                    return is_array($data)
                        && isset($data['trm'])
                        && $data['trm'] === 3200.69
                        && $data['source'] === 'dolarapi_fallback';
                }),
                86400
            )
            ->willReturn(true);

        $fakeDolarApiResponse = json_encode([
            'moneda' => 'USD',
            'nombre' => 'Dólar',
            'compra' => 3197.19,
            'venta' => 3200.69,
            'ultimoCierre' => 3226.06,
            'fechaActualizacion' => '2026-10-09T20:02:24.999Z',
        ]);

        $calls = [];
        $httpHandler = function (string $url) use (&$calls, $fakeDolarApiResponse): ?string {
            $calls[] = $url;
            if (str_contains($url, 'datos.gov.co')) {
                // Simula falla en Datos Abiertos
                return null;
            }
            if (str_contains($url, 'dolarapi.com')) {
                return $fakeDolarApiResponse;
            }
            return null;
        };

        $service = new CurrencyExchangeService(
            redis: $mockRedis,
            datosGovUrl: 'https://test.datos.gov.co/api/v3/views/test/query.json',
            dolarApiUrl: 'https://test.dolarapi.com/v1/usd',
            httpHandler: $httpHandler
        );

        $result = $service->getUsdCopRate();

        $this->assertSame(3200.69, $result['trm']);
        $this->assertSame('dolarapi_fallback', $result['source']);
        $this->assertCount(2, $calls);
    }

    public function testStaticFallbackWhenAllApisFail(): void
    {
        $mockRedis = $this->createMock(RedisClient::class);

        // Cache miss
        $mockRedis->expects($this->once())
            ->method('get')
            ->with(CurrencyExchangeService::REDIS_KEY_TRM)
            ->willReturn(null);

        // Ambas APIs retornan null (fallo)
        $httpHandler = function (string $url): ?string {
            return null;
        };

        $service = new CurrencyExchangeService(
            redis: $mockRedis,
            defaultTrm: 3200.00,
            httpHandler: $httpHandler
        );

        $result = $service->getUsdCopRate();

        $this->assertSame(3200.00, $result['trm']);
        $this->assertSame('static_fallback', $result['source']);
    }

    public function testInvalidJsonFromDatosGovFallsBackToDolarApi(): void
    {
        $mockRedis = $this->createMock(RedisClient::class);
        $mockRedis->method('get')->willReturn(null);

        $fakeDolarApiResponse = json_encode([
            'venta' => 3205.50,
        ]);

        $httpHandler = function (string $url) use ($fakeDolarApiResponse): ?string {
            if (str_contains($url, 'datos.gov.co')) {
                return 'invalid-html-response';
            }
            return $fakeDolarApiResponse;
        };

        $service = new CurrencyExchangeService(
            redis: $mockRedis,
            datosGovUrl: 'https://test.datos.gov.co/query.json',
            dolarApiUrl: 'https://test.dolarapi.com/usd',
            httpHandler: $httpHandler
        );

        $result = $service->getUsdCopRate();

        $this->assertSame(3205.50, $result['trm']);
        $this->assertSame('dolarapi_fallback', $result['source']);
    }

    public function testFindEffectiveRowForDateMatchesCorrectVigenciaWindow(): void
    {
        $rows = [
            [
                'valor' => '3181.74',
                'vigenciadesde' => '2026-10-14T00:00:00.000',
                'vigenciahasta' => '2026-10-14T00:00:00.000',
            ],
            [
                'valor' => '3194.44',
                'vigenciadesde' => '2026-10-10T00:00:00.000',
                'vigenciahasta' => '2026-10-13T00:00:00.000',
            ],
            [
                'valor' => '3218.75',
                'vigenciadesde' => '2026-10-09T00:00:00.000',
                'vigenciahasta' => '2026-10-09T00:00:00.000',
            ],
        ];

        // 13 de octubre debe coincidir con la fila de 3194.44
        $row13 = CurrencyExchangeService::findEffectiveRowForDate($rows, '2026-10-13');
        $this->assertNotNull($row13);
        $this->assertSame('3194.44', $row13['valor']);

        // 14 de octubre debe coincidir con la fila de 3181.74
        $row14 = CurrencyExchangeService::findEffectiveRowForDate($rows, '2026-10-14');
        $this->assertNotNull($row14);
        $this->assertSame('3181.74', $row14['valor']);

        // 11 de octubre (fin de semana cubierto por 10-13)
        $row11 = CurrencyExchangeService::findEffectiveRowForDate($rows, '2026-10-11');
        $this->assertNotNull($row11);
        $this->assertSame('3194.44', $row11['valor']);
    }

    public function testFindEffectiveRowForDateFallsBackToFirstValidWhenNoDateMatches(): void
    {
        $rows = [
            [
                'valor' => '3181.74',
                'vigenciadesde' => '2026-10-14T00:00:00.000',
                'vigenciahasta' => '2026-10-14T00:00:00.000',
            ],
        ];

        $fallback = CurrencyExchangeService::findEffectiveRowForDate($rows, '2026-12-31');
        $this->assertNotNull($fallback);
        $this->assertSame('3181.74', $fallback['valor']);
    }

    public function testExtractDolarApiValueSupportsValorAndVenta(): void
    {
        $this->assertSame(3194.44, CurrencyExchangeService::extractDolarApiValue(['valor' => 3194.44]));
        $this->assertSame(3194.44, CurrencyExchangeService::extractDolarApiValue(['valor' => '3194.44']));
        $this->assertSame(3200.69, CurrencyExchangeService::extractDolarApiValue(['venta' => 3200.69]));
        $this->assertNull(CurrencyExchangeService::extractDolarApiValue(['invalido' => 'foo']));
    }

    public function testGetColombiaCurrentDateReturnsValidDateFormat(): void
    {
        $date = CurrencyExchangeService::getColombiaCurrentDate();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    public function testForceRefreshBypassesCacheAndUpdatesRedis(): void
    {
        $mockRedis = $this->createMock(RedisClient::class);

        // Al forzar refresh, NUNCA debe leer de cache
        $mockRedis->expects($this->never())->method('get');

        // Y DEBE almacenar el nuevo valor en Redis
        $mockRedis->expects($this->once())
            ->method('set')
            ->with(CurrencyExchangeService::REDIS_KEY_TRM, $this->anything(), CurrencyExchangeService::DEFAULT_TTL_SECONDS)
            ->willReturn(true);

        $fakeDolarApiResponse = json_encode([
            'valor' => 3194.44,
            'fechaActualizacion' => '2026-10-13T10:00:00Z',
        ]);

        $httpHandler = function (string $url) use ($fakeDolarApiResponse): ?string {
            if (str_contains($url, 'datos.gov.co')) {
                return null;
            }
            return $fakeDolarApiResponse;
        };

        $service = new CurrencyExchangeService(
            redis: $mockRedis,
            httpHandler: $httpHandler
        );

        $result = $service->getUsdCopRate(forceRefresh: true);

        $this->assertSame(3194.44, $result['trm']);
        $this->assertSame(CurrencyExchangeService::SOURCE_DOLAR_API, $result['source']);
    }

    public function testRefreshUsdCopRateConvenienceMethod(): void
    {
        $mockRedis = $this->createMock(RedisClient::class);
        $mockRedis->expects($this->never())->method('get');
        $mockRedis->expects($this->once())->method('set')->willReturn(true);

        $fakeDolarApiResponse = json_encode(['valor' => 3195.00]);
        $service = new CurrencyExchangeService(
            redis: $mockRedis,
            httpHandler: fn() => $fakeDolarApiResponse
        );

        $result = $service->refreshUsdCopRate();

        $this->assertSame(3195.00, $result['trm']);
    }

    public function testDateRangeAndParsingUtilities(): void
    {
        $this->assertSame('2026-10-10', CurrencyExchangeService::parseVigenciaDate('2026-10-10T00:00:00.000'));
        $this->assertSame('2026-10-13', CurrencyExchangeService::parseVigenciaDate('2026-10-13'));
        $this->assertSame('', CurrencyExchangeService::parseVigenciaDate(null));
        $this->assertSame('', CurrencyExchangeService::parseVigenciaDate('   '));

        $this->assertTrue(CurrencyExchangeService::isDateWithinRange('2026-10-12', '2026-10-10T00:00:00.000', '2026-10-13T00:00:00.000'));
        $this->assertFalse(CurrencyExchangeService::isDateWithinRange('2026-10-14', '2026-10-10T00:00:00.000', '2026-10-13T00:00:00.000'));
        $this->assertFalse(CurrencyExchangeService::isDateWithinRange('2026-10-09', '2026-10-10T00:00:00.000', '2026-10-13T00:00:00.000'));
        $this->assertFalse(CurrencyExchangeService::isDateWithinRange('2026-10-12', null, '2026-10-13T00:00:00.000'));
    }

    public function testSanitizeUrlForLogsRedactsTokens(): void
    {
        $urlWithToken = 'https://www.datos.gov.co/api/v3/views/query.json?pageNumber=1&app_token=sVj0LWjA0pYlg1RLG2FOziLxC';
        $sanitized = CurrencyExchangeService::sanitizeUrlForLogs($urlWithToken);

        $this->assertStringNotContainsString('sVj0LWjA0pYlg1RLG2FOziLxC', $sanitized);
        $this->assertStringContainsString('app_token=***', $sanitized);

        $cleanUrl = 'https://co.dolarapi.com/v1/trm';
        $this->assertSame($cleanUrl, CurrencyExchangeService::sanitizeUrlForLogs($cleanUrl));
    }
}

