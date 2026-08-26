<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Logger;
use RuntimeException;
use Throwable;

/**
 * Servicio encargado de la pre-rasterización determinista de documentos PDF a imágenes JPEG de alta resolución.
 *
 * Utiliza pdftoppm (del paquete estándar poppler-utils) para transformar cada página del PDF en una
 * imagen JPEG a 200 DPI nativa. Esto evita la rasterización a baja resolución (~72-100 DPI) del backend
 * multimodal de Google Gemini, garantizando nitidez 1:1 en tipografías médicas pequeñas (6-8pt).
 */
class DocumentPdfRasterizer
{
    public const DEFAULT_DPI = 200;
    public const DEFAULT_TIMEOUT_SECONDS = 60;
    public const MAX_PAGES = 50;

    private string $binaryPath;
    private int $dpi;
    private int $timeoutSeconds;
    private ?bool $binaryAvailable = null;

    public function __construct(
        ?string $binaryPath = null,
        int $dpi = self::DEFAULT_DPI,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS
    ) {
        $this->binaryPath     = $binaryPath ?? 'pdftoppm';
        $this->dpi            = $dpi > 0 ? $dpi : self::DEFAULT_DPI;
        $this->timeoutSeconds = $timeoutSeconds > 0 ? $timeoutSeconds : self::DEFAULT_TIMEOUT_SECONDS;
    }

    /**
     * Verifica si el binario pdftoppm está disponible en el entorno de ejecución.
     */
    public function isAvailable(): bool
    {
        if ($this->binaryAvailable !== null) {
            return $this->binaryAvailable;
        }

        $checkCmd = DIRECTORY_SEPARATOR === '\\'
            ? "where {$this->binaryPath} 2>NUL"
            : "which {$this->binaryPath} 2>/dev/null";

        $output = [];
        $exitCode = 1;
        @exec($checkCmd, $output, $exitCode);

        $this->binaryAvailable = ($exitCode === 0 && !empty($output));
        return $this->binaryAvailable;
    }

