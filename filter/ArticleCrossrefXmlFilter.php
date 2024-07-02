<?php

/**
 * @file plugins/generic/crossref/filter/ArticleCrossrefXmlFilter.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
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
use APP\submission\Submission;
use DOMDocument;
use DOMElement;
use PKP\core\PKPApplication;
use PKP\db\DAORegistry;
use PKP\i18n\LocaleConversion;
use PKP\submission\GenreDAO;

class ArticleCrossrefXmlFilter extends IssueCrossrefXmlFilter
{
    /**
     * Constructor
     *
     * @param \PKP\filter\FilterGroup $filterGroup
     */
    public function __construct($filterGroup)
    {
        parent::__construct($filterGroup);
        $this->setDisplayName('Crossref XML article export');
    }

    //
    // Submission conversion functions
    //
    /**
     * @copydoc IssueCrossrefXmlFilter::createJournalNode()
     */
    public function createJournalNode($doc, $pubObject)
    {
        $deployment = $this->getDeployment();
        $journalNode = parent::createJournalNode($doc, $pubObject);
        assert($pubObject instanceof Submission);
        $journalNode->appendChild($this->createJournalArticleNode($doc, $pubObject));
        return $journalNode;
    }

    /**
     * Create and return the journal issue node 'journal_issue'.
     *
     * @param DOMDocument $doc
     * @param Submission $submission
     *
     * @return DOMElement
     */
    public function createJournalIssueNode($doc, $submission)
    {
        /** @var CrossrefExportDeployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $cache = $deployment->getCache();
        assert($submission instanceof Submission);
        $issueId = $submission->getCurrentPublication()->getData('issueId');
        if ($cache->isCached('issues', $issueId)) {
            $issue = $cache->get('issues', $issueId);
        } else {
            $issue = Repo::issue()->get($issueId);
            $issue = $issue->getJournalId() == $context->getId() ? $issue : null;
            if ($issue) {
                $cache->add($issue, null);
            }
        }
        $journalIssueNode = parent::createJournalIssueNode($doc, $issue);
        return $journalIssueNode;
    }

    /**
     * Create and return the journal article node 'journal_article'.
     *
     * @param \DOMDocument $doc
     * @param \APP\submission\Submission $submission
     *
     * @return \DOMElement
     */
    public function createJournalArticleNode($doc, $submission)
    {
        /** @var CrossrefExportDeployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();

        $publication = $submission->getCurrentPublication();
        $locale = $publication->getData('locale');

        // Issue should be set by now
        $issue = $deployment->getIssue();

        $journalArticleNode = $doc->createElementNS($deployment->getNamespace(), 'journal_article');
        $journalArticleNode->setAttribute('publication_type', 'full_text');
        $journalArticleNode->setAttribute('language', LocaleConversion::getIso1FromLocale($locale));

        // title
        $titleLanguages = array_keys($publication->getTitles());
        // Crossref 5.3.1 limits to 20 titles maximum, ensure the primary locale is first
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
            $contributorsNode = $doc->createElementNS($deployment->getNamespace(), 'contributors');

            $isFirst = true;
            foreach ($authors as $author) { /** @var Author $author */
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
                    $personNameNode->setAttribute('language', LocaleConversion::getIso1FromLocale($locale));
                    $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'given_name', htmlspecialchars(ucfirst($givenNames[$locale]), ENT_COMPAT, 'UTF-8')));
                    $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars(ucfirst($familyNames[$locale]), ENT_COMPAT, 'UTF-8')));

                    if ($author->getData('orcid')) {
                        $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'ORCID', $author->getData('orcid')));
                    }

                    $hasAltName = false;
                    foreach ($familyNames as $otherLocal => $familyName) {
                        if ($otherLocal != $locale && isset($familyName) && !empty($familyName)) {
                            if (!$hasAltName) {
                                $altNameNode = $doc->createElementNS($deployment->getNamespace(), 'alt-name');
                                $personNameNode->appendChild($altNameNode);
                                $hasAltName = true;
                            }

                            $nameNode = $doc->createElementNS($deployment->getNamespace(), 'name');
                            $nameNode->setAttribute('language', LocaleConversion::getIso1FromLocale($otherLocal));

                            $nameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars(ucfirst($familyName), ENT_COMPAT, 'UTF-8')));
                            if (isset($givenNames[$otherLocal]) && !empty($givenNames[$otherLocal])) {
                                $nameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'given_name', htmlspecialchars(ucfirst($givenNames[$otherLocal]), ENT_COMPAT, 'UTF-8')));
                            }

                            $altNameNode->appendChild($nameNode);
                        }
                    }
                } else {
                    $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars(ucfirst($givenNames[$locale]), ENT_COMPAT, 'UTF-8')));
                    if ($author->getData('orcid')) {
                        $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'ORCID', $author->getData('orcid')));
                    }
                }

                $contributorsNode->appendChild($personNameNode);
                $isFirst = false;
            }
            $journalArticleNode->appendChild($contributorsNode);
        }

        // abstract
        $abstracts = $publication->getData('abstract') ?: [];
        foreach($abstracts as $lang => $abstract) {
            $abstractNode = $doc->createElementNS($deployment->getJATSNamespace(), 'jats:abstract');
            $abstractNode->setAttributeNS($deployment->getXMLNamespace(), 'xml:lang', LocaleConversion::getIso1FromLocale($lang));
            $abstractNode->appendChild($node = $doc->createElementNS($deployment->getJATSNamespace(), 'jats:p', htmlspecialchars(html_entity_decode(strip_tags($abstract), ENT_COMPAT, 'UTF-8'), ENT_COMPAT, 'UTF-8')));
            $journalArticleNode->appendChild($abstractNode);
        }

        // publication date
        if ($datePublished = $publication->getData('datePublished')) {
            $journalArticleNode->appendChild($this->createPublicationDateNode($doc, $datePublished));
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

        // license
        if ($publication->getData('licenseUrl')) {
            $licenseNode = $doc->createElementNS($deployment->getAINamespace(), 'ai:program');
            $licenseNode->setAttribute('name', 'AccessIndicators');
            $licenseNode->appendChild($node = $doc->createElementNS($deployment->getAINamespace(), 'ai:license_ref', htmlspecialchars($publication->getData('licenseUrl'), ENT_COMPAT, 'UTF-8')));
            $journalArticleNode->appendChild($licenseNode);
        }

        // DOI data
        $dispatcher = $this->_getDispatcher($request);
        $url = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'view', [$publication->getData('urlPath') ?? $submission->getId()], null, null, true, '');
        $doiDataNode = $this->createDOIDataNode($doc, $publication->getDoi(), $url);
        // append galleys files and collection nodes to the DOI data node
        $galleys = $publication->getData('galleys');
        // All full-texts, PDF full-texts and remote galleys for text-mining and as-crawled URL
        $submissionGalleys = $pdfGalleys = $remoteGalleys = [];
        // preferred PDF full-text for the as-crawled URL
        $pdfGalleyInArticleLocale = null;
        // get immediately also supplementary files for component list
        $componentGalleys = [];
        $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var GenreDAO $genreDao */
        foreach ($galleys as $galley) {
            // filter supp files with DOI
            if (!$galley->getData('urlRemote')) {
                $galleyFile = $galley->getFile();
                if ($galleyFile) {
                    $genre = $genreDao->getById($galleyFile->getGenreId());
                    if ($genre->getSupplementary()) {
                        if ($galley->getDoi()) {
                            // construct the array key with galley best ID and locale needed for the component node
                            $componentGalleys[] = $galley;
                        }
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
        $this->appendAsCrawledCollectionNodes($doc, $doiDataNode, $submission, $asCrawledGalleys);
        // text-mining - collection nodes
        $submissionGalleys = array_merge($submissionGalleys, $remoteGalleys);
        $this->appendTextMiningCollectionNodes($doc, $doiDataNode, $submission, $submissionGalleys);
        $journalArticleNode->appendChild($doiDataNode);

        // component list (supplementary files)
        if (!empty($componentGalleys)) {
            $journalArticleNode->appendChild($this->createComponentListNode($doc, $submission, $componentGalleys));
        }

        return $journalArticleNode;
    }

    /**
     * Append the collection node 'collection property="crawler-based"' to the doi data node.
     *
     * @param \DOMDocument $doc
     * @param \DOMElement $doiDataNode
     * @param \APP\submission\Submission $submission
     * @param array $galleys of \PKP\galley\Galley objects
     */
    public function appendAsCrawledCollectionNodes($doc, $doiDataNode, $submission, $galleys)
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();
        $publication = $submission->getCurrentPublication();
        $dispatcher = $this->_getDispatcher($request);

        if (empty($galleys)) {
            $crawlerBasedCollectionNode = $doc->createElementNS($deployment->getNamespace(), 'collection');
            $crawlerBasedCollectionNode->setAttribute('property', 'crawler-based');
            $doiDataNode->appendChild($crawlerBasedCollectionNode);
        }
        foreach ($galleys as $galley) {
            $resourceURL = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'download', [$publication->getData('urlPath') ?? $submission->getId(), $galley->getBestGalleyId()], null, null, true, '');
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
     *
     * @param \DOMDocument $doc
     * @param \DOMElement $doiDataNode
     * @param \APP\submission\Submission $submission
     * @param array $galleys of \PKP\galley\Galley objects
     */
    public function appendTextMiningCollectionNodes($doc, $doiDataNode, $submission, $galleys)
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();
        $dispatcher = $this->_getDispatcher($request);
        $publication = $submission->getCurrentPublication();

        // start of the text-mining collection element
        $textMiningCollectionNode = $doc->createElementNS($deployment->getNamespace(), 'collection');
        $textMiningCollectionNode->setAttribute('property', 'text-mining');
        foreach ($galleys as $galley) {
            $resourceURL = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'article', 'download', [$publication->getData('urlPath') ?? $submission->getId(), $galley->getBestGalleyId()], null, null, true, ''); // text-mining collection item
            $textMiningItemNode = $doc->createElementNS($deployment->getNamespace(), 'item');
            $resourceNode = $doc->createElementNS($deployment->getNamespace(), 'resource', $resourceURL);
            if (!$galley->getData('urlRemote')) {
                $resourceNode->setAttribute('mime_type', $galley->getFileType());
            }
            $textMiningItemNode->appendChild($resourceNode);
            $textMiningCollectionNode->appendChild($textMiningItemNode);
        }
        $doiDataNode->appendChild($textMiningCollectionNode);
    }

    /**
     * Create and return component list node 'component_list'.
     *
     * @param \DOMDocument $doc
     * @param \APP\submission\Submission $submission
     * @param array $componentGalleys
     *
     * @return \DOMElement
     */
    public function createComponentListNode($doc, $submission, $componentGalleys)
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();
        $publication = $submission->getCurrentPublication();
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
}
