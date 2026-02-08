<?php

/**
 * @file plugins/generic/crossref/CrossrefPlugin.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossrefPlugin
 *
 * @brief Plugin to let managers deposit DOIs and metadata to Crossref
 *
 */

namespace APP\plugins\generic\crossref;

use APP\core\Application;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\plugins\generic\crossref\classes\CrossrefSettings;
use APP\plugins\IDoiRegistrationAgency;
use APP\publication\Publication;
use APP\services\ContextService;
use APP\submission\Submission;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use PKP\citation\Citation;
use PKP\citation\pid\Doi;
use PKP\context\Context;
use PKP\doi\RegistrationAgencySettings;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\PKPScheduler;
use PKP\services\PKPSchemaService;


class CrossrefPlugin extends GenericPlugin implements IDoiRegistrationAgency, HasTaskScheduler
{
    public const CROSSREF_API_REFS_URL = 'https://doi.crossref.org/getResolvedRefs';
    public const CROSSREF_API_REFS_URL_DEV = 'https://test.crossref.org/getResolvedRefs';

    private CrossrefSettings $_settingsObject;
    private ?CrossrefExportPlugin $_exportPlugin = null;

    public function getDisplayName(): string
    {
        return __('plugins.generic.crossref.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.crossref.description');
    }

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
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

            PluginRegistry::register('importexport', new CrossrefExportPlugin($this), $this->getPluginPath());
            $this->_exportPlugin = PluginRegistry::getPlugin('importexport', 'CrossrefExportPlugin');

            Hook::add('Schema::get::doi', $this->addToSchema(...));
            Hook::add('Schema::get::submission', $this->addSubmissionSchema(...));
            Hook::add('Citation::importCitations::after', $this->citationsChanged(...));

