(function () {
    'use strict';

    const root = document.querySelector('[data-aliexpress]');
    if (!root) return;

    async function copy(value) {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(value);
                return;
            } catch (_) {
                // The fallback also works on local HTTP sites and denied clipboard permissions.
            }
        }

        const textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.readOnly = true;
        textarea.style.cssText = 'position:fixed;left:-10000px;top:0';
        document.body.append(textarea);
        const focused = document.activeElement;
        try {
            textarea.select();
            if (!document.execCommand('copy')) throw new Error();
        } finally {
            textarea.remove();
            focused?.focus();
        }
    }

    const dialog = root.querySelector('[data-ae-dialog]');
    const title = dialog.querySelector('[data-ae-dialog-title]');
    const content = dialog.querySelector('[data-ae-dialog-content]');
    const status = dialog.querySelector('[data-ae-dialog-status]');
    const spinner = status.querySelector('[data-ae-dialog-spinner]');
    const message = status.querySelector('[data-ae-dialog-message]');
    const retry = status.querySelector('[data-ae-retry]');
    const closeButton = dialog.querySelector('header [data-ae-close]');
    const ajaxHeader = { 'X-Requested-With': 'XMLHttpRequest' };
    let opener;
    let pendingForm;
    let saving = false;

    function showStatus(text = '', loading = false, retryUrl = '') {
        message.textContent = text;
        spinner.hidden = !loading;
        if (retry) {
            retry.hidden = !retryUrl;
            retry.dataset.aeFormUrl = retryUrl;
        }
        status.hidden = !text;
        content.setAttribute('aria-busy', String(loading));
    }

    function openDialog(label, trigger) {
        if (!dialog.open) {
            opener = trigger;
            title.textContent = label;
            dialog.showModal();
        }
    }

    function closeDialog() {
        if (saving) return;
        pendingForm?.abort();
        pendingForm = null;
        showStatus();
        dialog.close();
        content.replaceChildren();
        if (opener?.isConnected) opener.focus();
        opener = null;
    }

    async function loadForm(url, label, trigger) {
        if (saving) return;
        pendingForm?.abort();
        const controller = new AbortController();
        const previousForm = content.querySelector('[data-ae-editor]');
        pendingForm = controller;
        openDialog(label, trigger);
        if (previousForm) previousForm.inert = true;
        showStatus(dialog.dataset.loadingText, true);
        dialog.scrollTop = 0;

        try {
            const endpoint = new URL(url, location.href);
            if (endpoint.origin !== location.origin) throw new Error();
            const response = await fetch(endpoint, { headers: ajaxHeader, signal: controller.signal });
            if (!response.ok) throw new Error();
            const html = new DOMParser().parseFromString(await response.text(), 'text/html');
            const form = html.querySelector('form[data-ae-editor]');
            if (controller.signal.aborted) return;
            if (!form) throw new Error();

            title.textContent = form.dataset.aeTitle;
            content.replaceChildren(form);
            showStatus();
            initializeEditor(form);
            form.elements.name.focus();
        } catch (_) {
            if (!controller.signal.aborted) {
                showStatus(dialog.dataset.loadFailed, false, url);
                retry?.focus();
            }
        } finally {
            if (pendingForm === controller) {
                pendingForm = null;
                if (previousForm?.isConnected) previousForm.inert = false;
            }
        }
    }

    function showFormErrors(form, messages) {
        const box = form.querySelector('[data-ae-form-errors]');
        const list = document.createElement('ul');
        for (const text of messages) {
            const item = document.createElement('li');
            item.textContent = text;
            list.append(item);
        }
        box.replaceChildren(list);
        box.hidden = false;
        dialog.scrollTop = 0;
        box.focus({ preventScroll: true });
    }

    dialog.addEventListener('cancel', function (event) {
        event.preventDefault();
        closeDialog();
    });

    root.addEventListener('click', async function (event) {
        const close = event.target.closest('[data-ae-close]');
        if (close) {
            closeDialog();
            return;
        }

        const details = event.target.closest('[data-ae-details]');
        if (details) {
            openDialog(details.dataset.aeTitle, details);
            content.replaceChildren(details.nextElementSibling.content.cloneNode(true));
            showStatus();
            dialog.scrollTop = 0;
            return;
        }

        const formLink = event.target.closest('[data-ae-form-url]');
        if (formLink?.dataset.aeFormUrl) {
            await loadForm(formLink.dataset.aeFormUrl, formLink.dataset.aeTitle, formLink);
            return;
        }

        const button = event.target.closest('[data-ae-copy]');
        if (!button) return;
        const feedback = button.closest('[data-ae-dialog-content]')?.querySelector('[data-ae-feedback]')
            || root.querySelector('[data-ae-feedback]');
        feedback.textContent = '';
        try {
            await copy(button.dataset.aeCopy);
            feedback.textContent = root.dataset.copied;
        } catch (_) {
            feedback.textContent = root.dataset.copyFailed;
        }
    });

    dialog.addEventListener('submit', async function (event) {
        const form = event.target;
        if (!form.matches('[data-ae-editor]')) return;
        event.preventDefault();
        if (saving || pendingForm) return;

        const data = new FormData(form);
        const submit = form.querySelector('[type=submit]');
        form.querySelector('[data-ae-form-errors]').hidden = true;
        saving = true;
        form.inert = true;
        closeButton.disabled = true;
        submit.classList.add('is-loading');
        let saved = false;
        let errors = [dialog.dataset.saveFailed];

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: data,
                headers: { ...ajaxHeader, Accept: 'application/json' }
            });
            const result = await response.json();
            saved = response.ok && result.saved === true;
            if (response.status === 422 && result.errors) {
                const validationErrors = Object.values(result.errors).flat().filter(text => typeof text === 'string');
                if (validationErrors.length) errors = validationErrors;
            }
        } catch (_) {
            // Report the failure in the current editor so the input is available for retry.
        } finally {
            saving = false;
            form.inert = false;
            closeButton.disabled = false;
            submit.classList.remove('is-loading');
        }

        if (saved) {
            closeDialog();
            window.location.reload();
        } else {
            showFormErrors(form, errors);
        }
    });

    function initializeEditor(form) {
        const rows = form.querySelector('[data-ae-rows]');
        const template = form.querySelector('[data-ae-template]');
        const rowMessage = form.querySelector('[data-ae-row-status]');
        const add = form.querySelector('[data-ae-add]');
        let nextIndex = rows.querySelectorAll('[data-ae-row]').length;

        function renderPreview(row) {
            const preview = row.querySelector('[data-ae-preview]');
            if (!preview) return;
            const segments = Array.from(row.querySelectorAll('[data-ae-part]'), input => input.value.trim());
            preview.textContent = segments.every(Boolean) ? form.dataset.prefix + segments.join('-') : form.dataset.previewEmpty;
        }

        function updateRows() {
            const current = rows.querySelectorAll('[data-ae-row]');
            rows.querySelectorAll('[data-ae-remove]').forEach(button => { button.disabled = current.length === 1; });
            add.disabled = current.length >= 50;
            rowMessage.textContent = add.disabled ? form.dataset.maxRows : '';
        }

        rows.addEventListener('input', function (event) {
            const row = event.target.closest('[data-ae-row]');
            if (row) renderPreview(row);
        });
        rows.addEventListener('click', function (event) {
            const button = event.target.closest('[data-ae-remove]');
            if (!button) return;
            button.closest('[data-ae-row]').remove();
            updateRows();
            add.focus();
        });
        add.addEventListener('click', function () {
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-ae-row]');
            const previousPinyin = Array.from(rows.querySelectorAll('[data-ae-part="pinyin"]')).find(input => input.value.trim());
            row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace('__index__', nextIndex); });
            if (previousPinyin) row.querySelector('[data-ae-part="pinyin"]').value = previousPinyin.value;
            nextIndex++;
            rows.insertBefore(fragment, template);
            renderPreview(row);
            updateRows();
            row.querySelector(previousPinyin ? '[data-ae-part="attribute"]' : '[data-ae-part="pinyin"]').focus();
        });
        rows.querySelectorAll('[data-ae-row]').forEach(renderPreview);
        updateRows();

        const lookup = form.querySelector('[data-ae-lookup]');
        const supplier = form.elements.supplier_url;
        const lookupStatus = form.querySelector('[data-ae-lookup-status]');
        const existing = form.querySelector('[data-ae-existing]');
        let activeLookup;

        supplier.addEventListener('input', function () {
            activeLookup?.abort();
            delete existing.dataset.aeFormUrl;
            existing.hidden = true;
            lookupStatus.textContent = '';
            lookup.disabled = false;
        });
        lookup.addEventListener('click', async function () {
            if (!supplier.reportValidity()) return;
            activeLookup?.abort();
            const controller = new AbortController();
            activeLookup = controller;
            const url = new URL(form.dataset.lookupUrl, location.href);
            url.searchParams.set('supplier_url', supplier.value.trim());

            lookup.disabled = true;
            existing.hidden = true;
            lookupStatus.textContent = form.dataset.lookupChecking;

            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });
                const result = await response.json();
                if (controller.signal.aborted) return;
                if (!response.ok) {
                    lookupStatus.textContent = result.errors?.supplier_url?.[0] || form.dataset.lookupFailed;
                } else if (!result.existing) {
                    lookupStatus.textContent = form.dataset.lookupClear.replace(':id', () => result.product_id);
                } else if (String(result.existing.id) === form.dataset.productId) {
                    lookupStatus.textContent = form.dataset.lookupCurrent;
                } else {
                    lookupStatus.textContent = form.dataset.lookupFound.replace(':name', () => result.existing.name);
                    existing.dataset.aeFormUrl = result.existing.edit_url;
                    existing.hidden = false;
                }
            } catch (_) {
                if (!controller.signal.aborted) lookupStatus.textContent = form.dataset.lookupFailed;
            } finally {
                if (activeLookup === controller) lookup.disabled = false;
            }
        });
    }
})();
