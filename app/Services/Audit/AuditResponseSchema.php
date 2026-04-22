<?php

namespace App\Services\Audit;

class AuditResponseSchema
{
    public const DOCUMENTO_MULTIPLE = 'MULTIPLE';

    public const RESPONSE_SUCCESS = 'success';
    public const RESPONSE_WARNING = 'warning';
    public const RESPONSE_ERROR = 'error';
    public const RESPONSE_HUMAN_REVIEW = 'human_review';

    /**
     * Devuelve métricas vacías con la forma esperada por la respuesta de auditoría.
     *
     * @return array<string, int> Contadores iniciales de evaluación y severidad.
     */
    public static function getEmptyMetrics(): array
    {
        return [
            'TotalCamposEvaluados' => 0,
            'TotalCoincidentes' => 0,
            'TotalDiscrepancias' => 0,
            'TotalOmitidos' => 0,
            'TotalExtraccionIncompleta' => 0,
            'Altas' => 0,
            'Medias' => 0,
            'Bajas' => 0,
        ];
    }

    /**
     * Devuelve la configuración vacía usada cuando no se ejecuta el motor de reglas.
     *
     * @return array<string, mixed> Pesos, umbrales y puntaje máximo inicializados en cero.
     */
    public static function getEmptyConfig(): array
    {
        return [
            'weights' => ['alta' => 0, 'media' => 0, 'baja' => 0],
            'thresholds' => ['warning' => 0, 'error' => 0],
            'max_score' => 0,
        ];
    }

}
