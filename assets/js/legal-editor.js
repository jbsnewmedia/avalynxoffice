/*
 * Initializes the Summernote Next rich text editors used by the legal
 * pages admin area. The editor containers are rendered inside modal
 * content that is loaded via XHR, so the initializer watches for the
 * containers to appear instead of relying on the initial page load.
 *
 * The legal admin area hosts one editor for three pages (imprint, privacy
 * policy, terms). Tab switches cache the current editor HTML in memory and
 * load the stored content of the selected page; the formdata listener
 * injects the live editor HTML into the request right before submission.
 */
(function () {
    'use strict';

    const EDITOR_SELECTOR = '[data-legal-editor]';
    const FORM_SELECTOR = '[data-legal-form]';
    const TAB_SELECTOR = '[data-legal-tab]';
    const PAGE_INPUT_SELECTOR = '[data-legal-page-input]';
    const EDITOR_ID = 'legal-editor';

    const TOOLBAR = [
        ['style', ['style', 'bold', 'underline', 'clear']],
        ['para', ['ul', 'ol', 'paragraph', 'table']],
        ['insert', ['link']],
        ['view', ['fullscreen', 'codeview', 'help']]
    ];

    const state = {
        created: false,
        page: null,
        cache: {}
    };

    const hasSummernote = () => !!window.summernote && typeof window.summernote.create === 'function' && typeof window.summernote.invoke === 'function';

    const readStoredContent = (page) => {
        const template = document.querySelector('template[data-legal-content="' + page + '"]');
        return template ? template.innerHTML : '';
    };

    const readEditorHtml = () => window.summernote.invoke('#' + EDITOR_ID, 'code');

    const writeEditorHtml = (html) => {
        window.summernote.invoke('#' + EDITOR_ID, 'code', html);
    };

    const activateTab = (page) => {
        if (!state.created || state.page === page) {
            return;
        }

        state.cache[state.page] = readEditorHtml();
        state.page = page;
        writeEditorHtml(Object.prototype.hasOwnProperty.call(state.cache, page) ? state.cache[page] : readStoredContent(page));

        const pageInput = document.querySelector(PAGE_INPUT_SELECTOR);
        if (pageInput) {
            pageInput.value = page;
        }

        document.querySelectorAll(TAB_SELECTOR).forEach((tab) => {
            const active = tab.getAttribute('data-legal-tab') === page;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    };

    const createEditor = (element) => {
        if (element.dataset.legalEditorInitialized === 'true' || !hasSummernote()) {
            return;
        }
        element.dataset.legalEditorInitialized = 'true';
        element.id = EDITOR_ID;

        state.page = element.getAttribute('data-legal-editor-name') || 'impressum';
        state.cache = {};
        state.cache[state.page] = readStoredContent(state.page);

        const options = {
            height: 300,
            minHeight: 200,
            maxHeight: 600,
            tabsize: 2,
            dialogsInBody: true,
            toolbar: TOOLBAR
        };

        const lang = (document.documentElement.lang || 'en').replace('_', '-');
        if (lang.indexOf('de') === 0) {
            options.lang = 'de-DE';
        }

        window.summernote.create('#' + EDITOR_ID, options);
        writeEditorHtml(state.cache[state.page]);
        state.created = true;
    };

    const attachForm = (form) => {
        if (form.dataset.legalFormBound === 'true') {
            return;
        }
        form.dataset.legalFormBound = 'true';

        form.addEventListener('formdata', (event) => {
            if (state.created) {
                event.formData.set('content', readEditorHtml());
            }
        });
    };

    const attachTab = (tab) => {
        if (tab.dataset.legalTabBound === 'true') {
            return;
        }
        tab.dataset.legalTabBound = 'true';

        tab.addEventListener('click', () => {
            activateTab(tab.getAttribute('data-legal-tab'));
        });
    };

    const initializeRoot = (root) => {
        if (root instanceof Element) {
            if (root.matches(EDITOR_SELECTOR)) {
                createEditor(root);
            }
            if (root.matches(FORM_SELECTOR)) {
                attachForm(root);
            }
            if (root.matches(TAB_SELECTOR)) {
                attachTab(root);
            }

            root.querySelectorAll(EDITOR_SELECTOR).forEach(createEditor);
            root.querySelectorAll(FORM_SELECTOR).forEach(attachForm);
            root.querySelectorAll(TAB_SELECTOR).forEach(attachTab);

            return;
        }

        // Whole-document initialization on DOMContentLoaded: the document
        // node is not an Element, but querySelectorAll works the same on it.
        if (root === document) {
            document.querySelectorAll(EDITOR_SELECTOR).forEach(createEditor);
            document.querySelectorAll(FORM_SELECTOR).forEach(attachForm);
            document.querySelectorAll(TAB_SELECTOR).forEach(attachTab);
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        initializeRoot(document);

        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node instanceof Element) {
                        initializeRoot(node);
                    }
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    });
})();
