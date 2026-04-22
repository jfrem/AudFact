<?php

namespace App\Services\Audit;

class AuditResponseSchema
{
    public const DOCUMENTO_MULTIPLE = 'MULTIPLE';

    public const RESPONSE_SUCCESS = 'success';
    public const RESPONSE_WARNING = 'warning';
    public const RESPONSE_ERROR = 'error';
    public const RESPONSE_HUMAN_REVIEW = 'human_review';

    public static function getEmptyMetrics(): array
    {
        return [
            'TotalCamposEvaluados' => 0,
            'TotalCoincidentes' => 0,
            'TotalDiscrepancias' => 0,
            'Altas' => 0,
            'Medias' => 0,
            'Bajas' => 0,
        ];
    }

    public static function getEmptyConfig(): array
    {
        return [
            'weights' => ['alta' => 0, 'media' => 0, 'baja' => 0],
            'thresholds' => ['warning' => 0, 'error' => 0],
            'max_score' => 0,
        ];
    }

}
