<?php

/**
 * @file plugins/generic/crossref/CrossRefPlugin.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossRefPlugin
 * @brief Plugin to let managers deposit DOIs and metadata to Crossref
 *
 */

namespace APP\plugins\generic\crossref;

use APP\core\Application;
use APP\core\Services;
use APP\plugins\generic\crossref\classes\CrossrefSettings;
use APP\plugins\IDoiRegistrationAgency;
use Illuminate\Support\Collection;
use PKP\context\Context;
use PKP\doi\RegistrationAgencySettings;
use PKP\form\Form;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\services\PKPSchemaService;

class CrossRefPlugin extends GenericPlugin implements IDoiRegistrationAgency
{

    private CrossrefSettings $_settingsObject;
    private ?CrossRefExportPlugin $_exportPlugin = null;

    public function getDisplayName() : string
    {
        return __('plugins.generic.crossref.displayName');
    }

    public function getDescription() : string
    {
        return __('plugins.generic.crossref.description');
    }

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success) {
            // If the system isn't installed, or is performing an upgrade, don't
            // register hooks. This will prevent DB access attempts before the
            // schema is installed.
            if (Application::isUnderMaintenance()) {
                return true;
            }

            if ($this->getEnabled($mainContextId)) {
                $this->_pluginInitialization();
            }
        }

        return $success;
    }

    /**
     * Remove plugin as configured registration agency if set at the time plugin is disabled.
     *
     * @copydoc LazyLoadPlugin::setEnabled()
     */
    public function setEnabled($enabled)
    {
        parent::setEnabled($enabled);
        if (!$enabled) {
            $contextId = $this->getCurrentContextId();
            /** @var \PKP\context\ContextDAO $contextDao */
            $contextDao = \APP\core\Application::getContextDAO();
            $context = $contextDao->getById($contextId);
            if ($context->getData(Context::SETTING_CONFIGURED_REGISTRATION_AGENCY) === $this->getName()) {
                $context->setData(Context::SETTING_CONFIGURED_REGISTRATION_AGENCY, Context::SETTING_NO_REGISTRATION_AGENCY);
                $contextDao->updateObject($context);
            }
        }
    }

    /**
     * Helper to register hooks that are used in normal plugin setup and in CLI tool usage.
     */
    private function _pluginInitialization()
    {
        PluginRegistry::register('importexport', new CrossRefExportPlugin($this), $this->getPluginPath());

        Hook::add('DoiSettingsForm::setEnabledRegistrationAgencies', [$this, 'addAsRegistrationAgencyOption']);
        Hook::add('Schema::get::doi', [$this, 'addToSchema']);

        Hook::add('Doi::markRegistered', [$this, 'editMarkRegisteredParams']);
        Hook::add('DoiListPanel::setConfig', [$this, 'addRegistrationAgencyName']);
    }

    /**
     * Add properties for Crossref to the DOI entity for storage in the database.
     *
     * @param string $hookName `Schema::get::doi`
     * @param array $args [
     *      @option stdClass $schema
     * ]
     *
     * @return bool
     */
    public function addToSchema(string $hookName, array $args): bool
    {
        $schema = &$args[0];

        $settings = [
            $this->_getDepositBatchIdSettingName(),
            $this->_getFailedMsgSettingName(),
            $this->_getSuccessMsgSettingName(),
        ];

        foreach ($settings as $settingName) {
            $schema->properties->{$settingName} = (object) [
                'type' => 'string',
                'apiSummary' => true,
                'validation' => ['nullable'],
            ];
        }

        return false;
    }

    /**
     * Includes plugin in list of configurable registration agencies for DOI depositing functionality
     *
     * @param string $hookName DoiSettingsForm::setEnabledRegistrationAgencies
     * @param array $args [
     *      @option $enabledRegistrationAgencies array
     * ]
     */
    public function addAsRegistrationAgencyOption($hookName, $args)
    {
        /** @var Collection<IDoiRegistrationAgency> $enabledRegistrationAgencies */
        $enabledRegistrationAgencies = &$args[0];
        $enabledRegistrationAgencies->add($this);
    }

    /**
     * Includes human-readable name of registration agency for display in conjunction with how/with whom the
     * DOI was registered.
     *
     * @param string $hookName DoiListPanel::setConfig
     * @param $args [
     *      @option $config array
     * ]
     * @return void
     */
    public function addRegistrationAgencyName(string $hookName, array $args): bool {
        $config = &$args[0];
        $config['registrationAgencyNames'][$this->_getExportPlugin()->getName()] = $this->getRegistrationAgencyName();

        return HOOK::CONTINUE;
    }

    /**
     * Checks if plugin meets registration agency-specific requirements for being active and handling deposits
     *
     * @return bool
     */
    public function isPluginConfigured(Context $context): bool
    {
        $settingsObject = $this->getSettingsObject();

        /** @var PKPSchemaService $schemaService */
        $schemaService = Services::get('schema');
        $requiredProps = $schemaService->getRequiredProps($settingsObject::class);

        foreach ($requiredProps as $requiredProp) {
            $settingValue = $this->getSetting($context->getId(), $requiredProp);
            if (empty($settingValue)) {
                return false;
            }
        }

        $doiPrefix = $context->getData(Context::SETTING_DOI_PREFIX);
        if (empty($doiPrefix)) {
            return false;
        }

        if (!$context->getData('publisherInstitution') || !($context->getData('onlineIssn') || $context->getData('printIssn'))) {
            return false;
        }

        return true;
    }

    /**
     * Get configured registration agency display name for use in DOI management pages
     * @return string
     */
    public function getRegistrationAgencyName(): string
    {
        return __('plugins.generic.crossref.registrationAgency.name');
    }

    /**
     * @param Submission[] $submissions
     * @param Context $context
     *
     * @return array
     */
    public function exportSubmissions(array $submissions, Context $context) : array
    {
        // Get filter and set objectsFileNamePart (see: PubObjectsExportPlugin::prepareAndExportPubObjects)
        $exportPlugin = $this->_getExportPlugin();
        $filterName = $exportPlugin->getSubmissionFilter();
        $xmlErrors = [];

        $temporaryFileId =  $exportPlugin->exportAsDownload($context, $submissions, $filterName, 'articles', null, $xmlErrors);
        return ['temporaryFileId' => $temporaryFileId, 'xmlErrors' => $xmlErrors];
    }

    /**
     * @param Submission[] $submissions
     * @param Context $context
     */
    public function depositSubmissions(array $submissions, Context $context): array
    {
        $exportPlugin = $this->_getExportPlugin();
        $filterName = $exportPlugin->getSubmissionFilter();
        $responseMessage = '';
        $status = $exportPlugin->exportAndDeposit($context, $submissions, $filterName, 'articles', $responseMessage);

        return [
            'hasErrors' => !$status,
            'responseMessage' => $responseMessage
        ];
    }

    /**
     * @param Issue[] $issues
     * @param Context $context
     *
     * @return array
     */
    public function exportIssues(array $issues, Context $context): array
    {
        // Get filter and set objectsFileNamePart (see: PubObjectsExportPlugin::prepareAndExportPubObjects)
        $exportPlugin = $this->_getExportPlugin();
        $filterName = $exportPlugin->getIssueFilter();
        $xmlErrors = [];

        $temporaryFileId = $exportPlugin->exportAsDownload($context, $issues, $filterName, 'articles', null, $xmlErrors);
        return ['temporaryFileId' => $temporaryFileId, 'xmlErrors' => $xmlErrors];
    }

    /**
     * @param Issue[] $issues
     * @param Context $context
     */
    public function depositIssues(array $issues, Context $context): array
    {
        $exportPlugin = $this->_getExportPlugin();
        $filterName = $exportPlugin->getIssueFilter();
        $responseMessage = '';
        $status = $exportPlugin->exportAndDeposit($context, $issues, $filterName, 'issues', $responseMessage);

        return [
            'hasErrors' => !$status,
            'responseMessage' => $responseMessage
        ];
    }

    /**
     * Adds Crossref specific info to Repo::doi()->markRegistered()
     *
     * @param string $hookName Doi::markRegistered
     * @param array $args
     *
     * @return bool
     */
    public function editMarkRegisteredParams(string $hookName, array $args): bool
    {
        $editParams = &$args[0];
        $editParams[$this->_getFailedMsgSettingName()] = null;
        $editParams[$this->_getSuccessMsgSettingName()] = null;

        return false;
    }

    /**
     * Get request failed message setting name.
     * NB: Change from 3.3.x to camelCase (over crossref::failedMsg)
     *
     * @return string
     */
    private function _getFailedMsgSettingName(): string
    {
        return $this->getName() . '_failedMsg';
    }

    /**
     * Get deposit batch ID setting name.
     * NB: Change from 3.3.x to camelCase (over crossref::batchId)
     *
     * @return string
     */
    private function _getDepositBatchIdSettingName(): string
    {
        return $this->getName() . '_batchId';
    }

    private function _getSuccessMsgSettingName(): string
    {
        return $this->getName() . '_successMsg';
    }

    private function _getExportPlugin()
    {
        if (empty($this->_exportPlugin)) {
            $pluginCategory = 'importexport';
            $pluginPathName = 'CrossRefExportPlugin';
            $this->_exportPlugin = PluginRegistry::getPlugin($pluginCategory, $pluginPathName);
            // If being run from CLI, there is no context, so plugin initialization would not have been fired
            if ($this->_exportPlugin === null && !isset($_SERVER['SERVER_NAME'])) {
                $this->_pluginInitialization();
                $this->_exportPlugin = PluginRegistry::getPlugin($pluginCategory, $pluginPathName);
            }
        }
        return $this->_exportPlugin;
    }

    /**
     * @inheritDoc
     */
    public function getErrorMessageKey(): ?string
    {
        return $this->_getFailedMsgSettingName();
    }

    /**
     * @inheritDoc
     */
    public function getRegisteredMessageKey(): ?string
    {
        return $this->_getSuccessMsgSettingName();
    }

    public function getSettingsObject(): RegistrationAgencySettings
    {
        if (!isset($this->_settingsObject)) {
            $this->_settingsObject = new CrossrefSettings($this);
        }

        return $this->_settingsObject;
    }
}
