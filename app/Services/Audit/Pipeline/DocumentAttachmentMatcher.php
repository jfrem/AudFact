<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

/**
 * Reconciliación determinista, global y uno-a-uno de documentos lógicos con adjuntos físicos.
 */
final class DocumentAttachmentMatcher
{
    /**
     * @param array<int,array<string,mixed>> $configuredDocuments
     * @param array<int|string,array<string,mixed>> $catalogById
     * @param array<int,array<string,mixed>> $physicalAttachments
     */
    public function matchAll(
        array $configuredDocuments,
        array $catalogById,
        array $physicalAttachments
    ): DocumentAttachmentMatchResult {
        $logicalDocuments = $this->buildLogicalDocuments($configuredDocuments, $catalogById);
        $physicalGroups = $this->groupPhysicalAttachments($physicalAttachments);
        $remainingLogical = array_fill_keys(array_keys($logicalDocuments), true);
        $availableAttachments = array_fill_keys(array_keys($physicalGroups), true);
        $usedAttachmentIds = [];
        $ambiguousCandidates = [];
        $matchesByLogical = [];
        $rejectionsByLogical = [];

        $this->matchByExactName(
            $logicalDocuments,
            $physicalGroups,
            $remainingLogical,
            $availableAttachments,
            $usedAttachmentIds,
            $ambiguousCandidates,
            $matchesByLogical,
            $rejectionsByLogical
        );
        $this->matchByValidatedId(
            $logicalDocuments,
            $physicalGroups,
            $remainingLogical,
            $availableAttachments,
            $usedAttachmentIds,
            $ambiguousCandidates,
            $matchesByLogical,
            $rejectionsByLogical
        );
        $this->matchByUniqueAlias(
            $logicalDocuments,
            $physicalGroups,
            $remainingLogical,
            $availableAttachments,
            $usedAttachmentIds,
            $ambiguousCandidates,
            $matchesByLogical,
            $rejectionsByLogical
        );

        foreach (array_keys($remainingLogical) as $logicalKey) {
            $logical = $logicalDocuments[$logicalKey];
            $candidateIds = $this->potentialCandidateIds($logical, $physicalGroups);
            $candidateIds = $this->mergeIds($candidateIds, $ambiguousCandidates[$logicalKey] ?? []);
            $reason = DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_MISSING;

            if (($ambiguousCandidates[$logicalKey] ?? []) !== [] || count($candidateIds) > 1) {
                $reason = DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_AMBIGUOUS;
            } elseif (count($candidateIds) === 1) {
                $candidateId = $candidateIds[0];
                $group = $physicalGroups[$candidateId] ?? null;
                if (isset($usedAttachmentIds[$candidateId])) {
                    $reason = DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_REUSED;
                } elseif ($group !== null && !$this->groupHasContent($group)) {
                    $reason = DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_NO_CONTENT;
                } else {
                    $reason = DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_AMBIGUOUS;
                }
            }

            $physicalAttachment = count($candidateIds) === 1
                ? ($physicalGroups[$candidateIds[0]]['canonical'] ?? null)
                : null;
            $rejectionsByLogical[$logicalKey] = $this->buildRejection(
                $logical,
                $reason,
                $candidateIds,
                is_array($physicalAttachment) ? $physicalAttachment : null
            );
        }

        $matches = [];
        $rejections = [];
        foreach (array_keys($logicalDocuments) as $logicalKey) {
            if (isset($matchesByLogical[$logicalKey])) {
                $matches[] = $matchesByLogical[$logicalKey];
            } elseif (isset($rejectionsByLogical[$logicalKey])) {
                $rejections[] = $rejectionsByLogical[$logicalKey];
            }
        }

        return new DocumentAttachmentMatchResult($matches, $rejections);
    }

    /**
     * @param array<int,array<string,mixed>> $configuredDocuments
     * @param array<int|string,array<string,mixed>> $catalogById
     * @return array<int,array<string,mixed>>
     */
    private function buildLogicalDocuments(array $configuredDocuments, array $catalogById): array
    {
        $logicalDocuments = [];
        foreach (array_values($configuredDocuments) as $index => $configuredDocument) {
            $docId = trim((string) ($configuredDocument['doc_id'] ?? ''));
            $documentName = trim((string) ($configuredDocument['document_name'] ?? ''));
            $catalogDocument = $catalogById[(int) $docId] ?? $catalogById[$docId] ?? [];

            $logicalDocuments[$index] = [
                'logical_doc_id' => $docId,
                'logical_document_name' => $documentName,
                'normalized_name' => self::normalizeDocumentName($documentName),
                'alias' => self::normalizeAlias($catalogDocument['NitMedDocCodAlt'] ?? ''),
                'logical_document' => $configuredDocument,
            ];
        }

        return $logicalDocuments;
    }

