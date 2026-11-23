(function () {
    'use strict';
    var page = document.querySelector('.credit-card-analysis-page');
    if (!page) return;
    var filter = page.querySelector('.ca-filter-form');
    var keyword = page.querySelector('#ca-filter-keyword');
    var clear = page.querySelector('.ca-clear-input');
    function refreshClear() { if (clear) clear.classList.toggle('is-visible', !!(keyword && keyword.value)); }
    if (keyword && clear) {
        keyword.addEventListener('input', refreshClear);
        clear.addEventListener('click', function () { keyword.value = ''; refreshClear(); keyword.focus(); });
        refreshClear();
    }
    function validateRange(range) {
        var inputs = range.querySelectorAll('input');
        var from = inputs[0], to = inputs[1];
        var invalid = from.value && to.value && (from.type === 'number' ? Number(from.value) > Number(to.value) : from.value > to.value);
        to.setCustomValidity(invalid ? page.dataset.invalidRange : '');
    }
    if (filter) {
        filter.querySelectorAll('[data-ca-range]').forEach(function (range) {
            range.addEventListener('input', function () { validateRange(range); });
        });
        filter.addEventListener('submit', function (event) {
            filter.querySelectorAll('[data-ca-range]').forEach(validateRange);
            if (!filter.checkValidity()) { event.preventDefault(); filter.reportValidity(); }
        });
    }
    function copyText(value) {
        if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(value);
        return new Promise(function (resolve, reject) {
            var helper = document.createElement('textarea');
            helper.value = value; helper.style.position = 'fixed'; helper.style.opacity = '0';
            document.body.appendChild(helper); helper.select();
            try { document.execCommand('copy') ? resolve() : reject(); } catch (error) { reject(error); }
            document.body.removeChild(helper);
        });
    }
    page.addEventListener('click', function (event) {
        var button = event.target.closest('.ca-copy-button');
        if (!button) return;
        copyText(button.dataset.copyValue || '').then(function () {
            button.title = page.dataset.copied;
            var icon = button.querySelector('.fa');
            if (icon) { icon.classList.remove('fa-copy'); icon.classList.add('fa-check'); }
            window.setTimeout(function () {
                button.title = button.getAttribute('aria-label');
                if (icon) { icon.classList.remove('fa-check'); icon.classList.add('fa-copy'); }
            }, 1200);
        }).catch(function () { button.title = page.dataset.copyFailed; });
    });
    var exportForm = page.querySelector('#ca-export-form');
    var all = page.querySelector('#ca-check-all');
    var selectedCount = page.querySelector('#ca-selected-count');
    var hiddenInputs = page.querySelector('#ca-selected-inputs');
    function checks() { return Array.prototype.slice.call(page.querySelectorAll('.ca-record-check')); }
    function selected() { return checks().filter(function (item) { return item.checked; }); }
    function refreshSelection() {
        var count = selected().length;
        selectedCount.textContent = page.dataset.selectedTemplate.replace(':count', count);
        all.checked = checks().length > 0 && count === checks().length;
        all.indeterminate = count > 0 && count < checks().length;
        var choice = exportForm.querySelector('[name="scope"][value="selected"]');
        choice.disabled = count === 0;
        if (!count && choice.checked) exportForm.querySelector('[name="scope"][value="filtered"]').checked = true;
        page.querySelector('#ca-export-error').textContent = '';
    }
    if (exportForm && all && selectedCount && hiddenInputs) {
        all.addEventListener('change', function () { checks().forEach(function (item) { item.checked = all.checked; }); refreshSelection(); });
        page.addEventListener('change', function (event) { if (event.target.classList.contains('ca-record-check')) refreshSelection(); });
        exportForm.addEventListener('submit', function (event) {
            hiddenInputs.innerHTML = '';
            if (exportForm.querySelector('[name="scope"]:checked').value !== 'selected') return;
            if (!selected().length) { event.preventDefault(); page.querySelector('#ca-export-error').textContent = page.dataset.selectRequired; return; }
            selected().forEach(function (item) {
                var input = document.createElement('input'); input.type = 'hidden'; input.name = 'selected_ids[]'; input.value = item.value; hiddenInputs.appendChild(input);
            });
        });
        refreshSelection();
    }
    page.querySelectorAll('.ca-handle-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) { event.preventDefault(); form.reportValidity(); return; }
            if (form.dataset.submitting === '1') { event.preventDefault(); return; }
            form.dataset.submitting = '1'; form.querySelector('[type="submit"]').disabled = true;
        });
    });
    window.addEventListener('pageshow', function () {
        page.querySelectorAll('.ca-handle-form').forEach(function (form) { delete form.dataset.submitting; form.querySelector('[type="submit"]').disabled = false; });
    });
    if (window.jQuery) {
        window.jQuery('#ca-export-modal').on('show.bs.modal', refreshSelection);
        if (page.dataset.exportError === '1') window.jQuery('#ca-export-modal').modal('show');
        var id = page.dataset.handleId;
        if (/^\d+$/.test(id || '') && document.getElementById('ca-handle-' + id)) window.jQuery('#ca-handle-' + id).modal('show');
    }
}());
