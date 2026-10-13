(function () {
    'use strict';

    var config = window.ErpPayPalRisk || {};
    var routes = config.routes || {};
    var state = { accountId: null, tab: 'overview', page: 1, lastPayload: null };

    function one(selector, root) { return (root || document).querySelector(selector); }
    function all(selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); }
    function route(template, id) { return String(template || '').replace('__ID__', String(id)); }
    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function money(value) { return '$' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function rate(value) { return Number(value || 0).toFixed(2) + '%'; }
    function integer(value) { return Number(value || 0).toLocaleString('zh-CN'); }
    function showModal(selector) { if (window.jQuery) { window.jQuery(selector).modal('show'); } }

    function initAccountModal() {
        var form = one('#paypal-account-form');
        if (!form) return;
        var method = one('#paypal-account-method');
        var title = one('#paypal-account-modal-title');
        all('[data-paypal-account-create]').forEach(function (button) {
            button.addEventListener('click', function () {
                form.reset();
                form.action = routes.store;
                method.disabled = true;
                method.value = 'POST';
                title.innerHTML = '<i class="fa fa-paypal"></i> 新增PayPal账号';
                one('#paypal-account-enabled').checked = true;
                showModal('#paypal-account-modal');
            });
        });

        all('[data-paypal-account-edit]').forEach(function (button) {
            button.addEventListener('click', function () {
                form.reset();
                form.action = route(routes.update, button.dataset.id);
                method.disabled = false;
                method.value = 'PUT';
                title.innerHTML = '<i class="fa fa-paypal"></i> 编辑PayPal账号';
                one('#paypal-account-name').value = button.dataset.name || '';
                one('#paypal-account-merchant').value = button.dataset.merchant || '';
                one('#paypal-account-email').value = button.dataset.email || '';
                one('#paypal-account-environment').value = button.dataset.environment || 'live';
                one('#paypal-account-enabled').checked = button.dataset.enabled === '1';
                showModal('#paypal-account-modal');
            });
        });
    }

    function detailUrl(tab, page) {
        var params = new URLSearchParams({
            tab: tab,
            page: String(page || 1),
            date_from: (config.filters || {}).date_from || '',
            date_to: (config.filters || {}).date_to || ''
        });
        return route(routes.detail, state.accountId) + '?' + params.toString();
    }

    function setDetailLoading(loading, message) {
        var loadingBox = one('#paypal-detail-loading');
        var content = one('#paypal-detail-content');
        var error = one('#paypal-detail-error');
        loadingBox.hidden = !loading;
        content.hidden = loading || Boolean(message);
        error.hidden = !message;
        error.textContent = message || '';
    }

    function riskLabel(risk) { return ({ low: '低风险', medium: '中风险', high: '高风险' })[risk] || risk || '—'; }

    function renderDetailSummary(summary) {
        one('#paypal-detail-subtitle').textContent = summary.account_name + ' · ' + summary.account_email + ' · Merchant ID ' + summary.merchant_id;
        one('#paypal-detail-summary').innerHTML = [
            '<div class="score"><small>安全指数 · ' + escapeHtml(riskLabel(summary.risk)) + '</small><strong>' + Number(summary.score || 0).toFixed(1) + '</strong></div>',
            '<div><small>支付订单</small><strong>' + integer(summary.order_count) + '</strong></div>',
            '<div><small>实收金额（USD）</small><strong>' + money(summary.paid_amount) + '</strong></div>',
            '<div><small>退款率</small><strong>' + rate(summary.refund_rate) + '</strong></div>',
            '<div><small>纠纷率</small><strong>' + rate(summary.dispute_rate) + '</strong></div>'
        ].join('');
    }

    function renderOverview(payload) {
        var factors = (payload.risk_parts || []).map(function (factor) {
            return '<div class="paypal-risk-factor tone-' + escapeHtml(factor.tone) + '"><div><span>' + escapeHtml(factor.label) + '</span><strong>' + Number(factor.value || 0).toFixed(1) + ' 分</strong></div><div class="bar"><i style="width:' + Math.min(100, Number(factor.value || 0)) + '%"></i></div></div>';
        }).join('');
        var exceptions = payload.exceptions || [];
        var rows = exceptions.map(function (row) {
            return '<tr><td>' + escapeHtml(row.happened_at) + '</td><td>' + escapeHtml(row.store_name) + '</td><td>' + escapeHtml(row.order_number) + '</td><td><span class="status-tag ' + (row.type === '纠纷' ? 'danger' : '') + '">' + escapeHtml(row.type) + '</span></td><td>' + money(row.amount) + '</td><td class="text-left">' + escapeHtml(row.reason) + '</td><td>' + escapeHtml(row.status) + '</td></tr>';
        }).join('');
        if (!rows) rows = '<tr><td colspan="7">当前时间范围内没有退款或纠纷异常。</td></tr>';

        one('#paypal-detail-body').innerHTML = '<div class="paypal-risk-overview-grid">' +
            '<section class="paypal-risk-panel"><div class="paypal-risk-panel-heading"><span>订单风险趋势</span><small><i style="color:#2384c6">●</i> 订单　<i style="color:#e39a2d">●</i> 退款　<i style="color:#d34b54">●</i> 纠纷</small></div><div class="paypal-risk-panel-body"><div class="paypal-risk-chart-wrap"><canvas id="paypal-risk-trend-chart"></canvas></div></div></section>' +
            '<section class="paypal-risk-panel"><div class="paypal-risk-panel-heading"><span>风险因子拆解</span><small>扣分贡献</small></div><div class="paypal-risk-panel-body">' + factors + '</div></section>' +
            '<section class="paypal-risk-panel paypal-risk-exceptions"><div class="paypal-risk-panel-heading"><span>最近异常记录</span><small>最多显示5条</small></div><div class="table-responsive"><table class="paypal-risk-data-table"><thead><tr><th>发生时间</th><th>店铺</th><th>订单号</th><th>类型</th><th>金额</th><th>原因</th><th>状态</th></tr></thead><tbody>' + rows + '</tbody></table></div></section>' +
            '</div>';
        one('#paypal-detail-pagination').innerHTML = '';
        window.requestAnimationFrame(function () { drawTrend(payload.trend || []); });
    }

    function drawTrend(data) {
        var canvas = one('#paypal-risk-trend-chart');
        if (!canvas) return;
        var wrap = canvas.parentElement;
        var ratio = window.devicePixelRatio || 1;
        var width = Math.max(500, wrap.clientWidth);
        var height = Math.max(220, wrap.clientHeight);
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        var context = canvas.getContext('2d');
        context.scale(ratio, ratio);
        context.clearRect(0, 0, width, height);
        var pad = { left: 38, right: 16, top: 15, bottom: 28 };
        var plotW = width - pad.left - pad.right;
        var plotH = height - pad.top - pad.bottom;
        var max = Math.max(1, data.reduce(function (carry, point) { return Math.max(carry, Number(point.orders || 0), Number(point.refunds || 0), Number(point.disputes || 0)); }, 0));
        context.font = '10px Arial';
        context.textAlign = 'right';
        context.fillStyle = '#849aae';
        context.strokeStyle = '#e4ebf1';
        context.lineWidth = 1;
        for (var line = 0; line <= 4; line++) {
            var y = pad.top + plotH * line / 4;
            context.beginPath(); context.moveTo(pad.left, y); context.lineTo(width - pad.right, y); context.stroke();
            context.fillText(String(Math.round(max * (4 - line) / 4)), pad.left - 7, y + 3);
        }
        if (!data.length) {
            context.textAlign = 'center'; context.fillText('暂无趋势数据', width / 2, height / 2); return;
        }
        var step = data.length > 1 ? plotW / (data.length - 1) : plotW;
        [
            { key: 'orders', color: '#2384c6' },
            { key: 'refunds', color: '#e39a2d' },
            { key: 'disputes', color: '#d34b54' }
        ].forEach(function (series) {
            context.beginPath();
            context.strokeStyle = series.color;
            context.lineWidth = 2;
            data.forEach(function (point, index) {
                var x = pad.left + (data.length === 1 ? plotW / 2 : index * step);
                var y = pad.top + plotH - Number(point[series.key] || 0) / max * plotH;
                if (index === 0) context.moveTo(x, y); else context.lineTo(x, y);
            });
            context.stroke();
        });
        context.fillStyle = '#849aae'; context.textAlign = 'center';
        var labelEvery = Math.max(1, Math.ceil(data.length / 6));
        data.forEach(function (point, index) {
            if (index % labelEvery !== 0 && index !== data.length - 1) return;
            var x = pad.left + (data.length === 1 ? plotW / 2 : index * step);
            context.fillText(point.label || '', x, height - 8);
        });
    }

    function renderTable(payload, tab) {
        var result = payload.rows || { data: [], meta: {} };
        var rows = result.data || [];
        var columns = {
            orders: [
                ['order_number', '订单号'], ['store_name', '店铺'], ['products', '产品'], ['paid_amount', '实付金额'], ['refund_status', '退款'], ['dispute_status', '纠纷'], ['order_created_at', '下单时间']
            ],
            products: [
                ['product_name', '产品'], ['sku', 'SKU'], ['quantity', '销量'], ['paid_amount', '实收金额'], ['refund_orders', '退款订单'], ['refund_rate', '退款率'], ['dispute_orders', '纠纷订单'], ['dispute_rate', '纠纷率']
            ],
            refunds: [
                ['refund_id', '退款交易号'], ['order_number', '订单号'], ['store_name', '店铺'], ['source', '来源'], ['amount', '金额'], ['reason', '原因'], ['status', '状态'], ['happened_at', '退款时间']
            ],
            disputes: [
                ['dispute_id', '纠纷编号'], ['order_number', '订单号'], ['store_name', '店铺'], ['amount', '金额'], ['reason', '原因'], ['stage', '阶段'], ['status', '状态'], ['happened_at', '发生时间']
            ]
        }[tab] || [];
        var thead = columns.map(function (column) { return '<th>' + escapeHtml(column[1]) + '</th>'; }).join('');
        var tbody = rows.map(function (row) {
            return '<tr>' + columns.map(function (column) {
                var key = column[0];
                var value = row[key];
                if (key === 'paid_amount' || key === 'amount') value = money(value);
                if (key === 'refund_rate' || key === 'dispute_rate') value = rate(value);
                if (tab === 'products' && key === 'product_name') {
                    var media = row.image_url ? '<img src="' + escapeHtml(row.image_url) + '" alt="">' : '<span class="paypal-risk-product-placeholder"><i class="fa fa-cube"></i></span>';
                    return '<td class="text-left"><div class="paypal-risk-product-cell">' + media + '<span>' + escapeHtml(value) + '</span></div></td>';
                }
                var className = (key === 'products' || key === 'reason') ? ' class="text-left"' : '';
                return '<td' + className + '>' + escapeHtml(value == null || value === '' ? '—' : value) + '</td>';
            }).join('') + '</tr>';
        }).join('');
        if (!tbody) tbody = '<tr><td colspan="' + columns.length + '">当前时间范围内暂无记录。</td></tr>';
        one('#paypal-detail-body').innerHTML = '<div class="table-responsive"><table class="paypal-risk-data-table"><thead><tr>' + thead + '</tr></thead><tbody>' + tbody + '</tbody></table></div>';
        renderPager(result.meta || {});
    }

    function renderPager(meta) {
        var container = one('#paypal-detail-pagination');
        if (!container) return;
        if (!meta.total) { container.innerHTML = ''; return; }
        var html = '<span>显示 ' + meta.from + '-' + meta.to + ' 条，共 ' + meta.total + ' 条</span>';
        html += '<button class="btn btn-default btn-sm" type="button" data-paypal-detail-page="' + Math.max(1, meta.current_page - 1) + '" ' + (meta.current_page <= 1 ? 'disabled' : '') + '><i class="fa fa-angle-left"></i></button>';
        for (var page = 1; page <= meta.last_page; page++) {
            if (page === 1 || page === meta.last_page || Math.abs(page - meta.current_page) <= 1) {
                html += '<button class="btn btn-sm ' + (page === meta.current_page ? 'btn-primary' : 'btn-default') + '" type="button" data-paypal-detail-page="' + page + '">' + page + '</button>';
            }
        }
        html += '<button class="btn btn-default btn-sm" type="button" data-paypal-detail-page="' + Math.min(meta.last_page, meta.current_page + 1) + '" ' + (meta.current_page >= meta.last_page ? 'disabled' : '') + '><i class="fa fa-angle-right"></i></button>';
        container.innerHTML = html;
    }

    function loadDetail(tab, page) {
        state.tab = tab || state.tab;
        state.page = page || 1;
        setDetailLoading(true, '');
        fetch(detailUrl(state.tab, state.page), { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                if (!response.ok) return response.json().catch(function () { return {}; }).then(function (body) { throw new Error(body.message || ('请求失败（HTTP ' + response.status + '）')); });
                return response.json();
            })
            .then(function (payload) {
                state.lastPayload = payload;
                renderDetailSummary(payload.summary || {});
                if (state.tab === 'overview') renderOverview(payload); else renderTable(payload, state.tab);
                setDetailLoading(false, '');
            })
            .catch(function (error) { setDetailLoading(false, error.message || '风险明细加载失败。'); });
    }

    function initDetailModal() {
        all('[data-paypal-detail]').forEach(function (button) {
            button.addEventListener('click', function () {
                state.accountId = button.dataset.paypalDetail;
                state.tab = 'overview';
                state.page = 1;
                all('[data-paypal-tab]').forEach(function (link) { link.parentElement.classList.toggle('active', link.dataset.paypalTab === 'overview'); });
                showModal('#paypal-detail-modal');
                loadDetail('overview', 1);
            });
        });
        all('[data-paypal-tab]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                all('[data-paypal-tab]').forEach(function (tab) { tab.parentElement.classList.remove('active'); });
                link.parentElement.classList.add('active');
                loadDetail(link.dataset.paypalTab, 1);
            });
        });
        var pager = one('#paypal-detail-pagination');
        if (pager) pager.addEventListener('click', function (event) {
            var button = event.target.closest('[data-paypal-detail-page]');
            if (button && !button.disabled) loadDetail(state.tab, Number(button.dataset.paypalDetailPage));
        });
    }

    initAccountModal();
    initDetailModal();
})();
