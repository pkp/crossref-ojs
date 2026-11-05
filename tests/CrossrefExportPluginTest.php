<?php

/**
 * @file plugins/generic/crossref/tests/CrossrefExportPluginTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossrefExportPluginTest
 *
 * @brief Tests for the deposit status the Crossref plugin records when a deposit fails
 */

namespace APP\plugins\generic\crossref\tests;

use APP\journal\Journal;
use APP\plugins\generic\crossref\CrossrefExportPlugin;
use APP\submission\Submission;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\core\Registry;
use PKP\doi\Doi;
use PKP\tests\PKPTestCase;

#[CoversClass(CrossrefExportPlugin::class)]
class CrossrefExportPluginTest extends PKPTestCase
{
    protected string $filename;

    /**
     * @copydoc PKPTestCase::getMockedRegistryKeys()
     */
    protected function getMockedRegistryKeys(): array
    {
        return [...parent::getMockedRegistryKeys(), PKPTestCase::MOCKED_GUZZLE_CLIENT_NAME];
    }

    /**
     * @copydoc PKPTestCase::setUp()
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->filename = tempnam(sys_get_temp_dir(), 'crossref');
    }

    /**
     * @copydoc PKPTestCase::tearDown()
     */
    protected function tearDown(): void
    {
        @unlink($this->filename);
        parent::tearDown();
    }

    /**
     * Make the HTTP client answer the deposit request with the given response or exception
     */
    protected function mockDepositResult(Response|\Throwable $result): void
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([$result]))]);
        Registry::set(PKPTestCase::MOCKED_GUZZLE_CLIENT_NAME, $client);
    }

    /**
     * Run a deposit that fails with the given response or exception
     *
     * @param string|null $expectedMessage Message expected to be stored with the error, or null to match any
     *
     * @return array|bool The result of depositXML()
     */
    protected function deposit(Response|\Throwable $result, ?string $expectedMessage = null): array|bool
    {
        $this->mockDepositResult($result);

        $context = Mockery::mock(Journal::class);
        $context->shouldReceive('getId')->andReturn(1);
        $objects = new Submission();

        $plugin = Mockery::mock(CrossrefExportPlugin::class)->makePartial();
        $plugin->shouldReceive('getSetting')->andReturn('');
        $plugin->shouldReceive('isTestMode')->andReturn(false);
        $plugin->shouldReceive('updateDepositStatus')
            ->once()
            ->with($context, $objects, Doi::STATUS_ERROR, Mockery::any(), $expectedMessage ?? Mockery::type('string'));

        return $plugin->depositXML($objects, $context, $this->filename);
    }

    /**
     * A deposit that cannot connect is recorded as an error, not left as submitted
     */
    public function testConnectionFailureRecordsError(): void
    {
        $message = 'cURL error 7: Failed to connect to api.crossref.org port 443';
        $result = $this->deposit(new ConnectException($message, new Request('POST', 'https://api.crossref.org/v2/deposits')), $message);

        $this->assertSame([['plugins.importexport.common.register.error.mdsError', $message]], $result);
    }

    /**
     * A transfer error without a response is recorded as an error
     */
    public function testRequestExceptionWithoutResponseRecordsError(): void
    {
        $message = 'cURL error 56: Recv failure';
        $result = $this->deposit(new RequestException($message, new Request('POST', 'https://api.crossref.org/v2/deposits')), $message);

        $this->assertSame([['plugins.importexport.common.register.error.mdsError', $message]], $result);
    }

    /**
     * A response that Crossref sent is still recorded as an error with the response in the message
     */
    public function testErrorResponseRecordsError(): void
    {
        $result = $this->deposit(new Response(500, [], 'Internal error'), 'Internal error (500 Internal Server Error)');

        $this->assertSame([['plugins.importexport.common.register.error.mdsError', 'Internal error (500 Internal Server Error)']], $result);
    }

    /**
     * A deposit that Crossref accepts is still recorded as registered
     */
    public function testAcceptedDepositRecordsRegistered(): void
    {
        $body = '<doi_batch_diagnostic><submission_id>1</submission_id><batch_id>batch-1</batch_id>'
            . '<failure_count>0</failure_count><warning_count>0</warning_count></doi_batch_diagnostic>';
        $this->mockDepositResult(new Response(200, [], $body));

        $context = Mockery::mock(Journal::class);
        $context->shouldReceive('getId')->andReturn(1);
        $objects = new Submission();

        $plugin = Mockery::mock(CrossrefExportPlugin::class)->makePartial();
        $plugin->shouldReceive('getSetting')->andReturn('');
        $plugin->shouldReceive('isTestMode')->andReturn(false);
        $plugin->shouldReceive('updateDepositStatus')
            ->once()
            ->with($context, $objects, Doi::STATUS_REGISTERED, 'batch-1', null, Mockery::type('string'));

        $this->assertTrue($plugin->depositXML($objects, $context, $this->filename));
    }
}
