{**
 * plugins/generic/crossref/templates/citedByCount.tpl
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * Cited-by count on article details page
 *}
<div class="item cited-by-count" data-vue-root>
	<pkp-cited-by-count v-bind='{$citedByConfig|json_encode}'></pkp-cited-by-count>
</div>
