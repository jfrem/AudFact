<?php

namespace App\Services\Audit;

/**
 * Schema JSON para la respuesta de EXTRACCIÓN de Gemini (Fase 1).
 *
 * A diferencia de AuditResponseSchema (que define el resultado final
 * de auditoría), este schema define SOLO la estructura de extracción
 * de campos y verificaciones visuales desde documentos adjuntos.
 *
 * Gemini responde con JSON estructurado que luego se procesa
 * determinísticamente por RuleEngine en la Fase 3.
 *
 * @version 4.0
 */
class ExtractionResponseSchema
{
    /**
     * Tipos de documento conocidos.
     */
    public const DOC_TYPE_FORMULA = 'FORMULA_MEDICA';
    public const DOC_TYPE_ACTA = 'ACTA_DE_ENTREGA';
    public const DOC_TYPE_AUTORIZACION = 'AUTORIZACION';
    public const DOC_TYPE_FACTURA = 'FACTURA';
    public const DOC_TYPE_OTRO = 'OTRO';
    public const DOC_TYPE_UNKNOWN = 'DESCONOCIDO';

    /**
     * Mapa de nombres de documento de la BD → tipo canónico del pipeline.
     *
     * Los nombres de la BD (`NitMedDocNom`) varían entre clientes/EPS
     * (ej. "FORMULA MEDICA", "FORMULA U ORDEN MEDICA", "FORMULA").
     * Este mapa normaliza a los tipos canónicos que usa FieldClassifier
     * para resolver documentos autoritativos.
     *
     * Mantenimiento: si se agrega un nuevo nombre de documento en la BD,
     * agregar aquí la entrada correspondiente.
     */
    private const DOC_TYPE_ALIASES = [
        // Fórmula médica
        'FORMULA MEDICA'          => self::DOC_TYPE_FORMULA,
        'FORMULA U ORDEN MEDICA'  => self::DOC_TYPE_FORMULA,
        'FORMULA'                 => self::DOC_TYPE_FORMULA,
        'ORDEN MEDICA'            => self::DOC_TYPE_FORMULA,
        // Acta de entrega / Dispensa
        'ACTA DE ENTREGA'             => self::DOC_TYPE_ACTA,
        'DISPENSA'                    => self::DOC_TYPE_ACTA,
        'DISPENSA O ACTA DE ENTREGA'  => self::DOC_TYPE_ACTA,
        // Autorización
        'AUTORIZACION'                => self::DOC_TYPE_AUTORIZACION,
        'AUTORIZACION DE SERVICIOS'   => self::DOC_TYPE_AUTORIZACION,
        // Factura
        'FACTURA'                     => self::DOC_TYPE_FACTURA,
        // Tipos ya canónicos (passthrough)
        self::DOC_TYPE_FORMULA        => self::DOC_TYPE_FORMULA,
        self::DOC_TYPE_ACTA           => self::DOC_TYPE_ACTA,
        self::DOC_TYPE_AUTORIZACION   => self::DOC_TYPE_AUTORIZACION,
        self::DOC_TYPE_FACTURA        => self::DOC_TYPE_FACTURA,
    ];

    /**
     * Normaliza un tipo de documento de la BD al tipo canónico del pipeline.
     *
     * @param string $rawType Nombre del documento como viene de la BD o de Gemini
     * @return string Tipo canónico (FORMULA_MEDICA, ACTA_DE_ENTREGA, etc.) o el original si no hay alias
     */
    public static function normalizeDocType(string $rawType): string
    {
        $upper = strtoupper(trim($rawType));
        return self::DOC_TYPE_ALIASES[$upper] ?? $rawType;
    }

