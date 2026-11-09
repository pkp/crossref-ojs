<?php

/**
 * @file plugins/generic/crossref/tests/CrossrefCitationDoiHandlerTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossrefCitationDoiHandlerTest
 *
 * @brief Tests for the reference DOI lookup when the Crossref request fails
 */

namespace APP\plugins\generic\crossref\tests;

use APP\plugins\generic\crossref\CrossrefCitationDoiHandler;
use APP\plugins\generic\crossref\CrossrefPlugin;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\core\Registry;
use PKP\tests\PKPTestCase;

#[CoversClass(CrossrefCitationDoiHandler::class)]
class CrossrefCitationDoiHandlerTest extends PKPTestCase
{
    /**
     * @copydoc PKPTestCase::getMockedRegistryKeys()
     */
    protected function getMockedRegistryKeys(): array
    {
        return [...parent::getMockedRegistryKeys(), PKPTestCase::MOCKED_GUZZLE_CLIENT_NAME];
    }

    /**
     * Look up the resolved references with the HTTP client answering with the given response or exception
     */
    protected function getResolvedRefs(Response|\Throwable $result): ?array
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([$result]))]);
        Registry::set(PKPTestCase::MOCKED_GUZZLE_CLIENT_NAME, $client);

        $plugin = Mockery::mock(CrossrefPlugin::class);
        $plugin->shouldReceive('getSetting')->andReturn('');
        $handler = new CrossrefCitationDoiHandler($plugin);

        $method = new \ReflectionMethod($handler, 'getResolvedRefs');
        return $method->invoke($handler, '10.1234/article.1', 1);
    }

    /**
     * A lookup that cannot connect returns null, so the submission is checked again on the next run
     */
    public function testConnectionFailureReturnsNull(): void
    {
        $message = 'cURL error 7: Failed to connect to doi.crossref.org port 443';
        $errorLog = ini_set('error_log', '/dev/null');
        try {
            $this->assertNull($this->getResolvedRefs(new ConnectException($message, new Request('POST', 'https://doi.crossref.org/getResolvedRefs'))));
        } finally {
            ini_set('error_log', $errorLog);
        }
    }

    /**
     * An error response returns null, so the submission is checked again on the next run
     */
    public function testErrorResponseReturnsNull(): void
    {
        $errorLog = ini_set('error_log', '/dev/null');
        try {
            $this->assertNull($this->getResolvedRefs(new Response(500, [], 'Internal error')));
        } finally {
            ini_set('error_log', $errorLog);
        }
    }

    /**
     * A successful lookup returns the matched references
     */
    public function testSuccessfulLookupReturnsMatchedReferences(): void
    {
        $references = [['key' => '1', 'doi' => '10.5678/cited.1']];
        $body = json_encode(['matched-references' => $references]);

        $this->assertSame($references, $this->getResolvedRefs(new Response(200, [], $body)));
    }
}
