<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditFieldValueType;
use Core\Logger;
use RuntimeException;

/**
 * Construye los prompts del sistema y usuario para la llamada a Gemini.
 *
 * Responsabilidades:
 * - buildSystemPrompt(): prompt del sistema (default + deduplicación de contexto del contrato)
 * - buildUserPrompt(): prompt del usuario con campos, ítems, visual checks y contexto de artículos
 * - buildToolConfig(): configuración de herramientas de Gemini
 * - promptContextHash(): hash del contexto de prompt para la clave de caché
 * - Helpers internos de deduplicación de oraciones
 *
 * @see DocumentExtractionWorker — delegante principal
 */
final class ExtractionPromptBuilder
{
    private const PROMPT_DEDUP_MIN_CHARS = 15;

    private const DEFAULT_SYSTEM_PROMPT = <<<TEXT
        Eres un extractor documental determinístico.
        Analiza un único documento.
        No inventes valores.
        Si un dato no es visible o no es legible, omítelo o usa el valor nativo null de JSON (sin comillas).
        Para verificaciones visuales usa presente=false cuando el elemento no sea visible.
        Invoca cada función permitida exactamente una vez en el mismo turno.
        No devuelvas texto libre; responde únicamente con function calls.

        Extrae el texto exactamente como aparece en la imagen.
        Transcribe cada carácter tal como es visible, sin corregir ni interpretar.
        Si el documento está rotado o invertido, orienta mentalmente la lectura en sentido natural antes de transcribir.
        Para fechas, transcribe el año exacto tal como está impreso.
    TEXT;

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $contract
     */
    public function buildSystemPrompt(array $payload, array $contract): string
    {
        $customPrompt = trim((string) ($payload['system_prompt'] ?? ''));
        if ($customPrompt === '') {
            return self::DEFAULT_SYSTEM_PROMPT;
        }

        $customPrompt = $this->removeContractRedundantPromptSentences(
            $customPrompt,
            $this->contractDescriptionTexts($contract, $payload)
        );

        if ($customPrompt === '') {
            return self::DEFAULT_SYSTEM_PROMPT;
        }

        return self::DEFAULT_SYSTEM_PROMPT . "\n\n" . $customPrompt;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $contract
     */
    public function buildUserPrompt(string $documentType, array $payload, array $contract): string
    {
        $parts = [
            "Documento objetivo: {$documentType}.",
            'Extrae solo la información visible en este documento.',
            'No completes campos con inferencias desde otros documentos.',
            'Si el documento está girado o invertido (ej. 180°), orienta la lectura en el sentido correcto de izquierda a derecha sin transponer dígitos.',
        ];

        $fieldGroups = $this->contractFieldGroups($contract);
        if ($this->hasIdentitySeparationFields($payload['fields_config'] ?? [])) {
            $parts[] = implode("\n", [
                '### Regla de identidad',
                '',
                'Si una linea combina tipo de documento, numero y nombre, separalos en sus campos correspondientes.',
                '',
                '**Ejemplos**',
                '- `CC 94229637 NORENA AGUDELO` => TipoDocumentoPaciente, DocumentoPaciente, NombrePaciente.',
                '- `Medico: 12345678-PEREZ ANA MARIA` => DocumentoMedico, Medico.',
                '',
                'Solo extrae datos visibles y requeridos; no infieras ni completes identidades faltantes.',
            ]);
        }

        if ($fieldGroups['fields'] !== []) {
            $parts[] = 'Campos para `extract_fields`: ' . implode(', ', $fieldGroups['fields']) . '.';
        }

        if ($fieldGroups['items'] !== []) {
            $parts[] = 'Campos para `extract_items`: ' . implode(', ', $fieldGroups['items']) . '.';
        }

        $visualChecks = $payload['visual_checks'] ?? [];
        if ($this->contractRequiresFunction($contract, DocumentExtractionContractBuilder::FN_DETECT_VISUAL_CHECKS)) {
            $parts[] = 'Checks visuales esperados:';
            foreach (is_array($visualChecks) ? $visualChecks : [] as $check) {
                if (!is_array($check)) {
                    continue;
                }
                $name = trim((string) ($check['check'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $description = trim((string) ($check['description'] ?? ''));
                $parts[] = $description !== '' ? "- {$name}: {$description}" : "- {$name}";
            }
            if ($this->hasVisualCheck($visualChecks, 'VigenciaEntrega')) {
                $parts[] = 'Para VigenciaEntrega, si el valor es visible retorna valor numerico, unidad="dias" y fecha_base con el nombre del campo fecha desde el cual se cuenta.';
            }
        }

        if ($fieldGroups['items'] !== [] && $this->requiresSegmentedDispensaItems($documentType, $payload)) {
            $parts[] = 'Este documento contiene multiples lineas de producto.';
            $parts[] = 'Debes usar `items` con una entrada por cada fila visible.';
            $parts[] = 'No colapses cantidades, lotes, fechas de vencimiento ni codigos de articulo en `fields`.';
        }

        $dispensedNames = $this->buildDispensedItemsContext($documentType, $payload, $fieldGroups);
        if ($dispensedNames !== []) {
            $parts[] = 'Candidatos de articulo para busqueda en prescripcion:';
            foreach ($dispensedNames as $name) {
                $parts[] = "- {$name}";
            }
            $parts[] = 'En `items`, extrae solo articulos visibles que coincidan de forma exacta u homologa con esos candidatos.';
            $parts[] = 'Devuelve el nombre tal como aparece en el documento.';
        }

        $parts[] = 'Invoca exactamente una vez cada función en el mismo turno: '
            . implode(', ', $this->requiredFunctionNames($contract)) . '.';

        return implode("\n", $parts);
    }

    /**
     * @param array<string,mixed> $contract
     * @return array<string,mixed>
     */
    public function buildToolConfig(array $contract): array
    {
        return [
            'functionCallingConfig' => [
                'mode' => 'ANY',
                'allowedFunctionNames' => $this->requiredFunctionNames($contract),
            ],
        ];
    }

    public function promptContextHash(string $userPrompt, string $systemPrompt): string
    {
        return DocumentExtractionContractBuilder::hashPayload([
            'system_prompt' => $systemPrompt,
            'user_prompt'   => $userPrompt,
        ]);
    }

    // ─── Helpers de contrato ─────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $contract
     * @return array<int,array<string,mixed>>
     */
    public function contractFunctionDeclarations(array $contract): array
    {
        $declarations = $this->requiredArray(
            $contract,
            'function_declarations',
            'extraction_contract sin function_declarations'
        );

        foreach ($declarations as $index => $declaration) {
            if (!is_array($declaration) || trim((string) ($declaration['name'] ?? '')) === '') {
                throw new RuntimeException("extraction_contract function_declaration inválida en posición {$index}");
            }
        }

        return array_values($declarations);
    }

    /**
     * @param array<string,mixed> $contract
     * @return array<int,string>
     */
    public function requiredFunctionNames(array $contract): array
    {
        $names = $this->requiredArray(
            $contract,
            'required_function_names',
            'extraction_contract sin required_function_names'
        );

        $normalized = [];
        foreach ($names as $index => $name) {
            if (!is_string($name) || trim($name) === '') {
                throw new RuntimeException("extraction_contract required_function_name inválido en posición {$index}");
            }
            $normalized[] = trim($name);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string,mixed> $contract
     */
    public function contractRequiresFunction(array $contract, string $functionName): bool
    {
        return in_array($functionName, $this->requiredFunctionNames($contract), true);
    }

    // ─── Helpers internos ────────────────────────────────────────────────────

    private function removeContractRedundantPromptSentences(string $systemPrompt, array $descriptions): string
    {
        if ($descriptions === []) {
            return $systemPrompt;
        }

        $descriptionIndex = [];
        foreach ($descriptions as $description) {
            foreach (array_merge([$description], $this->splitPromptSentences($description)) as $fragment) {
                $normalized = $this->normalizePromptFragment($fragment);
                if (mb_strlen($normalized) > self::PROMPT_DEDUP_MIN_CHARS) {
                    $descriptionIndex[$normalized] = true;
                }
            }
        }

        $normalizedDescriptions = array_keys($descriptionIndex);
        if ($normalizedDescriptions === []) {
            return $systemPrompt;
        }

        $keptSentences = [];
        foreach ($this->splitPromptSentences($systemPrompt) as $sentence) {
            $normalized = $this->normalizePromptFragment($sentence);
            if (!$this->promptSentenceCoveredByDescription($normalized, $normalizedDescriptions)) {
                $keptSentences[] = $sentence;
            }
        }

        return trim(implode(' ', $keptSentences), " \t\n\r\0\x0B,");
    }

    /** @return array<string> */
    private function splitPromptSentences(string $text): array
    {
        $parts = preg_split('/\R+|(?<=[.!?])\s+/', $text);
        if ($parts === false) {
            return [trim($text)];
        }

        $sentences = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $sentences[] = $part;
            }
        }

        return $sentences;
    }

    private function normalizePromptFragment(string $text): string
    {
        $cleaned = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($text));
        if (!is_string($cleaned)) {
            return '';
        }

        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        return is_string($cleaned) ? trim($cleaned) : '';
    }

    /** @param array<int,string> $normalizedDescriptions */
    private function promptSentenceCoveredByDescription(string $normalizedSentence, array $normalizedDescriptions): bool
    {
        if (mb_strlen($normalizedSentence) <= self::PROMPT_DEDUP_MIN_CHARS) {
            return false;
        }

        foreach ($normalizedDescriptions as $description) {
            if (str_contains($description, $normalizedSentence) || str_contains($normalizedSentence, $description)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $contract
     * @param array<string,mixed> $payload
     * @return array<string>
     */
    private function contractDescriptionTexts(array $contract, array $payload): array
    {
        $descriptions = [];

        $declarations = $contract['function_declarations'] ?? [];
        foreach (is_array($declarations) ? $declarations : [] as $declaration) {
            if (!is_array($declaration)) {
                continue;
            }

            foreach ($this->contractFieldSchemas($declaration) as $schema) {
                $description = $this->evidenceValueDescription($schema);
                if ($description !== null) {
                    $descriptions[] = $description;
                }
            }
        }

        $visualChecks = $payload['visual_checks'] ?? [];
        foreach (is_array($visualChecks) ? $visualChecks : [] as $check) {
            $description = is_array($check) ? ($check['description'] ?? null) : null;
            if (is_string($description) && trim($description) !== '') {
                $descriptions[] = trim($description);
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $descriptions))));
    }

    /** @return array<int|string,array<string,mixed>> */
    private function contractFieldSchemas(array $declaration): array
    {
        $name = $declaration['name'] ?? '';
        $schemas = match ($name) {
            DocumentExtractionContractBuilder::FN_EXTRACT_FIELDS => $declaration['parameters']['properties']['fields']['properties'] ?? [],
            DocumentExtractionContractBuilder::FN_EXTRACT_ITEMS  => $declaration['parameters']['properties']['items']['items']['properties'] ?? [],
            default => [],
        };

        return is_array($schemas) ? $schemas : [];
    }

    private function evidenceValueDescription(array $schema): ?string
    {
        $description = $schema['properties']['valor']['description'] ?? $schema['description'] ?? null;
        if (!is_string($description)) {
            return null;
        }

        $description = trim($description);
        return $description !== '' ? $description : null;
    }

    /**
     * @param array<string,mixed> $contract
     * @return array{fields:array<int,string>,items:array<int,string>}
     */
    private function contractFieldGroups(array $contract): array
    {
        $groups = is_array($contract['field_groups'] ?? null) ? $contract['field_groups'] : [];

        return [
            'fields' => $this->stringList($groups['fields'] ?? []),
            'items'  => $this->stringList($groups['items'] ?? []),
        ];
    }

    private function hasIdentitySeparationFields(mixed $fieldsConfig): bool
    {
        if (!is_array($fieldsConfig)) {
            return false;
        }

        foreach ($fieldsConfig as $fieldConfig) {
            if (!is_array($fieldConfig)) {
                continue;
            }

            $tipoDato = trim((string) ($fieldConfig['tipoDato'] ?? ''));
            if ($tipoDato === '') {
                continue;
            }

            try {
                $valueType = AuditFieldValueType::fromInput($tipoDato);
                if ($valueType->isIdentityPromptValue()) {
                    return true;
                }
            } catch (\InvalidArgumentException) {
                // tipo desconocido — ignorar
            }
        }

        return false;
    }

    private function hasVisualCheck(mixed $visualChecks, string $expectedName): bool
    {
        if (!is_array($visualChecks)) {
            return false;
        }

        foreach ($visualChecks as $check) {
            if (is_array($check) && trim((string) ($check['check'] ?? '')) === $expectedName) {
                return true;
            }
        }

        return false;
    }

    private function requiresSegmentedDispensaItems(string $documentType, array $payload): bool
    {
        $sourceTruthItems = is_array($payload['fuente_verdad']['items'] ?? null) ? $payload['fuente_verdad']['items'] : [];

        return strtoupper(trim($documentType)) === 'DISPENSA' && count($sourceTruthItems) > 1;
    }

    /**
     * @param array{fields:array<int,string>,items:array<int,string>} $fieldGroups
     * @return array<string>
     */
    private function buildDispensedItemsContext(string $documentType, array $payload, array $fieldGroups): array
    {
        if (!DocumentExtractionContractBuilder::isPrescriptionDocument($documentType)) {
            return [];
        }

        if (!in_array('NombreArticulo', $fieldGroups['items'] ?? [], true)) {
            return [];
        }

        $fdvItems = $payload['fuente_verdad']['items'] ?? [];
        if (!is_array($fdvItems) || $fdvItems === []) {
            return [];
        }

        $names = [];
        foreach ($fdvItems as $item) {
            $name = trim((string) ($item['NombreArticulo'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $unique = array_values(array_unique($names));

        if ($unique !== []) {
            Logger::info('Prescription selective extraction enabled', [
                'document_type'         => $documentType,
                'dispensed_items_count' => count($unique),
            ]);
        }

        return $unique;
    }

    /** @return array<int,string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $strings[] = trim($item);
            }
        }

        return array_values($strings);
    }

    private function requiredArray(array $payload, string $key, ?string $errorMessage = null): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException($errorMessage ?? "extraction_contract sin {$key}");
        }

        return $value;
    }
}
