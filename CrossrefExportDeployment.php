<?php

/**
 * @file plugins/generic/crossref/CrossrefExportDeployment.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2000-2025 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossrefExportDeployment
 *
 * @brief Base class configuring the crossref export process to an
 * application's specifics.
 */

namespace APP\plugins\generic\crossref;

use APP\issue\Issue;
use APP\plugins\PubObjectCache;
use PKP\context\Context;

class CrossrefExportDeployment
{
    // XML attributes
    public const CROSSREF_XMLNS = 'http://www.crossref.org/schema/5.4.0';
    public const CROSSREF_XMLNS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';
    public const CROSSREF_XSI_SCHEMAVERSION = '5.4.0';
    public const CROSSREF_XSI_SCHEMALOCATION = 'https://www.crossref.org/schemas/crossref5.4.0.xsd';
    public const CROSSREF_XMLNS_JATS = 'http://www.ncbi.nlm.nih.gov/JATS1';
    public const CROSSREF_XMLNS_AI = 'http://www.crossref.org/AccessIndicators.xsd';
    public const CROSSREF_XMLNS_XML = 'http://www.w3.org/XML/1998/namespace';
    public const CROSSREF_XMLNS_REL = 'http://www.crossref.org/relations.xsd';

    public Context $_context;

    public CrossrefExportPlugin $_plugin;

    public Issue $_issue;

    /**
     * Get plugin cache
     */
    public function getCache(): PubObjectCache
    {
        return $this->_plugin->getCache();
    }

    /**
     * Constructor
     */
    public function __construct(Context $context, CrossrefExportPlugin $plugin)
    {
        $this->setContext($context);
        $this->setPlugin($plugin);
    }

    //
    // Deployment items for subclasses to override
    //
    /**
     * Get the root element name
     */
    public function getRootElementName(): string
    {
        return 'doi_batch';
    }

    /**
     * Get the namespace URN
     */
    public function getNamespace(): string
    {
        return static::CROSSREF_XMLNS;
    }

    /**
     * Get the schema instance URN
     */
    public function getXmlSchemaInstance(): string
    {
        return static::CROSSREF_XMLNS_XSI;
    }

    /**
     * Get the schema version
     */
    public function getXmlSchemaVersion(): string
    {
        return static::CROSSREF_XSI_SCHEMAVERSION;
    }

    /**
     * Get the schema location URL
     */
    public function getXmlSchemaLocation(): string
    {
        return static::CROSSREF_XSI_SCHEMALOCATION;
    }

    /**
     * Get the JATS namespace URN
     */
    public function getJATSNamespace(): string
    {
        return static::CROSSREF_XMLNS_JATS;
    }

    /**
     * Get the access indicators namespace URN
     */
    public function getAINamespace(): string
    {
        return static::CROSSREF_XMLNS_AI;
    }

    /**
     * Get the XML namespace URN
     */
    public function getXMLNamespace(): string
    {
        return static::CROSSREF_XMLNS_XML;
    }

    /**
     * Get the XML namespace URN
     */
    public function getRelNamespace(): string
    {
        return static::CROSSREF_XMLNS_REL;
    }

    /**
     * Get the schema filename.
     */
    public function getSchemaFilename(): string
    {
        return $this->getXmlSchemaLocation();
    }

    /**
     * Set the import/export context.
     */
    public function setContext(Context $context): void
    {
        $this->_context = $context;
    }

    /**
     * Get the import/export context.
     */
    public function getContext(): Context
    {
        return $this->_context;
    }

    /**
     * Set the import/export plugin.
     */
    public function setPlugin(CrossrefExportPlugin $plugin): void
    {
        $this->_plugin = $plugin;
    }

    /**
     * Get the import/export plugin.
     */
    public function getPlugin(): CrossrefExportPlugin
    {
        return $this->_plugin;
    }

    /**
     * Set the import/export issue.
     */
    public function setIssue(Issue $issue): void
    {
        $this->_issue = $issue;
    }

    /**
     * Get the import/export issue.
     */
    public function getIssue(): Issue
    {
        return $this->_issue;
    }
}
