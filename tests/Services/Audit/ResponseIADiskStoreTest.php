<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\ResponseIADiskStore;
use Core\Env;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ResponseIADiskStoreTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $envBackup = [];
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envBackup = [
            'APP_ENV' => getenv('APP_ENV'),
            'AUDIT_RESPONSE_IA_ENABLED' => getenv('AUDIT_RESPONSE_IA_ENABLED'),
            'AUDIT_RESPONSE_IA_DIR' => getenv('AUDIT_RESPONSE_IA_DIR'),
        ];
        $this->baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'audfact-responseia-' . bin2hex(random_bytes(4));
        $this->resetEnvCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $this->resetEnvCache();
        $this->deleteTree($this->baseDir);

        parent::tearDown();
    }

    public function testDevelopmentPersistsRedactedSnapshotInConfiguredDirectory(): void
    {
        $this->setEnv('APP_ENV', 'development');
        $this->setEnv('AUDIT_RESPONSE_IA_ENABLED', '1');
        $this->setEnv('AUDIT_RESPONSE_IA_DIR', $this->baseDir);

        $store = new ResponseIADiskStore();
        $persisted = $store->persist(
            [
                'contents' => [
                    [
                        'inlineData' => [
                            'mimeType' => 'application/pdf',
                            'data' => 'raw-base64-payload',
                        ],
                    ],
                ],
            ],
            ['candidates' => []],
            [
                'dis_det_nro' => 'T38250701547',
                'status' => 'ok',
            ]
        );

        $this->assertTrue($persisted);

        $files = glob($this->baseDir . DIRECTORY_SEPARATOR . '*.json');
        $this->assertCount(1, $files);

        $payload = json_decode((string) file_get_contents($files[0]), true);
        $inlineData = $payload['request']['contents'][0]['inlineData'];

        $this->assertSame('development', $payload['meta']['app_env']);
        $this->assertSame('T38250701547', $payload['meta']['dis_det_nro']);
        $this->assertArrayNotHasKey('data', $inlineData);
        $this->assertTrue($inlineData['data_redacted']);
        $this->assertSame(strlen('raw-base64-payload'), $inlineData['data_base64_bytes']);
        $this->assertSame(hash('sha256', 'raw-base64-payload'), $inlineData['data_sha256']);
    }

    public function testProductionNeverCreatesSnapshotEvenWhenEnabled(): void
    {
        $this->setEnv('APP_ENV', 'production');
        $this->setEnv('AUDIT_RESPONSE_IA_ENABLED', '1');
        $this->setEnv('AUDIT_RESPONSE_IA_DIR', $this->baseDir);

        $store = new ResponseIADiskStore();
        $persisted = $store->persist([], [], ['dis_det_nro' => 'T38250701547']);

        $this->assertFalse($persisted);
        $this->assertDirectoryDoesNotExist($this->baseDir);
    }

    public function testDevelopmentCanDisableSnapshotPersistenceFromEnv(): void
    {
        $this->setEnv('APP_ENV', 'development');
        $this->setEnv('AUDIT_RESPONSE_IA_ENABLED', '0');
        $this->setEnv('AUDIT_RESPONSE_IA_DIR', $this->baseDir);

        $store = new ResponseIADiskStore();
        $persisted = $store->persist([], [], ['dis_det_nro' => 'T38250701547']);

        $this->assertFalse($persisted);
        $this->assertDirectoryDoesNotExist($this->baseDir);
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        $this->resetEnvCache();
    }

    private function resetEnvCache(): void
    {
        $cache = new ReflectionProperty(Env::class, 'cache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child)) {
                $this->deleteTree($child);
                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }
}
