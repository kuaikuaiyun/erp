(function (window, document, $) {
    'use strict';

    var config = window.ErpLogisticsProductStatistics || {};
    var products = Array.isArray(config.products) ? config.products : [];
    var overallSummary = config.summary || {};
    var refundReasonReport = config.refundReasonReport || {};
    var pagination = config.pagination || { current_page: 1, per_page: 10, total: products.length, last_page: 1, from: 0, to: products.length };
    var labels = config.labels || {};
    var state = { page: Number(pagination.current_page) || 1, sort: config.sort || '', direction: config.direction || '', selected: {}, reasonReportExpanded: false };
    var detailState = { product: null, overviewLoaded: false, loadingTabs: {}, tabData: {}, sorts: {}, filters: {}, pages: {}, perPages: {}, activeTab: 'overview' };
    var body = document.getElementById('product-statistics-body');
    var tableScroll = document.querySelector('.product-statistics-table-scroll');
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
    var retryTongtuButton = document.getElementById('product-statistics-retry-tongtu');
    var retryTongtuForm = document.getElementById('product-statistics-retry-tongtu-form');
    var retryTongtuPayload = document.getElementById('product-statistics-retry-tongtu-payload');
    var selectedCountLabel = document.getElementById('product-statistics-selected-count');

    if (!body || !filterForm) return;

    function wheelPixels(event) {
        if (event.deltaMode === 1) return event.deltaY * 16;
        if (event.deltaMode === 2) return event.deltaY * window.innerHeight;
        return event.deltaY;
    }

    function bindTableBoundaryScroll() {
        if (!tableScroll) return;

        tableScroll.addEventListener('wheel', function (event) {
            if (event.ctrlKey || event.shiftKey || event.deltaY === 0 || Math.abs(event.deltaX) > Math.abs(event.deltaY)) return;

            var deltaY = wheelPixels(event);
            var scrollTop = tableScroll.scrollTop;
            var maxScrollTop = tableScroll.scrollHeight - tableScroll.clientHeight;
            var movingUp = deltaY < 0;
            var movingDown = deltaY > 0;
            var reachesTop = movingUp && scrollTop + deltaY <= 0;
            var reachesBottom = movingDown && scrollTop + deltaY >= maxScrollTop;

            if (!(reachesTop || reachesBottom)) return;

            event.preventDefault();

            if (reachesTop) {
                var distanceToTop = Math.max(0, scrollTop);
                tableScroll.scrollTop = 0;
                window.scrollBy({ top: deltaY + distanceToTop, left: 0, behavior: 'auto' });
                return;
            }

            var distanceToBottom = Math.max(0, maxScrollTop - scrollTop);
            tableScroll.scrollTop = Math.max(0, maxScrollTop);
            window.scrollBy({ top: deltaY - distanceToBottom, left: 0, behavior: 'auto' });
        }, { passive: false });
    }

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
    function productThumb(product) {
        var imageUrl = product.image_url ? String(product.image_url) : '';
        var image = imageUrl ? '<img src="' + escapeHtml(imageUrl) + '" alt="" loading="lazy">' : '';
        var attributes = imageUrl ? ' role="button" tabindex="0" data-image-url="' + escapeHtml(imageUrl) + '" data-image-name="' + escapeHtml(product.name || '') + '"' : '';
        return '<span class="product-statistics-thumb ' + (image ? 'has-image is-previewable' : '') + '"' + attributes + '>' + image + '<i class="fa fa-cube" aria-hidden="true"></i></span>';
    }
    function hasTongtuMatch(product) {
        return Boolean(product && product.match_status === 'matched' && product.product_code);
    }
    function productIdentity(product) {
        if (hasTongtuMatch(product)) return product.product_code;
        var fallbackSku = product && (product.fallback_shopify_sku || product.shopify_sku || product.sku);
        return fallbackSku ? t('shopifySkuPrefix') + fallbackSku : (product && product.match_status_label ? product.match_status_label : t('pendingTongtuMatch'));
    }
    function productCodeCell(product) {
        if (hasTongtuMatch(product)) {
            return '<code title="' + escapeHtml(product.product_code) + '">' + escapeHtml(product.product_code) + '</code>';
        }

        var fallbackSku = product && (product.fallback_shopify_sku || product.shopify_sku || product.sku);
        var fallback = fallbackSku
            ? '<small>' + escapeHtml(t('shopifySkuPrefix')) + '<code title="' + escapeHtml(fallbackSku) + '">' + escapeHtml(fallbackSku) + '</code></small>'
            : '';

        var status = product && product.match_status_label ? product.match_status_label : t('pendingTongtuMatch');
        return '<div class="product-statistics-pcl-pending"><span class="is-' + escapeHtml((product && product.match_status) || 'unmatched') + '">' + escapeHtml(status) + '</span>' + fallback + '</div>';
    }
    function variantCountCell(product) {
        if (hasTongtuMatch(product)) return formatNumber(product.variant_count);
        return '<span class="product-statistics-pending-dash" title="' + escapeHtml(t('variantCountPending')) + '">—</span>';
    }
    function matchStatusCell(product) {
        var status = String((product && product.match_status) || 'unmatched');
        var label = product && product.match_status_label ? product.match_status_label : t('pendingTongtuMatch');
        return '<span class="product-statistics-match-status is-' + escapeHtml(status) + '">' + escapeHtml(label) + '</span>';
    }
    function parentRow(product) {
        var subtitle = product.matched_name || product.category || '';
        return '<tr class="product-statistics-product-row" data-product-id="' + escapeHtml(product.id) + '">'
            + '<td class="product-statistics-check-column"><input class="product-statistics-check" type="checkbox" value="' + escapeHtml(product.id) + '"' + (state.selected[product.id] ? ' checked' : '') + '></td>'
            + '<td><div class="product-statistics-product-cell">' + productThumb(product) + '<span><strong title="' + escapeHtml(product.name || '') + '">' + escapeHtml(product.name) + '</strong><small class="' + (product.matched_name ? 'product-statistics-matched-name' : '') + '" title="' + escapeHtml(subtitle) + '">' + escapeHtml(subtitle) + '</small></span></div></td>'
            + '<td>' + productCodeCell(product) + '</td>'
            + '<td>' + matchStatusCell(product) + '</td>'
            + '<td><span class="product-statistics-store-count">' + formatNumber(product.shop_count) + '</span><small class="product-statistics-store-names">' + escapeHtml((product.stores || []).join('、')) + '</small></td>'
            + '<td>' + formatNumber(product.sales) + '</td><td>' + formatMoney(product.revenue) + '</td>'
            + '<td>' + formatMoney(product.paypal_refund_amount) + '</td><td>' + formatRate(product.paypal_refund_rate) + '</td><td>' + formatNumber(product.paypal_dispute_count) + '</td><td>' + formatRate(product.paypal_dispute_rate) + '</td>'
            + '<td>' + formatMoney(product.credit_card_refund_amount) + '</td><td>' + formatRate(product.credit_card_refund_rate) + '</td><td>' + formatRate(product.combined_refund_rate) + '</td>'
            + '<td><button class="btn btn-primary btn-xs product-statistics-view" type="button" data-product-id="' + escapeHtml(product.id) + '"><i class="fa fa-eye"></i> ' + escapeHtml(t('view')) + '</button></td></tr>';
    }
    function renderSummary() {
        document.getElementById('product-statistics-summary-products').textContent = formatNumber(overallSummary.product_count);
        document.getElementById('product-statistics-summary-sales').textContent = formatNumber(overallSummary.sales);
        document.getElementById('product-statistics-summary-revenue').textContent = formatMoney(overallSummary.revenue);
        document.getElementById('product-statistics-summary-refunds').textContent = formatMoney(overallSummary.actual_refunds);
        document.getElementById('product-statistics-total-label').textContent = (t('totalProducts') || '').replace(':count', formatNumber(overallSummary.product_count));
    }
    function reasonReportLabel(row) {
        if (!row || row.reason === '__empty__') return t('noReason');
        if (row.reason === '__other__' || row.is_other) return t('reasonReportOther');
        return row.reason || t('noReason');
    }
    function reasonReportPanel(title, rows) {
        var content = Array.isArray(rows) && rows.length ? rows.map(function (row) {
            var width = Math.max(0, Math.min(100, Number(row.amount_ratio) || 0));
            return '<li><div class="product-statistics-reason-row-head"><strong title="' + escapeHtml(reasonReportLabel(row)) + '">' + escapeHtml(reasonReportLabel(row)) + '</strong><span>' + formatMoney(row.amount) + '</span></div>'
                + '<div class="product-statistics-reason-bar"><i style="width:' + width + '%"></i></div>'
                + '<small>' + escapeHtml((t('reasonReportCountShare') || '').replace(':count', formatNumber(row.record_count)).replace(':ratio', formatRate(row.count_ratio))) + '<b>·</b>' + escapeHtml((t('reasonReportAmountShare') || '').replace(':ratio', formatRate(row.amount_ratio))) + '</small></li>';
        }).join('') : '<li class="product-statistics-reason-empty">' + escapeHtml(t('reasonReportEmpty')) + '</li>';
        return '<section class="product-statistics-reason-panel"><h4>' + escapeHtml(title) + '</h4><ul>' + content + '</ul></section>';
    }
    function renderReasonReport() {
        var container = document.getElementById('product-statistics-reason-report');
        if (!container) return;
        var expanded = Boolean(state.reasonReportExpanded);
        var actionLabel = expanded ? t('reasonReportCollapse') : t('reasonReportExpand');
        container.classList.toggle('is-expanded', expanded);
        container.innerHTML = '<header><button type="button" class="product-statistics-reason-toggle" aria-expanded="' + (expanded ? 'true' : 'false') + '" aria-controls="product-statistics-reason-report-content">'
            + '<span class="product-statistics-reason-heading"><strong><i class="fa fa-pie-chart"></i> ' + escapeHtml(t('reasonReportTitle')) + '</strong><small>' + escapeHtml(t('reasonReportHint')) + '</small></span>'
            + '<span class="product-statistics-reason-total">' + escapeHtml((t('reasonReportTotal') || '').replace(':count', formatNumber(refundReasonReport.record_count)).replace(':amount', formatMoney(refundReasonReport.amount))) + '</span>'
            + '<span class="product-statistics-reason-toggle-label"><span>' + escapeHtml(actionLabel) + '</span><i class="fa ' + (expanded ? 'fa-angle-up' : 'fa-angle-down') + '" aria-hidden="true"></i></span>'
            + '</button></header>'
            + '<div id="product-statistics-reason-report-content" class="product-statistics-reason-panels"' + (expanded ? '' : ' hidden') + '>'
            + reasonReportPanel(t('reasonReportSource'), refundReasonReport.source_reason || [])
            + reasonReportPanel(t('reasonReportManual'), refundReasonReport.manual_reason || [])
            + '</div>';
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
        Array.prototype.slice.call(document.querySelectorAll('.product-statistics-check')).forEach(function (checkbox) {
            checkbox.checked = Boolean(state.selected[checkbox.value]);
        });
        if (checkAll) {
            checkAll.checked = products.length > 0 && selected.length === products.length;
            checkAll.indeterminate = selected.length > 0 && !checkAll.checked;
            checkAll.disabled = products.length === 0;
        }
        if (exportSelectedCount) exportSelectedCount.textContent = (t('selectedCount') || '').replace(':count', selected.length);
        if (selectedCountLabel) selectedCountLabel.textContent = (t('selectedCount') || '').replace(':count', selected.length);
        if (retryTongtuButton) retryTongtuButton.disabled = selected.length === 0;
        if (exportFilteredCount) exportFilteredCount.textContent = (t('filteredCount') || '').replace(':products', Number(overallSummary.product_count) || 0).replace(':variants', 0);
        if (exportSelectedScope && exportFilteredScope) {
            exportSelectedScope.disabled = selected.length === 0;
            if (!selected.length && exportSelectedScope.checked) exportFilteredScope.checked = true;
        }
    }
    function renderSortIndicators() {
        Array.prototype.slice.call(document.querySelectorAll('.product-statistics-sort')).forEach(function (button) {
            var icon = button.querySelector('.product-statistics-sort-indicator');
            if (!icon) return;
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
        renderReasonReport();
        renderSortIndicators();
        body.innerHTML = products.map(parentRow).join('');
        empty.hidden = products.length > 0;
        document.getElementById('product-statistics-table').classList.toggle('is-empty', products.length === 0);
        renderPagination();
        refreshSelection();
    }
    function findProduct(id) { return products.filter(function (product) { return product.id === id; })[0] || null; }
    function detailRequestUrl(productId, tab) {
        var url = new URL(config.detailUrl || window.location.href, window.location.origin);
        Array.prototype.slice.call(filterForm.querySelectorAll('input[name], select[name]')).forEach(function (field) {
            if (field.name && field.value !== '') url.searchParams.set(field.name, field.value);
        });
        url.searchParams.set('product_id', productId);
        url.searchParams.set('detail_tab', tab || detailState.activeTab || 'overview');
        if ((tab || detailState.activeTab) !== 'overview') {
            var table = tab || detailState.activeTab;
            var sorting = detailState.sorts[table] || {};
            var filters = detailFilter(table);
            url.searchParams.set('detail_page', detailPage(table));
            url.searchParams.set('detail_per_page', detailPerPage(table));
            if (sorting.key) url.searchParams.set('detail_sort', sorting.key);
            if (sorting.direction) url.searchParams.set('detail_direction', sorting.direction);
            ['keyword', 'status', 'type', 'outcome', 'payment_method', 'logistics_status', 'source_reason', 'manual_reason'].forEach(function (key) {
                if (filters[key]) url.searchParams.set('detail_' + key, filters[key]);
            });
        }
        return url.toString();
    }
    function detailTabElement(tab) {
        return document.getElementById('product-statistics-' + String(tab || 'overview').replace(/_/g, '-'));
    }
    function showDetailTabLoading(tab) {
        var container = detailTabElement(tab);
        if (!container) return;
        container.innerHTML = '<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> ' + escapeHtml(t('detailLoading')) + '</div>';
    }
    function clearDeferredDetailTabs() {
        ['paypal_orders', 'credit_card_orders', 'refunds', 'disputes', 'orders'].forEach(function (tab) {
            var container = detailTabElement(tab);
            if (container) container.innerHTML = '';
        });
    }
    function openProductDetail(product) {
        detailState = { product: product, overviewLoaded: false, loadingTabs: {}, tabData: {}, sorts: {}, filters: {}, pages: {}, perPages: {}, activeTab: 'overview' };
        document.getElementById('product-statistics-detail-title').textContent = t('detailTitle');
        document.getElementById('product-statistics-detail-identity').innerHTML = productThumb(product) + '<div><strong>' + escapeHtml(product.name) + '</strong><span>' + escapeHtml(productIdentity(product)) + '</span></div>';
        showDetailTabLoading('overview');
        clearDeferredDetailTabs();
        document.getElementById('product-statistics-detail-filters').hidden = true;
        if ($) {
            $('#product-statistics-detail-modal .nav-tabs a[href="#product-statistics-overview"]').tab('show');
            $('#product-statistics-detail-modal').modal('show');
        }
        ensureDetailLoaded(true);
    }
    function showDetailLoadFailure(tab, message) {
        var container = detailTabElement(tab);
        if (!container) return;
        container.innerHTML = '<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> ' + escapeHtml(message || t('detailLoadFailed')) + '</div>';
    }
    function ensureDetailLoaded(force) {
        if (!detailState.product) return;
        var tab = detailState.activeTab || 'overview';
        if (!force && (tab === 'overview' ? detailState.overviewLoaded : detailState.tabData[tab])) {
            renderDetailFilters();
            renderDetailTables();
            return;
        }
        if (detailState.loadingTabs[tab]) {
            showDetailTabLoading(tab);
            return;
        }
        detailState.loadingTabs[tab] = true;
        document.getElementById('product-statistics-detail-filters').hidden = true;
        showDetailTabLoading(tab);
        window.fetch(detailRequestUrl(detailState.product.id, tab), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (payload) {
                if (!response.ok || payload.tab !== tab) throw new Error(payload.message || t('detailLoadFailed'));
                return payload;
            });
        }).then(function (payload) {
            if (tab === 'overview') {
                if (!payload.product) throw new Error(t('detailLoadFailed'));
                detailState.product = payload.product;
                detailState.overviewLoaded = true;
                renderOverview(payload.product);
            } else {
                if (!payload.detail) throw new Error(t('detailLoadFailed'));
                detailState.tabData[tab] = payload.detail;
            }
            detailState.loadingTabs[tab] = false;
            renderDetailFilters();
            renderDetailTables();
        }).catch(function (error) {
            detailState.loadingTabs[tab] = false;
            showDetailLoadFailure(tab, error && error.message ? error.message : t('detailLoadFailed'));
        });
    }
    function openImagePreview(trigger) {
        if (!trigger.dataset.imageUrl) return;
        document.getElementById('product-statistics-image-preview').src = trigger.dataset.imageUrl;
        document.getElementById('product-statistics-image-preview').alt = trigger.dataset.imageName || '';
        document.getElementById('product-statistics-image-name').textContent = trigger.dataset.imageName || '';
        if ($) $('#product-statistics-image-modal').modal('show');
    }
    function detailMetric(label, value, className) { return '<div class="product-statistics-detail-metric ' + (className || '') + '"><span>' + escapeHtml(label) + '</span><strong>' + value + '</strong></div>'; }
    function replaceTokens(template, values) {
        return Object.keys(values || {}).reduce(function (result, key) {
            return result.split(':' + key).join(String(values[key]));
        }, String(template || ''));
    }
    function overviewMetric(label, value, formula, className) {
        return '<div class="product-statistics-overview-metric ' + (className || '') + '"><span>' + escapeHtml(label) + '</span><strong>' + value + '</strong>' + (formula ? '<small>' + escapeHtml(formula) + '</small>' : '') + '</div>';
    }
    function overviewDisputes(subject) {
        if (!subject.dispute_applicable) {
            return '<div class="product-statistics-overview-disputes is-not-applicable"><i class="fa fa-info-circle"></i> ' + escapeHtml(t('overviewDisputeNotApplicable')) + '</div>';
        }
        var disputes = Array.isArray(subject.dispute_reason_breakdown) ? subject.dispute_reason_breakdown : [];
        var summary = replaceTokens(t('overviewDisputeCounts'), {
            orders: formatNumber(subject.dispute_order_count),
            records: formatNumber(subject.dispute_count),
            amount: formatMoney(subject.dispute_amount)
        });
        var rows = disputes.length ? disputes.map(function (dispute) {
            return '<li>' + escapeHtml(replaceTokens(t('overviewDisputeReason'), {
                reason: dispute.reason || blank(),
                count: formatNumber(dispute.count),
                ratio: formatRate(dispute.ratio).replace('%', ''),
                amount: formatMoney(dispute.amount)
            })) + '</li>';
        }).join('') : '<li>' + escapeHtml(blank()) + '</li>';

        return '<div class="product-statistics-overview-disputes"><div class="product-statistics-overview-disputes-heading"><span>' + escapeHtml(t('overviewDisputeSummary')) + '</span></div><p class="product-statistics-overview-disputes-summary">' + escapeHtml(summary) + '</p><h5>' + escapeHtml(t('overviewDisputeBreakdown')) + '</h5><ul>' + rows + '</ul><small class="product-statistics-overview-dispute-formula">' + escapeHtml(t('overviewDisputeFormula')) + '</small></div>';
    }
    function overviewCreditCardReasonSummary(summary, kind) {
        summary = summary || {};
        var reasons = Array.isArray(summary.reason_breakdown) ? summary.reason_breakdown : [];
        var title = kind === 'alert' ? t('overviewCreditCardAlertSummary') : t('overviewCreditCardDisputeSummary');
        var counts = replaceTokens(t('overviewCreditCardRecordCounts'), {
            orders: formatNumber(summary.order_count),
            records: formatNumber(summary.record_count),
            amount: formatMoney(summary.amount)
        });
        var rows = reasons.length ? reasons.map(function (reason) {
            return '<li>' + escapeHtml(replaceTokens(t('overviewCreditCardReason'), {
                reason: reason.reason || blank(),
                count: formatNumber(reason.count),
                ratio: formatRate(reason.ratio).replace('%', ''),
                amount: formatMoney(reason.amount)
            })) + '</li>';
        }).join('') : '<li>' + escapeHtml(blank()) + '</li>';

        return '<div class="product-statistics-overview-disputes is-credit-card-' + kind + '"><div class="product-statistics-overview-disputes-heading"><span>' + escapeHtml(title) + '</span></div><p class="product-statistics-overview-disputes-summary">' + escapeHtml(counts) + '</p><h5>' + escapeHtml(t('overviewCreditCardReasonBreakdown')) + '</h5><ul>' + rows + '</ul><small class="product-statistics-overview-dispute-formula">' + escapeHtml(t('overviewCreditCardReasonFormula')) + '</small></div>';
    }
    function overviewCreditCardReasonSummaries(subject) {
        return '<div class="product-statistics-credit-card-record-summaries">'
            + overviewCreditCardReasonSummary(subject.alert_record_summary, 'alert')
            + overviewCreditCardReasonSummary(subject.dispute_record_summary, 'dispute')
            + '</div>';
    }
    function overviewModule(title, subject, className) {
        subject = subject || {};
        var received = formatMoney(subject.received_amount);
        var refund = formatMoney(subject.refund_amount);
        var refundFormula = replaceTokens(t('overviewRefundFormula'), { refund: refund, received: received });
        var shippingFormula = replaceTokens(t('overviewShippingFormula'), {
            shipped: formatNumber(subject.shipping_count),
            orders: formatNumber(subject.order_count)
        });
        var deliveryFormula = replaceTokens(t('overviewDeliveryFormula'), {
            delivered: formatNumber(subject.delivery_count),
            shipped: formatNumber(subject.shipping_count)
        });
        var disputeRateFormula = replaceTokens(t('overviewDisputeRateFormula'), {
            disputed: formatNumber(subject.dispute_order_count),
            orders: formatNumber(subject.order_count)
        });
        var chargebackRateFormula = replaceTokens(t('overviewChargebackRateFormula'), {
            amount: formatMoney(subject.chargeback_amount),
            received: received
        });
        var customerServiceRefundRateFormula = replaceTokens(t('overviewCustomerServiceRefundRateFormula'), {
            amount: formatMoney(subject.customer_service_refund_amount),
            received: received
        });
        var creditCardDisputeRateFormula = replaceTokens(t('overviewCreditCardDisputeRateFormula'), {
            count: formatNumber(subject.credit_card_dispute_count),
            orders: formatNumber(subject.order_count)
        });

        return '<section class="product-statistics-overview-module ' + className + '"><h4>' + escapeHtml(title) + '</h4><div class="product-statistics-overview-metrics">'
            + overviewMetric(t('overviewUnitCount'), formatNumber(subject.unit_count))
            + overviewMetric(t('overviewOrderCount'), formatNumber(subject.order_count))
            + overviewMetric(t('overviewReceivedAmount'), received)
            + overviewMetric(t('overviewRefundAmount'), refund)
            + overviewMetric(t('overviewRefundRate'), formatRate(subject.refund_rate), refundFormula)
            + (subject.dispute_applicable ? overviewMetric(t('overviewDisputeRate'), formatRate(subject.dispute_rate), disputeRateFormula, 'is-dispute-rate') : '')
            + (subject.chargeback_applicable ? overviewMetric(t('overviewChargebackRate'), formatRate(subject.chargeback_rate), chargebackRateFormula, 'is-chargeback-rate') : '')
            + (subject.chargeback_applicable ? overviewMetric(t('overviewCustomerServiceRefundRate'), formatRate(subject.customer_service_refund_rate), customerServiceRefundRateFormula, 'is-customer-service-refund-rate') : '')
            + (subject.chargeback_applicable ? overviewMetric(t('overviewCreditCardDisputeRate'), formatRate(subject.credit_card_dispute_rate), creditCardDisputeRateFormula, 'is-credit-card-dispute-rate') : '')
            + overviewMetric(t('overviewShippingRate'), formatRate(subject.shipping_rate), shippingFormula)
            + overviewMetric(t('overviewDeliveryRate'), formatRate(subject.delivery_rate), deliveryFormula)
            + '</div>' + (subject.chargeback_applicable ? overviewCreditCardReasonSummaries(subject) : overviewDisputes(subject)) + '</section>';
    }
    function detailStoreOrderDistribution(subject) {
        var stores = Array.isArray(subject.store_order_distribution) ? subject.store_order_distribution : [];
        if (!stores.length) return '';
        return '<div class="product-statistics-store-order-distribution"><h5>' + escapeHtml(t('storeOrderDistribution')) + '</h5><div class="product-statistics-store-order-list">'
            + stores.map(function (store) {
                return '<div class="product-statistics-store-order"><strong>' + escapeHtml(store.name || blank()) + '</strong><span>' + formatNumber(store.order_count) + ' ' + escapeHtml(t('orders')) + '</span></div>';
            }).join('')
            + '</div></div>';
    }
    function detailSortButton(table, key, label) {
        var current = detailState.sorts[table] || {};
        var active = current.key === key;
        var icon = active ? (current.direction === 'asc' ? 'fa-sort-asc' : 'fa-sort-desc') : 'fa-sort';
        return '<button type="button" class="product-statistics-detail-sort ' + (active ? 'is-active' : '') + '" data-detail-table="' + escapeHtml(table) + '" data-detail-sort="' + escapeHtml(key) + '">' + escapeHtml(label) + ' <i class="fa ' + icon + '"></i></button>';
    }
    function detailFilter(table) {
        if (!detailState.filters[table]) detailState.filters[table] = {};
        return detailState.filters[table];
    }
    function detailPage(table) { return Number(detailState.pages[table] || 1); }
    function detailPerPage(table) {
        var perPage = Number((detailState.perPages || {})[table] || 10);
        return [5, 10, 20].indexOf(perPage) === -1 ? 10 : perPage;
    }
    function detailSelect(name, label, values, selected) {
        if (!values.length) return '';
        return '<label><span>' + escapeHtml(label) + '</span><select class="form-control input-sm" name="' + escapeHtml(name) + '"><option value="">' + escapeHtml(t('detailFilterAll')) + '</option>' + values.map(function (item) {
            var value = typeof item === 'object' ? String(item.value || '') : String(item || '');
            var text = typeof item === 'object' ? String(item.label || item.value || '') : value;
            return '<option value="' + escapeHtml(value) + '"' + (String(selected || '') === value ? ' selected' : '') + '>' + escapeHtml(text) + '</option>';
        }).join('') + '</select></label>';
    }
    function detailPagination(table, pageData) {
        pageData = pageData || {};
        var currentPage = Number(pageData.current_page) || 1;
        var lastPage = Number(pageData.last_page) || 1;
        var pages = [];
        var start = Math.max(1, currentPage - 2);
        var end = Math.min(lastPage, start + 4);
        start = Math.max(1, end - 4);
        for (var page = start; page <= end; page++) {
            pages.push('<button type="button" class="btn btn-default btn-sm product-statistics-detail-page' + (page === currentPage ? ' is-active' : '') + '" data-detail-table="' + escapeHtml(table) + '" data-detail-page="' + page + '">' + page + '</button>');
        }
        var summary = Number(pageData.total) ? (t('detailPageSummary') || '').replace(':from', pageData.from).replace(':to', pageData.to).replace(':total', pageData.total) : (t('detailPageEmpty') || '');
        var perPage = detailPerPage(table);
        return '<div class="product-statistics-detail-pagination">'
            + '<span class="product-statistics-detail-page-summary">' + escapeHtml(summary) + '</span>'
            + '<div class="product-statistics-detail-page-controls">'
            + '<button type="button" class="btn btn-default btn-sm product-statistics-detail-page" data-detail-table="' + escapeHtml(table) + '" data-detail-page="' + Math.max(1, currentPage - 1) + '" aria-label="' + escapeHtml(t('previousPage')) + '"' + (currentPage <= 1 ? ' disabled' : '') + '><i class="fa fa-angle-left"></i></button>'
            + pages.join('')
            + '<button type="button" class="btn btn-default btn-sm product-statistics-detail-page" data-detail-table="' + escapeHtml(table) + '" data-detail-page="' + Math.min(lastPage, currentPage + 1) + '" aria-label="' + escapeHtml(t('nextPage')) + '"' + (currentPage >= lastPage ? ' disabled' : '') + '><i class="fa fa-angle-right"></i></button>'
            + '</div>'
            + '<label class="product-statistics-detail-page-size"><span>' + escapeHtml(t('perPage')) + '</span><select class="form-control input-sm product-statistics-detail-page-size-select" data-detail-table="' + escapeHtml(table) + '"><option value="5"' + (perPage === 5 ? ' selected' : '') + '>5</option><option value="10"' + (perPage === 10 ? ' selected' : '') + '>10</option><option value="20"' + (perPage === 20 ? ' selected' : '') + '>20</option></select><span>' + escapeHtml(t('items')) + '</span></label>'
            + '</div>';
    }
    function renderDetailFilters() {
        var container = document.getElementById('product-statistics-detail-filters');
        var product = detailState.product;
        var table = detailState.activeTab;
        var payload = detailState.tabData[table];
        if (!container || !product || !payload || table === 'overview') { if (container) container.hidden = true; return; }
        var filters = detailFilter(table);
        var options = payload.options || {};
        var placeholder = table === 'refunds' ? t('detailFilterRefundsPlaceholder') : (table === 'disputes' ? t('detailFilterDisputesPlaceholder') : t('detailFilterOrdersPlaceholder'));
        var selects = '';
        if (table === 'refunds') {
            selects += detailSelect('payment_method', t('paymentMethod'), options.payment_method || [], filters.payment_method);
            selects += detailSelect('status', t('detailFilterStatus'), options.status || [], filters.status);
            selects += detailSelect('source_reason', t('sourceReason'), options.source_reason || [], filters.source_reason);
            selects += detailSelect('manual_reason', t('manualReason'), options.manual_reason || [], filters.manual_reason);
        }
        if (table === 'disputes') {
            selects += detailSelect('type', t('detailFilterType'), options.type || [], filters.type);
            selects += detailSelect('status', t('detailFilterStatus'), options.status || [], filters.status);
            selects += detailSelect('outcome', t('detailFilterOutcome'), options.outcome || [], filters.outcome);
        }
        if (table === 'paypal_orders' || table === 'credit_card_orders' || table === 'orders') selects += detailSelect('logistics_status', t('detailFilterLogisticsStatus'), options.logistics_status || [], filters.logistics_status);
        container.hidden = false;
        container.innerHTML = '<form class="product-statistics-detail-filter-form" data-detail-table="' + escapeHtml(table) + '"><label class="is-keyword"><span>' + escapeHtml(t('detailFilterKeyword')) + '</span><input type="search" class="form-control input-sm" name="keyword" value="' + escapeHtml(filters.keyword || '') + '" placeholder="' + escapeHtml(placeholder) + '"></label>' + selects + '<div class="product-statistics-detail-filter-actions"><button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-filter"></i> ' + escapeHtml(t('detailFilterSearch')) + '</button><button type="button" class="btn btn-default btn-sm product-statistics-detail-filter-reset" data-detail-table="' + escapeHtml(table) + '">' + escapeHtml(t('detailFilterReset')) + '</button></div></form>';
    }
    function detailEmptyRow(columns) { return '<tr><td colspan="' + columns + '" class="text-center text-muted">' + escapeHtml(t('emptyRows') || blank()) + '</td></tr>'; }
    function orderLink(row) {
        var label = escapeHtml(row.shopify_order_number || blank());
        return row.order_id && row.detail_url ? '<button type="button" class="btn btn-link btn-xs js-logistics-order-detail" data-order-detail-url="' + escapeHtml(row.detail_url) + '">' + label + '</button>' : label;
    }
    function orderColumns(table) {
        var columns = [
            ['shopify_order_number', t('orderNumber')], ['store', t('storeName')], ['tongtu_product_codes', t('productCode')], ['tongtu_skus', t('tongtuSku')], ['shopify_skus', t('shopifySku')], ['bill_number', t('billNumber')], ['payment_method', t('paymentMethod')], ['quantity', t('quantity')], ['revenue', t('revenue')]
        ];
        if (table === 'paypal_orders') {
            columns.push(['paypal_refund', t('paypalRefund')], ['paypal_dispute_count', t('paypalDisputes')]);
        } else if (table === 'credit_card_orders') {
            columns.push(['credit_card_refund', t('creditCardRefund')]);
        } else {
            columns.push(['paypal_refund', t('paypalRefund')], ['credit_card_refund', t('creditCardRefund')], ['paypal_dispute_count', t('paypalDisputes')]);
        }
        return columns.concat([['tracking_number', t('trackingNumber')], ['logistics_status', t('logisticsStatus')], ['order_created_at', t('orderCreatedAt')]]);
    }
    function orderCell(order, key) {
        if (key === 'shopify_order_number') return orderLink(order);
        if (key === 'tongtu_product_codes' || key === 'tongtu_skus' || key === 'shopify_skus') return escapeHtml((order[key] || []).join(' / ') || blank());
        if (key === 'quantity' || key === 'paypal_dispute_count') return formatNumber(order[key]);
        if (key === 'revenue' || key === 'paypal_refund' || key === 'credit_card_refund') return formatMoney(order[key]);
        return escapeHtml(order[key] || blank());
    }
    function orderRows(orders, columns) {
        if (!orders.length) return detailEmptyRow(columns.length);
        return orders.map(function (order) {
            return '<tr>' + columns.map(function (column) { return '<td>' + orderCell(order, column[0]) + '</td>'; }).join('') + '</tr>';
        }).join('');
    }
    function renderOrdersTable(id, table, payload) {
        var headings = orderColumns(table);
        var rows = payload.rows || [];
        document.getElementById(id).innerHTML = '<div class="table-responsive product-statistics-detail-table-wrap"><table class="table product-statistics-detail-table"><thead><tr>' + headings.map(function (heading) { return '<th>' + detailSortButton(table, heading[0], heading[1]) + '</th>'; }).join('') + '</tr></thead><tbody>' + orderRows(rows, headings) + '</tbody></table></div>' + detailPagination(table, payload.pagination);
    }
    function refundRows(rows) {
        if (!rows.length) return detailEmptyRow(10);
        return rows.map(function (refund) {
            var disputeText = (refund.linked_disputes || []).join('、') || blank();
            var order = refund.order_id && refund.order_detail_url ? '<button type="button" class="btn btn-link btn-xs js-logistics-order-detail" data-order-detail-url="' + escapeHtml(refund.order_detail_url) + '">' + escapeHtml(refund.shopify_order_number || blank()) + '</button>' : escapeHtml(refund.shopify_order_number || blank());
            return '<tr><td>' + escapeHtml(refund.payment_method_label || blank()) + '</td><td>' + order + '</td><td>' + escapeHtml(refund.bill_number || blank()) + '</td><td>' + escapeHtml(refund.refund_number || blank()) + '</td><td>' + formatMoney(refund.amount) + '</td><td title="' + escapeHtml(refund.source_reason || '') + '">' + escapeHtml(refund.source_reason || blank()) + '</td><td title="' + escapeHtml(refund.manual_reason || '') + '">' + escapeHtml(refund.manual_reason || blank()) + '</td><td>' + escapeHtml(refund.status || blank()) + '</td><td>' + escapeHtml(disputeText) + '</td><td>' + escapeHtml(refund.imported_at || blank()) + '</td></tr>';
        }).join('');
    }
    function disputeRows(rows) {
        if (!rows.length) return detailEmptyRow(11);
        return rows.map(function (dispute) {
            var refunds = (dispute.linked_refunds || []).map(function (refund) { return String(refund.refund_number || '') + ' (' + formatMoney(refund.amount) + ')'; }).join('、') || blank();
            var order = dispute.order_id && dispute.order_detail_url ? '<button type="button" class="btn btn-link btn-xs js-logistics-order-detail" data-order-detail-url="' + escapeHtml(dispute.order_detail_url) + '">' + escapeHtml(dispute.shopify_order_number || blank()) + '</button>' : escapeHtml(dispute.shopify_order_number || blank());
            return '<tr><td>' + escapeHtml(dispute.dispute_id || blank()) + '</td><td>' + order + '</td><td>' + escapeHtml(dispute.bill_number || blank()) + '</td><td>' + formatMoney(dispute.amount) + '</td><td>' + escapeHtml(dispute.reason || blank()) + '</td><td>' + escapeHtml(dispute.stage || blank()) + '</td><td>' + escapeHtml(dispute.status || blank()) + '</td><td>' + escapeHtml(dispute.outcome || blank()) + '</td><td>' + escapeHtml(dispute.seller_response_due_at || blank()) + '</td><td>' + escapeHtml(refunds) + '</td><td>' + escapeHtml(dispute.updated_at || blank()) + '</td></tr>';
        }).join('');
    }
    function renderRefunds(payload) {
        var refundHeadings = [['payment_method_label', t('paymentMethod')], ['shopify_order_number', t('orderNumber')], ['bill_number', t('billNumber')], ['refund_number', t('refundReference')], ['amount', t('amount')], ['source_reason', t('sourceReason')], ['manual_reason', t('manualReason')], ['status', t('status')], ['linked_disputes', t('paypalDisputes')], ['imported_at', t('updatedAt')]];
        var summary = payload.summary || {};
        var summaryHtml = '<section class="product-statistics-refund-selection"><h4>' + escapeHtml(t('reasonSelectionTitle')) + '</h4><div>'
            + '<article><span>' + escapeHtml(t('reasonSelectionCount')) + '</span><strong>' + formatNumber(summary.record_count) + '</strong><small>/ ' + formatNumber(summary.total_record_count) + '</small></article>'
            + '<article><span>' + escapeHtml(t('reasonSelectionAmount')) + '</span><strong>' + formatMoney(summary.amount) + '</strong><small>/ ' + formatMoney(summary.total_amount) + '</small></article>'
            + '<article><span>' + escapeHtml(t('reasonSelectionCountRatio')) + '</span><strong>' + formatRate(summary.count_ratio) + '</strong></article>'
            + '<article><span>' + escapeHtml(t('reasonSelectionAmountRatio')) + '</span><strong>' + formatRate(summary.amount_ratio) + '</strong></article>'
            + '</div></section>';
        document.getElementById('product-statistics-refunds').innerHTML = summaryHtml + '<div class="table-responsive product-statistics-detail-table-wrap"><table class="table product-statistics-detail-table"><thead><tr>' + refundHeadings.map(function (heading) { return '<th>' + detailSortButton('refunds', heading[0], heading[1]) + '</th>'; }).join('') + '</tr></thead><tbody>' + refundRows(payload.rows || []) + '</tbody></table></div>' + detailPagination('refunds', payload.pagination);
    }
    function renderDisputes(payload) {
        var disputeHeadings = [['dispute_id', t('paypalDisputes')], ['shopify_order_number', t('orderNumber')], ['bill_number', t('billNumber')], ['amount', t('amount')], ['reason', t('reason')], ['stage', t('disputeType')], ['status', t('status')], ['outcome', t('disputeOutcome')], ['seller_response_due_at', t('responseDueAt')], ['linked_refunds', t('linkedRefunds')], ['updated_at', t('updatedAt')]];
        document.getElementById('product-statistics-disputes').innerHTML = '<div class="table-responsive product-statistics-detail-table-wrap"><table class="table product-statistics-detail-table"><thead><tr>' + disputeHeadings.map(function (heading) { return '<th>' + detailSortButton('disputes', heading[0], heading[1]) + '</th>'; }).join('') + '</tr></thead><tbody>' + disputeRows(payload.rows || []) + '</tbody></table></div>' + detailPagination('disputes', payload.pagination);
    }
    function renderVariants(payload) {
        var variants = payload.rows || [];
        var headings = [
            ['tongtu_sku', t('tongtuSku')], ['tongtu_product_name', t('tongtuProductName')], ['shopify_skus', t('shopifySku')], ['stores', t('storeName')], ['order_count', t('orderCount')], ['sales', t('sales')], ['revenue', t('revenue')], ['paypal_refund_amount', t('paypalRefundAmount')], ['paypal_dispute_count', t('paypalDisputeCount')], ['credit_card_refund_amount', t('creditCardRefundAmount')], ['shipping_rate', t('shippingRate')], ['delivery_rate', t('deliveryRate')]
        ];
        var rows = variants.length ? variants.map(function (variant) {
            return '<tr><td><code>' + escapeHtml(variant.tongtu_sku || blank()) + '</code></td><td>' + escapeHtml(variant.tongtu_product_name || blank()) + '</td><td>' + escapeHtml((variant.shopify_skus || []).join(' / ') || blank()) + '</td><td>' + escapeHtml((variant.stores || []).join(' / ') || blank()) + '</td><td>' + formatNumber(variant.order_count) + '</td><td>' + formatNumber(variant.sales) + '</td><td>' + formatMoney(variant.revenue) + '</td><td>' + formatMoney(variant.paypal_refund_amount) + '</td><td>' + formatNumber(variant.paypal_dispute_count) + '</td><td>' + formatMoney(variant.credit_card_refund_amount) + '</td><td>' + formatRate(variant.shipping_rate) + '</td><td>' + formatRate(variant.delivery_rate) + '</td></tr>';
        }).join('') : detailEmptyRow(headings.length);
        document.getElementById('product-statistics-variants').innerHTML = '<div class="table-responsive product-statistics-detail-table-wrap"><table class="table product-statistics-detail-table"><thead><tr>' + headings.map(function (heading) { return '<th>' + detailSortButton('variants', heading[0], heading[1]) + '</th>'; }).join('') + '</tr></thead><tbody>' + rows + '</tbody></table></div>' + detailPagination('variants', payload.pagination);
    }
    function renderDetailTables() {
        var tab = detailState.activeTab;
        var detail = detailState.tabData[tab];
        if (!detail || tab === 'overview') return;
        if (tab === 'paypal_orders') renderOrdersTable('product-statistics-paypal-orders', 'paypal_orders', detail);
        if (tab === 'credit_card_orders') renderOrdersTable('product-statistics-credit-card-orders', 'credit_card_orders', detail);
        if (tab === 'refunds') renderRefunds(detail);
        if (tab === 'disputes') renderDisputes(detail);
        if (tab === 'orders') renderOrdersTable('product-statistics-orders', 'orders', detail);
    }
    function formatMappingWeight(value) {
        return isNumeric(value) ? formatNumber(value) + ' g' : blank();
    }
    function formatMappingCny(value) {
        return isNumeric(value) ? '¥' + number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 }) : blank();
    }
    function currentPclProductData(product) {
        var rows = Array.isArray(product.current_pcl_product_data) ? product.current_pcl_product_data : [];
        if (!rows.length) {
            return '<section class="product-statistics-current-pcl-data is-empty"><header><i class="fa fa-cubes"></i> ' + escapeHtml(t('currentPclProductData')) + '</header><p>' + escapeHtml(t('currentPclProductDataEmpty')) + '</p></section>';
        }
        var headings = [
            ['tongtu_sku', t('currentSkuData')], ['shopify_sku', t('shopifySkus')], ['product_name', t('tongtuProductName')],
            ['goods_weight_gram', t('productWeight')], ['package_weight_gram', t('packageWeight')],
            ['goods_current_cost_cny', t('currentCostCny')], ['goods_average_cost_cny', t('averageCostCny')], ['package_cost_cny', t('packageCostCny')], ['product_guide_cost_cny', t('guideCostCny')],
            ['product_dimensions', t('productDimensions')], ['package_dimensions', t('packageDimensions')], ['tongtu_updated_at', t('tongtuUpdatedAt')], ['last_synced_at', t('syncedAt')]
        ];
        var body = rows.map(function (row) {
            return '<tr><td><code>' + escapeHtml(row.tongtu_sku || blank()) + '</code></td><td><code>' + escapeHtml(row.shopify_sku || blank()) + '</code></td><td title="' + escapeHtml(row.product_name || '') + '">' + escapeHtml(row.product_name || blank()) + '</td><td>' + formatMappingWeight(row.goods_weight_gram) + '</td><td>' + formatMappingWeight(row.package_weight_gram) + '</td><td>' + formatMappingCny(row.goods_current_cost_cny) + '</td><td>' + formatMappingCny(row.goods_average_cost_cny) + '</td><td>' + formatMappingCny(row.package_cost_cny) + '</td><td>' + formatMappingCny(row.product_guide_cost_cny) + '</td><td>' + escapeHtml(row.product_dimensions || blank()) + '</td><td>' + escapeHtml(row.package_dimensions || blank()) + '</td><td>' + escapeHtml(row.tongtu_updated_at || blank()) + '</td><td>' + escapeHtml(row.last_synced_at || blank()) + '</td></tr>';
        }).join('');
        return '<section class="product-statistics-current-pcl-data"><header><i class="fa fa-cubes"></i> ' + escapeHtml(t('currentPclProductData')) + '</header><div class="table-responsive product-statistics-detail-table-wrap"><table class="table product-statistics-detail-table"><thead><tr>' + headings.map(function (heading) { return '<th>' + escapeHtml(heading[1]) + '</th>'; }).join('') + '</tr></thead><tbody>' + body + '</tbody></table></div></section>';
    }
    function renderOverview(product) {
        var overviewModules = product.overview_modules || {};
        document.getElementById('product-statistics-overview').innerHTML = currentPclProductData(product) + '<div class="product-statistics-overview-modules">'
            + overviewModule(t('overviewOverall'), overviewModules.overall, 'is-overall')
            + overviewModule(t('overviewPayPal'), overviewModules.paypal, 'is-paypal')
            + overviewModule(t('overviewCreditCard'), overviewModules.credit_card, 'is-credit-card')
            + '</div>' + (detailState.overviewLoaded ? detailStoreOrderDistribution(product) : '');
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
        [['date_from', document.getElementById('product-statistics-date-from').value], ['date_to', document.getElementById('product-statistics-date-to').value], ['name', document.getElementById('product-statistics-name').value], ['keyword', document.getElementById('product-statistics-keyword').value], ['store', document.getElementById('product-statistics-store').value], ['match_status', document.getElementById('product-statistics-match-status').value], ['sort', state.sort], ['direction', state.direction]].forEach(function (item) { appendHiddenInput(exportPayload, item[0], item[1]); });
        selectedProductIds().forEach(function (id) { appendHiddenInput(exportPayload, 'selected_ids[]', id); });
    }
    function populateRetryTongtuPayload() {
        if (!retryTongtuPayload) return;
        retryTongtuPayload.innerHTML = '';
        [['date_from', document.getElementById('product-statistics-date-from').value], ['date_to', document.getElementById('product-statistics-date-to').value], ['name', document.getElementById('product-statistics-name').value], ['keyword', document.getElementById('product-statistics-keyword').value], ['store', document.getElementById('product-statistics-store').value], ['match_status', document.getElementById('product-statistics-match-status').value], ['sort', state.sort], ['direction', state.direction]].forEach(function (item) { appendHiddenInput(retryTongtuPayload, item[0], item[1]); });
        selectedProductIds().forEach(function (id) { appendHiddenInput(retryTongtuPayload, 'selected_ids[]', id); });
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
    if (retryTongtuButton && retryTongtuForm) {
        retryTongtuButton.addEventListener('click', function () {
            if (!selectedProductIds().length) return;
            if (!window.confirm(t('retryTongtuSkusConfirm'))) return;
            populateRetryTongtuPayload();
            retryTongtuForm.submit();
        });
    }
    document.addEventListener('submit', function (event) {
        var form = event.target.closest('.product-statistics-detail-filter-form');
        if (!form) return;
        event.preventDefault();
        var table = form.dataset.detailTable;
        var filters = detailFilter(table);
        Array.prototype.slice.call(form.querySelectorAll('[name]')).forEach(function (field) { filters[field.name] = field.value || ''; });
        detailState.pages[table] = 1;
        ensureDetailLoaded(true);
    });
    if ($) {
        $('#product-statistics-detail-modal').on('shown.bs.tab', 'a[data-product-detail-tab]', function (event) {
            detailState.activeTab = event.target.dataset.productDetailTab || 'overview';
            ensureDetailLoaded(false);
        });
    }
    document.addEventListener('click', function (event) {
        var reasonReportToggle = event.target.closest('.product-statistics-reason-toggle');
        if (reasonReportToggle) {
            state.reasonReportExpanded = !state.reasonReportExpanded;
            renderReasonReport();
            return;
        }
        var imagePreview = event.target.closest('.product-statistics-thumb.is-previewable');
        if (imagePreview) { openImagePreview(imagePreview); return; }
        var detailFilterReset = event.target.closest('.product-statistics-detail-filter-reset');
        if (detailFilterReset) {
            var filterTable = detailFilterReset.dataset.detailTable;
            detailState.filters[filterTable] = {};
            detailState.pages[filterTable] = 1;
            ensureDetailLoaded(true);
            return;
        }
        var detailPage = event.target.closest('.product-statistics-detail-page');
        if (detailPage) {
            if (detailPage.disabled) return;
            detailState.pages[detailPage.dataset.detailTable] = Number(detailPage.dataset.detailPage) || 1;
            ensureDetailLoaded(true);
            return;
        }
        var detailSort = event.target.closest('.product-statistics-detail-sort');
        if (detailSort) {
            var table = detailSort.dataset.detailTable;
            var key = detailSort.dataset.detailSort;
            var current = detailState.sorts[table] || {};
            if (current.key !== key) detailState.sorts[table] = { key: key, direction: 'asc' };
            else if (current.direction === 'asc') detailState.sorts[table] = { key: key, direction: 'desc' };
            else detailState.sorts[table] = {};
            detailState.pages[table] = 1;
            ensureDetailLoaded(true);
            return;
        }
        var target = event.target.closest('.product-statistics-view, .product-statistics-page-button, .product-statistics-sort');
        if (!target) return;
        if (target.classList.contains('product-statistics-view')) { var product = findProduct(target.dataset.productId); if (product) openProductDetail(product); return; }
        if (target.classList.contains('product-statistics-page-button')) { navigate({ page: number(target.dataset.page) || 1 }); return; }
        if (target.classList.contains('product-statistics-sort')) {
            var sort = target.dataset.sort;
            var direction = 'desc';
            if (state.sort === sort && state.direction === 'desc') direction = 'asc';
            else if (state.sort === sort && state.direction === 'asc') { sort = ''; direction = ''; }
            navigate({ sort: sort, direction: direction, page: 1 });
        }
    });
    document.addEventListener('change', function (event) {
        var pageSize = event.target.closest('.product-statistics-detail-page-size-select');
        if (!pageSize) return;
        var table = pageSize.dataset.detailTable;
        detailState.perPages[table] = Number(pageSize.value) || 10;
        detailState.pages[table] = 1;
        ensureDetailLoaded(true);
    });
    document.addEventListener('keydown', function (event) {
        if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('.product-statistics-thumb.is-previewable')) {
            event.preventDefault();
            openImagePreview(event.target);
        }
    });
    renderTable();
    bindTableBoundaryScroll();
    if (config.exportValidationError && $) $('#product-statistics-export-modal').modal('show');
}(window, document, window.jQuery));
