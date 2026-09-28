<template>
	<div :class="cn('root')">
		<p :class="cn('count')">
			{{
				t('plugins.generic.crossref.citedBy.citationCount', {
					count: store.total,
				})
			}}
		</p>

		<div :class="cn('citationsWrapper')" v-if="store.total > 0">
			<ul :class="cn('citationsList')" role="list">
				<li
					v-for="(citation, index) in store.citations"
					:key="index"
					:class="cn('citationsListItem')"
				>
					<div :class="cn('citationsListItemContent')">
						<h3 :class="cn('citationTitle')">
							{{ citation.title }}
						</h3>

						<p :class="cn('citationAuthors')">
							{{ citation.authors }}
						</p>

						<p :class="cn('citationSource')">
							<span>
								<template
									v-for="(source, index) in store.getSourceLine(citation)"
									:key="index"
								>
									<span>{{ source }}</span>
									<span
										:class="cn('citationSourceDelimiter')"
										v-if="index < store.getSourceLine(citation).length - 1"
									>
										.
									</span>
								</template>
							</span>
						</p>

						<p :class="cn('citationDoi')">
							<a
								v-if="citation.doi"
								:href="store.getDoiExternalLink(citation.doi)"
								target="_blank"
								rel="noopener noreferrer"
							>
								{{ `doi.org/${citation.doi}` }}
							</a>
						</p>
					</div>
				</li>
			</ul>
		</div>

		<div :class="cn('actions')">
			<PkpButton
				:class="cn('root')"
				:is-disabled="store.isLoading || store.total < 1"
				@click="store.copyAllToClipboard()"
			>
				{{
					store.copiedToClipboard
						? t('common.copied')
						: t('plugins.generic.crossref.citedBy.copyCitationDetails')
				}}
			</PkpButton>

			<PkpButton :class="cn('root')" @click="onClose" :is-secondary="true">
				{{ t('common.close') }}
			</PkpButton>
		</div>
	</div>
</template>

<script setup>
import {useCrossrefCitedByStore} from './useCrossrefCitedByStore.js';

const {usePkpLocalize} = pkp.modules.usePkpLocalize;
const {t} = usePkpLocalize();
const {usePkpStyles} = pkp.modules.usePkpStyles;

defineProps({
	onClose: {type: Function, default: () => () => {}},
});

const {cn} = usePkpStyles('CrossrefCitedByBody');
const store = useCrossrefCitedByStore();
</script>
