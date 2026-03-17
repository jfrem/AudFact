<?php

namespace App\Services\Audit;

/**
 * Schema formal para respuestas de auditoría con IA.
 * Define la estructura esperada del JSON generado por Gemini AI.
 * 
 * @version 2.0
 * @date 2026-03-17
 */
class AuditResponseSchema
{
    /**
     * Valor especial para auditorías que cubren múltiples documentos.
     */
    public const DOCUMENTO_MULTIPLE = 'MULTIPLE';

    /**
     * Tipos de respuesta válidos
     */
    public const RESPONSE_SUCCESS = 'success';
    public const RESPONSE_WARNING = 'warning';
    public const RESPONSE_ERROR = 'error';
    public const RESPONSE_HUMAN_REVIEW = 'human_review';

    /**
     * Retorna métricas nulas para payloads que no pasaron por la IA.
     * Garantiza conformidad del schema en rampas de escape (errores, human_review).
     *
     * @return array
     */
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

    /**
     * Retorna config vacía para payloads que no pasaron por la IA.
     * Garantiza conformidad del schema en rampas de escape (errores, human_review).
     *
     * @return array
     */
    public static function getEmptyConfig(): array
    {
        return [
            'weights' => ['alta' => 0, 'media' => 0, 'baja' => 0],
            'thresholds' => ['warning' => 0, 'error' => 0],
            'max_score' => 0,
        ];
    }

