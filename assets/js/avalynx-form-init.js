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
                if (typeof submittedForm.__syncMailboxListEditors === 'function') {
                    submittedForm.__syncMailboxListEditors();
                }

                return true;
            },
            onSuccess: (response) => {
                if (response.message && typeof AvalynxAlert !== 'undefined') {
                    AvalynxAlert.success({ message: response.message, position: 'top-right' });
                }
            },
            onError: (response) => {
                if (response && response.message && typeof AvalynxAlert !== 'undefined') {
                    AvalynxAlert.error({ message: response.message, position: 'top-right' });
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