    /**
     * @param array<int,array<string,mixed>> $physicalAttachments
     * @return array<string,array{canonical:array<string,mixed>,rows:array<int,array<string,mixed>>,conflict:bool}>
     */
    private function groupPhysicalAttachments(array $physicalAttachments): array
    {
        $groups = [];
        foreach ($physicalAttachments as $attachment) {
            $attachmentId = trim((string) ($attachment['attachment_id'] ?? ''));
            if ($attachmentId === '') {
                continue;
            }
            $groups[$attachmentId]['rows'][] = $attachment;
        }

        foreach ($groups as $attachmentId => $group) {
            $rows = array_values($group['rows']);
            $signatures = [];
            foreach ($rows as $row) {
                $signatures[$this->physicalSignature($row)] = true;
            }
            $groups[$attachmentId] = [
                'canonical' => $rows[0],
                'rows' => $rows,
                'conflict' => count($signatures) > 1,
            ];
        }

        uksort($groups, 'strnatcmp');

        return $groups;
    }

    /**
     * @param array<int,array<string,mixed>> $logicalDocuments
     * @param array<string,array<string,mixed>> $physicalGroups
     * @param array<int,bool> $remainingLogical
     * @param array<string,bool> $availableAttachments
     * @param array<string,bool> $usedAttachmentIds
     * @param array<int,array<int,string>> $ambiguousCandidates
     * @param array<int,array<string,mixed>> $matchesByLogical
     * @param array<int,array<string,mixed>> $rejectionsByLogical
     */
    private function matchByExactName(
        array $logicalDocuments,
        array $physicalGroups,
        array &$remainingLogical,
        array &$availableAttachments,
        array &$usedAttachmentIds,
        array &$ambiguousCandidates,
        array &$matchesByLogical,
        array &$rejectionsByLogical
    ): void {
        $logicalKeysByName = [];
        foreach (array_keys($remainingLogical) as $logicalKey) {
            $name = (string) $logicalDocuments[$logicalKey]['normalized_name'];
            if ($name !== '') {
                $logicalKeysByName[$name][] = $logicalKey;
            }
        }

        foreach ($logicalKeysByName as $name => $logicalKeys) {
            $candidateIds = $this->filterPhysicalIds(
                $physicalGroups,
                $availableAttachments,
                fn(array $group): bool => $this->groupContainsName($group, $name)
            );
            if (count($logicalKeys) === 1 && count($candidateIds) === 1) {
                $logicalKey = $logicalKeys[0];
                $candidateId = $candidateIds[0];
                if (!$physicalGroups[$candidateId]['conflict']) {
                    $this->resolveCandidate(
                        $logicalKey,
                        $candidateId,
                        'exact_name',
                        $logicalDocuments,
                        $physicalGroups,
                        $remainingLogical,
                        $availableAttachments,
                        $usedAttachmentIds,
                        $matchesByLogical,
                        $rejectionsByLogical
                    );
                    continue;
                }
            }

            if ($candidateIds !== []) {
                foreach ($logicalKeys as $logicalKey) {
                    $ambiguousCandidates[$logicalKey] = $this->mergeIds(
                        $ambiguousCandidates[$logicalKey] ?? [],
                        $candidateIds
                    );
                }
            }
        }
    }

