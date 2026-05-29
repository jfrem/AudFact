<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

use Core\Env;
use InvalidArgumentException;

final class AuditEvent
{
    public const TYPE_AUDIT_CREATED        = 'audit_created';
    public const TYPE_BATCH_CREATED        = 'batch_created';
    public const TYPE_DOCUMENT_REGISTERED  = 'document_registered';
    public const TYPE_DOCUMENT_EXTRACTED   = 'document_extracted';
    public const TYPE_DOCUMENT_NORMALIZED  = 'document_normalized';
    public const TYPE_RULES_EVALUATED      = 'rules_evaluated';
    public const TYPE_AUDIT_COMPLETED      = 'audit_completed';
    public const TYPE_AUDIT_FAILED         = 'audit_failed';
    public const TYPE_BATCH_COMPLETED      = 'batch_completed';
    public const TYPE_BATCH_COMPLETED_ERR  = 'batch_completed_with_errors';
    public const TYPE_DEAD_LETTER          = 'dead_letter';
    public const TYPE_BATCH_REQUESTED      = 'batch_requested';

    private const DEFAULT_VERSION_EXTRACTOR = 'gemini-3.x-parallel-fc-v1';

    public readonly string $eventId;
    public readonly ?string $parentEventId;
    public readonly string $eventType;
    public readonly ?string $auditId;
    public readonly ?string $jobId;
    public readonly ?string $documentId;
    public readonly string $timestamp;
    /** @internal Metadata de trazabilidad — no participa en decisiones del pipeline */
    public readonly string $versionExtractor;
    /** @internal Metadata de trazabilidad — no participa en decisiones del pipeline */
    public readonly string $versionNormalizer;
    /** @internal Metadata de trazabilidad — no participa en decisiones del pipeline */
    public readonly string $versionRules;
    /** @var array<string,mixed> */
    public readonly array $payload;

    private function __construct(
        string $eventId,
        ?string $parentEventId,
        string $eventType,
        ?string $auditId,
        ?string $jobId,
        ?string $documentId,
        string $timestamp,
        string $versionExtractor,
        string $versionNormalizer,
        string $versionRules,
        array $payload
    ) {
        self::assertUuid('event_id', $eventId);
        if ($parentEventId !== null) {
            self::assertUuid('parent_event_id', $parentEventId);
        }
        if ($auditId !== null) {
            self::assertUuid('audit_id', $auditId);
        }
        if ($jobId !== null) {
            self::assertUuid('job_id', $jobId);
        }
        if ($eventType === '') {
            throw new InvalidArgumentException('event_type no puede estar vacío');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', $timestamp)) {
            throw new InvalidArgumentException("timestamp debe ser ISO-8601 UTC: {$timestamp}");
        }

        $this->eventId = $eventId;
        $this->parentEventId = $parentEventId;
        $this->eventType = $eventType;
        $this->auditId = $auditId;
        $this->jobId = $jobId;
        $this->documentId = $documentId;
        $this->timestamp = $timestamp;
        $this->versionExtractor = $versionExtractor;
        $this->versionNormalizer = $versionNormalizer;
        $this->versionRules = $versionRules;
        $this->payload = $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function create(
        string $eventType,
        ?string $auditId,
        ?string $jobId = null,
        ?string $documentId = null,
        array $payload = [],
        ?string $parentEventId = null
    ): self {
        return new self(
            eventId: self::uuidV4(),
            parentEventId: $parentEventId,
            eventType: $eventType,
            auditId: $auditId,
            jobId: $jobId,
            documentId: $documentId,
            timestamp: gmdate('Y-m-d\TH:i:s\Z'),
            versionExtractor: self::envVersion('AUDIT_VERSION_EXTRACTOR', self::DEFAULT_VERSION_EXTRACTOR),
            versionNormalizer: self::envVersion('AUDIT_VERSION_NORMALIZER', '1.0.0'),
            versionRules: self::envVersion('AUDIT_VERSION_RULES', '1.0.0'),
            payload: $payload,
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['event_id', 'event_type', 'timestamp'] as $required) {
            if (!isset($data[$required]) || !is_string($data[$required]) || $data[$required] === '') {
                throw new InvalidArgumentException("Campo obligatorio ausente: {$required}");
            }
        }

        $payload = $data['payload'] ?? [];
        if (!is_array($payload)) {
            throw new InvalidArgumentException('payload debe ser un objeto JSON');
        }

        return new self(
            eventId: (string) $data['event_id'],
            parentEventId: isset($data['parent_event_id']) && is_string($data['parent_event_id'])
                ? $data['parent_event_id']
                : null,
            eventType: (string) $data['event_type'],
            auditId: isset($data['audit_id']) && is_string($data['audit_id']) ? $data['audit_id'] : null,
            jobId: isset($data['job_id']) && is_string($data['job_id']) ? $data['job_id'] : null,
            documentId: isset($data['document_id']) && is_string($data['document_id'])
                ? $data['document_id']
                : null,
            timestamp: (string) $data['timestamp'],
            versionExtractor: isset($data['version_extractor']) ? (string) $data['version_extractor'] : self::DEFAULT_VERSION_EXTRACTOR,
            versionNormalizer: isset($data['version_normalizer']) ? (string) $data['version_normalizer'] : '1.0.0',
            versionRules: isset($data['version_rules']) ? (string) $data['version_rules'] : '1.0.0',
            payload: $payload,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id'           => $this->eventId,
            'parent_event_id'    => $this->parentEventId,
            'event_type'         => $this->eventType,
            'audit_id'           => $this->auditId,
            'job_id'             => $this->jobId,
            'document_id'        => $this->documentId,
            'timestamp'          => $this->timestamp,
            'version_extractor'  => $this->versionExtractor,
            'version_normalizer' => $this->versionNormalizer,
            'version_rules'      => $this->versionRules,
            'payload'            => $this->payload,
        ];
    }

    public function toJson(): string
    {
        $encoded = json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('No se pudo serializar AuditEvent: ' . json_last_error_msg());
        }
        return $encoded;
    }

    public static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($data), 4)
        );
    }

    public static function isUuidV4(string $candidate): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $candidate
        );
    }

    private static function assertUuid(string $field, string $value): void
    {
        if (!self::isUuidV4($value)) {
            throw new InvalidArgumentException("{$field} debe ser UUID v4: {$value}");
        }
    }

    private static function envVersion(string $key, string $default): string
    {
        $value = Env::get($key, '');
        return $value !== '' ? $value : $default;
    }
}
