<?php

/**
 * @file plugins/generic/crossref/CrossrefCitationDoiHandler.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossrefCitationDoiHandler
 *
 * @brief Handles citation DOI matching via the Crossref API: tracking deposit responses,
 * fetching matched citation DOIs, and displaying them on the article detail page.
 */

namespace APP\plugins\generic\crossref;

use APP\core\Application;
use APP\facades\Repo;
use APP\publication\Publication;
use APP\submission\Submission;
use DOMDocument;
use PKP\citation\Citation;
use PKP\citation\pid\Doi;
use PKP\context\Context;
use PKP\plugins\Hook;

class CrossrefCitationDoiHandler
{
    public function __construct(
        protected CrossrefPlugin $plugin
    ) {
    }

    /**
     * Get setting name, that defines if the scheduled task for the automatic check
     * of the found Crossref citations DOIs should be run, if set up so in the plugin settings.
     */
    public function getAutoCheckSettingName(): string
    {
        return 'crossref::checkCitationsDOIs';
    }

    /**
     * Get citations diagnostic ID setting name.
     */
    public function getCitationsDiagnosticIdSettingName(): string
    {
        return 'crossref::citationsDiagnosticId';
    }

    /**
     * Register hooks for citation DOI handling.
     */
    public function registerHooks(): void
    {
        Hook::add('Schema::get::submission', $this->addSubmissionSchema(...));
        Hook::add('Citation::importCitations::after', $this->citationsChanged(...));
    }

    /**
     * Register hooks that require the plugin to be enabled.
     */
    public function registerEnabledHooks(): void
    {
        Hook::add('Templates::Article::Details::Reference', $this->displayReferenceDOI(...));
    }

    /**
     * Add properties to the submission schema for citation diagnostic tracking.
     *
     * @param string $hookName `Schema::get::submission`
     */
    public function addSubmissionSchema(string $hookName, array $args): bool
    {
        $schema = $args[0];

        $schema->properties->{$this->getCitationsDiagnosticIdSettingName()} = (object) [
            'type' => 'string',
            'apiSummary' => true,
            'validation' => ['nullable']
        ];

        $schema->properties->{$this->getAutoCheckSettingName()} = (object) [
            'type' => 'boolean',
            'apiSummary' => true,
            'validation' => ['nullable']
        ];

        return Hook::CONTINUE;
    }

    /**
     * Resets the submission's citations diagnostic ID and automatic check settings every time
     * all citations are changed, so that the submission will not be checked for found Crossref
     * references DOIs in the next scheduled task run.
     *
     * @param string $hookName Hook name 'Citation::importCitations::after'
     */
    public function citationsChanged(string $hookName, int $publicationId, array $existingCitations, array $importedCitations): bool
    {
        if (!$this->plugin->getEnabled() ||
            !$this->plugin->hasCrossrefCredentials() ||
            !$this->plugin->citationsEnabled()) {

                return Hook::CONTINUE;
        }

        $publication = Repo::publication()->get($publicationId);
        $submission = Repo::submission()->get($publication->getData('submissionId'));

        if ($submission->getData($this->getCitationsDiagnosticIdSettingName())) {
            $submission->setData($this->getCitationsDiagnosticIdSettingName(), null);
            $submission->setData($this->getAutoCheckSettingName(), null);
            Repo::submission()->edit($submission, []);
        }
        return Hook::CONTINUE;
    }

    /**
     * Append DOI link to a citation on the article detail page, if the citation text does not contain it.
     *
     * @param string $hookName Hook name 'Templates::Article::Details::Reference'
     * @param array $params [
     *  @option Citation
     *  @option Smarty
     *  @option string Rendered smarty template
     * ]
     */
    public function displayReferenceDOI(string $hookName, array $params): bool
    {
        /** @var Citation $citation */
        $citation = $params[0]['citation'];
        /** @var \Smarty $smarty */
        $smarty = &$params[1];
        /** @var string $output */
        $output = &$params[2];

        if ($citation->getData('doi')) {
            // Normalise to https so that Doi::extractFromString detects DOI links regardless of how they were stored.
            $rawString = str_ireplace('http://', 'https://', $citation->getRawCitation());
            $doi = Doi::extractFromString($rawString);
            // Display DOI only if the raw citation string doesn't already contain the DOI as a link, to avoid duplicate DOIs on the page
            if (empty($doi)) {
                $crossrefFullUrl = 'https://doi.org/' . $citation->getData('doi');
                $smarty->assign('crossrefFullUrl', $crossrefFullUrl);
                $output .= $smarty->fetch($this->plugin->getTemplateResource('displayDOI.tpl'));
            }
        }
        return Hook::CONTINUE;
    }

