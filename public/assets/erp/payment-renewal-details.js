(function () {
    'use strict';
    var page = document.querySelector('.payment-renewal-page');
    var pageButtons = Array.from(document.querySelectorAll('[data-renewal-export-url]'));
    var pageFeedback = page ? page.querySelector('[data-renewal-export-feedback]') : null;
    var pageMessages = page ? JSON.parse(page.dataset.renewalExportMessages || '{}') : {};
    var pageExporting = false;
    var savedButtons = new WeakMap();

    function busyButtons(buttons, busy, label) {
        buttons.filter(Boolean).forEach(function (button) {
            if (busy) {
                if (!savedButtons.has(button)) savedButtons.set(button, button.innerHTML);
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.replaceChildren();
                var icon = document.createElement('i');
                icon.className = 'fa fa-spinner fa-spin';
                icon.setAttribute('aria-hidden', 'true');
                button.append(icon, document.createTextNode(' ' + label));
            } else {
                button.disabled = false;
                button.removeAttribute('aria-busy');
                if (savedButtons.has(button)) {
                    button.innerHTML = savedButtons.get(button);
                    savedButtons.delete(button);
                }
            }
        });
    }

    async function downloadWorkbook(url, config) {
        var response;
        try {
            response = await fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
        } catch (networkError) {
            throw new Error(config.error);
        }
        if (!response.ok || response.redirected) {
            var problem = await response.json().catch(function () { return {}; });
            var errors = problem.errors ? Object.values(problem.errors).flat() : [];
            throw new Error(errors.length ? errors.join('；') : config.error);
        }
        if (!(response.headers.get('Content-Type') || '').includes('spreadsheetml')) throw new Error(config.error);
        var blob;
        try { blob = await response.blob(); } catch (downloadError) { throw new Error(config.error); }
        var disposition = response.headers.get('Content-Disposition') || '';
        var encoded = /filename\*=utf-8''([^;]+)/i.exec(disposition);
        var plain = /filename="?([^";]+)"?/i.exec(disposition);
        var filename = config.filename;
        if (encoded) {
            try { filename = decodeURIComponent(encoded[1]); } catch (ignore) {}
        } else if (plain) filename = plain[1];
        var objectUrl = URL.createObjectURL(blob);
        var link = document.createElement('a');
        try {
            link.href = objectUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
        } finally {
            link.remove();
            setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 1000);
        }
    }

    pageButtons.forEach(function (button) {
        button.addEventListener('click', async function () {
            if (pageExporting || exporting) return;
            pageExporting = true;
            busyButtons(pageButtons.concat(content ? [content.querySelector('[data-renewal-detail-export]')] : []), true, pageMessages.exporting);
            if (pageFeedback) {
                pageFeedback.hidden = false;
                pageFeedback.className = 'text-muted';
                pageFeedback.textContent = pageMessages.exporting;
            }
            try {
                await downloadWorkbook(button.dataset.renewalExportUrl, pageMessages);
                if (pageFeedback) {
                    pageFeedback.className = 'text-success';
                    pageFeedback.textContent = pageMessages.done;
                }
            } catch (error) {
                if (pageFeedback) {
                    pageFeedback.className = 'text-danger';
                    pageFeedback.textContent = error.message || pageMessages.error;
                }
            } finally {
                pageExporting = false;
                busyButtons(pageButtons.concat(content ? [content.querySelector('[data-renewal-detail-export]')] : []), false);
            }
        });
    });

    var modal = document.getElementById('payment-renewal-detail-modal');
    if (!modal || !window.jQuery) return;
    var content = modal.querySelector('[data-renewal-detail-content]');
    var feedback = modal.querySelector('[data-renewal-detail-feedback]');
    var title = modal.querySelector('.modal-title');
    var messages = JSON.parse(modal.dataset.messages);
    var parent = JSON.parse(modal.dataset.parentFilters);
    var base = null, current = {}, controller = null, sequence = 0, returnFocus = null, exporting = false;

    function filtersFromForm() {
        var form = content.querySelector('[data-renewal-detail-filter]');
        if (!form) return Object.assign({}, current);
        var values = Object.fromEntries(new FormData(form).entries());
        var size = content.querySelector('[data-renewal-detail-per-page]');
        values.detail_per_page = size ? size.value : '20';
        return values;
    }

    function showError(message) {
        feedback.classList.add('payment-renewal-detail-error');
        feedback.textContent = message;
        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn-default btn-sm';
        retry.textContent = messages.retry;
        retry.addEventListener('click', function () { load(current); });
        feedback.appendChild(retry);
    }

    function load(values) {
        if (!base) return;
        if (controller) controller.abort();
        controller = new AbortController();
        var request = ++sequence;
        current = Object.assign({}, values);
        var params = new URLSearchParams(Object.assign({}, parent, base, current));
        feedback.classList.remove('payment-renewal-detail-error');
        feedback.textContent = messages.loading;
        content.setAttribute('aria-busy', 'true');
        content.inert = true;
        fetch(modal.dataset.endpoint + '?' + params.toString(), {
            credentials: 'same-origin',
            signal: controller.signal,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(async function (response) {
            if (response.redirected) throw new Error(messages.error);
            if (!response.ok) {
                var problem = await response.json().catch(function () { return {}; });
                var errors = problem.errors ? Object.values(problem.errors).flat() : [];
                throw new Error(errors.length ? errors.join('；') : messages.error);
            }
            var html = await response.text();
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var fragment = doc.querySelector('[data-renewal-detail-fragment]');
            if (!fragment) throw new Error(messages.error);
            if (request !== sequence) return;
            content.replaceChildren(document.importNode(fragment, true));
            feedback.textContent = '';
            if (exporting || pageExporting) busyButtons([content.querySelector('[data-renewal-detail-export]')], true, pageMessages.exporting || messages.exporting);
        }).catch(function (error) {
            if (error.name === 'AbortError' || request !== sequence) return;
            showError(error.message || messages.error);
        }).finally(function () {
            if (request !== sequence) return;
            content.removeAttribute('aria-busy');
            content.inert = false;
        });
    }

    async function exportDetails(button) {
        if (!base || exporting || pageExporting) return;
        var form = content.querySelector('[data-renewal-detail-filter]');
        if (form && !form.reportValidity()) return;
        var params = new URLSearchParams(Object.assign({}, parent, base, filtersFromForm()));
        params.delete('detail_page');
        params.delete('detail_per_page');
        var request = sequence;
        exporting = true;
        busyButtons([button].concat(pageButtons), true, pageMessages.exporting || messages.exporting);
        feedback.classList.remove('payment-renewal-detail-error');
        feedback.textContent = messages.exporting;
        try {
            await downloadWorkbook(modal.dataset.exportEndpoint + '?' + params.toString(), {
                error: messages.exportError, filename: messages.exportFilename
            });
            if (request === sequence) feedback.textContent = '';
        } catch (error) {
            if (request === sequence) {
                feedback.classList.add('payment-renewal-detail-error');
                feedback.textContent = error.message || messages.exportError;
            }
        } finally {
            exporting = false;
            busyButtons([button, content.querySelector('[data-renewal-detail-export]')].concat(pageButtons), false);
        }
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-renewal-detail-open]');
        if (!trigger) return;
        returnFocus = trigger;
        title.textContent = trigger.dataset.title;
        base = { detail_group: trigger.dataset.group, detail_purchase: trigger.dataset.purchase, detail_kind: trigger.dataset.kind };
        content.replaceChildren();
        window.jQuery(modal).modal('show');
        load({ detail_per_page: '20', detail_page: '1' });
    });
    content.addEventListener('submit', function (event) {
        if (event.target.matches('[data-renewal-detail-filter]')) {
            event.preventDefault();
            load(Object.assign(filtersFromForm(), { detail_page: '1' }));
        } else if (event.target.matches('[data-renewal-detail-jump]')) {
            event.preventDefault();
            load(Object.assign(filtersFromForm(), { detail_page: event.target.querySelector('input').value }));
        }
    });
    content.addEventListener('click', function (event) {
        var exportButton = event.target.closest('[data-renewal-detail-export]');
        if (exportButton) { exportDetails(exportButton); return; }
        var page = event.target.closest('[data-renewal-detail-page]');
        if (page && !page.disabled) load(Object.assign(filtersFromForm(), { detail_page: page.dataset.renewalDetailPage }));
        if (event.target.closest('[data-renewal-detail-reset]')) load({ detail_per_page: '20', detail_page: '1' });
    });
    content.addEventListener('change', function (event) {
        if (event.target.matches('[data-renewal-detail-per-page]')) load(Object.assign(filtersFromForm(), { detail_page: '1' }));
    });
    window.jQuery(modal).on('hidden.bs.modal', function () {
        ++sequence;
        if (controller) controller.abort();
        content.inert = false;
        content.removeAttribute('aria-busy');
        if (returnFocus && returnFocus.isConnected) returnFocus.focus();
    });
}());
