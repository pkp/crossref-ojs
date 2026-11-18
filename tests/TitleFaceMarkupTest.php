<?php

/**
 * @file plugins/generic/crossref/tests/TitleFaceMarkupTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class TitleFaceMarkupTest
 *
 * @brief Tests that appendFaceMarkup() writes stored HTML titles as Crossref face markup,
 *   escaping each character once.
 */

namespace APP\plugins\generic\crossref\tests;

use APP\plugins\generic\crossref\CrossrefExportDeployment;
use APP\plugins\generic\crossref\filter\IssueCrossrefXmlFilter;
use DOMDocument;
use Mockery;
use PKP\tests\PKPTestCase;

/**
 * @covers \APP\plugins\generic\crossref\filter\IssueCrossrefXmlFilter
 */
class TitleFaceMarkupTest extends PKPTestCase
{
    private IssueCrossrefXmlFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $deployment = Mockery::mock(CrossrefExportDeployment::class);
        $deployment->shouldReceive('getNamespace')->andReturn(CrossrefExportDeployment::CROSSREF_XMLNS);

        $this->filter = $this->getMockBuilder(IssueCrossrefXmlFilter::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $this->filter->setDeployment($deployment);
    }

    /**
     * Returns the inner XML of a <title> element built from the given stored HTML.
     */
    private function createTitleContent(string $html): string
    {
        $doc = new DOMDocument();
        $node = $doc->createElementNS(CrossrefExportDeployment::CROSSREF_XMLNS, 'title');
        $this->filter->appendFaceMarkup($doc, $node, $html);
        $xml = $doc->saveXML($node);
        return preg_replace('/^<title[^>]*>|<\/title>$/', '', $xml);
    }

    public static function titleProvider(): array
    {
        return [
            'ampersand saved by the title box' => ['rocks &amp; sand', 'rocks &amp; sand'],
            'bare ampersand of an older title' => ['Hansen & Pinto: Reason Reclaimed', 'Hansen &amp; Pinto: Reason Reclaimed'],
            'italic word' => ['trees &amp; <i>shrubs</i>', 'trees &amp; <i>shrubs</i>'],
            'typed angle brackets stay text' => ['a &lt;b&gt; c', 'a &lt;b&gt; c'],
            'strong and em become b and i' => ['<strong>x</strong><em>y</em>', '<b>x</b><i>y</i>'],
            'other tags are dropped and the text kept' => ['<span>a</span> <a href="https://example.org">b</a>', 'a b'],
            'prefix with an apostrophe' => ['L&#039; title', 'L\' title'],
            'empty' => ['', ''],
        ];
    }

    /**
     * @dataProvider titleProvider
     */
    public function testAppendFaceMarkup(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->createTitleContent($html));
    }
}
