<template>
	<a data-target="crossmark">
		<img
			src="https://crossmark-cdn.crossref.org/widget/v2.0/logos/CROSSMARK_Color_horizontal.svg"
			width="150"
			alt="Crossmark"
		/>
	</a>
</template>

<script setup>
import {onMounted} from 'vue';

// The Crossmark widget binds its click handler to [data-target="crossmark"]
// elements once at DOMContentLoaded; re-bind after Vue (re)creates this element.
onMounted(() => {
	if (document.CROSSMARK) {
		document.CROSSMARK.bind();
	}
});
</script>
