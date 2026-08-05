<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\DocumentDuplicationEvaluator;
use PHPUnit\Framework\TestCase;

final class DocumentDuplicationEvaluatorTest extends TestCase
{
    public function testSameHashAndSamePhysicalIdDoNotProduceDuplication(): void
    {
        // Arrange:
        $audit = ['documents' => [
            $this->document('FORMULA MEDICA', 'HASH-A', '10'),
            $this->document('FORMULA MEDICA', 'HASH-A', '10'),
        ]];

        // Act:
        $findings = DocumentDuplicationEvaluator::evaluate($audit);

        // Assert:
        $this->assertSame([], $findings);
    }

    public function testSameHashAndDistinctPhysicalIdsProduceOneFindingPerAttachment(): void
    {
        // Arrange:
        $audit = ['documents' => [
            $this->document('FORMULA MEDICA', 'HASH-B', '10'),
            $this->document('ACTA DE ENTREGA', 'HASH-B', '11'),
        ]];

        // Act:
        $findings = DocumentDuplicationEvaluator::evaluate($audit);

        // Assert:
        $this->assertCount(2, $findings);
        $this->assertSame(['DUP', 'DUP'], array_column($findings, 'codigoCampo'));
        $this->assertSame(['FORMULA MEDICA', 'ACTA DE ENTREGA'], array_column($findings, 'documento'));
    }

    public function testRepeatedStateForOneAttachmentDoesNotInflateDistinctDuplicationFindings(): void
    {
        // Arrange:
        $audit = ['documents' => [
            $this->document('FORMULA MEDICA', 'HASH-C', '10'),
            $this->document('FORMULA MEDICA', 'HASH-C', '10'),
            $this->document('ACTA DE ENTREGA', 'HASH-C', '11'),
        ]];

        // Act:
        $findings = DocumentDuplicationEvaluator::evaluate($audit);

        // Assert:
        $this->assertCount(2, $findings);
        $this->assertSame(['FORMULA MEDICA', 'ACTA DE ENTREGA'], array_column($findings, 'documento'));
    }

    /** @return array<string,string> */
    private function document(string $name, string $hash, string $attachmentId): array
    {
        return [
            'tipo_documento' => $name,
            'document_hash' => $hash,
            'attachment_id' => $attachmentId,
        ];
    }
}
