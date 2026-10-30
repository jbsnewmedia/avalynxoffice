/*
 * AvalynxForm initializer.
 *
 * Initializes AvalynxForm on every form marked with [data-avalynxform].
 * Works on full pages (DOMContentLoaded) and inside XHR-loaded modal
 * content (MutationObserver). The server must answer in the AvalynxForm
 * JSON schema: {success, redirect?, invalid?, message?}.
 */
(function () {
    'use strict';

    const SELECTOR = 'form[data-avalynxform]';

    /*
     * AvalynxForm sends its form data with fetch() but no X-Requested-With
     * header, so XHR-guarded endpoints (requireXmlHttpRequest) reject every
     * AvalynxForm submission with 403. Wrap fetch once and add the header
     * to FormData requests that do not carry it yet.
     */
    const originalFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
        const options = init || {};

        if (options.body instanceof FormData) {
            const headers = new Headers(options.headers || {});
            if (!headers.has('X-Requested-With')) {
                headers.set('X-Requested-With', 'XMLHttpRequest');
            }

            return originalFetch(input, { ...options, headers });
        }

        return originalFetch(input, options);
    };

    /*
     * AvalynxForm builds its payload with new FormData(form), which drops the
     * clicked submit button. Symfony forms, however, use the clicked button
     * (e.g. "Save draft" vs. "Send") to decide what the submission means.
     * Remember the submitter in the capture phase (before AvalynxForm's own
     * submit listener runs) and let onBeforeSubmit inject it as a hidden
     * field so the server still sees which button was clicked.
     */
    document.addEventListener('submit', function (event) {
        const submitter = event.submitter;
        if (!submitter || '' === submitter.name) {
            return;
        }

        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.dataset.avalynxformInit !== 'true') {
            return;
        }

        form.dataset.avalynxformSubmitterName = submitter.name;
        form.dataset.avalynxformSubmitterValue = submitter.value || '';
    }, true);

    const initializeForm = (form) => {
        if (form.dataset.avalynxformInit === 'true') {
            return;
        }

        if (typeof AvalynxForm !== 'function') {
            return;
        }

        if (!form.id) {
            form.id = 'avalynxform-' + Math.random().toString(36).slice(2, 10);
        }

        form.dataset.avalynxformInit = 'true';

        new AvalynxForm(form.id, {
            onBeforeSubmit: (submittedForm) => {
                if (typeof submittedForm.__syncAccountListEditors === 'function') {
                    submittedForm.__syncAccountListEditors();
                }

                const submitterName = submittedForm.dataset.avalynxformSubmitterName || '';
                submittedForm.querySelectorAll('[data-avalynxform-submitter]').forEach((element) => element.remove());
                if (submitterName !== '') {
                    const submitterField = document.createElement('input');
                    submitterField.type = 'hidden';
                    submitterField.name = submitterName;
                    submitterField.value = submittedForm.dataset.avalynxformSubmitterValue || '';
                    submitterField.dataset.avalynxformSubmitter = '1';
                    submittedForm.appendChild(submitterField);
                }

                return true;
            },
            onSuccess: (response) => {
                if (response.message && typeof AvalynxAlert === 'function') {
                    new AvalynxAlert(response.message, 'success', { position: 'top-right' });
                }
            },
            onError: (response) => {
                if (response && response.message && typeof AvalynxAlert === 'function') {
                    new AvalynxAlert(response.message, 'danger', { position: 'top-right' });
                }
            }
        });
    };

    const initializeRoot = (root) => {
        if (root instanceof Element) {
            if (root.matches(SELECTOR)) {
                initializeForm(root);
            }

            root.querySelectorAll(SELECTOR).forEach(initializeForm);

            return;
        }

        // Whole-document initialization on DOMContentLoaded: the document
        // node is not an Element, but querySelectorAll works the same on it.
        if (root === document) {
            document.querySelectorAll(SELECTOR).forEach(initializeForm);
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        initializeRoot(document);

        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    initializeRoot(node);
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    });
})();
