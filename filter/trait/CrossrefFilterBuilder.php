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

    /**
     * Append stored HTML (e.g. a publication title) to a node as Crossref face markup.
     *
     * The inline tags Crossref accepts (b, i, u, sup, sub) are kept as elements,
     * strong and em become b and i, any other tag is dropped and its text kept.
     * Text is decoded by the HTML parser and escaped once when the document is written.
     */
    public function appendFaceMarkup(DOMDocument $doc, DOMElement $parent, string $html): void
    {
        if ($html === '') {
            return;
        }
        $source = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $source->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $source->getElementsByTagName('body')->item(0);
        if ($body) {
            $this->copyFaceMarkup($doc, $parent, $body);
        }
    }

    /**
     * Copy the children of an HTML node into a Crossref node, see appendFaceMarkup().
     */
    private function copyFaceMarkup(DOMDocument $doc, DOMElement $parent, \DOMNode $from): void
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $faces = ['b' => 'b', 'strong' => 'b', 'i' => 'i', 'em' => 'i', 'u' => 'u', 'sup' => 'sup', 'sub' => 'sub'];
        foreach ($from->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $parent->appendChild($doc->createTextNode($child->nodeValue));
            } elseif ($child instanceof DOMElement) {
                $face = $faces[strtolower($child->nodeName)] ?? null;
                if ($face) {
                    $parent->appendChild($node = $doc->createElementNS($deployment->getNamespace(), $face));
                    $this->copyFaceMarkup($doc, $node, $child);
                } else {
                    $this->copyFaceMarkup($doc, $parent, $child);
                }
            }
        }
    }
}
