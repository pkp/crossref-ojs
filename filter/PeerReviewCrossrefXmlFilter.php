<?php

/**
 * @file plugins/generic/crossref/filter/PeerReviewCrossrefXmlFilter.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2000-2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class PeerReviewCrossrefXmlFilter
 *
 * @ingroup plugins_generic_crossref
 *
 * @brief Class that converts an Article to a Crossref XML document.
 */

namespace APP\plugins\generic\crossref\filter;

use APP\author\Author;
use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\crossref\CrossrefExportDeployment;
use APP\plugins\generic\crossref\filter\trait\CrossrefFilterBuilder;
use APP\publication\Publication;
use APP\submission\Submission;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Enumerable;
use PKP\citation\Citation;
use PKP\citation\enum\CitationSourceType;
use PKP\citation\enum\CitationType;
use PKP\context\Context;
use PKP\core\PKPApplication;
use PKP\db\DAORegistry;
use PKP\filter\FilterGroup;
use PKP\i18n\LocaleConversion;
use PKP\plugins\importexport\native\filter\NativeExportFilter;
use PKP\submission\GenreDAO;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submission\reviewRound\authorResponse\AuthorResponse;
use PKP\submission\reviewRound\ReviewRound;
use PKP\submission\reviewRound\ReviewRoundDAO;
use PKP\user\User;

class PeerReviewCrossrefXmlFilter extends NativeExportFilter
{
    use CrossrefFilterBuilder;

    private Enumerable $publications;
    private Enumerable $reviewRounds;
    private Enumerable $authorResponses;

    private array $reviewAssignments = [];

    /**
     * @copydoc NativeExportFilter::__construct()
     */
    public function __construct($filterGroup)
    {
        $this->setDisplayName('Crossref XML peer review export');
        parent::__construct($filterGroup);
    }

    /**
     * @param ReviewAssignment[] $pubObjects Array of Review Assignments
     *
     * @return \DOMDocument
     * @throws \Exception
     * @see \PKP\filter\Filter::process()
     *
     */
    public function &process(&$pubObjects)
    {
        $this->reviewAssignments = $pubObjects;
        $this->loadReviewRounds();
        $this->loadPublications();

        // Create the XML document
        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();

        // Create the root node
        $rootNode = $this->createRootNode($doc);
        $doc->appendChild($rootNode);

        $rootNode->appendChild($this->createHeadNode($doc));

        $bodyNode = $doc->createElementNS($deployment->getNamespace(), 'body');
        $rootNode->appendChild($bodyNode);

        foreach ($pubObjects as $reviewAssignment) {
            $peerReviewNode = $doc->createElementNS($deployment->getNamespace(), 'peer_review');
            $publication = $this->getPublication($reviewAssignment);
            $locale = $publication->getData('locale');

            /**
             * Set Review round attribute. See https://www.crossref.org/documentation/schema-library/markup-guide-record-types/peer-reviews/#:~:text=Revision%20round%20number%2C%20first%20submission%20is%20defined%20as%20revision%20round%200
             */
            $peerReviewNode->setAttribute('revision-round', $reviewAssignment->getRound() - 1);
            $peerReviewNode->setAttribute('language', \Locale::getPrimaryLanguage($locale));
            $bodyNode->appendChild($peerReviewNode);

            $peerReviewNode->appendChild($this->createContributorsNode($doc, $reviewAssignment));
            $peerReviewNode->appendChild($this->createTitlesNode($doc, $reviewAssignment));
            $peerReviewNode->appendChild($this->createReviewDateNode($doc, $reviewAssignment));

            if ($reviewAssignment->getCompetingInterests()) {
                $peerReviewNode->appendChild($this->createCompetingInterestNode($doc, $reviewAssignment));
            }

            $peerReviewNode->appendChild($this->createRunningNumberNode($doc, $reviewAssignment));
            $peerReviewNode->appendChild($this->createProgramNode($doc, $reviewAssignment));

            $request = Application::get()->getRequest();
            $dispatcher = $this->_getDispatcher($request);

            $resourceURL = $dispatcher->url(
                $request,
                PKPApplication::ROUTE_PAGE,
                $context->getPath(),
                'article',
                'view',
                [
                    $publication->getData('urlPath') ??
                    Repo::submission()->get($publication->getData('submissionId'))->getId(),
                ],
                [
                    'tab' =>'peer-review-record',
                    'reviewId' => $reviewAssignment->getId(),
                ],
                null,
                true,
                ''
            );

            $peerReviewNode->appendChild($this->createDoiDataNode($doc, $reviewAssignment->getDoi(), $resourceURL));
        }

        return $doc;
    }

