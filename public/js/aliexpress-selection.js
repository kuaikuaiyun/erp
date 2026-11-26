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

    root.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-ae-copy]');
        if (!button) return;
        const feedback = root.querySelector('[data-ae-feedback]');
        feedback.textContent = '';
        try {
            await copy(button.dataset.aeCopy);
            feedback.textContent = root.dataset.copied;
        } catch (_) {
            feedback.textContent = root.dataset.copyFailed;
        }
    });

    const form = root.querySelector('[data-ae-editor]');
    if (!form) return;

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
                existing.href = result.existing.edit_url;
                existing.hidden = false;
            }
        } catch (_) {
            if (!controller.signal.aborted) lookupStatus.textContent = form.dataset.lookupFailed;
        } finally {
            if (activeLookup === controller) lookup.disabled = false;
        }
    });
})();
