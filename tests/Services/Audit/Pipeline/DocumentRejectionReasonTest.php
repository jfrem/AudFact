<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\DocumentRejectionReason;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;

final class DocumentRejectionReasonTest extends TestCase
{
    public function testDocumentExceedsPageLimitIsAllowedReason(): void
    {
        $this->assertTrue(DocumentRejectionReason::isAllowed(DocumentRejectionReason::DOCUMENT_EXCEEDS_PAGE_LIMIT));
        $this->assertSame('DOCUMENT_EXCEEDS_PAGE_LIMIT', DocumentRejectionReason::DOCUMENT_EXCEEDS_PAGE_LIMIT);
    }

    public function testEveryDeclaredConstantIsAllowedAndHasDedicatedAuditorMessage(): void
    {
        $constants = (new ReflectionClass(DocumentRejectionReason::class))
            ->getConstants(ReflectionClassConstant::IS_PUBLIC);
        $reasonConstants = array_filter(
            $constants,
            static fn(string $name): bool => $name !== 'REJECTION_CLASS',
            ARRAY_FILTER_USE_KEY
        );

        $this->assertCount(14, $reasonConstants);

        foreach ($reasonConstants as $reason) {
            $this->assertTrue(DocumentRejectionReason::isAllowed($reason), "La constante {$reason} debe estar habilitada.");

            $message = DocumentRejectionReason::describeForAuditor($reason, 'FORMULA 100%', 10);

            $this->assertNotSame('', trim($message), "Mensaje vacío para {$reason}");
            $this->assertStringContainsString("'FORMULA 100%'", $message);
            $this->assertStringNotContainsString(
                "problema de integridad ({$reason})",
                $message,
                "La razón {$reason} no debe caer al fallback genérico."
            );
            $this->assertStringNotContainsString($reason, $message, "El mensaje humano no debe exponer el código técnico {$reason}.");
        }
    }

    public function testDescribeExceedsPageLimitUsesConfiguredLimitAndHandlesNullOrInvalidLimit(): void
    {
        $withLimit = DocumentRejectionReason::describeForAuditor(
            DocumentRejectionReason::DOCUMENT_EXCEEDS_PAGE_LIMIT,
            'FORMULA MEDICA',
            10
        );
        $this->assertStringContainsString('10 páginas', $withLimit);
        $this->assertStringContainsString('historia clínica o epicrisis', $withLimit);
        $this->assertStringContainsString('validación manual', $withLimit);

        $withoutLimit = DocumentRejectionReason::describeForAuditor(
            DocumentRejectionReason::DOCUMENT_EXCEEDS_PAGE_LIMIT,
            '   ',
            null
        );
        $this->assertStringContainsString("'DOCUMENTO'", $withoutLimit);
        $this->assertStringContainsString('límite máximo de páginas permitido', $withoutLimit);
        $this->assertStringNotContainsString('0 páginas', $withoutLimit);
    }

    public function testUnknownReasonUsesReadableSpanishFallback(): void
    {
        $message = DocumentRejectionReason::describeForAuditor('CUSTOM_UNKNOWN_CODE', 'ACTA DE ENTREGA');

        $this->assertStringContainsString("'ACTA DE ENTREGA'", $message);
        $this->assertStringContainsString('(CUSTOM_UNKNOWN_CODE)', $message);
        $this->assertStringContainsString('revisión manual', $message);
    }
}
