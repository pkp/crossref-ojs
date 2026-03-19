<?php

/**
 * @file CrossrefCitationDoiCheckTask.php
 *
 * Copyright (c) 2013-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CrossrefCitationDoiCheckTask
 * @brief Scheduled task to fetch and store matched citation DOIs from Crossref.
 */

namespace APP\plugins\generic\crossref;

use APP\core\Application;
use APP\journal\Journal;
use APP\journal\JournalDAO;
use PKP\db\DAOResultFactory;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;

class CrossrefCitationDoiCheckTask extends ScheduledTask
{

    protected ?CrossrefPlugin $plugin = null;

    /**
     * Constructor.
     * @param $args array task arguments
     */
    public function __construct(array $args)
    {
        parent::__construct($args);
        $plugin = PluginRegistry::getPlugin('generic', 'crossrefplugin');
        if ($plugin instanceof CrossrefPlugin) {
            $this->plugin = $plugin;
            $this->plugin->addLocaleData();
        }
    }

    /**
     * @copydoc ScheduledTask::getName()
     */
    public function getName(): string
    {
        return __('plugins.generic.crossref.citationsDiagnostic.senderTask.name');
    }

    /**
     * @copydoc ScheduledTask::executeActions()
     */
    public function executeActions(): bool
    {
        if (!$this->plugin) {
            return false;
        }
        foreach ($this->getJournals() as $journal) {
            $this->plugin->processPendingCitationDois($journal);
        }
        return true;
    }

    /**
     * Get all journals eligible for Crossref citation DOI matching.
     *
     * @return Journal[]
     */
    protected function getJournals(): array
    {
        $contextDao = Application::getContextDAO(); /** @var JournalDAO $contextDao */
        $contextFactory = $contextDao->getAll(true); /** @var DAOResultFactory $contextFactory */
        $journals = [];
        foreach ($contextFactory->toIterator() as $journal) { /** @var Journal $journal */
            if ($this->plugin->getEnabled($journal->getId()) &&
                $this->plugin->citationsEnabled($journal->getId()) &&
                $this->plugin->hasCrossrefCredentials($journal->getId())) {

                    $journals[] = $journal;
            }
        }
        return $journals;
    }
}
