<?php

/**
 * @file plugins/generic/crossref/classes/form/CrossRefSettingsForm.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossRefSettingsForm
 * @ingroup plugins_generic_crossref
 *
 * @brief Form for journal managers to setup CrossRef plugin
 */

use PKP\form\Form;

class CrossRefSettingsForm extends Form
{

    //
    // Private properties
    //
    /** @var integer */
    public $_contextId;

    /**
     * Get the context ID.
     * @return integer
     */
    public function _getContextId()
    {
        return $this->_contextId;
    }

    /** @var CrossRefExportPlugin */
    public $_plugin;

    /**
     * Get the plugin.
     * @return CrossRefExportPlugin
     */
    public function _getPlugin()
    {
        return $this->_plugin;
    }


    //
    // Constructor
    //
    /**
     * Constructor
     * @param \PKP\plugins\Plugin $plugin
     * @param integer $contextId
     */
    public function __construct($plugin, $contextId)
    {
        $this->_contextId = $contextId;
        $this->_plugin = $plugin;

        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        // Add form validation checks.
        $this->addCheck(new \PKP\form\validation\FormValidator($this, 'depositorName', 'required', 'plugins.importexport.crossref.settings.form.depositorNameRequired'));
        $this->addCheck(new \PKP\form\validation\FormValidatorEmail($this, 'depositorEmail', 'required', 'plugins.importexport.crossref.settings.form.depositorEmailRequired'));
        $this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
        $this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));
    }


    //
    // Implement template methods from Form
    //
    /**
     * @copydoc Form::initData()
     */
    public function initData()
    {
        $contextId = $this->_getContextId();
        $plugin = $this->_getPlugin();
        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $this->setData($fieldName, $plugin->getSetting($contextId, $fieldName));
        }
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars(array_keys($this->getFormFields()));
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $plugin = $this->_getPlugin();
        $contextId = $this->_getContextId();
        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $plugin->updateSetting($contextId, $fieldName, $this->getData($fieldName), $fieldType);
        }
        parent::execute(...$functionArgs);
    }


    //
    // Public helper methods
    //
    /**
     * Get form fields
     * @return array (field name => field type)
     */
    public function getFormFields()
    {
        return [
            'depositorName' => 'string',
            'depositorEmail' => 'string',
            'username' => 'string',
            'password' => 'string',
            'automaticRegistration' => 'bool',
            'testMode' => 'bool'
        ];
    }

    /**
     * Is the form field optional
     * @param string $settingName
     * @return boolean
     */
    public function isOptional($settingName)
    {
        return in_array($settingName, ['username', 'password', 'automaticRegistration', 'testMode']);
    }
}
