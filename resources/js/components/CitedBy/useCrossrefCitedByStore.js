import {ref, computed} from 'vue';
import CrossrefCitedByBody from './CrossrefCitedByBody.vue';
import {defineStore} from 'pinia';

const {usePkpFetch} = pkp.modules.usePkpFetch;
const {usePkpModal} = pkp.modules.usePkpModal;
const {usePkpLocalize} = pkp.modules.usePkpLocalize;
const {useUrl} = pkp.modules.usePkpUrl;
const {usePkpPageData} = pkp.modules.usePkpPageData;
const {t} = usePkpLocalize();

export const useCrossrefCitedByStore = defineStore('crossrefCitedBy', () => {
	/**
	 * Init data passed from the server via
	 * TemplateManager::setPiniaStoreData('crossrefCitedBy', ...)
	 * @type {{submissionId?: number}}
	 */
	const {submissionId} = usePkpPageData().getStoreData('crossrefCitedBy');

	/**
	 * @type {Array<{
	 *   title: string|null,
	 *   authors: string,
	 *   doi: string|null,
	 *   year: number|null,
	 *   volume: number|null,
	 *   issue: string|null,
	 *   firstPage: number|null,
	 *   citationType: string,
	 *   journal?: string|null,
	 *   institutionName?: string|null
	 * }>}
	 */
	const citations = ref([]);
	const total = ref(0);
	const isLoading = ref(false);
	const copiedToClipboard = ref(false);
	let loadPromise = null;
	const hasError = ref(false);

	const totalDisplay = computed(() => {
		if (hasError.value || isLoading.value) {
			return '--';
		}
		return total.value;
	});
	/**
	 * Fetch the citations. Called by the components displaying them;
	 * they are fetched only once, however many components call it.
	 */
	function ensureCitationsLoaded() {
		loadPromise ??= loadCitations();
		return loadPromise;
	}

	async function loadCitations() {
		if (!submissionId) {
			return;
		}

		const {apiUrl} = useUrl(`crossref/citedBy/${submissionId}`);

		isLoading.value = true;

		const {data, fetch, isSuccess} = usePkpFetch(apiUrl, {
			method: 'GET',
			expectValidationError: true,
		});

		await fetch();

		if (isSuccess.value) {
			citations.value = data.value.items;
			total.value = data.value.itemsMax;
			hasError.value = false;
		} else {
			hasError.value = true;
		}

		isLoading.value = false;
	}

	/**
	 * Build a plain-text summary of every citation (used by the
	 * "Copy citation details" action) with one entry per line.
	 */
	function formatCitationForClipboard(citation, i) {
		const parts = [
			citation.title,
			citation.authors,
			...getSourceLine(citation),
			citation.doi ? getDoiExternalLink(citation.doi) : '',
		];

		return parts.join(t('common.commaListSeparator'));
	}

	/**
	 * Copy a plain-text summary of every fetched citation to the clipboard.
	 */
	async function copyAllToClipboard() {
		const text = citations.value.map(formatCitationForClipboard).join('\n');

		try {
			await navigator.clipboard.writeText(text);
			copiedToClipboard.value = true;
			setTimeout(() => {
				copiedToClipboard.value = false;
			}, 2000);
		} catch {
			// Clipboard API not available or denied
		}
	}

	/**
	 * Open the cited-by modal, listing every citation.
	 */
	async function openCitedByModal() {
		await ensureCitationsLoaded();

		const {openDialog, closeTopDialog} = usePkpModal();

		openDialog({
			title: t('plugins.generic.crossref.citedBy.title'),
			bodyComponent: CrossrefCitedByBody,
			size: 'large',
			bodyProps: {
				onClose: () => closeTopDialog(),
			},
		});
	}

	function getDoiExternalLink(doi) {
		return `https://doi.org/${doi}`;
	}

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
					? t(
							'plugins.generic.crossref.citedBy.citationSource.volumeWithIssue',
							{
								volume: citation.volume,
								issue: citation.issue,
							},
						)
					: t('plugins.generic.crossref.citedBy.citationSource.volume', {
							volume: citation.volume,
						}),
			);
		} else if (citation.issue) {
			parts.push(
				t(
					'plugins.generic.crossref.citedBy.citationSource.issueWithoutVolume',
					{
						issue: citation.issue,
					},
				),
			);
		}

		if (citation.firstPage) {
			parts.push(
				t('plugins.generic.crossref.citedBy.citationSource.firstPage', {
					page: citation.firstPage,
				}),
			);
		}

		return parts.join(t('common.commaListSeparator'));
	}

	return {
		citations,
		isLoading,
		copiedToClipboard,
		totalDisplay,
		total,
		ensureCitationsLoaded,
		openCitedByModal,
		copyAllToClipboard,
		getDoiExternalLink,
		getSourceLine,
	};
});
