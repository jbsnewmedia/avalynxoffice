/*
 * AvalynxDataTable initializer.
 *
 * Initializes AvalynxDataTable on every element marked with
 * [data-avalynxdatatable]. Configuration comes from data attributes:
 *
 *   data-api-url                  endpoint that returns the datatable JSON
 *   data-per-page                 initial page size (default: 10)
 *   data-label-show / -entries / -search / -previous / -next
 *   data-label-showing            template with %start% %end% %total%
 *   data-label-showing-filtered   template with %start% %end% %filtered% %total%
 *
 * Works on full pages (DOMContentLoaded) and inside XHR-loaded content
 * (MutationObserver).
 */
(function () {
    'use strict';

    const SELECTOR = '[data-avalynxdatatable]';

    const fillTemplate = (template, values) => String(template || '')
        .replace(/%start%/g, String(values.start))
        .replace(/%end%/g, String(values.end))
        .replace(/%filtered%/g, String(values.filtered))
        .replace(/%total%/g, String(values.total));

    const initialize = (element) => {
        if (element.dataset.avalynxdatatableInit === 'true') {
            return;
        }

        if (typeof AvalynxDataTable !== 'function') {
            return;
        }

        if (!element.id) {
            element.id = 'avalynxdatatable-' + Math.random().toString(36).slice(2, 10);
        }

        element.dataset.avalynxdatatableInit = 'true';

        const dataset = element.dataset;

        new AvalynxDataTable(element.id, {
            apiUrl: dataset.apiUrl || '',
            perPage: parseInt(dataset.perPage, 10) || 10
        }, {
            showLabel: dataset.labelShow || 'Show',
            entriesLabel: dataset.labelEntries || 'entries',
            searchLabel: dataset.labelSearch || 'Search',
            previousLabel: dataset.labelPrevious || 'Previous',
            nextLabel: dataset.labelNext || 'Next',
            showingEntries: (start, end, total) => fillTemplate(dataset.labelShowing, {
                start: start,
                end: end,
                total: total
            }),
            showingFilteredEntries: (start, end, filtered, total) => fillTemplate(dataset.labelShowingFiltered, {
                start: start,
                end: end,
                filtered: filtered,
                total: total
            })
        });
    };

    const initializeRoot = (root) => {
        if (root instanceof Element) {
            if (root.matches(SELECTOR)) {
                initialize(root);
            }

            root.querySelectorAll(SELECTOR).forEach(initialize);

            return;
        }

        // Whole-document initialization on DOMContentLoaded: the document
        // node is not an Element, but querySelectorAll works the same on it.
        if (root === document) {
            document.querySelectorAll(SELECTOR).forEach(initialize);
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
