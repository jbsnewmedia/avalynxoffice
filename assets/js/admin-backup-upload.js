/*
 * Chunked AJAX upload for the admin backup page.
 *
 * Splits the selected archive into small pieces and uploads them
 * sequentially (init → chunk … → finish), so archive size is not capped
 * by the PHP upload limits — only by the server-side limit (50 % of the
 * free disk space of the backup volume, enforced on init and per chunk).
 *
 * Activates on every form[data-backup-upload]; feedback goes inline at
 * the file input (missing/too large) and as AvalynxAlert for server
 * errors during the transfer.
 */
(function () {
    'use strict';

    const SELECTOR = 'form[data-backup-upload]';

    const init = () => {
        document.querySelectorAll(SELECTOR).forEach((form) => {
            if (form.dataset.backupUploadInit === 'true') {
                return;
            }
            form.dataset.backupUploadInit = 'true';
            form.addEventListener('submit', (event) => {
                onSubmit(event, form);
            });
        });
    };

    const onSubmit = async (event, form) => {
        event.preventDefault();

        const input = form.querySelector('input[type="file"]');
        const feedback = form.querySelector('.invalid-feedback');
        const progressBar = form.querySelector('[data-upload-bar]');
        const progressWrap = form.querySelector('[data-upload-progress]');
        const submit = form.querySelector('button[type="submit"]');
        const file = input && input.files && input.files.length > 0 ? input.files[0] : null;

        const resetInvalid = () => {
            if (input) input.classList.remove('is-invalid');
            if (feedback) feedback.textContent = '';
        };
        const showInvalid = (message) => {
            resetInvalid();
            if (input) input.classList.add('is-invalid');
            if (feedback) feedback.textContent = message;
        };
        const setProgress = (percent) => {
            if (!progressWrap || !progressBar) return;
            progressWrap.classList.remove('d-none');
            const clamped = Math.max(0, Math.min(100, Math.round(percent)));
            progressBar.style.width = clamped + '%';
            progressBar.setAttribute('aria-valuenow', String(clamped));
            progressBar.textContent = clamped + ' %';
        };
        const setBusy = (busy) => {
            if (submit) submit.disabled = busy;
            input.disabled = busy;
        };

        resetInvalid();

        if (!file) {
            showInvalid(form.dataset.msgMissing || '');
            return;
        }

        const maxBytes = parseInt(form.dataset.maxBytes || '0', 10);
        if (!(maxBytes > 0)) {
            showInvalid(form.dataset.msgUnavailable || '');
            return;
        }

        if (file.size > maxBytes) {
            showInvalid((form.dataset.msgTooLarge || '').replace('%limit%', formatBytes(maxBytes)).replace('%size%', formatBytes(file.size)));
            return;
        }

        setBusy(true);
        setProgress(0);
        const csrf = form.dataset.csrf || '';

        try {
            const started = await jsonRequest(form.dataset.actionInit || '', {
                method: 'POST',
                body: buildForm({ totalSize: String(file.size) }),
            }, csrf);
            const uploadId = typeof started.uploadId === 'string' ? started.uploadId : '';
            const chunkSize = parseInt(started.chunkSize || form.dataset.chunkSize || String(8 * 1024 * 1024), 10);

            if (uploadId === '') {
                throw new Error(form.dataset.msgFailed || '');
            }

            let offset = 0;
            let index = 0;
            while (offset < file.size) {
                const end = Math.min(offset + chunkSize, file.size);
                const response = await jsonRequest(
                    (form.dataset.actionChunk || '') + '?uploadId=' + encodeURIComponent(uploadId) + '&index=' + String(index),
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/octet-stream' },
                        body: file.slice(offset, end),
                    },
                    csrf
                );
                offset = typeof response.received === 'number' ? response.received : end;
                index += 1;
                setProgress(file.size > 0 ? (offset / file.size) * 100 : 100);
            }

            const finished = await jsonRequest(form.dataset.actionFinish || '', {
                method: 'POST',
                body: buildForm({ uploadId }),
            }, csrf);

            if (finished.redirect) {
                window.location.href = finished.redirect;
                return;
            }
            window.location.reload();
        } catch (error) {
            setBusy(false);
            setProgress(0);
            if (progressWrap) progressWrap.classList.add('d-none');
            if (typeof window.AvalynxAlert === 'function') {
                new window.AvalynxAlert(error && error.message ? error.message : (form.dataset.msgFailed || ''), 'danger', { position: 'top-right' });
            }
        }
    };

    const buildForm = (fields) => {
        const data = new FormData();
        Object.keys(fields).forEach((key) => data.append(key, fields[key]));
        return data;
    };

    const formatBytes = (bytes) => {
        if (!(bytes > 0)) return '0 B';
        const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        let value = bytes;
        let unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit += 1;
        }
        return Math.round(value * 10) / 10 + ' ' + units[unit];
    };

    const jsonRequest = async (url, options, csrfToken) => {
        const headers = Object.assign({}, options.headers || {}, {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken || '',
        });
        const response = await fetch(url, Object.assign({}, options, { headers }));
        let payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }
        if (!response.ok || !payload || payload.success !== true) {
            throw new Error(payload && payload.message ? payload.message : '');
        }
        return payload;
    };

    const start = () => {
        init();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
