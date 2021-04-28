<?php

/**
 * @defgroup plugins_generic_crossref CrossRef Plugin
 */

/**
 * @file plugins/generic/crossref/index.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @ingroup plugins_generic_crossref
 * @brief Wrapper for CrossRef export plugin.
 *
 */

require_once('CrossRefPlugin.inc.php');

return new CrossRefPlugin();