    /**
     * Convierte los bytes binarios de un archivo PDF en un arreglo de partes de imagen JPEG base64 para Gemini.
     *
     * @param string $pdfDataRaw Bytes crudos del archivo PDF (decodificados)
     * @param string $label Etiqueta del documento (ej. 'FORMULA MEDICA')
     * @param int|null $customDpi Resolución personalizada opcional en DPI
     * @return array<int, array{mime: string, data: string, label: string}>
     * @throws RuntimeException Si pdftoppm no está disponible o falla la rasterización
     */
    public function rasterize(string $pdfDataRaw, string $label, ?int $customDpi = null): array
    {
        if ($pdfDataRaw === '') {
            throw new RuntimeException('DocumentPdfRasterizer: Datos de PDF vacíos.');
        }

        // pdftoppm es obligatorio — sin fallback silencioso a PDF crudo
        if (!$this->isAvailable()) {
            throw new RuntimeException(
                'DocumentPdfRasterizer: pdftoppm no disponible en el sistema. '
                . 'Instalar poppler-utils es obligatorio para la rasterización de documentos.'
            );
        }

        $dpi = $customDpi ?? $this->dpi;
        $tempDir = $this->resolveTempDir();
        $uniqueId = bin2hex(random_bytes(8));
        $inputPdfPath = $tempDir . DIRECTORY_SEPARATOR . "input_{$uniqueId}.pdf";
        $outputPrefix = $tempDir . DIRECTORY_SEPARATOR . "page_{$uniqueId}";

        try {
            if (@file_put_contents($inputPdfPath, $pdfDataRaw) === false) {
                throw new RuntimeException("No se pudo escribir el archivo temporal PDF en {$inputPdfPath}");
            }

            // Renderizar como máximo MAX_PAGES + 1 para detectar excedente sin consumir CPU/disco innecesario
            $maxPagesToRender = self::MAX_PAGES + 1;
            $cmd = sprintf(
                '%s -jpeg -r %d -f 1 -l %d %s %s',
                escapeshellcmd($this->binaryPath),
                $dpi,
                $maxPagesToRender,
                escapeshellarg($inputPdfPath),
                escapeshellarg($outputPrefix)
            );

            $this->executeProcess($cmd);

            // Localizar imágenes generadas (pdftoppm genera prefix-1.jpg, prefix-2.jpg o prefix-01.jpg)
            $pattern = $outputPrefix . '-*.jpg';
            $generatedImages = glob($pattern) ?: [];

            if (empty($generatedImages)) {
                throw new RuntimeException(
                    'DocumentPdfRasterizer: pdftoppm no generó imágenes JPEG. '
                    . 'Verificar integridad del PDF y disponibilidad de poppler-utils.'
                );
            }

            // Ordenamiento natural (1, 2, ..., 10)
            natsort($generatedImages);
            $generatedImages = array_values($generatedImages);

            $totalPages = count($generatedImages);
            if ($totalPages > self::MAX_PAGES) {
                throw new RuntimeException(
                    "DocumentPdfRasterizer: Documento excede el límite máximo de " . self::MAX_PAGES . " páginas."
                );
            }

            $parts = [];

            for ($i = 0; $i < $totalPages; $i++) {
                $imgPath = $generatedImages[$i];
                $imgBytes = @file_get_contents($imgPath);

                if ($imgBytes === false || $imgBytes === '') {
                    throw new RuntimeException(
                        "DocumentPdfRasterizer: No se pudo leer la página generada en {$imgPath}"
                    );
                }

                $pageNumber = $i + 1;
                $pageLabel = $totalPages > 1
                    ? "{$label} (Página {$pageNumber}/{$totalPages})"
                    : $label;

                $parts[] = [
                    'mime'  => 'image/jpeg',
                    'data'  => base64_encode($imgBytes),
                    'label' => $pageLabel,
                ];
            }

            return $parts;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Logger::error('DocumentPdfRasterizer: Error inesperado durante la rasterización.', [
                'error' => $e->getMessage(),
                'label' => $label,
            ]);

            throw new RuntimeException(
                'DocumentPdfRasterizer: Error inesperado durante la rasterización: ' . $e->getMessage(),
                0,
                $e
            );
        } finally {
            // Limpieza atómica por patrón de prefijo único (cero fugas de disco ante éxito, timeout o excepción)
            $tempPattern = $tempDir . DIRECTORY_SEPARATOR . "*_{$uniqueId}*";
            foreach (glob($tempPattern) ?: [] as $tmpFile) {
                if (is_file($tmpFile)) {
                    @unlink($tmpFile);
                }
            }
        }
    }

    /**
     * Ejecuta el comando pdftoppm controlando timeouts y capturando streams de error.
     */
    protected function executeProcess(string $cmd): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException("No se pudo iniciar el proceso de rasterización: {$cmd}");
        }

        fclose($pipes[0]);

        $startTime = microtime(true);
        $stderr = '';

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $exitCode = 0;
        while (true) {
            stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            $status = proc_get_status($process);

            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            if ((microtime(true) - $startTime) > $this->timeoutSeconds) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException("Timeout de rasterización ({$this->timeoutSeconds}s) excedido");
            }

            usleep(10000); // 10ms
        }

        stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "DocumentPdfRasterizer: pdftoppm finalizó con código {$exitCode}. Stderr: " . trim($stderr)
            );
        }
    }

    /**
     * Resuelve el directorio temporal de trabajo para la rasterización.
     */
    private function resolveTempDir(): string
    {
        $dir = '/tmp/audfact-runtime/rasterizer';
        if (DIRECTORY_SEPARATOR === '\\' || !is_dir('/tmp')) {
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'audfact-runtime' . DIRECTORY_SEPARATOR . 'rasterizer';
        }

        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            $dir = sys_get_temp_dir();
        }

        return $dir;
    }
}
