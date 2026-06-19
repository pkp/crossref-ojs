<?php

/**
 * @file plugins/generic/crossref/filter/PeerReviewCrossrefXmlFilter.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class PeerReviewCrossrefXmlFilter
 *
 * @ingroup plugins_generic_crossref
 *
 * @brief Class that converts a Review Assignment to a Crossref XML document.
 */

namespace APP\plugins\generic\crossref\filter;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\crossref\CrossrefExportDeployment;
use APP\plugins\generic\crossref\filter\trait\CrossrefFilterBuilder;
use APP\publication\Publication;
use APP\submission\Submission;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use PKP\context\Context;
use PKP\core\PKPApplication;
use PKP\db\DAORegistry;
use PKP\plugins\importexport\native\filter\NativeExportFilter;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submission\reviewRound\ReviewRound;
use PKP\submission\reviewRound\ReviewRoundDAO;
use PKP\user\User;

class PeerReviewCrossrefXmlFilter extends NativeExportFilter
{
    use CrossrefFilterBuilder;

    private Enumerable $publications;
    private Collection $reviewRounds;
    private Enumerable $submissions;
    private Enumerable $reviewers;

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
     * @throws \Exception
     * @see \PKP\filter\Filter::process()
     *
     */
    public function &process(&$pubObjects): DOMDocument
    {
        $this->reviewAssignments = $pubObjects;

        // cache necessary data before processing reviews
        $this->loadReviewRounds();
        $this->loadReviewers();
        $this->loadPublications();
        $this->loadSubmissions();

        // Create the XML document
        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        // Create the root node
        $rootNode = $this->createRootNode($doc);
        $doc->appendChild($rootNode);

        $rootNode->appendChild($this->createHeadNode($doc));

        $bodyNode = $doc->createElementNS($deployment->getNamespace(), 'body');
        $rootNode->appendChild($bodyNode);

        foreach ($pubObjects as $reviewAssignment) {
            $peerReviewNode = $doc->createElementNS($deployment->getNamespace(), 'peer_review');
            $publication = $this->getPublication($reviewAssignment);
            /**
             * Set Review round attribute. See https://www.crossref.org/documentation/schema-library/markup-guide-record-types/peer-reviews/#:~:text=Revision%20round%20number%2C%20first%20submission%20is%20defined%20as%20revision%20round%200
             */
            $peerReviewNode->setAttribute('revision-round', $reviewAssignment->getRound() - 1);
            $peerReviewNode->setAttribute('type', 'referee-report');
            $bodyNode->appendChild($peerReviewNode);

            $peerReviewNode->appendChild($this->createContributorsNode($doc, $reviewAssignment));
            $peerReviewNode->appendChild($this->createTitlesNode($doc, $reviewAssignment));
            $peerReviewNode->appendChild($this->createReviewDateNode($doc, $reviewAssignment));

            if ($reviewAssignment->getCompetingInterests()) {
                $peerReviewNode->appendChild($this->createCompetingInterestNode($doc, $reviewAssignment));
            }

            $peerReviewNode->appendChild($this->createRunningNumberNode($doc, $reviewAssignment));
            $peerReviewNode->appendChild($this->createRelationshipNode($doc, $reviewAssignment));

            $request = Application::get()->getRequest();
            $dispatcher = $this->_getDispatcher($request);

            $resourceURL = $dispatcher->url(
                $request,
                PKPApplication::ROUTE_PAGE,
                $context->getPath(),
                'article',
                'view',
                [
                    $publication->getData('urlPath') ?? $this->getSubmission($publication->getData('submissionId'))->getId(),
                ],
                [
                    'tab' => 'peer-review-record',
                    'reviewId' => $reviewAssignment->getId(),
                ],
                null,
                true,
                ''
            );

            $peerReviewNode->appendChild($this->createDOIDataNode($doc, $reviewAssignment->getDoi(), $resourceURL));
        }

        return $doc;
    }

    /**
     * Create the contributor node.
     * @throws \DOMException
     */
    private function createContributorsNode(DOMDocument $doc, ReviewAssignment $reviewAssignment): DOMElement
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $contributorsNode = $doc->createElementNS($deployment->getNamespace(), 'contributors');

        $reviewer = $this->getReviewer($reviewAssignment);
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

            if ($reviewer->getData('orcid')) {
                $orcidNode = $doc->createElementNS($deployment->getNamespace(), 'ORCID', $reviewer->getData('orcid'));
                $orcidAuthenticated = $reviewer->getData('orcidIsVerified') ? 'true' : 'false';
                $orcidNode->setAttribute('authenticated', $orcidAuthenticated);
                $personNameNode->appendChild($orcidNode);
            }

