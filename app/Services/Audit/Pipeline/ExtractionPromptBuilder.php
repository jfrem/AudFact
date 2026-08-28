<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use App\Services\Audit\AuditFieldValueType;

/**
 * Construye los prompts del sistema y usuario para la llamada a Gemini con Structured Outputs.
 *
 * Responsabilidades:
 * - buildSystemPrompt(): prompt del sistema (default + deduplicación de contexto del contrato)
 * - buildUserPrompt(): prompt del usuario con campos, ítems, visual checks y contexto de artículos
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
        Responde únicamente con JSON estructurado válido según el schema indicado.

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
            '### Regla de tipología y conformidad documental',
            "1. Verifica primero si el formato y estructura del archivo corresponden genuinamente a un(a) \"{$documentType}\".",
            '2. Si el archivo adjunto corresponde a otra tipología documental distinta (ej: documento de identidad, recibo, factura u otro tipo que no es el solicitado):',
            '   - Asigna `document_conformity.matches_expected_type = false`.',
            '   - Describe en `document_conformity.detected_type` y `document_conformity.justification` lo que contiene el archivo.',
            '   - NO extraigas datos: asigna `null` a todas las propiedades de `fields` y devuelve `items = []`.',
            '3. Si el archivo sí corresponde al tipo documental objetivo, asigna `document_conformity.matches_expected_type = true` y procede con la extracción de los datos visibles.',
            'Extrae solo la información visible en este documento.',
            'No completes campos con inferencias desde otros documentos.',
            'Si el documento está girado o invertido (ej. 180°), orienta la lectura en el sentido correcto de izquierda a derecha sin transponer dígitos.',
        ];

        $fieldGroups = $this->contractFieldGroups($contract);
        $identityDirective = $this->buildIdentityDirective($payload['fields_config'] ?? []);
        if ($identityDirective !== null) {
            $parts[] = $identityDirective;
        }

        if ($fieldGroups['fields'] !== []) {
            $parts[] = 'Campos para `fields`: ' . implode(', ', $fieldGroups['fields']) . '.';
        }

        if ($fieldGroups['items'] !== []) {
            $parts[] = 'Campos para `items`: ' . implode(', ', $fieldGroups['items']) . '.';
        }

        $visualChecks = $payload['visual_checks'] ?? [];
        if ($this->contractRequiresVisualChecks($contract)) {
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
        }

        if ($this->requiresSegmentedItems($payload, $fieldGroups)) {
            $parts[] = 'Este documento contiene multiples lineas de detalle.';
            $parts[] = 'Debes usar `items` con una entrada por cada fila visible.';
            $parts[] = 'No colapses cantidades, lotes, fechas de vencimiento ni codigos de articulo en `fields`.';
        }

        $itemCandidates = $this->buildItemCandidatesContext($payload, $fieldGroups);
        if ($itemCandidates !== []) {
            $parts[] = 'Candidatos de articulo para busqueda en documento:';
            foreach ($itemCandidates as $name) {
                $parts[] = "- {$name}";
            }
            $parts[] = 'En `items`, extrae solo articulos visibles que coincidan de forma exacta u homologa con esos candidatos.';
            $parts[] = 'Devuelve el nombre tal como aparece en el documento.';
        }

        $sections = $this->expectedSchemaSections($contract);
        if ($sections !== []) {
            $parts[] = 'Devuelve un JSON con las siguientes secciones: ' . implode(', ', $sections) . '.';
        }

        return implode("\n", $parts);
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
     * @return array<int,string>
     */
    public function expectedSchemaSections(array $contract): array
    {
        $schema = $contract['response_schema'] ?? null;
        if (is_array($schema) && is_array($schema['properties'] ?? null)) {
            return array_keys($schema['properties']);
        }

        return [];
    }

    /**
     * @param array<string,mixed> $contract
     */
    public function contractRequiresVisualChecks(array $contract): bool
    {
        $schema = $contract['response_schema'] ?? null;
        return is_array($schema) && isset($schema['properties']['visual_checks']);
    }

    /**
     * @param array<string,mixed> $contract
     */
    public function contractRequiresItems(array $contract): bool
    {
        $fieldGroups = $contract['field_groups'] ?? [];
        if (!empty($fieldGroups['items'])) {
            return true;
        }

        $schema = $contract['response_schema'] ?? null;
        return is_array($schema) && isset($schema['properties']['items']);
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

        $responseSchema = $contract['response_schema'] ?? null;
        if (is_array($responseSchema)) {
            $fieldsProps = $responseSchema['properties']['fields']['properties'] ?? [];
            if (is_array($fieldsProps)) {
                foreach ($fieldsProps as $fieldSchema) {
                    $desc = $fieldSchema['description'] ?? null;
                    if (is_string($desc) && trim($desc) !== '') {
                        $descriptions[] = trim($desc);
                    }
                }
            }

            $itemsProps = $responseSchema['properties']['items']['items']['properties'] ?? [];
            if (is_array($itemsProps)) {
                foreach ($itemsProps as $fieldSchema) {
                    $desc = $fieldSchema['description'] ?? null;
                    if (is_string($desc) && trim($desc) !== '') {
                        $descriptions[] = trim($desc);
                    }
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

    private function buildIdentityDirective(mixed $fieldsConfig): ?string
    {
        if (!is_array($fieldsConfig)) {
            return null;
        }

        $identityFields = [];
        foreach ($fieldsConfig as $fieldConfig) {
            if (!is_array($fieldConfig)) {
                continue;
            }

            $tipoDato = trim((string) ($fieldConfig['tipoDato'] ?? ''));
            $fieldName = trim((string) ($fieldConfig['campoNombre'] ?? ''));
            if ($tipoDato === '' || $fieldName === '') {
                continue;
            }

            try {
                $valueType = AuditFieldValueType::fromInput($tipoDato);
                if ($valueType->isIdentityPromptValue()) {
                    $identityFields[] = $fieldName;
                }
            } catch (\InvalidArgumentException) {
                // ignorar tipo desconocido
            }
        }

        $uniqueFields = array_values(array_unique($identityFields));
        if ($uniqueFields === []) {
            return null;
        }

        $fieldsList = implode(', ', $uniqueFields);

        return implode("\n", [
            '### Regla de identidad',
            '',
            "Si una linea combina tipo de documento, numero de identificacion y/o nombre, separalos estrictamente en sus campos correspondientes ({$fieldsList}).",
            '',
            'Solo extrae datos visibles y requeridos; no infieras ni completes identidades faltantes.',
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array{fields:array<int,string>,items:array<int,string>} $fieldGroups
     */
    private function requiresSegmentedItems(array $payload, array $fieldGroups): bool
    {
        $sourceTruthItems = is_array($payload['fuente_verdad']['items'] ?? null) ? $payload['fuente_verdad']['items'] : [];

        return $fieldGroups['items'] !== [] && count($sourceTruthItems) > 1;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array{fields:array<int,string>,items:array<int,string>} $fieldGroups
     * @return array<string>
     */
    private function buildItemCandidatesContext(array $payload, array $fieldGroups): array
    {
        if ($fieldGroups['items'] === []) {
            return [];
        }

        $hasArticleField = false;
        $fieldsConfig = is_array($payload['fields_config'] ?? null) ? $payload['fields_config'] : [];
        foreach ($fieldsConfig as $fieldConfig) {
            if (!is_array($fieldConfig)) {
                continue;
            }
            $tipoDato = trim((string) ($fieldConfig['tipoDato'] ?? ''));
            $campoNombre = trim((string) ($fieldConfig['campoNombre'] ?? ''));
            if (in_array($campoNombre, $fieldGroups['items'], true)) {
                try {
                    if ($tipoDato !== '' && AuditFieldValueType::fromInput($tipoDato) === AuditFieldValueType::ARTICLE_NAME) {
                        $hasArticleField = true;
                        break;
                    }
                } catch (\InvalidArgumentException) {
                    // ignorar
                }
            }
        }

        if (!$hasArticleField) {
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

        return array_values(array_unique($names));
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
}
