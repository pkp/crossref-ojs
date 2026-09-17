<template>
	<div :class="cn('root')">
		<span :class="cn('count')">{{ store.total }}</span>
	</div>
</template>

<script setup>
import {useCrossrefCitedByStore} from './useCrossrefCitedByStore.js';
const {usePkpStyles} = pkp.modules.usePkpStyles;

const props = defineProps({
	submissionId: {type: Number, required: true},
	styles: {type: Object, default: () => ({})},
});

const store = useCrossrefCitedByStore();
const {cn} = usePkpStyles('CrossrefCitedByCount', props.styles);

store.initialize(props);
</script>
