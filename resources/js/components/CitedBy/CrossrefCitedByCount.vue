<template>
	<div>
		{{ store.total }}
	</div>
</template>

<script setup>
import {useCrossrefCitedByStore} from './useCrossrefCitedByStore.js';

const props = defineProps({
	submissionId: {type: Number, required: true},
});

const store = useCrossrefCitedByStore();

store.initialize(props);
</script>
