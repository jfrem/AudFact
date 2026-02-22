<?php

namespace App\Services\Audit;

/**
 * Schema formal para respuestas de auditoría con IA.
 * Define la estructura esperada del JSON generado por Gemini AI.
 * 
 * @version 1.0
 * @date 2026-02-09
 */
class AuditResponseSchema
{
    /**
     * Tipos de documento válidos
     */
    public const DOCUMENTO_ACTA_ENTREGA = 'ACTA_ENTREGA';
    public const DOCUMENTO_FORMULA_MEDICA = 'FORMULA_MEDICA';
    public const DOCUMENTO_AUTORIZACION = 'AUTORIZACION';
    public const DOCUMENTO_VALIDADOR = 'VALIDADOR';
    public const DOCUMENTO_MULTIPLE = 'MULTIPLE';

    /**
     * Tipos de respuesta válidos
     */
    public const RESPONSE_SUCCESS = 'success';
    public const RESPONSE_WARNING = 'warning';
    public const RESPONSE_ERROR = 'error';

    /**
     * Retorna el schema JSON completo siguiendo JSON Schema Draft 7
     * Para documentación y validación interna.
     *
     * @return array
     */
    public static function getSchema(): array
    {
        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'title' => 'AuditResponse',
            'description' => 'Estructura de respuesta para auditoría de documentos farmacéuticos con IA',
            'type' => 'object',
            'required' => ['response', 'severity', 'message', 'documento', 'data'],
            'properties' => [
                'response' => [
                    'type' => 'string',
                    'enum' => [
                        self::RESPONSE_SUCCESS,
                        self::RESPONSE_WARNING,
                        self::RESPONSE_ERROR
                    ],
                    'description' => 'Estado general de la auditoría'
                ],
                'severity' => [
                    'type' => 'string',
                    'enum' => ['alta', 'media', 'baja', 'ninguna'],
                    'description' => 'Nivel de severidad general del hallazgo'
                ],
                'message' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 500,
                    'description' => 'Descripción breve del resultado general'
                ],
                'documento' => [
                    'type' => 'string',
                    'enum' => [
                        self::DOCUMENTO_ACTA_ENTREGA,
                        self::DOCUMENTO_FORMULA_MEDICA,
                        self::DOCUMENTO_AUTORIZACION,
                        self::DOCUMENTO_VALIDADOR,
                        self::DOCUMENTO_MULTIPLE
                    ],
                    'description' => 'Tipo principal de documento auditado'
                ],
                'data' => [
                    'type' => 'object',
                    'required' => ['items'],
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'description' => 'Lista de discrepancias encontradas (vacío si todo es válido)',
                            'items' => [
                                'type' => 'object',
                                'required' => ['item', 'detalle', 'documento'],
                                'properties' => [
                                    'item' => [
                                        'type' => 'string',
                                        'minLength' => 1,
                                        'maxLength' => 200,
                                        'description' => 'Nombre del campo validado'
                                    ],
                                    'detalle' => [
                                        'type' => 'string',
                                        'minLength' => 1,
                                        'maxLength' => 200,
                                        'description' => 'Descripción de la discrepancia encontrada'
                                    ],
                                    'documento' => [
                                        'type' => 'string',
                                        'minLength' => 1,
                                        'description' => 'Documento específico donde se encontró la discrepancia'
                                    ],
                                    'severidad' => [
                                        'type' => 'string',
                                        'enum' => ['alta', 'media', 'baja'],
                                        'description' => 'Severidad de este ítem específico'
                                    ]
                                ],
                                'additionalProperties' => false
                            ]
                        ]
                    ],
                    'additionalProperties' => false
                ]
            ],
            'additionalProperties' => false
        ];
    }

    /**
     * Retorna schema simplificado compatible con Gemini AI API.
     * Solo incluye propiedades soportadas por la API de Gemini.
     *
     * @return array
     */
    public static function getGeminiSchema(): array
    {
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
                    'enum' => [
                        self::DOCUMENTO_ACTA_ENTREGA,
                        self::DOCUMENTO_FORMULA_MEDICA,
                        self::DOCUMENTO_AUTORIZACION,
                        self::DOCUMENTO_VALIDADOR,
                        self::DOCUMENTO_MULTIPLE
                    ]
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
                ]
            ],
            'required' => ['response', 'severity', 'message', 'documento', 'data']
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
        $requiredFields = ['response', 'message', 'documento', 'data'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $errors[] = "Missing required field: '{$field}'";
            }
        }

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        // Validar 'response'
        if (!in_array($data['response'], [self::RESPONSE_SUCCESS, self::RESPONSE_WARNING, self::RESPONSE_ERROR], true)) {
            $errors[] = "Field 'response' must be one of: success, warning, error. Got: " . $data['response'];
        }

        // Validar 'message'
        if (!is_string($data['message']) || strlen($data['message']) === 0) {
            $errors[] = "Field 'message' must be a non-empty string";
        } elseif (strlen($data['message']) > 500) {
            $errors[] = "Field 'message' exceeds maximum length of 500 characters";
        }

        // Validar 'documento'
        $validDocumentos = [
            self::DOCUMENTO_ACTA_ENTREGA,
            self::DOCUMENTO_FORMULA_MEDICA,
            self::DOCUMENTO_AUTORIZACION,
            self::DOCUMENTO_VALIDADOR,
            self::DOCUMENTO_MULTIPLE
        ];
        if (!in_array($data['documento'], $validDocumentos, true)) {
            $errors[] = "Field 'documento' must be one of: " . implode(', ', $validDocumentos) . ". Got: " . $data['documento'];
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
                $itemRequiredFields = ['item', 'detalle', 'documento'];
                foreach ($itemRequiredFields as $field) {
                    if (!isset($item[$field])) {
                        $errors[] = "Item at index {$index}: missing required field '{$field}'";
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

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Retorna un ejemplo de respuesta válida
     * 
     * @return array
     */
    public static function getExample(): array
    {
        return [
            'response' => self::RESPONSE_WARNING,
            'message' => 'Se encontraron 2 discrepancias en la validación del Acta de Entrega',
            'documento' => self::DOCUMENTO_ACTA_ENTREGA,
            'data' => [
                'items' => [
                    [
                        'item' => 'IPS (Institución Prestadora de Salud)',
                        'detalle' => 'El Reference JSON indica "e.s.e hospital regional de moniquira". El Acta de Entrega indica "SALUD VITAL".',
                        'documento' => 'ACTA_ENTREGA'
                    ],
                    [
                        'item' => 'Número de Autorización',
                        'detalle' => 'El Reference JSON indica que el campo está vacío (""). El Acta de Entrega registra el número D19251005204.',
                        'documento' => 'ACTA_ENTREGA'
                    ]
                ]
            ]
        ];
    }

    /**
     * Retorna descripción legible de los tipos de documento
     * 
     * @return array
     */
    public static function getDocumentTypeDescriptions(): array
    {
        return [
            self::DOCUMENTO_ACTA_ENTREGA => 'Acta de Entrega de Medicamentos',
            self::DOCUMENTO_FORMULA_MEDICA => 'Fórmula Médica o Prescripción',
            self::DOCUMENTO_AUTORIZACION => 'Autorización de Dispensación',
            self::DOCUMENTO_VALIDADOR => 'Validador de Derechos',
            self::DOCUMENTO_MULTIPLE => 'Múltiples documentos analizados'
        ];
    }
}
