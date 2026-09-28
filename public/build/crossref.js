(function(vue, pinia) {
	//#region resources/js/components/CrossrefCrossmarkButton.vue
	var _hoisted_1$1 = { "data-target": "crossmark" };
	var _sfc_main$2 = {
		__name: "CrossrefCrossmarkButton",
		setup(__props) {
			(0, vue.onMounted)(() => {
				if (document.CROSSMARK) document.CROSSMARK.bind();
			});
			return (_ctx, _cache) => {
				return (0, vue.openBlock)(), (0, vue.createElementBlock)("a", _hoisted_1$1, [..._cache[0] || (_cache[0] = [(0, vue.createElementVNode)("img", {
					src: "https://crossmark-cdn.crossref.org/widget/v2.0/logos/CROSSMARK_Color_horizontal.svg",
					width: "150",
					alt: "Crossmark"
				}, null, -1)])]);
			};
		}
	};
	//#endregion
	//#region resources/js/components/CitedBy/useCrossrefCitedByStore.js
	var { usePkpFetch } = pkp.modules.usePkpFetch;
	var { usePkpModal } = pkp.modules.usePkpModal;
	var { usePkpLocalize } = pkp.modules.usePkpLocalize;
	var { useUrl } = pkp.modules.usePkpUrl;
	var { usePkpPageData } = pkp.modules.usePkpPageData;
	var { t } = usePkpLocalize();
	var useCrossrefCitedByStore = (0, pinia.defineStore)("crossrefCitedBy", () => {
		/**
		* Init data passed from the server via
		* TemplateManager::setPiniaStoreData('crossrefCitedBy', ...)
		* @type {{submissionId?: number}}
		*/
		const { submissionId } = usePkpPageData().getStoreData("crossrefCitedBy");
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
		const citations = (0, vue.ref)([]);
		const total = (0, vue.ref)(0);
		const isLoading = (0, vue.ref)(false);
		const copiedToClipboard = (0, vue.ref)(false);
		let loadPromise = null;
		const hasError = (0, vue.ref)(false);
		const totalDisplay = (0, vue.computed)(() => {
			if (hasError.value || isLoading.value) return "--";
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
			if (!submissionId) return;
			const { apiUrl } = useUrl(`crossref/citedBy/${submissionId}`);
			isLoading.value = true;
			const { data, fetch, isSuccess } = usePkpFetch(apiUrl, {
				method: "GET",
				expectValidationError: true
			});
			await fetch();
			if (isSuccess.value) {
				citations.value = data.value.items;
				total.value = data.value.itemsMax;
				hasError.value = false;
			} else hasError.value = true;
			isLoading.value = false;
		}
		/**
		* Build a plain-text summary of every citation (used by the
		* "Copy citation details" action) with one entry per line.
		*/
		function formatCitationForClipboard(citation, i) {
			return [
				citation.title,
				citation.authors,
				...getSourceLine(citation),
				citation.doi ? getDoiExternalLink(citation.doi) : ""
			].join(t("common.commaListSeparator"));
		}
		/**
		* Copy a plain-text summary of every fetched citation to the clipboard.
		*/
		async function copyAllToClipboard() {
			const text = citations.value.map(formatCitationForClipboard).join("\n");
			try {
				await navigator.clipboard.writeText(text);
				copiedToClipboard.value = true;
				setTimeout(() => {
					copiedToClipboard.value = false;
				}, 2e3);
			} catch {}
		}
		/**
		* Open the cited-by modal, listing every citation.
		*/
		async function openCitedByModal() {
			await ensureCitationsLoaded();
			const { openDialog, closeTopDialog } = usePkpModal();
			openDialog({
				title: t("plugins.generic.crossref.citedBy.title"),
				bodyComponent: _sfc_main$1,
				size: "large",
				bodyProps: { onClose: () => closeTopDialog() }
			});
		}
		function getDoiExternalLink(doi) {
			return `https://doi.org/${doi}`;
		}
		function getSourceLine(citation) {
			return [
				citation?.journal,
				citation?.institutionName,
				citation?.year,
				getSourceLocator(citation)
			].filter(Boolean);
		}
		function getSourceLocator(citation) {
			const parts = [];
			if (citation.volume) parts.push(citation.issue ? t("plugins.generic.crossref.citedBy.citationSource.volumeWithIssue", {
				volume: citation.volume,
				issue: citation.issue
			}) : t("plugins.generic.crossref.citedBy.citationSource.volume", { volume: citation.volume }));
			else if (citation.issue) parts.push(t("plugins.generic.crossref.citedBy.citationSource.issueWithoutVolume", { issue: citation.issue }));
			if (citation.firstPage) parts.push(t("plugins.generic.crossref.citedBy.citationSource.firstPage", { page: citation.firstPage }));
			return parts.join(t("common.commaListSeparator"));
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
			getSourceLine
		};
	});
	//#endregion
	//#region resources/js/components/CitedBy/CrossrefCitedByBody.vue
	var _hoisted_1 = ["href"];
	var _sfc_main$1 = {
		__name: "CrossrefCitedByBody",
		props: { onClose: {
			type: Function,
			default: () => () => {}
		} },
		setup(__props) {
			const { usePkpLocalize } = pkp.modules.usePkpLocalize;
			const { t } = usePkpLocalize();
			const { usePkpStyles } = pkp.modules.usePkpStyles;
			const { cn } = usePkpStyles("CrossrefCitedByBody");
			const store = useCrossrefCitedByStore();
			return (_ctx, _cache) => {
				const _component_PkpButton = (0, vue.resolveComponent)("PkpButton");
				return (0, vue.openBlock)(), (0, vue.createElementBlock)("div", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("root")) }, [
					(0, vue.createElementVNode)("p", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("count")) }, (0, vue.toDisplayString)((0, vue.unref)(t)("plugins.generic.crossref.citedBy.citationCount", { count: (0, vue.unref)(store).total })), 3),
					(0, vue.unref)(store).total > 0 ? ((0, vue.openBlock)(), (0, vue.createElementBlock)("div", {
						key: 0,
						class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationsWrapper"))
					}, [(0, vue.createElementVNode)("ul", {
						class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationsList")),
						role: "list"
					}, [((0, vue.openBlock)(true), (0, vue.createElementBlock)(vue.Fragment, null, (0, vue.renderList)((0, vue.unref)(store).citations, (citation, index) => {
						return (0, vue.openBlock)(), (0, vue.createElementBlock)("li", {
							key: index,
							class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationsListItem"))
						}, [(0, vue.createElementVNode)("div", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationsListItemContent")) }, [
							(0, vue.createElementVNode)("h3", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationTitle")) }, (0, vue.toDisplayString)(citation.title), 3),
							(0, vue.createElementVNode)("p", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationAuthors")) }, (0, vue.toDisplayString)(citation.authors), 3),
							(0, vue.createElementVNode)("p", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationSource")) }, [(0, vue.createElementVNode)("span", null, [((0, vue.openBlock)(true), (0, vue.createElementBlock)(vue.Fragment, null, (0, vue.renderList)((0, vue.unref)(store).getSourceLine(citation), (source, index) => {
								return (0, vue.openBlock)(), (0, vue.createElementBlock)(vue.Fragment, { key: index }, [(0, vue.createElementVNode)("span", null, (0, vue.toDisplayString)(source), 1), index < (0, vue.unref)(store).getSourceLine(citation).length - 1 ? ((0, vue.openBlock)(), (0, vue.createElementBlock)("span", {
									key: 0,
									class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationSourceDelimiter"))
								}, " . ", 2)) : (0, vue.createCommentVNode)("", true)], 64);
							}), 128))])], 2),
							(0, vue.createElementVNode)("p", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationDoi")) }, [citation.doi ? ((0, vue.openBlock)(), (0, vue.createElementBlock)("a", {
								key: 0,
								href: (0, vue.unref)(store).getDoiExternalLink(citation.doi),
								target: "_blank",
								rel: "noopener noreferrer"
							}, (0, vue.toDisplayString)(`doi.org/${citation.doi}`), 9, _hoisted_1)) : (0, vue.createCommentVNode)("", true)], 2)
						], 2)], 2);
					}), 128))], 2)], 2)) : (0, vue.createCommentVNode)("", true),
					(0, vue.createElementVNode)("div", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("actions")) }, [(0, vue.createVNode)(_component_PkpButton, {
						class: (0, vue.normalizeClass)((0, vue.unref)(cn)("root")),
						"is-disabled": (0, vue.unref)(store).isLoading || (0, vue.unref)(store).total < 1,
						onClick: _cache[0] || (_cache[0] = ($event) => (0, vue.unref)(store).copyAllToClipboard())
					}, {
						default: (0, vue.withCtx)(() => [(0, vue.createTextVNode)((0, vue.toDisplayString)((0, vue.unref)(store).copiedToClipboard ? (0, vue.unref)(t)("common.copied") : (0, vue.unref)(t)("plugins.generic.crossref.citedBy.copyCitationDetails")), 1)]),
						_: 1
					}, 8, ["class", "is-disabled"]), (0, vue.createVNode)(_component_PkpButton, {
						class: (0, vue.normalizeClass)((0, vue.unref)(cn)("root")),
						onClick: __props.onClose,
						"is-secondary": true
					}, {
						default: (0, vue.withCtx)(() => [(0, vue.createTextVNode)((0, vue.toDisplayString)((0, vue.unref)(t)("common.close")), 1)]),
						_: 1
					}, 8, ["class", "onClick"])], 2)
				], 2);
			};
		}
	};
	//#endregion
	//#region resources/js/components/CitedBy/CrossrefCitedByCount.vue
	var _sfc_main = {
		__name: "CrossrefCitedByCount",
		setup(__props) {
			const store = useCrossrefCitedByStore();
			store.ensureCitationsLoaded();
			return (_ctx, _cache) => {
				return (0, vue.toDisplayString)((0, vue.unref)(store).totalDisplay);
			};
		}
	};
	//#endregion
	//#region resources/js/main.js
	/**
	* @file plugins/generic/crossref/resources/js/main.js
	*
	* Copyright (c) 2026 Simon Fraser University
	* Copyright (c) 2026 John Willinsky
	* Distributed under The MIT License. For full terms see the file LICENSE.
	*
	* @brief Registers the plugin's frontend Vue components with the core registry.
	*/
	pkp.registry.registerComponent("CrossrefCrossmarkButton", _sfc_main$2);
	pkp.registry.registerComponent("CrossrefCitedByBody", _sfc_main$1);
	pkp.registry.registerComponent("CrossrefCitedByCount", _sfc_main);
	pkp.registry.registerStore("crossrefCitedBy", useCrossrefCitedByStore);
	//#endregion
})(pkp.modules.vue, pkp.modules.pinia);
