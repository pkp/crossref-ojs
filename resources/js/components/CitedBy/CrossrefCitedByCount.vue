<template>
	{{ store.totalDisplay }}
</template>

<script setup>
import {useCrossrefCitedByStore} from './useCrossrefCitedByStore.js';

const store = useCrossrefCitedByStore();
store.ensureCitationsLoaded();
</script>
