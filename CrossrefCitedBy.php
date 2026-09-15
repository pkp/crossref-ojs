<?php

/**
 * @file plugins/generic/crossref/CrossrefCitedBy.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossrefCitedBy
 *
 * @brief Handles configurations for Crossref Cited-by.
 *
 */

namespace APP\plugins\generic\crossref;

use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\core\APIRouter;
use PKP\plugins\Hook;

class CrossrefCitedBy
{
    public function __construct(
        protected CrossrefPlugin $plugin
    )
    {
    }

    /**
     * Add Crossref CitedBy API endpoint
     */
    public function registerEndpoints(): void
    {
        Hook::add('APIHandler::endpoints::plugin', function (string $hookName, APIRouter $apiRouter): bool {
            $apiRouter->registerPluginApiControllers([
                new CrossrefCitedByController(),
            ]);

            return Hook::CONTINUE;
        });
    }

    /**
     * Register hooks that require the plugin to be enabled.
     */
    public function registerEnabledHooks(): void
    {
        Hook::add('Templates::Article::Footer::PageFooter', $this->displayCitedByComponent(...));
    }

    /**
     * Hook to add Crossref Cited-by to the article page.
     * @param array $params [
     * @option article,
     * @option TemplateManager,
     * @option string Rendered smarty template
     * ]
     */
    public function displayCitedByComponent(string $hookName, array $params): bool
    {
        /** @var TemplateManager $templateMgr */
        $templateMgr = &$params[1];
        $output = &$params[2];

        if (
            !$this->plugin->getSetting($this->plugin->getCurrentContextId(), 'citedBy') ||
            !$this->plugin->hasCrossrefCredentials($this->plugin->getCurrentContextId())
        ) {
            return Hook::CONTINUE;
        }

        /** @var Submission $article */
        $article = &$params[0];
        if (!$article) {
            return Hook::CONTINUE;
        }

        $templateMgr->assign([
            'citedByConfig' => ['submissionId' => $article->getId()],
        ]);

        $output .= $templateMgr->fetch($this->plugin->getTemplateResource('citedBy'));
        return Hook::CONTINUE;
    }


    /**
     * Hook to add Crossref Cited-by template configurations.
     * Load the CitedBy component scripts and styles on the article page and expose
     * the isCitedByEnabled template variable themes use to decide whether to render the components.
     */
    public function setupCitedByComponents(string $hookName, array $params): bool
    {
        $request = $params[0];
        $isCitedByEnabled = (bool)$this->plugin->getSetting($this->plugin->getCurrentContextId(), 'citedBy');

        $templateMgr = TemplateManager::getManager($request);

        if ($isCitedByEnabled) {
            $templateMgr->setLocaleKeys($this->getLocaleKeys());

            $scriptArgs = [
                'contexts' => ['frontend'],
                'priority' => TemplateManager::STYLE_SEQUENCE_LAST
            ];

            $templateMgr->addJavaScript(
                'CrossrefCitedBy',
                "{$request->getBaseUrl()}/{$this->plugin->getPluginPath()}/public/build/crossref.js",
                $scriptArgs
            );

            $templateMgr->addJavaScript(
                'CrossrefCitedByBody',
                "{$request->getBaseUrl()}/{$this-> plugin->getPluginPath()}/public/build/crossref.js",
                $scriptArgs
            );

            $templateMgr->addJavaScript(
                'CrossrefCitedByCount',
                "{$request->getBaseUrl()}/{$this-> plugin->getPluginPath()}/public/build/crossref.js",
                $scriptArgs
            );

            $templateMgr->addJavaScript(
                'CrossrefCitedByBody',
                "{$request->getBaseUrl()}/{$this-> plugin->getPluginPath()}/public/build/crossref.js",
                $scriptArgs
            );

            $templateMgr->addStyleSheet(
                'crossref.css',
                "{$request->getBaseUrl()}/{$this-> plugin->getPluginPath()}/public/build/crossref.css",
                $scriptArgs
            );
        }

        $templateMgr->assign('isCitedByEnabled', $isCitedByEnabled);
        return Hook::CONTINUE;
    }

    /**
     * Get crossref Cited-by citation types
     */
    public static function citationTypes(): array
    {
        return [
            'book_cite',
            'conf_cite',
            'database_cite',
            'dissertation_cite',
            'journal_cite',
            'msg',
            'report_cite',
            'standard_cite'
        ];
    }

    /**
     * Get the locale keys to expose for the CitedBy components.
     */
    public function getLocaleKeys(): array
    {
        return [
            'plugins.generic.crossref.citedBy.copyCitationDetails',
            'common.close',
            'common.copied',
            'plugins.generic.crossref.citedBy.title',
            'plugins.generic.crossref.registrationAgency.name',
            'plugins.generic.crossref.citedBy.citationCount',
        ];
    }
}
