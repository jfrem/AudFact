<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\DocumentQuality;
use RuntimeException;

/**
 * Parsea y valida la respuesta de Gemini Structured Output para una extracción documental.
 *
 * Responsabilidades:
 * - Extraer el JSON generado desde candidate text part.
 * - Validar la completitud estricta del contrato (campos y visual checks requeridos).
 * - Rehidratar los campos planos a la shape canónica {valor, presente, estadoExtraccion}.
 * - Validar calidad documental, items y visual checks.
 *
 * @see DocumentExtractionWorker — delegante principal
 */
final class GeminiResponseParser
{
    private const ACCEPTED_FINISH_REASON = 'STOP';

    private const ERROR_MISSING_CANDIDATE     = 'GEMINI_EXTRACTION_MISSING_CANDIDATE';
    private const ERROR_UNSAFE_FINISH_REASON  = 'GEMINI_EXTRACTION_UNSAFE_FINISH_REASON';
    private const ERROR_MISSING_TEXT_RESPONSE = 'GEMINI_EXTRACTION_MISSING_TEXT_RESPONSE';
    private const ERROR_INVALID_JSON          = 'GEMINI_EXTRACTION_INVALID_JSON';

    public function __construct() {}

    /**
     * Parsea la respuesta Structured Output de Gemini.
     *
     * @param  array<string,mixed> $response Respuesta de GeminiGateway (sin X-Audit-Metrics)
     * @param  array<string,mixed> $contract Contrato de extracción
     * @return array<string,mixed>
     */
    public function parse(
        array $response,
        array $contract = []
    ): array {
        $candidate = $this->extractPrimaryCandidate($response);
        $this->assertSuccessfulFinishReason($candidate);

        $textPart = $candidate['content']['parts'][0]['text'] ?? null;
        if (!is_string($textPart) || trim($textPart) === '') {
            throw new RuntimeException(self::ERROR_MISSING_TEXT_RESPONSE);
        }

        $decoded = json_decode($textPart, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(self::ERROR_INVALID_JSON . ': ' . json_last_error_msg());
        }

        return $this->validateAndRehydrate($decoded, $contract);
    }

    // ─── Helpers de parsing y rehidratación ──────────────────────────────────

    /** @return array<string,mixed> */
    private function extractPrimaryCandidate(array $response): array
    {
        $candidate = $response['candidates'][0] ?? null;
        if (!is_array($candidate)) {
            throw new RuntimeException(self::ERROR_MISSING_CANDIDATE);
        }

        return $candidate;
    }

    private function assertSuccessfulFinishReason(array $candidate): void
    {
        $finishReason = trim((string) ($candidate['finishReason'] ?? ''));
        if ($finishReason !== self::ACCEPTED_FINISH_REASON) {
            $reportedReason = $finishReason !== '' ? $finishReason : 'UNKNOWN';
            throw new RuntimeException(self::ERROR_UNSAFE_FINISH_REASON . ": {$reportedReason}");
        }
    }

    /**
     * Valida la estructura del JSON decodificado, asegura completitud del contrato
     * y rehidrata los campos planos a la shape canónica.
     *
     * @param  array<string,mixed> $decoded
     * @param  array<string,mixed> $contract
     * @return array<string,mixed>
     */
    private function validateAndRehydrate(array $decoded, array $contract): array
    {
        $this->assertContractCompleteness($decoded, $contract);

        $rawConformity = is_array($decoded['document_conformity'] ?? null)
            ? $decoded['document_conformity']
            : [
                'matches_expected_type' => false,
                'detected_type'         => null,
                'justification'         => null,
            ];

        $documentConformity = [
            'matches_expected_type' => (bool) ($rawConformity['matches_expected_type'] ?? false),
            'detected_type'         => isset($rawConformity['detected_type']) && $rawConformity['detected_type'] !== '' ? (string) $rawConformity['detected_type'] : null,
            'justification'         => isset($rawConformity['justification']) && $rawConformity['justification'] !== '' ? (string) $rawConformity['justification'] : null,
        ];

        $rawFields    = is_array($decoded['fields'] ?? null) ? $decoded['fields'] : [];
        $rawItems     = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        $visualChecks = is_array($decoded['visual_checks'] ?? null) ? $decoded['visual_checks'] : [];

        $this->validateItems($rawItems);

        $fields = $this->rehydrateEvidenceFields($rawFields);

        $items = [];
        foreach ($rawItems as $item) {
            $items[] = $this->rehydrateEvidenceFields($item);
        }

        $documentQuality = $this->validateDocumentQuality($decoded['document_quality'] ?? null);
        $qualityNotes    = is_array($decoded['quality_notes'] ?? null)
            ? $decoded['quality_notes']
            : ['Evaluación de calidad por defecto (propiedad no suministrada por la IA)'];

        $this->validateVisualChecks($visualChecks);

        return [
            'document_conformity' => $documentConformity,
            'fields'              => $fields,
            'items'               => array_values($items),
            'visual_checks'       => $visualChecks,
            'document_quality'    => $documentQuality,
            'quality_notes'       => array_values($qualityNotes),
        ];
    }

