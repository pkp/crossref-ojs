/**
 * @file plugins/generic/crossref/resources/js/main.js
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @brief Registers the plugin's frontend Vue components with the core registry.
 */
import CrossrefCrossmarkButton from "./components/CrossrefCrossmarkButton.vue";
import CrossrefCitedBy from "./components/CitedBy/CrossrefCitedBy.vue"
import CrossrefCitedByBody from './components/CitedBy/CrossrefCitedByBody.vue';
import CrossrefCitedByCount from './components/CitedBy/CrossrefCitedByCount.vue';

import "../styles/crossref.css";

pkp.registry.registerComponent("CrossrefCrossmarkButton", CrossrefCrossmarkButton);
pkp.registry.registerComponent('CrossrefCitedBy', CrossrefCitedBy);
pkp.registry.registerComponent('CrossrefCitedByBody', CrossrefCitedByBody);
pkp.registry.registerComponent('CrossrefCitedByCount', CrossrefCitedByCount);


