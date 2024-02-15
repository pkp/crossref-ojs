<?php

/**
 * @file plugins/generic/crossref/CrossrefExportPlugin.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossrefExportPlugin
 *
 * @brief Crossref/MEDLINE XML metadata export plugin
 */

namespace APP\plugins\generic\crossref;

use APP\core\Application;
use PKP\config\Config;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\DOIPubIdExportPlugin;
use APP\plugins\IDoiRegistrationAgency;
use APP\submission\Submission;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use PKP\core\DataObject;
use PKP\doi\Doi;
use PKP\file\FileManager;
use PKP\file\TemporaryFileManager;
use PKP\plugins\Hook;
use PKP\plugins\Plugin;

class CrossrefExportPlugin extends DOIPubIdExportPlugin
{
    // The status of the Crossref DOI.
    // any, notDeposited, and markedRegistered are reserved
    public const CROSSREF_STATUS_FAILED = 'failed';
    public const CROSSREF_API_DEPOSIT_OK = 200;
    public const CROSSREF_API_DEPOSIT_ERROR_FROM_CROSSREF = 403;
    public const CROSSREF_API_URL = 'https://api.crossref.org/v2/deposits';
    //TESTING
    public const CROSSREF_API_URL_DEV = 'https://test.crossref.org/v2/deposits';
    public const CROSSREF_API_STATUS_URL = 'https://doi.crossref.org/servlet/submissionDownload';
    //TESTING
    public const CROSSREF_API_STATUS_URL_DEV = 'https://test.crossref.org/servlet/submissionDownload';
    // The name of the setting used to save the registered DOI and the URL with the deposit status.
    public const CROSSREF_DEPOSIT_STATUS = 'depositStatus';

