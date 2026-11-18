(function () {
    'use strict';
    var modal = document.getElementById('payment-renewal-detail-modal');
    if (!modal || !window.jQuery) return;
    var content = modal.querySelector('[data-renewal-detail-content]');
    var feedback = modal.querySelector('[data-renewal-detail-feedback]');
    var title = modal.querySelector('.modal-title');
    var messages = JSON.parse(modal.dataset.messages);
    var parent = JSON.parse(modal.dataset.parentFilters);
    var base = null, current = {}, controller = null, sequence = 0, returnFocus = null;

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
        }).catch(function (error) {
            if (error.name === 'AbortError' || request !== sequence) return;
            showError(error.message || messages.error);
        }).finally(function () {
            if (request !== sequence) return;
            content.removeAttribute('aria-busy');
            content.inert = false;
        });
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