    /** @param array<int,array<string,mixed>> $logicalDocuments */
    private function matchByValidatedId(
        array $logicalDocuments,
        array $physicalGroups,
        array &$remainingLogical,
        array &$availableAttachments,
        array &$usedAttachmentIds,
        array &$ambiguousCandidates,
        array &$matchesByLogical,
        array &$rejectionsByLogical
    ): void {
        $logicalKeysById = [];
        foreach (array_keys($remainingLogical) as $logicalKey) {
            $logicalKeysById[(string) $logicalDocuments[$logicalKey]['logical_doc_id']][] = $logicalKey;
        }

        foreach ($logicalKeysById as $logicalId => $logicalKeys) {
            foreach ($logicalKeys as $logicalKey) {
                $logical = $logicalDocuments[$logicalKey];
                $candidateIds = $this->filterPhysicalIds(
                    $physicalGroups,
                    $availableAttachments,
                    fn(array $group): bool => $this->groupMatchesValidatedId(
                        $group,
                        (string) $logicalId,
                        $logical['normalized_name']
                    )
                );
                if (count($logicalKeys) === 1 && count($candidateIds) === 1) {
                    $candidateId = $candidateIds[0];
                    if (!$physicalGroups[$candidateId]['conflict']) {
                        $this->resolveCandidate(
                            $logicalKey,
                            $candidateId,
                            'validated_id',
                            $logicalDocuments,
                            $physicalGroups,
                            $remainingLogical,
                            $availableAttachments,
                            $usedAttachmentIds,
                            $matchesByLogical,
                            $rejectionsByLogical
                        );
                        continue;
                    }
                }

                if ($candidateIds !== []) {
                    $ambiguousCandidates[$logicalKey] = $this->mergeIds(
                        $ambiguousCandidates[$logicalKey] ?? [],
                        $candidateIds
                    );
                }
            }
        }
    }

