{**
 * templates/displayDOI.tpl
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Display reference DOI on the article metadata (backend) and article view page (frontend)
 *}

DOI: <a href="{$crossrefFullUrl|escape}">{$crossrefFullUrl|escape}</a>
