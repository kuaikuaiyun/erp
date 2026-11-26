(() => {
    'use strict';

    const records = document.getElementById('baokuan-ad-records');
    if (!records) return;

    const checkPage = records.querySelector('[data-baokuan-check-page]');
    const checkboxes = [...records.querySelectorAll('[data-baokuan-check-report]')];
    const selectedText = records.querySelector('[data-baokuan-selected-count]');
    const clearSelection = records.querySelector('[data-baokuan-clear-selection]');
    const storageKey = records.dataset.selectionPortal + ':advertising-expenses:selected:' + records.dataset.selectionUser;
    let selected = new Set();

    function restoreSelection() {
        try {
            const ids = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
            selected = new Set(Array.isArray(ids) ? ids.filter(id => typeof id === 'string' && /^\d+$/.test(id)) : []);
        } catch {
            // Selection remains available for the current page if storage is unavailable.
        }
    }

    function updateSelection() {
        let onPage = 0;
        for (const input of checkboxes) {
            input.checked = selected.has(input.value);
            input.closest('tbody').classList.toggle('is-selected', input.checked);
            if (input.checked) onPage++;
        }
        checkPage.checked = checkboxes.length > 0 && onPage === checkboxes.length;
        checkPage.indeterminate = onPage > 0 && onPage < checkboxes.length;
        clearSelection.disabled = !selected.size;
        selectedText.textContent = selectedText.dataset.text.replace('__count__', String(selected.size));
    }

    function saveSelection() {
        try {
            sessionStorage.setItem(storageKey, JSON.stringify([...selected]));
        } catch {
            // The current document still has the selected IDs.
        }
        updateSelection();
    }

    records.addEventListener('change', (event) => {
        const input = event.target;
        if (input.matches('[data-baokuan-page-size]')) {
            input.form.requestSubmit();
        } else if (input === checkPage) {
            for (const checkbox of checkboxes) {
                if (input.checked) selected.add(checkbox.value);
                else selected.delete(checkbox.value);
            }
            saveSelection();
        } else if (input.matches('[data-baokuan-check-report]')) {
            if (input.checked) selected.add(input.value);
            else selected.delete(input.value);
            saveSelection();
        }
    });

    function resetSelection() {
        selected.clear();
        saveSelection();
    }

    clearSelection.addEventListener('click', resetSelection);
    document.querySelector('[data-baokuan-reset]')?.addEventListener('click', (event) => {
        if (event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) resetSelection();
    });
    records.addEventListener('submit', (event) => {
        if (!event.defaultPrevented && event.target.matches('[data-baokuan-delete]')) {
            const id = event.target.closest('tbody').querySelector('[data-baokuan-check-report]').value;
            if (selected.delete(id)) saveSelection();
        }
    });
    window.addEventListener('pageshow', () => {
        restoreSelection();
        updateSelection();
    });
    restoreSelection();
    updateSelection();
})();

(() => {
    'use strict';

    const picker = document.querySelector('[data-baokuan-submitters]');
    if (!picker) return;

    const summary = picker.querySelector('summary');
    const search = picker.querySelector('[data-baokuan-submitter-search]');
    const options = [...picker.querySelectorAll('[data-baokuan-submitter-option]')];
    const empty = picker.querySelector('[data-baokuan-submitter-empty]');
    const clear = picker.querySelector('[data-baokuan-submitter-clear]');

    function filterOptions() {
        const keyword = search.value.trim().toLocaleLowerCase();
        let visible = 0;
        for (const option of options) {
            option.hidden = !option.textContent.trim().toLocaleLowerCase().includes(keyword);
            if (!option.hidden) visible++;
        }
        empty.hidden = visible !== 0;
    }

    function updateSummary() {
        const selected = options.filter(option => option.querySelector('input').checked);
        const names = selected.map(option => option.querySelector('span').textContent.trim());
        const caption = names.length
            ? picker.dataset.selectedText.replace('__count__', String(names.length)).replace('__names__', () => names.join('、'))
            : picker.dataset.all;
        summary.textContent = caption;
        summary.title = caption;
        clear.disabled = !names.length;
    }

    search.addEventListener('input', filterOptions);
    picker.addEventListener('change', updateSummary);
    clear.addEventListener('click', () => {
        for (const option of options) option.querySelector('input').checked = false;
        search.value = '';
        filterOptions();
        updateSummary();
        search.focus();
    });
    picker.addEventListener('toggle', () => {
        if (picker.open) search.focus();
    });
    picker.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !picker.open) return;
        event.stopPropagation();
        picker.open = false;
        summary.focus();
    });
    document.addEventListener('click', (event) => {
        if (!picker.contains(event.target)) picker.open = false;
    });
    window.addEventListener('pageshow', () => {
        filterOptions();
        updateSummary();
    });
    updateSummary();
})();