    /**
     * Store the citations diagnostic ID from a Crossref deposit response,
     * so the scheduled task can later check for found reference DOIs.
     */
    public function handleDepositResponse(string $responseBody, Submission $submission): void
    {
        $xmlDoc = new DOMDocument();
        $xmlDoc->loadXML($responseBody);
        if ($xmlDoc->getElementsByTagName('citations_diagnostic')->length > 0) {
            $citationsDiagnosticNode = $xmlDoc->getElementsByTagName('citations_diagnostic')->item(0);
            $citationsDiagnosticCode = $citationsDiagnosticNode->getAttribute('deferred');
            $submission->setData($this->getCitationsDiagnosticIdSettingName(), $citationsDiagnosticCode);
            $submission->setData($this->getAutoCheckSettingName(), true);
            Repo::submission()->edit($submission, []);
        }
    }

    /**
     * Fetch resolved citation DOIs from Crossref and store them for all pending submissions.
     */
    public function processPendingCitationDois(Context $context): void
    {
        // Retrieve all submissions flagged for auto-check, i.e. whose DOIs were deposited
        // with references and are awaiting resolved citation DOIs from Crossref.
        $submissionIds = Repo::submission()->getIdsBySetting($this->getAutoCheckSettingName(), true, $context->getId())->toArray();
        $submissions = Repo::submission()->getCollector()->filterByContextIds([$context->getId()])->filterBySubmissionIds($submissionIds)->getMany();
        foreach ($submissions as $submission) {
            $publicationsToCheck = $this->getPublicationsToCheck($submission, $context); // always contain DOI and are published
            $apiCallFailed = false;
            foreach ($publicationsToCheck as $publication) { /** @var Publication $publication */
                $citations = $publication->getData('citations') ?? [];

                $citationsToCheck = [];
                foreach ($citations as $citation) { /** @var Citation $citation */
                    if (!$citation->getData('doi')) {
                        $citationsToCheck[$citation->getId()] = $citation;
                    }
                }
                if (empty($citationsToCheck)) {
                    continue;
                }

                $matchedReferences = $this->getResolvedRefs($publication->getDoi(), $context->getId());
                if ($matchedReferences === null) {
                    $apiCallFailed = true;
                    continue;
                }
                foreach ($matchedReferences as $matchedReference) {
                    $key = $matchedReference['key'] ?? null;
                    if ($key === null || !isset($citationsToCheck[$key])) {
                        continue;
                    }
                    $citation = $citationsToCheck[$key];
                    $citation->setData('doi', $matchedReference['doi']);
                    Repo::citation()->edit($citation, []);
                }
            }
            // Only remove the auto check setting if all API calls succeeded,
            // so the submission will be retried in the next scheduled task run on failure.
            if (!$apiCallFailed) {
                $submission->setData($this->getAutoCheckSettingName(), null);
                Repo::submission()->edit($submission, []);
            }
        }
    }

    /**
     * Retrieve the submission's publications eligible for citation DOI matching.
     *
     * @return Publication[]
     */
    protected function getPublicationsToCheck(Submission $submission, Context $context): array
    {
        $publicationsToCheck = [];
        if (!$context->getData(Context::SETTING_DOI_VERSIONING)) {
            $publication = $submission->getCurrentPublication();
            if ($publication->getDoi() && $publication->getData('status') == Publication::STATUS_PUBLISHED) {
                $publicationsToCheck[] = $publication;
            }
        } else {
            $latestMinorPublications = Repo::doi()->getLatestMinorPublicationsForDoiDeposit($submission->getData('publications'));
            foreach ($latestMinorPublications as $publicationsByMajor) {
                foreach ($publicationsByMajor as $publication) {
                    $publicationsToCheck[] = $publication;
                }
            }
        }
        return $publicationsToCheck;
    }

    /**
     * Query the Crossref API for matched citation DOIs for the given article DOI.
     */
    protected function getResolvedRefs(string $doi, int $contextId): ?array
    {
        $matchedReferences = null;

        $username = $this->plugin->getSetting($contextId, 'username');
        $password = $this->plugin->getSetting($contextId, 'password');

        // Use a different endpoint for testing and production.
        $isTestMode = $this->plugin->getSetting($contextId, 'testMode') == 1;
        $endpoint = ($isTestMode ? CrossrefPlugin::CROSSREF_API_REFS_URL_DEV : CrossrefPlugin::CROSSREF_API_REFS_URL);

        $url = $endpoint . '?doi=' . urlencode($doi) . '&usr=' . urlencode($username) . '&pwd=' . urlencode($password);

        $httpClient = Application::get()->getHttpClient();
        try {
            $response = $httpClient->request('POST', $url);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            error_log('Crossref getResolvedRefs failed for DOI ' . $doi . ': ' . $e->getMessage());
            return null;
        }

        if ($response?->getStatusCode() == 200) {
            $response = json_decode($response->getBody(), true);
            $matchedReferences = $response['matched-references'] ?? [];
        }

        return $matchedReferences;
    }
}
