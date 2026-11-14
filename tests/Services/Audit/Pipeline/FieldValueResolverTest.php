<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\AuditFieldValueType;
use App\Services\Audit\Pipeline\FieldValueResolver;
use App\Services\Audit\Pipeline\ResolvedAuditValue;
use PHPUnit\Framework\TestCase;

final class FieldValueResolverTest extends TestCase
{
    public function testMergeDocumentArticleNamesCombinesAndDeduplicates(): void
    {
        $base = new ResolvedAuditValue(
            source: ResolvedAuditValue::SOURCE_DOCUMENT,
            displayValue: 'QUETIAPINA 100MG',
            values: ['QUETIAPINA 100MG'],
            normalizedValues: ['QUETIAPINA 100MG'],
            ambiguous: false,
            evidenceMeta: ['field' => 'NombreArticulo']
        );

        $merged = FieldValueResolver::mergeDocumentArticleNames(
            $base,
            ['FLUOXETINA 20MG CÁPSULAS', 'QUETIAPINA 100MG'],
            AuditFieldValueType::ARTICLE_NAME
        );

        $this->assertFalse($merged->ambiguous);
        $this->assertCount(2, $merged->values);
        $this->assertContains('FLUOXETINA 20MG CÁPSULAS', $merged->values);
        $this->assertContains('QUETIAPINA 100MG', $merged->values);
        $this->assertSame(['FLUOXETINA 20MG CÁPSULAS', 'QUETIAPINA 100MG'], $merged->evidenceMeta['unmatched_articles']);
    }

    public function testMergeDocumentArticleNamesReturnsBaseWhenNotArticleNameOrEmptyOrAmbiguous(): void
    {
        $base = new ResolvedAuditValue(
            source: ResolvedAuditValue::SOURCE_DOCUMENT,
            displayValue: 'MED001',
            values: ['MED001'],
            normalizedValues: ['MED001'],
            ambiguous: false,
            evidenceMeta: []
        );

        // Caso 1: tipo no es ARTICLE_NAME
        $res1 = FieldValueResolver::mergeDocumentArticleNames(
            $base,
            ['OTRO_MEDICAMENTO'],
            AuditFieldValueType::CODE
        );
        $this->assertSame($base, $res1);

        // Caso 2: lista vacía
        $res2 = FieldValueResolver::mergeDocumentArticleNames(
            $base,
            [],
            AuditFieldValueType::ARTICLE_NAME
        );
        $this->assertSame($base, $res2);

        // Caso 3: base ambigua
        $ambiguousBase = new ResolvedAuditValue(
            source: ResolvedAuditValue::SOURCE_DOCUMENT,
            displayValue: 'A, B',
            values: ['A', 'B'],
            normalizedValues: ['A', 'B'],
            ambiguous: true,
            evidenceMeta: []
        );
        $res3 = FieldValueResolver::mergeDocumentArticleNames(
            $ambiguousBase,
            ['C'],
            AuditFieldValueType::ARTICLE_NAME
        );
        $this->assertSame($ambiguousBase, $res3);
    }
}
