<?php

namespace Tests\Core;

use Core\RedisClient;
use PHPUnit\Framework\TestCase;
use Predis\Client;

final class RedisClientStreamParsingTest extends TestCase
{
    private RedisClient $redis;
    private Client $client;

    protected function setUp(): void
    {
        $this->redis = RedisClient::getInstance();
        $this->client = $this->createMock(Client::class);
        $this->injectRedisState($this->client, true);
    }

    public function testXReadGroupMaterializesNestedTraversables(): void
    {
        $raw = new \ArrayIterator([
            new \ArrayIterator([
                'audfact:audit.documents',
                new \ArrayIterator([
                    new \ArrayIterator([
                        '1745500000000-0',
                        new \ArrayIterator([
                            'event',
                            '{"type":"document_registered"}',
                            'attempts',
                            '1',
                        ]),
                    ]),
                ]),
            ]),
        ]);

        $this->client
            ->expects($this->once())
            ->method('executeRaw')
            ->with([
                'XREADGROUP',
                'GROUP', 'policy', 'worker-1',
                'COUNT', '1',
                'BLOCK', '2500',
                'STREAMS', 'audfact:audit.documents', '>',
            ])
            ->willReturn($raw);

        $messages = $this->redis->xReadGroup('policy', 'worker-1', 'audit.documents', 1, 2500);

        $this->assertSame([[
            'id' => '1745500000000-0',
            'fields' => [
                'event' => '{"type":"document_registered"}',
                'attempts' => '1',
            ],
        ]], $messages);
    }

    public function testXRangeMaterializesNestedTraversables(): void
    {
        $raw = new \ArrayIterator([
            new \ArrayIterator([
                '1745500000001-0',
                new \ArrayIterator([
                    'event',
                    '{"type":"dead_letter"}',
                    'last_error',
                    'timeout',
                ]),
            ]),
        ]);

        $this->client
            ->expects($this->once())
            ->method('executeRaw')
            ->with([
                'XRANGE',
                'audfact:audit.dlq',
                '-',
                '+',
                'COUNT',
                '10',
            ])
            ->willReturn($raw);

        $messages = $this->redis->xRange('audit.dlq', '-', '+', 10);

        $this->assertSame([[
            'id' => '1745500000001-0',
            'fields' => [
                'event' => '{"type":"dead_letter"}',
                'last_error' => 'timeout',
            ],
        ]], $messages);
    }

    protected function tearDown(): void
    {
        $this->injectRedisState(null, false);
    }

    private function injectRedisState(?Client $client, bool $connected): void
    {
        $clientProperty = new \ReflectionProperty(RedisClient::class, 'client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->redis, $client);

        $connectedProperty = new \ReflectionProperty(RedisClient::class, 'connected');
        $connectedProperty->setAccessible(true);
        $connectedProperty->setValue($this->redis, $connected);
    }
}