    /**
     * Valida que Gemini haya retornado las claves explícitas para todos los campos de cabecera,
     * ítems de línea y checks visuales requeridos por el contrato.
     *
     * @param array<string,mixed> $decoded
     * @param array<string,mixed> $contract
     */
    private function assertContractCompleteness(array $decoded, array $contract): void
    {
        if ($contract === []) {
            return;
        }

        $requiresConformity = in_array('document_conformity', $contract['response_schema']['required'] ?? [], true)
            || isset($contract['response_schema']['properties']['document_conformity']);

        if ($requiresConformity) {
            if (!isset($decoded['document_conformity']) || !is_array($decoded['document_conformity'])) {
                throw new RuntimeException('Gemini extraction payload omitió la sección requerida document_conformity');
            }

            if (!array_key_exists('matches_expected_type', $decoded['document_conformity'])) {
                throw new RuntimeException('Gemini extraction payload omitió el campo requerido matches_expected_type en document_conformity');
            }
        }

        $expectedFields = $contract['field_groups']['fields'] ?? [];
        if (is_array($expectedFields) && $expectedFields !== []) {
            if (!isset($decoded['fields']) || !is_array($decoded['fields'])) {
                throw new RuntimeException('Gemini extraction payload omitió la sección requerida fields');
            }

            foreach ($expectedFields as $expectedField) {
                if (is_string($expectedField) && !array_key_exists($expectedField, $decoded['fields'])) {
                    throw new RuntimeException("Gemini extraction payload omitió el campo requerido: {$expectedField}");
                }
            }
        }

        $expectedItemFields = $contract['field_groups']['items'] ?? [];
        if (is_array($expectedItemFields) && $expectedItemFields !== []) {
            if (!isset($decoded['items']) || !is_array($decoded['items'])) {
                throw new RuntimeException('Gemini extraction payload omitió la sección requerida items');
            }

            foreach ($decoded['items'] as $index => $item) {
                if (!is_array($item)) {
                    throw new RuntimeException("Gemini retornó item inválido en posición {$index}");
                }

                foreach ($expectedItemFields as $expectedField) {
                    if (is_string($expectedField) && !array_key_exists($expectedField, $item)) {
                        throw new RuntimeException("Gemini extraction payload omitió el campo de ítem requerido: {$expectedField} en posición {$index}");
                    }
                }
            }
        }

        $expectedChecks = $contract['response_schema']['properties']['visual_checks']['items']['properties']['check']['enum'] ?? [];
        if (is_array($expectedChecks) && $expectedChecks !== []) {
            if (!isset($decoded['visual_checks']) || !is_array($decoded['visual_checks'])) {
                throw new RuntimeException('Gemini extraction payload omitió la sección requerida visual_checks');
            }

            $returnedChecks = [];
            foreach ($decoded['visual_checks'] as $vc) {
                if (is_array($vc) && isset($vc['check']) && is_string($vc['check'])) {
                    $returnedChecks[] = $vc['check'];
                }
            }

            foreach ($expectedChecks as $expectedCheck) {
                if (is_string($expectedCheck) && !in_array($expectedCheck, $returnedChecks, true)) {
                    throw new RuntimeException("Gemini extraction payload omitió el check visual requerido: {$expectedCheck}");
                }
            }
        }
    }

