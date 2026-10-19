(function (window, document) {
    'use strict';

    var config = window.ErpAikaipinProductLibraryConfig || {};
    var form = document.querySelector('[data-aikaipin-filter-form]');
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-aikaipin-product]'));
    var emptyRow = document.querySelector('[data-aikaipin-empty-row]');
    var count = document.querySelector('[data-aikaipin-record-count]');
    var selectAll = document.querySelector('[data-aikaipin-select-all]');
    var selectedCount = document.querySelector('[data-aikaipin-selected-count]');
    var batchButtons = Array.prototype.slice.call(document.querySelectorAll('[data-aikaipin-batch-action]'));
    var visibilityModal = document.getElementById('aikaipin-visibility-modal');
    var visibilityTitle = document.querySelector('[data-aikaipin-visibility-title]');
    var visibilityDescription = document.querySelector('[data-aikaipin-visibility-description]');
    var visibilityConfirm = document.querySelector('[data-aikaipin-visibility-confirm]');
    var filters = {
        keyword: document.querySelector('[data-aikaipin-filter="keyword"]'),
        category: document.querySelector('[data-aikaipin-filter="category"]'),
        source: document.querySelector('[data-aikaipin-filter="source"]'),
        type: document.querySelector('[data-aikaipin-filter="type"]')
    };

    function notify(type, title, message) {
        if (window.ErpAdminNotifier && typeof window.ErpAdminNotifier.notify === 'function') {
            window.ErpAdminNotifier.notify({
                type: type,
                title: title,
                message: message
            });
        }
    }

    function filterValue(name) {
        return filters[name] ? String(filters[name].value || '').toLowerCase() : '';
    }

    function applyFilters() {
        var keyword = filterValue('keyword');
        var category = filterValue('category');
        var source = filterValue('source');
        var type = filterValue('type');
        var visible = 0;

        rows.forEach(function (row) {
            var matches = (!keyword || String(row.getAttribute('data-keyword') || '').indexOf(keyword) !== -1)
                && (!category || row.getAttribute('data-category') === category)
                && (!source || row.getAttribute('data-source') === source)
                && (!type || row.getAttribute('data-type') === type);

            row.hidden = !matches;
            if (matches) {
                visible += 1;
            }
        });

        if (emptyRow) {
            emptyRow.hidden = visible !== 0;
        }

        if (count) {
            count.textContent = visible + ' / ' + rows.length;
        }

        refreshSelection();
    }

    function clearFilters() {
        Object.keys(filters).forEach(function (name) {
            if (filters[name]) {
                filters[name].value = '';
            }
        });

        applyFilters();
    }

    function selectionBoxes() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-aikaipin-product-select]'));
    }

    function visibleSelectionBoxes() {
        return selectionBoxes().filter(function (box) {
            return !box.closest('[data-aikaipin-product]').hidden;
        });
    }

    function selectedSelectionBoxes() {
        return selectionBoxes().filter(function (box) {
            return box.checked;
        });
    }

    function replaceCount(template, value) {
        return String(template || '').replace(':count', String(value));
    }

    function refreshSelection() {
        var visibleBoxes = visibleSelectionBoxes();
        var selectedBoxes = selectedSelectionBoxes();
        var selectedTotal = selectedBoxes.length;

        if (selectAll) {
            selectAll.checked = visibleBoxes.length > 0 && visibleBoxes.every(function (box) {
                return box.checked;
            });
            selectAll.indeterminate = !selectAll.checked && visibleBoxes.some(function (box) {
                return box.checked;
            });
        }

        batchButtons.forEach(function (button) {
            button.disabled = selectedTotal === 0;
        });

        if (selectedCount) {
            selectedCount.textContent = selectedTotal > 0
                ? replaceCount(config.selectedCount, selectedTotal)
                : config.selectedNone;
        }
    }

    function openVisibilityModal(mode) {
        var selectedTotal = selectedSelectionBoxes().length;
        if (selectedTotal === 0) {
            notify('warning', config.selectionRequiredTitle, config.selectionRequiredMessage);
            return;
        }

        if (visibilityTitle) {
            visibilityTitle.textContent = mode === 'enable'
                ? config.enableVisibilityTitle
                : config.cancelVisibilityTitle;
        }
        if (visibilityDescription) {
            visibilityDescription.textContent = replaceCount(
                mode === 'enable' ? config.enableVisibilityDescription : config.cancelVisibilityDescription,
                selectedTotal
            );
        }
        if (visibilityConfirm) {
            visibilityConfirm.textContent = mode === 'enable'
                ? config.enableVisibilityConfirm
                : config.cancelVisibilityConfirm;
            visibilityConfirm.className = mode === 'enable'
                ? 'btn btn-primary'
                : 'btn btn-warning';
        }

        if (visibilityModal && window.jQuery) {
            window.jQuery(visibilityModal).modal('show');
        }
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            applyFilters();
        });

        form.addEventListener('reset', function () {
            window.setTimeout(clearFilters, 0);
        });
    }

    Object.keys(filters).forEach(function (name) {
        if (filters[name]) {
            filters[name].addEventListener('change', applyFilters);
            filters[name].addEventListener('input', applyFilters);
        }
    });

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            visibleSelectionBoxes().forEach(function (box) {
                box.checked = selectAll.checked;
            });
            refreshSelection();
        });
    }

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-aikaipin-product-select]')) {
            refreshSelection();
        }
    });

    document.addEventListener('click', function (event) {
        var clearButton = event.target.closest('[data-aikaipin-clear-filters]');
        if (clearButton) {
            clearFilters();
            return;
        }

        var batchButton = event.target.closest('[data-aikaipin-batch-action]');
        if (batchButton) {
            openVisibilityModal(batchButton.getAttribute('data-aikaipin-batch-action'));
            return;
        }

        var confirmButton = event.target.closest('[data-aikaipin-visibility-confirm]');
        if (confirmButton) {
            if (visibilityModal && window.jQuery) {
                window.jQuery(visibilityModal).modal('hide');
            }
            notify('info', config.demoTitle, replaceCount(config.demoMessage, selectedSelectionBoxes().length));
            return;
        }

        var actionButton = event.target.closest('[data-aikaipin-action]');
        if (actionButton) {
            notify('info', config.comingSoonTitle, config.comingSoonMessage);
        }
    });

    refreshSelection();
}(window, document));
