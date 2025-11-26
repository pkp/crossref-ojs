<?php

/**
 * @file plugins/generic/crossref/filter/IssueCrossrefXmlFilter.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2000-2025 John Willinsky
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
use DOMDocument;
use DOMElement;
use PKP\core\Dispatcher;
use PKP\core\PKPApplication;

class IssueCrossrefXmlFilter extends \PKP\plugins\importexport\native\filter\NativeExportFilter
{
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
     * Create and return the root node 'doi_batch'.
     */
    public function createRootNode(DOMDocument $doc): DOMElement
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $rootNode = $doc->createElementNS($deployment->getNamespace(), $deployment->getRootElementName());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', $deployment->getXmlSchemaInstance());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:jats', $deployment->getJATSNamespace());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ai', $deployment->getAINamespace());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:rel', $deployment->getRelNamespace());
        $rootNode->setAttribute('version', $deployment->getXmlSchemaVersion());
        $rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());
        return $rootNode;
    }

    /**
     * Create and return the head node 'head'.
     */
    public function createHeadNode(DOMDocument $doc): DOMElement
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();
        $headNode = $doc->createElementNS($deployment->getNamespace(), 'head');
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'doi_batch_id', htmlspecialchars($context->getData('acronym', $context->getPrimaryLocale()) . '_' . time(), ENT_COMPAT, 'UTF-8')));
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'timestamp', date('YmdHisv')));
        $depositorNode = $doc->createElementNS($deployment->getNamespace(), 'depositor');
        $depositorName = $plugin->getSetting($context->getId(), 'depositorName');
        if (empty($depositorName)) {
            $depositorName = $context->getData('supportName');
        }
        $depositorEmail = $plugin->getSetting($context->getId(), 'depositorEmail');
        if (empty($depositorEmail)) {
            $depositorEmail = $context->getData('supportEmail');
        }
        $depositorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'depositor_name', htmlspecialchars($depositorName, ENT_COMPAT, 'UTF-8')));
        $depositorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'email_address', htmlspecialchars($depositorEmail, ENT_COMPAT, 'UTF-8')));
        $headNode->appendChild($depositorNode);
        $publisherInstitution = $context->getData('publisherInstitution');
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'registrant', htmlspecialchars($publisherInstitution, ENT_COMPAT, 'UTF-8')));
        return $headNode;
    }

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
        // Attempt a fall back, in case the localized name is not set.
        if ($journalTitle == '') {
            $journalTitle = $context->getData('abbreviation', $context->getPrimaryLocale());
        }
        $journalMetadataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'full_title', htmlspecialchars($journalTitle, ENT_COMPAT, 'UTF-8')));
        /* Abbreviated title - defaulting to initials if no abbreviation found */
        $journalAbbrev = $context->getData('abbreviation', $context->getPrimaryLocale());
        if ($journalAbbrev == '') {
            $journalAbbrev = $context->getData('acronym', $context->getPrimaryLocale());
        }
        $journalMetadataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'abbrev_title', htmlspecialchars($journalAbbrev, ENT_COMPAT, 'UTF-8')));
        /* Both ISSNs are permitted for Crossref, so sending whichever one (or both) */
        if ($ISSN = $context->getData('onlineIssn')) {
            $journalMetadataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'issn', $ISSN));
            $node->setAttribute('media_type', 'electronic');
        }
        /* Both ISSNs are permitted for Crossref so sending whichever one (or both) */
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

    /**
     * Create and return the DOI date node 'doi_data'.
     */
    public function createDOIDataNode(DOMDocument $doc, string $doi, string $url): DOMElement
    {
        $deployment = $this->getDeployment();
        $doiDataNode = $doc->createElementNS($deployment->getNamespace(), 'doi_data');
        $doiDataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'doi', htmlspecialchars($doi, ENT_COMPAT, 'UTF-8')));
        $doiDataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'resource', $url));
        return $doiDataNode;
    }

    /**
     * Helper to ensure dispatcher is available even when called from CLI tools
     */
    protected function _getDispatcher(Request $request): Dispatcher
    {
        $dispatcher = $request->getDispatcher();
        if ($dispatcher === null) {
            $dispatcher = Application::get()->getDispatcher();
        }
        return $dispatcher;
    }
}
