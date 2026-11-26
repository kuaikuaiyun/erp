(() => {
    'use strict';

    const root = document.querySelector('[data-bg-performance]');
    if (!root) return;

    const filter = root.querySelector('[data-performance-filter]');

    root.addEventListener('change', (event) => {
        if (event.target.matches('[data-performance-page-size]')) event.target.form.requestSubmit();
    });

    function selectDateRange(shortcut) {
        const zone = filter.elements.timezone.value;
        const [hours, minutes] = zone.slice(1).split(':').map(Number);
        const offset = (hours * 60 + minutes) * (zone.startsWith('-') ? -1 : 1);
        const end = new Date(Date.now() + offset * 60000);
        const start = new Date(end);
        if (shortcut === 'yesterday') {
            start.setUTCDate(start.getUTCDate() - 1);
            end.setUTCDate(end.getUTCDate() - 1);
        } else if (shortcut === 'last_7_days') {
            start.setUTCDate(start.getUTCDate() - 6);
        } else if (shortcut === 'this_month') {
            start.setUTCDate(1);
            end.setUTCMonth(end.getUTCMonth() + 1, 0);
        }
        filter.elements.date_from.value = start.toISOString().slice(0, 10);
        filter.elements.date_to.value = end.toISOString().slice(0, 10);
    }

    filter.addEventListener('click', (event) => {
        const button = event.target.closest('[data-performance-date]');
        if (button) selectDateRange(button.dataset.performanceDate);
    });

    const $ = window.jQuery;
    if (!$?.fn.modal) return;

    const modal = root.querySelector('[data-performance-orders]');
    const $modal = $(modal);
    const find = (name) => modal.querySelector('[data-' + name + ']');
    const text = JSON.parse(modal.dataset.texts);
    const body = find('performance-modal-body');
    const loading = find('orders-loading');
    const error = find('orders-error');
    const loaded = find('orders-loaded');
    const orders = find('order-rows');
    const previous = find('order-previous');
    const next = find('order-next');
    const jump = find('order-jump');
    const jumpInput = jump.querySelector('input');
    let trigger = null, request = null, requestedPage = 1, page = 1, lastPage = 1;

    const amounts = new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const amount = (value, currency = 'USD') => currency + ' ' + amounts.format(Number(value) || 0);

    function append(parent, tag, value, className) {
        const node = document.createElement(tag);
        if (value != null) node.textContent = value;
        if (className) node.className = className;
        parent.append(node);
        return node;
    }

    function renderOrder(order) {
        const row = append(orders, 'tr');
        append(append(row, 'td'), 'strong', order.order_number || '—');
        append(row, 'td', order.order_created_at || '—');
        append(row, 'td', order.paid_at || '—');
        const customer = append(row, 'td', order.customer_name || '—');
        append(customer, 'small', order.customer_email || '—');
        const products = append(row, 'td', null, 'bg-performance-products');
        for (const detail of order.items || []) {
            const item = append(products, 'div', null, 'bg-performance-product');
            append(item, 'strong', detail.product_name || '—');
            append(item, 'small', [
                text.sku + ' · ' + (detail.sku || '—'),
                text.quantity + ' · ' + (detail.quantity ?? '—'),
                text.subtotal + ' · ' + amount(detail.total, order.currency),
            ].join('　'));
        }
        if (!products.hasChildNodes()) products.textContent = '—';
        append(row, 'td', order.payment_method || '—');
        append(row, 'td', order.status || '—');
        append(row, 'td', amount(order.total_amount, order.currency), 'bg-performance-money');
    }

    function showState(state) {
        loading.hidden = state !== 'loading';
        error.hidden = state !== 'error';
        loaded.hidden = state !== 'loaded';
        body.setAttribute('aria-busy', String(state === 'loading'));
        body.scrollTop = 0;
    }

    async function load(targetPage) {
        request?.abort();
        const controller = new AbortController();
        request = controller;
        requestedPage = targetPage;
        showState('loading');
        const url = new URL(modal.dataset.url, location.href);
        url.searchParams.set('date', trigger.dataset.orderDate);
        url.searchParams.set('store_id', trigger.dataset.storeId);
        url.searchParams.set('timezone', modal.dataset.timezone);
        url.searchParams.set('page', targetPage);

        try {
            const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('order-response');
            const data = await response.json();
            if (controller.signal.aborted) return;
            if (!Array.isArray(data.orders) || !data.pagination) throw new Error('order-response');

            find('order-total').textContent = data.count + ' ' + text.order_unit;
            find('order-amount').textContent = amount(data.amount);
            orders.replaceChildren();
            data.orders.forEach(renderOrder);
            const hasOrders = data.orders.length > 0;
            find('order-table').hidden = !hasOrders;
            find('orders-empty').hidden = hasOrders;
            page = Number(data.pagination.current_page);
            lastPage = Number(data.pagination.last_page);
            const pageLabel = find('order-page-label');
            pageLabel.textContent = pageLabel.dataset.format.replace('__page__', page).replace('__pages__', lastPage);
            previous.disabled = page <= 1;
            next.disabled = page >= lastPage;
            jumpInput.max = lastPage;
            jumpInput.value = '';
            showState('loaded');
        } catch (_) {
            if (!controller.signal.aborted) showState('error');
        } finally {
            if (request === controller) request = null;
        }
    }

    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-order-date]');
        if (!button) return;
        trigger = button;
        modal.querySelector('h2').textContent = text.orders_title
            .replace(':date', button.dataset.orderDate).replace(':store', () => button.dataset.storeName);
        $modal.modal('show');
        load(1);
    });
    previous.addEventListener('click', () => load(page - 1));
    next.addEventListener('click', () => load(page + 1));
    jump.addEventListener('submit', (event) => {
        event.preventDefault();
        const targetPage = Number(jumpInput.value);
        if (Number.isInteger(targetPage) && targetPage >= 1 && targetPage <= lastPage) load(targetPage);
    });
    find('orders-retry').addEventListener('click', () => load(requestedPage));
    $modal.on('hide.bs.modal', () => request?.abort());
    $modal.on('hidden.bs.modal', () => trigger?.focus());
})();
