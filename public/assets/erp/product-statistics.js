(function (window, document, $) {
    'use strict';

    var config = window.ErpLogisticsProductStatistics || {};
    var products = Array.isArray(config.products) ? config.products : [];
    var overallSummary = config.summary || {};
    var pagination = config.pagination || { current_page: 1, per_page: 10, total: products.length, last_page: 1, from: 0, to: products.length };
    var decisions = config.decisions || {};
    var labels = config.labels || {};
    var state = { page: Number(pagination.current_page) || 1, sort: config.sort || '', direction: config.direction || '', selected: {} };
    var detailState = { product: null, sorts: {} };
    var body = document.getElementById('product-statistics-body');
    var empty = document.getElementById('product-statistics-empty');
    var filterForm = document.getElementById('product-statistics-filter-form');
    var pageSizeInput = document.getElementById('product-statistics-page-size');
    var checkAll = document.getElementById('product-statistics-check-all');
    var exportForm = document.getElementById('product-statistics-export-form');
    var exportSelectedScope = exportForm ? exportForm.querySelector('input[name="scope"][value="selected"]') : null;
    var exportFilteredScope = exportForm ? exportForm.querySelector('input[name="scope"][value="filtered"]') : null;
    var exportSelectedCount = document.getElementById('product-statistics-export-selected-count');
    var exportFilteredCount = document.getElementById('product-statistics-export-filtered-count');
    var exportPayload = document.getElementById('product-statistics-export-payload');

    if (!body || !filterForm) return;

    function t(key) { return labels[key] || ''; }
    function blank() { return t('notAvailable') || '—'; }
    function isNumeric(value) { return value !== null && value !== '' && value !== undefined && isFinite(Number(value)); }
    function number(value) { return isNumeric(value) ? Number(value) : 0; }
    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function formatNumber(value) { return isNumeric(value) ? number(value).toLocaleString('en-US', { maximumFractionDigits: 0 }) : blank(); }
    function formatMoney(value) {
        if (!isNumeric(value)) return blank();
        var amount = number(value);
        return (amount < 0 ? '-$' : '$') + Math.abs(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function formatRate(value) { return isNumeric(value) ? number(value).toFixed(2) + '%' : blank(); }
    function decisionClass(key) { return ({ continue: 'is-continue', optimize: 'is-optimize', watch: 'is-watch', stop: 'is-stop' })[key] || 'is-watch'; }
    function decisionBadge(key) { return '<span class="product-statistics-decision ' + decisionClass(key) + '">' + escapeHtml(decisions[key] || blank()) + '</span>'; }
    function rateClass(value) {
        if (!isNumeric(value)) return 'is-watch';
        var rate = number(value);
        if (rate >= 35) return 'is-strong';
        if (rate >= 18) return 'is-healthy';
        if (rate >= 8) return 'is-watch';
        return 'is-risk';
    }
    function productFees(subject) { return number(subject.shipping) + number(subject.handling); }
    function productThumb(product) {
        var imageUrl = product.image_url ? String(product.image_url) : '';
        var image = imageUrl ? '<img src="' + escapeHtml(imageUrl) + '" alt="" loading="lazy">' : '';
        var attributes = imageUrl ? ' role="button" tabindex="0" data-image-url="' + escapeHtml(imageUrl) + '" data-image-name="' + escapeHtml(product.name || '') + '"' : '';
        return '<span class="product-statistics-thumb ' + (image ? 'has-image is-previewable' : '') + '"' + attributes + '>' + image + '<i class="fa fa-cube" aria-hidden="true"></i></span>';
    }
    function parentRow(product) {
        return '<tr class="product-statistics-product-row" data-product-id="' + escapeHtml(product.id) + '">'
            + '<td class="product-statistics-check-column"><input class="product-statistics-check" type="checkbox" value="' + escapeHtml(product.id) + '"' + (state.selected[product.id] ? ' checked' : '') + '></td>'
            + '<td><div class="product-statistics-product-cell">' + productThumb(product) + '<span><strong>' + escapeHtml(product.name) + '</strong><small>' + escapeHtml(product.category || '') + '</small></span></div></td>'
            + '<td><code>' + escapeHtml(product.sku || blank()) + '</code></td>'
            + '<td><span class="product-statistics-store-count">' + formatNumber(product.shop_count) + '</span><small class="product-statistics-store-names">' + escapeHtml((product.stores || []).join('、')) + '</small></td>'
            + '<td>' + formatNumber(product.sales) + '</td><td>' + formatMoney(product.revenue) + '</td><td>' + formatMoney(product.cost) + '</td><td>' + formatMoney(productFees(product)) + '</td>'
            + '<td>' + formatMoney(product.paypal_refund_amount) + '</td><td>' + formatRate(product.paypal_refund_rate) + '</td><td>' + formatNumber(product.paypal_dispute_count) + '</td><td>' + formatRate(product.paypal_dispute_rate) + '</td>'
            + '<td>' + formatMoney(product.credit_card_refund_amount) + '</td><td>' + formatRate(product.credit_card_refund_rate) + '</td><td>' + formatRate(product.combined_refund_rate) + '</td>'
            + '<td class="product-statistics-profit ' + (isNumeric(product.profit) && number(product.profit) < 0 ? 'is-negative' : '') + '">' + formatMoney(product.profit) + '</td>'
            + '<td><span class="product-statistics-rate ' + rateClass(product.profit_rate) + '">' + formatRate(product.profit_rate) + '</span></td>'
            + '<td>' + decisionBadge(product.decision) + '</td><td><button class="btn btn-primary btn-xs product-statistics-view" type="button" data-product-id="' + escapeHtml(product.id) + '"><i class="fa fa-eye"></i> ' + escapeHtml(t('view')) + '</button></td></tr>';
    }
    function renderSummary() {
        document.getElementById('product-statistics-summary-products').textContent = formatNumber(overallSummary.product_count);
        document.getElementById('product-statistics-summary-sales').textContent = formatNumber(overallSummary.sales);
        document.getElementById('product-statistics-summary-revenue').textContent = formatMoney(overallSummary.revenue);
        document.getElementById('product-statistics-summary-profit').textContent = formatMoney(overallSummary.profit);
        document.getElementById('product-statistics-summary-profit-rate').textContent = formatRate(overallSummary.profit_rate);
        document.getElementById('product-statistics-total-label').textContent = (t('totalProducts') || '').replace(':count', formatNumber(overallSummary.product_count));
    }
    function renderPagination() {
        var pageNumbers = document.getElementById('product-statistics-page-numbers');
        var totalPages = Number(pagination.last_page) || 1;
        var total = Number(pagination.total) || 0;
        var pages = [];
        var start = Math.max(1, state.page - 2);
        var end = Math.min(totalPages, start + 4);
        start = Math.max(1, end - 4);
        for (var page = start; page <= end; page++) {
            pages.push('<button type="button" class="btn btn-default btn-sm product-statistics-page-button ' + (page === state.page ? 'is-active' : '') + '" data-page="' + page + '">' + page + '</button>');
        }
        pageNumbers.innerHTML = pages.join('');
        document.getElementById('product-statistics-prev-page').disabled = state.page <= 1;
        document.getElementById('product-statistics-next-page').disabled = state.page >= totalPages;
        document.getElementById('product-statistics-page-summary').textContent = total === 0 ? t('pageEmpty') : (t('pageSummary') || '').replace(':from', pagination.from).replace(':to', pagination.to).replace(':total', total);
    }
    function selectedProductIds() { return Object.keys(state.selected).filter(function (id) { return state.selected[id]; }); }
    function refreshSelection() {
        var selected = products.filter(function (product) { return state.selected[product.id]; });
        if (checkAll) {
            checkAll.checked = products.length > 0 && selected.length === products.length;
            checkAll.indeterminate = selected.length > 0 && !checkAll.checked;
            checkAll.disabled = products.length === 0;
        }
        if (exportSelectedCount) exportSelectedCount.textContent = (t('selectedCount') || '').replace(':count', selected.length);
        if (exportFilteredCount) exportFilteredCount.textContent = (t('filteredCount') || '').replace(':products', Number(overallSummary.product_count) || 0).replace(':variants', 0);
        if (exportSelectedScope && exportFilteredScope) {
            exportSelectedScope.disabled = selected.length === 0;
            if (!selected.length && exportSelectedScope.checked) exportFilteredScope.checked = true;
        }
    }
    function renderSortIndicators() {
        Array.prototype.slice.call(document.querySelectorAll('.product-statistics-sort')).forEach(function (button) {
            var icon = button.querySelector('i');
            if (state.sort && button.dataset.sort === state.sort) {
                button.classList.add('is-active');
                icon.className = 'fa fa-sort-' + (state.direction === 'asc' ? 'asc' : 'desc');
            } else {
                button.classList.remove('is-active');
                icon.className = 'fa fa-sort';
            }
        });
    }
    function renderTable() {
        renderSummary();
        renderSortIndicators();
        body.innerHTML = products.map(parentRow).join('');
        empty.hidden = products.length > 0;
        document.getElementById('product-statistics-table').classList.toggle('is-empty', products.length === 0);
        renderPagination();
        refreshSelection();
    }
    function findProduct(id) { return products.filter(function (product) { return product.id === id; })[0] || null; }
    function openImagePreview(trigger) {
        if (!trigger.dataset.imageUrl) return;
        document.getElementById('product-statistics-image-preview').src = trigger.dataset.imageUrl;
        document.getElementById('product-statistics-image-preview').alt = trigger.dataset.imageName || '';
        document.getElementById('product-statistics-image-name').textContent = trigger.dataset.imageName || '';
        if ($) $('#product-statistics-image-modal').modal('show');
    }
    function detailMetric(label, value, className) { return '<div class="product-statistics-detail-metric ' + (className || '') + '"><span>' + escapeHtml(label) + '</span><strong>' + value + '</strong></div>'; }
    function negativeMoney(value) { return isNumeric(value) ? '-' + formatMoney(Math.abs(number(value))) : blank(); }
    function detailBreakdown(subject) {
        return '<div class="product-statistics-breakdown"><h5>' + escapeHtml(t('profitBreakdown')) + '</h5>'
            + '<dl><dt>' + escapeHtml(t('revenue')) + '</dt><dd>' + formatMoney(subject.revenue) + '</dd></dl>'
            + '<dl><dt>' + escapeHtml(t('cost')) + '</dt><dd>' + negativeMoney(subject.cost) + '</dd></dl>'
            + '<dl><dt>' + escapeHtml(t('fees')) + '</dt><dd>' + negativeMoney(productFees(subject)) + '</dd></dl>'
            + '<dl><dt>' + escapeHtml(t('actualRefunds')) + '</dt><dd>' + negativeMoney(subject.actual_refunds) + '</dd></dl>'
            + '<dl class="is-total"><dt>' + escapeHtml(t('actualProfit')) + '</dt><dd>' + formatMoney(subject.actual_profit) + '</dd></dl></div>';
    }
    function detailSortButton(table, key, label) {
        var current = detailState.sorts[table] || {};
        var active = current.key === key;
        var icon = active ? (current.direction === 'asc' ? 'fa-sort-asc' : 'fa-sort-desc') : 'fa-sort';
        return '<button type="button" class="product-statistics-detail-sort ' + (active ? 'is-active' : '') + '" data-detail-table="' + escapeHtml(table) + '" data-detail-sort="' + escapeHtml(key) + '">' + escapeHtml(label) + ' <i class="fa ' + icon + '"></i></button>';
    }
    function detailSortedRows(table, rows) {
        var sorting = detailState.sorts[table] || {};
        if (!sorting.key || !sorting.direction) return rows.slice();
        return rows.map(function (row, index) { return { row: row, index: index }; }).sort(function (leftEntry, rightEntry) {
            var left = leftEntry.row[sorting.key];
            var right = rightEntry.row[sorting.key];
            var leftBlank = left === null || left === undefined || left === '';
            var rightBlank = right === null || right === undefined || right === '';
            if (leftBlank !== rightBlank) return leftBlank ? 1 : -1;
            var comparison = isNumeric(left) && isNumeric(right) ? number(left) - number(right) : String(left || '').localeCompare(String(right || ''), 'zh-CN', { numeric: true, sensitivity: 'base' });
            if (comparison === 0) return leftEntry.index - rightEntry.index;
            return sorting.direction === 'asc' ? comparison : -comparison;
        }).map(function (entry) { return entry.row; });
    }
    function detailEmptyRow(columns) { return '<tr><td colspan="' + columns + '" class="text-center text-muted">' + escapeHtml(t('emptyRows') || blank()) + '</td></tr>'; }
    function orderLink(row) {
        var label = escapeHtml(row.shopify_order_number || blank());
        return row.order_id && row.detail_url ? '<button type="button" class="btn btn-link btn-xs js-logistics-order-detail" data-order-detail-url="' + escapeHtml(row.detail_url) + '">' + label + '</button>' : label;
    }
    function orderRows(table, orders) {
        var rows = detailSortedRows(table, orders);
        if (!rows.length) return detailEmptyRow(10);
        return rows.map(function (order) {
            return '<tr><td>' + orderLink(order) + '</td><td>' + escapeHtml(order.store || blank()) + '</td><td>' + escapeHtml(order.bill_number || blank()) + '</td><td>' + escapeHtml(order.payment_method || blank()) + '</td><td>' + formatNumber(order.quantity) + '</td><td>' + formatMoney(order.revenue) + '</td><td>' + formatMoney(order.paypal_refund) + '</td><td>' + formatMoney(order.credit_card_refund) + '</td><td>' + formatNumber(order.paypal_dispute_count) + '</td><td>' + escapeHtml(order.order_created_at || blank()) + '</td></tr>';
        }).join('');
    }
    function renderOrdersTable(id, table, orders) {
        var headings = [
            ['shopify_order_number', t('orderNumber')], ['store', t('storeName')], ['bill_number', t('billNumber')], ['payment_method', t('paymentMethod')], ['quantity', t('quantity')], ['revenue', t('revenue')], ['paypal_refund', t('paypalRefund')], ['credit_card_refund', t('creditCardRefund')], ['paypal_dispute_count', t('paypalDisputes')], ['order_created_at', t('orderCreatedAt')]
        ];
        document.getElementById(id).innerHTML = '<div class="table-responsive product-statistics-detail-table-wrap"><table class="table product-statistics-detail-table"><thead><tr>' + headings.map(function (heading) { return '<th>' + detailSortButton(table, heading[0], heading[1]) + '</th>'; }).join('') + '</tr></thead><tbody>' + orderRows(table, orders) + '</tbody></table></div>';
    }
    function refundRows(subject) {
        var details = detailSortedRows('refunds', subject.refund_details || []);
        if (!details.length) return detailEmptyRow(10);
        return details.map(function (refund) {
            var disputeText = (refund.linked_disputes || []).join('、') || blank();
            var order = refund.order_id && refund.order_detail_url ? '<button type="button" class="btn btn-link btn-xs js-logistics-order-detail" data-order-detail-url="' + escapeHtml(refund.order_detail_url) + '">' + escapeHtml(refund.shopify_order_number || blank()) + '</button>' : escapeHtml(refund.shopify_order_number || blank());
            return '<tr><td>' + escapeHtml(refund.payment_method_label || blank()) + '</td><td>' + order + '</td><td>' + escapeHtml(refund.bill_number || blank()) + '</td><td>' + escapeHtml(refund.refund_number || blank()) + '</td><td>' + formatMoney(refund.amount) + '</td><td title="' + escapeHtml(refund.source_reason || '') + '">' + escapeHtml(refund.source_reason || blank()) + '</td><td title="' + escapeHtml(refund.manual_reason || '') + '">' + escapeHtml(refund.manual_reason || blank()) + '</td><td>' + escapeHtml(refund.status || blank()) + '</td><td>' + escapeHtml(disputeText) + '</td><td>' + escapeHtml(refund.imported_at || blank()) + '</td></tr>';
        }).join('');
    }
    function disputeRows(subject) {
        var details = detailSortedRows('disputes', subject.paypal_dispute_details || []);
        if (!details.length) return detailEmptyRow(11);
        return details.map(function (dispute) {
            var refunds = (dispute.linked_refunds || []).map(function (refund) { return String(refund.refund_number || '') + ' (' + formatMoney(refund.amount) + ')'; }).join('、') || blank();
            var order = dispute.order_id && dispute.order_detail_url ? '<button type="button" class="btn btn-link btn-xs js-logistics-order-detail" data-order-detail-url="' + escapeHtml(dispute.order_detail_url) + '">' + escapeHtml(dispute.shopify_order_number || blank()) + '</button>' : escapeHtml(dispute.shopify_order_number || blank());
            return '<tr><td>' + escapeHtml(dispute.dispute_id || blank()) + '</td><td>' + order + '</td><td>' + escapeHtml(dispute.bill_number || blank()) + '</td><td>' + formatMoney(dispute.amount) + '</td><td>' + escapeHtml(dispute.reason || blank()) + '</td><td>' + escapeHtml(dispute.stage || blank()) + '</td><td>' + escapeHtml(dispute.status || blank()) + '</td><td>' + escapeHtml(dispute.outcome || blank()) + '</td><td>' + escapeHtml(dispute.seller_response_due_at || blank()) + '</td><td>' + escapeHtml(refunds) + '</td><td>' + escapeHtml(dispute.updated_at || blank()) + '</td></tr>';
        }).join('');
    }
    function renderRefunds(subject) {
        var refundHeadings = [['payment_method_label', t('paymentMethod')], ['shopify_order_number', t('orderNumber')], ['bill_number', t('billNumber')], ['refund_number', t('refundReference')], ['amount', t('amount')], ['source_reason', t('sourceReason')], ['manual_reason', t('manualReason')], ['status', t('status')], ['linked_disputes', t('paypalDisputes')], ['imported_at', t('updatedAt')]];
        var disputeHeadings = [['dispute_id', t('paypalDisputes')], ['shopify_order_number', t('orderNumber')], ['bill_number', t('billNumber')], ['amount', t('amount')], ['reason', t('reason')], ['stage', t('stage')], ['status', t('status')], ['outcome', t('disputeOutcome')], ['seller_response_due_at', t('responseDueAt')], ['linked_refunds', t('linkedRefunds')], ['updated_at', t('updatedAt')]];
        document.getElementById('product-statistics-refunds').innerHTML = '<div class="alert alert-info"><i class="fa fa-info-circle"></i> ' + escapeHtml(t('crossProductNote')) + '<br><i class="fa fa-info-circle"></i> ' + escapeHtml(t('creditCardDisputeNote')) + '</div>'
            + '<div class="table-responsive product-statistics-detail-table-wrap"><table class="table product-statistics-detail-table"><thead><tr>' + refundHeadings.map(function (heading) { return '<th>' + detailSortButton('refunds', heading[0], heading[1]) + '</th>'; }).join('') + '</tr></thead><tbody>' + refundRows(subject) + '</tbody></table></div>'
            + '<div class="table-responsive product-statistics-detail-table-wrap"><table class="table product-statistics-detail-table"><thead><tr>' + disputeHeadings.map(function (heading) { return '<th>' + detailSortButton('disputes', heading[0], heading[1]) + '</th>'; }).join('') + '</tr></thead><tbody>' + disputeRows(subject) + '</tbody></table></div>';
    }
    function renderDetailTables() {
        var product = detailState.product;
        if (!product) return;
        var related = product.related_orders || [];
        renderOrdersTable('product-statistics-paypal-orders', 'paypal_orders', related.filter(function (order) { return order.payment_kind === 'paypal'; }));
        renderOrdersTable('product-statistics-credit-card-orders', 'credit_card_orders', related.filter(function (order) { return order.payment_kind === 'credit_card'; }));
        renderRefunds(product);
        renderOrdersTable('product-statistics-orders', 'orders', related);
    }
    function fillModal(product) {
        detailState = { product: product, sorts: {} };
        document.getElementById('product-statistics-detail-title').textContent = t('detailTitle');
        document.getElementById('product-statistics-detail-identity').innerHTML = productThumb(product) + '<div><strong>' + escapeHtml(product.name) + '</strong><span>' + escapeHtml(product.sku || blank()) + '</span></div>' + decisionBadge(product.decision);
        document.getElementById('product-statistics-overview').innerHTML = '<div class="product-statistics-detail-grid">'
            + detailMetric(t('sales'), formatNumber(product.sales)) + detailMetric(t('revenue'), formatMoney(product.revenue))
            + detailMetric(t('paypalRefundAmount'), formatMoney(product.paypal_refund_amount)) + detailMetric(t('paypalRefundRate'), formatRate(product.paypal_refund_rate))
            + detailMetric(t('paypalDisputeCount'), formatNumber(product.paypal_dispute_count)) + detailMetric(t('paypalDisputeRate'), formatRate(product.paypal_dispute_rate))
            + detailMetric(t('creditCardRefundAmount'), formatMoney(product.credit_card_refund_amount)) + detailMetric(t('creditCardRefundRate'), formatRate(product.credit_card_refund_rate))
            + detailMetric(t('combinedRefundRate'), formatRate(product.combined_refund_rate)) + detailMetric(t('actualProfit'), formatMoney(product.actual_profit), isNumeric(product.actual_profit) && number(product.actual_profit) < 0 ? 'is-negative' : '')
            + '</div>' + detailBreakdown(product) + '<div class="alert alert-info"><i class="fa fa-info-circle"></i> ' + escapeHtml(t('crossProductNote')) + '<br><i class="fa fa-info-circle"></i> ' + escapeHtml(t('creditCardDisputeNote')) + '</div>';
        renderDetailTables();
        if ($) {
            $('#product-statistics-detail-modal .nav-tabs a[href="#product-statistics-overview"]').tab('show');
            $('#product-statistics-detail-modal').modal('show');
        }
    }
    function appendHiddenInput(container, name, value) { var input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; container.appendChild(input); }
    function navigate(params) {
        var url = new URL(window.location.href);
        Object.keys(params).forEach(function (key) {
            var value = params[key];
            if (value === null || value === undefined || value === '') url.searchParams.delete(key); else url.searchParams.set(key, value);
        });
        window.location.assign(url.toString());
    }
    function populateExportPayload() {
        if (!exportPayload) return;
        exportPayload.innerHTML = '';
        [['date_from', document.getElementById('product-statistics-date-from').value], ['date_to', document.getElementById('product-statistics-date-to').value], ['name', document.getElementById('product-statistics-name').value], ['keyword', document.getElementById('product-statistics-keyword').value], ['store', document.getElementById('product-statistics-store').value], ['decision', document.getElementById('product-statistics-decision').value], ['sort', state.sort], ['direction', state.direction]].forEach(function (item) { appendHiddenInput(exportPayload, item[0], item[1]); });
        selectedProductIds().forEach(function (id) { appendHiddenInput(exportPayload, 'selected_ids[]', id); });
    }
    filterForm.addEventListener('submit', function () { state.selected = {}; });
    pageSizeInput.addEventListener('change', function () { navigate({ per_page: pageSizeInput.value, page: 1 }); });
    document.getElementById('product-statistics-prev-page').addEventListener('click', function () { if (state.page > 1) navigate({ page: state.page - 1 }); });
    document.getElementById('product-statistics-next-page').addEventListener('click', function () { if (state.page < Number(pagination.last_page || 1)) navigate({ page: state.page + 1 }); });
    if (checkAll) checkAll.addEventListener('change', function () { products.forEach(function (product) { state.selected[product.id] = checkAll.checked; }); refreshSelection(); });
    document.addEventListener('change', function (event) { if (event.target.classList.contains('product-statistics-check')) { state.selected[event.target.value] = event.target.checked; refreshSelection(); } });
    if (exportForm) {
        exportForm.addEventListener('submit', function (event) {
            var scope = exportForm.querySelector('input[name="scope"]:checked').value;
            if (scope === 'selected' && !selectedProductIds().length) { event.preventDefault(); window.alert(t('selectExportWarning')); return; }
            populateExportPayload();
        });
        if ($) $('#product-statistics-export-modal').on('show.bs.modal', function () { refreshSelection(); populateExportPayload(); });
    }
    document.addEventListener('click', function (event) {
        var imagePreview = event.target.closest('.product-statistics-thumb.is-previewable');
        if (imagePreview) { openImagePreview(imagePreview); return; }
        var detailSort = event.target.closest('.product-statistics-detail-sort');
        if (detailSort) {
            var table = detailSort.dataset.detailTable;
            var key = detailSort.dataset.detailSort;
            var current = detailState.sorts[table] || {};
            if (current.key !== key) detailState.sorts[table] = { key: key, direction: 'asc' };
            else if (current.direction === 'asc') detailState.sorts[table] = { key: key, direction: 'desc' };
            else detailState.sorts[table] = {};
            renderDetailTables();
            return;
        }
        var target = event.target.closest('.product-statistics-view, .product-statistics-page-button, .product-statistics-sort');
        if (!target) return;
        if (target.classList.contains('product-statistics-view')) { var product = findProduct(target.dataset.productId); if (product) fillModal(product); return; }
        if (target.classList.contains('product-statistics-page-button')) { navigate({ page: number(target.dataset.page) || 1 }); return; }
        if (target.classList.contains('product-statistics-sort')) {
            var sort = target.dataset.sort;
            var direction = 'desc';
            if (state.sort === sort && state.direction === 'desc') direction = 'asc';
            else if (state.sort === sort && state.direction === 'asc') { sort = ''; direction = ''; }
            navigate({ sort: sort, direction: direction, page: 1 });
        }
    });
    document.addEventListener('keydown', function (event) {
        if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('.product-statistics-thumb.is-previewable')) {
            event.preventDefault();
            openImagePreview(event.target);
        }
    });
    renderTable();
    if (config.exportValidationError && $) $('#product-statistics-export-modal').modal('show');
}(window, document, window.jQuery));
