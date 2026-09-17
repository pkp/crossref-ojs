(function(vue, pinia) {
	//#region resources/js/components/CrossrefCrossmarkButton.vue
	var _hoisted_1$3 = { "data-target": "crossmark" };
	var _sfc_main$4 = {
		__name: "CrossrefCrossmarkButton",
		setup(__props) {
			(0, vue.onMounted)(() => {
				if (document.CROSSMARK) document.CROSSMARK.bind();
			});
			return (_ctx, _cache) => {
				return (0, vue.openBlock)(), (0, vue.createElementBlock)("a", _hoisted_1$3, [..._cache[0] || (_cache[0] = [(0, vue.createElementVNode)("img", {
					src: "https://crossmark-cdn.crossref.org/widget/v2.0/logos/CROSSMARK_Color_horizontal.svg",
					width: "150",
					alt: "Crossmark"
				}, null, -1)])]);
			};
		}
	};
	//#endregion
	//#region \0plugin-vue:export-helper
	var _plugin_vue_export_helper_default = (sfc, props) => {
		const target = sfc.__vccOpts || sfc;
		for (const [key, val] of props) target[key] = val;
		return target;
	};
	//#endregion
	//#region resources/js/components/CitedBy/icons/OpenNewTab.vue
	var _sfc_main$3 = {};
	var _hoisted_1$2 = {
		viewBox: "0 0 20 20",
		fill: "none",
		xmlns: "http://www.w3.org/2000/svg"
	};
	function _sfc_render(_ctx, _cache) {
		return (0, vue.openBlock)(), (0, vue.createElementBlock)("svg", _hoisted_1$2, [..._cache[0] || (_cache[0] = [(0, vue.createElementVNode)("path", {
			d: "M13.75 1.875H18.125V6.25M17.1875 2.8125L12.5 7.5M10.625 3.125H5C4.50272 3.125 4.02581 3.32254 3.67417 3.67417C3.32254 4.02581 3.125 4.50272 3.125 5V15C3.125 15.4973 3.32254 15.9742 3.67417 16.3258C4.02581 16.6775 4.50272 16.875 5 16.875H15C15.4973 16.875 15.9742 16.6775 16.3258 16.3258C16.6775 15.9742 16.875 15.4973 16.875 15V9.375",
			stroke: "currentColor",
			"stroke-width": "2",
			"stroke-linecap": "round",
			"stroke-linejoin": "round"
		}, null, -1)])]);
	}
	var OpenNewTab_default = /*#__PURE__*/ _plugin_vue_export_helper_default(_sfc_main$3, [["render", _sfc_render]]);
	//#endregion
	//#region resources/js/components/CitedBy/CrossrefCitedByBody.vue
	var _hoisted_1$1 = ["href"];
	var _sfc_main$2 = {
		__name: "CrossrefCitedByBody",
		props: {
			styles: {
				type: Object,
				default: () => ({})
			},
			onClose: {
				type: Function,
				default: () => () => {}
			}
		},
		setup(__props) {
			const { usePkpLocalize } = pkp.modules.usePkpLocalize;
			const { t } = usePkpLocalize();
			const { usePkpStyles } = pkp.modules.usePkpStyles;
			const { cn } = usePkpStyles("CrossrefCitedByBody", __props.styles);
			const store = useCrossrefCitedByStore();
			function getSourceLine(citation) {
				return [
					citation?.journal,
					citation?.institutionName,
					citation.year,
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
				return parts.join(", ");
			}
			return (_ctx, _cache) => {
				const _component_PkpButton = (0, vue.resolveComponent)("PkpButton");
				return (0, vue.openBlock)(), (0, vue.createElementBlock)("div", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("root")) }, [
					(0, vue.createElementVNode)("h5", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("count")) }, (0, vue.toDisplayString)((0, vue.unref)(t)("plugins.generic.crossref.citedBy.citationCount", { count: (0, vue.unref)(store).total })), 3),
					(0, vue.createElementVNode)("div", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationsWrapper")) }, [(0, vue.createElementVNode)("ul", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationsList")) }, [((0, vue.openBlock)(true), (0, vue.createElementBlock)(vue.Fragment, null, (0, vue.renderList)((0, vue.unref)(store).citations, (citation, index) => {
						return (0, vue.openBlock)(), (0, vue.createElementBlock)("li", {
							key: index,
							class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationsListItem"))
						}, [(0, vue.createElementVNode)("div", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationsListItemContent")) }, [
							(0, vue.createElementVNode)("h5", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationTitle")) }, (0, vue.toDisplayString)(citation.title), 3),
							(0, vue.createElementVNode)("p", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationAuthors")) }, (0, vue.toDisplayString)(citation.authors), 3),
							(0, vue.createElementVNode)("p", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationSource")) }, [(0, vue.createElementVNode)("span", null, [((0, vue.openBlock)(true), (0, vue.createElementBlock)(vue.Fragment, null, (0, vue.renderList)(getSourceLine(citation), (source, index) => {
								return (0, vue.openBlock)(), (0, vue.createElementBlock)(vue.Fragment, { key: index }, [(0, vue.createElementVNode)("span", null, (0, vue.toDisplayString)(source), 1), index < getSourceLine(citation).length - 1 ? ((0, vue.openBlock)(), (0, vue.createElementBlock)("span", {
									key: 0,
									class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationSourceDelimiter"))
								}, " . ", 2)) : (0, vue.createCommentVNode)("", true)], 64);
							}), 128))])], 2),
							citation.doi ? ((0, vue.openBlock)(), (0, vue.createElementBlock)("a", {
								key: 0,
								href: (0, vue.unref)(store).getDoiExternalLink(citation.doi),
								target: "_blank",
								rel: "noopener noreferrer",
								class: (0, vue.normalizeClass)((0, vue.unref)(cn)("citationDoi"))
							}, [(0, vue.createTextVNode)((0, vue.toDisplayString)(`doi.org/${citation.doi}`) + " ", 1), (0, vue.createVNode)(OpenNewTab_default, {
								icon: "OpenNewTab",
								class: (0, vue.normalizeClass)((0, vue.unref)(cn)("openIcon"))
							}, null, 8, ["class"])], 10, _hoisted_1$1)) : (0, vue.createCommentVNode)("", true)
						], 2)], 2);
					}), 128))], 2)], 2),
					(0, vue.createElementVNode)("div", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("actions")) }, [(0, vue.createVNode)(_component_PkpButton, {
						class: (0, vue.normalizeClass)((0, vue.unref)(cn)("actionsCopyBtn")),
						"is-disabled": (0, vue.unref)(store).isLoading || (0, vue.unref)(store).total < 1,
						onClick: _cache[0] || (_cache[0] = ($event) => (0, vue.unref)(store).copyAllToClipboard())
					}, {
						default: (0, vue.withCtx)(() => [(0, vue.createTextVNode)((0, vue.toDisplayString)((0, vue.unref)(store).copiedToClipboard ? (0, vue.unref)(t)("common.copied") : (0, vue.unref)(t)("plugins.generic.crossref.citedBy.copyCitationDetails")), 1)]),
						_: 1
					}, 8, ["class", "is-disabled"]), (0, vue.createVNode)(_component_PkpButton, {
						class: (0, vue.normalizeClass)((0, vue.unref)(cn)("actionsCloseBtn")),
						onClick: __props.onClose
					}, {
						default: (0, vue.withCtx)(() => [(0, vue.createTextVNode)((0, vue.toDisplayString)((0, vue.unref)(t)("common.close")), 1)]),
						_: 1
					}, 8, ["class", "onClick"])], 2)
				], 2);
			};
		}
	};
	//#endregion
	//#region resources/js/components/CitedBy/useCrossrefCitedByStore.js
	var { usePkpFetch } = pkp.modules.usePkpFetch;
	var { usePkpModal } = pkp.modules.usePkpModal;
	var { usePkpLocalize } = pkp.modules.usePkpLocalize;
	var { useUrl } = pkp.modules.usePkpUrl;
	var { t } = usePkpLocalize();
	var useCrossrefCitedByStore = (0, pinia.defineStore)("crossrefCitedBy", () => {
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
		const initialized = (0, vue.ref)(false);
		const copiedToClipboard = (0, vue.ref)(false);
		const styles = (0, vue.ref)({});
		const nestedStyles = (0, vue.ref)({});
		async function initialize(config, _nestedStyles = {}) {
			if (!config) return;
			styles.value = {
				...styles.value,
				...config.styles || {}
			};
			nestedStyles.value = {
				...nestedStyles.value,
				..._nestedStyles
			};
			if (initialized.value || isLoading.value) return;
			const submissionId = config.submissionId;
			const { apiUrl } = useUrl(`crossref/citedBy/${submissionId}`);
			isLoading.value = true;
			const { data, fetch } = usePkpFetch(apiUrl, { method: "GET" });
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
			return [
				i + 1,
				getDoiExternalLink(citation?.doi),
				citation.issue,
				citation.title,
				citation?.journal,
				citation.year,
				citation.volume,
				citation.authors,
				citation.firstPage
			].filter(Boolean).join(" ");
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
		function openCitedByModal() {
			if (!total.value) return;
			const { openDialog, closeTopDialog } = usePkpModal();
			openDialog({
				title: t("plugins.generic.crossref.citedBy.title"),
				bodyComponent: _sfc_main$2,
				size: "large",
				bodyProps: {
					styles: styles.value.CrossrefCitedByBody,
					onClose: () => closeTopDialog()
				}
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
			getDoiExternalLink
		};
	});
	//#endregion
	//#region resources/js/components/CitedBy/CrossrefCitedBy.vue
	var _hoisted_1 = ["innerHTML"];
	var _sfc_main$1 = {
		__name: "CrossrefCitedBy",
		props: {
			submissionId: {
				type: Number,
				required: true
			},
			isCompactDisplay: {
				type: Boolean,
				default: false
			},
			styles: {
				type: Object,
				default: () => ({})
			}
		},
		setup(__props) {
			const { usePkpStyles } = pkp.modules.usePkpStyles;
			const { usePkpLocalize } = pkp.modules.usePkpLocalize;
			const { t } = usePkpLocalize();
			const props = __props;
			const { cn, nestedStyles } = usePkpStyles("CrossrefCitedBy", props.styles);
			const store = useCrossrefCitedByStore();
			store.initialize(props, nestedStyles);
			return (_ctx, _cache) => {
				return (0, vue.openBlock)(), (0, vue.createElementBlock)("div", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("root")) }, [(0, vue.createElementVNode)("div", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)(__props.isCompactDisplay ? "compact" : "detailed")) }, [__props.isCompactDisplay ? ((0, vue.openBlock)(), (0, vue.createElementBlock)(vue.Fragment, { key: 0 }, [(0, vue.createElementVNode)("h2", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("count")) }, (0, vue.toDisplayString)((0, vue.unref)(store).total), 3), (0, vue.createElementVNode)("div", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("label")) }, (0, vue.toDisplayString)((0, vue.unref)(t)("plugins.generic.crossref.citedBy.viaCrossref")), 3)], 64)) : ((0, vue.openBlock)(), (0, vue.createElementBlock)("div", {
					key: 1,
					class: (0, vue.normalizeClass)((0, vue.unref)(cn)("label")),
					innerHTML: (0, vue.unref)(t)("plugins.generic.crossref.citedBy.thisArticleHasBeenCited", { count: (0, vue.unref)(store).total })
				}, null, 10, _hoisted_1)), (0, vue.unref)(store).total ? ((0, vue.openBlock)(), (0, vue.createElementBlock)("button", {
					key: 2,
					onClick: _cache[0] || (_cache[0] = (...args) => (0, vue.unref)(store).openCitedByModal && (0, vue.unref)(store).openCitedByModal(...args)),
					class: (0, vue.normalizeClass)((0, vue.unref)(cn)("viewCitations")),
					type: "button"
				}, (0, vue.toDisplayString)((0, vue.unref)(t)("plugins.generic.crossref.citedBy.viewCitingArticles")), 3)) : (0, vue.createCommentVNode)("", true)], 2)], 2);
			};
		}
	};
	//#endregion
	//#region resources/js/components/CitedBy/CrossrefCitedByCount.vue
	var _sfc_main = {
		__name: "CrossrefCitedByCount",
		props: {
			submissionId: {
				type: Number,
				required: true
			},
			styles: {
				type: Object,
				default: () => ({})
			}
		},
		setup(__props) {
			const { usePkpStyles } = pkp.modules.usePkpStyles;
			const props = __props;
			const store = useCrossrefCitedByStore();
			const { cn } = usePkpStyles("CrossrefCitedByCount", props.styles);
			store.initialize(props);
			return (_ctx, _cache) => {
				return (0, vue.openBlock)(), (0, vue.createElementBlock)("div", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("root")) }, [(0, vue.createElementVNode)("span", { class: (0, vue.normalizeClass)((0, vue.unref)(cn)("count")) }, (0, vue.toDisplayString)((0, vue.unref)(store).total), 3)], 2);
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
	pkp.registry.registerComponent("CrossrefCrossmarkButton", _sfc_main$4);
	pkp.registry.registerComponent("CrossrefCitedBy", _sfc_main$1);
	pkp.registry.registerComponent("CrossrefCitedByBody", _sfc_main$2);
	pkp.registry.registerComponent("CrossrefCitedByCount", _sfc_main);
	//#endregion
})(pkp.modules.vue, pkp.modules.pinia);
