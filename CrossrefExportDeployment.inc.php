<?php
/**
 * @defgroup plugins_generic_crossref Crossref export plugin
 */

/**
 * @file plugins/generic/crossref/CrossrefExportDeployment.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossrefExportDeployment
 * @ingroup plugins_generic_crossref
 *
 * @brief Base class configuring the crossref export process to an
 * application's specifics.
 */

// XML attributes
define('CROSSREF_XMLNS', 'http://www.crossref.org/schema/4.3.6');
define('CROSSREF_XMLNS_XSI', 'http://www.w3.org/2001/XMLSchema-instance');
define('CROSSREF_XSI_SCHEMAVERSION', '4.3.6');
define('CROSSREF_XSI_SCHEMALOCATION', 'https://www.crossref.org/schemas/crossref4.3.6.xsd');
define('CROSSREF_XMLNS_JATS', 'http://www.ncbi.nlm.nih.gov/JATS1');
define('CROSSREF_XMLNS_AI', 'http://www.crossref.org/AccessIndicators.xsd');

class CrossrefExportDeployment
{
    /** @var Context The current import/export context */
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
     * @param Context $context
     * @param DOIPubIdExportPlugin $plugin
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
     * @return string
     */
    public function getRootElementName()
    {
        return 'doi_batch';
    }

    /**
     * Get the namespace URN
     * @return string
     */
    public function getNamespace()
    {
        return CROSSREF_XMLNS;
    }

    /**
     * Get the schema instance URN
     * @return string
     */
    public function getXmlSchemaInstance()
    {
        return CROSSREF_XMLNS_XSI;
    }

    /**
     * Get the schema version
     * @return string
     */
    public function getXmlSchemaVersion()
    {
        return CROSSREF_XSI_SCHEMAVERSION;
    }

    /**
     * Get the schema location URL
     * @return string
     */
    public function getXmlSchemaLocation()
    {
        return CROSSREF_XSI_SCHEMALOCATION;
    }

    /**
     * Get the JATS namespace URN
     * @return string
     */
    public function getJATSNamespace()
    {
        return CROSSREF_XMLNS_JATS;
    }

    /**
     * Get the access indicators namespace URN
     * @return string
     */
    public function getAINamespace()
    {
        return CROSSREF_XMLNS_AI;
    }

    /**
     * Get the schema filename.
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
     * @param Context $context
     */
    public function setContext($context)
    {
        $this->_context = $context;
    }

    /**
     * Get the import/export context.
     * @return Context
     */
    public function getContext()
    {
        return $this->_context;
    }

    /**
     * Set the import/export plugin.
     * @param ImportExportPlugin $plugin
     */
    public function setPlugin($plugin)
    {
        $this->_plugin = $plugin;
    }

    /**
     * Get the import/export plugin.
     * @return ImportExportPlugin
     */
    public function getPlugin()
    {
        return $this->_plugin;
    }

    /**
     * Set the import/export issue.
     * @param Issue $issue
     */
    public function setIssue($issue)
    {
        $this->_issue = $issue;
    }

    /**
     * Get the import/export issue.
     * @return Issue
     */
    public function getIssue()
    {
        return $this->_issue;
    }
}
