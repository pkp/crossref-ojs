import {ref} from 'vue';
import CrossrefCitedByBody from './CrossrefCitedByBody.vue';
const {usePkpFetch} = pkp.modules.usePkpFetch;
const {usePkpModal} = pkp.modules.usePkpModal;
const {usePkpLocalize} = pkp.modules.usePkpLocalize;
const {useUrl} = pkp.modules.usePkpUrl;
const {t} = usePkpLocalize();
import {defineStore} from 'pinia';

export const useCrossrefCitedByStore = defineStore('crossrefCitedBy', () => {
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
	const initialized = ref(false);
	const copiedToClipboard = ref(false);
	const styles = ref({});
	const nestedStyles = ref({});

	async function initialize(config, _nestedStyles = {}) {
		if (!config) {
			return;
		}

		// Store is used by multiple components, so allow component to still be able to set styles even if the store was initialized by another component
		styles.value = {
			...styles.value,
			...(config.styles || {}),
		};

		nestedStyles.value = {
			...nestedStyles.value,
			..._nestedStyles,
		}

		if (initialized.value || isLoading.value) {
			return;
		}

		const submissionId = config.submissionId;

		const {apiUrl} = useUrl(`crossref/citedBy/${submissionId}`);

		isLoading.value = true;

		const {data, fetch} = usePkpFetch(apiUrl, {method: 'GET'});
		await fetch();

		citations.value = data.value.items;
		total.value = data.value.itemsMax;

		isLoading.value = false;
		initialized.value = true;
	}

	/**
	 * Build a plain-text summary of every citation (used by the
	 * "Copy citation details" action) with one entry per line.
	 */
	function formatCitationForClipboard(citation, i) {
		const parts = [
			i + 1,
			getDoiExternalLink(citation?.doi),
			citation.issue,
			citation.title,
			citation?.journal,
			citation.year,
			citation.volume,
			citation.authors,
			citation.firstPage,
		].filter(Boolean);
		const text = parts.join(' ');
		return text;
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
	function openCitedByModal() {
		if (!total.value) {
			return;
		}

		const {openDialog, closeTopDialog} = usePkpModal();

		openDialog({
			title: t('plugins.generic.crossref.citedBy.title'),
			bodyComponent: CrossrefCitedByBody,
			size: 'large',
			bodyProps: {
				styles: styles.value.CrossrefCitedByBody,
				onClose: () => closeTopDialog(),
			},
		});
	}

	function getDoiExternalLink(doi) {
		return `https://doi.org/${doi}`;
	}

	return {
		citations,
		isLoading,
		initialized,
		copiedToClipboard,
		total,
		initialize,
		openCitedByModal,
		copyAllToClipboard,
		getDoiExternalLink,
	};
});
