document.addEventListener('DOMContentLoaded', function () {
    (function(){
        const getStoredTheme = () => localStorage.getItem('theme');
        const setStoredTheme = theme => localStorage.setItem('theme', theme);
        const getPreferredTheme = () => {
            const storedTheme = getStoredTheme();
            if (storedTheme) {
                return storedTheme;
            }
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        };
        const setTheme = theme => {
            if (theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.setAttribute('data-bs-theme', 'dark');
            } else {
                document.documentElement.setAttribute('data-bs-theme', theme);
            }
        };
        const updateThemeIcon = (theme) => {
            const toggleBtn = document.getElementById('darkModeToggle');
            if (!toggleBtn) return;
            const icon = toggleBtn.querySelector('i');
            if (theme === 'dark') {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
            } else {
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
            }
        };
        const initDarkMode = () => {
            const theme = getPreferredTheme();
            setTheme(theme);
            updateThemeIcon(theme);

            const toggleBtn = document.getElementById('darkModeToggle');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', () => {
                    const currentTheme = document.documentElement.getAttribute('data-bs-theme');
                    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                    setStoredTheme(newTheme);
                    setTheme(newTheme);
                    updateThemeIcon(newTheme);
                });
            }
        };
        initDarkMode();
    })();
    (function(){
        var el = document.getElementById('i18n-mail');
        if (el){
            try {
                var txt = (el.textContent || '').trim();
                if (txt){
                    window.I18N = JSON.parse(txt);
                } else {
                    var d = el.dataset || {};
                    window.I18N = {
                        loading_email: d.loadingEmail,
                        load_more: d.loadMore,
                        email_load_error: d.emailLoadError,
                        no_results: d.noResults,
                        already_in_folder: d.alreadyInFolder,
                        unexpected_response: d.unexpectedResponse,
                        move_failed: d.moveFailed,
                        moved_to_folder: d.movedToFolder,
                        read_label: d.readLabel,
                        unread_label: d.unreadLabel,
                        folder_unread_aria: d.folderUnreadAria
                    };
                }
            } catch(e){ console.warn('Failed to parse i18n data', e); }
        }
    })();
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js').catch(function(e){console.warn('SW reg failed', e);});
        });
    }
    const initMailboxListEditors = (root) => {
        (root || document).querySelectorAll('[data-mailbox-list-editor]').forEach((editor) => {
            if (editor.dataset.mailboxListInitialized === 'true') {
                return;
            }

            const storage = editor.querySelector('[data-mailbox-list-storage]');
            const addButton = editor.querySelector('[data-mailbox-list-add]');
            const itemsContainer = editor.querySelector('[data-mailbox-list-items]');
            const emptyState = editor.querySelector('[data-mailbox-list-empty]');
            const type = editor.dataset.mailboxListType || 'alias';
            let folderFunctionOptions = [];

            if (type === 'folder' && editor.dataset.folderFunctionOptions) {
                try {
                    const parsedOptions = JSON.parse(editor.dataset.folderFunctionOptions);
                    if (Array.isArray(parsedOptions)) {
                        folderFunctionOptions = parsedOptions.filter(option => typeof option?.value === 'string' && typeof option?.label === 'string');
                    }
                } catch (_error) {
                    folderFunctionOptions = [];
                }
            }

            if (!storage || !addButton || !itemsContainer || !emptyState) {
                return;
            }

            editor.dataset.mailboxListInitialized = 'true';

            const normalizeItem = (item) => {
                if (type === 'folder') {
                    const name = typeof item?.name === 'string' ? item.name.trim() : '';
                    const sortOrderRaw = item?.sortOrder;
                    const functionsRaw = Array.isArray(item?.functions) ? item.functions : [];
                    let sortOrder = 0;
                    if (typeof sortOrderRaw === 'number' && Number.isFinite(sortOrderRaw)) {
                        sortOrder = Math.trunc(sortOrderRaw);
                    } else if (typeof sortOrderRaw === 'string' && sortOrderRaw.trim() !== '' && !Number.isNaN(Number(sortOrderRaw))) {
                        sortOrder = Math.trunc(Number(sortOrderRaw));
                    }

                    return {
                        name,
                        sortOrder,
                        functions: functionsRaw
                            .filter(value => typeof value === 'string')
                            .map(value => value.trim())
                            .filter(value => value !== '')
                    };
                }

                const email = typeof item?.email === 'string' ? item.email.trim() : '';
                const name = typeof item?.name === 'string' ? item.name.trim() : '';

                return { email, name };
            };

            const parseItems = () => {
                try {
                    const parsed = JSON.parse(storage.value || '[]');
                    return Array.isArray(parsed) ? parsed.map(normalizeItem) : [];
                } catch (_error) {
                    return [];
                }
            };

            let items = parseItems();

            const sanitizeItems = () => items
                .map(normalizeItem)
                .filter((item) => type === 'folder' ? item.name !== '' : item.email !== '');

            const syncStorage = (sanitize = false) => {
                const payload = sanitize ? sanitizeItems() : items.map(normalizeItem);
                storage.value = JSON.stringify(payload);
                storage.dispatchEvent(new Event('input', { bubbles: true }));
            };

            const buildField = (labelText, type, placeholder, value, onInput) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'mailbox-list-field';

                const label = document.createElement('label');
                label.className = 'form-label small text-body-secondary mb-1';
                label.textContent = labelText;

                const input = document.createElement('input');
                input.type = type;
                input.className = 'form-control';
                input.placeholder = placeholder || '';
                input.value = value ?? '';
                input.addEventListener('input', onInput);

                wrapper.appendChild(label);
                wrapper.appendChild(input);

                return { wrapper, input };
            };

            const buildMultiSelectField = (labelText, options, selectedValues, onInput) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'mailbox-list-field';

                const label = document.createElement('label');
                label.className = 'form-label small text-body-secondary mb-1';
                label.textContent = labelText;

                const select = document.createElement('select');
                select.className = 'form-select';
                select.multiple = true;
                select.size = Math.max(2, options.length);
                select.addEventListener('change', onInput);

                options.forEach((optionConfig) => {
                    const option = document.createElement('option');
                    option.value = optionConfig.value;
                    option.textContent = optionConfig.label;
                    option.selected = selectedValues.includes(optionConfig.value);
                    select.appendChild(option);
                });

                wrapper.appendChild(label);
                wrapper.appendChild(select);

                return { wrapper, select };
            };

            const renderItems = () => {
                const removeLabel = itemsContainer.dataset.removeLabel || 'Remove';
                itemsContainer.innerHTML = '';

                items.forEach((value, index) => {
                    const row = document.createElement('div');
                    row.className = 'mailbox-list-row';

                    const primaryField = buildField(
                        editor.dataset.primaryLabel || '',
                        type === 'folder' ? 'text' : 'email',
                        editor.dataset.primaryPlaceholder || '',
                        type === 'folder' ? value.name : value.email,
                        function (event) {
                            if (type === 'folder') {
                                items[index].name = event.target.value;
                            } else {
                                items[index].email = event.target.value;
                            }
                            syncStorage();
                        }
                    );
                    row.appendChild(primaryField.wrapper);

                    const secondaryField = buildField(
                        editor.dataset.secondaryLabel || '',
                        type === 'folder' ? 'number' : 'text',
                        editor.dataset.secondaryPlaceholder || '',
                        type === 'folder' ? String(value.sortOrder ?? 0) : (value.name || ''),
                        function (event) {
                            if (type === 'folder') {
                                items[index].sortOrder = event.target.value;
                            } else {
                                items[index].name = event.target.value;
                            }
                            syncStorage();
                        }
                    );
                    row.appendChild(secondaryField.wrapper);

                    if (type === 'folder' && folderFunctionOptions.length > 0) {
                        const tertiaryField = buildMultiSelectField(
                            editor.dataset.tertiaryLabel || '',
                            folderFunctionOptions,
                            Array.isArray(value.functions) && value.functions.length > 0 ? value.functions : ['none'],
                            function (event) {
                                let selectedValues = Array.from(event.target.selectedOptions).map(option => option.value);
                                if (selectedValues.includes('none')) {
                                    if (selectedValues.length > 1) {
                                        selectedValues = selectedValues.filter(value => value !== 'none');
                                        Array.from(event.target.options).forEach((option) => {
                                            option.selected = selectedValues.includes(option.value);
                                        });
                                    } else {
                                        selectedValues = [];
                                    }
                                }
                                items[index].functions = selectedValues;
                                syncStorage();
                            }
                        );
                        row.appendChild(tertiaryField.wrapper);
                    }

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.className = 'mailbox-list-row-remove btn btn-outline-danger';
                    removeButton.dataset.mailboxListRemove = String(index);
                    removeButton.setAttribute('aria-label', removeLabel + ': ' + (type === 'folder' ? value.name : value.email));
                    removeButton.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';

                    row.appendChild(removeButton);
                    itemsContainer.appendChild(row);
                });

                emptyState.classList.toggle('d-none', items.length > 0);
            };

            const addItem = () => {
                items.push(type === 'folder' ? { name: '', sortOrder: items.length, functions: [] } : { email: '', name: '' });
                syncStorage();
                renderItems();

                const lastRow = itemsContainer.lastElementChild;
                const firstInput = lastRow ? lastRow.querySelector('input') : null;
                if (firstInput) {
                    firstInput.focus();
                }
            };

            editor.__flushMailboxListInput = () => syncStorage(true);

            addButton.addEventListener('click', addItem);
            itemsContainer.addEventListener('click', function (event) {
                const removeButton = event.target.closest('[data-mailbox-list-remove]');
                if (!removeButton) {
                    return;
                }

                const index = Number(removeButton.dataset.mailboxListRemove);
                if (Number.isNaN(index)) {
                    return;
                }

                items = items.filter((_, itemIndex) => itemIndex !== index);
                syncStorage();
                renderItems();
            });

            const form = editor.closest('form');
            if (form && typeof form.__syncMailboxListEditors !== 'function') {
                form.__syncMailboxListEditors = () => {
                    form.querySelectorAll('[data-mailbox-list-editor]').forEach((listEditor) => {
                        if (typeof listEditor.__flushMailboxListInput === 'function') {
                            listEditor.__flushMailboxListInput();
                        }
                    });
                };
            }

            syncStorage();
            renderItems();
        });
    };
    const initComposeEditors = (root) => {
        (root || document).querySelectorAll('[data-compose-editor]').forEach((editor) => {
            if (editor.dataset.composeInitialized === 'true') {
                return;
            }

            editor.dataset.composeInitialized = 'true';

            editor.querySelectorAll('[data-compose-recipient-autocomplete]').forEach(function (autocompleteInput) {
                if (autocompleteInput.dataset.autocompleteInitialized === 'true') {
                    return;
                }

                autocompleteInput.dataset.autocompleteInitialized = 'true';

                const storageInputId = autocompleteInput.dataset.targetInputId || '';
                const storageInput = storageInputId ? document.getElementById(storageInputId) : null;
                const sourceUrl = autocompleteInput.dataset.sourceUrl || '';
                if (!storageInput || !sourceUrl || typeof window.AvalynxAutocomplete !== 'function') {
                    return;
                }

                let defaultSelections = [];
                try {
                    const parsed = JSON.parse(autocompleteInput.dataset.defaultSelections || '[]');
                    if (Array.isArray(parsed)) {
                        defaultSelections = parsed.filter((item) => typeof item?.key === 'string' && typeof item?.value === 'string');
                    }
                } catch (_error) {
                    defaultSelections = [];
                }

                const maxSelections = Math.max(1, Number(autocompleteInput.dataset.maxSelections || '50'));
                const syncStorage = (keys) => {
                    storageInput.value = keys.join(', ');
                    storageInput.dispatchEvent(new Event('input', { bubbles: true }));
                };

                syncStorage(defaultSelections.map((item) => item.key));

                const buildCustomCandidate = (query) => {
                    const trimmedQuery = String(query || '').trim();
                    if (!trimmedQuery || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(trimmedQuery)) {
                        return null;
                    }

                    return { key: trimmedQuery, value: trimmedQuery };
                };

                const widget = new window.AvalynxAutocomplete('#' + autocompleteInput.id, {
                    fetchData: async (query) => {
                        const requestUrl = new URL(sourceUrl, window.location.origin);
                        requestUrl.searchParams.set('search', query);
                        const response = await fetch(requestUrl.toString(), {
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        const payload = await response.json();
                        const suggestions = Array.isArray(payload) ? payload : [];
                        const customCandidate = buildCustomCandidate(query);
                        if (!customCandidate) {
                            return suggestions;
                        }

                        const alreadyPresent = suggestions.some((item) => item && item.key === customCandidate.key);
                        return alreadyPresent ? suggestions : [customCandidate].concat(suggestions);
                    },
                    debounce: 150,
                    maxItems: 8,
                    maxSelections,
                    tagsPosition: 'above',
                    clearStyle: 'button',
                    defaultSelections,
                    onChange: (keys) => syncStorage(Array.isArray(keys) ? keys : [])
                }, {
                    placeholder: autocompleteInput.dataset.placeholder || 'Search…',
                    noResults: autocompleteInput.dataset.noResults || 'No results found',
                    clearTitle: autocompleteInput.dataset.clearTitle || 'Clear selection',
                    removeTitle: autocompleteInput.dataset.removeTitle || 'Remove'
                });

                const getInstance = () => Array.isArray(widget.instances) ? widget.instances[0] || null : null;
                const selectDropdownItem = (dropdownItem) => {
                    const instance = getInstance();
                    if (!instance || !dropdownItem || !dropdownItem.dataset.key) {
                        return false;
                    }

                    widget.selectItem(instance, dropdownItem.dataset.key, dropdownItem.dataset.value || dropdownItem.dataset.key);
                    window.setTimeout(() => autocompleteInput.focus(), 0);

                    return true;
                };
                const commitTypedEmail = () => {
                    const customCandidate = buildCustomCandidate(autocompleteInput.value);
                    if (!customCandidate) {
                        return false;
                    }

                    const instance = getInstance();
                    if (!instance) {
                        return false;
                    }

                    widget.selectItem(instance, customCandidate.key, customCandidate.value);
                    window.setTimeout(() => autocompleteInput.focus(), 0);

                    return true;
                };
                const findPreferredDropdownItem = () => {
                    const instance = getInstance();
                    if (!instance || !instance.dropdown) {
                        return null;
                    }

                    return instance.dropdown.querySelector('.avalynx-autocomplete-item.active')
                        || instance.dropdown.querySelector('.avalynx-autocomplete-item');
                };

                autocompleteInput.addEventListener('keydown', function (event) {
                    if (!['Enter', 'Tab', ',', ';'].includes(event.key) || event.isComposing) {
                        return;
                    }

                    if (event.key === 'Enter') {
                        const hasDropdownSelection = selectDropdownItem(findPreferredDropdownItem());
                        if (hasDropdownSelection || commitTypedEmail()) {
                            event.preventDefault();
                        }

                        return;
                    }

                    if (autocompleteInput.value.trim() === '') {
                        return;
                    }

                    if (selectDropdownItem(findPreferredDropdownItem()) || commitTypedEmail()) {
                        event.preventDefault();
                    }
                });

                autocompleteInput.addEventListener('blur', function () {
                    window.setTimeout(() => {
                        commitTypedEmail();
                    }, 150);
                });
            });

            const advancedToggle = editor.querySelector('[data-compose-advanced-toggle]');
            const advancedFields = editor.querySelector('[data-compose-advanced-fields]');
            const scheduleToggle = editor.querySelector('[data-compose-schedule-toggle]');
            const scheduleFields = editor.querySelector('[data-compose-schedule-fields]');
            const scheduleInput = scheduleFields ? scheduleFields.querySelector('input, select, textarea') : null;
            const attachmentRoot = editor.querySelector('[data-compose-attachments]');
            const attachmentToggle = editor.querySelector('[data-compose-attachments-toggle]');
            const attachmentFields = editor.querySelector('[data-compose-attachments-fields]');
            const attachmentInput = editor.querySelector('[data-compose-attachments-input]');
            const attachmentChoose = editor.querySelector('[data-compose-attachments-choose]');
            const attachmentDropzone = editor.querySelector('[data-compose-attachments-dropzone]');
            const attachmentList = editor.querySelector('[data-compose-attachments-list]');
            const attachmentEmpty = editor.querySelector('[data-compose-attachments-empty]');
            const retainedAttachmentInput = editor.querySelector('[data-retained-attachments-input]');

            const updateAdvancedToggle = () => {
                if (!advancedToggle || !advancedFields) {
                    return;
                }

                const expanded = !advancedFields.classList.contains('d-none');
                advancedToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                const showLabel = advancedToggle.dataset.showLabel || 'Weitere Felder';
                const hideLabel = advancedToggle.dataset.hideLabel || 'Weniger Felder';
                advancedToggle.innerHTML = '<i class="fa-solid fa-sliders me-1" aria-hidden="true"></i>' + (expanded ? hideLabel : showLabel);
            };

            if (advancedToggle && advancedFields) {
                advancedToggle.addEventListener('click', function () {
                    advancedFields.classList.toggle('d-none');
                    updateAdvancedToggle();
                });
                updateAdvancedToggle();
            }

            const updateScheduleFields = () => {
                if (!scheduleToggle || !scheduleFields) {
                    return;
                }

                const enabled = !!scheduleToggle.checked;
                scheduleFields.classList.toggle('d-none', !enabled);
                if (scheduleInput) {
                    scheduleInput.disabled = !enabled;
                    if (!enabled) {
                        scheduleInput.value = '';
                    }
                }
            };

            if (scheduleToggle && scheduleFields) {
                scheduleToggle.addEventListener('change', updateScheduleFields);
                updateScheduleFields();
            }

            if (!attachmentRoot || !attachmentInput || !attachmentList || !retainedAttachmentInput) {
                return;
            }

            const updateAttachmentFields = () => {
                if (!attachmentToggle || !attachmentFields) {
                    return;
                }

                const enabled = !!attachmentToggle.checked;
                attachmentFields.classList.toggle('d-none', !enabled);
                attachmentInput.disabled = !enabled;
                if (attachmentChoose) {
                    attachmentChoose.disabled = !enabled;
                }
            };

            if (attachmentToggle && attachmentFields) {
                attachmentToggle.addEventListener('change', updateAttachmentFields);
                updateAttachmentFields();
            }

            let selectedFiles = [];
            const dropLabel = attachmentRoot.dataset.dropLabel || 'Anhänge hier ablegen';
            const dropActiveLabel = attachmentRoot.dataset.dropActiveLabel || dropLabel;
            const noAttachmentsLabel = attachmentRoot.dataset.noAttachmentsLabel || 'Noch keine Anhänge.';
            const dropLabelElement = attachmentDropzone ? attachmentDropzone.querySelector('.compose-attachments-dropzone-label') : null;

            const fileKey = (file) => [file.name, file.size, file.lastModified, file.type].join('::');

            const mergeFiles = (incomingFiles) => {
                const merged = new Map();
                selectedFiles.forEach((file) => merged.set(fileKey(file), file));
                incomingFiles.forEach((file) => merged.set(fileKey(file), file));
                selectedFiles = Array.from(merged.values());
            };

            const syncFileInput = () => {
                if (typeof DataTransfer === 'undefined') {
                    return;
                }

                const transfer = new DataTransfer();
                selectedFiles.forEach((file) => transfer.items.add(file));
                attachmentInput.files = transfer.files;
            };

            const retainedIds = () => (retainedAttachmentInput.value || '')
                .split(',')
                .map((item) => item.trim())
                .filter((item) => item !== '');

            const setRetainedIds = (ids) => {
                retainedAttachmentInput.value = ids.join(',');
            };

            const updateAttachmentEmptyState = () => {
                const visibleExisting = attachmentList.querySelectorAll('[data-existing-attachment-id]:not(.d-none)').length;
                const hasAttachments = visibleExisting > 0 || selectedFiles.length > 0;
                if (attachmentEmpty) {
                    attachmentEmpty.textContent = noAttachmentsLabel;
                    attachmentEmpty.classList.toggle('d-none', hasAttachments);
                }
            };

            const renderSelectedFiles = () => {
                attachmentList.querySelectorAll('[data-compose-new-attachment]').forEach((item) => item.remove());

                selectedFiles.forEach((file, index) => {
                    const item = document.createElement('li');
                    item.className = 'list-group-item px-0 d-flex justify-content-between align-items-center gap-3';
                    item.dataset.composeNewAttachment = '1';

                    const meta = document.createElement('div');
                    meta.className = 'min-w-0';
                    const name = document.createElement('div');
                    name.className = 'fw-semibold text-truncate';
                    name.textContent = file.name;
                    const info = document.createElement('small');
                    info.className = 'text-body-secondary';
                    info.textContent = Math.max(1, Math.round(file.size / 1024)) + ' KB';
                    meta.appendChild(name);
                    meta.appendChild(info);

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.className = 'btn btn-sm btn-outline-danger';
                    removeButton.dataset.removeNewAttachment = String(index);
                    removeButton.setAttribute('aria-label', 'Attachment entfernen: ' + file.name);
                    removeButton.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';

                    item.appendChild(meta);
                    item.appendChild(removeButton);
                    attachmentList.appendChild(item);
                });

                updateAttachmentEmptyState();
            };

            if (attachmentChoose) {
                attachmentChoose.addEventListener('click', function () {
                    attachmentInput.click();
                });
            }

            attachmentInput.addEventListener('change', function () {
                mergeFiles(Array.from(attachmentInput.files || []));
                syncFileInput();
                renderSelectedFiles();
            });

            if (attachmentDropzone) {
                attachmentDropzone.addEventListener('dragover', function (event) {
                    event.preventDefault();
                    attachmentDropzone.classList.add('is-dragover');
                    if (dropLabelElement) {
                        dropLabelElement.textContent = dropActiveLabel;
                    }
                });
                ['dragleave', 'dragend'].forEach(function (eventName) {
                    attachmentDropzone.addEventListener(eventName, function () {
                        attachmentDropzone.classList.remove('is-dragover');
                        if (dropLabelElement) {
                            dropLabelElement.textContent = dropLabel;
                        }
                    });
                });
                attachmentDropzone.addEventListener('drop', function (event) {
                    event.preventDefault();
                    attachmentDropzone.classList.remove('is-dragover');
                    if (dropLabelElement) {
                        dropLabelElement.textContent = dropLabel;
                    }

                    const files = Array.from((event.dataTransfer && event.dataTransfer.files) || []);
                    if (files.length === 0) {
                        return;
                    }

                    mergeFiles(files);
                    syncFileInput();
                    renderSelectedFiles();
                });
            }

            attachmentList.addEventListener('click', function (event) {
                const removeExisting = event.target.closest('[data-remove-existing-attachment]');
                if (removeExisting) {
                    const attachmentId = removeExisting.dataset.removeExistingAttachment;
                    const nextIds = retainedIds().filter((id) => id !== attachmentId);
                    setRetainedIds(nextIds);
                    const item = attachmentList.querySelector('[data-existing-attachment-id="' + attachmentId + '"]');
                    if (item) {
                        item.classList.add('d-none');
                    }
                    updateAttachmentEmptyState();
                    return;
                }

                const removeNew = event.target.closest('[data-remove-new-attachment]');
                if (!removeNew) {
                    return;
                }

                const index = Number(removeNew.dataset.removeNewAttachment);
                if (Number.isNaN(index)) {
                    return;
                }

                selectedFiles = selectedFiles.filter((_, fileIndex) => fileIndex !== index);
                syncFileInput();
                renderSelectedFiles();
            });

            if (dropLabelElement) {
                dropLabelElement.textContent = dropLabel;
            }

            attachmentList.querySelectorAll('[data-existing-attachment-id]').forEach(function (item) {
                const attachmentId = item.getAttribute('data-existing-attachment-id') || '';
                item.classList.toggle('d-none', attachmentId !== '' && !retainedIds().includes(attachmentId));
            });

            renderSelectedFiles();
        });
    };
    (function(){
        const actionModalEl = document.getElementById('actionModal');
        if (!actionModalEl) return;
        const actionModal = window.bootstrap.Modal.getOrCreateInstance(actionModalEl);
        const actionModalDialog = actionModalEl.querySelector('.modal-dialog');
        const modalTitle = actionModalEl.querySelector('.modal-title');
        const modalContent = actionModalEl.querySelector('.action-modal-content');
        const modalLoader = actionModalEl.querySelector('.action-modal-loader');
        const modalFooter = actionModalEl.querySelector('.modal-footer');
        const modalDismissButton = actionModalEl.querySelector('.action-modal-dismiss');
        const modalFooterButtonsTarget = actionModalEl.querySelector('.modal-form-buttons-target');
        const formLoadErrorText = actionModalEl.dataset.formLoadError || 'Form could not be loaded.';
        const defaultDismissLabel = actionModalEl.dataset.defaultDismissLabel || 'Cancel';
        const modalSizeClasses = ['modal-sm', 'modal-lg', 'modal-xl', 'modal-fullscreen'];
        const setModalSize = (size) => {
            if (!actionModalDialog) return;
            modalSizeClasses.forEach(cls => actionModalDialog.classList.remove(cls));
            actionModalDialog.classList.add(size || 'modal-lg');
        };
        document.addEventListener('click', function(ev) {
            const btn = ev.target.closest('.action-btn-modal');
            if (!btn) return;
            ev.preventDefault();
            const url = btn.getAttribute('href');
            const title = btn.dataset.title || '...';
            setModalSize(btn.dataset.modalSize || 'modal-lg');
            modalTitle.textContent = title;
            if (modalDismissButton) {
                modalDismissButton.textContent = btn.dataset.dismissLabel || defaultDismissLabel;
            }
            modalContent.innerHTML = '';
            if (modalFooterButtonsTarget) {
                modalFooterButtonsTarget.innerHTML = '';
            } else {
                modalFooter.innerHTML = '';
            }
            modalLoader.classList.remove('d-none');
            actionModal.show();
            const updateModalFooterButtons = () => {
                if (modalFooterButtonsTarget) {
                    modalFooterButtonsTarget.innerHTML = '';
                } else {
                    modalFooter.innerHTML = '';
                }
                const buttonContainer = modalContent.querySelector('.modal-form-buttons');
                if (buttonContainer) {
                    const buttons = buttonContainer.querySelectorAll('button, input[type="submit"]');
                    buttons.forEach(b => {
                        const newBtn = b.cloneNode(true);
                        newBtn.classList.remove('d-none'); // Ensure it's visible in footer
                        newBtn.addEventListener('click', (e) => {
                            const hiddenInput = document.createElement('input');
                            hiddenInput.type = 'hidden';
                            hiddenInput.name = b.getAttribute('name');
                            hiddenInput.value = b.getAttribute('value') || '1';
                            const currentForm = modalContent.querySelector('form');
                            if (currentForm) {
                                currentForm.appendChild(hiddenInput);
                                currentForm.requestSubmit();
                            }
                        });
                        if (modalFooterButtonsTarget) {
                            modalFooterButtonsTarget.appendChild(newBtn);
                        } else {
                            modalFooter.appendChild(newBtn);
                        }
                    });
                }
            };
            const attachFormListener = (form, originalUrl) => {
                if (form.matches('[data-avalynxform]')) {
                    // AvalynxForm (avalynx-form-init.js) owns this form.
                    return;
                }
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (typeof form.__syncMailboxListEditors === 'function') {
                        form.__syncMailboxListEditors();
                    }
                    const formData = new FormData(form);
                    modalLoader.classList.remove('d-none');
                    modalContent.classList.add('opacity-50');
                    fetch(form.getAttribute('action') || originalUrl, {
                        method: 'POST',
                        body: formData,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(res => {
                        if ((res.headers.get('content-type') || '').includes('application/json')) {
                            return res.json();
                        }
                        return res.text();
                    })
                    .then(data => {
                        modalLoader.classList.add('d-none');
                        modalContent.classList.remove('opacity-50');
                        if (data.success) {
                            actionModal.hide();
                            window.location.reload(); // Simple reload for now to reflect changes
                        } else if (typeof data === 'string' && data.includes('modal-form-ajax-success')) {
                            modalContent.innerHTML = data;
                            initMailboxListEditors(modalContent);
                            initComposeEditors(modalContent);
                            const newFormAfterNav = modalContent.querySelector('form');
                            if (newFormAfterNav) {
                                attachFormListener(newFormAfterNav, originalUrl);
                            }
                        } else {
                            modalContent.innerHTML = data;
                            initMailboxListEditors(modalContent);
                            initComposeEditors(modalContent);
                            updateModalFooterButtons();
                            const errorForm = modalContent.querySelector('form');
                            if (errorForm) {
                                attachFormListener(errorForm, originalUrl);
                            }
                        }
                    })
                    .catch(err => {
                        console.error('Submit failed', err);
                        modalLoader.classList.add('d-none');
                        modalContent.classList.remove('opacity-50');
                    });
                });
            };
            fetch(url + (url.includes('?') ? '&' : '?') + '_=' + Date.now(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store'
            })
            .then(response => response.text())
            .then(html => {
                modalLoader.classList.add('d-none');
                modalContent.innerHTML = html;
                initMailboxListEditors(modalContent);
                initComposeEditors(modalContent);
                updateModalFooterButtons();
                const form = modalContent.querySelector('form');
                if (form) {
                    attachFormListener(form, url);
                }
            })
            .catch(err => {
                console.error('Fetch failed', err);
                modalLoader.classList.add('d-none');
                modalContent.innerHTML = '<div class="alert alert-danger m-4">' + formLoadErrorText + '</div>';
            });
        });
    })();
    (function(){
        const confirmModalEl = document.getElementById('confirmModal');
        if (!confirmModalEl) return;
        const confirmModal = window.bootstrap.Modal.getOrCreateInstance(confirmModalEl);
        const confirmText = confirmModalEl.querySelector('.confirm-modal-text');
        const confirmBtn = confirmModalEl.querySelector('.confirm-modal-button');
        const defaultConfirmText = confirmModalEl.dataset.defaultConfirm || 'Are you sure?';
        let currentTrigger = null;
        document.addEventListener('click', function(ev) {
            const btn = ev.target.closest('.confirm-action');
            if (!btn) return;
            ev.preventDefault();
            ev.stopPropagation();
            currentTrigger = btn;
            const text = btn.dataset.confirm || defaultConfirmText;
            if (confirmText) confirmText.textContent = text;
            confirmModal.show();
        });
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                if (currentTrigger) {
                    const form = currentTrigger.closest('form');
                    if (form) {
                        if (form.classList.contains('modal-form-ajax')) {
                            const formData = new FormData(form);
                            fetch(form.getAttribute('action'), {
                                method: 'POST',
                                body: formData,
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            })
                            .then(res => res.headers.get('content-type').includes('application/json') ? res.json() : res.text())
                            .then(data => {
                                if (data.success || (typeof data === 'string' && data.includes('modal-form-ajax-success'))) {
                                    confirmModal.hide();
                                    const actionModalEl = document.getElementById('actionModal');
                                    if (actionModalEl && actionModalEl.classList.contains('show')) {
                                        const modalContent = actionModalEl.querySelector('.action-modal-content');
                                        if (modalContent && typeof data === 'string') {
                                            modalContent.innerHTML = data;
                                        } else {
                                            window.location.reload();
                                        }
                                    } else {
                                        window.location.reload();
                                    }
                                } else {
                                    window.location.reload();
                                }
                            })
                            .catch(err => {
                                console.error('Confirm action failed', err);
                                window.location.reload();
                            });
                        } else {
                            form.submit();
                        }
                    } else if (currentTrigger.tagName === 'A') {
                        const method = currentTrigger.dataset.method || 'GET';
                        const token = currentTrigger.dataset.token;
                        if (method.toUpperCase() === 'POST') {
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.action = currentTrigger.getAttribute('href');
                            if (token) {
                                const input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = '_token';
                                input.value = token;
                                form.appendChild(input);
                            }
                            document.body.appendChild(form);
                            form.submit();
                        } else {
                            window.location.href = currentTrigger.getAttribute('href');
                        }
                    }
                    confirmModal.hide();
                }
            });
        }
    })();
    (function(){
        function isDisplayModeStandalone(){
            return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || (window.navigator && window.navigator.standalone);
        }
        function isDesktopXXL(){
            try { return window.matchMedia('(min-width: 1400px)').matches; } catch(_) { return false; }
        }
        function computeEnv(){
            const desktopXXL = isDesktopXXL();
            const pwa = !!isDisplayModeStandalone();
            return {
                isDesktopXXL: desktopXXL,
                isPWA: pwa,
                isMobileLike: !desktopXXL || pwa
            };
        }
        window.AppEnv = computeEnv();
        document.documentElement.dataset.desktopXxl = String(!!window.AppEnv.isDesktopXXL);
        document.documentElement.dataset.pwa = String(!!window.AppEnv.isPWA);
        document.documentElement.dataset.mobileLike = String(!!window.AppEnv.isMobileLike);
        let rt;
        window.addEventListener('resize', function(){
            clearTimeout(rt);
            rt = setTimeout(function(){
                window.AppEnv = computeEnv();
                document.documentElement.dataset.desktopXxl = String(!!window.AppEnv.isDesktopXXL);
                document.documentElement.dataset.pwa = String(!!window.AppEnv.isPWA);
                document.documentElement.dataset.mobileLike = String(!!window.AppEnv.isMobileLike);
            }, 150);
        });
    })();
    (function(){
        var emailListEl = document.getElementById('emailListOffcanvas');
        var mailboxEl = document.getElementById('mailboxOffcanvas');
        if (emailListEl && window.AppEnv && window.AppEnv.isMobileLike && !(window.AppEnv.isDesktopXXL)){
            try {
                var alreadyShown = emailListEl.classList.contains('show');
                if (!alreadyShown && window.bootstrap && window.bootstrap.Offcanvas){
                    var inst = window.bootstrap.Offcanvas.getOrCreateInstance(emailListEl, {backdrop: true, scroll: true});
                    setTimeout(function(){ inst.show(); }, 50);
                }
            } catch(e){ console.warn('Open emailListOffcanvas failed', e); }
        }
        if (mailboxEl){
            var folderClicked = false;
            mailboxEl.addEventListener('click', function(ev){
                var a = ev.target.closest('a.list-group-item');
                if (a && mailboxEl.contains(a) && a.matches('[data-folder-id]')){
                    folderClicked = true; // let navigation proceed
                }
            });
            mailboxEl.addEventListener('hidden.bs.offcanvas', function(){
                if (folderClicked && emailListEl && window.AppEnv && window.AppEnv.isMobileLike && window.bootstrap && window.bootstrap.Offcanvas){
                    try { window.bootstrap.Offcanvas.getOrCreateInstance(emailListEl, {backdrop: true, scroll: true}).show(); } catch(_){}
                }
                folderClicked = false;
            });
            mailboxEl.addEventListener('click', function(ev){
                var opt = ev.target.closest('.mailbox-option');
                if (!opt) return;
                if (!mailboxEl.contains(opt)) return;
                var href = opt.getAttribute('href');
                if (!href) return;
                ev.preventDefault();
                fetch(href, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
                    .then(function(resp){ return resp.text(); })
                    .then(function(html){
                        var tmp = document.createElement('div');
                        tmp.innerHTML = html;
                        var newFolders = tmp.querySelector('#folders-container');
                        var target = mailboxEl.querySelector('#folders-container');
                        if (newFolders && target){
                            target.innerHTML = newFolders.innerHTML;
                        }
                    })
                    .catch(function(e){ console.warn('Mailbox folders reload failed', e); });
            });
        }
    })();
    const listContainers = document.querySelectorAll('.email-list-container');
    const detailContainer = document.getElementById('email-detail');
    if (detailContainer) {
        setTimeout(function(){ setupEmailActionOverflow(detailContainer); }, 0);
    }
    function updateFolderUnreadBadges(countMap) {
        if (!countMap) return;
        try {
            Object.keys(countMap).forEach(function (fid) {
                document.querySelectorAll('.folder-unread-badge[data-folder-id="' + fid + '"]').forEach(function (el) {
                    el.textContent = countMap[fid];
                    try {
                        const tpl = (window.I18N && window.I18N.folder_unread_aria) || 'Ungelesene E-Mails: __COUNT__';
                        el.setAttribute('aria-label', String(tpl).replace('__COUNT__', countMap[fid]));
                    } catch(_) {
                        el.setAttribute('aria-label', 'Ungelesene E-Mails: ' + countMap[fid]);
                    }
                });
            });
        } catch (e) {
            console.error(e);
        }
    }
    function setActiveEmailItem(clicked) {
        document.querySelectorAll('.email-item').forEach(el => {
            el.classList.remove('email-active');
            el.setAttribute('aria-selected', 'false');
        });
        if (clicked) {
            clicked.classList.add('email-active');
            clicked.setAttribute('aria-selected', 'true');
        }
    }
    function updateSelectedEmailUrl(listContainer, emailId) {
        if (!window.history || typeof window.history.replaceState !== 'function' || !listContainer || !emailId) {
            return;
        }
        const folderId = listContainer.getAttribute('data-current-folder-id') || listContainer.getAttribute('data-folder-id') || '';
        if (!folderId) {
            return;
        }
        const mailboxId = listContainer.getAttribute('data-mailbox-id') || 'all';
        const nextUrl = '/mails/' + encodeURIComponent(mailboxId) + '/' + encodeURIComponent(folderId) + '/' + encodeURIComponent(emailId);
        window.history.replaceState({mailboxId, folderId, emailId}, '', nextUrl);
    }
    function setupEmailActionOverflow(root){
        try{
            const bar = root && root.querySelector ? root.querySelector('.email-action-bar') : null;
            if (!bar) return;
            const actionsGroup = bar.querySelector('[data-overflow-group="actions"]');
            const menu = bar.querySelector('[data-overflow-menu="actions"]');
            const moreGroup = bar.querySelector('.overflow-more');
            if (!actionsGroup || !menu || !moreGroup) return;
            const actions = Array.from(actionsGroup.querySelectorAll('[data-action-id]'));
            const proxies = new Map();
            actions.forEach(function(act){
                const id = act.getAttribute('data-action-id');
                if (!id) return;
                let li = menu.querySelector('[data-menu-for="' + id + '"]');
                if (!li){
                    const a = document.createElement('a');
                    a.className = 'dropdown-item d-flex align-items-center';
                    let href = act.getAttribute('href') || '#';
                    let target = act.getAttribute('target');
                    if (act.tagName === 'FORM' || (act.tagName === 'A' && act.dataset.method)) {
                        const btn = act.tagName === 'FORM' ? act.querySelector('button, input[type="submit"]') : act;
                        if (btn) {
                            href = '#';
                        }
                    }
                    a.setAttribute('href', href);
                    if (target) a.setAttribute('target', target);
                    a.setAttribute('data-menu-for', id);
                    if (act.classList.contains('action-btn-modal')) {
                        a.classList.add('action-btn-modal');
                        if (act.dataset.title) a.dataset.title = act.dataset.title;
                        if (act.dataset.modalSize) a.dataset.modalSize = act.dataset.modalSize;
                    }
                    const ico = act.querySelector('i');
                    const ic = document.createElement('i');
                    ic.className = ico ? ico.className + ' me-2' : 'fa-solid fa-gear me-2';
                    ic.setAttribute('aria-hidden', 'true');
                    a.appendChild(ic);
                    const t = document.createTextNode(act.getAttribute('title') || act.getAttribute('aria-label') || id);
                    a.appendChild(t);
                    const wrap = document.createElement('li');
                    wrap.appendChild(a);
                    menu.appendChild(wrap);
                    li = a;
                } else if (li.tagName === 'LI') {
                    li = li.querySelector('a,button') || li;
                }
                proxies.set(id, li);
                if (li){
                    li.addEventListener('click', function(ev){
                        const isAnchor = act.tagName === 'A' && act.getAttribute('href') && act.getAttribute('href') !== '#';
                        if (isAnchor && !act.classList.contains('action-btn-modal')){ return; }
                        ev.preventDefault();
                        if (act.tagName === 'FORM') {
                            const confirmBtnInForm = act.querySelector('.confirm-action');
                            if (confirmBtnInForm) {
                                confirmBtnInForm.click();
                            } else {
                                act.requestSubmit();
                            }
                        } else {
                            act.click();
                        }
                    });
                }
            });
            function applyLayout(){
                moreGroup.classList.remove('d-none');
                actions.forEach(function(a){ a.classList.remove('d-none'); });
                menu.querySelectorAll('li').forEach(function(li){ li.classList.add('d-none'); });
                moreGroup.classList.add('d-none');
                const sorted = actions.slice().sort(function(a,b){
                    const pa = parseInt(a.getAttribute('data-priority')||'0',10);
                    const pb = parseInt(b.getAttribute('data-priority')||'0',10);
                    return pa - pb;
                });
                moreGroup.classList.remove('d-none');
                const maxLoops = actions.length;
                let loops = 0;
                while (actionsGroup.scrollWidth > actionsGroup.clientWidth && loops < maxLoops){
                    const toHide = sorted[loops];
                    if (!toHide) break;
                    toHide.classList.add('d-none');
                    const id = toHide.getAttribute('data-action-id');
                    const proxy = proxies.get(id);
                    if (proxy){
                        const li = proxy.closest('li') || proxy;
                        li.classList.remove('d-none');
                    }
                    loops++;
                }
                if (loops === 0) {
                    moreGroup.classList.add('d-none');
                }
            }
            applyLayout();
            let rt;
            window.addEventListener('resize', function(){
                clearTimeout(rt); rt = setTimeout(applyLayout, 100);
            });
        }catch(e){ console.warn('setupEmailActionOverflow failed', e); }
    }
    async function loadEmailDetail(id, clickedEl) {
        if (!detailContainer || !id) return;
        const previousHtml = detailContainer.innerHTML;
        const loadingText = (window.I18N && window.I18N.loading_email) || 'Lade E-Mail…';
        detailContainer.innerHTML = '<div class="p-5 text-center text-body-secondary">' + loadingText + '</div>';
        try {
            const listContainer = clickedEl ? clickedEl.closest('.email-list-container') : document.querySelector('.email-list-container');
            const mailboxId = listContainer ? listContainer.getAttribute('data-mailbox-id') : 'all';
            const resp = await fetch('/email/' + encodeURIComponent(id) + '?mailboxId=' + encodeURIComponent(mailboxId), {headers: {'X-Requested-With': 'XMLHttpRequest'}});
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const html = await resp.text();
            detailContainer.innerHTML = html;
            setActiveEmailItem(clickedEl);
            updateSelectedEmailUrl(listContainer, id);
            setupEmailActionOverflow(detailContainer);
        } catch (e) {
            console.error(e);
            const errText = (window.I18N && window.I18N.email_load_error) || 'E-Mail konnte nicht geladen werden.';
            detailContainer.innerHTML = '<div class="alert alert-danger m-4">' + errText + '</div>' + previousHtml;
        }
    }
    async function postRead(id, read) {
        const body = new URLSearchParams();
        body.set('read', read ? '1' : '0');
        const resp = await fetch('/email/' + encodeURIComponent(id) + '/read', {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded'},
            body
        });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        return resp.json();
    }
    async function postFavorite(id, favorite) {
        const body = new URLSearchParams();
        body.set('favorite', favorite ? '1' : '0');
        const resp = await fetch('/email/' + encodeURIComponent(id) + '/favorite', {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded'},
            body
        });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        return resp.json();
    }
    document.querySelectorAll('.email-list-container').forEach(function (container) {
        const header = container.previousElementSibling; // sticky search header
        if (!header) return;
        const input = header.querySelector('input[type="text"]');
        const button = header.querySelector('button.btn-primary, button.btn-outline-primary');
        const clearBtn = header.querySelector('[data-action="clear-search"]');
        let noRes = container.querySelector('.email-no-results');
        if (!noRes) {
            noRes = document.createElement('div');
            noRes.className = 'email-no-results p-4 text-body-secondary d-none';
            noRes.textContent = (window.I18N && window.I18N.no_results) || 'Keine Treffer.';
            container.appendChild(noRes);
        }
        function filterList(query) {
            const q = (query || '').trim().toLowerCase();
            let visible = 0;
            container.querySelectorAll('.email-item').forEach(function (item) {
                const text = item.textContent.toLowerCase();
                const match = q === '' || text.indexOf(q) !== -1;
                item.classList.toggle('d-none', !match);
                if (match) visible++;
            });
            noRes.classList.toggle('d-none', visible !== 0);
            if (clearBtn) { clearBtn.classList.toggle('d-none', q === ''); }
        }
        if (input) {
            input.addEventListener('input', function () { filterList(input.value); });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { input.value = ''; filterList(''); }
                if (e.key === 'Enter') { e.preventDefault(); filterList(input.value); }
            });
        }
        if (button) {
            button.addEventListener('click', function () { if (input) filterList(input.value); });
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (input) { input.value = ''; input.focus(); }
                filterList('');
            });
            if (input) { clearBtn.classList.toggle('d-none', (input.value || '').trim() === ''); }
        }
    });
    document.querySelectorAll('.mailbox-dropdown').forEach(function (dd) {
        const menu = dd.querySelector('.dropdown-menu');
        const input = dd.querySelector('.mailbox-search');
        const clearBtn = dd.querySelector('[data-action="clear-mailbox-search"]');
        const options = menu ? menu.querySelectorAll('.mailbox-option') : [];
        const noRes = dd.querySelector('.mailbox-no-results');
        function applyFilter(query) {
            const q = (query || '').trim().toLowerCase();
            let visible = 0;
            options.forEach(function (a) {
                const li = a.closest('li');
                const text = a.textContent.toLowerCase();
                const match = q === '' || text.indexOf(q) !== -1;
                if (li) li.classList.toggle('d-none', !match);
                if (match) visible++;
            });
            if (noRes) noRes.classList.toggle('d-none', !(q !== '' && visible === 0));
            if (clearBtn) clearBtn.classList.toggle('d-none', q === '');
        }
        if (input) {
            input.addEventListener('input', function () { applyFilter(input.value); });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { input.value = ''; applyFilter(''); }
                if (e.key === 'Enter') { e.preventDefault(); applyFilter(input.value); }
            });
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (input) { input.value = ''; input.focus(); }
                applyFilter('');
            });
            if (input) clearBtn.classList.toggle('d-none', (input.value || '').trim() === '');
        }
        dd.addEventListener('shown.bs.dropdown', function () {
            if (input) { input.focus(); input.select(); }
        });
    });
    function findScrollParent(el){
        let node = el && el.parentElement;
        while (node && node !== document.body){
            const style = window.getComputedStyle(node);
            if ((node.scrollHeight > node.clientHeight) && (/(auto|scroll)/).test(style.overflowY)){
                return node;
            }
            node = node.parentElement;
        }
        return window; // fallback to window scrolling
    }
    function initInfiniteScroll(container){
        if (!container) return;
        const sentinel = container.querySelector('.email-load-more-sentinel');
        if (!sentinel) return;
        const folderId = container.getAttribute('data-folder-id') || '';
        const mailboxId = container.getAttribute('data-mailbox-id') || '';
        let total = parseInt(container.getAttribute('data-total') || '0', 10);
        let offset = parseInt(container.getAttribute('data-offset') || '0', 10);
        const limit = parseInt(container.getAttribute('data-limit') || '10', 10);
        let loading = false;
        if (!folderId) return;
        async function loadMore(){
            if (loading) return;
            if (offset >= total) return;
            loading = true;
            const moreText = (window.I18N && window.I18N.load_more) || 'Lade mehr…';
            sentinel.innerHTML = '<div class="text-center text-body-secondary py-2">' + moreText + '</div>';
            try {
                let url = '/folder/' + encodeURIComponent(folderId) + '/more?offset=' + encodeURIComponent(offset) + '&limit=' + encodeURIComponent(limit);
                if (mailboxId) {
                    url += '&mailboxId=' + encodeURIComponent(mailboxId);
                }
                const resp = await fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const html = await resp.text();
                const tmp = document.createElement('div');
                tmp.innerHTML = html;
                const items = Array.from(tmp.children);
                items.forEach(function(it){ sentinel.parentElement.insertBefore(it, sentinel); });
                offset += items.length;
                container.setAttribute('data-offset', String(offset));
                if (offset >= total){ observer.disconnect(); sentinel.innerHTML = ''; }
            } catch (e) {
                console.error(e);
            } finally {
                loading = false;
                sentinel.innerHTML = '';
            }
        }
        const rootEl = findScrollParent(container);
        const options = {root: rootEl === window ? null : rootEl, rootMargin: '100px'};
        const observer = new IntersectionObserver((entries)=>{
            entries.forEach((entry)=>{
                if (entry.isIntersecting){ loadMore(); }
            });
        }, options);
        observer.observe(sentinel);
    }
    listContainers.forEach(container => {
        initInfiniteScroll(container);
        let singleClickTimer = null;
        let pendingItem = null;
        let pendingId = null;
        function clearSingleClickTimer(){
            if (singleClickTimer){ clearTimeout(singleClickTimer); singleClickTimer = null; }
            pendingItem = null;
            pendingId = null;
        }
        container.addEventListener('click', function (event) {
            const lpItem = event.target.closest('.email-item');
            if (lpItem && lpItem.getAttribute('data-lp-just-toggled') === '1') {
                event.preventDefault();
                event.stopPropagation();
                lpItem.removeAttribute('data-lp-just-toggled');
                return;
            }
            const toggleBtn = event.target.closest('.toggle-read');
            if (toggleBtn) {
                event.preventDefault();
                event.stopPropagation();
                clearSingleClickTimer();
                const id = toggleBtn.getAttribute('data-email');
                const wantRead = toggleBtn.getAttribute('data-read') === '1';
                postRead(id, wantRead).then(json => {
                    if (!json.success) return;
                    toggleBtn.setAttribute('data-read', json.read ? '0' : '1');
                    const icon = toggleBtn.querySelector('i');
                    if (icon) icon.className = 'fa-solid ' + (json.read ? 'fa-envelope' : 'fa-envelope-open');
                    const item = toggleBtn.closest('.email-item');
                    if (item) {
                        if (json.read) item.classList.remove('email-unread'); else item.classList.add('email-unread');
                        const sender = item.querySelector('span');
                        if (sender) sender.classList.toggle('fw-bold', !json.read);
                        const subj = item.querySelector('.email-subject');
                        if (subj) subj.classList.toggle('fw-semibold', !json.read);
                    }
                    if (detailContainer && detailContainer.querySelector('.toggle-read-detail') && detailContainer.querySelector('[data-email="' + id + '"]')) {
                        const dbtn = detailContainer.querySelector('.toggle-read-detail');
                        dbtn.setAttribute('data-read', json.read ? '0' : '1');
                        const dicon = dbtn.querySelector('i');
                        if (dicon) dicon.className = 'fa-solid ' + (json.read ? 'fa-envelope' : 'fa-envelope-open') + ' me-1';
                        const dtext = dbtn.querySelector('.toggle-read-detail-text');
                        if (dtext) dtext.textContent = json.read ? 'Ungelesen' : 'Gelesen';
                    }
                    updateFolderUnreadBadges(json.folderCounts);
                }).catch(console.error);
                return;
            }
            const item = event.target.closest('.email-item');
            if (!item) return;
            const id = item.getAttribute('data-email');
            clearSingleClickTimer();
            pendingItem = item;
            pendingId = id;
            singleClickTimer = setTimeout(function(){
                if (!pendingId || !pendingItem) { return; }
                loadEmailDetail(pendingId, pendingItem);
                const offcanvasEl = container.closest && container.closest('.offcanvas');
                if (offcanvasEl && offcanvasEl.id === 'emailListOffcanvas' && window.bootstrap && window.bootstrap.Offcanvas) {
                    try { window.bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl).hide(); } catch (e) {  }
                }
                clearSingleClickTimer();
            }, 500);
        });
        container.addEventListener('dblclick', function (event) {
            const item = event.target.closest('.email-item');
            if (!item) return;
            clearSingleClickTimer();
            const toggleBtn = item.querySelector('.toggle-read');
            if (!toggleBtn) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            const id = toggleBtn.getAttribute('data-email');
            const wantRead = toggleBtn.getAttribute('data-read') === '1';
            postRead(id, wantRead).then(json => {
                if (!json.success) return;
                toggleBtn.setAttribute('data-read', json.read ? '0' : '1');
                const icon = toggleBtn.querySelector('i');
                if (icon) icon.className = 'fa-solid ' + (json.read ? 'fa-envelope' : 'fa-envelope-open');
                if (json.read) item.classList.remove('email-unread'); else item.classList.add('email-unread');
                const sender = item.querySelector('span');
                if (sender) sender.classList.toggle('fw-bold', !json.read);
                const subj = item.querySelector('.email-subject');
                if (subj) subj.classList.toggle('fw-semibold', !json.read);
                if (detailContainer && detailContainer.querySelector('.toggle-read-detail') && detailContainer.querySelector('[data-email="' + id + '"]')) {
                    const dbtn = detailContainer.querySelector('.toggle-read-detail');
                    dbtn.setAttribute('data-read', json.read ? '0' : '1');
                    const dicon = dbtn.querySelector('i');
                    if (dicon) dicon.className = 'fa-solid ' + (json.read ? 'fa-envelope' : 'fa-envelope-open') + ' me-1';
                    const dtext = dbtn.querySelector('.toggle-read-detail-text');
                    if (dtext) dtext.textContent = json.read ? 'Ungelesen' : 'Gelesen';
                }
                updateFolderUnreadBadges(json.folderCounts);
            }).catch(console.error);
        });
        const timers = new WeakMap();
        const fired = new WeakMap();
        function clearLP(item){
            const t = timers.get(item);
            if (t){ clearTimeout(t); timers.delete(item); }
        }
        function startLP(target){
            const item = target && target.closest && target.closest('.email-item');
            if (!item) return;
            clearLP(item);
            fired.set(item, false);
            const t = setTimeout(function(){
                fired.set(item, true);
                item.setAttribute('data-lp-just-toggled','1');
                const fav = item.querySelector('.favorite-indicator');
                const id = item.getAttribute('data-email');
                const wantFav = fav ? fav.classList.contains('d-none') : true;
                postFavorite(id, wantFav).then(json => {
                    if (!json.success) return;
                    const indicators = item.querySelectorAll('.favorite-indicator');
                    indicators.forEach(function(el){ el.classList.toggle('d-none', !json.favorite); });
                }).catch(console.error);
            }, 2000);
            timers.set(item, t);
        }
        function maybeCancel(e){
            const item = e.target && e.target.closest && e.target.closest('.email-item');
            if (!item) return;
            const wasFired = fired.get(item) === true;
            clearLP(item);
            if (wasFired){
                item.setAttribute('data-lp-just-toggled','1');
            }
        }
        container.addEventListener('mousedown', function(e){ startLP(e.target); });
        container.addEventListener('touchstart', function(e){ if (e.touches && e.touches.length>0) startLP(e.target); }, {passive: true});
        container.addEventListener('mouseup', maybeCancel);
        container.addEventListener('mouseleave', maybeCancel);
        container.addEventListener('touchend', maybeCancel);
        container.addEventListener('touchcancel', maybeCancel);
        container.addEventListener('dragstart', maybeCancel);
    });
    document.addEventListener('click', function (event) {
        const btn = event.target.closest('.toggle-read-detail');
        if (!btn) return;
        const id = btn.getAttribute('data-email');
        const wantRead = btn.getAttribute('data-read') === '1';
        postRead(id, wantRead).then(json => {
            if (!json.success) return;
            btn.setAttribute('data-read', json.read ? '0' : '1');
            const icon = btn.querySelector('i');
            if (icon) icon.className = 'fa-solid ' + (json.read ? 'fa-envelope' : 'fa-envelope-open') + ' me-1';
            const text = btn.querySelector('.toggle-read-detail-text');
            if (text) {
                const unreadLabel = (window.I18N && window.I18N.unread_label) || 'Ungelesen';
                const readLabel = (window.I18N && window.I18N.read_label) || 'Gelesen';
                text.textContent = json.read ? unreadLabel : readLabel;
            }
            const item = document.querySelector('.email-item[data-email="' + id + '"]');
            if (item) {
                if (json.read) item.classList.remove('email-unread'); else item.classList.add('email-unread');
                const toggleBtn = item.querySelector('.toggle-read');
                if (toggleBtn) {
                    toggleBtn.setAttribute('data-read', json.read ? '0' : '1');
                    const ic = toggleBtn.querySelector('i');
                    if (ic) ic.className = 'fa-solid ' + (json.read ? 'fa-envelope' : 'fa-envelope-open');
                }
                const sender = item.querySelector('span');
                if (sender) sender.classList.toggle('fw-bold', !json.read);
                const subj = item.querySelector('.email-subject');
                if (subj) subj.classList.toggle('fw-semibold', !json.read);
            }
            if (json.read) {
                const activeItem = document.querySelector('.email-item.email-active');
                let next = activeItem ? activeItem.nextElementSibling : null;
                while (next && !next.classList.contains('email-item')) next = next.nextElementSibling;
                while (next && !next.classList.contains('email-unread')) next = next.nextElementSibling;
                if (next && next.classList.contains('email-item')) {
                    const nextId = next.getAttribute('data-email');
                    if (nextId) {
                        loadEmailDetail(nextId, next);
                    }
                }
            }
            updateFolderUnreadBadges(json.folderCounts);
        }).catch(console.error);
    });
    (function(){
        function alertSuccess(msg){ try{ AvalynxAlert.success({message: msg, position: 'top-right'}); }catch(e){ console.log('SUCCESS:', msg);} }
        function alertError(msg){ try{ AvalynxAlert.error({message: msg, position: 'top-right'}); }catch(e){ console.error('ERROR:', msg);} }
        document.querySelectorAll('.email-list-container').forEach(function(container){
            container.addEventListener('dragstart', function(e){
                const item = e.target && e.target.closest && e.target.closest('.email-item');
                if (!item) return;
                const emailId = item.getAttribute('data-email');
                const sourceFolderId = container.getAttribute('data-current-folder-id') || '';
                try {
                    e.dataTransfer.setData('text/plain', emailId);
                    e.dataTransfer.setData('application/x-email-id', emailId);
                    e.dataTransfer.setData('application/x-source-folder-id', sourceFolderId);
                } catch(err) {}
                item.classList.add('dragging');
            });
            container.addEventListener('dragend', function(e){
                const item = e.target && e.target.closest && e.target.closest('.email-item');
                if (item) item.classList.remove('dragging');
                document.querySelectorAll('.folder-drop-target.drag-over').forEach(el=>el.classList.remove('drag-over'));
            });
        });
        function setupFolderDrops(root){
            root.querySelectorAll('.folder-drop-target').forEach(function(el){
                if (el.dataset.dropInit === '1') return;
                el.dataset.dropInit = '1';
                el.addEventListener('dragover', function(ev){
                    ev.preventDefault(); // allow drop
                    el.classList.add('drag-over');
                });
                el.addEventListener('dragleave', function(){ el.classList.remove('drag-over'); });
                el.addEventListener('drop', function(ev){
                    ev.preventDefault();
                    el.classList.remove('drag-over');
                    let emailId = '';
                    let sourceFolderId = '';
                    try {
                        emailId = ev.dataTransfer.getData('application/x-email-id') || ev.dataTransfer.getData('text/plain');
                        sourceFolderId = ev.dataTransfer.getData('application/x-source-folder-id');
                    } catch(err) {}
                    const targetFolderId = el.getAttribute('data-folder-id');
                    const targetFolderName = el.getAttribute('data-folder-name') || 'Ordner';
                    if (!emailId || !targetFolderId){ return; }
                    if (sourceFolderId && targetFolderId && sourceFolderId === targetFolderId){
                        alertError((window.I18N && window.I18N.already_in_folder) || 'E-Mail befindet sich bereits in diesem Ordner.');
                        return;
                    }
                    if (!window.__movingEmailIds) { window.__movingEmailIds = new Set(); }
                    if (window.__movingEmailIds.has(emailId)) { return; }
                    window.__movingEmailIds.add(emailId);
                    const body = new URLSearchParams();
                    body.set('sourceFolderId', sourceFolderId);
                    body.set('targetFolderId', targetFolderId);
                    fetch('/email/' + encodeURIComponent(emailId) + '/move', {
                        method: 'POST',
                        headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded'},
                        body
                    }).then(async resp => {
                        let json;
                        try { json = await resp.json(); } catch(e){ json = {success:false, error: ((window.I18N && window.I18N.unexpected_response) || 'Unerwartete Antwort') }; }
                        if (!resp.ok || !json || !json.success){
                            alertError(json && json.error ? json.error : ((window.I18N && window.I18N.move_failed) || 'Verschieben fehlgeschlagen.'));
                            return;
                        }
                        if (sourceFolderId){
                            document.querySelectorAll('.email-list-container[data-current-folder-id="' + sourceFolderId + '"] .email-item[data-email="' + emailId + '"]').forEach(function(it){
                                it.parentElement && it.parentElement.removeChild(it);
                            });
                        }
                        updateFolderUnreadBadges(json.folderCounts);
                        const movedTpl = (window.I18N && window.I18N.moved_to_folder) || 'E-Mail in "__NAME__" verschoben.';
                        alertSuccess(String(movedTpl).replace('__NAME__', targetFolderName));
                    }).catch(function(){
                        alertError((window.I18N && window.I18N.move_failed) || 'Verschieben fehlgeschlagen.');
                    }).finally(function(){
                        try { window.__movingEmailIds && window.__movingEmailIds.delete(emailId); } catch(e){}
                    });
                });
            });
        }
        // Full-page admin areas (settings, connectors, mailboxes, legal)
        // render their editors server-side, so initialize them on load —
        // the modal flow initializes its content itself after insertion.
        initMailboxListEditors(document);
        initComposeEditors(document);
        setupFolderDrops(document);
        const mo = new MutationObserver(function(){ setupFolderDrops(document); });
        mo.observe(document.body, {subtree:true, childList:true});
    })();
});
