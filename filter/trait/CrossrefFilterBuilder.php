<?php

/**
 * @file plugins/generic/crossref/filter/trait/CrossrefFilterBuilder.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossrefFilterBuilder
 * @brief Trait with common methods used in the construction of Crossref XML filters.
 *
 */
namespace APP\plugins\generic\crossref\filter\trait;

use APP\core\Application;
use APP\core\Request;
use APP\plugins\generic\crossref\CrossrefExportDeployment;
use DOMDocument;
use DOMElement;
use PKP\core\Dispatcher;

trait CrossrefFilterBuilder
{
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
     * Create and return the DOI data node 'doi_data'.
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
        $dispatcher = $request?->getDispatcher();
        if ($dispatcher === null) {
            $dispatcher = Application::get()->getDispatcher();
        }
        return $dispatcher;
    }

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
}
