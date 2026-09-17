<template>
	<div :class="cn('root')">
		<template v-if="isCompactDisplay">
			<h2 :class="cn('count')">
				{{ store.total }}
			</h2>

			<div :class="cn('label')">
				{{ t('plugins.generic.crossref.citedBy.viaCrossref') }}
			</div>
		</template>
		<template v-else>
			<span
				:class="cn('label')"
				v-html="
					t('plugins.generic.crossref.citedBy.thisArticleHasBeenCited', {
						count: store.total,
					})
				"
			></span>
		</template>
		<a @click="store.openCitedByModal" :class="cn('viewCitations')">
			{{ t('plugins.generic.crossref.citedBy.viewCitingArticles') }}
		</a>
	</div>
</template>

<script setup>
import {useCrossrefCitedByStore} from './useCrossrefCitedByStore.js';
const {usePkpStyles} = pkp.modules.usePkpStyles;
const {usePkpLocalize} = pkp.modules.usePkpLocalize;
const {t} = usePkpLocalize();
const props = defineProps({
	submissionId: {type: Number, required: true},
	isCompactDisplay: {type: Boolean, default: false},
	styles: {type: Object, default: () => ({})},
});

const {cn, nestedStyles} = usePkpStyles('CrossrefCitedBy', props.styles);
const store = useCrossrefCitedByStore();
store.initialize(props, nestedStyles);
</script>
