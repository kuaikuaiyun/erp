(function (window, document, $) {
    'use strict';

    var config = window.ErpLogisticsProductStatistics || {};
    var products = Array.isArray(config.products) ? config.products : [];
    var decisions = config.decisions || {};
    var labels = config.labels || {};
    var state = { page: 1, pageSize: 10, sort: null, direction: null, expanded: {}, selected: {}, filtered: [] };
    var body = document.getElementById('product-statistics-body');
    var empty = document.getElementById('product-statistics-empty');
    var filterForm = document.getElementById('product-statistics-filter-form');
    var nameInput = document.getElementById('product-statistics-name');
    var keywordInput = document.getElementById('product-statistics-keyword');
    var storeInput = document.getElementById('product-statistics-store');
    var decisionInput = document.getElementById('product-statistics-decision');
    var pageSizeInput = document.getElementById('product-statistics-page-size');
    var checkAll = document.getElementById('product-statistics-check-all');
    var exportForm = document.getElementById('product-statistics-export-form');
    var exportSelectedScope = exportForm ? exportForm.querySelector('input[name="scope"][value="selected"]') : null;
    var exportFilteredScope = exportForm ? exportForm.querySelector('input[name="scope"][value="filtered"]') : null;
    var exportSelectedCount = document.getElementById('product-statistics-export-selected-count');
    var exportFilteredCount = document.getElementById('product-statistics-export-filtered-count');
    var exportPayload = document.getElementById('product-statistics-export-payload');

    if (!body || !filterForm) return;

    function t(key, fallback) { return labels[key] || fallback || ''; }
    function isNumeric(value) { return value !== null && value !== '' && value !== undefined && isFinite(Number(value)); }
    function number(value) { return isNumeric(value) ? Number(value) : 0; }
    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function formatNumber(value) { return isNumeric(value) ? number(value).toLocaleString('en-US', { maximumFractionDigits: 0 }) : t('notAvailable', '—'); }
    function formatMoney(value) {
        if (!isNumeric(value)) return t('notAvailable', '—');
        var amount = number(value);
        return (amount < 0 ? '-$' : '$') + Math.abs(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function formatRate(value) { return isNumeric(value) ? number(value).toFixed(2) + '%' : t('notAvailable', '—'); }
    function decisionClass(key) { return ({ continue: 'is-continue', optimize: 'is-optimize', watch: 'is-watch', stop: 'is-stop' })[key] || 'is-watch'; }
    function decisionBadge(key) { return '<span class="product-statistics-decision ' + decisionClass(key) + '">' + escapeHtml(decisions[key] || '') + '</span>'; }
    function rateClass(value) {
        if (!isNumeric(value)) return 'is-watch';
        var rate = number(value);
        if (rate >= 35) return 'is-strong';
        if (rate >= 18) return 'is-healthy';
        if (rate >= 8) return 'is-watch';
        return 'is-risk';
    }
    function productFees(subject) { return number(subject.shipping) + number(subject.handling); }
    function productThumb(product, compact) {
        var imageUrl = product.image_url ? String(product.image_url) : '';
        var image = imageUrl ? '<img src="' + escapeHtml(imageUrl) + '" alt="" loading="lazy">' : '';
        var attributes = imageUrl ? ' role="button" tabindex="0" data-image-url="' + escapeHtml(imageUrl) + '" data-image-name="' + escapeHtml(product.name || '') + '" title="点击放大图片"' : '';
        return '<span class="product-statistics-thumb ' + (compact ? 'is-compact ' : '') + (image ? 'has-image is-previewable' : '') + '"' + attributes + '>' + image + '<i class="fa fa-cube" aria-hidden="true"></i></span>';
    }
    function productMatches(product) {
        var name = nameInput.value.trim().toLowerCase();
        var keyword = keywordInput.value.trim().toLowerCase();
        var store = storeInput.value;
        var decision = decisionInput.value;
        var variantText = (product.variants || []).map(function (variant) { return [variant.name, variant.sku].join(' ').toLowerCase(); }).join(' ');
        var haystack = [product.name, product.sku, product.category, variantText].join(' ').toLowerCase();

        return (!name || String(product.name).toLowerCase().indexOf(name) !== -1)
            && (!keyword || haystack.indexOf(keyword) !== -1)
            && (!store || (product.stores || []).indexOf(store) !== -1)
            && (!decision || product.decision === decision);
    }
    function sortValue(product, key) { return key === 'fees' ? productFees(product) : (key === 'decision' ? ['continue', 'optimize', 'watch', 'stop'].indexOf(product.decision) : product[key]); }
    function sortedProducts() {
        var list = products.filter(productMatches);
        if (!state.sort || !state.direction) return list;
        return list.sort(function (left, right) {
            var leftValue = sortValue(left, state.sort);
            var rightValue = sortValue(right, state.sort);
            var comparison;
            if (typeof leftValue === 'string') comparison = leftValue.localeCompare(String(rightValue), 'zh-CN');
            else if (!isNumeric(leftValue) && !isNumeric(rightValue)) comparison = 0;
            else if (!isNumeric(leftValue)) return 1;
            else if (!isNumeric(rightValue)) return -1;
            else comparison = number(leftValue) - number(rightValue);
            return state.direction === 'asc' ? comparison : -comparison;
        });
    }
    function parentRow(product) {
        var expanded = Boolean(state.expanded[product.id]);
        var maximum = Math.max.apply(null, state.filtered.map(function (item) { return number(item.sales); }).concat([1]));
        var ratio = Math.min(100, Math.max(8, number(product.sales) / maximum * 100));
        return '<tr class="product-statistics-product-row ' + (expanded ? 'is-expanded' : '') + '" data-product-id="' + escapeHtml(product.id) + '">'
            + '<td class="product-statistics-check-column"><input class="product-statistics-check" type="checkbox" value="' + escapeHtml(product.id) + '"' + (state.selected[product.id] ? ' checked' : '') + '></td>'
            + '<td class="product-statistics-expand-column"><button class="product-statistics-expand" type="button" data-product-id="' + escapeHtml(product.id) + '" aria-expanded="' + (expanded ? 'true' : 'false') + '"><i class="fa fa-angle-' + (expanded ? 'down' : 'right') + '"></i></button></td>'
            + '<td><div class="product-statistics-product-cell">' + productThumb(product) + '<span><strong>' + escapeHtml(product.name) + '</strong><small>' + escapeHtml(product.category) + '</small></span></div></td>'
            + '<td><code>' + escapeHtml(product.sku) + '</code></td>'
            + '<td><span class="product-statistics-store-count">' + formatNumber(product.shop_count) + '</span><small class="product-statistics-store-names">' + escapeHtml((product.stores || []).join('、')) + '</small></td>'
            + '<td><div class="product-statistics-sales-cell"><strong>' + formatNumber(product.sales) + '</strong><span><i style="width:' + ratio.toFixed(1) + '%"></i></span></div></td>'
            + '<td>' + formatMoney(product.revenue) + '</td><td>' + formatMoney(product.cost) + '</td><td>' + formatMoney(productFees(product)) + '</td><td>' + formatMoney(product.refunds) + '</td>'
            + '<td class="product-statistics-profit ' + (isNumeric(product.profit) && number(product.profit) < 0 ? 'is-negative' : '') + '">' + formatMoney(product.profit) + '</td>'
            + '<td><span class="product-statistics-rate ' + rateClass(product.profit_rate) + '">' + formatRate(product.profit_rate) + '</span></td><td>' + formatRate(product.refund_rate) + '</td>'
            + '<td>' + decisionBadge(product.decision) + '</td><td><button class="btn btn-primary btn-xs product-statistics-view" type="button" data-product-id="' + escapeHtml(product.id) + '"><i class="fa fa-eye"></i> ' + escapeHtml(t('view', '')) + '</button></td></tr>';
    }
    function variantRows(product) {
        if (!state.expanded[product.id]) return '';
        return (product.variants || []).map(function (variant) {
            return '<tr class="product-statistics-variant-row" data-product-id="' + escapeHtml(product.id) + '" data-variant-id="' + escapeHtml(variant.id) + '">'
                + '<td class="product-statistics-check-column"></td><td></td>'
                + '<td><div class="product-statistics-variant-cell">' + productThumb(variant, true) + '<span><strong>' + escapeHtml(variant.name) + '</strong><small>' + escapeHtml(t('orderedVariant', '')) + '</small></span></div></td>'
                + '<td><code>' + escapeHtml(variant.sku) + '</code></td><td><small>' + escapeHtml(t('orderedVariant', '')) + '</small></td>'
                + '<td>' + formatNumber(variant.sales) + '</td><td>' + formatMoney(variant.revenue) + '</td><td>' + formatMoney(variant.cost) + '</td><td>' + formatMoney(productFees(variant)) + '</td><td>' + formatMoney(variant.refunds) + '</td>'
                + '<td class="product-statistics-profit ' + (isNumeric(variant.profit) && number(variant.profit) < 0 ? 'is-negative' : '') + '">' + formatMoney(variant.profit) + '</td><td><span class="product-statistics-rate ' + rateClass(variant.profit_rate) + '">' + formatRate(variant.profit_rate) + '</span></td><td>' + formatRate(variant.refund_rate) + '</td>'
                + '<td><span class="product-statistics-child-tag">' + escapeHtml(t('variant', '')) + '</span></td><td><button class="btn btn-default btn-xs product-statistics-variant-view" type="button" data-product-id="' + escapeHtml(product.id) + '" data-variant-id="' + escapeHtml(variant.id) + '">' + escapeHtml(t('detail', '')) + '</button></td></tr>';
        }).join('');
    }
    function renderSummary() {
        var summary = state.filtered.reduce(function (result, product) {
            result.sales += number(product.sales); result.revenue += number(product.revenue);
            if (!isNumeric(product.profit)) result.profitComplete = false; else result.profit += number(product.profit);
            return result;
        }, { sales: 0, revenue: 0, profit: 0, profitComplete: true });
        document.getElementById('product-statistics-summary-products').textContent = formatNumber(state.filtered.length);
        document.getElementById('product-statistics-summary-sales').textContent = formatNumber(summary.sales);
        document.getElementById('product-statistics-summary-revenue').textContent = formatMoney(summary.revenue);
        document.getElementById('product-statistics-summary-profit').textContent = summary.profitComplete ? formatMoney(summary.profit) : t('notAvailable', '—');
        document.getElementById('product-statistics-summary-profit-rate').textContent = summary.profitComplete && summary.revenue > 0 ? formatRate(summary.profit / summary.revenue * 100) : t('notAvailable', '—');
        document.getElementById('product-statistics-total-label').textContent = (t('totalProducts', '') || '').replace(':count', formatNumber(state.filtered.length));
    }
    function renderPagination(totalPages, total) {
        var pageNumbers = document.getElementById('product-statistics-page-numbers');
        var pages = [], start = Math.max(1, state.page - 2), end = Math.min(totalPages, start + 4);
        start = Math.max(1, end - 4);
        for (var page = start; page <= end; page++) pages.push('<button type="button" class="btn btn-default btn-sm product-statistics-page-button ' + (page === state.page ? 'is-active' : '') + '" data-page="' + page + '">' + page + '</button>');
        pageNumbers.innerHTML = pages.join('');
        document.getElementById('product-statistics-prev-page').disabled = state.page <= 1;
        document.getElementById('product-statistics-next-page').disabled = state.page >= totalPages;
        document.getElementById('product-statistics-page-summary').textContent = total === 0 ? t('pageEmpty', '—') : (t('pageSummary', '') || '').replace(':from', (state.page - 1) * state.pageSize + 1).replace(':to', Math.min(state.page * state.pageSize, total)).replace(':total', total);
    }
    function currentPageProducts() { var start = (state.page - 1) * state.pageSize; return state.filtered.slice(start, start + state.pageSize); }
    function selectedProductIds() { return Object.keys(state.selected).filter(function (id) { return state.selected[id]; }); }
    function clearSelection() { state.selected = {}; }
    function refreshSelection() {
        var visible = currentPageProducts(), selectedOnPage = visible.filter(function (product) { return state.selected[product.id]; });
        if (checkAll) { checkAll.checked = visible.length > 0 && selectedOnPage.length === visible.length; checkAll.indeterminate = selectedOnPage.length > 0 && !checkAll.checked; checkAll.disabled = visible.length === 0; }
        if (exportSelectedCount) exportSelectedCount.textContent = (t('selectedCount', '') || '').replace(':count', selectedOnPage.length);
        if (exportFilteredCount) {
            var variantCount = state.filtered.reduce(function (total, product) { return total + (product.variants || []).length; }, 0);
            exportFilteredCount.textContent = (t('filteredCount', '') || '').replace(':products', state.filtered.length).replace(':variants', variantCount);
        }
        if (exportSelectedScope && exportFilteredScope) { exportSelectedScope.disabled = selectedOnPage.length === 0; if (!selectedOnPage.length && exportSelectedScope.checked) exportFilteredScope.checked = true; }
    }
    function renderSortIndicators() {
        Array.prototype.slice.call(document.querySelectorAll('.product-statistics-sort')).forEach(function (button) {
            var icon = button.querySelector('i');
            if (state.sort && button.dataset.sort === state.sort) { button.classList.add('is-active'); icon.className = 'fa fa-sort-' + (state.direction === 'asc' ? 'asc' : 'desc'); }
            else { button.classList.remove('is-active'); icon.className = 'fa fa-sort'; }
        });
    }
    function renderTable() {
        var list = sortedProducts(), totalPages = Math.max(1, Math.ceil(list.length / state.pageSize));
        if (state.page > totalPages) state.page = totalPages;
        state.filtered = list; renderSummary(); renderSortIndicators();
        var visible = currentPageProducts();
        body.innerHTML = visible.map(function (product) { return parentRow(product) + variantRows(product); }).join('');
        empty.hidden = visible.length > 0;
        document.getElementById('product-statistics-table').classList.toggle('is-empty', visible.length === 0);
        renderPagination(totalPages, list.length); refreshSelection();
    }
    function findProduct(productId) { return products.filter(function (product) { return product.id === productId; })[0] || null; }
    function findVariant(product, variantId) { return (product.variants || []).filter(function (item) { return item.id === variantId; })[0] || null; }
    function openImagePreview(trigger) {
        var imageUrl = trigger.dataset.imageUrl;
        if (!imageUrl) return;
        var image = document.getElementById('product-statistics-image-preview');
        var name = document.getElementById('product-statistics-image-name');
        image.src = imageUrl;
        image.alt = trigger.dataset.imageName || '';
        name.textContent = trigger.dataset.imageName || '';
        if ($) $('#product-statistics-image-modal').modal('show');
    }
    function detailMetric(label, value, className) { return '<div class="product-statistics-detail-metric ' + (className || '') + '"><span>' + escapeHtml(label) + '</span><strong>' + value + '</strong></div>'; }
    function negativeMoney(value) { return isNumeric(value) ? '-' + formatMoney(Math.abs(number(value))) : t('notAvailable', '—'); }
    function detailBreakdown(subject) {
        return '<div class="product-statistics-breakdown"><h5>' + escapeHtml(t('profitBreakdown', '')) + '</h5>'
            + '<dl><dt>' + escapeHtml(t('revenue', '')) + '</dt><dd>' + formatMoney(subject.revenue) + '</dd></dl>'
            + '<dl><dt>' + escapeHtml(t('cost', '')) + '</dt><dd>' + negativeMoney(subject.cost) + '</dd></dl>'
            + '<dl><dt>' + escapeHtml(t('fees', '')) + '</dt><dd>' + negativeMoney(productFees(subject)) + '</dd></dl>'
            + '<dl><dt>' + escapeHtml(t('refunds', '')) + '</dt><dd>' + negativeMoney(subject.refunds) + '</dd></dl>'
            + '<dl class="is-total"><dt>' + escapeHtml(t('profit', '')) + '</dt><dd>' + formatMoney(subject.profit) + '</dd></dl></div>';
    }
    function shopRows(product) {
        return (product.store_metrics || []).map(function (store) {
            return '<tr><td>' + escapeHtml(store.name) + '</td><td>' + formatNumber(store.sales) + '</td><td>' + formatMoney(store.revenue) + '</td><td>' + formatMoney(store.profit) + '</td><td><span class="product-statistics-rate ' + rateClass(store.profit_rate) + '">' + formatRate(store.profit_rate) + '</span></td></tr>';
        }).join('');
    }
    function refundRows(subject) {
        var details = subject.refund_details || [];
        if (!details.length) return '<tr><td colspan="9" class="text-center text-muted">' + escapeHtml(t('pageEmpty', '—')) + '</td></tr>';
        return details.map(function (refund) {
            return '<tr><td>' + escapeHtml(refund.store || '—') + '</td><td>' + escapeHtml(refund.shopify_order_number || '—') + '</td><td>' + escapeHtml(refund.bill_number || '—') + '</td><td>' + escapeHtml(refund.refund_number || '—') + '</td><td>' + formatMoney(refund.amount) + '</td><td>' + escapeHtml(refund.refund_type || '—') + '</td><td title="' + escapeHtml(refund.refund_reason || '') + '">' + escapeHtml(refund.refund_reason || '—') + '</td><td title="' + escapeHtml(refund.remark || '') + '">' + escapeHtml(refund.remark || '—') + '</td><td>' + escapeHtml(refund.imported_at || '—') + '</td></tr>';
        }).join('');
    }
    function fillModal(product, variantId) {
        var variant = variantId ? findVariant(product, variantId) : null, subject = variant || product;
        document.getElementById('product-statistics-detail-title').textContent = variant ? t('variantDetailTitle', '') : t('detailTitle', '');
        document.getElementById('product-statistics-detail-identity').innerHTML = productThumb(subject) + '<div><strong>' + escapeHtml(subject.name) + '</strong><span>' + escapeHtml(subject.sku) + ' · ' + escapeHtml(product.category) + '</span></div>' + decisionBadge(product.decision);
        document.getElementById('product-statistics-overview').innerHTML = '<div class="product-statistics-detail-grid">'
            + detailMetric(t('sales', ''), formatNumber(subject.sales)) + detailMetric(t('revenue', ''), formatMoney(subject.revenue))
            + detailMetric(t('profit', ''), formatMoney(subject.profit), isNumeric(subject.profit) && number(subject.profit) < 0 ? 'is-negative' : '')
            + detailMetric(t('profitRate', ''), formatRate(subject.profit_rate), rateClass(subject.profit_rate))
            + detailMetric(t('refundRate', ''), formatRate(subject.refund_rate)) + detailMetric(t('cost', ''), formatMoney(subject.cost))
            + detailMetric(t('actualRefunds', ''), formatMoney(subject.actual_refunds)) + detailMetric(t('actualRefundCount', ''), formatNumber(subject.actual_refund_count))
            + '</div>' + detailBreakdown(subject);
        document.getElementById('product-statistics-variants').innerHTML = '<div class="table-responsive"><table class="table product-statistics-detail-table"><thead><tr><th>' + escapeHtml(t('variant', '')) + '</th><th>SKU</th><th>' + escapeHtml(t('sales', '')) + '</th><th>' + escapeHtml(t('revenue', '')) + '</th><th>' + escapeHtml(t('profit', '')) + '</th><th>' + escapeHtml(t('profitRate', '')) + '</th><th>' + escapeHtml(t('refundRate', '')) + '</th></tr></thead><tbody>' + (product.variants || []).map(function (item) {
            return '<tr class="' + (variant && item.id === variant.id ? 'is-focused' : '') + '"><td>' + escapeHtml(item.name) + '</td><td><code>' + escapeHtml(item.sku) + '</code></td><td>' + formatNumber(item.sales) + '</td><td>' + formatMoney(item.revenue) + '</td><td>' + formatMoney(item.profit) + '</td><td><span class="product-statistics-rate ' + rateClass(item.profit_rate) + '">' + formatRate(item.profit_rate) + '</span></td><td>' + formatRate(item.refund_rate) + '</td></tr>';
        }).join('') + '</tbody></table></div>';
        document.getElementById('product-statistics-stores').innerHTML = '<div class="table-responsive"><table class="table product-statistics-detail-table"><thead><tr><th>' + escapeHtml(t('storeName', '')) + '</th><th>' + escapeHtml(t('sales', '')) + '</th><th>' + escapeHtml(t('revenue', '')) + '</th><th>' + escapeHtml(t('profit', '')) + '</th><th>' + escapeHtml(t('profitRate', '')) + '</th></tr></thead><tbody>' + shopRows(product) + '</tbody></table></div>';
        document.getElementById('product-statistics-trend').innerHTML = '<div class="table-responsive"><table class="table product-statistics-detail-table product-statistics-trend-table"><thead><tr><th>' + escapeHtml(t('period', '')) + '</th><th>' + escapeHtml(t('sales', '')) + '</th><th>' + escapeHtml(t('profit', '')) + '</th><th>' + escapeHtml(t('salesChange', '')) + '</th></tr></thead><tbody>' + (product.trend || []).map(function (item, index, rows) {
            var previous = rows[index - 1], change = previous && number(previous.sales) > 0 ? (number(item.sales) - number(previous.sales)) / number(previous.sales) * 100 : null;
            return '<tr><td>' + escapeHtml(item.label) + '</td><td>' + formatNumber(item.sales) + '</td><td>' + formatMoney(item.profit) + '</td><td>' + (change === null ? t('notAvailable', '—') : '<span class="' + (change >= 0 ? 'text-green' : 'text-red') + '">' + (change >= 0 ? '+' : '') + formatRate(change) + '</span>') + '</td></tr>';
        }).join('') + '</tbody></table></div>';
        document.getElementById('product-statistics-refunds').innerHTML = '<div class="alert alert-info"><i class="fa fa-info-circle"></i> ' + escapeHtml(t('refundAllocationNote', '')) + '</div><div class="table-responsive"><table class="table product-statistics-detail-table"><thead><tr><th>店铺</th><th>Shopify订单号</th><th>账单号</th><th>退款单号</th><th>分摊退款额（USD）</th><th>退款类型</th><th>退款原因</th><th>备注</th><th>导入时间</th></tr></thead><tbody>' + refundRows(subject) + '</tbody></table></div>';
        if ($) { $('#product-statistics-detail-modal .nav-tabs a[href="#product-statistics-overview"]').tab('show'); $('#product-statistics-detail-modal').modal('show'); }
    }
    function appendHiddenInput(container, name, value) { var input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; container.appendChild(input); }
    function populateExportPayload() {
        if (!exportPayload) return;
        exportPayload.innerHTML = '';
        [['date_from', document.getElementById('product-statistics-date-from').value], ['date_to', document.getElementById('product-statistics-date-to').value], ['name', nameInput.value], ['keyword', keywordInput.value], ['store', storeInput.value], ['decision', decisionInput.value], ['sort', state.sort || ''], ['direction', state.direction || '']].forEach(function (item) { appendHiddenInput(exportPayload, item[0], item[1]); });
        selectedProductIds().forEach(function (id) { appendHiddenInput(exportPayload, 'selected_ids[]', id); });
    }
    function refreshWithTransition() { var table = document.getElementById('product-statistics-table'); table.classList.add('is-refreshing'); window.setTimeout(function () { renderTable(); table.classList.remove('is-refreshing'); }, 120); }

    filterForm.addEventListener('submit', function () { state.page = 1; });
    [nameInput, keywordInput, storeInput, decisionInput].forEach(function (element) { element.addEventListener(element.tagName === 'SELECT' ? 'change' : 'input', function () { state.page = 1; clearSelection(); refreshWithTransition(); }); });
    pageSizeInput.addEventListener('change', function () { state.pageSize = number(pageSizeInput.value) || 10; state.page = 1; clearSelection(); renderTable(); });
    document.getElementById('product-statistics-prev-page').addEventListener('click', function () { if (state.page > 1) { state.page--; clearSelection(); renderTable(); } });
    document.getElementById('product-statistics-next-page').addEventListener('click', function () { var pages = Math.ceil(state.filtered.length / state.pageSize); if (state.page < pages) { state.page++; clearSelection(); renderTable(); } });
    if (checkAll) checkAll.addEventListener('change', function () { currentPageProducts().forEach(function (product) { state.selected[product.id] = checkAll.checked; }); refreshSelection(); });
    document.addEventListener('change', function (event) { if (event.target.classList.contains('product-statistics-check')) { state.selected[event.target.value] = event.target.checked; refreshSelection(); } });
    if (exportForm) {
        exportForm.addEventListener('submit', function (event) { var scope = exportForm.querySelector('input[name="scope"]:checked').value; if (scope === 'selected' && !currentPageProducts().some(function (product) { return state.selected[product.id]; })) { event.preventDefault(); window.alert(t('selectExportWarning', '')); return; } populateExportPayload(); });
        if ($) $('#product-statistics-export-modal').on('show.bs.modal', function () { refreshSelection(); populateExportPayload(); });
    }
    document.addEventListener('click', function (event) {
        var imagePreview = event.target.closest('.product-statistics-thumb.is-previewable');
        if (imagePreview) { openImagePreview(imagePreview); return; }
        var row = event.target.closest('.product-statistics-product-row');
        if (row && !event.target.closest('button, a, input, select')) { state.expanded[row.dataset.productId] = !state.expanded[row.dataset.productId]; renderTable(); return; }
        var target = event.target.closest('.product-statistics-expand, .product-statistics-view, .product-statistics-variant-view, .product-statistics-page-button, .product-statistics-sort');
        if (!target) return;
        if (target.classList.contains('product-statistics-expand')) { state.expanded[target.dataset.productId] = !state.expanded[target.dataset.productId]; renderTable(); return; }
        if (target.classList.contains('product-statistics-view') || target.classList.contains('product-statistics-variant-view')) { var product = findProduct(target.dataset.productId); if (product) fillModal(product, target.dataset.variantId || null); return; }
        if (target.classList.contains('product-statistics-page-button')) { state.page = number(target.dataset.page) || 1; clearSelection(); renderTable(); return; }
        if (target.classList.contains('product-statistics-sort')) { var sort = target.dataset.sort; if (state.sort !== sort) { state.sort = sort; state.direction = 'desc'; } else if (state.direction === 'desc') { state.direction = 'asc'; } else { state.sort = null; state.direction = null; } state.page = 1; clearSelection(); renderTable(); }
    });
    document.addEventListener('keydown', function (event) {
        if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('.product-statistics-thumb.is-previewable')) {
            event.preventDefault();
            openImagePreview(event.target);
        }
    });
    state.filtered = products.slice(); renderTable();
    if (config.exportValidationError && $) $('#product-statistics-export-modal').modal('show');
}(window, document, window.jQuery));
