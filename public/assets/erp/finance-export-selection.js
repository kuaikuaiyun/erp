(function () {
    'use strict';
    function rows() { return Array.from(document.querySelectorAll('[data-export-row]')); }
    function refresh() {
        var count = rows().filter(function (row) { return row.checked; }).length;
        document.querySelectorAll('[data-export-selected]').forEach(function (button) {
            button.disabled = !count;
            button.textContent = count ? '导出所选（' + count + '）' : '导出所选';
        });
        document.querySelectorAll('[data-export-all]').forEach(function (all) {
            var checks = Array.from(all.closest('table').querySelectorAll('[data-export-row]'));
            var selected = checks.filter(function (row) { return row.checked; }).length;
            all.checked = checks.length > 0 && selected === checks.length;
            all.indeterminate = selected > 0 && selected < checks.length;
            all.disabled = !checks.length;
        });
    }
    document.querySelectorAll('[data-finance-download], [data-finance-select-export]').forEach(function (source) {
        var selected = document.createElement('button');
        selected.type = 'button';
        selected.className = 'btn btn-default';
        selected.dataset.exportSelected = '';
        selected.disabled = true;
        selected.textContent = '导出所选';
        source.after(selected);
        selected.addEventListener('click', function () {
            var checked = rows().filter(function (row) { return row.checked; });
            if (!checked.length) return;
            var url;
            if (source.form) {
                if (!source.form.reportValidity()) return;
                url = new URL(source.form.action || window.location.href, window.location.href);
                url.search = new URLSearchParams(new FormData(source.form)).toString();
                url.searchParams.set('download', '1');
                source.form.dispatchEvent(new CustomEvent('finance:prepare-export', {detail: url.searchParams}));
            } else url = new URL(source.href, window.location.href);
            url.searchParams.delete('page');
            if (source.hasAttribute('data-selection-records')) url.searchParams.set('export_scope', 'records');
            checked.forEach(function (row) {
                url.searchParams.append(source.hasAttribute('data-selection-records') ? 'selected_ids[]' : 'selected_rows[]', row.value);
                if (source.form?.dataset.yeExport === '1' && row.dataset.recordId) {
                    url.searchParams.append('export_record_ids[]', row.dataset.recordId);
                }
            });
            window.location.assign(url.toString());
        });
    });
    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-export-all]')) {
            event.target.closest('table').querySelectorAll('[data-export-row]').forEach(function (row) { row.checked = event.target.checked; });
        }
        if (event.target.matches('[data-export-all], [data-export-row]')) refresh();
    });
    var browser = document.querySelector('[data-loss-browser]');
    if (browser) new MutationObserver(refresh).observe(browser.querySelector('.loss-comparison'), {childList: true});
    refresh();
}());