    /**
     * Create the contributor node.
     * @param DOMDocument $doc
     * @param ReviewAssignment $reviewAssignment
     * @return DOMElement
     * @throws \DOMException
     */
    private function createContributorsNode(DOMDocument $doc, ReviewAssignment $reviewAssignment): DOMElement
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $contributorsNode = $doc->createElementNS($deployment->getNamespace(), 'contributors');

        // move getting remover, round, and publication details to methods
        // add cache for these data too
        /** @var User $reviewer */
        $reviewer = Repo::user()->get($reviewAssignment->getReviewerId());

        $isOpenReview = $reviewAssignment->getReviewMethod() === ReviewAssignment::SUBMISSION_REVIEW_METHOD_OPEN;
        $publication = $this->getPublication($reviewAssignment);

        $locale = $publication->getData('locale');

        if ($isOpenReview) {
            $familyNames = $reviewer->getFamilyName(null);
            $givenNames = $reviewer->getGivenName(null);
            $personNameNode = $doc->createElementNS($deployment->getNamespace(), 'person_name');
            $personNameNode->setAttribute('contributor_role', 'reviewer-external');
            $personNameNode->setAttribute('sequence', 'first');

            // Check if both givenName and familyName are set for the submission language.
            if (!empty($familyNames[$locale]) && !empty($givenNames[$locale])) {
                $personNameNode->setAttribute('language', \Locale::getPrimaryLanguage($locale));
                $personNameNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'given_name', htmlspecialchars($givenNames[$locale], ENT_COMPAT, 'UTF-8')));
                $personNameNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars($familyNames[$locale], ENT_COMPAT, 'UTF-8')));
            } else {
                $personNameNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars($givenNames[$locale], ENT_COMPAT, 'UTF-8')));
            }

            $affiliation = $reviewer->getAffiliation($locale);
            if ($affiliation) {
                $affiliationsNode = $doc->createElementNS($deployment->getNamespace(), 'affiliations');
                $institutionNode = $doc->createElementNS($deployment->getNamespace(), 'institution');
                $institutionNameNode = $doc->createElementNS($deployment->getNamespace(), 'institution_name', htmlspecialchars($affiliation, ENT_COMPAT, 'UTF-8'));
                $institutionNode->appendChild($institutionNameNode);
                $affiliationsNode->appendChild($institutionNode);
                $personNameNode->appendChild($affiliationsNode);
            }

            $contributorsNode->appendChild($personNameNode);
        } else {
            $anonymousReviewerNode = $doc->createElementNS($deployment->getNamespace(), 'anonymous');
            $anonymousReviewerNode->setAttribute('contributor_role', 'reviewer-external');
            $anonymousReviewerNode->setAttribute('sequence', 'first');
            $contributorsNode->appendChild($anonymousReviewerNode);
        }


        return $contributorsNode;
    }

    /**
     * Create the titles node.
     * @param DOMDocument $doc
     * @param ReviewAssignment $reviewAssignment
     * @return DOMElement
     * @throws \DOMException
     */
    private function createTitlesNode(DOMDocument $doc, ReviewAssignment $reviewAssignment): DOMElement
    {
        $deployment = $this->getDeployment();

        $publication = $this->getPublication($reviewAssignment);

        $titlesNode = $doc->createElementNS($deployment->getNamespace(), 'titles');
        $titleText = 'Review: ' . $publication->getLocalizedTitle($publication->getData('locale'));

        $titleNode = $doc->createElementNS(
            $deployment->getNamespace(),
            'title',
            htmlspecialchars($titleText, ENT_COMPAT, 'UTF-8')
        );

        $titlesNode->appendChild($titleNode);

        return $titlesNode;
    }

    /**
     * Create the review date node.
     * @param DOMDocument $doc
     * @param ReviewAssignment $reviewAssignment
     * @return DOMElement
     * @throws \DOMException
     */
    private function createReviewDateNode(DOMDocument $doc, ReviewAssignment $reviewAssignment): DOMElement
    {
        $deployment = $this->getDeployment();

        $reviewDateNode = $doc->createElementNS($deployment->getNamespace(), 'review_date');
        $dateParsed = Carbon::parse($reviewAssignment->getDateCompleted());

        $reviewDateNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'month', $dateParsed->month));
        $reviewDateNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'day', $dateParsed->day));
        $reviewDateNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'year', $dateParsed->year));

        return $reviewDateNode;
    }

    /**
     * Create the competing interest node.
     * @param DOMDocument $doc
     * @param ReviewAssignment $reviewAssignment
     * @return DOMElement
     * @throws \DOMException
     */
    private function createCompetingInterestNode(DOMDocument $doc, ReviewAssignment $reviewAssignment): DOMElement
    {
        $deployment = $this->getDeployment();
        return $doc->createElementNS($deployment->getNamespace(), 'competing_interest_statement', htmlspecialchars($reviewAssignment->getCompetingInterests(), ENT_COMPAT, 'UTF-8'));
    }

    /**
     * Create the running number node.
     * @param DOMDocument $doc
     * @param ReviewAssignment $reviewAssignment
     * @return DOMElement
     * @throws \DOMException
     */
    private function createRunningNumberNode(DOMDocument $doc, ReviewAssignment $reviewAssignment): DOMElement
    {
        $deployment = $this->getDeployment();
        return $doc->createElementNS($deployment->getNamespace(), 'running_number', $reviewAssignment->getId());
    }

    /**
     * Create the program node.
     * @param DOMDocument $doc
     * @param ReviewAssignment $reviewAssignment
     * @return DOMElement
     * @throws \DOMException
     */
    private function createProgramNode(DOMDocument $doc, ReviewAssignment $reviewAssignment): DOMElement
    {
        $relationsNs = 'http://www.crossref.org/relations.xsd';
        $programNode = $doc->createElementNS($relationsNs, 'program');
        $relatedItemNode = $doc->createElementNS($relationsNs, 'related_item');
        $publication = $this->getPublication($reviewAssignment);

        $relationNode = $doc->createElementNS($relationsNs, 'inter_work_relation', $publication->getDoi());
        $relationNode->setAttribute('relationship-type', 'isReviewOf');
        $relationNode->setAttribute('identifier-type', 'doi');

        $relatedItemNode->appendChild($relationNode);
        $programNode->appendChild($relatedItemNode);

        return $programNode;
    }

    /**
     * Get the publication for a review assignment.
     */
    private function getPublication(ReviewAssignment $reviewAssignment): Publication
    {
        $reviewRound = $this->getReviewRound($reviewAssignment->getReviewRoundId());

        if (empty($this->publications)) {
            $this->loadPublications();
        }

        /** @var Publication $publication */
        $publication = $this->publications->get($reviewRound->getpublicationId());
        return $publication;
    }

    /**
     * Get the review round for a review assignment.
     */
    private function getReviewRound($id): ReviewRound
    {

        if (empty($this->reviewRounds)) {
            $this->loadReviewRounds($this->reviewAssignments);
        }

        /** @var ReviewRound $reviewRound */
        $reviewRound = $this->reviewRounds->get($id);

        return $reviewRound;
    }

    /**
     * Load the review rounds for all review assignments.
     * @return void
     * @throws \Exception
     */
    private function loadReviewRounds(): void
    {
        if (!empty($this->reviewRounds)) {
            return;
        }

        /** @var ReviewRoundDAO $reviewRoundDao */
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
        $this->reviewRounds = collect();

        foreach ($this->reviewAssignments as $reviewAssignment) {
            $this->reviewRounds->put(
                $reviewAssignment->getReviewRoundId(),
                $reviewRoundDao->getById($reviewAssignment->getReviewRoundId())
            );
        }
    }

    /**
     * Load the publications for all review assignments.
     * @return void
     */
    private function loadPublications(): void
    {
        if (!empty($this->publications)) {
            return;
        }

        $this->publications = collect();
        $publicationIds = [];

        foreach ($this->reviewAssignments as $reviewAssignment) {
            $reviewRound = $this->getReviewRound($reviewAssignment->getReviewRoundId());
            $publicationIds[] = $reviewRound->getPublicationId();
        }

        $publications = Repo::publication()->getCollector()
            ->filterByPublicationIds($publicationIds)
            ->getMany()
            ->keyBy(fn(Publication $publication) => $publication->getId());

        $this->publications = $publications;
    }
}
