<template>
	<div :class="cn('root')" @click="store.openCitedByModal">
		<div :class="cn('openIconWrapper')">
			<OpenNewTab :class="cn('openIcon')"></OpenNewTab>
		</div>

		<div :class="cn('label')">
			{{ t('plugins.generic.crossref.registrationAgency.name') }}
		</div>
		<h2 :class="cn('count')">
			{{ store.total }}
		</h2>
	</div>
</template>

<script setup>
import {useCrossrefCitedByStore} from './useCrossrefCitedByStore.js';
import OpenNewTab from './icons/OpenNewTab.vue';

const {usePkpStyles} = pkp.modules.usePkpStyles;
const {usePkpLocalize} = pkp.modules.usePkpLocalize;
const {t} = usePkpLocalize();
const props = defineProps({
	submissionId: {type: Number, required: true},
	styles: {type: Object, default: () => ({})},
});

const {cn, nestedStyles} = usePkpStyles('CrossrefCitedBy', props.styles);
const store = useCrossrefCitedByStore();
store.initialize(props, nestedStyles);
</script>
