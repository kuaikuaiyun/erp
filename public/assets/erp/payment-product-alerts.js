(function () {
    'use strict';

    const list = document.querySelector('[data-alert-list]');
    if (!list) return;

    const checks = Array.from(list.querySelectorAll('[data-alert-check-row]'));
    const checkPage = list.querySelector('[data-alert-check-page]');
    const clear = list.querySelector('[data-alert-clear-selection]');
    const counter = list.querySelector('[data-alert-selected-count]');
    const storageKey = list.dataset.alertSelectionKey;
    let selected = new Set();

    function renderSelection() {
        let pageCount = 0;
        for (const check of checks) {
            check.disabled = false;
            check.checked = selected.has(check.value);
            check.closest('tr').dataset.selected = check.checked ? '1' : '0';
            if (check.checked) pageCount++;
        }
        checkPage.disabled = checks.length === 0;
        checkPage.checked = pageCount > 0 && pageCount === checks.length;
        checkPage.indeterminate = pageCount > 0 && pageCount < checks.length;
        clear.disabled = selected.size === 0;
        counter.textContent = list.dataset.alertSelectionLabel.replace(':count', selected.size);
    }

    function restoreSelection() {
        try {
            const saved = JSON.parse(window.sessionStorage.getItem(storageKey) || '[]');
            if (Array.isArray(saved)) selected = new Set(saved.filter(value => typeof value === 'string'));
        } catch (_) {
            // The current page remains selectable if browser storage is unavailable.
        }
        renderSelection();
    }

    function saveSelection() {
        try {
            if (selected.size) window.sessionStorage.setItem(storageKey, JSON.stringify(Array.from(selected)));
            else window.sessionStorage.removeItem(storageKey);
        } catch (_) {
            // Keep the current page interactive when browser storage is restricted.
        }
        renderSelection();
    }

    function setSelected(id, checked) {
        if (checked) selected.add(id);
        else selected.delete(id);
    }

    list.querySelector('.payment-alert-table').addEventListener('keydown', event => {
        if (event.target.closest('tr[data-payment-alert-detail]') && !event.target.matches('tr')) event.stopPropagation();
    });

    for (const check of checks) {
        check.closest('td').addEventListener('click', event => event.stopPropagation());
        check.addEventListener('change', () => {
            setSelected(check.value, check.checked);
            saveSelection();
        });
    }

    checkPage.addEventListener('change', () => {
        for (const check of checks) setSelected(check.value, checkPage.checked);
        saveSelection();
    });

    function clearSelection() {
        selected.clear();
        saveSelection();
    }
    clear.addEventListener('click', clearSelection);
    list.querySelector('[data-alert-reset]').addEventListener('click', clearSelection);

    const pageSize = list.querySelector('[data-alert-page-size]');
    const pageNumber = pageSize.form.elements.namedItem('page');
    pageNumber.addEventListener('invalid', () => pageNumber.setCustomValidity(pageNumber.dataset.alertInvalidPage));
    pageNumber.addEventListener('input', () => pageNumber.setCustomValidity(''));
    pageSize.addEventListener('change', () => {
        pageNumber.setCustomValidity('');
        pageNumber.value = '1';
        pageSize.form.requestSubmit();
    });

    window.addEventListener('pageshow', restoreSelection);
    restoreSelection();
}());
