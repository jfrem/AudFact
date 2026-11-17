<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Env;
use Core\Logger;
use RuntimeException;
use Throwable;

/**
 * Servicio encargado de la pre-rasterización determinista de documentos PDF a imágenes JPEG de alta resolución.
 *
 * Arquitectura Clean Code y Tolerancia a Fallos:
 * 1. Sonda rápida Poppler (`pdfinfo`): valida el conteo total de páginas en milisegundos antes de tocar disco.
 * 2. Ghostscript (gs / gswin): Motor primario de alta tolerancia a fallos. Corrige y repara
 *    estructuras sintácticas malformadas generadas por librerías como iText 8 y mapea tipografías
 *    del sistema con codificación Identity-H no incrustadas (ej. Segoe UI).
 * 3. pdftoppm (poppler-utils): Motor alternativo / fallback de alto rendimiento para PDFs estándar.
 *
 * Garantiza nitidez nativa a 200 DPI y acota la rasterización a `maxPages + 1` páginas para evitar DoS.
 */
class DocumentPdfRasterizer
{
    public const DEFAULT_DPI = 200;
    public const DEFAULT_TIMEOUT_SECONDS = 60;
    public const DEFAULT_MAX_PAGES = 10;
    public const MIN_ALLOWED_PAGES = 1;
    public const MAX_ALLOWED_PAGES = 100;
    public const DEFAULT_PROBE_TIMEOUT_SECONDS = 3;
    private const PROBE_POLL_INTERVAL_US = 5000;
    private const CHUNK_SIZE = 25;

    public const ENGINE_GHOSTSCRIPT = 'ghostscript';
    public const ENGINE_PDFTOPPM    = 'pdftoppm';

    private ?string $explicitBinary;
    private int $dpi;
    private int $timeoutSeconds;
    private int $maxPages;
    private ?string $activeEngine = null;
    private ?string $activeBinary = null;
    private ?bool $binaryAvailable = null;

    public function __construct(
        ?string $binaryPath = null,
        int $dpi = self::DEFAULT_DPI,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?int $maxPages = null
    ) {
        $this->explicitBinary = $binaryPath;
        $this->dpi            = $dpi > 0 ? $dpi : self::DEFAULT_DPI;
        $this->timeoutSeconds = $timeoutSeconds > 0 ? $timeoutSeconds : self::DEFAULT_TIMEOUT_SECONDS;
        $this->maxPages       = $maxPages !== null
            ? self::normalizeMaxPages($maxPages)
            : self::resolveMaxPagesFromEnv();

        $this->resolveEngineAndBinary();
    }

    public function getMaxPages(): int
    {
        return $this->maxPages;
    }

    /**
     * Resuelve y valida el límite máximo de páginas configurado en el entorno.
     * Rango permitido: [MIN_ALLOWED_PAGES, MAX_ALLOWED_PAGES]. Default: DEFAULT_MAX_PAGES.
     */
    public static function resolveMaxPagesFromEnv(?string $raw = null): int
    {
        $value = $raw ?? Env::get('PDF_MAX_PAGES', $_ENV['PDF_MAX_PAGES'] ?? null);
        if (!is_string($value) && !is_int($value)) {
            return self::DEFAULT_MAX_PAGES;
        }

        $trimmed = trim((string) $value);
        if (preg_match('/^[0-9]+$/D', $trimmed) !== 1) {
            return self::DEFAULT_MAX_PAGES;
        }

        return self::normalizeMaxPages((int) $trimmed);
    }

    /**
     * Normaliza un límite de páginas: negativos caen a DEFAULT_MAX_PAGES; 0..N se acotan a [1, 100].
     */
    public static function normalizeMaxPages(int $pages): int
    {
        if ($pages < 0) {
            return self::DEFAULT_MAX_PAGES;
        }

        return max(self::MIN_ALLOWED_PAGES, min(self::MAX_ALLOWED_PAGES, $pages));
    }

