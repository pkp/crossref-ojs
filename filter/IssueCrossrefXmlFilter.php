<?php

/**
 * @file plugins/generic/crossref/filter/IssueCrossrefXmlFilter.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2000-2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class IssueCrossrefXmlFilter
 *
 * @brief Class that converts an Issue to a Crossref XML document.
 */

namespace APP\plugins\generic\crossref\filter;

use APP\core\Application;
use APP\core\Request;
use APP\issue\Issue;
use APP\plugins\generic\crossref\CrossrefExportDeployment;
use APP\plugins\generic\crossref\filter\trait\CrossrefFilterBuilder;
use DOMDocument;
use DOMElement;
use PKP\core\Dispatcher;
use PKP\core\PKPApplication;

class IssueCrossrefXmlFilter extends \PKP\plugins\importexport\native\filter\NativeExportFilter
{
    use CrossrefFilterBuilder;

    /**
     * Constructor
     *
     * @param \PKP\filter\FilterGroup $filterGroup
     */
    public function __construct($filterGroup)
    {
        $this->setDisplayName('Crossref XML issue export');
        parent::__construct($filterGroup);
    }

    //
    // Implement template methods from Filter
    //
    /**
     * @see \PKP\filter\Filter::process()
     *
     * @param array $pubObjects Array of Issues
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
            $journalNode = $this->createJournalNode($doc, $pubObject);
            $bodyNode->appendChild($journalNode);
        }
        return $doc;
    }

    //
    // Issue conversion functions
    //
    /**
     * Create and return the journal node 'journal'.
     */
    public function createJournalNode(DOMDocument $doc, Issue $pubObject): DOMElement
    {
        $deployment = $this->getDeployment();
        $journalNode = $doc->createElementNS($deployment->getNamespace(), 'journal');
        $journalNode->appendChild($this->createJournalMetadataNode($doc));
        $journalNode->appendChild($this->createJournalIssueNode($doc, $pubObject));
        return $journalNode;
    }

    /**
     * Create and return the journal metadata node 'journal_metadata'.
     */
    public function createJournalMetadataNode(DOMDocument $doc): DOMElement
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        $journalMetadataNode = $doc->createElementNS($deployment->getNamespace(), 'journal_metadata');
        // Full title
        $journalTitle = $context->getName($context->getPrimaryLocale());
        // Fall back to the journal abbreviation if the full title is not set in the primary locale.
        if ($journalTitle == '') {
            $journalTitle = $context->getData('abbreviation', $context->getPrimaryLocale());
        }
        $journalMetadataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'full_title', htmlspecialchars($journalTitle, ENT_COMPAT, 'UTF-8')));
        // Abbreviated title — falling back to the journal acronym if no abbreviation is set.
        $journalAbbrev = $context->getData('abbreviation', $context->getPrimaryLocale());
        if ($journalAbbrev == '') {
            $journalAbbrev = $context->getData('acronym', $context->getPrimaryLocale());
        }
        $journalMetadataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'abbrev_title', htmlspecialchars($journalAbbrev, ENT_COMPAT, 'UTF-8')));
        // Both online and print ISSNs are permitted by Crossref — send whichever are available.
        if ($ISSN = $context->getData('onlineIssn')) {
            $journalMetadataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'issn', $ISSN));
            $node->setAttribute('media_type', 'electronic');
        }
        if ($ISSN = $context->getData('printIssn')) {
            $journalMetadataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'issn', $ISSN));
            $node->setAttribute('media_type', 'print');
        }
        return $journalMetadataNode;
    }

    /**
     * Create and return the journal issue node 'journal_issue'.
     */
    public function createJournalIssueNode(DOMDocument $doc, Issue $issue): DOMElement
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $deployment->setIssue($issue);

        $journalIssueNode = $doc->createElementNS($deployment->getNamespace(), 'journal_issue');
        if ($issue->getDatePublished()) {
            $journalIssueNode->appendChild($this->createDateNode($doc, $issue->getDatePublished(), 'publication_date'));
        }
        if ($issue->getVolume() && $issue->getShowVolume()) {
            $journalVolumeNode = $doc->createElementNS($deployment->getNamespace(), 'journal_volume');
            $journalVolumeNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'volume', htmlspecialchars($issue->getVolume(), ENT_COMPAT, 'UTF-8')));
            $journalIssueNode->appendChild($journalVolumeNode);
        }
        if ($issue->getNumber() && $issue->getShowNumber()) {
            $journalIssueNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'issue', htmlspecialchars($issue->getNumber(), ENT_COMPAT, 'UTF-8')));
        }
        if ($issue->getDatePublished() && $issue->hasDoi()) {
            $request = Application::get()->getRequest();
            $dispatcher = $this->_getDispatcher($request);
            $url = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'issue', 'view', [$issue->getBestIssueId()], null, null, true, '');
            $journalIssueNode->appendChild($this->createDOIDataNode($doc, $issue->getDoi(), $url));
        }
        return $journalIssueNode;
    }

    /**
     * Create and return the given date node
     */
    public function createDateNode(DOMDocument $doc, string $objectPublicationDate, string $elementName): DOMElement
    {
        $deployment = $this->getDeployment();
        $publicationDate = strtotime($objectPublicationDate);
        $publicationDateNode = $doc->createElementNS($deployment->getNamespace(), $elementName);
        $publicationDateNode->setAttribute('media_type', 'online');
        if (date('m', $publicationDate)) {
            $publicationDateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'month', date('m', $publicationDate)));
        }
        if (date('d', $publicationDate)) {
            $publicationDateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'day', date('d', $publicationDate)));
        }
        $publicationDateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'year', date('Y', $publicationDate)));
        return $publicationDateNode;
    }
}
