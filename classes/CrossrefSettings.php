<?php

/**
 * @file plugins/generic/crossref/classes/CrossrefSettings.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossrefSettings
 *
 * @ingroup plugins_generic_crossref
 *
 * @brief Setting management class to handle schema, fields, validation, etc. for Crossref plugin
 */

namespace APP\plugins\generic\crossref\classes;

use APP\core\Application;
use PKP\components\forms\FieldHTML;
use PKP\components\forms\FieldOptions;
use PKP\components\forms\FieldText;
use PKP\context\Context;
use PKP\core\PKPApplication;

class CrossrefSettings extends \PKP\doi\RegistrationAgencySettings
{
    public function getSchema(): \stdClass
    {
        return (object) [
            'title' => 'Crossref Plugin',
            'description' => 'Registration agency plugin for Crossref',
            'type' => 'object',
            'required' => ['depositorName', 'depositorEmail'],
            'properties' => (object) [
                'depositorName' => (object) [
                    'type' => 'string',
                    'validation' => ['required', 'max:60'],
                ],
                'depositorEmail' => (object) [
                    'type' => 'string',
                    'validation' => ['required', 'email', 'max:90'],
                ],
                'username' => (object) [
                    'type' => 'string',
                    'validation' => ['nullable', 'max:120'],
                ],
                'password' => (object) [
                    'type' => 'string',
                    'validation' => ['nullable', 'max:50'],
                ],
                'testMode' => (object) [
                    'type' => 'boolean',
                ]
            ],
        ];
    }

    /** @inheritDoc */
    public function getFields(Context $context): array
    {
        return [
            new FieldHTML('preamble', [
                'label' => __('plugins.importexport.crossref.settings'),
                'description' => $this->_getPreambleText($context),
            ]),
            new FieldText('depositorName', [
                'label' => __('plugins.importexport.crossref.settings.form.depositorName'),
                'description' => __('plugins.importexport.crossref.settings.form.depositorName.description'),
                'isRequired' => true,
                'value' => $this->agencyPlugin->getSetting($context->getId(), 'depositorName'),
            ]),
            new FieldText('depositorEmail', [
                'label' => __('plugins.importexport.crossref.settings.form.depositorEmail'),
                'description' => __('plugins.importexport.crossref.settings.form.depositorEmail.description'),
                'isRequired' => true,
                'value' => $this->agencyPlugin->getSetting($context->getId(), 'depositorEmail'),
            ]),
            new FieldHTML('credentialsExplanation', [
                'description' => __('plugins.importexport.crossref.registrationIntro'),
            ]),
            new FieldText('username', [
                'label' => __('plugins.importexport.crossref.settings.form.username'),
                'description' => __('plugins.importexport.crossref.settings.form.username.description'),
                'value' => $this->agencyPlugin->getSetting($context->getId(), 'username'),
                'inputType' => 'text',
            ]),
            new FieldText('password', [
                'label' => __('plugins.importexport.common.settings.form.password'),
                'description' => __('plugins.importexport.common.settings.form.password.description'),
                'value' => $this->agencyPlugin->getSetting($context->getId(), 'password'),
                'inputType' => 'password',
            ]),
            new FieldOptions('testMode', [
                'label' => __('plugins.importexport.common.settings.form.testMode.label'),
                'options' => [
                    ['value' => true, 'label' => __('plugins.importexport.crossref.settings.form.testMode.description')]
                ],
                'value' => (bool) $this->agencyPlugin->getSetting($context->getId(), 'testMode'),
            ])
        ];
    }

    protected function _getPreambleText(Context $context): string
    {
        $text = '';

        $journalSettingsUrl = Application::get()->getDispatcher()->url(
            Application::get()->getRequest(),
            PKPApplication::ROUTE_PAGE,
            $context->getPath(),
            'management',
            'settings',
            'context'
        );

        $notices = [];
        if (!$context->getData('publisherInstitution')) {
            $notices[] = __('plugins.importexport.crossref.error.publisherNotConfigured', ['journalSettingsUrl' => $journalSettingsUrl]);
        }

        if (!$context->getData('onlineIssn') && !$context->getData('printIssn')) {
            $notices[] = __('plugins.importexport.crossref.error.issnNotConfigured', ['journalSettingsUrl' => $journalSettingsUrl]);
        }

        if (!empty($notices)) {
            $text .= '<div class="pkpNotification pkpNotification--warning">';
            $text .= '<p><strong>' . __('plugins.importexport.common.missingRequirements') . '</strong></p><ul>';

            foreach ($notices as $notice) {
                $text .= '<li>' . $notice . '</li>';
            }

            $text .= '</ul></div>';
        }

        $text .= '<p>' . __('plugins.importexport.crossref.settings.depositorIntro') . '</p>';

        return $text;
    }
}
