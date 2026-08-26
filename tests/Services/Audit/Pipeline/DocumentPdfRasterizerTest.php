<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\DocumentPdfRasterizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DocumentPdfRasterizerTest extends TestCase
{
    public function testThrowsWhenPdfRawDataIsEmpty(): void
    {
        $rasterizer = new DocumentPdfRasterizer();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Datos de PDF vacíos');
        $rasterizer->rasterize('', 'FORMULA MEDICA');
    }

    public function testThrowsWhenBinaryNotAvailable(): void
    {
        $rasterizer = new DocumentPdfRasterizer('non_existent_binary_for_test_123');
        $rawPdf = "%PDF-1.4\n%%EOF\n";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pdftoppm no disponible');

        $rasterizer->rasterize($rawPdf, 'FORMULA MEDICA');
    }

    public function testThrowsWhenCorruptedPdfFailsRasterization(): void
    {
        $rasterizer = new DocumentPdfRasterizer();
        $corruptedPdf = "Not a real PDF binary data stream";

        if (!$rasterizer->isAvailable()) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('pdftoppm no disponible');
            $rasterizer->rasterize($corruptedPdf, 'AUTORIZACION');
            return;
        }

        $this->expectException(RuntimeException::class);
        $rasterizer->rasterize($corruptedPdf, 'AUTORIZACION');
    }

    public function testIsAvailableDetectsExistingOrMissingBinary(): void
    {
        $missing = new DocumentPdfRasterizer('totally_fake_binary_xyz_999');
        $this->assertFalse($missing->isAvailable());
    }

    public function testRasterizerCleansUpTemporaryFilesOnExecuteProcessFailure(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'audfact-runtime' . DIRECTORY_SEPARATOR . 'rasterizer';
        $beforeFiles = is_dir($tempDir) ? glob($tempDir . '/*') ?: [] : [];

        $rasterizer = new class extends DocumentPdfRasterizer {
            public function __construct()
            {
                parent::__construct('mock-binary');
            }

            public function isAvailable(): bool
            {
                return true;
            }

            protected function executeProcess(string $cmd): void
            {
                // Extrae output prefix y simula un archivo parcial antes de fallar
                preg_match('/([^\s]+)$/', trim($cmd), $m);
                $prefix = trim($m[1] ?? '', "'\"");
                if ($prefix !== '') {
                    file_put_contents("{$prefix}-1.jpg", "partial_bytes");
                }
                throw new RuntimeException("Simulated pdftoppm failure");
            }
        };

        try {
            $rasterizer->rasterize("%PDF-1.4\n%%EOF\n", 'DISPENSA');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated pdftoppm failure', $e->getMessage());
        }

        $afterFiles = is_dir($tempDir) ? glob($tempDir . '/*') ?: [] : [];
        $this->assertSame(count($beforeFiles), count($afterFiles), 'Temporary files were not cleaned up on failure');
    }

    public function testThrowsWhenExceedsMaxPagesAndCleansUp(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'audfact-runtime' . DIRECTORY_SEPARATOR . 'rasterizer';
        $beforeFiles = is_dir($tempDir) ? glob($tempDir . '/*') ?: [] : [];

        $rasterizer = new class extends DocumentPdfRasterizer {
            public function __construct()
            {
                parent::__construct('mock-binary');
            }

            public function isAvailable(): bool
            {
                return true;
            }

            protected function executeProcess(string $cmd): void
            {
                preg_match('/([^\s]+)$/', trim($cmd), $m);
                $prefix = trim($m[1] ?? '', "'\"");
                for ($i = 1; $i <= 52; $i++) {
                    file_put_contents("{$prefix}-{$i}.jpg", "image_page_{$i}");
                }
            }
        };

        try {
            $rasterizer->rasterize("%PDF-1.4\n%%EOF\n", 'ACTA DE ENTREGA');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Documento excede el límite máximo de 50 páginas', $e->getMessage());
        }

        $afterFiles = is_dir($tempDir) ? glob($tempDir . '/*') ?: [] : [];
        $this->assertSame(count($beforeFiles), count($afterFiles), 'Temporary files from 52 pages were not cleaned up');
    }

    public function testThrowsWhenGeneratedPageImageCannotBeRead(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'audfact-runtime' . DIRECTORY_SEPARATOR . 'rasterizer';
        $beforeFiles = is_dir($tempDir) ? glob($tempDir . '/*') ?: [] : [];

        $rasterizer = new class extends DocumentPdfRasterizer {
            public function __construct()
            {
                parent::__construct('mock-binary');
            }

            public function isAvailable(): bool
            {
                return true;
            }

            protected function executeProcess(string $cmd): void
            {
                preg_match('/([^\s]+)$/', trim($cmd), $m);
                $prefix = trim($m[1] ?? '', "'\"");
                // Archivo vacío (0 bytes) para que falle la lectura
                file_put_contents("{$prefix}-1.jpg", "");
            }
        };

        try {
            $rasterizer->rasterize("%PDF-1.4\n%%EOF\n", 'ACTA DE ENTREGA');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No se pudo leer la página generada', $e->getMessage());
        }

        $afterFiles = is_dir($tempDir) ? glob($tempDir . '/*') ?: [] : [];
        $this->assertSame(count($beforeFiles), count($afterFiles));
    }

    public function testSuccessfulMultiPageRasterizationAndCleanOutput(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'audfact-runtime' . DIRECTORY_SEPARATOR . 'rasterizer';
        $beforeFiles = is_dir($tempDir) ? glob($tempDir . '/*') ?: [] : [];

        $rasterizer = new class extends DocumentPdfRasterizer {
            public function __construct()
            {
                parent::__construct('mock-binary');
            }

            public function isAvailable(): bool
            {
                return true;
            }

            protected function executeProcess(string $cmd): void
            {
                preg_match('/([^\s]+)$/', trim($cmd), $m);
                $prefix = trim($m[1] ?? '', "'\"");
                file_put_contents("{$prefix}-1.jpg", "JPEG_BINARY_PAGE_1");
                file_put_contents("{$prefix}-2.jpg", "JPEG_BINARY_PAGE_2");
            }
        };

        $parts = $rasterizer->rasterize("%PDF-1.4\n%%EOF\n", 'FORMULA MEDICA');

        $this->assertCount(2, $parts);
        $this->assertSame('image/jpeg', $parts[0]['mime']);
        $this->assertSame(base64_encode('JPEG_BINARY_PAGE_1'), $parts[0]['data']);
        $this->assertSame('FORMULA MEDICA (Página 1/2)', $parts[0]['label']);

        $this->assertSame('image/jpeg', $parts[1]['mime']);
        $this->assertSame(base64_encode('JPEG_BINARY_PAGE_2'), $parts[1]['data']);
        $this->assertSame('FORMULA MEDICA (Página 2/2)', $parts[1]['label']);

        $afterFiles = is_dir($tempDir) ? glob($tempDir . '/*') ?: [] : [];
        $this->assertSame(count($beforeFiles), count($afterFiles), 'Temporary files were not cleaned up after successful rasterization');
    }

    public function testSuccessfulRasterizationWithRealPopplerIfAvailable(): void
    {
        $rasterizer = new DocumentPdfRasterizer();
        if (!$rasterizer->isAvailable()) {
            $this->markTestSkipped('pdftoppm no está disponible en este entorno');
        }

        // PDF minimalista válido de 1 página
        $minimalPdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>\nendobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \ntrailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n185\n%%EOF\n";

        $parts = $rasterizer->rasterize($minimalPdf, 'DOCUMENTO TEST');

        $this->assertCount(1, $parts);
        $this->assertSame('image/jpeg', $parts[0]['mime']);
        $this->assertSame('DOCUMENTO TEST', $parts[0]['label']);
        $this->assertNotEmpty($parts[0]['data']);
    }
}
