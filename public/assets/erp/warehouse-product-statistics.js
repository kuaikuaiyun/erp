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
    var fillPclCode = '';
    var fillRows = [];
    var fillRealImages = [];       // [{ path, url, link_sku_id, index }]
    var fillPasteActive = false;
    var fillPasteTimer = null;
    var rejectReasons = config.rejectReasons || {};
    var IMAGE_MIMES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    var IMAGES_URL = config.linkSkuImagesUrl || '';
    var IMAGE_UPLOAD_URL = config.linkSkuImageUploadUrl || '';
    var IMAGE_DELETE_URL = config.linkSkuImageDeleteUrl || '';

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
        clearFillPaste();
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

    /** 拉全该产品的所有规格（规格数超过单页上限时按页追加）+ 已填异常记录 */
    function fetchAllVariants(productId) {
        var all = [];
        var product = null;
        var existing = null;

        function walk(page) {
            return fetchDetailPage(productId, page).then(function (data) {
                if (data && data.product) product = data.product;
                if (page === 1 && data && data.existing_exceptions) existing = data.existing_exceptions;
                var rows = (data && data.detail && data.detail.rows) ? data.detail.rows : [];
                all = all.concat(rows);
                var lastPage = (data && data.detail && data.detail.pagination) ? Number(data.detail.pagination.last_page || 1) : 1;
                if (page < lastPage && page < 5) return walk(page + 1);
                return { product: product, rows: all, existing: existing || {} };
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
        loadingEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> 正在加载规格数据…';
        $('#warehouse-variant-detail-content').hidden = true;
        $('#warehouse-variant-detail-empty').hidden = true;
        $('#warehouse-variant-detail-error').hidden = true;
    }

    function showError(message) {
        var err = $('#warehouse-variant-detail-error');
        err.textContent = message || '加载失败，请稍后重试';
        err.hidden = false;
        $('#warehouse-variant-detail-loading').hidden = true;
        $('#warehouse-variant-detail-content').hidden = true;
        $('#warehouse-variant-detail-empty').hidden = true;
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
            '<td>' +
                '<div class="wh-cell-label">PCL 编号</div>' +
                '<span class="wh-code" title="' + escapeHtml(variant.tongtu_product_code || '') + '">' + escapeHtml(variant.tongtu_product_code || '—') + '</span>' +
            '</td>' +
            '<td>' +
                '<div class="wh-cell-label">SKU</div>' +
                '<span class="wh-code wh-code-sku" title="' + escapeHtml(tongtuSku) + '">' + escapeHtml(tongtuSku || '—') + '</span>' +
            '</td>' +
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

        // 回填已填异常
        var ex = variant._existing_exception || null;
        var initWeight = ex && ex.corrected_weight_gram != null ? ex.corrected_weight_gram : '';
        var initReasons = ex && ex.reject_reason
            ? String(ex.reject_reason).split(',').map(function (reason) { return reason.trim(); }).filter(Boolean)
            : [];
        var initRemark = ex && ex.remark ? ex.remark : '';
        var initPhysicalUrl = ex && ex.physical_image_url ? ex.physical_image_url : '';
        var exceptionId = ex ? ex.id : '';

        var reasonHtml = '';
        Object.keys(rejectReasons).forEach(function (code) {
            reasonHtml += '<button type="button" class="wh-reason-pill' + (initReasons.indexOf(code) !== -1 ? ' active' : '') + '" data-value="' + escapeHtml(code) + '">' +
                escapeHtml(rejectReasons[code]) + '</button>';
        });

        // 实物图上传区（大虚线框 + 粘贴按钮 + 拖拽，和列表实拍图风格一致）
        var imgSection = '<div class="wh-fill-item-images">' +
            '<label class="wh-upload-zone wh-physical-upload-zone" for="wh-fill-img-' + exceptionId + '-' + escapeHtml(tongtuSku) + '" title="点击选择文件、拖拽图片到此处，或 Ctrl+V 粘贴">' +
                '<i class="fa fa-cloud-upload wh-upload-icon"></i>' +
                '<div class="wh-upload-hint-text">拖拽 / <strong>粘贴图片</strong> / 点击选择</div>' +
                '<button type="button" class="wh-paste-btn wh-fill-physical-paste" data-fill-sku="' + escapeHtml(tongtuSku) + '" data-fill-exception="' + exceptionId + '" title="设为粘贴目标后 Ctrl+V 粘贴">' +
                    '<i class="fa fa-clipboard wh-clip-icon"></i> 粘贴图片 <kbd>Ctrl+V</kbd>' +
                '</button>' +
                '<input type="file" class="wh-fill-physical-input" id="wh-fill-img-' + exceptionId + '-' + escapeHtml(tongtuSku) + '" accept="image/jpeg,image/png,image/webp" style="display:none;">' +
            '</label>' +
            '<div class="wh-fill-physical-preview" data-role="physical-preview">' +
                (initPhysicalUrl !== '' ?
                    '<img src="' + initPhysicalUrl + '" alt="" class="erp-zoomable" style="width:72px;height:72px;object-fit:cover;border:1px solid #dfe6ed;border-radius:4px;cursor:zoom-in;" title="点击放大查看" onclick="window.erpZoomOpen && window.erpZoomOpen(this.src); return false;">' :
                    '<span class="text-muted" style="font-size:12px;">未上传</span>') +
            '</div>' +
            (initPhysicalUrl !== '' ? '<label class="wh-fill-physical-remove"><input type="checkbox" class="wh-fill-physical-remove-checkbox" data-role="remove-physical"> 删除原图</label>' : '') +
        '</div>';

        item.innerHTML =
            '<div class="wh-fill-item-head">' +
                '<div class="wh-fill-head-left">' +
                    '<span class="wh-fill-head-label">PCL</span>' +
                    '<span class="wh-code">' + escapeHtml(variant.tongtu_product_code || '—') + '</span>' +
                    '<span class="wh-fill-head-label">SKU</span>' +
                    '<span class="wh-code wh-code-sku" title="' + escapeHtml(tongtuSku) + '">' + escapeHtml(tongtuSku || '—') + '</span>' +
                '</div>' +
                '<div class="wh-fill-head-right">' +
                    refWeightHtml(variant) +
                '</div>' +
                '<input type="number" step="0.01" min="0.01" class="form-control input-sm wh-fill-weight" placeholder="填写实测重量 g" value="' + escapeHtml(String(initWeight)) + '">' +
            '</div>' +
            '<div class="wh-fill-item-meta">' +
                '<span class="wh-fill-label">驳回理由（可多选）</span>' +
                reasonHtml +
                '<input type="text" class="form-control input-sm wh-fill-remark" maxlength="500" placeholder="备注（可选）" value="' + escapeHtml(initRemark) + '">' +
            '</div>' +
            imgSection;

        return item;
    }

    function showFillLoading() {
        document.getElementById('warehouse-fill-loading').hidden = false;
        document.getElementById('warehouse-fill-loading').innerHTML = '<i class="fa fa-spinner fa-spin"></i> 正在加载规格数据…';
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
                // 把已填异常合并进 rows，让 buildFillRow 能回填
                var existing = result.existing || {};
                var merged = (result.rows || []).map(function (variant) {
                    var sku = (variant.tongtu_sku || '').trim();
                    if (existing[sku]) {
                        variant._existing_exception = existing[sku];
                    }
                    return variant;
                });
                renderFillRows(merged);

                // 加载实拍图（PCL 级别）
                fillPclCode = (fillProduct && fillProduct.product_code) ? String(fillProduct.product_code) : '';
                loadFillRealImages();
            })
            .catch(function (err) {
                showFillError(err && err.message ? err.message : '加载失败');
            });
    }

    /** 实拍图模块（PCL 级别，挂在 fill modal 顶部） */
    function loadFillRealImages() {
        fillRealImages = [];
        renderFillRealImages();
        if (!fillPclCode || !IMAGES_URL) return;
        var sep = IMAGES_URL.indexOf('?') >= 0 ? '&' : '?';
        fetch(IMAGES_URL + sep + 'pcl_code=' + encodeURIComponent(fillPclCode), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) throw new Error(data.message || '加载失败');
                return data;
            });
        }).then(function (data) {
            fillRealImages = buildRealEntries(data.images || [], data.link_sku_id || 0);
            renderFillRealImages();
        }).catch(function () { /* 静默忽略 */ });
    }

    function buildRealEntries(paths, linkSkuId) {
        if (!paths || !paths.length) return [];
        return paths.map(function (path, idx) {
            return { path: path, url: '/warehouse-product-statistics/warehouse-images/' + linkSkuId + '/' + idx, link_sku_id: linkSkuId, index: idx };
        });
    }

    function renderFillRealImages() {
        var grid = document.getElementById('wh-fill-real-images');
        if (!grid) return;
        grid.innerHTML = '';
        if (!fillRealImages.length) return;
        fillRealImages.forEach(function (entry) {
            var item = document.createElement('span');
            item.className = 'wh-real-image-item';
            item.innerHTML =
                '<img src="' + entry.url + '" alt="实拍图" class="wh-real-image-thumb erp-zoomable" loading="lazy" decoding="async" title="点击放大查看" onclick="window.erpZoomOpen && window.erpZoomOpen(this.src); return false;">' +
                '<button type="button" class="wh-real-image-delete" data-real-index="' + entry.index + '" title="删除">&times;</button>';
            grid.appendChild(item);
        });
    }

    function syncFillRealImages(data) {
        fillRealImages = buildRealEntries(data.images || [], data.link_sku_id || 0);
        renderFillRealImages();
    }

    function uploadFillReal(file) {
        if (!fillPclCode || !IMAGE_UPLOAD_URL) return Promise.reject(new Error('缺少上传配置'));
        var fd = new FormData();
        fd.append('_token', csrfToken());
        fd.append('pcl_code', fillPclCode);
        fd.append('image', file);
        return fetch(IMAGE_UPLOAD_URL, {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) throw new Error(data.message || '上传失败');
                return data;
            });
        });
    }

    function deleteFillReal(index) {
        if (!fillPclCode || !IMAGE_DELETE_URL) return Promise.reject(new Error('缺少删除配置'));
        var body = '_token=' + encodeURIComponent(csrfToken())
            + '&pcl_code=' + encodeURIComponent(fillPclCode)
            + '&index=' + encodeURIComponent(index);
        return fetch(IMAGE_DELETE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) throw new Error(data.message || '删除失败');
                return data;
            });
        });
    }

    function handleFillRealFiles(files) {
        if (!files || !files.length) return;
        if (!fillPclCode) {
            alert('缺少 PCL 编号，暂时无法上传实拍图');
            return;
        }
        var arr = Array.prototype.slice.call(files).filter(function (f) {
            return f && IMAGE_MIMES.indexOf(f.type) !== -1;
        });
        if (!arr.length) { alert('请选择 jpg/png/webp 图片'); return; }
        function recurse(file) {
            uploadFillReal(file).then(function (data) {
                syncFillRealImages(data);
                if (arr.length) recurse(arr.shift());
            }).catch(function (err) { alert('上传失败：' + (err.message || '未知错误')); });
        }
        recurse(arr.shift());
    }

    function activateFillPaste() {
        fillPasteActive = true;
        clearTimeout(fillPasteTimer);
        fillPasteTimer = setTimeout(clearFillPaste, 15000);
        var zone = document.getElementById('wh-fill-real-upload');
        if (zone) zone.classList.add('is-paste-target');
        document.activeElement && document.activeElement.blur();
    }
    function clearFillPaste() {
        fillPasteActive = false;
        clearTimeout(fillPasteTimer);
        var zone = document.getElementById('wh-fill-real-upload');
        if (zone) zone.classList.remove('is-paste-target');
    }

    /** 缺重量的 SKU 手动调通途接口补抓重量。 */
    function refreshWeights(btn) {
        var skus = (btn.getAttribute('data-skus') || '')
            .split(',')
            .map(function (sku) { return sku.trim(); })
            .filter(Boolean);
        if (!skus.length) return;

        var originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> 抓取中';

        fetch(config.refreshWeightUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ skus: skus })
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        }).then(function (result) {
            var message = (result.data && result.data.message) || '抓取完成';
            if (result.ok && result.data && result.data.ok) {
                window.alert(message);
                window.location.reload();
                return;
            }
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            window.alert(message);
        }).catch(function (error) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            window.alert('网络异常：' + (error && error.message ? error.message : '抓取失败'));
        });
    }

    function submitFill() {
        var body = document.getElementById('warehouse-fill-body');
        var statusEl = document.getElementById('warehouse-fill-status');
        var submitBtn = document.getElementById('warehouse-fill-submit');
        var rows = [];
        var formData = new FormData();
        var invalid = false;

        fillRows.forEach(function (variant, idx) {
            var item = body.children[idx];
            if (!item || invalid) return;
            var weightInput = item.querySelector('.wh-fill-weight');
            var activePills = item.querySelectorAll('.wh-reason-pill.active');
            var remarkInput = item.querySelector('.wh-fill-remark');
            var fileInput = item.querySelector('.wh-fill-physical-input');
            var removeCheckbox = item.querySelector('.wh-fill-physical-remove-checkbox');
            var weightRaw = (weightInput.value || '').trim();
            var reason = Array.prototype.map.call(activePills, function (pill) {
                return (pill.getAttribute('data-value') || '').trim();
            }).filter(Boolean).join(',');
            var remark = (remarkInput.value || '').trim();
            var hasNewFile = fileInput && fileInput.files && fileInput.files.length > 0;
            var removeImg = removeCheckbox ? removeCheckbox.checked : false;
            if (weightRaw === '' && reason === '' && remark === '' && !hasNewFile && !removeImg) return;

            var weight = parseFloat(weightRaw);
            if (weightRaw !== '' && (!isFinite(weight) || weight <= 0)) {
                weightInput.focus();
                statusEl.innerHTML = '<span class="text-danger">「' + escapeHtml(variant.tongtu_sku) + '」填写的重量需大于 0</span>';
                invalid = true;
                return;
            }

            var skuKey = 'rows[' + idx + ']';
            formData.append(skuKey + '[tongtu_sku]', (variant.tongtu_sku || '').trim());
            formData.append(skuKey + '[shopify_sku]', (variant.shopify_skus || []).join(', '));
            formData.append(skuKey + '[original_weight_gram]', Number(variant.sale_weight_gram) || 0);
            if (isFinite(weight) && weight > 0) formData.append(skuKey + '[corrected_weight_gram]', weight);
            if (reason) formData.append(skuKey + '[reject_reason]', reason);
            if (remark) formData.append(skuKey + '[remark]', remark);
            if (hasNewFile) formData.append(skuKey + '[physical_image]', fileInput.files[0]);
            if (removeImg) formData.append(skuKey + '[remove_physical_image]', '1');

            rows.push({ sku: (variant.tongtu_sku || '').trim() });
        });

        if (invalid) return;
        if (rows.length === 0) {
            statusEl.innerHTML = '<span class="text-warning">请至少为一个 SKU 填写重量、驳回理由、备注或上传实物图</span>';
            return;
        }

        formData.append('product_id', fillProductId);
        formData.append('tongtu_product_code', (fillRows[0] && fillRows[0].tongtu_product_code) || (fillProduct && fillProduct.product_code) || '');
        formData.append('tongtu_product_name', (fillRows[0] && fillRows[0].tongtu_product_name) || '');
        formData.append('product_image_url', (fillRows[0] && fillRows[0].product_image_url) || (fillProduct && fillProduct.image_url) || '');

        submitBtn.disabled = true;
        statusEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> 正在提交…';

        fetch(config.fillUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: formData,
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
            setTimeout(function () {
                closeModal($fillModal, fillModal);
                window.location.reload();
            }, 600);
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

    // 实物图文件选择后做本地预览
    document.addEventListener('change', function (ev) {
        var target = ev.target;
        if (!target || !target.classList || !target.classList.contains('wh-fill-physical-input')) return;
        var file = target.files && target.files[0];
        if (!file) return;
        var item = target.closest('.wh-fill-item-images');
        if (!item) return;
        var preview = item.querySelector('[data-role="physical-preview"]');
        if (!preview) return;
        var reader = new FileReader();
        reader.onload = function () {
            preview.innerHTML = '<img src="' + reader.result + '" alt="" style="width:72px;height:72px;object-fit:cover;border:1px solid #dfe6ed;border-radius:4px;cursor:zoom-in;" onclick="window.erpZoomOpen && window.erpZoomOpen(this.src); return false;">';
        };
        reader.readAsDataURL(file);
    });

    // 实物图粘贴支持
    var fillPasteTarget = null;
    var fillPasteTimer = null;
    document.addEventListener('paste', function (ev) {
        if (!fillPasteTarget) return;
        var fillItem = document.querySelector('.wh-fill-item-images.is-paste-target');
        if (!fillItem) return;
        var clipboard = ev.clipboardData || window.clipboardData;
        if (!clipboard) return;
        var file = null;
        for (var i = 0; i < clipboard.items.length; i++) {
            if (clipboard.items[i].kind === 'file') {
                var f = clipboard.items[i].getAsFile();
                if (f && (f.type === 'image/jpeg' || f.type === 'image/jpg' || f.type === 'image/png' || f.type === 'image/webp')) {
                    file = f; break;
                }
            }
        }
        if (!file) return;
        ev.preventDefault();
        var input = fillItem.querySelector('.wh-fill-physical-input');
        if (!input) return;
        // 把粘贴的文件塞到 input 里（用 DataTransfer 触发 change 事件）
        try {
            var dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (e) {
            alert('浏览器不支持粘贴上传，请直接选择文件');
        }
        // 取消高亮
        clearFillPasteTarget();
    });
    function clearFillPasteTarget() {
        clearTimeout(fillPasteTimer);
        var el = document.querySelector('.wh-fill-item-images.is-paste-target');
        if (el) el.classList.remove('is-paste-target');
    }

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

        // ④ 列表上的"抓重量"：调通途接口补抓缺失的 SKU 重量
        var refreshWeightBtn = target.closest('.warehouse-product-statistics-refresh-weight-btn');
        if (refreshWeightBtn) {
            ev.preventDefault();
            refreshWeights(refreshWeightBtn);
            return;
        }

        // ⑤ 驳回理由按钮组：支持多选，再次点击取消当前选项
        var reasonPill = target.closest('.wh-reason-pill');
        if (reasonPill) {
            ev.preventDefault();
            reasonPill.classList.toggle('active');
            return;
        }

        // ④-1 实物图删除 checkbox
        var removeCb = target.closest('[data-role="remove-physical"]');
        if (removeCb) {
            var preview = removeCb.closest('.wh-fill-item-images').querySelector('[data-role="physical-preview"]');
            if (preview) {
                if (removeCb.checked) {
                    var existingImg = preview.querySelector('img');
                    if (existingImg) existingImg.style.opacity = '0.25';
                } else {
                    var existingImg2 = preview.querySelector('img');
                    if (existingImg2) existingImg2.style.opacity = '1';
                }
            }
            return;
        }

        // ④-2 实物图粘贴按钮 → 设为粘贴目标
        var fillPasteBtn = target.closest('.wh-fill-physical-paste');
        if (fillPasteBtn) {
            ev.preventDefault();
            ev.stopPropagation();
            clearFillPasteTarget();
            var fillItem = fillPasteBtn.closest('.wh-fill-item-images');
            if (fillItem) {
                fillPasteTarget = true;
                fillItem.classList.add('is-paste-target');
                fillPasteTimer = setTimeout(clearFillPasteTarget, 10000);
            }
            document.activeElement && document.activeElement.blur();
            return;
        }

        // ④-3 fill modal 实拍图上传区：点击虚线框 → 选文件
        if (fillModal && fillModal.contains(target)) {
            var realUploadZone = target.closest('#wh-fill-real-upload');
            if (realUploadZone) {
                if (target.closest('#wh-fill-real-paste')) {
                    ev.preventDefault();
                    ev.stopPropagation();
                    activateFillPaste();
                    return;
                }
                if (target.closest('#wh-fill-real-file')) return;
                ev.preventDefault();
                var realFile = document.getElementById('wh-fill-real-file');
                if (realFile) realFile.click();
                return;
            }

            // ④-4 fill modal 实拍图粘贴按钮
            var realPasteBtn = target.closest('#wh-fill-real-paste');
            if (realPasteBtn) {
                ev.preventDefault();
                ev.stopPropagation();
                activateFillPaste();
                return;
            }

            // ④-5 fill modal 实拍图删除按钮
            var realDelBtn = target.closest('.wh-real-image-delete[data-real-index]');
            if (realDelBtn) {
                ev.preventDefault();
                var idx = parseInt(realDelBtn.getAttribute('data-real-index') || '0', 10);
                if (!confirm('确认删除该图片？')) return;
                deleteFillReal(idx).then(syncFillRealImages).catch(function (err) {
                    alert('删除失败：' + (err.message || '未知错误'));
                });
                return;
            }
        }

        // ⑤ 填写弹窗提交
        if (target.closest('#warehouse-fill-submit')) {
            ev.preventDefault();
            submitFill();
        }
    });

    // ==== fill modal 实拍图：file input change ====
    document.addEventListener('change', function (ev) {
        if (ev.target && ev.target.id === 'wh-fill-real-file') {
            handleFillRealFiles(ev.target.files);
            ev.target.value = '';
        }
    });

    // ==== fill modal 实拍图：拖拽 ====
    (function bindFillRealDrop() {
        var zone = document.getElementById('wh-fill-real-upload');
        if (!zone) return;
        zone.addEventListener('dragover', function (ev) { ev.preventDefault(); zone.classList.add('is-active'); });
        zone.addEventListener('dragleave', function (ev) { ev.preventDefault(); zone.classList.remove('is-active'); });
        zone.addEventListener('drop', function (ev) {
            ev.preventDefault();
            zone.classList.remove('is-active');
            handleFillRealFiles(ev.dataTransfer && ev.dataTransfer.files);
        });
    })();

    // ==== fill modal 实拍图：Ctrl+V 粘贴 ====
    document.addEventListener('paste', function (ev) {
        if (!fillPasteActive || !fillPclCode) return;
        var clipboard = ev.clipboardData || window.clipboardData;
        if (!clipboard) return;
        var files = [];
        for (var i = 0; i < clipboard.items.length; i++) {
            if (clipboard.items[i].kind === 'file') {
                var f = clipboard.items[i].getAsFile();
                if (f && IMAGE_MIMES.indexOf(f.type) !== -1) files.push(f);
            }
        }
        if (!files.length) return;
        ev.preventDefault();
        handleFillRealFiles(files);
    });
})();