            if (!empty($familyNames[$locale]) && !empty($givenNames[$locale])) {
                $hasAltName = false;
                foreach ($familyNames as $otherLocal => $familyName) {
                    if ($otherLocal != $locale && isset($familyName) && !empty($familyName)) {
                        if (!$hasAltName) {
                            $altNameNode = $doc->createElementNS($deployment->getNamespace(), 'alt-name');
                            $personNameNode->appendChild($altNameNode);
                            $hasAltName = true;
                        }

                        $nameNode = $doc->createElementNS($deployment->getNamespace(), 'name');
                        $nameNode->setAttribute('language', \Locale::getPrimaryLanguage($otherLocal));

                        $nameNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars($familyName, ENT_COMPAT, 'UTF-8')));
                        if (!empty($givenNames[$otherLocal])) {
                            $nameNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'given_name', htmlspecialchars($givenNames[$otherLocal], ENT_COMPAT, 'UTF-8')));
                        }

                        $altNameNode->appendChild($nameNode);
                    }
                }
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
     * @throws \DOMException
     */
    private function createTitlesNode(DOMDocument $doc, ReviewAssignment $reviewAssignment): DOMElement
    {
        $deployment = $this->getDeployment();
        $publication = $this->getPublication($reviewAssignment);
        $locale = $publication->getData('locale');
        $titlesNode = $doc->createElementNS($deployment->getNamespace(), 'titles');
        $reviewRound = $this->getReviewRound($reviewAssignment->getReviewRoundId());


        /**
         * Prepare review title below.
         * This includes:
         *  - localized publication title
         *  - Revision Number & Review Number (see https://www.crossref.org/documentation/schema-library/markup-guide-record-types/peer-reviews/#:~:text=Title%20of%20review.,R2%2FRC3)
         */

        // publication title
        $publicationTitle = $publication->getLocalizedTitle($locale);

        // Get Revision Number (round of review)
        $revisionNumber = $reviewRound->getRound();

        /** Get Review Number (position of the review assignment within the round) */
        // 1 - Get all reviews in the round
        $allReviewsInRound = array_filter($this->reviewAssignments, fn (ReviewAssignment $reviewAssignment) => $reviewAssignment->getReviewRoundId() === $reviewRound->getId());

        // 2 - Sort reviews by date completed; from earliest to most recent so reviews appear in the order that they were completed.
        usort($allReviewsInRound, function (ReviewAssignment $a, ReviewAssignment $b) {
            return strtotime($a->getDateCompleted()) <=> strtotime($b->getDateCompleted());
        });

        // 3 - Find index of current review assignment within the sorted reviews for the round
        $reviewNumber = array_search($reviewAssignment, $allReviewsInRound, true) + 1;

        $titleText = __('plugins.importexport.crossref.reviewTitle', [
            'publicationTitle' => $publicationTitle,
            'revisionNumber' => $revisionNumber,
            'reviewNumber' => $reviewNumber,
        ], $locale);

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
     * @throws \DOMException
     */
    private function createReviewDateNode(DOMDocument $doc, ReviewAssignment $reviewAssignment): DOMElement
    {
        $deployment = $this->getDeployment();

        $reviewDateNode = $doc->createElementNS($deployment->getNamespace(), 'review_date');
        $dateParsed = Carbon::parse($reviewAssignment->getDateCompleted());

        $reviewDateNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'month', $dateParsed->format('m')));
        $reviewDateNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'day', $dateParsed->format('d')));
        $reviewDateNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'year', $dateParsed->format('Y')));

        return $reviewDateNode;
    }

    /**
     * Create the competing interest node.
     * @throws \DOMException
     */
    private function createCompetingInterestNode(DOMDocument $doc, ReviewAssignment $reviewAssignment): DOMElement
    {
        $deployment = $this->getDeployment();
        return $doc->createElementNS($deployment->getNamespace(), 'competing_interest_statement', htmlspecialchars($reviewAssignment->getCompetingInterests(), ENT_COMPAT, 'UTF-8'));
    }

    /**
     * Create the running number node.
     * @throws \DOMException
     */
    private function createRunningNumberNode(DOMDocument $doc, ReviewAssignment $reviewAssignment): DOMElement
    {
        $deployment = $this->getDeployment();
        return $doc->createElementNS($deployment->getNamespace(), 'running_number', $reviewAssignment->getId());
    }

    /**
     * Create the relationship node to associate a review with a publication.
     * @throws \DOMException
     */
    private function createRelationshipNode(DOMDocument $doc, ReviewAssignment $reviewAssignment): DOMElement
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();

        $relationsNs = $deployment->getRelNamespace();
        $programNode = $doc->createElementNS($relationsNs, 'rel:program');
        $relatedItemNode = $doc->createElementNS($relationsNs, 'rel:related_item');
        $publication = $this->getPublication($reviewAssignment);

        $doiVersioning = $deployment->getContext()->getData(Context::SETTING_DOI_VERSIONING);
        // If same DOI is used for all publication versions then link the peer review to the current publication as that publication would be the one deposited to crossref
        $publicationDoi = null;
        if (!$doiVersioning) {
            $submission = $this->getSubmission($publication->getData('submissionId'));
            $currentPublication = $submission->getCurrentPublication();
            $publicationDoi = $currentPublication->getDoi();
        } else {
            $publicationDoi = $publication->getDoi();
        }

        $interRelationNode = $doc->createElementNS($relationsNs, 'rel:inter_work_relation', $publicationDoi);
        $interRelationNode->setAttribute('relationship-type', 'isReviewOf');
        $interRelationNode->setAttribute('identifier-type', 'doi');

        $relatedItemNode->appendChild($interRelationNode);
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
        $publication = $this->publications->get($reviewRound->getPublicationId());
        return $publication;
    }

    /**
     * Get the reviewer for a review assignment.
     */
    private function getReviewer(ReviewAssignment $reviewAssignment): User
    {
        if (empty($this->reviewers)) {
            $this->loadReviewers();
        }

        /** @var User $reviewer */
        $reviewer = $this->reviewers->get($reviewAssignment->getReviewerId());
        return $reviewer;
    }

    /**
     * Get the review round for a review assignment.
     */
    private function getReviewRound(int $id): ReviewRound
    {
        if (empty($this->reviewRounds)) {
            $this->loadReviewRounds();
        }

        /** @var ReviewRound $reviewRound */
        $reviewRound = $this->reviewRounds->get($id);

        return $reviewRound;
    }

    /**
     * Load the review rounds for all review assignments.
     * @throws \Exception
     */
    private function loadReviewRounds(): void
    {
        if (!empty($this->reviewRounds)) {
            return;
        }

        /** @var ReviewRoundDAO $reviewRoundDao */
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
        $roundIds = [];

        /** @var ReviewAssignment $reviewAssignment */
        foreach ($this->reviewAssignments as $reviewAssignment) {
            $roundIds[] = $reviewAssignment->getReviewRoundId();
        }

        $roundIds = array_unique($roundIds);
        $reviewRounds = $reviewRoundDao->getByReviewRoundIds($roundIds);
        $this->reviewRounds = collect();

        while ($reviewRound = $reviewRounds->next()) {
            $this->reviewRounds->put(
                $reviewRound->getId(),
                $reviewRound
            );
        }
    }

    /**
     * Load the reviewers for all review assignments
     */
    private function loadReviewers(): void
    {
        if (!empty($this->reviewers)) {
            return;
        }

        $reviewerIds = array_unique(
            array_map(fn(ReviewAssignment $review) => $review->getReviewerId(), $this->reviewAssignments)
        );

        $this->reviewers = Repo::user()->getCollector()
            ->filterByUserIds($reviewerIds)
            ->getMany()
            ->keyBy(fn(User $user) => $user->getId());
    }

    /**
     * Load the publications for all review assignments.
     */
    private function loadPublications(): void
    {
        if (!empty($this->publications)) {
            return;
        }

        $this->publications = collect();
        $publicationIds = [];

        /** @var ReviewAssignment $reviewAssignment */
        foreach ($this->reviewAssignments as $reviewAssignment) {
            $reviewRound = $this->getReviewRound($reviewAssignment->getReviewRoundId());
            $publicationIds[] = $reviewRound->getPublicationId();
        }

        $publications = Repo::publication()->getCollector()
            ->filterByPublicationIds(array_unique($publicationIds))
            ->getMany()
            ->keyBy(fn(Publication $publication) => $publication->getId());

        $this->publications = $publications;
    }

    /**
     * Load the submissions for all review assignments.
     */
    private function loadSubmissions(): void
    {
        if (!empty($this->submissions)) {
            return;
        }

        $this->submissions = collect();
        $submissionIds = [];

        /** @var ReviewAssignment $reviewAssignment */
        foreach ($this->reviewAssignments as $reviewAssignment) {
            $submissionIds[] = $reviewAssignment->getSubmissionId();
        }

        $context = $this->getDeployment()->getContext();

        $submissions = Repo::submission()->getCollector()
            ->filterByContextIds([$context->getId()])
            ->filterBySubmissionIds(array_unique($submissionIds))
            ->getMany()
            ->keyBy(fn(Submission $submission) => $submission->getId());

        $this->submissions = $submissions;
    }

    /**
     * Get a submission by ID.
     */
    private function getSubmission(int $submissionId): Submission
    {
        if (empty($this->submissions)) {
            $this->loadSubmissions();
        }

        /** @var Submission $submission */
        $submission = $this->submissions->get($submissionId);

        return $submission;
    }
}
