<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

enum AuditTaskStage: string
{
    case ORCHESTRATION = 'orchestration';
    case EXTRACTION = 'extraction';
    case POLICY = 'policy';
    case SEMANTIC = 'semantic';

    public function eventTypes(): array
    {
        return match ($this) {
            self::ORCHESTRATION => [AuditEvent::TYPE_AUDIT_CREATED],
            self::EXTRACTION => [AuditEvent::TYPE_DOCUMENT_DOWNLOADED],
            self::POLICY => [AuditEvent::TYPE_DOCUMENT_NORMALIZED, AuditEvent::TYPE_DOCUMENT_REJECTED, AuditEvent::TYPE_SEMANTIC_RESOLVED],
            self::SEMANTIC => [AuditEvent::TYPE_SEMANTIC_REQUESTED],
        };
    }
}
