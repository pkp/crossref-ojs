<?php

/**
 * @file plugins/generic/crossref/filter/IssueCrossrefXmlFilter.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class IssueCrossrefXmlFilter
 * @ingroup plugins_generic_crossref
 *
 * @brief Class that converts an Issue to a Crossref XML document.
 */

use APP\core\Application;
use PKP\core\PKPApplication;

import('lib.pkp.plugins.importexport.native.filter.NativeExportFilter');

class IssueCrossrefXmlFilter extends NativeExportFilter
{
    /**
     * Constructor
     * @param FilterGroup $filterGroup
     */
    public function __construct($filterGroup)
    {
        $this->setDisplayName('Crossref XML issue export');
        parent::__construct($filterGroup);
    }

    //
    // Implement template methods from PersistableFilter
    //
    /**
     * @copydoc PersistableFilter::getClassName()
     */
    public function getClassName()
    {
        return 'plugins.generic.crossref.filter.IssueCrossrefXmlFilter';
    }

    //
    // Implement template methods from Filter
    //
    /**
     * @see Filter::process()
     * @param array $pubObjects Array of Issues or Submissions
     * @return DOMDocument
     */
    public function &process(&$pubObjects)
    {
        // Create the XML document
        $doc = new DOMDocument('1.0', 'utf-8');
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
            // pubObject is either Issue or Submission
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
     * @param DOMDocument $doc
     * @return DOMElement
     */
    public function createRootNode($doc)
    {
        $deployment = $this->getDeployment();
        $rootNode = $doc->createElementNS($deployment->getNamespace(), $deployment->getRootElementName());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', $deployment->getXmlSchemaInstance());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:jats', $deployment->getJATSNamespace());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ai', $deployment->getAINamespace());
        $rootNode->setAttribute('version', $deployment->getXmlSchemaVersion());
        $rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());
        return $rootNode;
    }

    /**
     * Create and return the head node 'head'.
     * @param DOMDocument $doc
     * @return DOMElement
     */
    public function createHeadNode($doc)
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();
        $headNode = $doc->createElementNS($deployment->getNamespace(), 'head');
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'doi_batch_id', htmlspecialchars($context->getData('initials', $context->getPrimaryLocale()) . '_' . time(), ENT_COMPAT, 'UTF-8')));
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'timestamp', time()));
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
     * @param DOMDocument $doc
     * @param object $pubObject Issue or Submission
     * @return DOMElement
     */
    public function createJournalNode($doc, $pubObject)
    {
        $deployment = $this->getDeployment();
        $journalNode = $doc->createElementNS($deployment->getNamespace(), 'journal');
        $journalNode->appendChild($this->createJournalMetadataNode($doc));
        $journalNode->appendChild($this->createJournalIssueNode($doc, $pubObject));
        return $journalNode;
    }

    /**
     * Create and return the journal metadata node 'journal_metadata'.
     * @param DOMDocument $doc
     * @return DOMElement
     */
    public function createJournalMetadataNode($doc)
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
        /* Both ISSNs are permitted for CrossRef, so sending whichever one (or both) */
        if ($ISSN = $context->getData('onlineIssn')) {
            $journalMetadataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'issn', $ISSN));
            $node->setAttribute('media_type', 'electronic');
        }
        /* Both ISSNs are permitted for CrossRef so sending whichever one (or both) */
        if ($ISSN = $context->getData('printIssn')) {
            $journalMetadataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'issn', $ISSN));
            $node->setAttribute('media_type', 'print');
        }
        return $journalMetadataNode;
    }

    /**
     * Create and return the journal issue node 'journal_issue'.
     * @param DOMDocument $doc
     * @param Issue $issue
     * @return DOMElement
     */
    public function createJournalIssueNode($doc, $issue)
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $deployment->setIssue($issue);

        $journalIssueNode = $doc->createElementNS($deployment->getNamespace(), 'journal_issue');
        if ($issue->getDatePublished()) {
            $journalIssueNode->appendChild($this->createPublicationDateNode($doc, $issue->getDatePublished()));
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
            $url = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'issue', 'view', $issue->getBestIssueId($context), null, null, true);
            $journalIssueNode->appendChild($this->createDOIDataNode($doc, $issue->getDoi(), $url));
        }
        return $journalIssueNode;
    }

    /**
     * Create and return the publication date node 'publication_date'.
     * @param DOMDocument $doc
     * @param string $objectPublicationDate
     * @return DOMElement
     */
    public function createPublicationDateNode($doc, $objectPublicationDate)
    {
        $deployment = $this->getDeployment();
        $publicationDate = strtotime($objectPublicationDate);
        $publicationDateNode = $doc->createElementNS($deployment->getNamespace(), 'publication_date');
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
     * @param DOMDocument $doc
     * @param string $doi
     * @param string $url
     * @return DOMElement
     */
    public function createDOIDataNode($doc, $doi, $url)
    {
        $deployment = $this->getDeployment();
        $doiDataNode = $doc->createElementNS($deployment->getNamespace(), 'doi_data');
        $doiDataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'doi', htmlspecialchars($doi, ENT_COMPAT, 'UTF-8')));
        $doiDataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'resource', $url));
        return $doiDataNode;
    }

    /**
     * Helper to ensure dispatcher is available even when called from CLI tools
     *
     * @param \APP\core\Request $request
     *
     * @return \PKP\core\Dispatcher
     */
    protected function _getDispatcher(\APP\core\Request $request): \PKP\core\Dispatcher
    {
        $dispatcher = $request->getDispatcher();
        if ($dispatcher === null) {
            $dispatcher = \APP\core\Application::get()->getDispatcher();
        }

        return $dispatcher;
    }
}
