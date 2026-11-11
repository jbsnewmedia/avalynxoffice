/*
 * Help page (/help): client-side search over the documentation sections
 * and entries, plus current-section highlighting in the table of contents.
 */
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('help-search');
    if (!searchInput) {
        return;
    }
    const form = searchInput.closest('form');
    const clearButton = form ? form.querySelector('[data-help-search-clear]') : null;
    const countElement = form ? form.querySelector('[data-help-search-count]') : null;
    const resultsTemplate = searchInput.getAttribute('data-results-template') ?? '';
    const noResultsTemplate = searchInput.getAttribute('data-no-results-template') ?? '';
    const sections = Array.from(document.querySelectorAll('.help-section'));
    const entries = Array.from(document.querySelectorAll('.help-entry'));
    const tocLinks = Array.from(document.querySelectorAll('.help-toc-link'));

    const normalize = (value) => value.toLowerCase().replace(/\s+/g, ' ').trim();

    const applySearch = () => {
        const query = normalize(searchInput.value);
        let visibleEntries = 0;

        entries.forEach((entry) => {
            const haystack = normalize(entry.textContent);
            const visible = query === '' || haystack.indexOf(query) !== -1;
            entry.classList.toggle('d-none', !visible);
            if (visible) {
                visibleEntries++;
            }
        });

        sections.forEach((section) => {
            const hasVisibleEntries = section.querySelector('.help-entry:not(.d-none)') !== null;
            const sectionTitle = section.querySelector('.help-section-title');
            const titleMatches = query !== '' && sectionTitle !== null
                && normalize(sectionTitle.textContent).indexOf(query) !== -1;
            section.classList.toggle('d-none', query !== '' && !hasVisibleEntries && !titleMatches);
        });

        tocLinks.forEach((link) => {
            const section = document.querySelector(link.getAttribute('href'));
            link.classList.toggle('d-none', section !== null && section.classList.contains('d-none'));
        });

        if (clearButton) {
            clearButton.classList.toggle('d-none', searchInput.value === '');
        }
        if (countElement) {
            if (query === '') {
                countElement.textContent = '';
                countElement.classList.add('d-none');
            } else {
                countElement.textContent = visibleEntries > 0
                    ? resultsTemplate.replace('%count%', String(visibleEntries))
                    : noResultsTemplate.replace('%query%', searchInput.value.trim());
                countElement.classList.remove('d-none');
            }
        }
    };

    let searchTimer = null;
    searchInput.addEventListener('input', function () {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(applySearch, 120);
    });

    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            searchInput.value = '';
            applySearch();
            return;
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            applySearch();
            const firstEntry = document.querySelector('.help-entry:not(.d-none)');
            const target = firstEntry ?? document.querySelector('.help-section:not(.d-none)');
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    });

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
        });
    }
    if (clearButton) {
        clearButton.addEventListener('click', function () {
            searchInput.value = '';
            applySearch();
            searchInput.focus();
        });
    }

    // Highlight the section currently in view in the table of contents.
    // The app layout scrolls inside <main> (body is overflow:hidden), so
    // resolve the actual scroll root instead of listening on the document.
    const findScrollRoot = function () {
        let el = document.querySelector('.help-section');
        while (el && el !== document.body) {
            if (/(auto|scroll)/.test(window.getComputedStyle(el).overflowY)) {
                return el;
            }
            el = el.parentElement;
        }
        return document;
    };
    const scrollRoot = findScrollRoot();
    const getScrollElement = function () {
        return scrollRoot === document ? document.scrollingElement : scrollRoot;
    };
    const setActiveLink = function () {
        let currentId = null;
        sections.forEach(function (section) {
            if (!section.classList.contains('d-none') && section.getBoundingClientRect().top <= 120) {
                currentId = section.id;
            }
        });
        // When scrolled to the end, the last section cannot reach the top;
        // mark the last visible section active instead.
        const scrollElement = getScrollElement();
        if (scrollElement !== null && scrollElement.scrollTop + scrollElement.clientHeight >= scrollElement.scrollHeight - 2) {
            const visible = sections.filter(function (section) {
                return !section.classList.contains('d-none');
            });
            if (visible.length > 0) {
                currentId = visible[visible.length - 1].id;
            }
        }
        tocLinks.forEach(function (link) {
            link.classList.toggle('active', link.getAttribute('href') === '#' + currentId);
        });
    };
    scrollRoot.addEventListener('scroll', setActiveLink, { passive: true });
    setActiveLink();
});
