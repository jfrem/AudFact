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
            'stream' => 'audit.documents',
        ]], $messages);
    }

    public function testXReadGroupMultiOrdersPositionalStreams(): void
    {
        $raw = new \ArrayIterator([
            new \ArrayIterator([
                'audfact:audit.inbox.priority',
                new \ArrayIterator([
                    new \ArrayIterator([
                        '1745500000000-1',
                        new \ArrayIterator([
                            'event',
                            '{"type":"audit_created","source":"single"}',
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
                'GROUP', 'orchestrator', 'worker-1',
                'COUNT', '1',
                'BLOCK', '5000',
                'STREAMS', 'audfact:audit.inbox.priority', 'audfact:audit.inbox.batch', '>', '>',
            ])
            ->willReturn($raw);

        $messages = $this->redis->xReadGroupMulti(
            'orchestrator',
            'worker-1',
            ['audit.inbox.priority', 'audit.inbox.batch'],
            1,
            5000
        );

        $this->assertSame([[
            'id' => '1745500000000-1',
            'fields' => [
                'event' => '{"type":"audit_created","source":"single"}',
            ],
            'stream' => 'audit.inbox.priority',
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
