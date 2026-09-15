<?php


/**
 * @file plugins/generic/crossref/CrossrefCitedByController.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CrossrefCitedByController
 *
 * @brief Handle API request for Crossref's Cited-by
 */

namespace APP\plugins\generic\crossref;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\IDoiRegistrationAgency;
use APP\publication\Publication;
use APP\submission\Submission;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use PKP\context\Context;
use PKP\core\PKPBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use PKP\security\authorization\PublicAccessPolicy;
use PKP\core\PKPRequest;
use SimpleXMLElement;

class CrossrefCitedByController extends PKPBaseController
{
    private const CROSSREF_API_URL = 'https://doi.crossref.org/servlet/getForwardLinks?usr=%s&pwd=%s&doi=%s';

    /**
     * @copydoc \PKP\core\PKPBaseController::getHandlerPath()
     */
    public function getHandlerPath(): string
    {
        return 'crossref/citedBy';
    }

    /**
     * @copydoc \PKP\core\PKPBaseController::getRouteGroupMiddleware()
     */
    public function getRouteGroupMiddleware(): array
    {
        return [
            'has.context',
        ];
    }

    /** @copydoc \PKP\core\PKPBaseController::authorize() */
    public function authorize(PKPRequest $request, array &$args, array $roleAssignments): bool
    {
        $this->addPolicy(new PublicAccessPolicy());
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * @copydoc \PKP\core\PKPBaseController::getGroupRoutes()
     */
    public function getGroupRoutes(): void
    {
        Route::get('{submissionId}', $this->getCitations(...))
            ->name('citations.get')
            ->whereNumber('submissionId');
    }

    /**
     * Get all crossref citations for article with the given ID.
     */
    public function getCitations(Request $illuminateRequest): JsonResponse
    {
        $submissionId = $illuminateRequest->route('submissionId');
        $context = $this->getRequest()->getContext();

        /** @var Submission $submission */
        $submission = Repo::submission()->get($submissionId, $context->getId());

        if (!$submission) {
            return response()->json([
                'error' => __('api.404.resourceNotFound')
            ], Response::HTTP_NOT_FOUND);
        }

        $enabledRegistrationAgency = $context->getConfiguredDoiAgency();

        if (
            !$enabledRegistrationAgency instanceof CrossrefPlugin ||
            !$enabledRegistrationAgency->getSetting($context->getId(), 'citedBy') ||
            !$enabledRegistrationAgency->hasCrossrefCredentials($context->getId())
        ) {
            return response()->json([
                'error' => __('plugins.generic.crossref.api.citedByNotEnabled')
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $results = Cache::remember(
                "crossref-citedBy-{$submissionId}",
                60 * 60 * 24, // 1 day
                fn() => $this->getCitedByCacheMiss($submission, $enabledRegistrationAgency, $context)
            );
        } catch (Exception $e) {
            // When there is an error, Crossref includes the full URL which has the credentials in the error message.
            // We log the error message, with sensitive values redacted, for debugging purposes by Admins, but return a generic error message to the user to prevent leaking sensitive information.
            error_log('CrossrefPlugin::CrossrefCitedByController: ' . preg_replace('/([?&](?:usr|pwd)=)[^&]*/', '$1*****', $e->getMessage()));
            return response()->json([
                'error' => __('plugins.generic.crossref.api.citedByError')
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'items' => $results,
            'itemsMax' => count($results),
        ], Response::HTTP_OK);
    }

    /**
     * Fetches fresh data from Crossref API when there's a cache miss.
     */
    protected function getCitedByCacheMiss(Submission $submission, IDoiRegistrationAgency $plugin, Context $context): array
    {
        $dois = [];

        if ($context->getData(Context::SETTING_DOI_VERSIONING)) {
            /** @var Publication[] $publishedPublications */
            $publishedPublications = $submission->getPublishedPublications();

            foreach ($publishedPublications as $publication) {
                if ($publication->getDoi()) {
                    $dois[] = $publication->getDoi();
                }
            }
        } else {
            /** @var Publication $publication */
            $publication = $submission->getCurrentPublication();

            if ($publication->getData('status') === Publication::STATUS_PUBLISHED) {
                if ($publication->getDoi()) {
                    $dois[] = $publication->getDoi();
                }
            }
        }

        $cPwd = $plugin->getSetting($context->getId(), 'password');
        $cUser = $plugin->getSetting($context->getId(), 'username');
        $httpClient = Application::get()->getHttpClient();

        $results = [];
        foreach ($dois as $doi) {
            try {
                $response = $httpClient->request(
                    'GET',
                    sprintf(self::CROSSREF_API_URL, urlencode($cUser), urlencode($cPwd), urlencode($doi)),
                    [
                        'headers' => [
                            'Accept' => "application/xml",
                        ],
                    ]
                );

                $data = $response->getBody()->getContents();

                if ($data != null && str_contains($data, "<crossref_result")) {
                    $xml = simplexml_load_string($data);

                    if (!$xml) {
                        $xmlErrors = implode('; ', array_map(fn($error) => trim($error->message), libxml_get_errors()));
                        libxml_clear_errors();
                        error_log("CrossrefPlugin::CrossrefCitedByController: Failed to parse Crossref Cited-by response for DOI {$doi}: {$xmlErrors}");
                        continue;
                    }

                    $elementList = $xml->query_result->body->forward_link ?: null;

                    if (!empty($elementList) && is_iterable($elementList)) {
                        $results = array_merge($results, $this->extractCitationsFromXMLList($elementList));
                    }
                }
            } catch (Exception $e) {
                // If a DOI is not found in crossref, that should not stop the processing of other DOIs.
                // Propagate all non-404 errors
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
        }

        return $results;
    }

    /**
     * Extracts the citations from the XML
     * @param SimpleXMLElement $elementList The List of XML elements
     * @return array The citations
     */
    private function extractCitationsFromXMLList(SimpleXMLElement $elementList): array
    {
        $results = [];

        foreach ($elementList as $item) {
            foreach (CrossrefCitedBy::citationTypes() as $citeType) {
                if ($item->{$citeType}) {
                    if ($citeType === 'msg') {
                        // The msg cite type contains a forward link to other cite types.
                        // So we recursively get those citations as well.
                        // See https://data.crossref.org/reports/help/schema_doc/crossref_query_output2.0/2_0.html#msg
                        $results = [...$results, ...$this->extractCitationsFromXMLList($item->{$citeType}->forward_link)];
                    } else {
                        $results[] = $this->getCitationData($item, $citeType);
                    }
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * Extracts the citation data from the XML
     * @param SimpleXMLElement $item The XML element
     * @param string $type The type of the citation
     * @return array The citation data
     */
    public function getCitationData(SimpleXMLElement $item, string $type): array
    {
        return array_merge(
            $this->getTitles($item, $type),
            [
                'authors' => $this->extractAuthorList($item, $type),
                'doi' => $item->{$type}->doi ? (string)$item->{$type}->doi : null,
                'year' => $item->{$type}->year ? (int)$item->{$type}->year : null,
                'volume' => $item->{$type}->volume ? (int)$item->{$type}->volume : null,
                'issue' => $item->{$type}->issue ? (string)$item->{$type}->issue : null,
                'firstPage' => $item->{$type}->first_page ? (int)$item->{$type}->first_page : null,
                'citationType' => $type
            ]
        );
    }

    /**
     * Extracts the titles of the citation from the XML, handling all possible citation types.
     * @param SimpleXMLElement $item The XML element
     * @param string $type The type of the citation
     * @return array The titles
     */
    private function getTitles(SimpleXMLElement $item, string $type): array
    {
        $result = [];

        switch ($type) {
            case 'book_cite':
            case 'conf_cite':
                $result['title'] = $item->{$type}->volume_title ? (string)$item->{$type}->volume_title : null;
                $result['journal'] = $item->{$type}->series_title ? (string)$item->{$type}->series_title : null;
                $result['componentNumber'] = $item->{$type}->component_number ? (int)$item->{$type}->component_number : null;
                break;
            case 'journal_cite':
                $result['title'] = $item->{$type}->article_title ? (string)$item->{$type}->article_title : null;
                $result['journal'] = $item->{$type}->journal_title ? (string)$item->{$type}->journal_title : null;
                break;
            case 'database_cite':
            case 'dissertation_cite':
                $result['title'] = $item->{$type}->title ? (string)$item->{$type}->title : null;
                $result['institutionName'] = $item->{$type}->institution_name ? (string)$item->{$type}->institution_name : null;
                break;
            case 'standard_cite':
            case 'report_cite':
                $result['title'] = $item->{$type}->volume_title ? (string)$item->{$type}->volume_title : null;
                $result['journal'] = $item->{$type}->series_title ? (string)$item->{$type}->series_title : null;
                $result['institutionName'] = $item->{$type}->institution_name ? (string)$item->{$type}->institution_name : null;
                break;
            default:
                $result['title'] = null;
                $result['journal'] = null;
        }

        return $result;
    }

    /**
     * Extracts the authors from the XML as a comma-separated string.
     * @param SimpleXMLElement $item The XML element
     * @param string $type The type of the citation
     * @return string Comma-separated list of authors
     */
    private function extractAuthorList(SimpleXMLElement $item, string $type): string
    {
        $contributors = $item->{$type}->contributors->contributor ?? [];
        $authors = [];

        foreach ($contributors as $contributor) {
            $name = $contributor->surname . ' ' . $contributor->given_name;

            if ($contributor['first-author'] == 'true') {
                array_unshift($authors, $name);
            } else {
                $authors[] = $name;
            }
        }

        return implode(', ', $authors);
    }
}
