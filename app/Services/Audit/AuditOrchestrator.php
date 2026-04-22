<?php

namespace App\Services\Audit;

use Core\Logger;

class AuditOrchestrator
{
    private AuditFileManager $fileManager;
    private GeminiGateway $gateway;
    private AuditPersistenceService $persistence;
    private AuditTelemetryService $telemetry;
    private AuditPreValidator $preValidator;

    private ExtractionPromptBuilder $extractionPrompt;
    private EmbeddingGateway $embeddingGateway;
    private SemanticComparator $comparator;
    private FieldClassifier $classifier;
    private RuleEngine $ruleEngine;

    /**
     * Recibe todas las dependencias necesarias para ejecutar el pipeline v4.
     *
     * @param  AuditFileManager $fileManager  Preparación y limpieza de adjuntos.
     * @param  GeminiGateway $gateway  Cliente Gemini multimodal.
     * @param  AuditPersistenceService $persistence  Persistencia en disco/BD/cache.
     * @param  AuditTelemetryService $telemetry  Construcción de métricas técnicas.
     * @param  AuditPreValidator $preValidator  Guardas previas al llamado IA.
     * @param  ExtractionPromptBuilder $extractionPrompt  Prompt de extracción.
     * @param  EmbeddingGateway $embeddingGateway  Cliente de embeddings.
     * @param  SemanticComparator $comparator  Comparador semántico.
     * @param  FieldClassifier $classifier  Catálogo y clasificación de campos.
     * @param  RuleEngine $ruleEngine  Motor determinista de evaluación.
     * @return void
     */
    public function __construct(
        AuditFileManager $fileManager,
        GeminiGateway $gateway,
        AuditPersistenceService $persistence,
        AuditTelemetryService $telemetry,
        AuditPreValidator $preValidator,
        ExtractionPromptBuilder $extractionPrompt,
        EmbeddingGateway $embeddingGateway,
        SemanticComparator $comparator,
        FieldClassifier $classifier,
        RuleEngine $ruleEngine
    ) {
        $this->fileManager = $fileManager;
        $this->gateway = $gateway;
        $this->persistence = $persistence;
        $this->telemetry = $telemetry;
        $this->preValidator = $preValidator;
        $this->extractionPrompt = $extractionPrompt;
        $this->embeddingGateway = $embeddingGateway;
        $this->comparator = $comparator;
        $this->classifier = $classifier;
        $this->ruleEngine = $ruleEngine;
    }