    /**
     * Resuelve el motor y binario a utilizar según configuración explícita o auto-detección del entorno.
     */
    private function resolveEngineAndBinary(): void
    {
        if ($this->explicitBinary !== null && $this->explicitBinary !== '') {
            $lower = strtolower($this->explicitBinary);
            if (str_contains($lower, 'gs') || str_contains($lower, 'ghostscript')) {
                $this->activeEngine = self::ENGINE_GHOSTSCRIPT;
            } else {
                $this->activeEngine = self::ENGINE_PDFTOPPM;
            }
            $this->activeBinary = $this->explicitBinary;
            return;
        }

        // Auto-detección preferencial: Ghostscript primero (resuelve fuentes no incrustadas y repara PDFs), fallback a pdftoppm
        $gsCandidates = DIRECTORY_SEPARATOR === '\\'
            ? ['gswin64c', 'gswin32c', 'gs']
            : ['gs'];

        foreach ($gsCandidates as $candidate) {
            if ($this->checkBinaryExists($candidate)) {
                $this->activeEngine = self::ENGINE_GHOSTSCRIPT;
                $this->activeBinary = $candidate;
                $this->binaryAvailable = true;
                return;
            }
        }

        if ($this->checkBinaryExists('pdftoppm')) {
            $this->activeEngine = self::ENGINE_PDFTOPPM;
            $this->activeBinary = 'pdftoppm';
            $this->binaryAvailable = true;
            return;
        }

        // Ninguno disponible en el sistema operativo
        $this->activeEngine = self::ENGINE_PDFTOPPM;
        $this->activeBinary = 'pdftoppm';
        $this->binaryAvailable = false;
    }

    /**
     * Retorna el motor activo ('ghostscript' o 'pdftoppm').
     */
    public function getEngine(): string
    {
        return $this->activeEngine ?? self::ENGINE_PDFTOPPM;
    }

    /**
     * Retorna el binario en uso.
     */
    public function getBinary(): string
    {
        return $this->activeBinary ?? 'pdftoppm';
    }

    /**
     * Verifica si el binario configurado o detectado está disponible en el entorno de ejecución.
     */
    public function isAvailable(): bool
    {
        if ($this->binaryAvailable !== null) {
            return $this->binaryAvailable;
        }

        $this->binaryAvailable = $this->checkBinaryExists($this->activeBinary ?? 'pdftoppm');
        return $this->binaryAvailable;
    }

    /**
     * Comprueba la existencia de un ejecutable en el PATH del sistema operativo.
     */
    protected function checkBinaryExists(string $binary): bool
    {
        $checkCmd = DIRECTORY_SEPARATOR === '\\'
            ? "where " . escapeshellarg($binary) . " 2>NUL"
            : "which " . escapeshellarg($binary) . " 2>/dev/null";

        $output = [];
        $exitCode = 1;
        @exec($checkCmd, $output, $exitCode);

        return ($exitCode === 0 && !empty($output));
    }