    public function __construct(protected IDoiRegistrationAgency|Plugin $agencyPlugin)
    {
        parent::__construct();
    }

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success) {
            // register hooks. This will prevent DB access attempts before the
            // schema is installed.
            if (Application::isUnderMaintenance()) {
                return true;
            }
        }
        return $success;
    }

    /**
     * @copydoc Plugin::getName()
     */
    public function getName()
    {
        return 'CrossrefExportPlugin';
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.importexport.crossref.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.importexport.crossref.description');
    }

    /**
     * @copydoc PubObjectsExportPlugin::getSubmissionFilter()
     */
    public function getSubmissionFilter()
    {
        return 'article=>crossref-xml';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getIssueFilter()
     */
    public function getIssueFilter()
    {
        return 'issue=>crossref-xml';
    }

    /** Proxy to main plugin class's `getSetting` method */
    public function getSetting($contextId, $name)
    {
        return $this->agencyPlugin->getSetting($contextId, $name);
    }

    /**
     * @copydoc PubObjectsExportPlugin::getStatusMessage()
     */
    public function getStatusMessage($request)
    {
        // Application is set to sandbox mode and will not run the features of plugin
        if (Config::getVar('general', 'sandbox', false)) {
            error_log('Application is set to sandbox mode and will not have any interaction with crossref external service');
            return __('common.sandbox');
        }
        
        // if the failure occurred on request and the message was saved
        // return that message
        $articleId = $request->getUserVar('articleId');
        $article = Repo::submission()->get((int)$articleId);
        $failedMsg = $article->getData('doiObject')->getData($this->getFailedMsgSettingName());
        if (!empty($failedMsg)) {
            return $failedMsg;
        }

        $context = $request->getContext();

        $httpClient = Application::get()->getHttpClient();
        try {
            $response = $httpClient->request(
                'POST',
                $this->isTestMode($context) ? static::CROSSREF_API_STATUS_URL_DEV : static::CROSSREF_API_STATUS_URL,
                [
                    'form_params' => [
                        'doi_batch_id' => $request->getUserVar('batchId'),
                        'type' => 'result',
                        'usr' => $this->getSetting($context->getId(), 'username'),
                        'pwd' => $this->getSetting($context->getId(), 'password'),
                    ]
                ]
            );
        } catch (RequestException $e) {
            $returnMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $returnMessage = $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')';
            }
            return __('plugins.importexport.common.register.error.mdsError', ['param' => $returnMessage]);
        }

        return (string) $response->getBody();
    }

    /**
     * Get a list of additional setting names that should be stored with the objects.
     *
     * @return array
     */
    protected function _getObjectAdditionalSettings()
    {
        return array_merge(parent::_getObjectAdditionalSettings(), [
            $this->getDepositBatchIdSettingName(),
            $this->getFailedMsgSettingName(),
            $this->getSuccessMsgSettingName(),
        ]);
    }

    /**
     * @copydoc ImportExportPlugin::getPluginSettingsPrefix()
     */
    public function getPluginSettingsPrefix()
    {
        return 'crossrefplugin';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getSettingsFormClassName()
     */
    public function getSettingsFormClassName()
    {
        throw new Exception('DOI settings no longer managed via plugin settings form.');
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportDeploymentClassName()
     */
    public function getExportDeploymentClassName()
    {
        return (string) \APP\plugins\generic\crossref\CrossrefExportDeployment::class;
    }

    public function exportAndDeposit($context, $objects, $filter, string &$responseMessage, $noValidation = null): bool
    {
        $fileManager = new FileManager();
        $resultErrors = [];

        assert($filter != null);
        // Errors occurred will be accessible via the status link
        // thus do not display all errors notifications (for every article),
        // just one general.
        // Warnings occurred when the registration was successful will however be
        // displayed for each article.
        $errorsOccurred = false;
        // The new Crossref deposit API expects one request per object.
        // On contrary the export supports bulk/batch object export, thus
        // also the filter expects an array of objects.
        // Thus the foreach loop, but every object will be in an one item array for
        // the export and filter to work.
        foreach ($objects as $object) {
            // Get the XML
            // Supply an exportErrors array because otherwise exportXML() will echo out export errors
            $exportErrors = [];
            $exportXml = $this->exportXML([$object], $filter, $context, $noValidation, $exportErrors);
            // Write the XML to a file.
            // export file name example: crossref-20160723-160036-articles-1-1.xml
            $objectFileNamePart = $this->_getObjectFileNamePart($object);
            $exportFileName = $this->getExportFileName($this->getExportPath(), $objectFileNamePart, $context, '.xml');
            $fileManager->writeFile($exportFileName, $exportXml);
            // Deposit the XML file.
            $result = $this->depositXML($object, $context, $exportFileName);
            if (!$result) {
                $errorsOccurred = true;
            }
            if (is_array($result)) {
                $resultErrors[] = $result;
            }
            // Remove all temporary files.
            $fileManager->deleteByPath($exportFileName);
        }
        // Prepare response message and return status
        if (empty($resultErrors)) {
            if ($errorsOccurred) {
                $responseMessage = 'plugins.importexport.crossref.register.error.mdsError';
                return false;
            } else {
                $responseMessage = $this->getDepositSuccessNotificationMessageKey();
                return true;
            }
        } else {
            $responseMessage = 'api.dois.400.depositFailed';
            return false;
        }
    }

    /**
     * Exports and stores XML as a TemporaryFile
     *
     *
     * @throws Exception
     */
    public function exportAsDownload(\PKP\context\Context $context, array $objects, string $filter, string $objectsFileNamePart, ?bool $noValidation = null, ?array &$exportErrors = null): ?int
    {
        $fileManager = new TemporaryFileManager();

        $exportErrors = [];
        $exportXml = $this->exportXML($objects, $filter, $context, $noValidation, $exportErrors);

        $exportFileName = $this->getExportFileName($this->getExportPath(), $objectsFileNamePart, $context, '.xml');

        $fileManager->writeFile($exportFileName, $exportXml);

        $user = Application::get()->getRequest()->getUser();

        return $fileManager->createTempFileFromExisting($exportFileName, $user->getId());
    }

    /**
     * @param Submission $objects
     * @param Journal $context
     * @param string $filename Export XML filename
     *
     * @throws GuzzleException
     *
     * @see PubObjectsExportPlugin::depositXML()
     *
     */
    public function depositXML($objects, $context, $filename)
    {
        // Application is set to sandbox mode and will not run the features of plugin
        if (Config::getVar('general', 'sandbox', false)) {
            error_log('Application is set to sandbox mode and will not have any interaction with crossref external service');
            return false;
        }

        $status = null;
        $msgSave = null;

        $httpClient = Application::get()->getHttpClient();
        assert(is_readable($filename));

        try {
            $response = $httpClient->request(
                'POST',
                $this->isTestMode($context) ? static::CROSSREF_API_URL_DEV : static::CROSSREF_API_URL,
                [
                    'multipart' => [
                        [
                            'name' => 'usr',
                            'contents' => $this->getSetting($context->getId(), 'username'),
                        ],
                        [
                            'name' => 'pwd',
                            'contents' => $this->getSetting($context->getId(), 'password'),
                        ],
                        [
                            'name' => 'operation',
                            'contents' => 'doMDUpload',
                        ],
                        [
                            'name' => 'mdFile',
                            'contents' => fopen($filename, 'r'),
                        ],
                    ]
                ]
            );
        } catch (RequestException $e) {
            $returnMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $eResponseBody = $e->getResponse()->getBody();
                $eStatusCode = $e->getResponse()->getStatusCode();
                if ($eStatusCode == static::CROSSREF_API_DEPOSIT_ERROR_FROM_CROSSREF) {
                    $xmlDoc = new \DOMDocument('1.0', 'utf-8');
                    $xmlDoc->loadXML($eResponseBody);
                    $batchIdNode = $xmlDoc->getElementsByTagName('batch_id')->item(0);
                    $msg = $xmlDoc->getElementsByTagName('msg')->item(0)->nodeValue;
                    $msgSave = $msg . PHP_EOL . $eResponseBody;
                    $status = Doi::STATUS_ERROR;
                    $this->updateDepositStatus($context, $objects, $status, $batchIdNode->nodeValue, $msgSave);
                    $returnMessage = $msg . ' (' . $eStatusCode . ' ' . $e->getResponse()->getReasonPhrase() . ')';
                } else {
                    $returnMessage = $eResponseBody . ' (' . $eStatusCode . ' ' . $e->getResponse()->getReasonPhrase() . ')';
                    $this->updateDepositStatus($context, $objects, Doi::STATUS_ERROR, null, $returnMessage);
                }
            }
            return [['plugins.importexport.common.register.error.mdsError', $returnMessage]];
        }

        // Get DOMDocument from the response XML string
        $xmlDoc = new \DOMDocument('1.0', 'utf-8');
        $xmlDoc->loadXML($response->getBody());
        $batchIdNode = $xmlDoc->getElementsByTagName('batch_id')->item(0);
        $submissionIdNode = $xmlDoc->getElementsByTagName('submission_id')->item(0);
        $successMessage = __('plugins.generic.crossref.successMessage', ['submissionId' => $submissionIdNode->nodeValue]);

        // Get the DOI deposit status
        // If the deposit failed
        $failureCountNode = $xmlDoc->getElementsByTagName('failure_count')->item(0);
        $failureCount = (int) $failureCountNode->nodeValue;
        if ($failureCount > 0) {
            $status = Doi::STATUS_ERROR;
            $result = false;
        } else {
            // Deposit was received
            $status = Doi::STATUS_REGISTERED;
            $result = true;

            // If there were some warnings, display them
            $warningCountNode = $xmlDoc->getElementsByTagName('warning_count')->item(0);
            $warningCount = (int) $warningCountNode->nodeValue;
            if ($warningCount > 0) {
                $result = [['plugins.importexport.crossref.register.success.warning', htmlspecialchars($response->getBody())]];
            }
            // A possibility for other plugins (e.g. reference linking) to work with the response
            Hook::run('crossrefexportplugin::deposited', [[$this, $response->getBody(), $objects]]);
        }

        // Update the status
        if ($status) {
            $this->updateDepositStatus($context, $objects, $status, $batchIdNode->nodeValue, $msgSave, $successMessage);
        }

        return $result;
    }

    /**
     * Check the Crossref APIs, if deposits and registration have been successful
     *
     * @param Journal $context
     * @param DataObject $object The object getting deposited
     * @param int $status
     * @param string $batchId
     * @param string $failedMsg (optional)
     * @param null|mixed $successMsg
     */
    public function updateDepositStatus($context, $object, $status, $batchId = null, $failedMsg = null, $successMsg = null)
    {
        assert($object instanceof Submission || $object instanceof Issue);
        if ($object instanceof Submission) {
            $doiIds = Repo::doi()->getDoisForSubmission($object->getId());
        } else {
            $doiIds = Repo::doi()->getDoisForIssue($object->getId(), true);
        }

        foreach ($doiIds as $doiId) {
            $doi = Repo::doi()->get($doiId);

            $editParams = [
                'status' => $status,
                // Sets new failedMsg or resets to null for removal of previous message
                $this->getFailedMsgSettingName() => $failedMsg,
                $this->getDepositBatchIdSettingName() => $batchId,
                $this->getSuccessMsgSettingName() => $successMsg,
            ];

            if ($status === Doi::STATUS_REGISTERED) {
                $editParams['registrationAgency'] = $this->getName();
            }

            Repo::doi()->edit($doi, $editParams);
        }
    }

    /**
     * @copydoc DOIPubIdExportPlugin::markRegistered()
     */
    public function markRegistered($context, $objects)
    {
        foreach ($objects as $object) {
            // Get all DOIs for each object
            // Check if submission or issue
            if ($object instanceof Submission) {
                $doiIds = Repo::doi()->getDoisForSubmission($object->getId());
            } else {
                $doiIds = Repo::doi()->getDoisForIssue($object->getId, true);
            }

            foreach ($doiIds as $doiId) {
                Repo::doi()->markRegistered($doiId);
            }
        }
    }

    /**
     * Get request failed message setting name.
     * NB: Changed as of 3.4
     *
     * @return string
     */
    public function getFailedMsgSettingName()
    {
        return $this->getPluginSettingsPrefix() . '_failedMsg';
    }

    /**
     * Get deposit batch ID setting name.
     * NB Changed as of 3.4
     *
     * @return string
     */
    public function getDepositBatchIdSettingName()
    {
        return $this->getPluginSettingsPrefix() . '_batchId';
    }

    public function getSuccessMsgSettingName(): string
    {
        return $this->getPluginSettingsPrefix() . '_successMsg';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getDepositSuccessNotificationMessageKey()
     */
    public function getDepositSuccessNotificationMessageKey()
    {
        return 'plugins.importexport.common.register.success';
    }

    /**
     * @param Submission|Issue $object
     *
     */
    private function _getObjectFileNamePart(DataObject $object): string
    {
        if ($object instanceof Submission) {
            return 'articles-' . $object->getId();
        } elseif ($object instanceof Issue) {
            return 'issues-' . $object->getId();
        } else {
            return '';
        }
    }
}