    /**
     * Ejecuta una auditoría completa para una dispensación: prevalidación, extracción, embeddings, reglas y persistencia.
     *
     * @param  string $invoiceId  Identificador de factura recibido como entrada.
     * @param  string $disDetNro  Identificador de dispensación a auditar.
     * @param  string|null $attachmentId  ID de adjunto específico; reservado para flujos selectivos.
     * @return array<string, mixed> Resultado normalizado del pipeline.
     */
    public function auditInvoice(string $invoiceId, string $disDetNro, ?string $attachmentId = null): array
    {
        $totalStart = hrtime(true);

        $preValidation = $this->preValidator->validate($invoiceId, $disDetNro);

        if ($preValidation['result'] !== null) {
            return $this->handlePreValidationFailure($preValidation, $disDetNro, $totalStart);
        }

        $dispensationData = $preValidation['dispensationData'];
        $dispensation = $preValidation['dispensation'];
        $files = $preValidation['files'];
        $dataFetchMs = $preValidation['dataFetchMs'];
        $filePrepMs = $preValidation['filePrepMs'];
        $auditConfig = $preValidation['auditConfig'] ?? [];

        $phaseTimes = ['extraction' => 0.0, 'embedding' => 0.0, 'rules' => 0.0];

        try {
            $phase1Start = hrtime(true);

            $extractionResult = $this->executeExtraction(
                $dispensationData,
                $files,
                $auditConfig
            );

            $phaseTimes['extraction'] = (hrtime(true) - $phase1Start) / 1e6;

            Logger::info('Fase 1 completada: extracción', [
                'DisDetNro' => $disDetNro,
                'documentsExtracted' => count($extractionResult['documents'] ?? []),
                'timeMs' => round($phaseTimes['extraction']),
            ]);

            $phase2Start = hrtime(true);

            $semanticResults = $this->executeSemanticComparison(
                $dispensationData,
                $extractionResult,
                $auditConfig
            );

            $phaseTimes['embedding'] = (hrtime(true) - $phase2Start) / 1e6;

            Logger::info('Fase 2 completada: embedding', [
                'DisDetNro' => $disDetNro,
                'pairsCompared' => count($semanticResults),
                'timeMs' => round($phaseTimes['embedding']),
            ]);

            $phase3Start = hrtime(true);

            $ruleAuditConfig = $auditConfig;
            $ruleAuditConfig['_extractionQuality'] = $extractionResult['extractionQuality'] ?? [];

            $result = $this->ruleEngine->evaluate(
                $dispensationData,
                $extractionResult['documents'] ?? [],
                $extractionResult['visualChecks'] ?? [],
                $semanticResults,
                $ruleAuditConfig,
                $this->classifier
            );

            $phaseTimes['rules'] = (hrtime(true) - $phase3Start) / 1e6;

            Logger::info('Fase 3 completada: reglas', [
                'DisDetNro' => $disDetNro,
                'response' => $result['response'] ?? 'unknown',
                'discrepancies' => $result['metrics']['TotalDiscrepancias'] ?? 0,
                'timeMs' => round($phaseTimes['rules']),
            ]);
        } catch (\Exception $e) {
            Logger::error('Error en pipeline v4', [
                'DisDetNro' => $disDetNro,
                'error' => $e->getMessage(),
                'httpCode' => (int) $e->getCode(),
                'phaseTimes' => $phaseTimes,
            ]);

            $shortMsg = $this->telemetry->formatErrorMessage(
                (int) $e->getCode(),
                $e->getMessage(),
                $disDetNro
            );
            $result = $this->errorResponse($shortMsg, ['raw' => $e->getMessage()]);
        } finally {
            foreach ($files as $file) {
                $this->fileManager->cleanup($file);
            }
        }

        $totalMs = (hrtime(true) - $totalStart) / 1e6;
        $geminiApiMs = $phaseTimes['extraction'] + $phaseTimes['embedding'];

        $result['_meta'] = $this->telemetry->buildMeta(
            $dispensation,
            $files,
            $dataFetchMs,
            $filePrepMs,
            $geminiApiMs,
            1, // single-pass (no retry loop)
            $totalMs,
            $extractionResult['promptHash'] ?? ''
        );

        $result['_meta']['v4_phases'] = [
            'extractionMs' => round($phaseTimes['extraction']),
            'embeddingMs' => round($phaseTimes['embedding']),
            'rulesMs' => round($phaseTimes['rules']),
        ];
        $result['_meta']['modelConfig'] = $this->buildModelDebugConfig();
        $result['_meta']['extractionQuality'] = $extractionResult['extractionQuality'] ?? [];

        Logger::info('Auditoría v4 completada', [
            'DisDetNro' => $disDetNro,
            'totalTimeMs' => round($totalMs),
            'phases' => $result['_meta']['v4_phases'],
        ]);

        $this->persistence->saveResponse($disDetNro, $result);
        $persisted = $this->persistence->saveToDatabase($disDetNro, $result, $dispensation);

        if (!$persisted) {
            Logger::warning('Persistencia en BD falló', ['DisDetNro' => $disDetNro]);
        }

        return $result;
    }

    /**
     * Ejecuta la fase 1 de extracción documental con Function Calling.
     *
     * @param  array<int, array<string, mixed>> $dispensationData  Datos FDV de la dispensación.
     * @param  array<int, array<string, mixed>> $files  Archivos preparados para Gemini.
     * @param  array<string, mixed> $auditConfig  Configuración dinámica del cliente.
     * @return array<string, mixed> Datos extraídos, visual checks y hash de prompt.
     * @throws \RuntimeException Si Gemini no invoca la función report_extraction.
     */
    private function executeExtraction(array $dispensationData, array $files, array $auditConfig): array
    {
        $systemInstruction = $this->extractionPrompt->getSystemInstruction();

        $documentLabels = array_map(
            fn(array $f): string => $f['label'] ?? 'Documento',
            $files
        );

        $userPrompt = $this->extractionPrompt->buildUserPrompt(
            $auditConfig,
            $dispensationData,
            $documentLabels
        );

        $fieldsToExtract = $this->extractionPrompt->resolveFieldsFromConfig($auditConfig);
        $visualChecks = $this->extractionPrompt->resolveVisualChecksFromConfig($auditConfig);
        $documentRequirements = $this->extractionPrompt->resolveDocumentFieldRequirements($auditConfig);

        $docTypes = array_values(array_unique(array_map(
            [ExtractionResponseSchema::class, 'normalizeDocType'],
            $documentLabels
        )));

        $tools = ExtractionResponseSchema::getToolsBlock($fieldsToExtract, $visualChecks, $docTypes, $documentRequirements);
        $toolConfig = ExtractionResponseSchema::getToolConfig();

        Logger::info('Fase 1: enviando a Gemini FC', [
            'fieldsToExtract' => count($fieldsToExtract),
            'visualChecks' => count($visualChecks),
            'filesCount' => count($files),
            'promptLength' => strlen($userPrompt),
        ]);

        $geminiResponse = $this->gateway->sendWithFunctionCalling(
            $userPrompt,
            $files,
            $systemInstruction,
            $tools,
            $toolConfig
        );

        $extracted = ExtractionResponseSchema::parseExtractionResponse($geminiResponse);

        if ($extracted === null) {
            Logger::error('Fase 1: Gemini no invocó report_extraction', [
                'finishReason' => $geminiResponse['candidates'][0]['finishReason'] ?? 'unknown',
            ]);
            throw new \RuntimeException('Gemini no invocó report_extraction — function calling falló');
        }

        $extracted['promptHash'] = md5($systemInstruction . $userPrompt);
        $extracted['documentRequirements'] = $documentRequirements;
        $extracted['extractionQuality'] = $this->buildExtractionQuality(
            is_array($extracted['documents'] ?? null) ? $extracted['documents'] : [],
            $documentRequirements
        );

        return $extracted;
    }

