<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Events;

use App\Services\Audit\Events\InternalAuditApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InternalAuditApiClientTest extends TestCase
{
    public function testConstructorRejectsMissingBaseUrl(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AUDIT_INTERNAL_API_BASE es requerido para workers');
        new InternalAuditApiClient(baseUrl: '');
    }

    public function testGetDispensationReturnsWrappedData(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'success' => true,
                'message' => 'ok',
                'data' => [
                    'header' => ['NitSec' => '2426', 'FacSec' => '87723098', 'NumeroFactura' => 'T38250701547'],
                    'items' => [],
                ],
            ], JSON_UNESCAPED_UNICODE)),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $apiClient = new InternalAuditApiClient($client, 'http://nginx');

        $result = $apiClient->getDispensation('T38250701547');

        $this->assertSame('2426', $result['header']['NitSec']);
        $this->assertSame('87723098', $result['header']['FacSec']);
    }

    public function testDownloadAttachmentReturnsMimeAndBase64Data(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'success' => true,
                'message' => 'ok',
                'data' => [
                    'mime' => 'application/pdf',
                    'data' => base64_encode('pdf-data'),
                ],
            ], JSON_UNESCAPED_UNICODE)),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $apiClient = new InternalAuditApiClient($client, 'http://nginx');

        $result = $apiClient->downloadAttachment('/dispensation/T38250701547/attachments/download/1');

        $this->assertSame('application/pdf', $result['mime']);
        $this->assertSame(base64_encode('pdf-data'), $result['data']);
    }
}
