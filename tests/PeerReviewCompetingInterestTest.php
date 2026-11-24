<?php

/**
 * @file plugins/generic/crossref/tests/PeerReviewCompetingInterestTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class PeerReviewCompetingInterestTest
 *
 * @brief Tests that the peer review deposit contains the competing interests statement only for open reviews.
 */

namespace APP\plugins\generic\crossref\tests;

use APP\plugins\generic\crossref\CrossrefExportDeployment;
use APP\plugins\generic\crossref\filter\PeerReviewCrossrefXmlFilter;
use DOMDocument;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\tests\PKPTestCase;

#[CoversClass(PeerReviewCrossrefXmlFilter::class)]
class PeerReviewCompetingInterestTest extends PKPTestCase
{
    private PeerReviewCrossrefXmlFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $deployment = Mockery::mock(CrossrefExportDeployment::class);
        $deployment->shouldReceive('getNamespace')->andReturn(CrossrefExportDeployment::CROSSREF_XMLNS);

        $this->filter = $this->getMockBuilder(PeerReviewCrossrefXmlFilter::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $this->filter->setDeployment($deployment);
    }

    public static function competingInterestProvider(): array
    {
        return [
            'open review' => [ReviewAssignment::SUBMISSION_REVIEW_METHOD_OPEN, 'Former colleague of the first author', 'Former colleague of the first author'],
            'anonymous review' => [ReviewAssignment::SUBMISSION_REVIEW_METHOD_ANONYMOUS, 'Former colleague of the first author', null],
            'double-anonymous review' => [ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS, 'Former colleague of the first author', null],
            'open review without statement' => [ReviewAssignment::SUBMISSION_REVIEW_METHOD_OPEN, null, null],
        ];
    }

    #[DataProvider('competingInterestProvider')]
    public function testCompetingInterestStatementOnlyForOpenReviews(int $reviewMethod, ?string $competingInterests, ?string $expected): void
    {
        $reviewAssignment = new ReviewAssignment();
        $reviewAssignment->setReviewMethod($reviewMethod);
        $reviewAssignment->setCompetingInterests($competingInterests);

        $node = $this->filter->createCompetingInterestNode(new DOMDocument(), $reviewAssignment);

        $this->assertSame($expected, $node?->textContent);
        if ($node) {
            $this->assertSame('competing_interest_statement', $node->localName);
        }
    }
}
