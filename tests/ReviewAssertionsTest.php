<?php

/**
 * @file plugins/generic/crossref/tests/ReviewAssertionsTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class ReviewAssertionsTest
 *
 * @brief Tests which reviews appendReviewAssertions() lists in the Crossmark of a version and in which order,
 *   and the value getReviewAssertionValue() writes for a review.
 */

namespace APP\plugins\generic\crossref\tests;

use APP\journal\Journal;
use APP\plugins\generic\crossref\CrossrefExportDeployment;
use APP\plugins\generic\crossref\filter\ArticleCrossrefXmlFilter;
use APP\publication\DAO;
use APP\publication\Publication;
use APP\submission\Submission;
use DOMDocument;
use DOMElement;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PKP\context\Context;
use PKP\services\PKPSchemaService;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submission\reviewer\recommendation\enums\ReviewerRecommendationType;
use PKP\tests\PKPTestCase;
use PKP\user\User;

#[CoversClass(ArticleCrossrefXmlFilter::class)]
class ReviewAssertionsTest extends PKPTestCase
{
    private ArticleCrossrefXmlFilter $filter;
    private Journal $journal;
    private Submission $submission;
    private DOMDocument $doc;

    /** @var array<string, Publication> */
    private array $publications = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockRequest();

        $this->journal = new Journal();
        $this->journal->setPath('test');
        $deployment = Mockery::mock(CrossrefExportDeployment::class);
        $deployment->shouldReceive('getContext')->andReturn($this->journal);
        $deployment->shouldReceive('getNamespace')->andReturn(CrossrefExportDeployment::CROSSREF_XMLNS);

        $this->filter = $this->getMockBuilder(ArticleCrossrefXmlFilter::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $this->filter->setDeployment($deployment);

        // The design example: PMUR 1, PMUR 2, VoR 1, VoR 2, in version order
        $publicationDao = new DAO(new PKPSchemaService());
        foreach (['PMUR1' => 1, 'PMUR2' => 2, 'VoR1' => 3, 'VoR2' => 4] as $key => $id) {
            $publication = $publicationDao->newDataObject();
            $publication->setId($id);
            $this->publications[$key] = $publication;
        }
        $this->submission = new Submission();
        $this->submission->setId(123);
        $this->submission->setData('publications', collect(array_values($this->publications)));
    }

    /**
     * Set the reviews of the design example
     */
    private function setExampleReviews(): void
    {
        $review = fn (int $id, string $reviewer, string $version, string $date) => [
            'reviewerId' => ord($reviewer),
            'publicationId' => $this->publications[$version]->getId(),
            'dateCompleted' => "{$date} 10:00:00",
            'assertionName' => "review-report-{$id}",
            'doiUrl' => "https://doi.org/10.1234/review.{$id}",
            'value' => "{$version}, Reviewer {$reviewer}, {$date}, https://doi.org/10.1234/review.{$id}",
        ];
        $reviews = [
            $review(486, 'A', 'PMUR1', '2026-01-10'),
            $review(487, 'B', 'PMUR1', '2026-01-11'),
            $review(488, 'C', 'PMUR1', '2026-01-12'),
            $review(501, 'A', 'PMUR2', '2026-03-05'),
            $review(503, 'B', 'PMUR2', '2026-03-06'),
            $review(502, 'D', 'PMUR2', '2026-03-08'),
            $review(507, 'B', 'VoR1', '2026-04-20'),
            $review(510, 'D', 'VoR2', '2026-06-15'),
        ];
        $property = new \ReflectionProperty(ArticleCrossrefXmlFilter::class, 'crossmarkReviews');
        $property->setValue($this->filter, [$this->submission->getId() => $reviews]);
    }

    /**
     * @return DOMElement[]
     */
    private function getAssertions(Publication $publication): array
    {
        // A property, so that the returned elements outlive this method
        $this->doc = new DOMDocument();
        $customMetadataNode = $this->doc->appendChild($this->doc->createElementNS(CrossrefExportDeployment::CROSSREF_XMLNS, 'custom_metadata'));
        $this->filter->appendReviewAssertions($this->doc, $customMetadataNode, $publication, $this->submission);
        return iterator_to_array($customMetadataNode->childNodes);
    }