            if ($this->getEnabled($mainContextId)) {
                $this->_pluginInitialization();
                Hook::add('Templates::Article::Details::Reference', [$this, 'displayReferenceDOI']);
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
            $contextDao = Application::getContextDAO();
            $context = $contextDao->getById($contextId);
            if ($context->getData(Context::SETTING_CONFIGURED_REGISTRATION_AGENCY) === $this->getName()) {
                $context->setData(Context::SETTING_CONFIGURED_REGISTRATION_AGENCY, Context::SETTING_NO_REGISTRATION_AGENCY);
                $contextDao->updateObject($context);
            }
        }
    }

    /**
     * @copydoc \PKP\plugins\Plugin::getEncryptedSettingFields()
     */
    public function getEncryptedSettingFields(): array
    {
        return [
            'password',
        ];
    }

    /**
     * Helper to register hooks that are used in normal plugin setup and in CLI tool usage.
     */
    private function _pluginInitialization(): void
    {
        Hook::add('DoiSettingsForm::setEnabledRegistrationAgencies', $this->addAsRegistrationAgencyOption(...));
        Hook::add('DoiSetupSettingsForm::getObjectTypes', $this->addAllowedObjectTypes(...));
        Hook::add('Context::validate', $this->validateAllowedPubObjectTypes(...));

        Hook::add('Doi::markRegistered', $this->editMarkRegisteredParams(...));
        Hook::add('DoiListPanel::setConfig', $this->addRegistrationAgencyName(...));
        Hook::add('Publication::validatePublish', $this->validate(...));
    }

    /**
     * @copydoc \PKP\plugins\interfaces\HasTaskScheduler::registerSchedules()
     */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scheduler
            ->addSchedule(new CrossrefCitationsDiagnosticInfoSender([]))
            ->hourly()
            ->name(CrossrefCitationsDiagnosticInfoSender::class)
            ->withoutOverlapping();
    }

    /**
     * Add properties for Crossref to the DOI entity for storage in the database.
     *
     * @param string $hookName `Schema::get::doi`
     * @param array{schema: object{properties: array<string, object>}} $args
     */
    public function addToSchema(string $hookName, array $args): bool
    {
        $schema = &$args[0];

        $settings = [
            $this->_exportPlugin->getDepositBatchIdSettingName(),
            $this->_exportPlugin->getFailedMsgSettingName(),
            $this->_exportPlugin->getSuccessMsgSettingName(),
        ];

        foreach ($settings as $settingName) {
            $schema->properties->{$settingName} = (object) [
                'type' => 'string',
                'apiSummary' => true,
                'validation' => ['nullable'],
            ];
        }

        return Hook::CONTINUE;
    }

    /**
     * Add properties to the submission entity (SchemaDAO-based)
     *
     * @param string $hookName `Schema::get::submission`
     * @param array $args [
     *      @option stdClass $schema
     * ]
     */
    public function addSubmissionSchema(string $hookName, array $args): bool
    {
        $schema = $args[0];

        $schema->properties->{$this->_exportPlugin->getCitationsDiagnosticIdSettingName()} = (object) [
            'type' => 'string',
            'apiSummary' => true,
            'validation' => ['nullable']
        ];

        $schema->properties->{$this->_exportPlugin->getAutoCheckSettingName()} = (object) [
            'type' => 'boolean',
            'apiSummary' => true,
            'validation' => ['nullable']
        ];
        return Hook::CONTINUE;
    }

    /**
     * Resets the submission's citations diagnostic ID and authomatic check settings every time all citations are changed,
     * so that the submission will not be checked for found Crossref references DOIs in the next scheduled task run.
     *
     * @param string $hookName Hook name 'Citation::importCitations::after'
     */
    public function citationsChanged(string $hookName, int $publicationId, array $existingCitations, array $importedCitations): bool
    {
        if (!$this->getEnabled() ||
            !$this->hasCrossrefCredentials() ||
            !$this->citationsEnabled()) {

                return Hook::CONTINUE;
        }

        $publication = Repo::publication()->get($publicationId);
        $submission = Repo::submission()->get($publication->getData('submissionId'));

        if ($submission->getData($this->_exportPlugin->getCitationsDiagnosticIdSettingName())) {
            $submission->setData($this->_exportPlugin->getCitationsDiagnosticIdSettingName(), null);
            $submission->setData($this->_exportPlugin->getAutoCheckSettingName(), null);
            Repo::submission()->edit($submission, []);
        }
        return Hook::CONTINUE;
    }

    /**
     * Insert reference DOI on the citations and article view page.
     *
     * @param string $hookName Hook name 'Templates::Article::Details::Reference'
     * @param array $params [
     *  @option Citation
     *  @option Smarty
     *  @option string Rendered smarty template
     * ]
     */
    public function displayReferenceDOI(string $hookName, array $params): bool
    {
        /** @var Citation $citation */
        $citation = $params[0]['citation'];
        /** @var \Smarty $smarty */
        $smarty = &$params[1];
        /** @var string $output */
        $output = &$params[2];

        if ($citation->getData('doi')) {
            $rawString = str_ireplace('http://', 'https://', $citation->getRawCitation());
            $doi = Doi::extractFromString($rawString);
            // Display DOI only if the raw citation string doesn't already contain the DOI as a link, to avoid duplicate DOIs on the page
            if (empty($doi)) {
                $crossrefFullUrl = 'https://doi.org/' . $citation->getData('doi');
                $smarty->assign('crossrefFullUrl', $crossrefFullUrl);
                $output .= $smarty->fetch($this->getTemplateResource('displayDOI.tpl'));
            }
        }
        return Hook::CONTINUE;
    }

    /**
     * Are Crossref username and password set in Crossref plugin
     */
    public function hasCrossrefCredentials(?int $contextId = null): bool
    {
        if (!isset($contextId)) {
            $contextId = $this->getCurrentContextId();
        }
        return strlen((string) $this->getSetting($contextId, 'username')) > 0
            && strlen((string) $this->getSetting($contextId, 'password')) > 0;
    }

    /**
     * Are citations submission metadata enabled in this journal
     */
    public function citationsEnabled(?int $contextId = null): bool
    {
        if (!isset($contextId)) {
            $contextId = $this->getCurrentContextId();
        }
        $contextDao = Application::getContextDAO();
        $context = $contextDao->getById($contextId);
        return !empty($context->getData('citations'));
    }

    /**
     * Retrieve submission's publication that should be checked for the found Crossref citations DOIs.
     *
     * @return Publication[]
     */
    protected function getPublicationsToCheck(Submission $submission, Context $context): array
    {
        $publicationsToCheck = [];
        if (!$context->getData(Context::SETTING_DOI_VERSIONING)) {
            $publication = $submission->getCurrentPublication();
            if ($publication->getDoi() && $publication->getData('status') == Publication::STATUS_PUBLISHED) {
                $publicationsToCheck[] = $publication;
            }
        } else {
            $latestMinorPublications = $this->_exportPlugin->getLatestMinorPublications($submission->getData('publications'));
            foreach ($latestMinorPublications as $versionStage) {
                foreach ($versionStage as $publication) {
                    $publicationsToCheck[] = $publication;
                }
            }
        }
        return $publicationsToCheck;
    }

    /**
     * Consider found Crossref references DOIs.
     */
    public function considerFoundCrossrefReferencesDOIs(Context $context): void
    {
        // Retrieve all articles with their DOIs deposited together with the references.
        // i.e. with the citations diagnostic ID setting
        $submissionIds = Repo::submission()->getIdsBySetting($this->_exportPlugin->getAutoCheckSettingName(), true, $context->getId())->toArray();
        $submissions = Repo::submission()->getCollector()->filterBySubmissionIds($submissionIds)->getMany();
        foreach ($submissions as $submission) {
            $publicationsToCheck = $this->getPublicationsToCheck($submission, $context); // always contain DOI and are published
            foreach ($publicationsToCheck as $publication) { /** @var Publication $publication */
                $citations = $publication->getData('citations') ?? [];

                $citationsToCheck = [];
                foreach ($citations as $citation) { /** @var Citation $citation */
                    if (!$citation->getData('doi')) {
                        $citationsToCheck[$citation->getId()] = $citation;
                    }
                }
                if (empty($citationsToCheck)) {
                    continue;
                }

                $matchedReferences = $this->getResolvedRefs($publication->getDoi(), $context->getId());
                if ($matchedReferences) {
                    foreach ($matchedReferences as $matchedReference) {
                        $key = $matchedReference['key'] ?? null;
                        if ($key === null || !isset($citationsToCheck[$key])) {
                            continue;
                        }
                        $citation = $citationsToCheck[$key];
                        $citation->setData('doi', $matchedReference['doi']);
                        Repo::citation()->edit($citation, []);
                    }
                }
            }
            // remove auto check setting
            $submission->setData($this->_exportPlugin->getAutoCheckSettingName(), null);
            Repo::submission()->edit($submission, []);
        }
    }

    /**
     * Use Crossref API to get the references DOIs for the the given article DOI.
     */
    protected function getResolvedRefs(string $doi, int $contextId): ?array
    {
        $matchedReferences = null;

        $username = $this->getSetting($contextId, 'username');
        $password = $this->getSetting($contextId, 'password');

        // Use a different endpoint for testing and production.
        $isTestMode = $this->getSetting($contextId, 'testMode') == 1;
        $endpoint = ($isTestMode ? self::CROSSREF_API_REFS_URL_DEV : self::CROSSREF_API_REFS_URL);

        $url = $endpoint . '?doi=' . $doi . '&usr=' . urlencode($username) . '&pwd=' . urlencode($password);

        $httpClient = Application::get()->getHttpClient();
        try {
            $response = $httpClient->request('POST', $url);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            return null;
        }

        if ($response?->getStatusCode() == 200) {
            $response = json_decode($response->getBody(), true);
            $matchedReferences = $response['matched-references'] ?? [];
        }

        return $matchedReferences;
    }

    /**
     * Includes plugin in list of configurable registration agencies for DOI depositing functionality
     *
     * @param string $hookName DoiSettingsForm::setEnabledRegistrationAgencies
     * @param array{Collection<int,IDoiRegistrationAgency>} $args [Enabled registration agencies]
     */
    public function addAsRegistrationAgencyOption($hookName, $args)
    {
        /** @var Collection<int,IDoiRegistrationAgency> $enabledRegistrationAgencies */
        $enabledRegistrationAgencies = &$args[0];
        $enabledRegistrationAgencies->add($this);
    }

    /**
     * Includes human-readable name of registration agency for display in conjunction with how/with whom the
     * DOI was registered.
     *
     * @param string $hookName DoiListPanel::setConfig
     * @param array{array<string, mixed>} $args [Configuration]
     */
    public function addRegistrationAgencyName(string $hookName, array $args): bool
    {
        $config = &$args[0];
        $config['registrationAgencyNames'][$this->_exportPlugin->getName()] = $this->getRegistrationAgencyName();

        return HOOK::CONTINUE;
    }

    /**
     * Adds self to "allowed" list of pub object types that can be assigned DOIs for this registration agency.
     *
     * @param string $hookName DoiSetupSettingsForm::getObjectTypes
     * @param array{array<array<string, mixed>>} $args [Object type options]
     */
    public function addAllowedObjectTypes(string $hookName, array $args): bool
    {
        $objectTypeOptions = &$args[0];
        $allowedTypes = $this->getAllowedDoiTypes();

        $objectTypeOptions = array_map(function ($option) use ($allowedTypes) {
            if (in_array($option['value'], $allowedTypes)) {
                $option['allowedBy'][] = $this->getName();
            }
            return $option;
        }, $objectTypeOptions);

        return Hook::CONTINUE;
    }

    /**
     * Add validation rule to Context for restriction of allowed pubObject types for DOI registration.
     *
     * @throws Exception
     */
    public function validateAllowedPubObjectTypes(string $hookName, array $args): bool
    {
        $errors = &$args[0];
        $props = $args[2];

        if (!isset($props['enabledDoiTypes'])) {
            return Hook::CONTINUE;
        }

        $contextId = $props['id'];
        if (empty($contextId)) {
            throw new Exception('A context ID must be present to edit context settings');
        }

        /** @var ContextService $contextService */
        $contextService = app()->get('context');
        $context = $contextService->get($contextId);
        $enabledRegistrationAgency = $context->getConfiguredDoiAgency();
        if (!$enabledRegistrationAgency instanceof $this) {
            return Hook::CONTINUE;
        }

        $allowedTypes = $enabledRegistrationAgency->getAllowedDoiTypes();

        if (!empty(array_diff($props['enabledDoiTypes'], $allowedTypes))) {
            $errors['enabledDoiTypes'] = [__('doi.manager.settings.enabledDoiTypes.error')];
        }

        return Hook::CONTINUE;
    }

    /**
     * Checks if plugin meets registration agency-specific requirements for being active and handling deposits
     */
    public function isPluginConfigured(Context $context): bool
    {
        $settingsObject = $this->getSettingsObject();

        /** @var PKPSchemaService $schemaService */
        $schemaService = app()->get('schema');
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
     */
    public function getRegistrationAgencyName(): string
    {
        return __('plugins.generic.crossref.registrationAgency.name');
    }

    /**
     * @param Submission[] $submissions
     */
    public function exportSubmissions(array $submissions, Context $context): array
    {
        // Get filter and set objectsFileNamePart (see: PubObjectsExportPlugin::prepareAndExportPubObjects)
        $filterName = $this->_exportPlugin->getSubmissionFilter();
        $xmlErrors = [];

        $temporaryFileId = $this->_exportPlugin->exportAsDownload($context, $submissions, $filterName, 'articles', null, $xmlErrors);
        return ['temporaryFileId' => $temporaryFileId, 'xmlErrors' => $xmlErrors];
    }

    /**
     * @param Submission[] $submissions
     */
    public function depositSubmissions(array $submissions, Context $context): array
    {
        $filterName = $this->_exportPlugin->getSubmissionFilter();
        $responseMessage = '';
        $status = $this->_exportPlugin->exportAndDeposit($context, $submissions, $filterName, $responseMessage);

        return [
            'hasErrors' => !$status,
            'responseMessage' => $responseMessage
        ];
    }

    /**
     * @param Issue[] $issues
     */
    public function exportIssues(array $issues, Context $context): array
    {
        // Get filter and set objectsFileNamePart (see: PubObjectsExportPlugin::prepareAndExportPubObjects)
        $filterName = $this->_exportPlugin->getIssueFilter();
        $xmlErrors = [];

        $temporaryFileId = $this->_exportPlugin->exportAsDownload($context, $issues, $filterName, 'issues', null, $xmlErrors);
        return ['temporaryFileId' => $temporaryFileId, 'xmlErrors' => $xmlErrors];
    }

    /**
     * @param Issue[] $issues
     */
    public function depositIssues(array $issues, Context $context): array
    {
        $filterName = $this->_exportPlugin->getIssueFilter();
        $responseMessage = '';
        $status = $this->_exportPlugin->exportAndDeposit($context, $issues, $filterName, $responseMessage);

        return [
            'hasErrors' => !$status,
            'responseMessage' => $responseMessage
        ];
    }

    /**
     * Adds Crossref specific info to Repo::doi()->markRegistered()
     *
     * @param string $hookName Doi::markRegistered
     *
     */
    public function editMarkRegisteredParams(string $hookName, array $args): bool
    {
        $editParams = &$args[0];
        $editParams[$this->_exportPlugin->getFailedMsgSettingName()] = null;
        $editParams[$this->_exportPlugin->getSuccessMsgSettingName()] = null;
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getErrorMessageKey(): ?string
    {
        return $this->_exportPlugin->getFailedMsgSettingName();
    }

    /**
     * @inheritDoc
     */
    public function getRegisteredMessageKey(): ?string
    {
        return $this->_exportPlugin->getSuccessMsgSettingName();
    }

    /**
     * @inheritDoc
     */
    public function getSettingsObject(): RegistrationAgencySettings
    {
        if (!isset($this->_settingsObject)) {
            $this->_settingsObject = new CrossrefSettings($this);
        }

        return $this->_settingsObject;
    }

    /**
     * @inheritDoc
     */
    public function getAllowedDoiTypes(): array
    {
        return [Repo::doi()::TYPE_PUBLICATION, Repo::doi()::TYPE_ISSUE];
    }

    /**
     * Make additional validation checks against publishing requirements
     *
     * @throws Exception
     * @see PKPPublicationService::validatePublish()
     */
    public function validate(string $hookName, array $args): bool
    {
        $errors = & $args[0];
        $publication = $args[1];
        $submission = $args[2];

        $issueId = $publication->getData('issueId');

        $context = Application::getContextDAO()->getById($submission->getData('contextId'));
        $enabledRegistrationAgency = $context->getConfiguredDoiAgency();
        $enabledDoiTypes = $context->getData('enabledDoiTypes');
        $doiCreationTime = $context->getData(Context::SETTING_DOI_CREATION_TIME);
        if (!($enabledRegistrationAgency instanceof $this) ||
            !in_array(Repo::doi()::TYPE_PUBLICATION, $enabledDoiTypes) ||
            $doiCreationTime === Repo::doi()::CREATION_TIME_PUBLICATION) {

                return Hook::CONTINUE;
        }

        $rules = [
            'publisherInstitution' => ['required', 'string'],
            'onlineIssn' => ['required_without:printIssn', 'nullable', 'string'],
            'printIssn' => ['required_without:onlineIssn', 'nullable', 'string'],
            'doi' => ['required', 'string'],
            'issueId' => [
                'sometimes',
                'nullable',
                'integer',
                function ($attribute, $value, $fail) use ($context, $publication) {
                    $issue = Repo::issue()->get($value, $context->getId());
                    if (!$issue) {
                        $fail(__('plugins.generic.crossref.issueId.invalid', [
                            'publicationTitle' => $publication->getLocalizedTitle()
                        ]));
                    }
                },
            ],
        ];

        $metadata = [
            'publisherInstitution' => $context->getData('publisherInstitution'),
            'onlineIssn' => $context->getData('onlineIssn'),
            'printIssn' => $context->getData('printIssn'),
            'doi' => $publication->getDoi(),
            'issueId' => $issueId,
        ];

        $validator = Validator::make(
            $metadata,
            $rules,
            $this->getValidationMessages($publication)
        );
        if (!$validator->passes()) {
            $errors = $this->formatErrors($validator->errors()->toArray());
        }
        return HOOK::CONTINUE;
    }

    /**
     * Get validation messages
     * @throws Exception
     */
    private function getValidationMessages(Publication $publication): array
    {
        return [
            'doi.required' => __('plugins.generic.crossref.doi.required', [
                'publicationTitle' => $publication->getLocalizedTitle()
            ]),
            'doi.url' => __('plugins.generic.crossref.doi.url', [
                'publicationTitle' => $publication->getLocalizedTitle()
            ]),
            'onlineIssn.required_without' => __('plugins.generic.crossref.issn.requiredWithout'),
            'printIssn.required_without' => __('plugins.generic.crossref.issn.requiredWithout'),
            'publisherInstitution.required' => __('plugins.generic.crossref.publisherInstitution.required')
        ];
    }

    /**
     * Format errors
     */
    private function formatErrors(array $errors): array
    {
        $values = [];
        foreach ($errors as $value) {
            if (is_array($value)) {
                $values = array_merge($values, $this->formatErrors($value));
            } else {
                $values[] = $value;
            }
        }
        return $values;
    }
}
