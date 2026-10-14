(function () {
    'use strict';

    var modal = document.getElementById('logistics-order-detail-modal');
    if (!modal) return;

    var loading = modal.querySelector('[data-order-detail-loading]');
    var error = modal.querySelector('[data-order-detail-error]');
    var content = modal.querySelector('[data-order-detail-content]');
    var edit = modal.querySelector('[data-order-detail-edit]');
    var requestSequence = 0;

    function setState(state, message) {
        loading.hidden = state !== 'loading';
        error.hidden = state !== 'error';
        content.hidden = state !== 'content';
        if (state === 'error') error.textContent = message || '订单详情加载失败。';
    }

    function openDetail(url) {
        if (!url) return;
        var sequence = ++requestSequence;
        content.innerHTML = '';
        edit.hidden = true;
        edit.href = '#';
        setState('loading');
        if (window.jQuery) window.jQuery(modal).modal('show');

        fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) throw new Error('订单详情请求失败（HTTP ' + response.status + '）。');
            return response.text();
        }).then(function (html) {
            if (sequence !== requestSequence) return;
            content.innerHTML = html;
            var root = content.querySelector('.logistics-order-detail');
            if (root && root.dataset.editUrl) {
                edit.href = root.dataset.editUrl;
                edit.hidden = false;
            }
            setState('content');
        }).catch(function (failure) {
            if (sequence !== requestSequence) return;
            setState('error', failure.message || '订单详情加载失败。');
        });
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('.js-logistics-order-detail[data-order-detail-url]');
        if (!trigger) return;
        event.preventDefault();
        event.stopPropagation();
        openDetail(trigger.dataset.orderDetailUrl);
    });

    if (window.jQuery) {
        window.jQuery(modal).on('shown.bs.modal', function () {
            var backdrops = document.querySelectorAll('.modal-backdrop');
            var backdrop = backdrops[backdrops.length - 1];
            if (backdrop) backdrop.classList.add('logistics-order-detail-backdrop');
        }).on('hidden.bs.modal', function () {
            requestSequence++;
            content.innerHTML = '';
            if (document.querySelector('.modal.in, .modal.show')) document.body.classList.add('modal-open');
        });
    }
}());