    /** @param array<int,array<string,mixed>> $logicalDocuments */
    private function matchByUniqueAlias(
        array $logicalDocuments,
        array $physicalGroups,
        array &$remainingLogical,
        array &$availableAttachments,
        array &$usedAttachmentIds,
        array &$ambiguousCandidates,
        array &$matchesByLogical,
        array &$rejectionsByLogical
    ): void {
        $logicalKeysByAlias = [];
        foreach (array_keys($remainingLogical) as $logicalKey) {
            $alias = (string) $logicalDocuments[$logicalKey]['alias'];
            if ($alias !== '') {
                $logicalKeysByAlias[$alias][] = $logicalKey;
            }
        }

        foreach ($logicalKeysByAlias as $alias => $logicalKeys) {
            $candidateIds = $this->filterPhysicalIds(
                $physicalGroups,
                $availableAttachments,
                fn(array $group): bool => $this->groupContainsAlias($group, $alias)
            );
            if (count($logicalKeys) === 1 && count($candidateIds) === 1) {
                $logicalKey = $logicalKeys[0];
                $candidateId = $candidateIds[0];
                if (!$physicalGroups[$candidateId]['conflict']) {
                    $this->resolveCandidate(
                        $logicalKey,
                        $candidateId,
                        'unique_alias',
                        $logicalDocuments,
                        $physicalGroups,
                        $remainingLogical,
                        $availableAttachments,
                        $usedAttachmentIds,
                        $matchesByLogical,
                        $rejectionsByLogical
                    );
                    continue;
                }
            }

            if ($candidateIds !== []) {
                foreach ($logicalKeys as $logicalKey) {
                    $ambiguousCandidates[$logicalKey] = $this->mergeIds(
                        $ambiguousCandidates[$logicalKey] ?? [],
                        $candidateIds
                    );
                }
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $logicalDocuments
     * @param array<string,array<string,mixed>> $physicalGroups
     */
    private function resolveCandidate(
        int $logicalKey,
        string $attachmentId,
        string $strategy,
        array $logicalDocuments,
        array $physicalGroups,
        array &$remainingLogical,
        array &$availableAttachments,
        array &$usedAttachmentIds,
        array &$matchesByLogical,
        array &$rejectionsByLogical
    ): void {
        $logical = $logicalDocuments[$logicalKey];
        $physicalAttachment = $physicalGroups[$attachmentId]['canonical'];

        if ($this->groupHasContent($physicalGroups[$attachmentId])) {
            $matchesByLogical[$logicalKey] = [
                'logical_doc_id' => $logical['logical_doc_id'],
                'logical_document_name' => $logical['logical_document_name'],
                'attachment_id' => $attachmentId,
                'physical_catalog_id' => self::nullableScalar($physicalAttachment['physical_catalog_id'] ?? null),
                'physical_document_name' => (string) ($physicalAttachment['physical_document_name'] ?? ''),
                'strategy' => $strategy,
                'candidate_attachment_ids' => [$attachmentId],
                'logical_document' => $logical['logical_document'],
                'physical_attachment' => $physicalAttachment,
            ];
        } else {
            $rejectionsByLogical[$logicalKey] = $this->buildRejection(
                $logical,
                DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_NO_CONTENT,
                [$attachmentId],
                $physicalAttachment
            );
        }

        unset($remainingLogical[$logicalKey], $availableAttachments[$attachmentId]);
        $usedAttachmentIds[$attachmentId] = true;
    }

    /**
     * @param array<string,mixed> $logical
     * @param array<int,string> $candidateIds
     * @param array<string,mixed>|null $physicalAttachment
     * @return array<string,mixed>
     */
    private function buildRejection(
        array $logical,
        string $reason,
        array $candidateIds,
        ?array $physicalAttachment
    ): array {
        return [
            'logical_doc_id' => $logical['logical_doc_id'],
            'logical_document_name' => $logical['logical_document_name'],
            'reason' => $reason,
            'candidate_attachment_ids' => $this->sortIds($candidateIds),
            'logical_document' => $logical['logical_document'],
            'physical_attachment' => $physicalAttachment,
        ];
    }

    /**
     * @param array<string,mixed> $logical
     * @param array<string,array<string,mixed>> $physicalGroups
     * @return array<int,string>
     */
    private function potentialCandidateIds(array $logical, array $physicalGroups): array
    {
        $ids = [];
        foreach ($physicalGroups as $attachmentId => $group) {
            if (
                $this->groupContainsName($group, $logical['normalized_name'])
                || $this->groupMatchesValidatedId($group, $logical['logical_doc_id'], $logical['normalized_name'])
                || ($logical['alias'] !== '' && $this->groupContainsAlias($group, $logical['alias']))
            ) {
                $ids[] = $attachmentId;
            }
        }

        return $this->sortIds($ids);
    }

    /**
     * @param array<string,array<string,mixed>> $physicalGroups
     * @param array<string,bool> $availableAttachments
     * @return array<int,string>
     */
    private function filterPhysicalIds(array $physicalGroups, array $availableAttachments, callable $predicate): array
    {
        $ids = [];
        foreach (array_keys($availableAttachments) as $attachmentId) {
            if ($predicate($physicalGroups[$attachmentId])) {
                $ids[] = $attachmentId;
            }
        }

        return $this->sortIds($ids);
    }

    /** @param array<string,mixed> $group */
    private function groupContainsName(array $group, string $normalizedName): bool
    {
        if ($normalizedName === '') {
            return false;
        }
        foreach ($group['rows'] as $row) {
            if (self::normalizeDocumentName($row['physical_document_name'] ?? '') === $normalizedName) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $group */
    private function groupMatchesValidatedId(array $group, string $logicalId, string $logicalName): bool
    {
        foreach ($group['rows'] as $row) {
            if (trim((string) ($row['physical_catalog_id'] ?? '')) !== $logicalId) {
                continue;
            }
            $physicalName = self::normalizeDocumentName($row['physical_document_name'] ?? '');
            if ($physicalName === '' || $physicalName === $logicalName) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $group */
    private function groupContainsAlias(array $group, string $logicalAlias): bool
    {
        foreach ($group['rows'] as $row) {
            if (
                self::normalizeAlias($row['physical_catalog_alias'] ?? '') === $logicalAlias
                || self::normalizeAlias($row['physical_stored_alias'] ?? '') === $logicalAlias
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $group */
    private function groupHasContent(array $group): bool
    {
        foreach ($group['rows'] as $row) {
            if (in_array(strtoupper(trim((string) ($row['storage_type'] ?? ''))), ['URL', 'BLOB'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $row */
    private function physicalSignature(array $row): string
    {
        return implode('|', [
            trim((string) ($row['physical_catalog_id'] ?? '')),
            self::normalizeDocumentName($row['physical_document_name'] ?? ''),
            self::normalizeAlias($row['physical_catalog_alias'] ?? ''),
            self::normalizeAlias($row['physical_stored_alias'] ?? ''),
            strtoupper(trim((string) ($row['storage_type'] ?? ''))),
        ]);
    }

    private static function normalizeDocumentName(mixed $name): string
    {
        return DocumentExtractionContractBuilder::normalizeDocumentName((string) $name);
    }

    private static function normalizeAlias(mixed $alias): string
    {
        return strtoupper(trim((string) $alias));
    }

    private static function nullableScalar(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** @param array<int,string> $ids */
    private function sortIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        usort($ids, 'strnatcmp');

        return $ids;
    }

    /** @param array<int,string> $left @param array<int,string> $right */
    private function mergeIds(array $left, array $right): array
    {
        return $this->sortIds(array_merge($left, $right));
    }
}