    /**
     * Resuelve la ruta del binario de pdfinfo (poppler-utils) si está disponible en el entorno.
     */
    protected function resolvePdfinfoBinary(): ?string
    {
        $candidates = DIRECTORY_SEPARATOR === '\\'
            ? ['pdfinfo.exe', 'pdfinfo']
            : ['pdfinfo'];

        foreach ($candidates as $candidate) {
            if ($this->checkBinaryExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Ejecuta pdfinfo de forma aislada con timeout estricto sin alterar
     * los contadores ni llamadas de executeProcess().
     */
    protected function executePdfinfoProcess(string $cmd, int $timeoutSeconds = self::DEFAULT_PROBE_TIMEOUT_SECONDS): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // En Windows se usa bypass_shell para invocar el binario directamente sin cmd.exe,
        // garantizando que proc_terminate termine el subproceso de inmediato en caso de timeout.
        $options = DIRECTORY_SEPARATOR === '\\' ? ['bypass_shell' => true] : [];

        $process = @proc_open($cmd, $descriptors, $pipes, null, null, $options);
        if (!is_resource($process)) {
            return null;
        }

        $timeout = $timeoutSeconds > 0 ? $timeoutSeconds : self::DEFAULT_PROBE_TIMEOUT_SECONDS;
        $startTime = microtime(true);
        $exitCode = -1;

        try {
            fclose($pipes[0]);

            // En Windows, stream_set_blocking() no desactiva el bloqueo en pipes de proc_open().
            // Leer solo tras finalizar el proceso evita bloqueo sincrónico antes de evaluar el timeout.
            while (true) {
                $status = proc_get_status($process);

                if (!$status['running']) {
                    // En PHP < 8.3/POSIX, proc_close() retorna -1 tras proc_get_status().
                    $exitCode = (int) ($status['exitcode'] ?? -1);
                    break;
                }

                if ((microtime(true) - $startTime) > $timeout) {
                    @proc_terminate($process, 9);
                    return null;
                }

                usleep(self::PROBE_POLL_INTERVAL_US);
            }

            $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            return ($exitCode === 0 && trim($output) !== '') ? $output : null;
        } finally {
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            @proc_close($process);
        }
    }

    /**
     * Consulta la cantidad total de páginas con pdfinfo sin rasterizar.
     * Retorna null si pdfinfo no está disponible, falla, da timeout o su salida no es interpretable.
     */
    protected function probePageCountWithPdfinfo(string $pdfPath): ?int
    {
        $pdfinfoBinary = $this->resolvePdfinfoBinary();
        if ($pdfinfoBinary === null) {
            return null;
        }

        $cmd = sprintf('%s %s', escapeshellcmd($pdfinfoBinary), escapeshellarg($pdfPath));

        try {
            $output = $this->executePdfinfoProcess($cmd, self::DEFAULT_PROBE_TIMEOUT_SECONDS);
            if ($output === null) {
                return null;
            }

            if (preg_match('/^Pages:\s+(\d+)/m', $output, $matches) === 1) {
                return (int) $matches[1];
            }

            return null;
        } catch (Throwable) {
            // Degrada silenciosamente al sondeo acotado de rasterización ante cualquier excepción
            return null;
        }
    }

    /**
     * Orquesta la rasterización del PDF a partes multimodales JPEG a 200 DPI.
     *
     * Cada bloque tiene timeout independiente y comparte una página con el
     * anterior para detectar el final sin solicitar un rango completamente vacío.
     *
     * @param string $pdfDataRaw Bytes crudos del archivo PDF (decodificados)
     * @param string $label Etiqueta del documento (ej. 'FORMULA MEDICA')
     * @param int|null $customDpi Resolución personalizada opcional en DPI
     * @return array<int, array{mime: string, data: string, label: string}>
     * @throws RuntimeException Si las herramientas no están disponibles o falla la rasterización
     */
    public function rasterize(string $pdfDataRaw, string $label, ?int $customDpi = null): array
    {
        $this->validateInput($pdfDataRaw);
        $this->ensureBinaryAvailable();

        $dpi          = $customDpi ?? $this->dpi;
        $tempDir      = $this->resolveTempDir();
        $uniqueId     = bin2hex(random_bytes(8));
        $inputPdfPath = $tempDir . DIRECTORY_SEPARATOR . "input_{$uniqueId}.pdf";

        try {
            $this->writeTemporaryPdf($inputPdfPath, $pdfDataRaw);

            // Capa 1: Sonda rápida Poppler pdfinfo (5ms)
            $detectedPages = $this->probePageCountWithPdfinfo($inputPdfPath);
            if ($detectedPages !== null && $detectedPages > $this->maxPages) {
                throw new RasterizationFailureException(PdfContentFailure::EXCEEDS_PAGE_LIMIT);
            }

            // Capa 2: Rasterización acotada a (maxPages + 1)
            $images = [];
            $pageLimitProbe = $this->maxPages + 1;

            for ($firstPage = 1; $firstPage < $pageLimitProbe; $firstPage += self::CHUNK_SIZE - 1) {
                $lastPage = min($firstPage + self::CHUNK_SIZE - 1, $pageLimitProbe);
                $chunkId = $uniqueId . '_c' . $firstPage;
                $outputPrefix = $tempDir . DIRECTORY_SEPARATOR . "page_{$chunkId}";

                $chunkImages = $this->executeRasterization(
                    $inputPdfPath,
                    $outputPrefix,
                    $dpi,
                    $firstPage,
                    $lastPage,
                    $label,
                    $tempDir,
                    $chunkId
                );

                $renderedPageCount = count($chunkImages);
                if ($firstPage > 1) {
                    array_shift($chunkImages);
                }
                array_push($images, ...$chunkImages);

                if (count($images) > $this->maxPages) {
                    throw new RasterizationFailureException(PdfContentFailure::EXCEEDS_PAGE_LIMIT);
                }

                if ($renderedPageCount < $lastPage - $firstPage + 1) {
                    break;
                }
            }

            return $this->buildMultimodalParts($images, $label);
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
            // El prefijo raíz incluye el PDF y todos los bloques, aun si fallaron.
            $this->cleanupPrefixFiles($tempDir, $uniqueId);
        }
    }

    /**
     * Ejecuta el comando de rasterización gestionando fallback automático entre motores.
     *
     * El éxito requiere salida cero e imágenes. Un diagnóstico explícito del
     * parser permite rechazo; un fallo desconocido conserva naturaleza técnica.
     *
     * @return list<string> Imágenes del bloque en orden de página.
     */
    private function executeRasterization(
        string $inputPdfPath,
        string $outputPrefix,
        int $dpi,
        int $firstPage,
        int $lastPage,
        string $label,
        string $tempDir,
        string $chunkId
    ): array {
        $engine = $this->activeEngine ?? self::ENGINE_PDFTOPPM;
        $binary = $this->activeBinary ?? 'pdftoppm';

        $cmd = $this->buildCommand($inputPdfPath, $outputPrefix, $dpi, $lastPage, $engine, $binary, $firstPage);

        try {
            return $this->executeAndValidateOutput($cmd, $outputPrefix, $engine);
        } catch (RuntimeException $e) {
            if (!$this->shouldAttemptFallback($engine)) {
                throw $e;
            }
            Logger::warning('DocumentPdfRasterizer: intentando motor alternativo', [
                'label' => $label,
                'engine' => $engine,
                'error_class' => get_class($e),
                'reason' => $e instanceof RasterizationFailureException ? $e->reason->value : 'TECHNICAL_FAILURE',
                'error' => $e->getMessage(),
            ]);

            $this->cleanupPrefixFiles($tempDir, $chunkId);

            $fallbackCmd = $this->buildCommand(
                $inputPdfPath,
                $outputPrefix,
                $dpi,
                $lastPage,
                self::ENGINE_PDFTOPPM,
                'pdftoppm',
                $firstPage
            );

            return $this->executeAndValidateOutput($fallbackCmd, $outputPrefix, self::ENGINE_PDFTOPPM);
        }
    }

    /** @return list<string> */
    private function executeAndValidateOutput(string $cmd, string $outputPrefix, string $engine): array
    {
        $result = $this->executeProcess($cmd);
        $images = glob($outputPrefix . '-*.jpg') ?: [];
        if ($result->exitCode === 0 && $images !== []) {
            natsort($images);
            return array_values($images);
        }
        $contentFailure = $result->contentFailure();
        if ($contentFailure !== null) {
            throw new RasterizationFailureException($contentFailure);
        }
        throw new RuntimeException("DocumentPdfRasterizer: {$engine} sin salida utilizable (exit {$result->exitCode}); diagnóstico de contenido no confirmado.");
    }

    /**
     * Determina si procede ejecutar un fallback a pdftoppm.
     */
    private function shouldAttemptFallback(string $currentEngine): bool
    {
        return $this->explicitBinary === null
            && $currentEngine === self::ENGINE_GHOSTSCRIPT
            && $this->checkBinaryExists('pdftoppm');
    }

    /**
     * Lee las imágenes del disco y construye el arreglo multipart codificado en base64.
     *
     * @param array<int, string> $images
     * @return array<int, array{mime: string, data: string, label: string}>
     */
    private function buildMultimodalParts(array $images, string $label): array
    {
        $totalPages = count($images);
        $parts = [];

        foreach ($images as $index => $imgPath) {
            $imgBytes = @file_get_contents($imgPath);

            if ($imgBytes === false || $imgBytes === '') {
                throw new RuntimeException(
                    "DocumentPdfRasterizer: No se pudo leer la página generada en {$imgPath}"
                );
            }

            $pageNumber = $index + 1;
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
    }

    /**
     * Valida que los datos binarios del PDF no estén vacíos.
     */
    private function validateInput(string $pdfDataRaw): void
    {
        if ($pdfDataRaw === '') {
            throw new RuntimeException('DocumentPdfRasterizer: Datos de PDF vacíos.');
        }
    }

    /**
     * Garantiza que al menos un binario de rasterización esté disponible.
     */
    private function ensureBinaryAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException(
                'DocumentPdfRasterizer: pdftoppm no disponible en el sistema (ghostscript tampoco disponible). '
                . 'Instalar poppler-utils o ghostscript es obligatorio para la rasterización de documentos.'
            );
        }
    }

    /**
     * Escribe los bytes binarios a un archivo temporal.
     */
    private function writeTemporaryPdf(string $path, string $data): void
    {
        if (@file_put_contents($path, $data) === false) {
            throw new RuntimeException("No se pudo escribir el archivo temporal PDF en {$path}");
        }
    }

    /**
     * Construye la línea de comandos adecuada según el motor de rasterización.
     *
     * @param int $firstPage Primera página del chunk a renderizar (default 1)
     */
    public function buildCommand(
        string $inputPdfPath,
        string $outputPrefix,
        int $dpi,
        int $maxPagesToRender,
        string $engine,
        string $binary,
        int $firstPage = 1
    ): string {
        if ($engine === self::ENGINE_GHOSTSCRIPT) {
            return sprintf(
                '%s -sDEVICE=jpeg -dJPEGQ=90 -r%d -dSAFER -dBATCH -dNOPAUSE -dFirstPage=%d -dLastPage=%d -sOutputFile=%s %s',
                escapeshellcmd($binary),
                $dpi,
                $firstPage,
                $maxPagesToRender,
                escapeshellarg($outputPrefix . '-%d.jpg'),
                escapeshellarg($inputPdfPath)
            );
        }

        return sprintf(
            '%s -jpeg -r %d -f %d -l %d %s %s',
            escapeshellcmd($binary),
            $dpi,
            $firstPage,
            $maxPagesToRender,
            escapeshellarg($inputPdfPath),
            escapeshellarg($outputPrefix)
        );
    }

    /**
     * Elimina archivos temporales asociados a un identificador único de ejecución.
     */
    private function cleanupPrefixFiles(string $tempDir, string $uniqueId): void
    {
        $tempPattern = $tempDir . DIRECTORY_SEPARATOR . "*_{$uniqueId}*";
        foreach (glob($tempPattern) ?: [] as $tmpFile) {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    /**
     * Ejecuta el comando controlando timeouts y capturando streams de error.
     */
    protected function executeProcess(string $cmd): RasterizationProcessResult
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

        // Cerramos stdin inmediatamente: el proceso de rasterización no espera entrada interactiva
        fclose($pipes[0]);

        $startTime = microtime(true);
        $output = '';

        // Modo no bloqueante para evitar deadlocks de buffer del SO entre stdout/stderr
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $exitCode = 0;
        while (true) {
            $output = substr($output . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]), -65536);

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

        // Drenado final de streams
        $output = substr($output . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]), -65536);

        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($process);

        return new RasterizationProcessResult($exitCode, $output);
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
