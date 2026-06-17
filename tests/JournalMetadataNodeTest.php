<?php

/**
 * @file plugins/generic/crossref/tests/JournalMetadataNodeTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class JournalMetadataNodeTest
 *
 * @brief Tests that createJournalMetadataNode() uses stamped identity values
 *   from the publication rather than the journal's current values after a rename or ISSN change.
 */

namespace APP\plugins\generic\crossref\tests;

use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\generic\crossref\CrossrefExportDeployment;
use APP\plugins\generic\crossref\filter\IssueCrossrefXmlFilter;
use APP\publication\DAO;
use APP\publication\Publication;
use DOMDocument;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\core\Registry;
use PKP\services\PKPSchemaService;
use PKP\site\Site;
use PKP\tests\PKPTestCase;

#[CoversClass(IssueCrossrefXmlFilter::class)]
class JournalMetadataNodeTest extends PKPTestCase
{
    private Publication $publication;
    private IssueCrossrefXmlFilter $filter;

    protected function getMockedRegistryKeys(): array
    {
        // Keep the parent's keys (e.g. 'request' from mockRequest()), or they leak into the next test.
        return [...parent::getMockedRegistryKeys(), 'site'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // getLocalePrecedence() accesses the request and site — set up minimal mocks.
        $mockSite = Mockery::mock(Site::class);
        $mockSite->shouldReceive('getPrimaryLocale')->andReturn('en');
        Registry::set('site', $mockSite);
        $this->mockRequest();

        $this->publication = (new DAO(new PKPSchemaService()))->newDataObject();

        $this->filter = $this->getMockBuilder(IssueCrossrefXmlFilter::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    private function setupDeployment(Journal $journal): void
    {
        $deployment = Mockery::mock(CrossrefExportDeployment::class);
        $deployment->shouldReceive('getContext')->andReturn($journal);
        $deployment->shouldReceive('getNamespace')->andReturn(CrossrefExportDeployment::CROSSREF_XMLNS);
        $this->filter->setDeployment($deployment);
    }

    private function createJournal(): Journal
    {
        $journal = new Journal();
        $journal->setName('Old Journal Name', 'en');
        $journal->setPrimaryLocale('en');
        $journal->setData('onlineIssn', 'old-online-issn');
        $journal->setData('printIssn', 'old-print-issn');
        $journal->setData('publisherInstitution', 'Old Publisher');
        $journal->setData('abbreviation', 'J.Old', 'en');
        return $journal;
    }

    private function createXml(Issue|Publication $pubObject): string
    {
        $xml = $this->filter->createJournalMetadataNode(new DOMDocument(), $pubObject);
        return $xml->ownerDocument->saveXML($xml);
    }

    public function testJournalMetadataUsesStampedIdentityAfterJournalChange(): void
    {
        $journal = $this->createJournal();

        $this->publication->stampContextIdentity($journal);

        // Journal is later renamed and its ISSNs changed — the stamp must remain authoritative.
        $journal->setName('New Journal Name', 'en');
        $journal->setData('abbreviation', 'J.New', 'en');
        $journal->setData('onlineIssn', 'new-online-issn');
        $journal->setData('printIssn', 'new-print-issn');

        $this->setupDeployment($journal);
        $xmlString = $this->createXml($this->publication);

        $this->assertStringContainsString('<full_title>Old Journal Name</full_title>', $xmlString);
        $this->assertStringContainsString('<abbrev_title>J.Old</abbrev_title>', $xmlString);
        $this->assertStringContainsString('old-online-issn', $xmlString);
        $this->assertStringContainsString('old-print-issn', $xmlString);
        $this->assertStringNotContainsString('New Journal Name', $xmlString);
        $this->assertStringNotContainsString('J.New', $xmlString);
        $this->assertStringNotContainsString('new-online-issn', $xmlString);
        $this->assertStringNotContainsString('new-print-issn', $xmlString);
    }

    public function testJournalMetadataUsesStampedIssueIdentity(): void
    {
        $issue = new Issue();
        $issue->setData('contextName', ['en' => 'Old Journal Name']);
        $issue->setData('contextAbbreviation', ['en' => 'J.Old']);
        $issue->setData('contextPrimaryLocale', 'en');
        $issue->setData('onlineIssn', 'old-online-issn');
        $issue->setData('printIssn', 'old-print-issn');

        $journal = $this->createJournal();
        $journal->setName('New Journal Name', 'en');
        $journal->setData('abbreviation', 'J.New', 'en');
        $journal->setData('onlineIssn', 'new-online-issn');
        $journal->setData('printIssn', 'new-print-issn');

        $this->setupDeployment($journal);
        $xmlString = $this->createXml($issue);

        $this->assertStringContainsString('<full_title>Old Journal Name</full_title>', $xmlString);
        $this->assertStringContainsString('<abbrev_title>J.Old</abbrev_title>', $xmlString);
        $this->assertStringContainsString('old-online-issn', $xmlString);
        $this->assertStringContainsString('old-print-issn', $xmlString);
        $this->assertStringNotContainsString('New Journal Name', $xmlString);
        $this->assertStringNotContainsString('new-online-issn', $xmlString);
    }

    /**
     * Content not stamped yet (e.g. not migrated) is exported with the journal's current identity.
     */
    public function testJournalMetadataFallsBackToLiveJournalWhenNotStamped(): void
    {
        $this->setupDeployment($this->createJournal());
        $xmlString = $this->createXml($this->publication);

        $this->assertStringContainsString('<full_title>Old Journal Name</full_title>', $xmlString);
        $this->assertStringContainsString('<abbrev_title>J.Old</abbrev_title>', $xmlString);
        $this->assertStringContainsString('old-online-issn', $xmlString);
        $this->assertStringContainsString('old-print-issn', $xmlString);
    }

    public function testJournalMetadataFallsBackToAcronymAndOmitsMissingValues(): void
    {
        $journal = $this->createJournal();
        $journal->setData('abbreviation', null);
        $journal->setData('acronym', 'JON', 'en');
        $journal->setData('printIssn', null);

        $this->setupDeployment($journal);
        $xmlString = $this->createXml($this->publication);

        $this->assertStringContainsString('<abbrev_title>JON</abbrev_title>', $xmlString);
        $this->assertStringContainsString('media_type="electronic"', $xmlString);
        $this->assertStringNotContainsString('media_type="print"', $xmlString);

        $journal->setData('acronym', null);
        $this->assertStringNotContainsString('abbrev_title', $this->createXml($this->publication));
    }
}
