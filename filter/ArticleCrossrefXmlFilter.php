<?php

/**
 * @file plugins/generic/crossref/filter/ArticleCrossrefXmlFilter.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2000-2025 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class ArticleCrossrefXmlFilter
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
use PKP\submission\genre\Genre;
;

class ArticleCrossrefXmlFilter extends IssueCrossrefXmlFilter
{
    // Processed versions DOIs
    protected array $versionsDois = [];

    /**
     * Constructor
     *
     * @param FilterGroup $filterGroup
     */
    public function __construct($filterGroup)
    {
        parent::__construct($filterGroup);
        $this->setDisplayName('Crossref XML article export');
    }

    /**
     * @see \PKP\filter\Filter::process()
     *
     * @param array $pubObjects Array of Submissions
     *
     * @return \DOMDocument
     */
    public function &process(&$pubObjects)
    {
        // Create the XML document
        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        // Create the root node
        $rootNode = $this->createRootNode($doc);
        $doc->appendChild($rootNode);

        // Create and append the 'head' node and all parts inside it
        $rootNode->appendChild($this->createHeadNode($doc));

        // Create and append the 'body' node, that contains everything
        $bodyNode = $doc->createElementNS($deployment->getNamespace(), 'body');
        $rootNode->appendChild($bodyNode);

        foreach ($pubObjects as $pubObject) {
            if (!$context->getData(Context::SETTING_DOI_VERSIONING)) {
                $publication = $pubObject->getCurrentPublication();
                $journalNode = $this->createSubmissionJournalNode($doc, $pubObject, $publication);
                $bodyNode->appendChild($journalNode);
            } else {
                $latestMinorPublications = $this->getLatestMinorPublications($pubObject->getData('publications'));
                foreach ($latestMinorPublications as $versionStage) {
                    foreach ($versionStage as $publication) {
                        $journalNode = $this->createSubmissionJournalNode($doc, $pubObject, $publication);
                        $bodyNode->appendChild($journalNode);
                        $this->versionsDois[] = $publication->getDoi();
                    }
                }
            }
            $this->versionsDois = [];
        }
        return $doc;
    }

    /**
     * Get the publications that can be exported/deposited.
     * Only the last minor versions are considered.
     */
    protected function getLatestMinorPublications(Enumerable $publications): array
    {
        $latestMinorPublications = [];
        foreach ($publications as $publication) {
            if (!$publication->getDoi() ||
                $publication->getData('status') != Publication::STATUS_PUBLISHED) {

                    continue;
            }

            $versionStage = $publication->getData('versionStage');
            $versionMajor = $publication->getData('versionMajor');
            $versionMinor = $publication->getData('versionMinor');
            if (!array_key_exists($versionStage, $latestMinorPublications)) {
                $latestMinorPublications[$versionStage] = [];
            }
            if (!array_key_exists($versionMajor, $latestMinorPublications[$versionStage])) {
                $latestMinorPublications[$versionStage][$versionMajor] = $publication;
                continue;
            }
            if ($versionMinor > $latestMinorPublications[$versionStage][$versionMajor]->getData('versionMinor')) {
                $latestMinorPublications[$versionStage][$versionMajor] = $publication;
            }
        }
        return $latestMinorPublications;
    }

    //
    // Submission conversion functions
    //
    /**
     * Create and return the journal node 'journal'.
     */
    public function createSubmissionJournalNode(DOMDocument $doc, Submission $pubObject, Publication $publication): DOMElement
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        $journalNode = $doc->createElementNS($deployment->getNamespace(), 'journal');
        $journalNode->appendChild($this->createJournalMetadataNode($doc));
        $issueNode = $this->createSubmissionJournalIssueNode($doc, $publication);
        if ($issueNode) {
            $journalNode->appendChild($issueNode);
        }
        $journalNode->appendChild($this->createJournalArticleNode($doc, $publication, $pubObject));
        return $journalNode;
    }

    /**
     * Create and return the journal issue node 'journal_issue'.
     */
    public function createSubmissionJournalIssueNode(DOMDocument $doc, Publication $publication): ?DOMElement
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $cache = $deployment->getCache();

        $issueId = $publication->getData('issueId');

        if (!$issueId) {
            return null;
        }

        if ($cache->isCached('issues', $issueId)) {
            $issue = $cache->get('issues', $issueId);
        } else {
            $issue = Repo::issue()->get($issueId);
            $issue = $issue->getJournalId() == $context->getId() ? $issue : null;
            if ($issue) {
                $cache->add($issue, null);
            }
        }
        return parent::createJournalIssueNode($doc, $issue);
    }

    /**
     * Create and return the journal article node 'journal_article'.
     */
    public function createJournalArticleNode(DOMDocument $doc, Publication $publication, Submission $submission): DOMElement
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();

        $locale = $publication->getData('locale');

        $journalArticleNode = $doc->createElementNS($deployment->getNamespace(), 'journal_article');
        $journalArticleNode->setAttribute('publication_type', 'full_text');
        $journalArticleNode->setAttribute('language', \Locale::getPrimaryLanguage($locale));

        // titles
        $titleLanguages = array_keys($publication->getTitles());
        // Crossref 5.4.0 limits to 20 titles maximum, ensure the primary locale is first
        $primaryLanguageIndex = array_search($locale, $titleLanguages);
        if ($primaryLanguageIndex) {
            unset($titleLanguages[$primaryLanguageIndex]);
            array_unshift($titleLanguages, $locale);
        }
        $languageCounter = 1;
        foreach ($titleLanguages as $lang) {
            $titlesNode = $doc->createElementNS($deployment->getNamespace(), 'titles');
            $titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'title'));
            $node->appendChild($doc->createTextNode($publication->getLocalizedTitle($lang, 'html')));
            if ($subtitle = $publication->getLocalizedSubTitle($lang, 'html')) {
                $titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'subtitle'));
                $node->appendChild($doc->createTextNode($subtitle));
            }
            $journalArticleNode->appendChild($titlesNode);
            $languageCounter++;
            if ($languageCounter > 20) {
                break;
            }
        }

        // contributors
        $authors = $publication->getData('authors');
        if ($authors->count() != 0) {
            $contributorsNode = $this->createContributorsNode($doc, $publication);
            $journalArticleNode->appendChild($contributorsNode);
        }

        // jats:abstract
        $this->appendAbstractNode($doc, $journalArticleNode, $publication);

        // publication_date
        if ($datePublished = $publication->getData('datePublished')) {
            $journalArticleNode->appendChild($this->createDateNode($doc, $datePublished, 'publication_date'));
        }

        // pages
        // Crossref requires first_page and last_page of any contiguous range, then any other ranges go in other_pages
        $pages = $publication->getPageArray();
        if (!empty($pages)) {
            $firstRange = array_shift($pages);
            $firstPage = array_shift($firstRange);
            if (count($firstRange)) {
                // There is a first page and last page for the first range
                $lastPage = array_shift($firstRange);
            } else {
                // There is not a range in the first segment
                $lastPage = '';
            }
            // Crossref accepts no punctuation in first_page or last_page
            if ((!empty($firstPage) || $firstPage === '0') && !preg_match('/[^[:alnum:]]/', $firstPage) && !preg_match('/[^[:alnum:]]/', $lastPage)) {
                $pagesNode = $doc->createElementNS($deployment->getNamespace(), 'pages');
                $pagesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'first_page', $firstPage));
                if ($lastPage != '') {
                    $pagesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'last_page', $lastPage));
                }
                $otherPages = '';
                foreach ($pages as $range) {
                    $otherPages .= ($otherPages ? ',' : '') . implode('-', $range);
                }
                if ($otherPages != '') {
                    $pagesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'other_pages', $otherPages));
                }
                $journalArticleNode->appendChild($pagesNode);
            }
        }

        // ai:program (AccessIndicators) element, that contains the license URL
        $this->appendProgramNode($doc, $journalArticleNode, $publication);

        if ($context->getData(Context::SETTING_DOI_VERSIONING) && $this->versionsDois) {
            // crossmark updates
            $this->appendCrossmarkNode($doc, $journalArticleNode, $this->versionsDois, $datePublished);

            // rel:program
            $this->appendRelationships($doc, $journalArticleNode, $this->versionsDois);
        }

        // doi_data
        $dispatcher = $this->_getDispatcher($request);
        if ($context->getData(Context::SETTING_DOI_VERSIONING)) {
            $url = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'view', [$publication->getData('urlPath') ?? $submission->getId(), 'version', $publication->getId()], null, null, true, '');
        } else {
            $url = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'view', [$publication->getData('urlPath') ?? $submission->getId()], null, null, true, '');
        }
        $doiDataNode = $this->createDOIDataNode($doc, $publication->getDoi(), $url);

        // Append galleys files and collection nodes to the DOI data node
        $galleys = $publication->getData('galleys');
        // All full-texts, PDF full-texts and remote galleys for text-mining and as-crawled URL
        $submissionGalleys = $pdfGalleys = $remoteGalleys = [];
        // preferred PDF full-text for the as-crawled URL
        $pdfGalleyInArticleLocale = null;
        // get immediately also supplementary files for component list
        $componentGalleys = [];
        foreach ($galleys as $galley) {
            // filter supp files with DOI
            if (!$galley->getData('urlRemote')) {
                $galleyFile = $galley->getFile();
                if ($galleyFile) {
                    $genre = Genre::findById((int) $galleyFile->getGenreId());
                    if ($genre !== null && $genre->supplementary && $galley->getDoi()) {
                        $componentGalleys[] = $galley;
                    } else {
                        $submissionGalleys[] = $galley;
                        if ($galley->isPdfGalley()) {
                            $pdfGalleys[] = $galley;
                            if (!$pdfGalleyInArticleLocale && $galley->getLocale() == $locale) {
                                $pdfGalleyInArticleLocale = $galley;
                            }
                        }
                    }
                }
            } else {
                $remoteGalleys[] = $galley;
            }
        }
        // as-crawled URLs
        $asCrawledGalleys = [];
        if ($pdfGalleyInArticleLocale) {
            $asCrawledGalleys = [$pdfGalleyInArticleLocale];
        } elseif (!empty($pdfGalleys)) {
            $asCrawledGalleys = [$pdfGalleys[0]];
        } else {
            $asCrawledGalleys = $submissionGalleys;
        }
        // as-crawled URL - collection nodes
        $this->appendAsCrawledCollectionNodes($doc, $doiDataNode, $publication, $submission, $asCrawledGalleys);
        // text-mining - collection nodes
        $submissionGalleys = array_merge($submissionGalleys, $remoteGalleys);
        $this->appendTextMiningCollectionNodes($doc, $doiDataNode, $publication, $submission, $submissionGalleys);

        $journalArticleNode->appendChild($doiDataNode);


        $this->appendCitationListNode($doc, $journalArticleNode, $publication);

        // component_list (supplementary files)
        if (!empty($componentGalleys)) {
            $journalArticleNode->appendChild($this->createComponentListNode($doc, $publication, $submission, $componentGalleys));
        }

        return $journalArticleNode;
    }

    /**
     * Create contributors node.
     */
    public function createContributorsNode(DOMDocument $doc, Publication $publication): DOMElement
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();

        $locale = $publication->getData('locale');

        $contributorsNode = $doc->createElementNS($deployment->getNamespace(), 'contributors');

        $isFirst = true;
        foreach ($publication->getData('authors') as $author) {
            /** @var Author $author */
            $personNameNode = $doc->createElementNS($deployment->getNamespace(), 'person_name');
            $personNameNode->setAttribute('contributor_role', 'author');
            if ($isFirst) {
                $personNameNode->setAttribute('sequence', 'first');
            } else {
                $personNameNode->setAttribute('sequence', 'additional');
            }

            $familyNames = $author->getFamilyName(null);
            $givenNames = $author->getGivenName(null);

            // Check if both givenName and familyName is set for the submission language.
            if (!empty($familyNames[$locale]) && !empty($givenNames[$locale])) {
                $personNameNode->setAttribute('language', \Locale::getPrimaryLanguage($locale));
                $personNameNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'given_name', htmlspecialchars($givenNames[$locale], ENT_COMPAT, 'UTF-8')));
                $personNameNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars($familyNames[$locale], ENT_COMPAT, 'UTF-8')));
            } else {
                $personNameNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars($givenNames[$locale], ENT_COMPAT, 'UTF-8')));
            }

            $affiliations = $author->getAffiliations();
            if (count($affiliations) > 0) {
                $affiliationsNode = $doc->createElementNS($deployment->getNamespace(), 'affiliations');
                foreach ($affiliations as $affiliation) {
                    $institutionNode = $doc->createElementNS($deployment->getNamespace(), 'institution');
                    $institutionNameNode = $doc->createElementNS($deployment->getNamespace(), 'institution_name', htmlspecialchars($affiliation->getLocalizedName($locale), ENT_COMPAT, 'UTF-8'));
                    $institutionNode->appendChild($institutionNameNode);
                    $rorId = $affiliation->getRor();
                    if ($rorId) {
                        $institutionIdNode = $doc->createElementNS($deployment->getNamespace(), 'institution_id', $rorId);
                        $institutionIdNode->setAttribute('type', 'ror');
                        $institutionNode->appendChild($institutionIdNode);
                    }
                    $affiliationsNode->appendChild($institutionNode);
                }
                $personNameNode->appendChild($affiliationsNode);
            }

            if ($author->getData('orcid')) {
                $orcidNode = $doc->createElementNS($deployment->getNamespace(), 'ORCID', $author->getData('orcid'));
                $orcidAuthenticated = $author->getData('orcidIsVerified') ? 'true' : 'false';
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
                        if (isset($givenNames[$otherLocal]) && !empty($givenNames[$otherLocal])) {
                            $nameNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'given_name', htmlspecialchars($givenNames[$otherLocal], ENT_COMPAT, 'UTF-8')));
                        }

                        $altNameNode->appendChild($nameNode);
                    }
                }
            }

            $contributorsNode->appendChild($personNameNode);
            $isFirst = false;
        }
        return $contributorsNode;
    }

    /**
     * Append jats:abstract node
     */
    public function appendAbstractNode(DOMDocument $doc, DOMElement $parentNode, Publication $publication): void
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();

        $abstracts = $publication->getData('abstract') ?: [];
        foreach($abstracts as $lang => $abstract) {
            $abstractNode = $doc->createElementNS($deployment->getJATSNamespace(), 'jats:abstract');
            $abstractNode->setAttributeNS($deployment->getXMLNamespace(), 'xml:lang', LocaleConversion::toBcp47($lang));
            $abstractNode->appendChild($doc->createElementNS($deployment->getJATSNamespace(), 'jats:p', htmlspecialchars(html_entity_decode(strip_tags($abstract), ENT_COMPAT, 'UTF-8'), ENT_COMPAT, 'utf-8')));
            $parentNode->appendChild($abstractNode);
        }
    }

    /**
     * Append ai:program (AccessIndicators) node, that contains the license URL
     */
    public function appendProgramNode(DOMDocument $doc, DOMElement $parentNode, Publication $publication): void
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();

        if ($publication->getData('licenseUrl')) {
            $licenseNode = $doc->createElementNS($deployment->getAINamespace(), 'ai:program');
            $licenseNode->setAttribute('name', 'AccessIndicators');
            $licenseNode->appendChild($node = $doc->createElementNS($deployment->getAINamespace(), 'ai:license_ref', htmlspecialchars($publication->getData('licenseUrl'), ENT_COMPAT, 'UTF-8')));
            $parentNode->appendChild($licenseNode);
        }
    }

    /**
     * Append the collection node 'collection property="crawler-based"' to the doi data node.
     */
    public function appendAsCrawledCollectionNodes(DOMDocument $doc, DOMElement $doiDataNode, Publication $publication, Submission $submission, array $galleys): void
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();
        $dispatcher = $this->_getDispatcher($request);

        if (empty($galleys)) {
            $crawlerBasedCollectionNode = $doc->createElementNS($deployment->getNamespace(), 'collection');
            $crawlerBasedCollectionNode->setAttribute('property', 'crawler-based');
            $doiDataNode->appendChild($crawlerBasedCollectionNode);
        }
        foreach ($galleys as $galley) {
            if ($context->getData(Context::SETTING_DOI_VERSIONING)) {
                $resourceURL = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'download', [$publication->getData('urlPath') ?? $submission->getId(), 'version', $publication->getId(), $galley->getBestGalleyId()], null, null, true, '');
            } else {
                $resourceURL = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'download', [$publication->getData('urlPath') ?? $submission->getId(), $galley->getBestGalleyId()], null, null, true, '');
            }
            // iParadigms crawler based collection element
            $crawlerBasedCollectionNode = $doc->createElementNS($deployment->getNamespace(), 'collection');
            $crawlerBasedCollectionNode->setAttribute('property', 'crawler-based');
            $iParadigmsItemNode = $doc->createElementNS($deployment->getNamespace(), 'item');
            $iParadigmsItemNode->setAttribute('crawler', 'iParadigms');
            $iParadigmsItemNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'resource', $resourceURL));
            $crawlerBasedCollectionNode->appendChild($iParadigmsItemNode);
            $doiDataNode->appendChild($crawlerBasedCollectionNode);
        }
    }

    /**
     * Append the collection node 'collection property="text-mining"' to the doi data node.
     */
    public function appendTextMiningCollectionNodes(DOMDocument $doc, DOMElement $doiDataNode, Publication $publication, Submission $submission, array $galleys): void
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();
        $dispatcher = $this->_getDispatcher($request);

        // Check if there is at least one galley that is NOT audio or video
        $hasTextMiningCandidate = false;
        foreach ($galleys as $galley) {
            $fileType = $galley->getFileType();
            if (!$galley->getData('urlRemote') && strpos($fileType, 'audio') === false && strpos($fileType, 'video') === false) {
                $hasTextMiningCandidate = true;
                break;
            }
        }

        // If all galleys are audio/video, skip adding the text-mining node
        if (!$hasTextMiningCandidate) {
            return;
        }

        // start of the text-mining collection element
        $textMiningCollectionNode = $doc->createElementNS($deployment->getNamespace(), 'collection');
        $textMiningCollectionNode->setAttribute('property', 'text-mining');
        foreach ($galleys as $galley) {
            $fileType = $galley->getFileType();
            if ($context->getData(Context::SETTING_DOI_VERSIONING)) {
                $resourceURL = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'download', [$publication->getData('urlPath') ?? $submission->getId(), 'version', $publication->getId(), $galley->getBestGalleyId()], null, null, true, '');
            } else {
                $resourceURL = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'download', [$publication->getData('urlPath') ?? $submission->getId(), $galley->getBestGalleyId()], null, null, true, '');
            }
            // add only non-audio/video galleys to text-mining
            if (strpos($fileType, 'audio') === false && strpos($fileType, 'video') === false) {
                $textMiningItemNode = $doc->createElementNS($deployment->getNamespace(), 'item');
                $resourceNode = $doc->createElementNS($deployment->getNamespace(), 'resource', $resourceURL);

                if (!$galley->getData('urlRemote')) {
                    $resourceNode->setAttribute('mime_type', $galley->getFileType());
                }

                $textMiningItemNode->appendChild($resourceNode);
                $textMiningCollectionNode->appendChild($textMiningItemNode);
            }
        }
        $doiDataNode->appendChild($textMiningCollectionNode);
    }

    /**
     * Append citation list node.
     */
    public function appendCitationListNode(DOMDocument $doc, DOMElement $parentNode, Publication $publication): void
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();

        $citations = $publication->getData('citations');
        if ($citations) {
            $citationListNode = $doc->createElementNS($deployment->getNamespace(), 'citation_list');
            foreach ($citations as $citation) {
                /** @var Citation $citation */
                $rawCitation = $citation->getRawCitation();
                $isStructured = $citation->isStructured();
                if (!empty($rawCitation)) { // This should not be the case any more, but lets leave it here
                    $citationNode = $doc->createElementNS($deployment->getNamespace(), 'citation');
                    $citationNode->setAttribute('key', $citation->getId());
                    if ($isStructured) {
                        $this->appendStructuredCitationElements($doc, $citationNode, $citation);
                    } elseif ($citationDoi = $citation->getData('doi')) {
                        $node = $doc->createElementNS($deployment->getNamespace(), 'doi');
                        $node->appendChild($doc->createTextNode($citationDoi));
                        $citationNode->appendChild($node);
                    } else {
                        $node = $doc->createElementNS($deployment->getNamespace(), 'unstructured_citation');
                        $node->appendChild($doc->createTextNode($rawCitation));
                        $citationNode->appendChild($node);
                    }
                    $citationListNode->appendChild($citationNode);
                }
            }
            $parentNode->appendChild($citationListNode);
        }
    }

    /**
     * Append structured citation elements
     */
    public function appendStructuredCitationElements(DOMDocument$doc, DOMElement $parentNode, Citation $citation): void
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();

        if ($issn = $citation->getData('sourceIssn')) {
            $issnNode = $doc->createElementNS($deployment->getNamespace(), 'issn', $issn);
            $parentNode->appendChild($issnNode);
        }
        if (($citation->getData('sourceType') == CitationSourceType::JOURNAL->value && $journalTitle = $citation->getData('sourceName')) ||
            ($citation->getData('type') == CitationSourceType::JOURNAL->value && $journalTitle = $citation->getData('title'))) {

                $journalTitleNode = $doc->createElementNS($deployment->getNamespace(), 'journal_title');
                $journalTitleNode->appendChild($doc->createTextNode($journalTitle));
                $parentNode->appendChild($journalTitleNode);
        }
        if ($authors = $citation->getData('authors')) {
            $firstAuthorNames = $authors[0]['givenName'] ? [$authors[0]['givenName']] : [];
            if ($authors[0]['familyName'] ?? '') {
                $firstAuthorNames[] = $authors[0]['familyName'];
            }
            if (!empty($firstAuthorNames)) {
                $firstAuthorName = implode(' ', $firstAuthorNames);
                $authorNode = $doc->createElementNS($deployment->getNamespace(), 'author');
                $authorNode->appendChild($doc->createTextNode($firstAuthorName));
                $parentNode->appendChild($authorNode);
            }
        }
        if ($volume = $citation->getData('volume')) {
            $volumeNode = $doc->createElementNS($deployment->getNamespace(), 'volume', $volume);
            $parentNode->appendChild($volumeNode);
        }
        if ($issue = $citation->getData('issue')) {
            $issueNode = $doc->createElementNS($deployment->getNamespace(), 'issue', $issue);
            $parentNode->appendChild($issueNode);
        }
        if ($firstPage = $citation->getData('firstPage')) {
            $firstPageNode = $doc->createElementNS($deployment->getNamespace(), 'first_page', $firstPage);
            $parentNode->appendChild($firstPageNode);
        }
        if ($date = $citation->getData('date')) {
            $dateParsed = Carbon::parse($date);
            $cYearNode = $doc->createElementNS($deployment->getNamespace(), 'cYear', $dateParsed->year);
            $parentNode->appendChild($cYearNode);
        }
        if ($doi = $citation->getData('doi')) {
            $doiNode = $doc->createElementNS($deployment->getNamespace(), 'doi');
            $doiNode->appendChild($doc->createTextNode($doi));
            $parentNode->appendChild($doiNode);
        }
        if (($citation->getData('sourceType') == CitationSourceType::BOOK_SERIES->value && $seriesTitle = $citation->getData('sourceName')) ||
            ($citation->getData('type') == CitationType::BOOK_SERIES->value && $seriesTitle = $citation->getData('title'))) {

                $seriesTitleNode = $doc->createElementNS($deployment->getNamespace(), 'series_title');
                $seriesTitleNode->appendChild($doc->createTextNode($seriesTitle));
                $parentNode->appendChild($seriesTitleNode);
        }
        if (($citation->getData('type') == CitationType::BOOK->value || $citation->getData('type') == CitationType::MONOGRAPH->value) &&
            $volumeTitle = $citation->getData('title')) {

                $volumeTitleNode = $doc->createElementNS($deployment->getNamespace(), 'volume_title');
                $volumeTitleNode->appendChild($doc->createTextNode($volumeTitle));
                $parentNode->appendChild($volumeTitleNode);
        }
        if ($citation->getData('type') == CitationType::JOURNAL_ARTICLE->value && $articleTitle = $citation->getData('title')) {
                $articleTitleNode = $doc->createElementNS($deployment->getNamespace(), 'article_title');
                $articleTitleNode->appendChild($doc->createTextNode($articleTitle));
                $parentNode->appendChild($articleTitleNode);
        }
    }

    /**
     * Create and return component list node 'component_list'.
     */
    public function createComponentListNode(DOMDocument $doc, Publication $publication, Submission $submission, array $componentGalleys): DOMElement
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();
        $dispatcher = $this->_getDispatcher($request);

        // Create the base node
        $componentListNode = $doc->createElementNS($deployment->getNamespace(), 'component_list');
        // Run through supp files and add component nodes.
        foreach ($componentGalleys as $componentGalley) {
            $componentFile = $componentGalley->getFile();
            $componentNode = $doc->createElementNS($deployment->getNamespace(), 'component');
            $componentNode->setAttribute('parent_relation', 'isPartOf');
            /* Titles */
            $componentFileTitle = $componentFile->getData('name', $componentGalley->getLocale());
            if (!empty($componentFileTitle)) {
                $titlesNode = $doc->createElementNS($deployment->getNamespace(), 'titles');
                $titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'title', htmlspecialchars($componentFileTitle, ENT_COMPAT, 'UTF-8')));
                $componentNode->appendChild($titlesNode);
            }
            // DOI data node
            $resourceURL = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'download', [$publication->getData('urlPath') ?? $submission->getId(), $componentGalley->getBestGalleyId()], null, null, true, '');
            $componentNode->appendChild($this->createDOIDataNode($doc, $componentGalley->getStoredPubId('doi'), $resourceURL));
            $componentListNode->appendChild($componentNode);
        }
        return $componentListNode;
    }

    /**
     * Create crossmark update field, used to register the update of the previous version
     */
    public function appendCrossmarkNode(DOMDocument $doc, DOMElement $parentNode, array $versionsDois, string $datePublished): void
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();

        $crossmarkPolicyDoi = $plugin->getSetting($context->getId(), 'updatePolicyDoi');
        $crossmarkNode = $doc->createElementNS($deployment->getNamespace(), 'crossmark');
        $crossmarkNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'crossmark_policy', htmlspecialchars($crossmarkPolicyDoi, ENT_COMPAT, 'UTF-8')));
        $updatesNode = $doc->createElementNS($deployment->getNamespace(), 'updates');
        foreach ($versionsDois as $versionDoi) {
            $updateNode = $doc->createElementNS($deployment->getNamespace(), 'update', htmlspecialchars($versionDoi, ENT_COMPAT, 'UTF-8'));
            $updateNode->setAttribute('type', 'new_version');
            $updateNode->setAttribute('date', $datePublished);
            $updatesNode->appendChild($updateNode);
        }
        $crossmarkNode->appendChild($updatesNode);
        $parentNode->appendChild($crossmarkNode);
    }

    /**
     * Create relationships to previous versions
     */
    public function appendRelationships(DOMDocument $doc, DOMElement $parentNode, array $versionsDois): void
    {
		/** @var CrossrefExportDeployment $deployment */
		$deployment = $this->getDeployment();

        $programNode = $doc->createElementNS($deployment->getRelNamespace(), 'rel:program');
        foreach ($versionsDois as $versionDoi) {
            $relatedItemNode = $doc->createElementNS($deployment->getRelNamespace(), 'rel:related_item');
            $intraWorkRel = $doc->createElementNS($deployment->getRelNamespace(), 'rel:intra_work_relation', htmlspecialchars($versionDoi, ENT_COMPAT, 'UTF-8'));
            $intraWorkRel->setAttribute('relationship-type', 'isVersionOf');
            $intraWorkRel->setAttribute('identifier-type', 'doi');
            $relatedItemNode->appendChild($intraWorkRel);
            $programNode->appendChild($relatedItemNode);
        }
        $parentNode->appendChild($programNode);
    }
}
