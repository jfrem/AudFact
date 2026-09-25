#!/usr/bin/env php
<?php

/**
 * audit-import.php — Importa y encola auditorías individuales en lote desde archivos .csv o .xlsx.
 *
 * Uso:
 *   php bin/audit-import.php <archivo.csv|archivo.xlsx> [opciones]
 *
 * Opciones:
 *   --endpoint=<url>     URL del endpoint /audit/single (default: http://localhost:8080/audit/single)
 *   --delay-ms=<ms>      Milisegundos entre peticiones (default: 650ms, menos de 100 req/min)
 *   --column=<nombre>    Nombre o índice (0, 1... o A, B...) de la columna con el DisDetNro
 *   --output=<archivo>   Ruta del reporte CSV de resultados (default: logs/audit_import_YYYYMMDD_HHMMSS.csv)
 *   --resume             Reanudar proceso saltando los registros ya encolados en el archivo de reporte
 *   --dry-run            Solo analiza y valida el archivo sin enviar peticiones HTTP
 *   --help               Muestra esta ayuda
 *
 * Ejemplos:
 *   php bin/audit-import.php dispensaciones.xlsx
 *   php bin/audit-import.php dispensaciones.csv --delay-ms=300
 *   php bin/audit-import.php lista.xlsx --dry-run
 *   php bin/audit-import.php lista.xlsx --output=logs/mi_reporte.csv --resume
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Clases Auxiliares de Lectura (XLSX & CSV)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Lector nativo y ligero de archivos Excel .xlsx sin dependencias externas.
 */
final class SimpleXlsxReader
{
    /**
     * @return list<list<string>> Matriz de filas y columnas
     */
    public static function readFile(string $filePath): array
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('simplexml_load_string')) {
            throw new \RuntimeException("La lectura XLSX requiere las extensiones zip y SimpleXML.");
        }

        $zip = new \ZipArchive();
        $res = $zip->open($filePath);
        if ($res !== true) {
            throw new \RuntimeException("No se pudo abrir el archivo XLSX como ZIP (código de error: {$res}): {$filePath}");
        }

        try {
            // 1. Cargar strings compartidos
            $sharedStrings = [];
            $sharedXmlContent = $zip->getFromName('xl/sharedStrings.xml');
            if ($sharedXmlContent !== false) {
                $sXml = self::parseXml($sharedXmlContent);
                foreach ($sXml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string) $si->t;
                    } elseif (isset($si->r)) {
                        $text = '';
                        foreach ($si->r as $r) {
                            $text .= (string) $r->t;
                        }
                        $sharedStrings[] = $text;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }

            // 2. Localizar primera hoja
            $sheetXmlContent = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXmlContent === false) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (preg_match('#^xl/worksheets/sheet\d+\.xml$#i', (string) $name)) {
                        $sheetXmlContent = $zip->getFromIndex($i);
                        break;
                    }
                }
            }
        } finally {
            $zip->close();
        }

        if ($sheetXmlContent === false) {
            throw new \RuntimeException("No se encontró ninguna hoja de cálculo en el archivo XLSX.");
        }

        $sheetXml = self::parseXml($sheetXmlContent);
        if (!isset($sheetXml->sheetData)) {
            throw new \RuntimeException("Error al parsear el XML de la hoja de cálculo XLSX.");
        }

        $rows = [];
        foreach ($sheetXml->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $c) {
                $cellRef = (string) $c['r'];
                preg_match('/^([A-Z]+)(\d+)$/', $cellRef, $matches);
                $colLetters = $matches[1] ?? 'A';
                $colIndex = self::columnLetterToIndex($colLetters);

                $cellType = (string) ($c['t'] ?? '');
                $val = '';

                if ($cellType === 's') {
                    $idx = (int) $c->v;
                    if (!array_key_exists($idx, $sharedStrings)) {
                        throw new \RuntimeException("Referencia a texto compartido inexistente en {$cellRef}.");
                    }
                    $val = $sharedStrings[$idx];
                } elseif ($cellType === 'inlineStr') {
                    $val = (string) ($c->is->t ?? '');
                    foreach ($c->is->r ?? [] as $run) {
                        $val .= (string) $run->t;
                    }
                } elseif ($cellType === 'str') {
                    $val = (string) ($c->v ?? '');
                } elseif ($cellType === 'b') {
                    $val = ((string) $c->v === '1') ? 'true' : 'false';
                } else {
                    $val = (string) ($c->v ?? '');
                }

                $rowData[$colIndex] = trim($val);
            }

            if (!empty($rowData)) {
                $maxCol = max(array_keys($rowData));
                $normalized = [];
                for ($i = 0; $i <= $maxCol; $i++) {
                    $normalized[$i] = $rowData[$i] ?? '';
                }
                $rows[] = $normalized;
            }
        }

        return $rows;
    }

    private static function parseXml(string $content): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false) {
                throw new \RuntimeException('El XLSX contiene XML inválido.');
            }
            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function columnLetterToIndex(string $letters): int
    {
        $letters = strtoupper(trim($letters));
        $index = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }
}

