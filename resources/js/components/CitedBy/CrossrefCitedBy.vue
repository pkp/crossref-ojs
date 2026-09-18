<template>
	<div :class="cn('root')">
		<div :class="cn(isCompactDisplay ? 'compact' : 'detailed')">
			<template v-if="isCompactDisplay">
				<h2 :class="cn('count')">
					{{ store.total }}
				</h2>

				<div :class="cn('label')">
					{{ t('plugins.generic.crossref.citedBy.viaCrossref') }}
				</div>
			</template>

			<div
				v-else
				:class="cn('label')"
				v-html="
					t('plugins.generic.crossref.citedBy.thisArticleHasBeenCited', {
						count: store.total,
					})
				"
			></div>

			<button
				@click="store.openCitedByModal"
				:class="cn('viewCitations')"
				v-if="store.total"
				type="button"
			>
				{{ t('plugins.generic.crossref.citedBy.viewCitingArticles') }}
			</button>
		</div>
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
