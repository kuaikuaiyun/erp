(function () {
    'use strict';

    var config = window.__warehouseProductStatisticsConfig || {};
    var modal = document.getElementById('warehouse-product-statistics-variant-modal');
    if (!modal) return;

    var currentProductId = '';
    var currentProduct = null;
    var $ = function (sel, root) { return (root || modal).querySelector(sel); };

    // 保留原生 DOM 引用，同时准备 jQuery 对象用于 Bootstrap Modal 插件
    var jq = window.jQuery || window.$ || null;
    var $modal = jq ? jq(modal) : null;

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function (c) {
            return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c] || c);
        });
    }

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
     * null 表示不显示。
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

    function setIdentity(product) {
        currentProduct = product || null;
        var box = $('#warehouse-variant-detail-identity');
        if (!product) { box.innerHTML = ''; return; }
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
        box.innerHTML = html;
    }

    function buildVariantRow(variant, idx) {
        var tongtuSku = (variant.tongtu_sku || '').trim();

        var weight = Number(variant.sale_weight_gram);
        var weightCell;
        if (!isFinite(weight) || weight <= 0) {
            weightCell = '<span class="wh-weight-missing" title="通途重量缺失，请核对"><i class="fa fa-exclamation-triangle"></i> 缺失</span>';
        } else {
            weightCell = '<span class="wh-weight-badge" title="通途 goodsDetail 计算值">' + weight.toFixed(2).replace(/\.?0+$/, '') + '</span>';
        }

        var imageCell;
        if (!variant.product_image_url) {
            imageCell = '<span class="text-muted" title="未返回图片">—</span>';
        } else {
            imageCell = '<img src="' + escapeHtml(variant.product_image_url) + '" ' +
                'class="wh-thumb erp-zoomable" alt="" ' +
                'title="点击放大查看" onclick="window.erpZoomOpen && window.erpZoomOpen(this.src); return false;">';
        }

        var tr = document.createElement('tr');
        tr.setAttribute('data-form-index', String(idx));
        tr.innerHTML =
            '<td>' + imageCell + '</td>' +
            '<td><span class="wh-code" title="' + escapeHtml(variant.tongtu_product_code || '') + '">' + escapeHtml(variant.tongtu_product_code || '') + '</span></td>' +
            '<td><span class="wh-code" title="' + escapeHtml(tongtuSku) + '">' + escapeHtml(tongtuSku) + '</span></td>' +
            '<td><span class="wh-name">' + escapeHtml(variant.tongtu_product_name || '') + '</span></td>' +
            '<td>' + weightCell + '</td>' +
            '<td>' +
                '<button type="button" class="btn btn-warning btn-xs warehouse-report-weight-btn wh-report-btn">' +
                    '<i class="fa fa-exclamation-triangle"></i> 提报' +
                '</button>' +
            '</td>';
        return tr;
    }

    function buildReportFormRow(variant, idx) {
        var tr = document.createElement('tr');
        tr.setAttribute('data-form-index', String(idx));
        tr.hidden = true;
        var colspan = 8;
        var originalWeight = Number(variant.sale_weight_gram);
        var weightHint;
        if (!isFinite(originalWeight) || originalWeight <= 0) {
            weightHint = '通途返回重量为空，请填写正确值';
        } else {
            weightHint = '当前通途重量 ' + originalWeight.toFixed(2).replace(/\.?0+$/, '') + ' g，请填写正确值';
        }

        tr.innerHTML =
            '<td colspan="' + colspan + '" style="background:#fff8e1;border-top:2px solid #f0ad4e;">' +
                '<div class="warehouse-report-form" style="padding:10px 14px;">' +
                    '<div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;">' +
                        '<div style="min-width:140px;">' +
                            '<label class="text-muted" style="font-weight:normal;">通途 SKU</label>' +
                            '<div><code>' + escapeHtml(variant.tongtu_sku || '') + '</code></div>' +
                        '</div>' +
                        '<div style="min-width:180px;">' +
                            '<label class="text-muted" style="font-weight:normal;">规格产品名</label>' +
                            '<div>' + escapeHtml(variant.tongtu_product_name || '') + '</div>' +
                        '</div>' +
                        '<div style="min-width:200px;">' +
                            '<label class="text-muted" style="font-weight:normal;">' + escapeHtml(weightHint) + '</label>' +
                            '<input type="number" step="0.01" min="0.01" class="form-control warehouse-report-corrected-weight" placeholder="填写正确重量 (g)" required>' +
                        '</div>' +
                        '<div style="min-width:220px;flex:1;">' +
                            '<label class="text-muted" style="font-weight:normal;">备注 (可选)</label>' +
                            '<input type="text" class="form-control warehouse-report-remark" maxlength="500" placeholder="描述异常原因或参考值来源">' +
                        '</div>' +
                        '<div style="display:flex;gap:6px;align-items:flex-end;">' +
                            '<button type="button" class="btn btn-success btn-sm warehouse-report-submit-btn"><i class="fa fa-check"></i> 提交</button>' +
                            '<button type="button" class="btn btn-default btn-sm warehouse-report-cancel-btn">取消</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="warehouse-report-status text-muted" style="margin-top:6px;"></div>' +
                '</div>' +
            '</td>';
        return tr;
    }

    function renderVariants(rows) {
        var body = $('#warehouse-variant-detail-body');
        var empty = $('#warehouse-variant-detail-empty');
        body.innerHTML = '';
        body.__variants__ = [];
        if (!rows || rows.length === 0) {
            empty.hidden = false;
            $('#warehouse-variant-detail-content').hidden = false;
            $('#warehouse-variant-detail-loading').hidden = true;
            return;
        }
        rows.forEach(function (variant, idx) {
            body.__variants__.push(variant);
            body.appendChild(buildVariantRow(variant, idx));
            body.appendChild(buildReportFormRow(variant, idx));
        });
        empty.hidden = true;
        $('#warehouse-variant-detail-content').hidden = false;
        $('#warehouse-variant-detail-loading').hidden = true;
    }

    function buildDetailUrl(productId) {
        var url = config.detailUrl;
        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        var params = new URLSearchParams(window.location.search);
        var qs = new URLSearchParams();
        ['date_from', 'date_to', 'name', 'keyword', 'store', 'decision'].forEach(function (k) {
            if (params.get(k)) qs.set(k, params.get(k));
        });
        qs.set('product_id', productId);
        return url + sep + qs.toString();
    }

    function loadVariants(productId) {
        currentProductId = productId;
        showLoading();

        // 先打开 modal（显示 loading spinner），再发 fetch。
        // 这样用户点完按钮立刻看到反馈，不会感觉卡住了。
        if ($modal && typeof $modal.modal === 'function') {
            $modal.modal('show');
        } else {
            modal.style.display = 'block';
            modal.classList.add('in');
        }

        fetch(buildDetailUrl(productId), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) {
                if (!res.ok) {
                    return res.json().catch(function () { return {}; }).then(function (body) {
                        throw new Error(body.message || '请求失败 (' + res.status + ')');
                    });
                }
                return res.json();
            })
            .then(function (data) {
                var product = null;
                if (data && data.product) product = data.product;
                setIdentity(product);
                var rows = data && data.detail && data.detail.rows ? data.detail.rows : [];
                renderVariants(rows);

                // 展示后端通途重量刷新统计
                if (data && data.weight_refresh) {
                    setWeightRefreshSummary(data.weight_refresh);
                }
            })
            .catch(function (err) {
                showError(err && err.message ? err.message : '加载失败');
            });
    }

    function submitReport(formRow, variant) {
        var statusEl = formRow.querySelector('.warehouse-report-status');
        var correctedInput = formRow.querySelector('.warehouse-report-corrected-weight');
        var remarkInput = formRow.querySelector('.warehouse-report-remark');
        var corrected = parseFloat(correctedInput.value);
        if (!corrected || corrected <= 0) {
            statusEl.innerHTML = '<span class="text-danger">请填写大于 0 的正确重量值</span>';
            correctedInput.focus();
            return;
        }

        var shopify = (variant.shopify_skus || []).join(', ');
        var payload = {
            product_id: currentProductId,
            tongtu_sku: variant.tongtu_sku,
            shopify_sku: shopify,
            tongtu_product_code: variant.tongtu_product_code || '',
            tongtu_product_name: variant.tongtu_product_name || '',
            product_image_url: variant.product_image_url || (currentProduct && currentProduct.image_url ? currentProduct.image_url : ''),
            original_weight_gram: Number(variant.sale_weight_gram) || 0,
            corrected_weight_gram: corrected,
            remark: (remarkInput.value || '').trim(),
        };

        statusEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> 正在提交…';
        var tokenEls = document.getElementsByName('_token');
        var csrf = '';
        for (var i = 0; i < tokenEls.length; i++) {
            if (tokenEls[i].content) { csrf = tokenEls[i].content; break; }
        }
        if (!csrf) {
            var metaToken = document.querySelector('meta[name="csrf-token"]');
            if (metaToken) csrf = metaToken.getAttribute('content') || '';
        }

        fetch(config.reportUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(payload),
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) { return { ok: res.ok, status: res.status, data: data }; });
        }).then(function (result) {
            if (!result.ok) {
                statusEl.innerHTML = '<span class="text-danger">提交失败：' + escapeHtml(result.data.message || '未知错误') + '</span>';
                return;
            }
            if (result.data.deduplicated) {
                statusEl.innerHTML = '<span class="text-info"><i class="fa fa-info-circle"></i> ' + escapeHtml(result.data.message || '已在队列中') + '</span>';
            } else {
                statusEl.innerHTML = '<span class="text-success"><i class="fa fa-check-circle"></i> ' + escapeHtml(result.data.message || '提报成功') + '</span>';
            }
            var idx = Number(formRow.getAttribute('data-form-index'));
            var mainRow = formRow.previousElementSibling;
            if (mainRow) {
                var btn = mainRow.querySelector('.warehouse-report-weight-btn');
                if (btn) {
                    btn.disabled = true;
                    btn.className = 'btn btn-default btn-xs';
                    btn.innerHTML = '<i class="fa fa-check"></i> 已提报';
                }
            }
            setTimeout(function () { formRow.hidden = true; }, 1800);
        }).catch(function (err) {
            statusEl.innerHTML = '<span class="text-danger">网络异常：' + escapeHtml(err.message) + '</span>';
        });
    }

    function closeModal() {
        if ($modal && typeof $modal.modal === 'function') {
            $modal.modal('hide');
        } else {
            modal.classList.remove('in');
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            // 手动清理 backdrop（如果之前手动 show 创建了）
            var bd = document.querySelector('.modal-backdrop');
            if (bd) bd.parentNode && bd.parentNode.removeChild(bd);
            // 恢复 body 滚动
            document.body.style.paddingRight = '';
            document.body.classList.remove('modal-open');
        }
    }

    // ESC 键关闭
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && modal.classList.contains('in')) {
            closeModal();
        }
    });

    // ============ 事件委托：全部绑在 document 上 ============

    document.addEventListener('click', function (ev) {
        // ① Modal 关闭兜底：拦截 data-dismiss="modal" 和点击 modal 外部遮罩
        var dismissBtn = ev.target && ev.target.closest && ev.target.closest('[data-dismiss="modal"]');
        var isInsideOurModal = modal.contains(ev.target);
        var isBackdrop = !isInsideOurModal
            && ev.target === document.querySelector('.modal-backdrop')
            && modal.classList.contains('in');
        if (dismissBtn && isInsideOurModal) {
            ev.preventDefault();
            ev.stopPropagation();
            closeModal();
            return;
        }
        if (isBackdrop) {
            closeModal();
            return;
        }

        // ② 列表上的"查看"按钮（在 modal 之外）
        var viewBtn = ev.target && ev.target.closest('.warehouse-product-statistics-view-btn');
        if (viewBtn) {
            var productId = viewBtn.getAttribute('data-product-id');
            if (!productId) return;
            ev.preventDefault();
            $('#warehouse-variant-detail-identity').innerHTML = '';
            loadVariants(productId);
            return;
        }

        // ③ 规格详情里的"提报"按钮：展开表单
        var reportBtn = ev.target && ev.target.closest('.warehouse-report-weight-btn');
        if (reportBtn) {
            ev.preventDefault();
            if (reportBtn.disabled) return;
            var mainRow = reportBtn.closest('[data-form-index]');
            if (!mainRow) return;
            var idx = mainRow.getAttribute('data-form-index');
            var tbody = mainRow.parentElement;
            if (!tbody) return;
            var formRow = tbody.querySelector('[data-form-index="' + idx + '"]');
            if (!formRow || formRow === mainRow) {
                // 可能主行和表单行同 index；formRow 应该是下一行，补定位
                var all = tbody.querySelectorAll('[data-form-index="' + idx + '"]');
                if (all.length >= 2) formRow = all[1];
            }
            if (!formRow) return;
            // 收起其他展开的表单
            tbody.querySelectorAll('.warehouse-report-form').forEach(function (f) {
                var r = f.closest('tr');
                if (r && r !== formRow) r.hidden = true;
            });
            formRow.hidden = !formRow.hidden;
            if (!formRow.hidden) {
                var input = formRow.querySelector('.warehouse-report-corrected-weight');
                if (input) input.focus();
            }
            return;
        }

        // ④ "取消" 按钮
        var cancelBtn = ev.target && ev.target.closest('.warehouse-report-cancel-btn');
        if (cancelBtn) {
            ev.preventDefault();
            var tr = cancelBtn.closest('[data-form-index]');
            if (tr) tr.hidden = true;
            return;
        }

        // ⑤ "提交" 按钮
        var submitBtn = ev.target && ev.target.closest('.warehouse-report-submit-btn');
        if (submitBtn) {
            ev.preventDefault();
            var formTr = submitBtn.closest('[data-form-index]');
            if (!formTr) return;
            var formTbody = formTr.parentElement;
            var formIdx = Number(formTr.getAttribute('data-form-index'));
            var variant = (formTbody && formTbody.__variants__ && formTbody.__variants__[formIdx]) || null;
            if (!variant) {
                formTr.querySelector('.warehouse-report-status').innerHTML = '<span class="text-danger">规格数据缺失，请重新打开详情</span>';
                return;
            }
            submitReport(formTr, variant);
            return;
        }
    });
})();
