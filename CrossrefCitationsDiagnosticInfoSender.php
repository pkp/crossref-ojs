<?php

/**
 * @file CrossrefCitationsDiagnosticInfoSender.php
 *
 * Copyright (c) 2013-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CrossrefCitationsDiagnosticInfoSender
 * @brief Scheduled task to check for found Crossref citation DOIs.
 */

namespace APP\plugins\generic\crossref;

use APP\core\Application;
use APP\journal\Journal;
use APP\journal\JournalDAO;
use PKP\db\DAOResultFactory;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;

class CrossrefCitationsDiagnosticInfoSender extends ScheduledTask
{

    protected CrossrefPlugin $plugin;

    /**
     * Constructor.
     * @param $args array task arguments
     */
    public function __construct(array $args)
    {
        parent::__construct($args);
        $this->plugin = PluginRegistry::getPlugin('generic', 'crossrefplugin');
        $this->plugin->addLocaleData();
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
            $this->plugin->considerFoundCrossrefReferencesDOIs($journal);
        }
        return true;
    }

    /**
     * Get all journals that meet the requirements to have
     * their articles or issues DOIs sent to Crossref.
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
