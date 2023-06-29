<?php

/**
 * @file plugins/generic/crossref/CrossrefExportDeployment.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2000-2022 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossrefExportDeployment
 *
 * @brief Base class configuring the crossref export process to an
 * application's specifics.
 */

namespace APP\plugins\generic\crossref;
use APP\issue\Issue;
use APP\journal\Journal;
use PKP\plugins\Plugin;

class CrossrefExportDeployment
{
    // XML attributes
    public const CROSSREF_XMLNS = 'http://www.crossref.org/schema/5.3.1';
    public const CROSSREF_XMLNS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';
    public const CROSSREF_XSI_SCHEMAVERSION = '5.3.1';
    public const CROSSREF_XSI_SCHEMALOCATION = 'https://www.crossref.org/schemas/crossref5.3.1.xsd';
    public const CROSSREF_XMLNS_JATS = 'http://www.ncbi.nlm.nih.gov/JATS1';
    public const CROSSREF_XMLNS_AI = 'http://www.crossref.org/AccessIndicators.xsd';
    public const CROSSREF_XMLNS_XML = 'http://www.w3.org/XML/1998/namespace';

    /** @var Journal The current import/export context */
    public $_context;

    /** @var Plugin The current import/export plugin */
    public $_plugin;

    /** @var Issue */
    public $_issue;

    public function getCache()
    {
        return $this->_plugin->getCache();
    }

    /**
     * Constructor
     *
     * @param \PKP\context\Context $context
     * @param \PKP\plugins\Plugin $plugin
     */
    public function __construct($context, $plugin)
    {
        $this->setContext($context);
        $this->setPlugin($plugin);
    }

    //
    // Deployment items for subclasses to override
    //
    /**
     * Get the root element name
     *
     * @return string
     */
    public function getRootElementName()
    {
        return 'doi_batch';
    }

    /**
     * Get the namespace URN
     *
     * @return string
     */
    public function getNamespace()
    {
        return static::CROSSREF_XMLNS;
    }

    /**
     * Get the schema instance URN
     *
     * @return string
     */
    public function getXmlSchemaInstance()
    {
        return static::CROSSREF_XMLNS_XSI;
    }

    /**
     * Get the schema version
     *
     * @return string
     */
    public function getXmlSchemaVersion()
    {
        return static::CROSSREF_XSI_SCHEMAVERSION;
    }

    /**
     * Get the schema location URL
     *
     * @return string
     */
    public function getXmlSchemaLocation()
    {
        return static::CROSSREF_XSI_SCHEMALOCATION;
    }

    /**
     * Get the JATS namespace URN
     *
     * @return string
     */
    public function getJATSNamespace()
    {
        return static::CROSSREF_XMLNS_JATS;
    }

    /**
     * Get the access indicators namespace URN
     *
     * @return string
     */
    public function getAINamespace()
    {
        return static::CROSSREF_XMLNS_AI;
    }

    /**
     * Get the XML namespace URN
     *
     * @return string
     */
    public function getXMLNamespace()
    {
        return static::CROSSREF_XMLNS_XML;
    }

    /**
     * Get the schema filename.
     *
     * @return string
     */
    public function getSchemaFilename()
    {
        return $this->getXmlSchemaLocation();
    }

    //
    // Getter/setters
    //
    /**
     * Set the import/export context.
     *
     * @param \PKP\context\Context $context
     */
    public function setContext($context)
    {
        $this->_context = $context;
    }

    /**
     * Get the import/export context.
     *
     * @return \PKP\context\Context
     */
    public function getContext()
    {
        return $this->_context;
    }

    /**
     * Set the import/export plugin.
     *
     * @param \PKP\plugins\Plugin $plugin
     */
    public function setPlugin($plugin)
    {
        $this->_plugin = $plugin;
    }

    /**
     * Get the import/export plugin.
     *
     * @return \PKP\plugins\Plugin
     */
    public function getPlugin()
    {
        return $this->_plugin;
    }

    /**
     * Set the import/export issue.
     *
     * @param \APP\issue\Issue $issue
     */
    public function setIssue($issue)
    {
        $this->_issue = $issue;
    }

    /**
     * Get the import/export issue.
     *
     * @return \APP\issue\Issue
     */
    public function getIssue()
    {
        return $this->_issue;
    }
}