/**
 * Lector de archivos CSV con autodetección de delimitador y BOM.
 */
final class SimpleCsvReader
{
    /**
     * @return list<list<string>>
     */
    public static function readFile(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("No se pudo abrir el archivo CSV: {$filePath}");
        }

        try {
            $rawFirstLine = (string) fgets($handle);
            $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $rawFirstLine);
            $hasSeparatorDirective = preg_match('/^sep=([,;\t|])\s*$/i', $firstLine, $separator) === 1;
            $dataOffset = $hasSeparatorDirective ? ftell($handle) : (str_starts_with($rawFirstLine, "\xEF\xBB\xBF") ? 3 : 0);
            rewind($handle);

            $delimiters = $hasSeparatorDirective ? [$separator[1]] : [',', ';', "\t", '|'];
            $bestDelimiter = $delimiters[0];
            $maxCount = 0;
            foreach ($delimiters as $d) {
                fseek($handle, $dataOffset);
                $count = count(fgetcsv($handle, 0, $d, '"', '') ?: []);
                if ($count > $maxCount) {
                    $maxCount = $count;
                    $bestDelimiter = $d;
                }
            }

            fseek($handle, $dataOffset);
            $rows = [];
            while (($data = fgetcsv($handle, 0, $bestDelimiter, '"', '')) !== false) {
                // Normalizar valores
                $cleanedRow = array_map(static fn($v) => trim((string) $v), $data);

                // Ignorar filas completamente vacías
                $hasContent = false;
                foreach ($cleanedRow as $cell) {
                    if ($cell !== '') {
                        $hasContent = true;
                        break;
                    }
                }
                if ($hasContent) {
                    $rows[] = $cleanedRow;
                }
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Ejecución Principal CLI
// ─────────────────────────────────────────────────────────────────────────────

final class AuditBatchImporter
{
    private const DEFAULT_ENDPOINT = 'http://172.16.0.3:8080/audit/single';
    private const DEFAULT_DELAY_MS = 650;
    private const REPORT_HEADER = [
        'Timestamp',
        'Fila',
        'DisDetNro',
        'DisIdEnviado',
        'HttpCode',
        'Exito',
        'Status',
        'AuditId',
        'DisIdResuelto',
        'Mensaje',
        'DuracionMs',
    ];
    private string $endpoint;
    private int $delayMs;
    private ?string $explicitColumn;
    private string $outputCsv;
    private bool $resume;
    private bool $dryRun;
    private string $filePath;

    public function __construct(array $args)
    {
        $this->parseOptions($args);
    }

    private function parseOptions(array $args): void
    {
        $options = [];
        $fileArg = null;

        for ($i = 1; $i < count($args); $i++) {
            $arg = $args[$i];
            if (str_starts_with($arg, '--')) {
                $clean = substr($arg, 2);
                if (str_contains($clean, '=')) {
                    [$key, $val] = explode('=', $clean, 2);
                    $options[$key] = $val;
                } else {
                    $options[$clean] = true;
                }
            } elseif ($fileArg === null) {
                $fileArg = $arg;
            } else {
                throw new \InvalidArgumentException('Solo se admite un archivo de origen.');
            }
        }

        if (isset($options['help'])) {
            $this->showHelp();
            exit(0);
        }

        $valueOptions = ['endpoint', 'delay-ms', 'column', 'output'];
        $flagOptions = ['resume', 'dry-run'];
        foreach ($options as $name => $value) {
            if (in_array($name, $valueOptions, true)) {
                if (!is_string($value) || trim($value) === '') {
                    throw new \InvalidArgumentException("--{$name} requiere un valor con =.");
                }
            } elseif (!in_array($name, $flagOptions, true) || $value !== true) {
                throw new \InvalidArgumentException("Opción inválida: --{$name}");
            }
        }

        $this->endpoint = rtrim($options['endpoint'] ?? self::DEFAULT_ENDPOINT, '/');
        $url = parse_url($this->endpoint);
        if (
            $url === false || !in_array($url['scheme'] ?? '', ['http', 'https'], true)
            || empty($url['host']) || isset($url['user']) || isset($url['pass'])
            || ($url['path'] ?? '') !== '/audit/single' || isset($url['query']) || isset($url['fragment'])
        ) {
            throw new \InvalidArgumentException('--endpoint debe ser una URL HTTP(S) de /audit/single sin credenciales.');
        }

        $delay = $options['delay-ms'] ?? (string) self::DEFAULT_DELAY_MS;
        if (!ctype_digit($delay) || (float) $delay > intdiv(PHP_INT_MAX, 1000)) {
            throw new \InvalidArgumentException('--delay-ms debe ser un entero no negativo representable en microsegundos.');
        }
        $this->delayMs = (int) $delay;

        $this->explicitColumn = isset($options['column']) ? trim($options['column']) : null;

        $defaultOutput = 'logs/audit_import_' . date('Ymd_His') . '.csv';
        $this->outputCsv = isset($options['output']) ? trim($options['output']) : $defaultOutput;

        $this->resume = isset($options['resume']);
        $this->dryRun = isset($options['dry-run']);
        if ($this->resume && !isset($options['output'])) {
            throw new \InvalidArgumentException('--resume requiere --output con la bitácora anterior.');
        }

        if ($fileArg === null || trim($fileArg) === '') {
            // Solicitar interactivamente si no se pasó
            echo "\n\033[1;36m=== AUDFACT BATCH AUDIT IMPORTER ===\033[0m\n";
            echo "Por favor ingrese la ruta del archivo (.csv o .xlsx): ";
            $input = trim((string) fgets(STDIN));
            $input = trim($input, "\"' ");
            if ($input === '') {
                throw new \InvalidArgumentException('No se proporcionó la ruta del archivo. Usa --help para ver el uso.');
            }
            $fileArg = $input;
        }

        $this->filePath = $fileArg;
    }

    private function showHelp(): void
    {
        echo <<<HELP

\033[1;36mUso:\033[0m
  php bin/audit-import.php <archivo.csv|archivo.xlsx> [opciones]

\033[1;33mOpciones:\033[0m
  \033[1m--endpoint=<url>\033[0m     URL del endpoint (Default: http://localhost:8080/audit/single)
  \033[1m--delay-ms=<ms>\033[0m      Milisegundos de pausa entre llamadas (Default: 650ms)
  \033[1m--column=<nombre>\033[0m    Nombre o índice de columna del DisDetNro (ej: 'DisDetNro', 'A', '0')
  \033[1m--output=<archivo>\033[0m   Ruta de archivo CSV para guardar bitácora de resultados
  \033[1m--resume\033[0m             Reanudar proceso saltando los ya encolados exitosamente
  \033[1m--dry-run\033[0m            Simula la lectura y parseo del archivo sin llamar a la API
  \033[1m--help\033[0m               Muestra este mensaje de ayuda

\033[1;32mEjemplos:\033[0m
  php bin/audit-import.php facturas.xlsx
  php bin/audit-import.php facturas.csv --delay-ms=300
  php bin/audit-import.php facturas.xlsx --dry-run
  php bin/audit-import.php facturas.xlsx --output=logs/reporte.csv --resume

HELP;
    }

    public function run(): int
    {
        $startedAt = microtime(true);
        $records = $this->readRecords();
        $completed = $this->readPreviousReport();
        printf("Destino: %s\nRegistros únicos: %d\nPausa: %d ms\n", $this->endpoint, count($records), $this->delayMs);

        if ($this->dryRun) {
            printf("[DRY-RUN EXITOSO] Estructura validada; %d registros previos. No se consultó la API.\n", count($completed));
            return 0;
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('El envío requiere la extensi?n curl.');
        }

        $directory = dirname($this->outputCsv);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('No se pudo crear el directorio de la bitácora.');
        }
        $report = fopen($this->outputCsv, $this->resume ? 'a+' : 'x');
        if ($report === false) {
            throw new \RuntimeException('No se pudo abrir la bitácora.');
        }
        try {
            if (!flock($report, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Otro proceso está usando la bitácora.');
            }
            // Volver a leer bajo lock evita usar una vista anterior de la bitácora.
            if ($this->resume) {
                rewind($report);
                $completed = $this->readCompletedReport($report);
                fseek($report, 0, SEEK_END);
            } else {
                $this->writeReportRow($report, self::REPORT_HEADER);
            }
            $stats = $this->sendRecords($records, $completed, $report);
        } finally {
            fclose($report);
        }
        printf(
            "Aceptadas: %d | Omitidas: %d | Errores: %d | Tiempo: %.2fs\nBitácora: %s\n",
            $stats['accepted'],
            $stats['skipped'],
            $stats['failed'],
            microtime(true) - $startedAt,
            $this->outputCsv
        );
        return $stats['failed'] > 0 ? 1 : 0;
    }

    /** @return list<array{row_number: int, disDetNro: string, disId: ?string}> */
    private function readRecords(): array
    {
        if (!is_file($this->filePath) || !is_readable($this->filePath)) {
            throw new \RuntimeException('No se puede leer el archivo de origen.');
        }
        $rows = match (strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION))) {
            'xlsx' => SimpleXlsxReader::readFile($this->filePath),
            'csv', 'txt' => SimpleCsvReader::readFile($this->filePath),
            default => throw new \InvalidArgumentException('Formato no soportado. Usa CSV, TXT o XLSX.'),
        };
        if ($rows === []) {
            throw new \RuntimeException('El archivo está vacío.');
        }
        $columns = $this->resolveColumns($rows);
        $records = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            if ($index === 0 && $columns['hasHeader']) {
                continue;
            }
            $disDetNro = trim((string) ($row[$columns['disDetCol']] ?? ''));
            $disId = $columns['disIdCol'] === null ? null : trim((string) ($row[$columns['disIdCol']] ?? ''));
            $disId = $disId === '' ? null : $disId;
            if ($disDetNro === '') {
                continue;
            }
            if (strlen($disDetNro) > 255 || ($disId !== null && strlen($disId) > 255)) {
                throw new \RuntimeException('Identificador mayor a 255 bytes en fila ' . ($index + 1));
            }
            if (array_key_exists($disDetNro, $seen)) {
                if ($seen[$disDetNro] !== $disId) {
                    throw new \RuntimeException('DisId contradictorio en fila ' . ($index + 1));
                }
                continue;
            }
            $seen[$disDetNro] = $disId;
            $records[] = ['row_number' => $index + 1, 'disDetNro' => $disDetNro, 'disId' => $disId];
        }
        if ($records === []) {
            throw new \RuntimeException('No hay registros válidos para procesar.');
        }
        return $records;
    }

    /** @return array<string, true> */
    private function readPreviousReport(): array
    {
        if (realpath($this->filePath) === realpath($this->outputCsv)) {
            throw new \InvalidArgumentException('Origen y bitácora deben ser archivos diferentes.');
        }
        if ($this->resume) {
            return $this->loadCompletedFromCsv($this->outputCsv);
        }
        if (file_exists($this->outputCsv)) {
            throw new \RuntimeException('La bitácora ya existe. Usa --resume o elige otro --output.');
        }
        return [];
    }

    /** @param resource $report
     *  @return array{accepted: int, skipped: int, failed: int}
     */
    private function sendRecords(array $records, array $completed, $report): array
    {
        $stats = ['accepted' => 0, 'skipped' => 0, 'failed' => 0];
        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('No se pudo inicializar curl.');
        }
        try {
            foreach ($records as $index => $record) {
                if (isset($completed[$record['disDetNro']])) {
                    $stats['skipped']++;
                    continue;
                }
                $payload = ['disDetNro' => $record['disDetNro']];
                if ($record['disId'] !== null) {
                    $payload['disId'] = $record['disId'];
                }
                $startedAt = microtime(true);
                $response = $this->sendRequestWithRetry($ch, $this->endpoint, $payload);
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $json = json_decode($response['body'], true);
                $json = is_array($json) ? $json : [];
                $data = is_array($json['data'] ?? null) ? $json['data'] : [];
                $auditId = is_string($data['audit_id'] ?? null) ? $data['audit_id'] : '';
                $accepted = $response['http_code'] === 202 && ($json['success'] ?? false) === true && $auditId !== '';
                $message = is_string($json['message'] ?? null)
                    ? $json['message'] : ($response['curl_error'] ?: 'Respuesta inesperada de la API');
                $this->writeReportRow($report, [
                    date('Y-m-d H:i:s'),
                    $record['row_number'],
                    $record['disDetNro'],
                    $record['disId'] ?? '',
                    $response['http_code'],
                    $accepted ? '1' : '0',
                    is_string($data['status'] ?? null) ? $data['status'] : '',
                    $auditId,
                    is_scalar($data['dis_id'] ?? null) ? (string) $data['dis_id'] : '',
                    $message,
                    $durationMs,
                ]);
                $stats[$accepted ? 'accepted' : 'failed']++;
                printf("[%d/%d] HTTP %d: %s\n", $index + 1, count($records), $response['http_code'], $accepted ? 'Aceptada' : 'Error');
                if ($this->delayMs > 0 && $index < count($records) - 1) {
                    usleep($this->delayMs * 1000);
                }
            }
        } finally {
            curl_close($ch);
        }
        return $stats;
    }

    /** @param resource $handle */
    private function writeReportRow($handle, array $row): void
    {
        if (fputcsv($handle, $row, ',', '"', '') === false || !fflush($handle)) {
            throw new \RuntimeException('No se pudo guardar la bitácora; se detiene el envío para evitar perder trazabilidad.');
        }
    }

    /**
     * Envía la petición HTTP con reintentos automáticos en caso de HTTP 429 o 503.
     */
    private function sendRequestWithRetry(\CurlHandle $ch, string $url, array $payload): array
    {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $maxAttempts = 3;

        for ($attempt = 1;; $attempt++) {
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $jsonPayload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
            ]);

            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            // Reintentar si hubo rate-limiting (429) o sobrecarga temporal (503)
            if (($httpCode === 429 || $httpCode === 503) && $attempt < $maxAttempts) {
                $backoffSec = $attempt * 2;
                echo "      HTTP {$httpCode}. Reintentando en {$backoffSec}s (intento {$attempt}/{$maxAttempts})...\n";
                sleep($backoffSec);
                continue;
            }

            return [
                'http_code'  => (int) $httpCode,
                'body'       => is_string($body) ? $body : '',
                'curl_error' => $curlError,
            ];
        }
    }

    /**
     * Resuelve los índices de columnas para DisDetNro y DisId.
     */
    private function resolveColumns(array $rows): array
    {
        $firstRow = $rows[0];
        $disDetCol = null;
        $disIdCol = null;
        $hasHeader = false;

        // Detectar ambas identidades incluso cuando --column está presente.
        foreach ($firstRow as $idx => $cell) {
            $name = strtolower(trim((string) $cell));
            $clean = preg_replace('/[^a-z0-9]/', '', $name);

            if (preg_match('/^(disdetnro|numerofactura|nrofactura|factura|facnro|dispensacion|disdetnum)$/i', $clean)) {
                $disDetCol = $idx;
                $hasHeader = true;
            } elseif (preg_match('/^(disid|dissec|facsec)$/i', $clean)) {
                $disIdCol = $idx;
                $hasHeader = true;
            }
        }

        if ($this->explicitColumn !== null) {
            $colSpec = $this->explicitColumn;
            $explicitIndex = null;
            // Un nombre de encabezado tiene precedencia sobre letras Excel.
            foreach ($firstRow as $idx => $cell) {
                if (strcasecmp(trim((string) $cell), $colSpec) === 0) {
                    $explicitIndex = $idx;
                    $hasHeader = true;
                    break;
                }
            }
            if ($explicitIndex === null && ctype_digit($colSpec)) {
                $explicitIndex = (int) $colSpec;
            } elseif ($explicitIndex === null && preg_match('/^[A-Z]{1,3}$/i', $colSpec)) {
                $explicitIndex = SimpleXlsxReader::columnLetterToIndex($colSpec);
            }
            $columnCount = max(array_map('count', $rows));
            if ($explicitIndex === null || $explicitIndex >= $columnCount) {
                throw new \InvalidArgumentException('La columna solicitada no existe en el archivo.');
            }
            $disDetCol = $explicitIndex;
        }

        // Sin encabezado conocido, solo una columna es inequívoca.
        if ($disDetCol === null) {
            if (count($firstRow) !== 1 || $hasHeader) {
                throw new \InvalidArgumentException('No se identificó DisDetNro. Indica --column con el nombre o índice.');
            }
            $disDetCol = 0;
        }
        if ($disDetCol === $disIdCol) {
            throw new \InvalidArgumentException('DisDetNro y DisId deben ocupar columnas distintas.');
        }

        return [
            'disDetCol'         => $disDetCol,
            'disIdCol'          => $disIdCol,
            'hasHeader'         => $hasHeader,
        ];
    }

    /**
     * Carga los DisDetNro que ya fueron exitosos desde un archivo CSV previo para reanudar.
     */
    private function loadCompletedFromCsv(string $csvPath): array
    {
        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo leer la bitácora para reanudar.');
        }

        try {
            return $this->readCompletedReport($handle);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle
     *  @return array<string, true>
     */
    private function readCompletedReport($handle): array
    {
        $completed = [];
        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header !== self::REPORT_HEADER) {
            throw new \RuntimeException('La bitácora tiene un encabezado incompatible.');
        }
        $disDetIdx = array_search('DisDetNro', $header, true);
        $exitoIdx = array_search('Exito', $header, true);
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($row) !== count($header)) {
                throw new \RuntimeException('La bitácora contiene una fila incompleta.');
            }
            $disDet = trim((string) $row[$disDetIdx]);
            $exito = trim((string) $row[$exitoIdx]);
            if (!in_array($exito, ['0', '1'], true)) {
                throw new \RuntimeException('La bitácora contiene un resultado inválido.');
            }
            if ($disDet !== '' && $exito === '1') {
                $completed[$disDet] = true;
            }
        }
        return $completed;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Entrada CLI
// ─────────────────────────────────────────────────────────────────────────────

try {
    $importer = new AuditBatchImporter($argv);
    exit($importer->run());
} catch (\Throwable $error) {
    fwrite(STDERR, '[ERROR] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