    /**
     * Retorna schema simplificado compatible con Gemini AI API.
     * El enum 'documento' se construye dinámicamente con los nombres
     * reales de adjuntos desde la BD.
     *
     * @param array<string> $documentNames Nombres reales de adjuntos desde BD.
     * @return array
     */
    public static function getGeminiSchema(array $documentNames = []): array
    {
        if (empty($documentNames)) {
            throw new \InvalidArgumentException('getGeminiSchema requires non-empty $documentNames from DB attachments');
        }

        $docEnum = array_values(array_unique(
            array_merge($documentNames, [self::DOCUMENTO_MULTIPLE])
        ));

        return [
            'type' => 'object',
            'properties' => [
                'response' => [
                    'type' => 'string',
                    'enum' => [
                        self::RESPONSE_SUCCESS,
                        self::RESPONSE_WARNING,
                        self::RESPONSE_ERROR
                    ]
                ],
                'severity' => [
                    'type' => 'string',
                    'enum' => ['alta', 'media', 'baja', 'ninguna']
                ],
                'message' => [
                    'type' => 'string'
                ],
                'documento' => [
                    'type' => 'string',
                    'enum' => $docEnum
                ],
                'data' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'item' => ['type' => 'string'],
                                    'detalle' => ['type' => 'string'],
                                    'documento' => ['type' => 'string'],
                                    'severidad' => [
                                        'type' => 'string',
                                        'enum' => ['alta', 'media', 'baja']
                                    ]
                                ],
                                'required' => ['item', 'detalle', 'documento', 'severidad']
                            ]
                        ]
                    ],
                    'required' => ['items']
                ],
                'metrics' => [
                    'type' => 'object',
                    'properties' => [
                        'TotalCamposEvaluados' => ['type' => 'integer'],
                        'TotalCoincidentes' => ['type' => 'integer'],
                        'TotalDiscrepancias' => ['type' => 'integer'],
                        'Altas' => ['type' => 'integer'],
                        'Medias' => ['type' => 'integer'],
                        'Bajas' => ['type' => 'integer']
                    ],
                    'required' => [
                        'TotalCamposEvaluados',
                        'TotalCoincidentes',
                        'TotalDiscrepancias',
                        'Altas',
                        'Medias',
                        'Bajas'
                    ]
                ],
                'config_used' => [
                    'type' => 'object',
                    'properties' => [
                        'weights' => [
                            'type' => 'object',
                            'properties' => [
                                'alta' => ['type' => 'integer'],
                                'media' => ['type' => 'integer'],
                                'baja' => ['type' => 'integer']
                            ],
                            'required' => ['alta', 'media', 'baja']
                        ],
                        'thresholds' => [
                            'type' => 'object',
                            'properties' => [
                                'warning' => ['type' => 'integer'],
                                'error' => ['type' => 'integer']
                            ],
                            'required' => ['warning', 'error']
                        ],
                        'max_score' => ['type' => 'integer']
                    ],
                    'required' => ['weights', 'thresholds', 'max_score']
                ]
            ],
            'required' => ['response', 'severity', 'message', 'documento', 'data', 'metrics', 'config_used']
        ];
    }

    /**
     * Valida un array contra el schema definido
     * 
     * @param array|null $data Datos a validar
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validate(?array $data): array
    {
        $errors = [];

        // Validar que sea un array
        if (!is_array($data)) {
            return [
                'valid' => false,
                'errors' => ['Data must be an array']
            ];
        }

        // Validar campos requeridos a nivel raíz
        $requiredFields = ['response', 'severity', 'message', 'documento', 'data', 'metrics', 'config_used'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $errors[] = "Missing required field: '{$field}'";
            }
        }

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        // Validar 'response'
        $validResponses = [self::RESPONSE_SUCCESS, self::RESPONSE_WARNING, self::RESPONSE_ERROR, self::RESPONSE_HUMAN_REVIEW];
        if (!in_array($data['response'], $validResponses, true)) {
            $errors[] = "Field 'response' must be one of: " . implode(', ', $validResponses) . ". Got: " . $data['response'];
        }

        // Validar 'severity' como enum
        $validSeverities = ['alta', 'media', 'baja', 'ninguna'];
        if (!is_string($data['severity']) || !in_array($data['severity'], $validSeverities, true)) {
            $errors[] = "Field 'severity' must be one of: " . implode(', ', $validSeverities) . ". Got: " . var_export($data['severity'] ?? null, true);
        }

        // Validar 'message'
        if (!is_string($data['message']) || strlen($data['message']) === 0) {
            $errors[] = "Field 'message' must be a non-empty string";
        } elseif (strlen($data['message']) > 500) {
            $errors[] = "Field 'message' exceeds maximum length of 500 characters";
        }

        // Validar 'documento' — debe ser string no vacío (valores dinámicos desde BD).
        if (!is_string($data['documento']) || trim($data['documento']) === '') {
            $errors[] = "Field 'documento' must be a non-empty string. Got: " . var_export($data['documento'] ?? null, true);
        }

        // Validar 'data'
        if (!isset($data['data']['items'])) {
            $errors[] = "Missing required field: 'data.items'";
        } elseif (!is_array($data['data']['items'])) {
            $errors[] = "Field 'data.items' must be an array";
        } else {
            // Validar cada item
            foreach ($data['data']['items'] as $index => $item) {
                if (!is_array($item)) {
                    $errors[] = "Item at index {$index} must be an object/array";
                    continue;
                }

                // Validar campos requeridos en item
                $itemRequiredFields = ['item', 'detalle', 'documento', 'severidad'];
                foreach ($itemRequiredFields as $field) {
                    if (!isset($item[$field])) {
                        $errors[] = "Item at index {$index}: missing required field '{$field}'";
                    }
                }

                // Validar 'severidad' como enum
                if (isset($item['severidad'])) {
                    $validItemSeverities = ['alta', 'media', 'baja'];
                    if (!in_array($item['severidad'], $validItemSeverities, true)) {
                        $errors[] = "Item at index {$index}: field 'severidad' must be one of: " . implode(', ', $validItemSeverities) . ". Got: " . var_export($item['severidad'], true);
                    }
                }

                // Validar tipos y longitudes
                if (isset($item['item'])) {
                    if (!is_string($item['item']) || strlen($item['item']) === 0) {
                        $errors[] = "Item at index {$index}: field 'item' must be a non-empty string";
                    } elseif (strlen($item['item']) > 200) {
                        $errors[] = "Item at index {$index}: field 'item' exceeds maximum length of 200 characters";
                    }
                }

                if (isset($item['detalle'])) {
                    if (!is_string($item['detalle']) || strlen($item['detalle']) === 0) {
                        $errors[] = "Item at index {$index}: field 'detalle' must be a non-empty string";
                    } elseif (strlen($item['detalle']) > 200) {
                        $errors[] = "Item at index {$index}: field 'detalle' exceeds maximum length of 200 characters";
                    }
                }

                if (isset($item['documento'])) {
                    if (!is_string($item['documento']) || strlen($item['documento']) === 0) {
                        $errors[] = "Item at index {$index}: field 'documento' must be a non-empty string";
                    }
                }
            }
        }

        // Validar 'metrics'
        if (!isset($data['metrics']) || !is_array($data['metrics'])) {
            $errors[] = "Field 'metrics' must be an object/array";
        } else {
            $metricFields = [
                'TotalCamposEvaluados',
                'TotalCoincidentes',
                'TotalDiscrepancias',
                'Altas',
                'Medias',
                'Bajas',
            ];

            foreach ($metricFields as $field) {
                if (!array_key_exists($field, $data['metrics'])) {
                    $errors[] = "Missing required field: 'metrics.{$field}'";
                    continue;
                }

                if (!is_int($data['metrics'][$field]) || $data['metrics'][$field] < 0) {
                    $errors[] = "Field 'metrics.{$field}' must be a non-negative integer";
                }
            }
        }

        // Validar 'config_used'
        if (!isset($data['config_used']) || !is_array($data['config_used'])) {
            $errors[] = "Field 'config_used' must be an object/array";
        } else {
            $cfg = $data['config_used'];
            if (!isset($cfg['weights']) || !is_array($cfg['weights'])) {
                $errors[] = "Missing required field: 'config_used.weights'";
            } else {
                foreach (['alta', 'media', 'baja'] as $k) {
                    if (!array_key_exists($k, $cfg['weights'])) {
                        $errors[] = "Missing required field: 'config_used.weights.{$k}'";
                    } elseif (!is_int($cfg['weights'][$k]) || $cfg['weights'][$k] < 0) {
                        $errors[] = "Field 'config_used.weights.{$k}' must be a non-negative integer";
                    }
                }
            }

            if (!isset($cfg['thresholds']) || !is_array($cfg['thresholds'])) {
                $errors[] = "Missing required field: 'config_used.thresholds'";
            } else {
                foreach (['warning', 'error'] as $k) {
                    if (!array_key_exists($k, $cfg['thresholds'])) {
                        $errors[] = "Missing required field: 'config_used.thresholds.{$k}'";
                    } elseif (!is_int($cfg['thresholds'][$k]) || $cfg['thresholds'][$k] < 0) {
                        $errors[] = "Field 'config_used.thresholds.{$k}' must be a non-negative integer";
                    }
                }
            }

            if (!array_key_exists('max_score', $cfg)) {
                $errors[] = "Missing required field: 'config_used.max_score'";
            } elseif (!is_int($cfg['max_score']) || $cfg['max_score'] < 0) {
                $errors[] = "Field 'config_used.max_score' must be a non-negative integer";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

}
