<template>
	<div :class="cn('root')">
		<div>
			<h5 :class="cn('bodyCitationsCount')">
				{{
					t('plugins.generic.crossref.citedBy.citationCount', {
						count: store.total,
					})
				}}
			</h5>
		</div>

		<div :class="cn('citationsWrapper')">
			<ul :class="cn('citationsList')">
				<li
					v-for="citation in store.citations"
					:key="citation.doi"
					:class="cn('citationsListItem')"
				>
					<div :class="cn('citationsListItemContent')">
						<h5 :class="cn('title')">
							{{ citation.title }}
						</h5>

						<p :class="cn('authors')">
							{{ citation.authors }}
						</p>

						<p :class="cn('citationSource')">
							<span>
								<template
									v-for="(source, index) in getSourceLine(citation)"
									:key="index"
								>
									<span>{{ source }}</span>
									<span
										:class="cn('sourceDelimiter')"
										v-if="index < getSourceLine(citation).length - 1"
									>
										{{
											t(
												'plugins.generic.crossref.citedBy.citationSource.separator',
											)
										}}
									</span>
								</template>
							</span>
						</p>

						<a
							v-if="citation.doi"
							:href="store.getDoiExternalLink(citation.doi)"
							target="_blank"
							:class="cn('doi')"
						>
							{{ `doi.org/${citation.doi}` }}
							<OpenNewTab icon="OpenNewTab" :class="cn('openIcon')" />
						</a>
					</div>
				</li>
			</ul>
		</div>

		<div :class="cn('actions')">
			<PkpButton
				:is-disabled="store.isLoading || store.total < 1"
				@click="store.copyAllToClipboard()"
			>
				{{
					store.copiedToClipboard
						? t('common.copied')
						: t('plugins.generic.crossref.citedBy.copyCitationDetails')
				}}
			</PkpButton>

			<PkpButton :class="cn('actionsCloseBtn')" @click="onClose">
				{{ t('common.close') }}
			</PkpButton>
		</div>
	</div>
</template>

<script setup>
import {useCrossrefCitedByStore} from './useCrossrefCitedByStore.js';
import OpenNewTab from './icons/OpenNewTab.vue';

const {usePkpLocalize} = pkp.modules.usePkpLocalize;
const {t} = usePkpLocalize();
const {usePkpStyles} = pkp.modules.usePkpStyles;

const props = defineProps({
	styles: {type: Object, default: () => ({})},
	onClose: {type: Function, default: () => () => {}},
});

const {cn} = usePkpStyles('CrossrefCitedByBody', props.styles);
const store = useCrossrefCitedByStore();


function getSourceLine(citation) {
	const source = [
		citation?.journal,
		citation?.institutionName,
		citation?.year,
		getSourceLocator(citation),
	];

	return source.filter(Boolean);
}

function getSourceLocator(citation) {
	const parts = [];

	if (citation.volume) {
		parts.push(
			citation.issue
				? t('plugins.generic.crossref.citedBy.citationSource.volumeWithIssue', {
						volume: citation.volume,
						issue: citation.issue,
					})
				: t('plugins.generic.crossref.citedBy.citationSource.volume', {
						volume: citation.volume,
					}),
		);
	} else if (citation.issue) {
		parts.push(
			t('plugins.generic.crossref.citedBy.citationSource.issueWithoutVolume', {
				issue: citation.issue,
			}),
		);
	}

	if (citation.firstPage) {
		parts.push('p' + citation.firstPage);
	}

	return parts.join(', ');
}
</script>
