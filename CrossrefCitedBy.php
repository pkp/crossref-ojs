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

use APP\publication\Publication;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\core\APIRouter;
use PKP\plugins\Hook;
use PKP\context\Context;


class CrossrefCitedBy
{
    public function __construct(
        protected CrossrefPlugin $plugin,
        protected Context $context
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
        Hook::add('Templates::Article::Details', $this->displayCitedByComponent(...));
    }

    /**
     * Hook to add Crossref Cited-by to the article page.
     */
    public function displayCitedByComponent(string $hookName, array $params): bool
    {
        /** @var TemplateManager $templateMgr */
        $templateMgr = &$params[1];
        $output = &$params[2];

        if (!$templateMgr->getTemplateVars('isCitedByEnabled') || !$templateMgr->getTemplateVars('article')) {
            return Hook::CONTINUE;
        }

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
        $templateMgr = TemplateManager::getManager($request);

        /** @var Submission $article */
        $article = &$params[2];

        if (!$article) {
            return Hook::CONTINUE;
        }

        $articleHasPublishedDoi = array_any($article->getPublishedPublications(), fn(Publication $publication) => !!$publication->getDoi());

        $isCitedByEnabled = self::isCitedByEnabled($this->context) && $articleHasPublishedDoi;

        if ($isCitedByEnabled) {
            $templateMgr->requiresVueRuntime();
            $templateMgr->setLocaleKeys($this->getLocaleKeys());
            $templateMgr->setPiniaStoreData('crossrefCitedBy', ['submissionId' => $article->getId()]);

            $this->plugin->loadCommonRuntimeScripts();

            // Styles are written for the default theme; other themes style the components themselves
            if ($this->plugin->isThemeActive('defaultthemeplugin')) {
                $this->plugin->loadCommonCrossrefStyles();
            }
        }

        $templateMgr->assign('isCitedByEnabled', $isCitedByEnabled);
        return Hook::CONTINUE;
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
            'plugins.generic.crossref.citedBy.citationCount',
            'plugins.generic.crossref.citedBy.citationSource.issueWithoutVolume',
            'plugins.generic.crossref.citedBy.citationSource.volumeWithIssue',
            'plugins.generic.crossref.citedBy.citationSource.volume',
            'plugins.generic.crossref.citedBy.citationSource.firstPage',
            'common.commaListSeparator',
            'plugins.generic.crossref.citedBy.api.error',
        ];
    }

    /**
     * Check if Crossref Cited-by is enabled for the given context.
     */
    public static function isCitedByEnabled(Context $context): bool
    {
        $enabledRegistrationAgency = $context->getConfiguredDoiAgency();

        return $enabledRegistrationAgency instanceof CrossrefPlugin &&
            $enabledRegistrationAgency->getSetting($context->getId(), 'citedBy') &&
            $enabledRegistrationAgency->hasCrossrefCredentials($context->getId());
    }
}
