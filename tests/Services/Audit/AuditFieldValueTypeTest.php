<?php

declare(strict_types=1);

namespace Tests\Services\Audit;

use App\Services\Audit\AuditFieldValueType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuditFieldValueTypeTest extends TestCase
{
    public function testFromInputResolvesEveryConfiguredType(): void
    {
        foreach (AuditFieldValueType::cases() as $valueType) {
            $this->assertSame($valueType, AuditFieldValueType::fromInput($valueType->value));
            $this->assertSame($valueType, AuditFieldValueType::fromInput(strtoupper($valueType->value)));
        }
    }

    public function testFromInputRejectsUnknownType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuditFieldValueType::fromInput('NombrePaciente');
    }

    public function testMultiValueDocumentTypes(): void
    {
        $this->assertTrue(AuditFieldValueType::CODE->allowsMultiValueDocument());
        $this->assertTrue(AuditFieldValueType::TRACE_TOKEN->allowsMultiValueDocument());
        $this->assertFalse(AuditFieldValueType::TEXT->allowsMultiValueDocument());
        $this->assertFalse(AuditFieldValueType::PERSON_NAME->allowsMultiValueDocument());
    }

    public function testComparisonStrategies(): void
    {
        $this->assertTrue(AuditFieldValueType::CODE->requiresSubsetComparison());
        $this->assertTrue(AuditFieldValueType::TRACE_TOKEN->requiresTraceSetComparison());
        $this->assertTrue(AuditFieldValueType::PERSON_NAME->requiresTokenSortComparison());

        $this->assertFalse(AuditFieldValueType::TEXT->requiresSubsetComparison());
        $this->assertFalse(AuditFieldValueType::ARTICLE_NAME->requiresTokenSortComparison());
    }

    public function testQuantityAndSchemaCapabilities(): void
    {
        $this->assertTrue(AuditFieldValueType::QUANTITY->isQuantitySummable());
        $this->assertFalse(AuditFieldValueType::MONEY->isQuantitySummable());

        $this->assertTrue(AuditFieldValueType::QUANTITY->isNumericForSchema());
        $this->assertTrue(AuditFieldValueType::MONEY->isNumericForSchema());
        $this->assertFalse(AuditFieldValueType::CODE->isNumericForSchema());
    }

    public function testOnlyArticleNameAllowsSemanticGeminiFallback(): void
    {
        $this->assertTrue(AuditFieldValueType::ARTICLE_NAME->allowsSemanticGeminiFallback());
        $this->assertFalse(AuditFieldValueType::PERSON_NAME->allowsSemanticGeminiFallback());
        $this->assertFalse(AuditFieldValueType::INSTITUTION_NAME->allowsSemanticGeminiFallback());
        $this->assertFalse(AuditFieldValueType::TEXT->allowsSemanticGeminiFallback());
    }

    public function testAllowedTypesDependOnComparisonType(): void
    {
        $this->assertSame(['quantity'], AuditFieldValueType::allowedValuesForTipoCampo('B'));
        $this->assertSame(
            ['text', 'person_name', 'institution_name', 'article_name'],
            AuditFieldValueType::allowedValuesForTipoCampo('S')
        );
        $this->assertContains('trace_token', AuditFieldValueType::allowedValuesForTipoCampo('E'));
        $this->assertSame([], AuditFieldValueType::allowedValuesForTipoCampo('V'));

        $this->assertTrue(AuditFieldValueType::QUANTITY->isAllowedForTipoCampo('B'));
        $this->assertFalse(AuditFieldValueType::MONEY->isAllowedForTipoCampo('B'));
        $this->assertTrue(AuditFieldValueType::ARTICLE_NAME->isAllowedForTipoCampo('S'));
        $this->assertFalse(AuditFieldValueType::TRACE_TOKEN->isAllowedForTipoCampo('S'));
    }

    public function testIdentityPromptValuesAreExplicit(): void
    {
        $this->assertTrue(AuditFieldValueType::IDENTITY_DOC_TYPE->isIdentityPromptValue());
        $this->assertTrue(AuditFieldValueType::IDENTITY_DOC_NUMBER->isIdentityPromptValue());
        $this->assertTrue(AuditFieldValueType::PERSON_NAME->isIdentityPromptValue());
        $this->assertFalse(AuditFieldValueType::INSTITUTION_NAME->isIdentityPromptValue());
    }
}
