(function () {
    'use strict';

    var config = window.__warehouseProductStatisticsConfig || {};
    var modal = document.getElementById('warehouse-product-statistics-variant-modal');
    if (!modal) return;

    var fillModal = document.getElementById('warehouse-product-statistics-fill-modal');
    var jq = window.jQuery || window.$ || null;
    var $modal = jq ? jq(modal) : null;
    var $fillModal = (jq && fillModal) ? jq(fillModal) : null;

    var currentProductId = '';
    var currentProduct = null;
    var fillProductId = '';
    var fillProduct = null;
    var fillRows = [];
    var rejectReasons = config.rejectReasons || {};

    function $(sel, root) { return (root || modal).querySelector(sel); }

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function (c) {
            return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c] || c);
        });
    }

    function csrfToken() {
        var tokenEls = document.getElementsByName('_token');
        for (var i = 0; i < tokenEls.length; i++) {
            if (tokenEls[i].content) return tokenEls[i].content;
        }
        var metaToken = document.querySelector('meta[name="csrf-token"]');
        return metaToken ? (metaToken.getAttribute('content') || '') : '';
    }

    function openModal($m, el) {
        if ($m && typeof $m.modal === 'function') {
            $m.modal('show');
        } else if (el) {
            el.style.display = 'block';
            el.classList.add('in');
        }
    }

    function closeModal($m, el) {
        if ($m && typeof $m.modal === 'function') {
            $m.modal('hide');
            return;
        }
        if (!el) return;
        el.classList.remove('in');
        el.style.display = 'none';
        el.setAttribute('aria-hidden', 'true');
        var bd = document.querySelector('.modal-backdrop');
        if (bd) bd.parentNode && bd.parentNode.removeChild(bd);
        document.body.style.paddingRight = '';
        document.body.classList.remove('modal-open');
    }

    /** 抓取某一页规格数据；detail 接口会顺带调通途刷新重量 */
    function fetchDetailPage(productId, page) {
        var url = config.detailUrl;
        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        var params = new URLSearchParams(window.location.search);
        var qs = new URLSearchParams();
        ['date_from', 'date_to', 'name', 'keyword', 'store', 'decision'].forEach(function (k) {
            if (params.get(k)) qs.set(k, params.get(k));
        });
        qs.set('product_id', productId);
        qs.set('detail_page', String(page || 1));
        qs.set('detail_per_page', '20');

        return fetch(url + sep + qs.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) {
                if (!res.ok) {
                    return res.json().catch(function () { return {}; }).then(function (body) {
                        throw new Error(body.message || '请求失败 (' + res.status + ')');
                    });
                }
                return res.json();
            });
    }

    /** 拉全该产品的所有规格（规格数超过单页上限时按页追加） */
    function fetchAllVariants(productId) {
        var all = [];
        var product = null;

        function walk(page) {
            return fetchDetailPage(productId, page).then(function (data) {
                if (data && data.product) product = data.product;
                var rows = (data && data.detail && data.detail.rows) ? data.detail.rows : [];
                all = all.concat(rows);
                var lastPage = (data && data.detail && data.detail.pagination) ? Number(data.detail.pagination.last_page || 1) : 1;
                if (page < lastPage && page < 5) return walk(page + 1);
                return { product: product, rows: all, weightRefresh: data && data.weight_refresh ? data.weight_refresh : null };
            });
        }

        return walk(1);
    }

    function identityHtml(product) {
        if (!product) return '';
        var html = '';
        if (product.image_url) {
            html += '<img src="' + escapeHtml(product.image_url) + '" class="product-statistics-thumb" alt="" style="margin-right:10px;">';
        }
        html += '<strong>' + escapeHtml(product.name) + '</strong>';
        if (product.product_code) {
            html += ' <code>' + escapeHtml(product.product_code) + '</code>';
        }
        if (product.stores && product.stores.length) {
            html += ' <span class="text-muted">· ' + escapeHtml(product.stores.join('、')) + '</span>';
        }
        return html;
    }

    // ==================== 规格详情（只读） ====================

    function showLoading() {
        var loadingEl = $('#warehouse-variant-detail-loading');
        loadingEl.hidden = false;
        loadingEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> 正在从通途 API 刷新最新重量数据…';
        $('#warehouse-variant-detail-content').hidden = true;
        $('#warehouse-variant-detail-empty').hidden = true;
        $('#warehouse-variant-detail-error').hidden = true;
        setWeightRefreshSummary(null);
    }

    function showError(message) {
        var err = $('#warehouse-variant-detail-error');
        err.textContent = message || '加载失败，请稍后重试';
        err.hidden = false;
        $('#warehouse-variant-detail-loading').hidden = true;
        $('#warehouse-variant-detail-content').hidden = true;
        $('#warehouse-variant-detail-empty').hidden = true;
        setWeightRefreshSummary(null);
    }

    /**
     * 后端 detail 接口会返回 weight_refresh 统计，这里渲染一行绿色/灰色的结果汇总。
     * @param {object|null} stats {refreshed:int, failed:int, not_found:int, skipped:int}
     */
    function setWeightRefreshSummary(stats) {
        var summary = document.getElementById('warehouse-variant-detail-refresh-summary');
        if (!summary) return;
        if (!stats) { summary.hidden = true; summary.innerHTML = ''; return; }

        var html = '';
        if (stats.refreshed > 0) {
            html += '<span class="label label-success" style="margin-right:4px;"><i class="fa fa-check-circle"></i> 已刷新 ' + stats.refreshed + '</span>';
        }
        if (stats.not_found > 0) {
            html += '<span class="label label-default" style="margin-right:4px;">通途未找到 ' + stats.not_found + '</span>';
        }
        if (stats.failed > 0) {
            html += '<span class="label label-danger" style="margin-right:4px;"><i class="fa fa-exclamation-triangle"></i> 失败 ' + stats.failed + '</span>';
        }
        if (stats.refreshed === 0 && stats.not_found === 0 && stats.failed === 0) {
            html = '<span class="text-muted">通途刷新完成，无更新</span>';
        } else if (stats.refreshed > 0) {
            html += '<span class="text-success" style="font-weight:normal;margin-left:6px;">重量已从通途 goodsDetail 同步</span>';
        }
        summary.innerHTML = html;
        summary.hidden = false;
    }

    function weightBadgeCell(variant) {
        var weight = Number(variant.sale_weight_gram);
        if (!isFinite(weight) || weight <= 0) {
            return '<span class="wh-weight-missing" title="通途重量缺失，请核对"><i class="fa fa-exclamation-triangle"></i> 缺失</span>';
        }
        return '<span class="wh-weight-badge" title="通途 goodsDetail 计算值">' + weight.toFixed(2).replace(/\.?0+$/, '') + '</span>';
    }

    function imageCell(variant) {
        if (!variant.product_image_url) {
            return '<span class="text-muted" title="未返回图片">—</span>';
        }
        return '<img src="' + escapeHtml(variant.product_image_url) + '" ' +
            'class="wh-thumb erp-zoomable" alt="" title="点击放大查看" ' +
            'onclick="window.erpZoomOpen && window.erpZoomOpen(this.src); return false;">';
    }

    function setIdentity(product) {
        currentProduct = product || null;
        $('#warehouse-variant-detail-identity').innerHTML = identityHtml(product);
    }

    function buildVariantRow(variant) {
        var tongtuSku = (variant.tongtu_sku || '').trim();
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + imageCell(variant) + '</td>' +
            '<td><span class="wh-code" title="' + escapeHtml(variant.tongtu_product_code || '') + '">' + escapeHtml(variant.tongtu_product_code || '') + '</span></td>' +
            '<td><span class="wh-code" title="' + escapeHtml(tongtuSku) + '">' + escapeHtml(tongtuSku) + '</span></td>' +
            '<td><span class="wh-name">' + escapeHtml(variant.tongtu_product_name || '') + '</span></td>' +
            '<td>' + weightBadgeCell(variant) + '</td>';
        return tr;
    }

    function renderVariants(rows) {
        var body = $('#warehouse-variant-detail-body');
        var empty = $('#warehouse-variant-detail-empty');
        body.innerHTML = '';
        if (!rows || rows.length === 0) {
            empty.hidden = false;
            $('#warehouse-variant-detail-content').hidden = false;
            $('#warehouse-variant-detail-loading').hidden = true;
            return;
        }
        rows.forEach(function (variant) { body.appendChild(buildVariantRow(variant)); });
        empty.hidden = true;
        $('#warehouse-variant-detail-content').hidden = false;
        $('#warehouse-variant-detail-loading').hidden = true;
    }

    function loadVariants(productId) {
        currentProductId = productId;
        showLoading();
        // 先打开弹窗显示 loading，再发请求，避免用户以为没反应
        openModal($modal, modal);

        fetchDetailPage(productId, 1)
            .then(function (data) {
                setIdentity(data && data.product ? data.product : null);
                renderVariants(data && data.detail && data.detail.rows ? data.detail.rows : []);
                if (data && data.weight_refresh) {
                    setWeightRefreshSummary(data.weight_refresh);
                }
            })
            .catch(function (err) {
                showError(err && err.message ? err.message : '加载失败');
            });
    }

    // ==================== 填写异常数据 ====================

    /** 驳回理由按钮组（点击选中，再次点击取消） */
    function reasonPillsHtml() {
        var html = '';
        Object.keys(rejectReasons).forEach(function (code) {
            html += '<button type="button" class="wh-reason-pill" data-value="' + escapeHtml(code) + '">' +
                escapeHtml(rejectReasons[code]) + '</button>';
        });
        return html;
    }

    function refWeightHtml(variant) {
        var weight = Number(variant.sale_weight_gram);
        if (!isFinite(weight) || weight <= 0) {
            return '<span class="wh-fill-ref is-missing">通途重量: <strong>缺失</strong></span>';
        }
        return '<span class="wh-fill-ref">通途重量: <strong>' + weight.toFixed(2).replace(/\.?0+$/, '') + ' g</strong></span>';
    }

    function buildFillRow(variant) {
        var tongtuSku = (variant.tongtu_sku || '').trim();
        var item = document.createElement('div');
        item.className = 'wh-fill-item';
        item.setAttribute('data-sku', tongtuSku);
        item.innerHTML =
            '<div class="wh-fill-item-head">' +
                '<span class="wh-fill-sku-tag" title="' + escapeHtml(tongtuSku) + '">' + escapeHtml(tongtuSku || '—') + '</span>' +
                (variant.tongtu_product_code ? '<span class="wh-code">' + escapeHtml(variant.tongtu_product_code) + '</span>' : '') +
                refWeightHtml(variant) +
                '<input type="number" step="0.01" min="0.01" class="form-control input-sm wh-fill-weight" placeholder="填写实测重量 g">' +
            '</div>' +
            '<div class="wh-fill-item-meta">' +
                '<span class="wh-fill-label">驳回理由</span>' +
                reasonPillsHtml() +
                '<input type="text" class="form-control input-sm wh-fill-remark" maxlength="500" placeholder="备注（可选）">' +
            '</div>';
        return item;
    }

    function showFillLoading() {
        document.getElementById('warehouse-fill-loading').hidden = false;
        document.getElementById('warehouse-fill-loading').innerHTML = '<i class="fa fa-spinner fa-spin"></i> 正在从通途 API 刷新最新重量数据…';
        document.getElementById('warehouse-fill-content').hidden = true;
        document.getElementById('warehouse-fill-empty').hidden = true;
        document.getElementById('warehouse-fill-error').hidden = true;
        document.getElementById('warehouse-fill-status').innerHTML = '';
        document.getElementById('warehouse-fill-identity').innerHTML = '';
        var submitBtn = document.getElementById('warehouse-fill-submit');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fa fa-check"></i> 提交';
    }

    function showFillError(message) {
        var err = document.getElementById('warehouse-fill-error');
        err.textContent = message || '加载失败，请稍后重试';
        err.hidden = false;
        document.getElementById('warehouse-fill-loading').hidden = true;
        document.getElementById('warehouse-fill-content').hidden = true;
    }

    function renderFillRows(rows) {
        var body = document.getElementById('warehouse-fill-body');
        var empty = document.getElementById('warehouse-fill-empty');
        body.innerHTML = '';
        fillRows = rows || [];
        if (fillRows.length === 0) {
            empty.hidden = false;
            document.getElementById('warehouse-fill-content').hidden = false;
            document.getElementById('warehouse-fill-loading').hidden = true;
            return;
        }
        fillRows.forEach(function (variant) { body.appendChild(buildFillRow(variant)); });
        empty.hidden = true;
        document.getElementById('warehouse-fill-content').hidden = false;
        document.getElementById('warehouse-fill-loading').hidden = true;
    }

    function loadFill(productId) {
        fillProductId = productId;
        fillProduct = null;
        fillRows = [];
        showFillLoading();
        openModal($fillModal, fillModal);

        fetchAllVariants(productId)
            .then(function (result) {
                fillProduct = result.product || null;
                document.getElementById('warehouse-fill-identity').innerHTML = identityHtml(fillProduct);
                renderFillRows(result.rows || []);
            })
            .catch(function (err) {
                showFillError(err && err.message ? err.message : '加载失败');
            });
    }

    function submitFill() {
        var body = document.getElementById('warehouse-fill-body');
        var statusEl = document.getElementById('warehouse-fill-status');
        var submitBtn = document.getElementById('warehouse-fill-submit');
        var rows = [];
        var invalid = false;

        fillRows.forEach(function (variant, idx) {
            var item = body.children[idx];
            if (!item || invalid) return;
            var weightInput = item.querySelector('.wh-fill-weight');
            var activePill = item.querySelector('.wh-reason-pill.active');
            var remarkInput = item.querySelector('.wh-fill-remark');
            var weightRaw = (weightInput.value || '').trim();
            var reason = activePill ? (activePill.getAttribute('data-value') || '').trim() : '';
            var remark = (remarkInput.value || '').trim();
            if (weightRaw === '' && reason === '' && remark === '') return;

            var weight = parseFloat(weightRaw);
            if (weightRaw !== '' && (!isFinite(weight) || weight <= 0)) {
                weightInput.focus();
                statusEl.innerHTML = '<span class="text-danger">「' + escapeHtml(variant.tongtu_sku) + '」填写的重量需大于 0</span>';
                invalid = true;
                return;
            }

            rows.push({
                tongtu_sku: (variant.tongtu_sku || '').trim(),
                shopify_sku: (variant.shopify_skus || []).join(', '),
                original_weight_gram: Number(variant.sale_weight_gram) || 0,
                corrected_weight_gram: isFinite(weight) && weight > 0 ? weight : null,
                reject_reason: reason,
                remark: remark,
            });
        });

        if (invalid) return;
        if (rows.length === 0) {
            statusEl.innerHTML = '<span class="text-warning">请至少为一个 SKU 填写重量、驳回理由或备注</span>';
            return;
        }

        var payload = {
            product_id: fillProductId,
            tongtu_product_code: (fillRows[0] && fillRows[0].tongtu_product_code) || (fillProduct && fillProduct.product_code) || '',
            tongtu_product_name: (fillRows[0] && fillRows[0].tongtu_product_name) || '',
            product_image_url: (fillRows[0] && fillRows[0].product_image_url) || (fillProduct && fillProduct.image_url) || '',
            rows: rows,
        };

        submitBtn.disabled = true;
        statusEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> 正在提交…';

        fetch(config.fillUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(payload),
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                return { ok: res.ok, status: res.status, data: data };
            });
        }).then(function (result) {
            if (!result.ok) {
                submitBtn.disabled = false;
                statusEl.innerHTML = '<span class="text-danger">提交失败：' + escapeHtml(result.data.message || '未知错误') + '</span>';
                return;
            }
            statusEl.innerHTML = '<span class="text-success"><i class="fa fa-check-circle"></i> ' +
                escapeHtml(result.data.message || '提交成功') + '</span>';
            setTimeout(function () { closeModal($fillModal, fillModal); }, 1200);
        }).catch(function (err) {
            submitBtn.disabled = false;
            statusEl.innerHTML = '<span class="text-danger">网络异常：' + escapeHtml(err.message) + '</span>';
        });
    }

    // ==================== 关闭与事件 ====================

    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') return;
        if (fillModal && fillModal.classList.contains('in')) { closeModal($fillModal, fillModal); return; }
        if (modal.classList.contains('in')) { closeModal($modal, modal); }
    });

    document.addEventListener('click', function (ev) {
        var target = ev.target;
        if (!target || !target.closest) return;

        // ① Modal 关闭兜底：拦截 data-dismiss="modal" 和点击遮罩
        var dismissBtn = target.closest('[data-dismiss="modal"]');
        if (dismissBtn) {
            if (modal.contains(dismissBtn)) { ev.preventDefault(); closeModal($modal, modal); return; }
            if (fillModal && fillModal.contains(dismissBtn)) { ev.preventDefault(); closeModal($fillModal, fillModal); return; }
        }
        if (!modal.contains(target) && !(fillModal && fillModal.contains(target))) {
            var backdrop = document.querySelector('.modal-backdrop');
            if (backdrop && target === backdrop) {
                if (fillModal && fillModal.classList.contains('in')) { closeModal($fillModal, fillModal); return; }
                if (modal.classList.contains('in')) { closeModal($modal, modal); }
                return;
            }
        }

        // ② 列表上的"查看"
        var viewBtn = target.closest('.warehouse-product-statistics-view-btn');
        if (viewBtn) {
            var productId = viewBtn.getAttribute('data-product-id');
            if (!productId) return;
            ev.preventDefault();
            loadVariants(productId);
            return;
        }

        // ③ 列表上的"填写"
        var fillBtn = target.closest('.warehouse-product-statistics-fill-btn');
        if (fillBtn) {
            var fillId = fillBtn.getAttribute('data-product-id');
            if (!fillId) return;
            ev.preventDefault();
            loadFill(fillId);
            return;
        }

        // ④ 驳回理由按钮组：单选切换，再次点击取消
        var reasonPill = target.closest('.wh-reason-pill');
        if (reasonPill) {
            ev.preventDefault();
            var wasActive = reasonPill.classList.contains('active');
            Array.prototype.forEach.call(reasonPill.parentNode.querySelectorAll('.wh-reason-pill'), function (pill) {
                pill.classList.remove('active');
            });
            if (!wasActive) reasonPill.classList.add('active');
            return;
        }

        // ⑤ 填写弹窗提交
        if (target.closest('#warehouse-fill-submit')) {
            ev.preventDefault();
            submitFill();
        }
    });
})();