    public function testLatestReviewOfEachReviewerUpToTheVersion(): void
    {
        $this->setExampleReviews();

        $assertions = $this->getAssertions($this->publications['VoR1']);

        // B's PMUR 2 review is replaced by B's VoR 1 review; D's VoR 2 review comes later
        $this->assertSame(
            ['peer-review-record', 'review-report-507', 'review-report-502', 'review-report-501', 'review-report-488'],
            array_map(fn (DOMElement $assertion) => $assertion->getAttribute('name'), $assertions)
        );
        $this->assertSame(['0', '1', '2', '3', '4'], array_map(fn (DOMElement $assertion) => $assertion->getAttribute('order'), $assertions));
        foreach ($assertions as $assertion) {
            $this->assertSame('article-reports', $assertion->getAttribute('group_name'));
            $this->assertSame('Latest Review of Each Reviewer', $assertion->getAttribute('group_label'));
        }

        $record = $assertions[0];
        $this->assertSame('Peer Review Record', $record->getAttribute('label'));
        $this->assertStringContainsString('tab=peer-review-record', $record->getAttribute('href'));
        $this->assertSame($record->getAttribute('href'), $record->textContent);

        $report = $assertions[1];
        $this->assertSame('Review Report', $report->getAttribute('label'));
        $this->assertSame('https://doi.org/10.1234/review.507', $report->getAttribute('href'));
        $this->assertSame('VoR1, Reviewer B, 2026-04-20, https://doi.org/10.1234/review.507', $report->textContent);
    }

    public function testEarlierVersionListsOnlyItsOwnAndEarlierReviews(): void
    {
        $this->setExampleReviews();

        $names = array_map(fn (DOMElement $assertion) => $assertion->getAttribute('name'), $this->getAssertions($this->publications['PMUR2']));

        // C did not review PMUR 2, so C's PMUR 1 review is listed
        $this->assertSame(['peer-review-record', 'review-report-502', 'review-report-503', 'review-report-501', 'review-report-488'], $names);
    }

    public static function peerReviewRecordUrlProvider(): array
    {
        return [
            'DOI versioning links the deposited version' => [true, '/article/view/current-path/version/3?tab=peer-review-record'],
            'without DOI versioning the current version is deposited' => [false, '/article/view/current-path?tab=peer-review-record'],
        ];
    }

    /**
     * The current urlPath, because ArticleHandler redirects any other one and drops the tab
     */
    #[DataProvider('peerReviewRecordUrlProvider')]
    public function testPeerReviewRecordUrl(bool $doiVersioning, string $expectedPath): void
    {
        $this->setExampleReviews();
        $this->journal->setData(Context::SETTING_DOI_VERSIONING, $doiVersioning);
        $this->publications['VoR1']->setData('urlPath', 'old-path');
        $this->publications['VoR2']->setData('urlPath', 'current-path');
        $this->submission->setData('currentPublicationId', $this->publications['VoR2']->getId());

        $href = $this->getAssertions($this->publications['VoR1'])[0]->getAttribute('href');

        $this->assertStringEndsWith($expectedPath, $href);
    }

    public function testNoAssertionsWithoutReviews(): void
    {
        $this->assertSame([], $this->getAssertions($this->publications['VoR1']));
    }

    public static function reviewAssertionValueProvider(): array
    {
        $doiUrl = 'https://doi.org/10.1234/review.501';
        return [
            'open review shows the reviewer name' => [
                ReviewAssignment::SUBMISSION_REVIEW_METHOD_OPEN,
                ReviewerRecommendationType::APPROVED->value,
                "PMUR 2.0, Approved, Jane Doe, 2026-03-05, {$doiUrl}",
            ],
            'anonymous review hides the reviewer name' => [
                ReviewAssignment::SUBMISSION_REVIEW_METHOD_ANONYMOUS,
                ReviewerRecommendationType::REVISIONS_REQUESTED->value,
                "PMUR 2.0, Revisions Requested, 2026-03-05, {$doiUrl}",
            ],
            'double-anonymous review hides the reviewer name' => [
                ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS,
                ReviewerRecommendationType::NOT_APPROVED->value,
                "PMUR 2.0, Not Approved, 2026-03-05, {$doiUrl}",
            ],
            'review without recommendation' => [
                ReviewAssignment::SUBMISSION_REVIEW_METHOD_OPEN,
                null,
                "PMUR 2.0, Jane Doe, 2026-03-05, {$doiUrl}",
            ],
        ];
    }

    #[DataProvider('reviewAssertionValueProvider')]
    public function testReviewAssertionValue(int $reviewMethod, ?int $recommendationType, string $expected): void
    {
        $reviewAssignment = new ReviewAssignment();
        $reviewAssignment->setReviewMethod($reviewMethod);
        $reviewAssignment->setDateCompleted('2026-03-05 14:30:00');

        $publication = $this->publications['PMUR2'];
        $publication->setData('versionStage', 'PMUR');
        $publication->setData('versionMajor', 2);
        $publication->setData('versionMinor', 0);

        // The reviewer is passed for every method: the method alone decides whether the name is shown
        $reviewer = new User();
        $reviewer->setGivenName('Jane', 'en');
        $reviewer->setFamilyName('Doe', 'en');

        $this->assertSame(
            $expected,
            $this->filter->getReviewAssertionValue($reviewAssignment, $publication, $reviewer, $recommendationType, 'https://doi.org/10.1234/review.501', 'en')
        );
    }
}
