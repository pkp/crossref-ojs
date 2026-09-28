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
import CrossrefCitedByModal from './components/CitedBy/CrossrefCitedByModal.vue';
import CrossrefCitedByCount from './components/CitedBy/CrossrefCitedByCount.vue';
import {useCrossrefCitedByStore} from './components/CitedBy/useCrossrefCitedByStore.js';

import "../styles/crossref.css";

pkp.registry.registerComponent("CrossrefCrossmarkButton", CrossrefCrossmarkButton);
pkp.registry.registerComponent('CrossrefCitedByModal', CrossrefCitedByModal);
pkp.registry.registerComponent('CrossrefCitedByCount', CrossrefCitedByCount);

pkp.registry.registerStore('crossrefCitedBy', useCrossrefCitedByStore);