(() => {
    'use strict';

    const editor = document.querySelector('.baokuan-ad-editor');
    const $ = window.jQuery;
    if (!editor || !$?.fn.modal) return;

    const modal = editor.closest('.baokuan-ad-modal');
    const $modal = $(modal);
    const openLink = document.querySelector('[data-baokuan-open-editor]');
    const focusTarget = openLink || document.querySelector('#baokuan-ad-records .flt-toolbar-actions a');
    const $childModals = $('#fashion-image-modal, #misc-fee-help-modal');

    openLink?.addEventListener('click', (event) => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        $modal.modal('show');
    });

    $modal.on('hidden.bs.modal', () => focusTarget?.focus());

    $childModals.on('show.bs.modal', function () {
        if (!$modal.hasClass('in')) return;

        const $child = $(this);
        const parentPadding = document.body.style.paddingRight;
        const previousFocus = document.activeElement;
        $child.addClass('baokuan-ad-child-modal');
        // Bootstrap appends the second backdrop after emitting show.bs.modal.
        queueMicrotask(() => $child.data('bs.modal')?.$backdrop?.addClass('baokuan-ad-child-backdrop'));
        $child.one('hidden.bs.modal', () => {
            $child.removeClass('baokuan-ad-child-modal');
            if (!$modal.hasClass('in')) return;

            document.body.classList.add('modal-open');
            document.body.style.paddingRight = parentPadding;
            $modal.data('bs.modal').enforceFocus();
            if (modal.contains(previousFocus)) previousFocus.focus();
        });
    });

    if (modal.dataset.autoOpen === '1') $modal.modal('show');

    const toolbar = editor.querySelector('.baokuan-ad-toolbar');
    const rows = editor.querySelector('#advertising-rows');
    const table = rows.closest('table');
    const labels = JSON.parse(modal.dataset.accountLabels);
    const identityFields = [
        ['agency', '.js-agency'],
        ['media', '.js-media'],
        ['account_number', '.field-number'],
        ['account_name', '.field-account'],
        ['store', '.js-applovin-store select'],
    ];
    const expenseFields = [
        ['consumption', '.js-book-spend'],
        ['coefficient', '.js-settlement-coefficient'],
        ['actual', '.js-actual-spend'],
        ['misc_fee', '.field-misc-rmb'],
        ['screenshot', '.fashion-image-open'],
        ['remark', 'textarea'],
        ['remove', '[data-remove-row]'],
    ];
    const managed = new Set();
    let labelId = 0;

    function layoutAccount(row) {
        if (!row.hasAttribute('data-store-ready') || row.classList.contains('baokuan-ad-account-row')) return;

        const cell = row.insertCell();
        cell.colSpan = table.tHead.rows[0].cells.length;
        cell.className = 'baokuan-ad-account-cell';
        const card = document.createElement('div');
        card.className = 'baokuan-ad-entry';
        cell.append(card);

        for (const [group, fields] of [['identity', identityFields], ['expenses', expenseFields]]) {
            const line = document.createElement('div');
            line.className = 'baokuan-ad-entry-' + group;
            card.append(line);

            for (const [key, selector] of fields) {
                const control = row.querySelector(selector);
                if (!control) continue;

                const source = control.closest('td');
                const field = document.createElement('div');
                const heading = document.createElement('div');
                const label = document.createElement('span');
                const contents = document.createElement('div');
                field.className = 'baokuan-ad-entry-field';
                field.dataset.field = key;
                heading.className = 'baokuan-ad-entry-label';
                label.textContent = labels[key];
                label.id = 'baokuan-ad-field-' + (++labelId);
                heading.append(label);
                field.append(heading, contents);
                line.append(field);
                control.setAttribute('aria-labelledby', label.id);

                if (key === 'store') contents.className = 'js-applovin-store';
                if (key === 'screenshot') contents.className = 'fashion-image-cell';
                if (key === 'consumption') {
                    const hint = document.createElement('span');
                    hint.className = 'fashion-spend-tip';
                    hint.textContent = labels.gross_hint;
                    heading.append(hint);
                }
                if (key === 'misc_fee') {
                    const help = table.tHead.querySelector('[data-target="#misc-fee-help-modal"]')?.cloneNode(true);
                    if (help) {
                        help.textContent = '?';
                        help.className = 'baokuan-ad-misc-help';
                        help.removeAttribute('style');
                        help.title = labels.misc_help;
                        help.setAttribute('aria-label', labels.misc_help);
                        heading.append(help);
                    }
                }

                contents.append(...source.childNodes);
                source.remove();
            }
        }
        row.classList.add('baokuan-ad-account-row');
    }

    function enhance(select) {
        if (managed.has(select)) return;

        const fieldLabel = select.closest('.baokuan-ad-entry-field')?.querySelector('.baokuan-ad-entry-label > span');
        if (fieldLabel) select.setAttribute('aria-labelledby', fieldLabel.id);
        if (!$.fn.select2) return;

        const $select = $(select);
        $select.select2({
            width: '100%',
            dropdownParent: $modal,
            dropdownCssClass: 'baokuan-ad-select-options',
            minimumResultsForSearch: select.matches('.js-media') ? Infinity : 0,
            language: { noResults: () => toolbar.dataset.noOptions },
        });

        const control = select.nextElementSibling.querySelector('.select2-selection');
        if (fieldLabel) {
            control.setAttribute('aria-labelledby', [fieldLabel.id, control.getAttribute('aria-labelledby')].filter(Boolean).join(' '));
        } else if (select.labels?.[0]) {
            control.setAttribute('aria-label', select.labels[0].textContent.trim());
        }
        $select.on('select2:open.baokuanAds', () => {
            const search = modal.querySelector('.baokuan-ad-select-options .select2-search__field');
            if (search) {
                search.placeholder = toolbar.dataset.searchLabel;
                search.setAttribute('aria-label', toolbar.dataset.searchLabel);
            }
        });
        $select.on('select2:select.baokuanAds', () => {
            // The shared form listens for native changes to update dependent fields.
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        select.addEventListener('invalid', () => {
            control.setAttribute('aria-invalid', 'true');
        });
        select.addEventListener('change', () => control.removeAttribute('aria-invalid'));
        managed.add(select);
    }

    function updateScreenshot(cell) {
        const open = cell.querySelector('.fashion-image-open');
        const summary = cell.querySelector('.fashion-image-summary');
        if (!open.classList.contains('baokuan-ad-shot-open')) {
            const upload = document.createElement('span');
            const icon = document.createElement('i');
            upload.className = 'baokuan-ad-shot-upload';
            icon.className = 'fa fa-picture-o';
            icon.setAttribute('aria-hidden', 'true');
            upload.append(icon, document.createTextNode(modal.dataset.shotUpload));
            open.replaceChildren(upload, summary);
            open.classList.add('baokuan-ad-shot-open');
            open.removeAttribute('aria-labelledby');

            let remove = cell.querySelector('.fashion-image-remove');
            if (!remove) {
                remove = document.createElement('button');
                remove.type = 'button';
                cell.append(remove);
            }
            remove.className = 'fashion-image-remove baokuan-ad-shot-remove';
            remove.textContent = '×';
            remove.title = modal.dataset.shotRemove;
            remove.setAttribute('aria-label', modal.dataset.shotRemove);
        }

        const image = summary.querySelector('img');
        const label = image ? modal.dataset.shotPreview : modal.dataset.shotUpload;
        open.setAttribute('aria-label', label);
        open.title = label;
        if (image) image.alt = '';
    }

    function refresh() {
        Array.from(rows.rows).forEach(layoutAccount);
        rows.querySelectorAll('.fashion-image-cell').forEach(updateScreenshot);
        for (const select of managed) {
            if (!editor.contains(select)) {
                $(select).off('.baokuanAds').select2('destroy');
                managed.delete(select);
            }
        }
        editor.querySelectorAll('#fashion-operator, .js-agency, .js-media, .js-applovin-store select').forEach(enhance);
    }

    refresh();
    table.classList.add('baokuan-ad-accounts-ready');
    table.setAttribute('role', 'presentation');
    new MutationObserver(refresh).observe(rows, { childList: true, subtree: true });
    $modal.on('hide.bs.modal', () => {
        for (const select of managed) $(select).select2('close');
    });
})();
