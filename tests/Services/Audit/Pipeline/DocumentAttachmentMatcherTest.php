<?php

declare(strict_types=1);

namespace Tests\Services\Audit\Pipeline;

use App\Services\Audit\Pipeline\DocumentAttachmentMatcher;
use App\Services\Audit\Pipeline\DocumentAttachmentMatchResult;
use App\Services\Audit\Pipeline\DocumentMappingRejectionReason;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DocumentAttachmentMatcherTest extends TestCase
{
    public function test2624ResolvesFourDistinctPhysicalAttachmentsDespiteSharedAlias(): void
    {
        // Arrange:
        $matcher = new DocumentAttachmentMatcher();
        $configured = [
            ['doc_id' => 1, 'document_name' => 'DISPENSA'],
            ['doc_id' => 2, 'document_name' => 'AUTORIZACION'],
            ['doc_id' => 3, 'document_name' => 'FORMULA MEDICA'],
            ['doc_id' => 4, 'document_name' => 'VALIDADOR DE DERECHOS'],
        ];
        $catalog = [
            1 => ['NitMedDocCodAlt' => 'ANE'],
            2 => ['NitMedDocCodAlt' => 'PDE'],
            3 => ['NitMedDocCodAlt' => 'OPF'],
            4 => ['NitMedDocCodAlt' => 'PDE'],
        ];
        $physical = [
            $this->attachment('1', '1', 'DISPENSA', 'ANE'),
            $this->attachment('6', '6', 'AUTORIZACION', 'PDE'),
            $this->attachment('3', '3', 'FORMULA MEDICA', 'OPF'),
            $this->attachment('4', '4', 'VALIDADOR DE DERECHOS', 'PDE'),
        ];

        // Act:
        $result = $matcher->matchAll($configured, $catalog, $physical);

        // Assert:
        $this->assertSame([], $result->rejections);
        $this->assertSame(['1', '6', '3', '4'], array_column($result->matches, 'attachment_id'));
        $this->assertSame(['1', '2', '3', '4'], array_column($result->matches, 'logical_doc_id'));
        $this->assertSame(
            ['exact_name', 'exact_name', 'exact_name', 'exact_name'],
            array_column($result->matches, 'strategy')
        );
        $this->assertCount(4, array_unique(array_column($result->matches, 'attachment_id')));
    }

    public function testRepeatedAliasWithoutExactNameIsAmbiguous(): void
    {
        // Arrange:
        $matcher = new DocumentAttachmentMatcher();
        $configured = [['doc_id' => 2, 'document_name' => 'AUTORIZACION']];
        $catalog = [2 => ['NitMedDocCodAlt' => 'PDE']];
        $physical = [
            $this->attachment('4', null, 'SOPORTE CUATRO', 'PDE'),
            $this->attachment('6', null, 'SOPORTE SEIS', 'PDE'),
        ];

        // Act:
        $result = $matcher->matchAll($configured, $catalog, $physical);

        // Assert:
        $this->assertSame([], $result->matches);
        $this->assertSame(
            DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_AMBIGUOUS,
            $result->rejections[0]['reason']
        );
        $this->assertSame(['4', '6'], $result->rejections[0]['candidate_attachment_ids']);
    }

    public function testConflictingRowsForSamePhysicalIdAreNotReusedAsTwoAttachments(): void
    {
        // Arrange:
        $matcher = new DocumentAttachmentMatcher();
        $configured = [
            ['doc_id' => 2, 'document_name' => 'AUTORIZACION'],
            ['doc_id' => 4, 'document_name' => 'VALIDADOR DE DERECHOS'],
        ];
        $catalog = [
            2 => ['NitMedDocCodAlt' => 'PDE'],
            4 => ['NitMedDocCodAlt' => 'PDE'],
        ];
        $physical = [
            $this->attachment('6', '2', 'AUTORIZACION', 'PDE'),
            $this->attachment('6', '4', 'VALIDADOR DE DERECHOS', 'PDE'),
        ];

        // Act:
        $result = $matcher->matchAll($configured, $catalog, $physical);

        // Assert:
        $this->assertSame([], $result->matches);
        $this->assertCount(2, $result->rejections);
        $this->assertSame(
            [
                DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_AMBIGUOUS,
                DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_AMBIGUOUS,
            ],
            array_column($result->rejections, 'reason')
        );
        $this->assertSame([['6'], ['6']], array_column($result->rejections, 'candidate_attachment_ids'));
    }

    public function testSameCatalogIdWithIncompatiblePhysicalNameIsNotAccepted(): void
    {
        // Arrange:
        $matcher = new DocumentAttachmentMatcher();
        $configured = [['doc_id' => 5, 'document_name' => 'ACTA DE ENTREGA']];
        $catalog = [5 => ['NitMedDocCodAlt' => 'CRC']];
        $physical = [$this->attachment('50', '5', 'FORMULA MEDICA', 'OPF')];

        // Act:
        $result = $matcher->matchAll($configured, $catalog, $physical);

        // Assert:
        $this->assertSame([], $result->matches);
        $this->assertSame(
            DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_MISSING,
            $result->rejections[0]['reason']
        );
        $this->assertSame([], $result->rejections[0]['candidate_attachment_ids']);
    }

    public function testEmptyPhysicalSetReturnsControlledMissingRejection(): void
    {
        // Arrange:
        $matcher = new DocumentAttachmentMatcher();
        $configured = [['doc_id' => 9, 'document_name' => 'CERTIFICADO PACIENTE']];
        $catalog = [9 => ['NitMedDocCodAlt' => 'CER']];

        // Act:
        $result = $matcher->matchAll($configured, $catalog, []);

        // Assert:
        $this->assertSame([], $result->matches);
        $this->assertSame(
            DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_MISSING,
            $result->rejections[0]['reason']
        );
    }

    public function testMatchedMetadataWithoutPhysicalContentIsRejected(): void
    {
        // Arrange:
        $matcher = new DocumentAttachmentMatcher();
        $configured = [['doc_id' => 1, 'document_name' => 'DISPENSA']];
        $catalog = [1 => ['NitMedDocCodAlt' => 'ANE']];
        $physical = [$this->attachment('1', '1', 'DISPENSA', 'ANE', 'SIN_DOCUMENTOS')];

        // Act:
        $result = $matcher->matchAll($configured, $catalog, $physical);

        // Assert:
        $this->assertSame([], $result->matches);
        $this->assertSame(
            DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_NO_CONTENT,
            $result->rejections[0]['reason']
        );
        $this->assertSame(['1'], $result->rejections[0]['candidate_attachment_ids']);
    }

    public function testBlankPhysicalNameCanBeCorroboratedByCatalogId(): void
    {
        // Arrange:
        $matcher = new DocumentAttachmentMatcher();
        $configured = [['doc_id' => 3, 'document_name' => 'FORMULA MEDICA']];
        $catalog = [3 => ['NitMedDocCodAlt' => 'OPF']];
        $physical = [$this->attachment('80', '3', '', null)];

        // Act:
        $result = $matcher->matchAll($configured, $catalog, $physical);

        // Assert:
        $this->assertSame([], $result->rejections);
        $this->assertSame('80', $result->matches[0]['attachment_id']);
        $this->assertSame('validated_id', $result->matches[0]['strategy']);
    }

    public function testUniqueAliasResolvesWhenNameAndIdDoNotMatch(): void
    {
        // Arrange:
        $matcher = new DocumentAttachmentMatcher();
        $configured = [['doc_id' => 3, 'document_name' => 'FORMULA MEDICA']];
        $catalog = [3 => ['NitMedDocCodAlt' => 'OPF']];
        $physical = [$this->attachment('81', null, 'SOPORTE DIGITAL', 'OPF')];

        // Act:
        $result = $matcher->matchAll($configured, $catalog, $physical);

        // Assert:
        $this->assertSame([], $result->rejections);
        $this->assertSame('81', $result->matches[0]['attachment_id']);
        $this->assertSame('unique_alias', $result->matches[0]['strategy']);
    }

    public function testTwoExactPhysicalCandidatesAreAmbiguous(): void
    {
        // Arrange:
        $matcher = new DocumentAttachmentMatcher();
        $configured = [['doc_id' => 1, 'document_name' => 'DISPENSA']];
        $catalog = [1 => ['NitMedDocCodAlt' => 'ANE']];
        $physical = [
            $this->attachment('10', null, 'DISPENSA', null),
            $this->attachment('11', null, 'DISPENSA', null),
        ];

        // Act:
        $result = $matcher->matchAll($configured, $catalog, $physical);

        // Assert:
        $this->assertSame([], $result->matches);
        $this->assertSame(
            DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_AMBIGUOUS,
            $result->rejections[0]['reason']
        );
        $this->assertSame(['10', '11'], $result->rejections[0]['candidate_attachment_ids']);
    }

    public function testAlreadyConsumedPhysicalCandidateIsRejectedAsReused(): void
    {
        // Arrange:
        $matcher = new DocumentAttachmentMatcher();
        $configured = [
            ['doc_id' => 1, 'document_name' => 'DISPENSA'],
            ['doc_id' => 2, 'document_name' => 'SOPORTE DISPENSA'],
        ];
        $catalog = [
            1 => ['NitMedDocCodAlt' => 'ANE'],
            2 => ['NitMedDocCodAlt' => 'ANE'],
        ];
        $physical = [$this->attachment('12', '1', 'DISPENSA', 'ANE')];

        // Act:
        $result = $matcher->matchAll($configured, $catalog, $physical);

        // Assert:
        $this->assertSame(['12'], array_column($result->matches, 'attachment_id'));
        $this->assertSame(
            DocumentMappingRejectionReason::DOCUMENT_ATTACHMENT_REUSED,
            $result->rejections[0]['reason']
        );
        $this->assertSame(['12'], $result->rejections[0]['candidate_attachment_ids']);
    }

    public function testResultRejectsDuplicatePhysicalIdsAcrossMatches(): void
    {
        // Arrange:
        $matches = [
            ['logical_doc_id' => '1', 'attachment_id' => '90'],
            ['logical_doc_id' => '2', 'attachment_id' => '90'],
        ];

        // Act:
        $createResult = static fn(): DocumentAttachmentMatchResult => new DocumentAttachmentMatchResult($matches, []);

        // Assert:
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attachment_id repetido: 90');
        $createResult();
    }

    /** @return array<string,mixed> */
    private function attachment(
        string $attachmentId,
        ?string $catalogId,
        string $name,
        ?string $alias,
        string $storageType = 'BLOB'
    ): array {
        return [
            'attachment_id' => $attachmentId,
            'physical_catalog_id' => $catalogId,
            'physical_document_name' => $name,
            'physical_catalog_alias' => $alias,
            'physical_stored_alias' => $alias,
            'storage_type' => $storageType,
        ];
    }
}