    /**
     * Rehidrata campos planos a shape canónica {valor, valores, presente, estadoExtraccion}.
     *
     * Manejo determinista de cardinalidad:
     * - Si valor es array (ej. TRACE_TOKEN multivalor ["A1", "B2"]):
     *   -> valor="A1, B2", valores=["A1", "B2"], presente=true, estadoExtraccion=FOUND_IN_LIST
     * - Si valor es string con múltiples tokens (ej. "A1, B2"):
     *   -> valor="A1, B2", valores=["A1", "B2"], presente=true, estadoExtraccion=FOUND_IN_LIST
     * - Si valor escalar no nulo:
     *   -> valor=X, valores=[X], presente=true, estadoExtraccion=FOUND
     * - Si valor es nulo o vacío:
     *   -> valor=null, valores=[], presente=false, estadoExtraccion=NOT_FOUND
     *
     * @param  array<string, mixed> $flatFields
     * @return array<string, array{valor: mixed, valores?: array<int, mixed>, presente: bool, estadoExtraccion: string}>
     */
    public function rehydrateEvidenceFields(array $flatFields): array
    {
        $rehydrated = [];
        foreach ($flatFields as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            if (is_array($value)) {
                $tokens = array_values(array_filter(array_map('trim', $value), fn($v) => is_string($v) && $v !== ''));
                if ($tokens !== []) {
                    $rehydrated[$name] = [
                        'valor'            => implode(', ', $tokens),
                        'valores'          => $tokens,
                        'presente'         => true,
                        'estadoExtraccion' => count($tokens) > 1 ? 'FOUND_IN_LIST' : 'FOUND',
                    ];
                } else {
                    $rehydrated[$name] = [
                        'valor'            => null,
                        'valores'          => [],
                        'presente'         => false,
                        'estadoExtraccion' => 'NOT_FOUND',
                    ];
                }
            } else {
                $isPresent = $value !== null && $value !== '';
                $rehydrated[$name] = [
                    'valor'            => $value,
                    'valores'          => $isPresent ? [$value] : [],
                    'presente'         => $isPresent,
                    'estadoExtraccion' => $isPresent ? 'FOUND' : 'NOT_FOUND',
                ];
            }
        }

        return $rehydrated;
    }

    private function validateDocumentQuality(mixed $documentQuality): string
    {
        if (!is_string($documentQuality) || trim($documentQuality) === '') {
            throw new RuntimeException('Gemini retornó extraction payload sin document_quality');
        }

        return DocumentQuality::fromString(trim($documentQuality))->value;
    }

    /** @param array<int,mixed> $items */
    private function validateItems(array $items): void
    {
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException("Gemini retornó item inválido en posición {$index}");
            }
        }
    }

    /** @param array<int,mixed> $visualChecks */
    private function validateVisualChecks(array $visualChecks): void
    {
        foreach ($visualChecks as $index => $visualCheck) {
            if (!is_array($visualCheck)) {
                throw new RuntimeException("Gemini retornó visual_check inválido en posición {$index}");
            }

            $check = $visualCheck['check'] ?? null;
            if (!is_string($check) || trim($check) === '') {
                throw new RuntimeException("Gemini retornó visual_check sin check en posición {$index}");
            }

            if (!array_key_exists('presente', $visualCheck) || !is_bool($visualCheck['presente'])) {
                throw new RuntimeException("Gemini retornó visual_check sin presente booleano en posición {$index}");
            }

            if (
                array_key_exists('valor', $visualCheck)
                && $visualCheck['valor'] !== null
                && !is_int($visualCheck['valor'])
                && !is_float($visualCheck['valor'])
                && !is_string($visualCheck['valor'])
            ) {
                throw new RuntimeException("Gemini retornó visual_check.valor inválido en posición {$index}");
            }

            foreach (['unidad', 'fecha_base'] as $optionalStringKey) {
                if (
                    array_key_exists($optionalStringKey, $visualCheck)
                    && $visualCheck[$optionalStringKey] !== null
                    && !is_string($visualCheck[$optionalStringKey])
                ) {
                    throw new RuntimeException("Gemini retornó visual_check.{$optionalStringKey} inválido en posición {$index}");
                }
            }
        }
    }
}
