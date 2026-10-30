/*
 * AvalynxDataTable initializer.
 *
 * Initializes AvalynxDataTable on every element marked with
 * [data-avalynxdatatable]. Configuration comes from data attributes:
 *
 *   data-api-url                  endpoint that returns the datatable JSON
 *   data-csrf-token               CSRF token sent as `_token` with every request
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

    /*
     * Shows a single "no entries" row (colspan over all columns) while the
     * table body has no data rows, and removes it again once data arrives.
     * The library leaves the tbody completely empty for empty result sets.
     */
    const syncEmptyRow = (element, dataset) => {
        const table = element.querySelector('table');
        if (!table) {
            return;
        }

        const headerColumns = table.querySelectorAll('thead th');
        if (headerColumns.length === 0) {
            // The table has not been populated yet (initial fetch pending).
            return;
        }

        const tbody = table.querySelector('tbody');
        if (!tbody) {
            return;
        }

        const emptyRow = tbody.querySelector('tr[data-avalynx-datatable-empty]');
        const dataRows = tbody.querySelectorAll('tr:not([data-avalynx-datatable-empty])');

        if (dataRows.length > 0) {
            if (emptyRow) {
                emptyRow.remove();
            }

            return;
        }

        if (!emptyRow) {
            const row = document.createElement('tr');
            row.dataset.avalynxDatatableEmpty = 'true';
            const cell = document.createElement('td');
            cell.colSpan = headerColumns.length;
            cell.className = 'text-center text-body-secondary py-4';
            cell.textContent = dataset.labelEmpty || 'Keine Einträge';
            row.appendChild(cell);
            tbody.appendChild(row);
        }
    };

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

        const apiParams = {};
        if (dataset.csrfToken) {
            apiParams._token = dataset.csrfToken;
        }

        new AvalynxDataTable(element.id, {
            apiUrl: dataset.apiUrl || '',
            apiParams: apiParams,
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

        // Keep the empty-state row in sync with every (re)render of the
        // table body. Own insertions are ignored via the data attribute.
        const tableObserver = new MutationObserver(function () {
            syncEmptyRow(element, dataset);
        });
        tableObserver.observe(element, { childList: true, subtree: true });
        syncEmptyRow(element, dataset);
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
