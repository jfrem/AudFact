<?php

namespace App\Services\Audit;

use Core\Logger;

class AuditTelemetryService
{
    /**
     * Construye el bloque de metadatos de rendimiento de la auditoría.
     *
     * @param array $dispensation Datos maestros de dispensación
     * @param array $files Archivos procesados
     * @param float $dataFetchMs Duración de lectura de datos
     * @param float $filePrepMs Duración de preparación de archivos
     * @param float $geminiApiMs Duración de llamada a Gemini
     * @param int $attempts Número de intentos realizados
     * @param float $totalMs Duración total
     * @return array Metadatos listos para respuesta
     */
    public function buildMeta(array $dispensation, array $files, float $dataFetchMs, float $filePrepMs, float $geminiApiMs, int $attempts, float $totalMs): array
    {
        return [
            'totalTimeMs' => round($totalMs),
            'phases' => [
                'dataFetchMs' => round($dataFetchMs),
                'filePrepMs' => round($filePrepMs),
                'geminiApiMs' => round($geminiApiMs),
            ],
            'attempts' => $attempts,
            'factura' => $dispensation['NumeroFactura'] ?? '',
            'FacSec' => $dispensation['FacSec'] ?? '',
            'documentos' => array_values(array_unique(array_map(fn($f) => $f['label'] ?? 'N/A', $files))),
            'timestamp' => date('c'),
        ];
    }

    /**
     * Mapea errores técnicos a mensajes de negocio legibles.
     *
     * @param int $httpCode Código HTTP de error
     * @param string $errorMsg Mensaje técnico
     * @param string $disDetNro Identificador de dispensación/factura
     * @return string Mensaje corto para respuesta al cliente
     */
    public function formatErrorMessage(int $httpCode, string $errorMsg, string $disDetNro): string
    {
        $httpPrefix = $httpCode > 0 ? "[HTTP {$httpCode}] " : '';

        $friendlyMessages = [
            429 => 'Cuota de API excedida. Espera unos minutos.',
            503 => 'Servicio temporalmente no disponible. Reintenta más tarde.',
            500 => 'Error interno del servidor de IA.',
            502 => 'Error de gateway. Reintenta más tarde.',
            504 => 'Timeout del servidor. Reintenta más tarde.',
        ];

        if (isset($friendlyMessages[$httpCode])) {
            return $httpPrefix . $friendlyMessages[$httpCode];
        }

        if (str_contains($errorMsg, 'quota') || str_contains($errorMsg, 'exceeded')) {
            Logger::error("Quota exceeded for invoice: $disDetNro with message: $errorMsg");
            return $httpPrefix . 'Cuota de API excedida. Espera unos minutos.';
        }

        if (str_contains($errorMsg, 'timeout') || str_contains($errorMsg, 'timed out')) {
            Logger::error("Timeout for invoice: $disDetNro with message: $errorMsg");
            return $httpPrefix . 'Timeout de conexión. Reintenta más tarde.';
        }

        return $httpPrefix . 'Error del servicio de IA';
    }
}
