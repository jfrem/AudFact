<?php

namespace App\Services\Audit;

class ExtractionResponseSchema
{
    public const DOC_TYPE_FORMULA = 'FORMULA_MEDICA';
    public const DOC_TYPE_ACTA = 'ACTA_DE_ENTREGA';
    public const DOC_TYPE_AUTORIZACION = 'AUTORIZACION';
    public const DOC_TYPE_FACTURA = 'FACTURA';
    public const DOC_TYPE_OTRO = 'OTRO';
    public const DOC_TYPE_UNKNOWN = 'DESCONOCIDO';

    // Agregar aquí nuevos nombres de documento que lleguen desde la BD.
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
     * Convierte una etiqueta documental de BD o Gemini al tipo canónico del pipeline.
     *
     * @param  string $rawType  Tipo documental original.
     * @return string Tipo documental canónico cuando existe alias, o valor original.
     */
    public static function normalizeDocType(string $rawType): string
    {
        $upper = strtoupper(trim($rawType));
        return self::DOC_TYPE_ALIASES[$upper] ?? $rawType;
    }

    /**
     * Construye la declaración de Function Calling usada para la extracción documental.
     *
     * @param  array<int, string> $fieldsToExtract  Campos que Gemini debe extraer.
     * @param  array<int, string> $visualChecks  Verificaciones visuales requeridas.
     * @param  array<int, string> $documentTypes  Tipos documentales permitidos para la respuesta.
     * @return array<string, mixed> Declaración de función compatible con Gemini.
     */
    public static function getFunctionDeclaration(
        array $fieldsToExtract,
        array $visualChecks = [],
        array $documentTypes = []
    ): array {
        $fieldProperties = [];
        foreach ($fieldsToExtract as $field) {
            $fieldProperties[$field] = [
                'type' => 'STRING',
                'description' => "Valor extraído del campo '{$field}' del documento. Null si no encontrado.",
                'nullable' => true,
            ];
        }

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
     * Envuelve la declaración de función en el bloque tools esperado por Gemini.
     *
     * @param  array<int, string> $fieldsToExtract  Campos que Gemini debe extraer.
     * @param  array<int, string> $visualChecks  Verificaciones visuales requeridas.
     * @param  array<int, string> $documentTypes  Tipos documentales permitidos.
     * @return array<int, array<string, mixed>> Bloque tools para generateContent.
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
     * Define la configuración que obliga a Gemini a invocar report_extraction.
     *
     * @return array<string, mixed> Configuración de Function Calling.
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
     * Extrae los argumentos de report_extraction desde una respuesta de Gemini.
     *
     * @param  array<string, mixed> $geminiResponse  Respuesta cruda de generateContent.
     * @return array<string, mixed>|null Argumentos de la tool call, o null si no fue invocada.
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