    /**
     * Genera el schema Gemini para Function Calling de extracción.
     *
     * Se usa como `functionDeclarations` en el payload de Gemini,
     * forzando al modelo a responder con la estructura exacta definida.
     *
     * @param array<string> $fieldsToExtract Campos a extraer (desde audit-config)
     * @param array<string> $visualChecks Checks visuales a realizar
     * @param array<string> $documentTypes Tipos de documento esperados
     * @return array Function declaration para Gemini
     */
    public static function getFunctionDeclaration(
        array $fieldsToExtract,
        array $visualChecks = [],
        array $documentTypes = []
    ): array {
        // Propiedades de campos extraídos (todos nullable strings)
        $fieldProperties = [];
        foreach ($fieldsToExtract as $field) {
            $fieldProperties[$field] = [
                'type' => 'STRING',
                'description' => "Valor extraído del campo '{$field}' del documento. Null si no encontrado.",
                'nullable' => true,
            ];
        }

        // Propiedades de verificaciones visuales
        $visualCheckProperties = [];
        foreach ($visualChecks as $check) {
            $visualCheckProperties[$check] = [
                'type' => 'OBJECT',
                'properties' => [
                    'present' => [
                        'type' => 'BOOLEAN',
                        'description' => "true si '{$check}' está presente/visible en el documento",
                    ],
                    'confidence' => [
                        'type' => 'STRING',
                        'description' => 'Nivel de confianza: ALTA, MEDIA, BAJA',
                        'enum' => ['ALTA', 'MEDIA', 'BAJA'],
                    ],
                    'evidence' => [
                        'type' => 'STRING',
                        'description' => 'Descripción breve de la evidencia visual observada',
                        'nullable' => true,
                    ],
                ],
                'required' => ['present', 'confidence'],
            ];
        }

        // Enum de tipos de documento
        $docTypeEnum = !empty($documentTypes)
            ? $documentTypes
            : [
                self::DOC_TYPE_FORMULA,
                self::DOC_TYPE_ACTA,
                self::DOC_TYPE_AUTORIZACION,
                self::DOC_TYPE_FACTURA,
                self::DOC_TYPE_OTRO,
                self::DOC_TYPE_UNKNOWN,
            ];

        $documentSchema = [
            'type' => 'OBJECT',
            'properties' => [
                'type' => [
                    'type' => 'STRING',
                    'description' => 'Tipo de documento identificado',
                    'enum' => $docTypeEnum,
                ],
                'label' => [
                    'type' => 'STRING',
                    'description' => 'Nombre/etiqueta original del documento adjunto',
                ],
                'fields' => [
                    'type' => 'OBJECT',
                    'properties' => $fieldProperties,
                    'description' => 'Campos extraídos del documento',
                ],
            ],
            'required' => ['type', 'fields'],
        ];

        // Estructura de Function Declaration completa
        $parameters = [
            'type' => 'OBJECT',
            'properties' => [
                'documents' => [
                    'type' => 'ARRAY',
                    'description' => 'Lista de documentos procesados con sus campos extraídos',
                    'items' => $documentSchema,
                ],
            ],
            'required' => ['documents'],
        ];

        // Agregar visual checks si hay alguno configurado
        if (!empty($visualCheckProperties)) {
            $parameters['properties']['visualChecks'] = [
                'type' => 'OBJECT',
                'description' => 'Verificaciones visuales sobre los documentos',
                'properties' => $visualCheckProperties,
            ];
            $parameters['required'][] = 'visualChecks';
        }

        return [
            'name' => 'report_extraction',
            'description' => 'Reporta los campos extraídos de cada documento adjunto y las verificaciones visuales realizadas.',
            'parameters' => $parameters,
        ];
    }

    /**
     * Genera el bloque `tools` completo para el payload de Gemini.
     *
     * @param array<string> $fieldsToExtract
     * @param array<string> $visualChecks
     * @param array<string> $documentTypes
     * @return array tools block para el request
     */
    public static function getToolsBlock(
        array $fieldsToExtract,
        array $visualChecks = [],
        array $documentTypes = []
    ): array {
        return [
            [
                'functionDeclarations' => [
                    self::getFunctionDeclaration($fieldsToExtract, $visualChecks, $documentTypes),
                ],
            ],
        ];
    }

    /**
     * Genera el `toolConfig` para forzar Function Calling mode ANY.
     *
     * Esto garantiza que Gemini SIEMPRE invoque la función
     * `report_extraction` en lugar de generar texto libre.
     *
     * @return array toolConfig para el request
     */
    public static function getToolConfig(): array
    {
        return [
            'functionCallingConfig' => [
                'mode' => 'ANY',
                'allowedFunctionNames' => ['report_extraction'],
            ],
        ];
    }

    /**
     * Parsea la respuesta de Function Calling de Gemini.
     *
     * Extrae los argumentos de la función invocada por el modelo,
     * que contienen los campos extraídos y visual checks.
     *
     * @param array $geminiResponse Respuesta completa de la API
     * @return array|null Datos extraídos o null si no hay function call
     */
    public static function parseExtractionResponse(array $geminiResponse): ?array
    {
        $candidates = $geminiResponse['candidates'] ?? [];
        if (empty($candidates)) {
            return null;
        }

        $parts = $candidates[0]['content']['parts'] ?? [];

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $functionCall = $part['functionCall'];

                if (($functionCall['name'] ?? '') === 'report_extraction') {
                    return $functionCall['args'] ?? null;
                }
            }
        }

        return null;
    }
}