    /**
     * Ejecuta la fase 2 comparando campos semánticos entre FDV y documentos.
     *
     * @param  array<int, array<string, mixed>> $dispensationData  Datos FDV.
     * @param  array<string, mixed> $extractionResult  Resultado de la fase de extracción.
     * @param  array<string, mixed> $auditConfig  Configuración dinámica del cliente.
     * @return array<int, array<string, mixed>> Resultados semánticos por campo.
     */
    private function executeSemanticComparison(
        array $dispensationData,
        array $extractionResult,
        array $auditConfig
    ): array
    {
        $semanticFields = $this->resolveSemanticFields($auditConfig);

        if (empty($semanticFields)) {
            return [];
        }

        $extractedByDoc = [];
        foreach ($extractionResult['documents'] ?? [] as $doc) {
            $rawType = $doc['type'] ?? 'UNKNOWN';
            $type = ExtractionResponseSchema::normalizeDocType($rawType);
            $fields = $doc['fields'] ?? [];
            foreach ($fields as $key => $value) {
                if ($value !== null && trim($value) !== '') {
                    $extractedByDoc[$type][$key] = $value;
                }
            }
        }

        $pairs = [];
        foreach ($semanticFields as $fieldConfig) {
            $field = $fieldConfig['field'];
            $document = $fieldConfig['document'] ?? null;
            $fdvValue = $this->resolveFdvValue($field, $dispensationData);
            $docValue = $document !== null
                ? ($extractedByDoc[$document][$field] ?? null)
                : $this->resolveDocValueByPriority($field, $extractedByDoc);

            if ($fdvValue === null || $docValue === null) {
                continue;
            }

            if (trim($fdvValue) === '' || trim($docValue) === '') {
                continue;
            }

            $pairs[] = [
                'field' => $field,
                'document' => $document,
                'fdvValue' => $fdvValue,
                'docValue' => $docValue,
            ];
        }

        if (empty($pairs)) {
            Logger::info('Fase 2: sin pares semánticos para comparar');
            return [];
        }

        Logger::info('Fase 2: pares semánticos a comparar', [
            'count' => count($pairs),
            'pairs' => array_map(
                static fn(array $p): array => [
                    'field' => $p['field'],
                    'document' => $p['document'] ?? null,
                    'fdv' => mb_substr($p['fdvValue'], 0, 60),
                    'doc' => mb_substr($p['docValue'], 0, 60),
                ],
                $pairs
            ),
        ]);

        return $this->comparator->compareBatch($pairs, $this->embeddingGateway);
    }

    /**
     * Resuelve los campos semánticos configurados por documento o usa fallback del clasificador.
     *
     * @param  array<string, mixed> $auditConfig  Configuración dinámica del cliente.
     * @return array<int, array{field: string, document: string|null}> Campos semánticos a comparar.
     */
    private function resolveSemanticFields(array $auditConfig): array
    {
        $documents = $auditConfig['documents'] ?? [];
        if (!is_array($documents) || empty($documents)) {
            return array_map(
                static fn(string $field): array => ['field' => $field, 'document' => null],
                $this->classifier->getFieldsByType(FieldClassifier::TYPE_SEMANTIC)
            );
        }

        $fields = [];
        $seen = [];
        foreach ($documents as $docName => $doc) {
            if (!is_array($doc)) {
                continue;
            }

            $documentType = is_string($docName)
                ? ExtractionResponseSchema::normalizeDocType($docName)
                : null;

            foreach (($doc['fields'] ?? []) as $field) {
                $fieldName = is_string($field)
                    ? trim($field)
                    : (is_array($field) ? (string) ($field['field'] ?? $field['name'] ?? $field['campoNombre'] ?? '') : '');

                if ($fieldName === '') {
                    continue;
                }

                $fieldName = $this->classifier->normalizeField($fieldName);
                if ($this->classifier->classify($fieldName) === FieldClassifier::TYPE_SEMANTIC) {
                    $key = $fieldName . '|' . ($documentType ?? '');
                    if (!isset($seen[$key])) {
                        $fields[] = ['field' => $fieldName, 'document' => $documentType];
                        $seen[$key] = true;
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * Obtiene el valor de Fuente de Verdad para un campo, agregando filas cuando es per-item.
     *
     * @param  string $field  Campo canónico a consultar.
     * @param  array<int, array<string, mixed>>|array<string, mixed> $data  Datos FDV.
     * @return string|null Valor FDV normalizado a string, o null si no existe.
     */
    private function resolveFdvValue(string $field, array $data): ?string
    {
        $column = $this->classifier->getSqlColumn($field);
        $isMultiRow = isset($data[0]) && is_array($data[0]);

        if ($isMultiRow && count($data) > 1 && in_array($field, RuleEngine::PER_ITEM_FIELDS, true)) {
            $values = [];
            foreach ($data as $row) {
                $val = $this->extractRowValueSimple($row, $field, $column);
                if ($val !== null) {
                    $values[] = $val;
                }
            }

            if (empty($values)) {
                return null;
            }

            $unique = array_unique($values);
            if (count($unique) === 1) {
                return $unique[0];
            }

            return implode(', ', $values);
        }

        $row = $isMultiRow ? ($data[0] ?? null) : $data;
        if ($row === null || !is_array($row)) {
            return null;
        }

        return $this->extractRowValueSimple($row, $field, $column);
    }

    /**
     * Extrae un valor no vacío desde una fila usando campo canónico o columna SQL.
     *
     * @param  array<string, mixed> $row  Fila de FDV.
     * @param  string $field  Campo canónico.
     * @param  string|null $column  Columna SQL alternativa.
     * @return string|null Valor encontrado.
     */
    private function extractRowValueSimple(array $row, string $field, ?string $column): ?string
    {
        if (isset($row[$field]) && is_string($row[$field]) && trim($row[$field]) !== '') {
            return $row[$field];
        }

        if ($column !== null && $column !== $field && isset($row[$column]) && trim((string) $row[$column]) !== '') {
            return (string) $row[$column];
        }

        return null;
    }

    /**
     * Persiste y devuelve un resultado de prevalidación que aborta el flujo IA.
     *
     * @param  array<string, mixed> $preValidation  Salida temprana del prevalidator.
     * @param  string $disDetNro  Identificador de dispensación.
     * @param  int $totalStart  Marca hrtime inicial del pipeline.
     * @return array<string, mixed> Resultado final ya persistido.
     */
    private function handlePreValidationFailure(array $preValidation, string $disDetNro, int $totalStart): array
    {
        $failResult = $preValidation['result'];
        $dispensation = is_array($preValidation['dispensation'] ?? null) ? $preValidation['dispensation'] : [];
        $dataFetchMs = (float) ($preValidation['dataFetchMs'] ?? 0.0);
        $filePrepMs = (float) ($preValidation['filePrepMs'] ?? 0.0);
        $availableDocuments = is_array($preValidation['availableDocuments'] ?? null) ? $preValidation['availableDocuments'] : [];

        $metaFiles = array_map(
            static fn(string $name): array => ['label' => $name],
            array_values(array_filter($availableDocuments, static fn($name) => is_string($name) && trim($name) !== ''))
        );

        $totalMs = (hrtime(true) - $totalStart) / 1e6;

        if (!isset($failResult['_meta']) || !is_array($failResult['_meta'])) {
            $failResult['_meta'] = $this->telemetry->buildMeta(
                $dispensation,
                $metaFiles,
                $dataFetchMs,
                $filePrepMs,
                0.0,
                0,
                $totalMs
            );
        }
        $failResult['_meta']['modelConfig'] = $this->buildModelDebugConfig();

        $this->persistence->saveResponse($disDetNro, $failResult);
        $this->persistence->saveToDatabase($disDetNro, $failResult, $dispensation);

        return $failResult;
    }

    /**
     * Construye una respuesta de error compatible con el schema del frontend.
     *
     * @param  string $message  Mensaje público del error.
     * @param  array<string, mixed> $data  Detalles opcionales para enriquecer la respuesta.
     * @return array<string, mixed> Respuesta de error normalizada.
     */
    private function errorResponse(string $message, array $data = []): array
    {
        return [
            'response' => 'error',
            'severity' => $data['severity'] ?? 'alta',
            'message' => $message,
            'documento' => $data['documento'] ?? AuditResponseSchema::DOCUMENTO_MULTIPLE,
            '_errorOrigin' => $data['_errorOrigin'] ?? 'infrastructure',
            'data' => [
                'items' => $data['items'] ?? [],
                'details' => $data['raw'] ?? null,
            ],
            'metrics' => AuditResponseSchema::getEmptyMetrics(),
            'config_used' => AuditResponseSchema::getEmptyConfig(),
        ];
    }

    /**
     * Consolida configuración efectiva no sensible de modelos IA para debug.
     *
     * @return array<string, mixed> Configuración segura de extracción y embeddings.
     */
    private function buildModelDebugConfig(): array
    {
        return [
            'extraction' => $this->gateway->getDebugConfig(),
            'embedding' => $this->embeddingGateway->getDebugConfig(),
        ];
    }

    /**
     * Resume calidad de extracción comparando llaves devueltas contra contrato esperado.
     *
     * @param  array<int, array<string, mixed>> $documents  Documentos extraídos por Gemini.
     * @param  array<string, array{sourceLabel: string, fields: array<int, string>, visualChecks: array<int, string>}> $requirements  Contrato por documento.
     * @return array<string, array<string, mixed>> Calidad por documento.
     */
    private function buildExtractionQuality(array $documents, array $requirements): array
    {
        $returnedByDoc = [];
        foreach ($documents as $doc) {
            $type = ExtractionResponseSchema::normalizeDocType((string) ($doc['type'] ?? 'UNKNOWN'));
            $fields = is_array($doc['fields'] ?? null) ? $doc['fields'] : [];
            $returnedByDoc[$type] = array_merge($returnedByDoc[$type] ?? [], $fields);
        }

        $quality = [];
        foreach ($requirements as $documentType => $requirement) {
            $expectedFields = $requirement['fields'];
            $returnedFields = $returnedByDoc[$documentType] ?? [];
            $returnedKeys = array_keys($returnedFields);
            $missingKeys = [];
            $nullFields = [];

            foreach ($expectedFields as $field) {
                if (!array_key_exists($field, $returnedFields)) {
                    $missingKeys[] = $field;
                    continue;
                }

                $value = $returnedFields[$field];
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    $nullFields[] = $field;
                }
            }

            $quality[$documentType] = [
                'sourceLabel' => $requirement['sourceLabel'],
                'expected' => count($expectedFields),
                'returnedKeys' => count($returnedKeys),
                'missingKeys' => $missingKeys,
                'nullFields' => $nullFields,
            ];
        }

        return $quality;
    }

    /**
     * Selecciona el valor documental de un campo priorizando documento autoritativo y alternativos.
     *
     * @param  string $field  Campo canónico buscado.
     * @param  array<string, array<string, mixed>> $extractedByDoc  Campos extraídos agrupados por tipo documental.
     * @return string|null Valor documental encontrado.
     */
    private function resolveDocValueByPriority(string $field, array $extractedByDoc): ?string
    {
        $authDoc = $this->classifier->getAuthoritativeDoc($field);
        if ($authDoc !== null && isset($extractedByDoc[$authDoc][$field])) {
            $val = $extractedByDoc[$authDoc][$field];
            if (is_string($val) && trim($val) !== '') {
                return $val;
            }
        }

        foreach ($this->classifier->getAlternativeDocs($field) as $altDoc) {
            if (isset($extractedByDoc[$altDoc][$field])) {
                $val = $extractedByDoc[$altDoc][$field];
                if (is_string($val) && trim($val) !== '') {
                    return $val;
                }
            }
        }

        foreach ($extractedByDoc as $fields) {
            if (isset($fields[$field])) {
                $val = $fields[$field];
                if (is_string($val) && trim($val) !== '') {
                    return $val;
                }
            }
        }

        return null;
    }
}
