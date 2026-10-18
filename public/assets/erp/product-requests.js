(function () {
    'use strict';

    var config = window.ErpProductRequestConfig || {};
    var editorModal = document.querySelector('[data-product-request-editor-modal]');
    var editorContent = editorModal ? editorModal.querySelector('[data-product-request-modal-content]') : null;

    function notify(type, text) {
        if (!text) { return; }
        if (window.ErpAdminNotifier) {
            window.ErpAdminNotifier.notify({ type: type, title: config.noticeTitle || '', message: text });
            return;
        }
        window.alert(text);
    }

    function setEditorMessage(text, failed) {
        if (!editorContent) { return; }
        editorContent.innerHTML = '';
        var body = document.createElement('div');
        body.className = 'modal-body product-request-modal-loading' + (failed ? ' text-red' : '');
        var icon = document.createElement('i');
        icon.className = failed ? 'fa fa-exclamation-triangle' : 'fa fa-spinner fa-spin';
        body.appendChild(icon);
        body.appendChild(document.createTextNode(' ' + (text || config.loading || '')));
        editorContent.appendChild(body);
    }

    function reportInitializationError(scope, error) {
        if (window.console && typeof window.console.error === 'function') {
            window.console.error('[product-requests] ' + scope, error);
        }
    }

    function showEditor() {
        if (window.jQuery && editorModal) {
            window.jQuery(editorModal).modal('show');
        }
    }

    function hideEditor() {
        if (window.jQuery && editorModal) {
            window.jQuery(editorModal).modal('hide');
        }
    }

    function modalUrl(url) {
        try {
            var parsed = new URL(url, window.location.origin);
            parsed.searchParams.set('modal', '1');
            return parsed.toString();
        } catch (error) {
            return url;
        }
    }

    function openEditor(url) {
        if (!editorModal || !editorContent) {
            window.location.assign(url);
            return;
        }
        setEditorMessage(config.loading, false);
        showEditor();
        fetch(modalUrl(url), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Product-Request-Modal': '1',
                'Accept': 'text/html'
            }
        }).then(function (response) {
            if (!response.ok) { throw new Error('load'); }
            return response.text();
        }).then(function (markup) {
            editorContent.innerHTML = markup;
            initialize(editorContent);
        }).catch(function () {
            setEditorMessage(config.loadFailed, true);
        });
    }

    function isSupported(file) {
        var allowed = ['image/jpeg', 'image/png', 'image/webp'];
        return allowed.indexOf(file.type) !== -1 && file.size <= 5 * 1024 * 1024;
    }

    function inputFiles(input) {
        return Array.prototype.slice.call(input.files || []);
    }

    function syncInput(input, files) {
        var data = new DataTransfer();
        files.forEach(function (file) { data.items.add(file); });
        input.files = data.files;
    }

    function renderPreview(container, input) {
        var preview = container.querySelector('[data-product-request-preview]');
        if (!preview) { return; }
        preview.querySelectorAll('[data-new-upload-preview]').forEach(function (node) { node.remove(); });
        if (inputFiles(input).length > 0) {
            preview.querySelectorAll('[data-reused-preview]').forEach(function (node) { node.remove(); });
        }
        inputFiles(input).forEach(function (file, index) {
            var item = document.createElement('div');
            item.className = 'product-request-upload-preview-item';
            item.dataset.newUploadPreview = 'true';
            var image = document.createElement('img');
            image.alt = file.name;
            image.src = URL.createObjectURL(file);
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'product-request-upload-remove';
            remove.textContent = '×';
            remove.setAttribute('aria-label', config.removeImage || '');
            remove.addEventListener('click', function () {
                var files = inputFiles(input);
                files.splice(index, 1);
                syncInput(input, files);
                renderPreview(container, input);
            });
            item.appendChild(image);
            item.appendChild(remove);
            preview.appendChild(item);
        });
    }

    function addFiles(container, input, maxFiles, incoming) {
        var current = inputFiles(input);
        // 新上传数量上限需扣除仍保留的已保存附件
        var limit = Math.max(0, maxFiles - container.querySelectorAll('[data-existing-preview]').length);
        Array.prototype.slice.call(incoming || []).forEach(function (file) {
            if (!isSupported(file)) {
                notify('warning', config.invalidFile);
                return;
            }
            if (current.length >= limit) {
                notify('warning', (config.tooMany || '').replace(':count', maxFiles));
                return;
            }
            current.push(file);
        });
        syncInput(input, current);
        renderPreview(container, input);
    }

    function clipboardImageFiles(items) {
        var pending = [];
        Array.prototype.slice.call(items || []).forEach(function (item) {
            Array.prototype.slice.call(item.types || []).forEach(function (type) {
                if (/^image\//.test(type) && typeof item.getType === 'function') {
                    pending.push(item.getType(type).then(function (blob) {
                        var ext = (type.split('/')[1] || 'png').replace('jpeg', 'jpg');
                        return new File([blob], 'clipboard-' + Date.now() + '.' + ext, { type: blob.type || type });
                    }).catch(function () { return null; }));
                }
            });
        });

        return Promise.all(pending).then(function (files) {
            return files.filter(function (file) { return !!file; });
        });
    }

    function pasteFromClipboard(container, input, maxFiles) {
        var canRead = window.isSecureContext && navigator.clipboard && typeof navigator.clipboard.read === 'function';
        if (!canRead) {
            container.focus();
            notify('info', config.pasteHint);
            return;
        }
        navigator.clipboard.read().then(function (items) {
            return clipboardImageFiles(items);
        }).then(function (files) {
            if (files.length) { addFiles(container, input, maxFiles, files); }
            else { notify('info', config.pasteHint); }
        }).catch(function () {
            container.focus();
            notify('info', config.pasteHint);
        });
    }

    function submitProgress(form) {
        if (!form) { return null; }
        var existing = form.querySelector('[data-submit-progress]');
        if (existing) { return existing; }
        var wrapper = document.createElement('div');
        wrapper.className = 'product-request-submit-progress';
        wrapper.setAttribute('data-submit-progress', 'true');
        wrapper.hidden = true;
        var track = document.createElement('div');
        track.className = 'product-request-submit-progress-track';
        var bar = document.createElement('div');
        bar.className = 'product-request-submit-progress-bar';
        bar.setAttribute('data-submit-progress-bar', 'true');
        track.appendChild(bar);
        var text = document.createElement('span');
        text.className = 'product-request-submit-progress-text';
        text.setAttribute('data-submit-progress-text', 'true');
        wrapper.appendChild(track);
        wrapper.appendChild(text);
        var footer = form.querySelector('.modal-footer');
        if (footer && footer.parentNode) {
            footer.parentNode.insertBefore(wrapper, footer);
        } else {
            form.appendChild(wrapper);
        }
        return wrapper;
    }

    function initializeUploads(root) {
        root.querySelectorAll('[data-product-request-upload]').forEach(function (container) {
            if (container.dataset.productRequestUploadReady === '1') { return; }
            container.dataset.productRequestUploadReady = '1';
            var input = container.querySelector('input[type="file"]');
            if (!input) { return; }
            var maxFiles = parseInt(container.getAttribute('data-max-files') || '1', 10);
            input.addEventListener('change', function () { renderPreview(container, input); });
            var pasteButton = container.querySelector('[data-product-request-paste]');
            if (pasteButton) {
                pasteButton.addEventListener('click', function () {
                    pasteFromClipboard(container, input, maxFiles);
                });
            }

            var removedInput = container.querySelector('[data-removed-attachments]');
            if (removedInput) {
                container.querySelectorAll('[data-existing-remove]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        var item = button.closest('[data-existing-preview]');
                        if (!item) { return; }
                        var id = item.getAttribute('data-attachment-id');
                        var ids = removedInput.value ? removedInput.value.split(',') : [];
                        if (ids.indexOf(id) === -1) {
                            ids.push(id);
                            removedInput.value = ids.join(',');
                        }
                        item.remove();
                        renderPreview(container, input);
                    });
                });
            }
            ['dragenter', 'dragover'].forEach(function (eventName) {
                container.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    container.classList.add('is-dragging');
                });
            });
            ['dragleave', 'drop'].forEach(function (eventName) {
                container.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    container.classList.remove('is-dragging');
                    if (eventName === 'drop') { addFiles(container, input, maxFiles, event.dataTransfer ? event.dataTransfer.files : []); }
                });
            });
        });
    }

    function clearValidationErrors(form) {
        form.querySelectorAll('.product-request-server-error').forEach(function (node) { node.remove(); });
        form.querySelectorAll('.has-error').forEach(function (node) { node.classList.remove('has-error'); });
    }

    function appendValidationError(group, text) {
        if (!group || !text) { return; }
        group.classList.add('has-error');
        var message = document.createElement('span');
        message.className = 'help-block text-red product-request-server-error';
        message.textContent = text;
        group.appendChild(message);
    }

    function errorField(form, name) {
        var rootName = name.replace(/\.(?:\d+|\*)$/, '');
        return form.querySelector('[name="' + name + '"]')
            || form.querySelector('[name="' + rootName + '"]')
            || form.querySelector('[name="' + rootName + '[]"]');
    }

    function showValidationErrors(form, errors) {
        clearValidationErrors(form);
        var first = null;
        Object.keys(errors || {}).forEach(function (name) {
            var input = errorField(form, name);
            if (!input) { return; }
            var group = input.closest('.form-group') || input.closest('[data-product-request-upload]') || input.parentNode;
            if (!group) { return; }
            appendValidationError(group, (errors[name] || [])[0] || '');
            if (!first) { first = input; }
        });
        if (first && typeof first.focus === 'function') { first.focus(); }
    }

    function validateRequiredUploads(form) {
        var first = null;
        form.querySelectorAll('[data-product-request-upload]').forEach(function (container) {
            var input = container.querySelector('input[type="file"]');
            if (!input || container.getAttribute('data-required') !== '1') { return; }
            // 已保存的附件或复用带过来的图片同样满足必填
            if (inputFiles(input).length > 0 || container.querySelector('.product-request-upload-preview-item')) { return; }
            var group = container.closest('.form-group') || container;
            appendValidationError(group, container.getAttribute('data-required-message') || '');
            if (!first) { first = container; }
        });
        if (first && typeof first.focus === 'function') {
            first.focus();
        }
        return !first;
    }

    function initializeReuse(root) {
        var reuseForm = root.querySelector('[data-product-request-reuse-form]');
        if (!reuseForm || reuseForm.dataset.productRequestReuseReady === '1') { return; }
        reuseForm.dataset.productRequestReuseReady = '1';
        var reuseButton = reuseForm.querySelector('[data-product-request-reuse-check]');
        var overlay = reuseForm.querySelector('[data-product-request-reuse-modal]');
        var results = overlay ? overlay.querySelector('[data-product-request-reuse-results]') : null;
        var selectedText = reuseForm.querySelector('[data-product-request-reuse-selected]');
        var selectedSource = reuseForm.querySelector('[name="reuse_source_id"]');

        function revealSkuPanel() {
            if (typeof reuseForm.erpSkuSuggestions === 'function') { reuseForm.erpSkuSuggestions(); }
        }

        function closeReuse() {
            if (overlay) { overlay.hidden = true; }
            revealSkuPanel();
        }

        function renderReusedImages(items) {
            var preview = reuseForm.querySelector('[data-product-request-upload] [data-product-request-preview]');
            if (!preview) { return; }
            preview.querySelectorAll('[data-reused-preview]').forEach(function (node) { node.remove(); });
            (items || []).forEach(function (item) {
                if (!item || !item.url) { return; }
                var wrapper = document.createElement('div');
                wrapper.className = 'product-request-upload-preview-item product-request-upload-existing';
                wrapper.setAttribute('data-reused-preview', 'true');
                var link = document.createElement('a');
                link.href = item.url;
                link.setAttribute('data-product-request-image-preview', 'true');
                link.setAttribute('data-product-request-image-alt', item.name || '');
                var image = document.createElement('img');
                image.src = item.url;
                image.alt = item.name || '';
                link.appendChild(image);
                wrapper.appendChild(link);
                preview.appendChild(wrapper);
            });
        }

        function applyRecord(record) {
            ['standard_chinese_name', 'large_volume_weight_g', 'minimum_purchase_price_rmb', 'estimated_shipping_weight_g', 'outer_package_size', 'pcl_product_no'].forEach(function (field) {
                var input = reuseForm.querySelector('[name="' + field + '"]');
                if (input) { input.value = record[field] || ''; }
            });
            renderReusedImages(record.seller_images);
            // 按名称匹配复用时，来源记录链接与当前链接不同，需把来源系统 SKU 并入右栏供勾选
            if (typeof reuseForm.erpSkuSuggestionsAppend === 'function') {
                reuseForm.erpSkuSuggestionsAppend(record.skus || [], record.report_no || '');
            }
            if (selectedSource) { selectedSource.value = record.id; }
            if (selectedText) {
                selectedText.hidden = false;
                selectedText.textContent = (config.reuseSelected || '').replace(':report_no', record.report_no || '');
            }
            closeReuse();
        }

        function buildDetail(record) {
            var detail = document.createElement('div');
            detail.className = 'product-request-reuse-detail';
            detail.hidden = true;
            [
                [config.dedupePrice, record.minimum_purchase_price_rmb],
                [config.dedupeWeight, record.estimated_shipping_weight_g],
                [config.dedupeLargeVolume, record.large_volume_weight_g],
                [config.dedupePackage, record.outer_package_size]
            ].forEach(function (pair) {
                var item = document.createElement('div');
                item.className = 'product-request-reuse-detail-item';
                var label = document.createElement('span');
                label.className = 'product-request-reuse-detail-label';
                label.textContent = pair[0] || '';
                var value = document.createElement('span');
                value.className = 'product-request-reuse-detail-value';
                value.textContent = (pair[1] === null || pair[1] === undefined || pair[1] === '') ? '\u2014' : pair[1];
                item.appendChild(label);
                item.appendChild(value);
                detail.appendChild(item);
            });

            var images = document.createElement('div');
            images.className = 'product-request-reuse-detail-images';
            var imagesLabel = document.createElement('span');
            imagesLabel.className = 'product-request-reuse-detail-label';
            imagesLabel.textContent = config.dedupeSellerImages || '';
            images.appendChild(imagesLabel);
            var list = record.seller_images || [];
            if (!list.length) {
                var empty = document.createElement('span');
                empty.className = 'text-muted';
                empty.textContent = config.dedupeNoImages || '';
                images.appendChild(empty);
            } else {
                list.forEach(function (item) {
                    var link = document.createElement('a');
                    link.href = item.url;
                    link.setAttribute('data-product-request-image-preview', 'true');
                    link.setAttribute('data-product-request-image-alt', item.name || '');
                    var image = document.createElement('img');
                    image.src = item.url;
                    image.alt = item.name || '';
                    link.appendChild(image);
                    images.appendChild(link);
                });
            }
            detail.appendChild(images);
            return detail;
        }

        function appendResult(record) {
            var wrapper = document.createElement('div');
            wrapper.className = 'product-request-reuse-item';

            var row = document.createElement('div');
            row.className = 'product-request-reuse-row';
            if (record.cover_url) {
                var image = document.createElement('img');
                image.src = record.cover_url;
                image.alt = record.standard_chinese_name || '';
                row.appendChild(image);
            }
            var content = document.createElement('div');
            content.className = 'product-request-reuse-content';
            var name = document.createElement('strong');
            name.textContent = record.standard_chinese_name || '';
            var detail = document.createElement('span');
            detail.textContent = record.supplier_url || '';
            content.appendChild(name);
            content.appendChild(detail);
            if (record.skus && record.skus.length) {
                var skuLine = document.createElement('span');
                skuLine.className = 'product-request-reuse-skus';
                var skuLabel = document.createElement('span');
                skuLabel.textContent = (config.dedupeSkus || 'SKU') + '：';
                skuLine.appendChild(skuLabel);
                var skuTags = document.createElement('span');
                skuTags.className = 'product-request-sku-tags';
                record.skus.forEach(function (sku) {
                    var tag = document.createElement('span');
                    tag.className = 'product-request-sku-tag';
                    tag.textContent = sku;
                    skuTags.appendChild(tag);
                });
                skuLine.appendChild(skuTags);
                content.appendChild(skuLine);
            }
            row.appendChild(content);

            var detailBlock = buildDetail(record);

            var actions = document.createElement('div');
            actions.className = 'product-request-reuse-actions';
            var detailButton = document.createElement('button');
            detailButton.type = 'button';
            detailButton.className = 'btn btn-default btn-sm';
            detailButton.textContent = config.dedupeDetail || '';
            detailButton.addEventListener('click', function () { detailBlock.hidden = !detailBlock.hidden; });
            var applyButton = document.createElement('button');
            applyButton.type = 'button';
            applyButton.className = 'btn btn-success btn-sm';
            applyButton.textContent = config.reuse || '';
            applyButton.addEventListener('click', function () { applyRecord(record); });
            actions.appendChild(detailButton);
            actions.appendChild(applyButton);
            row.appendChild(actions);

            wrapper.appendChild(row);
            wrapper.appendChild(detailBlock);
            results.appendChild(wrapper);
        }

        if (overlay) {
            overlay.querySelectorAll('[data-product-request-reuse-close]').forEach(function (button) {
                button.addEventListener('click', closeReuse);
            });
            overlay.addEventListener('click', function (event) {
                if (event.target === overlay) { closeReuse(); }
            });
        }
        if (!reuseButton || !overlay || !results) { return; }

        reuseButton.addEventListener('click', function () {
            var url = reuseForm.querySelector('[name="supplier_url"]');
            var name = reuseForm.querySelector('[name="standard_chinese_name"]');
            if (!name || !name.value.trim()) {
                notify('warning', config.dedupeNameRequired);
                return;
            }
            if (!url || !url.value.trim()) {
                notify('warning', config.reuseRequired);
                return;
            }
            results.innerHTML = '';
            var loading = document.createElement('p');
            loading.className = 'text-muted';
            loading.textContent = config.loading || '';
            results.appendChild(loading);
            overlay.hidden = false;
            var body = new URLSearchParams();
            body.append('supplier_url', url.value.trim());
            if (name && name.value.trim()) { body.append('standard_chinese_name', name.value.trim()); }
            body.append('current_id', reuseForm.getAttribute('data-current-id') || '');
            fetch(reuseForm.getAttribute('data-reuse-check-url'), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': config.csrfToken || '', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (payload) {
                    return { response: response, payload: payload };
                });
            }).then(function (result) {
                if (result.response.status === 422) {
                    overlay.hidden = true;
                    showValidationErrors(reuseForm, result.payload.errors || {});
                    return;
                }
                if (!result.response.ok) { throw new Error(result.payload.message || 'reuse'); }
                var payload = result.payload;
                results.innerHTML = '';
                if (!payload.records || payload.records.length === 0) {
                    var empty = document.createElement('p');
                    empty.className = 'text-muted';
                    empty.textContent = config.reuseEmpty || '';
                    results.appendChild(empty);
                    return;
                }
                payload.records.forEach(appendResult);
            }).catch(function () {
                overlay.hidden = true;
                notify('warning', config.reuseFailed);
            });
        });
    }

    function initializeSkuSuggestions(root) {
        var input = root.querySelector('#supplier_url[data-sku-suggestions-url]');
        if (!input || input.dataset.skuSuggestionsReady === '1') { return; }
        input.dataset.skuSuggestionsReady = '1';
        var form = input.closest('form');
        var panel = root.querySelector('#sku-suggestion-panel');
        var list = root.querySelector('#sku-suggestion-list');
        var countEl = root.querySelector('#sku-suggestion-count');
        var summaryRow = root.querySelector('#sku-suggestion-summary-row');
        var summary = root.querySelector('#sku-selected-summary');
        var url = input.getAttribute('data-sku-suggestions-url');
        var currentId = input.getAttribute('data-sku-suggestions-current-id');
        var linkSkusInput = form ? form.querySelector('[data-product-request-link-skus]') : null;
        var checkButton = form ? form.querySelector('[data-sku-check]') : null;
        if (!panel || !list || !form) { return; }

        var operationList = panel.querySelector('#sku-operation-list');
        var skuSource = root.querySelector('[data-sku-edit-source]');
        var skuPreview = skuSource ? skuSource.parentNode.querySelector('[data-sku-edit-preview]') : null;
        var syncingSkus = false;

        // 运营 SKU 编辑框内容实时渲染为标签，便于逐行查看
        function renderSkuPreview() {
            if (!skuSource || !skuPreview) { return; }
            skuPreview.innerHTML = '';
            skuSource.value.split(/[\n,]+/).forEach(function (part) {
                var sku = part.trim();
                if (sku === '') { return; }
                var tag = document.createElement('span');
                tag.className = 'product-request-sku-tag';
                tag.textContent = sku;
                skuPreview.appendChild(tag);
            });
        }

        // 多行编辑框高度随内容自适应
        function resizeSkuSource() {
            if (!skuSource) { return; }
            skuSource.style.height = 'auto';
            skuSource.style.height = (skuSource.scrollHeight + 2) + 'px';
        }

        function updateSummary() {
            var checked = form.querySelectorAll('input[name="selected_skus[]"]:checked');
            var parts = [];
            for (var i = 0; i < checked.length; i++) {
                if (checked[i].value.trim() !== '' && parts.indexOf(checked[i].value) === -1) { parts.push(checked[i].value); }
            }
            if (summaryRow) { summaryRow.hidden = parts.length === 0; }
            if (summary) { summary.textContent = parts.length ? '已勾选 ' + parts.length + ' 个：' + parts.join(', ') : ''; }
        }

        // 提报信息下的「运营 SKU」可直接修改，文本与勾选项双向同步
        function operationValues() {
            var values = [];
            if (!operationList) { return values; }
            operationList.querySelectorAll('[data-sku-select]').forEach(function (box) {
                var value = (box.value || '').trim();
                if (value !== '' && values.indexOf(value) === -1) { values.push(value); }
            });
            return values;
        }

        function writeOperationSource() {
            if (!skuSource) { return; }
            syncingSkus = true;
            skuSource.value = operationValues().join("\n");
            syncingSkus = false;
            renderSkuPreview();
            resizeSkuSource();
        }

        // SKU 行：editable=true 时渲染文本框（仅运营 SKU 可改），否则只读展示
        function buildSkuRow(value, editable) {
            var label = document.createElement('label');
            label.className = 'sku-suggestion-item';
            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.name = 'selected_skus[]';
            checkbox.value = value;
            checkbox.checked = true;
            checkbox.setAttribute('data-sku-select', 'true');
            checkbox.addEventListener('change', function () {
                if (label.parentNode === list) { syncLinkSkus(); }
                updateSummary();
            });
            label.appendChild(checkbox);
            if (editable) {
                var edit = document.createElement('input');
                edit.type = 'text';
                edit.className = 'sku-suggestion-input';
                edit.value = value;
                edit.setAttribute('maxlength', '500');
                edit.setAttribute('data-sku-edit', 'true');
                edit.addEventListener('input', function () {
                    var next = edit.value.trim();
                    checkbox.value = next;
                    if (next === '') { checkbox.checked = false; }
                    writeOperationSource();
                    updateSummary();
                });
                label.appendChild(edit);
            } else {
                label.appendChild(document.createTextNode(value));
            }
            return label;
        }

        function renderOperationRows(value) {
            if (!operationList) { return; }
            operationList.innerHTML = '';
            var values = String(value === null || typeof value === 'undefined' ? '' : value).split(/[\n,]+/).map(function (part) { return part.trim(); }).filter(function (part) { return part !== ''; });
            if (!values.length) {
                var empty = document.createElement('span');
                empty.className = 'sku-suggestion-empty';
                empty.textContent = operationList.getAttribute('data-empty-text') || '';
                operationList.appendChild(empty);
                return;
            }
            values.forEach(function (sku) { operationList.appendChild(buildSkuRow(sku, true)); });
        }

        if (skuSource) {
            skuSource.addEventListener('input', function () {
                if (syncingSkus) { return; }
                renderOperationRows(skuSource.value);
                renderSkuPreview();
                resizeSkuSource();
                updateSummary();
            });
        }
        renderOperationRows(skuSource ? skuSource.value : (operationList ? operationList.getAttribute('data-operation-skus') : ''));
        renderSkuPreview();
        resizeSkuSource();

        // 链接基线始终与「系统已录入的 SKU」框保持一致，保证增删比对准确
        function syncLinkSkus() {
            if (!linkSkusInput) { return; }
            var values = [];
            list.querySelectorAll('[data-sku-select]').forEach(function (box) {
                var value = (box.value || '').trim();
                if (value !== '' && values.indexOf(value) === -1) { values.push(value); }
            });
            linkSkusInput.value = values.join(',');
        }

        // fetchedItems 为当前链接查询结果；extraItems 为按名称复用时带入的来源记录 SKU
        var fetchedItems = [];
        var extraItems = [];

        function render() {
            var items = fetchedItems.concat(extraItems);
            list.innerHTML = '';
            if (countEl) { countEl.textContent = ''; }
            if (!items.length) {
                var empty = document.createElement('span');
                empty.className = 'sku-suggestion-empty';
                empty.textContent = '该链接在系统中暂无已录入 SKU';
                list.appendChild(empty);
                panel.hidden = false;
                if (linkSkusInput) { linkSkusInput.value = ''; }
                updateSummary();
                return;
            }
            var total = 0;
            var seen = [];
            items.forEach(function (item) {
                (item.skus || []).forEach(function (sku) {
                    var value = String(sku === null || typeof sku === 'undefined' ? '' : sku).trim();
                    // 同一 SKU 只保留一个：当前链接已录入的优先，复用来源重复的不再追加
                    if (value === '' || seen.indexOf(value) !== -1) { return; }
                    seen.push(value);
                    total += 1;
                    // 链接上已录入的 SKU 只读展示，仅可勾选/取消（不提供修改）
                    var label = buildSkuRow(sku, false);
                    if (item.report_no) {
                        var source = document.createElement('span');
                        source.className = 'sku-source';
                        source.textContent = '（' + item.report_no + '）';
                        label.appendChild(source);
                    }
                    list.appendChild(label);
                });
            });
            if (countEl) { countEl.textContent = '共 ' + total + ' 个'; }
            panel.hidden = false;
            syncLinkSkus();
            updateSummary();
        }

        // 按名称匹配复用时，把来源记录的系统 SKU 并入右栏供勾选（与已有勾选项去重）
        form.erpSkuSuggestionsAppend = function (skus, reportNo) {
            var existing = [];
            form.querySelectorAll('input[name="selected_skus[]"]').forEach(function (box) {
                var value = (box.value || '').trim();
                if (value !== '' && existing.indexOf(value) === -1) { existing.push(value); }
            });
            var added = [];
            (skus || []).forEach(function (sku) {
                var value = String(sku === null || typeof sku === 'undefined' ? '' : sku).trim();
                if (value === '' || existing.indexOf(value) !== -1 || added.indexOf(value) !== -1) { return; }
                added.push(value);
            });
            extraItems = added.length ? [{ id: null, report_no: reportNo || null, skus: added }] : [];
            render();
        };

        function fetchSuggestions() {
            var value = input.value.trim();
            if (!/^https?:\/\//i.test(value)) {
                panel.hidden = true;
                if (summaryRow) { summaryRow.hidden = true; }
                if (linkSkusInput) { linkSkusInput.value = ''; }
                return;
            }
            var query = '?supplier_url=' + encodeURIComponent(value) + (currentId ? '&current_id=' + encodeURIComponent(currentId) : '');
            fetch(url + query, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) { return response.ok ? response.json() : { items: [] }; })
                .then(function (payload) { fetchedItems = payload.items || []; render(); })
                .catch(function () { panel.hidden = true; });
        }

        if (checkButton) { checkButton.addEventListener('click', fetchSuggestions); }
        form.erpSkuSuggestions = fetchSuggestions;
        updateSummary();
    }

    function initializeRadioChoices(root) {
        root.querySelectorAll('.product-request-radio-list').forEach(function (list) {
            if (list.dataset.productRequestRadioReady === '1') { return; }
            list.dataset.productRequestRadioReady = '1';
            list.classList.add('is-button-group');

            function refresh() {
                list.querySelectorAll('label').forEach(function (label) {
                    var input = label.querySelector('input[type="radio"]');
                    var selected = Boolean(input && input.checked);
                    label.classList.toggle('is-selected', selected);
                    label.setAttribute('aria-pressed', selected ? 'true' : 'false');
                });
            }

            list.addEventListener('change', refresh);
            refresh();
        });
    }

    function initializeSkuBuilder(root) {
        var builder = root.querySelector('[data-product-request-sku-builder]');
        if (!builder || builder.dataset.skuBuilderReady === '1') { return; }
        builder.dataset.skuBuilderReady = '1';
        var form = builder.closest('form');
        var template = builder.querySelector('[data-sku-row]');
        if (!form || !template) { return; }
        var hidden = form.querySelector('input[name="skus"]');
        if (!hidden) { return; }
        var counter = root.querySelector('#sku-count');
        var warn = root.querySelector('#sku-warn');
        var prefix = builder.getAttribute('data-sku-prefix') || '';
        var max = 50;
        var boxDefault = builder.getAttribute('data-sku-box-default') || '1pc*';
        var templateBox = template.querySelector('[data-sku-part="box"]');
        if (templateBox && templateBox.value === '') { templateBox.value = boxDefault; }

        function defaultPinyin() {
            var found = '';
            rows().forEach(function (row) {
                if (found !== '') { return; }
                var value = partValue(row, 'pinyin');
                if (value !== '') { found = value; }
            });
            return found;
        }

        function rows() { return Array.prototype.slice.call(builder.querySelectorAll('[data-sku-row]')); }

        function partValue(row, name) {
            var input = row.querySelector('[data-sku-part="' + name + '"]');
            return input ? String(input.value || '').replace(/[\n\r,，;；\t]+/g, '').trim() : '';
        }

        function hasSeparator(row) {
            return ['pinyin', 'attribute', 'box'].some(function (name) {
                var input = row.querySelector('[data-sku-part="' + name + '"]');
                return input ? /[\n\r,，;；\t]/.test(String(input.value || '')) : false;
            });
        }

        function rowParts(row) {
            return {
                pinyin: partValue(row, 'pinyin').replace(/\s+/g, ''),
                attribute: partValue(row, 'attribute'),
                box: partValue(row, 'box')
            };
        }

        function rowValue(row) {
            var parts = rowParts(row);
            if (parts.pinyin === '' || parts.attribute === '') { return ''; }
            return prefix + parts.pinyin + '-' + parts.attribute + '-' + parts.box;
        }

        function refreshRowButtons() {
            var list = rows();
            list.forEach(function (row, index) {
                var remove = row.querySelector('[data-sku-remove]');
                if (remove) { remove.hidden = list.length <= 1; }
                var add = row.querySelector('[data-sku-add]');
                if (add) { add.hidden = index !== list.length - 1; }
            });
        }

        function sync() {
            var values = rows().map(rowValue).filter(function (value) { return value !== ''; });
            hidden.value = values.join(',');
            if (counter) {
                counter.textContent = String(values.length);
                counter.className = values.length > max ? 'text-red' : 'text-muted';
            }
            if (warn) {
                var messages = [];
                var incomplete = rows().filter(function (row) {
                    var parts = rowParts(row);
                    if (parts.pinyin === '' && parts.attribute === '') { return false; }
                    return parts.pinyin === '' || parts.attribute === '' || parts.box === '';
                }).length;
                if (incomplete > 0) { messages.push((config.skuIncomplete || '').replace(':count', incomplete)); }
                if (rows().filter(hasSeparator).length > 0) { messages.push(config.skuSeparator || ''); }
                var duplicated = values.filter(function (value, index) { return values.indexOf(value) !== index; });
                if (duplicated.length > 0) { messages.push(config.skuDuplicated || ''); }
                if (values.length > max) { messages.push((config.skuTooMany || '').replace(':count', max)); }
                warn.textContent = messages.filter(function (message) { return message !== ''; }).join('；');
                warn.style.display = warn.textContent === '' ? 'none' : '';
            }
            refreshRowButtons();
        }

        var hintCanvas = null;
        function updateBoxHint(input) {
            if (!input) { return; }
            var wrap = input.parentNode;
            if (!wrap || !wrap.classList.contains('product-request-sku-box-wrap')) { return; }
            var hint = wrap.querySelector('[data-sku-box-hint]');
            if (!hint) { return; }
            var value = String(input.value || '');
            var starIndex = value.lastIndexOf('*');
            var starEmpty = starIndex !== -1 && value.slice(starIndex + 1).trim() === '';
            if (!starEmpty) { hint.style.display = 'none'; return; }
            if (!hintCanvas) { hintCanvas = document.createElement('canvas'); }
            var context = hintCanvas.getContext('2d');
            var style = window.getComputedStyle(input);
            context.font = style.font || (style.fontSize + ' ' + style.fontFamily);
            var paddingLeft = parseFloat(style.paddingLeft) || 0;
            hint.style.left = (paddingLeft + context.measureText(value.slice(0, starIndex + 1)).width + 1) + 'px';
            hint.style.display = 'block';
        }

        function bindRow(row) {
            var add = row.querySelector('[data-sku-add]');
            if (add) { add.addEventListener('click', addRow); }
            var remove = row.querySelector('[data-sku-remove]');
            if (remove) {
                remove.addEventListener('click', function () {
                    if (rows().length <= 1) { return; }
                    row.parentNode.removeChild(row);
                    sync();
                });
            }
            Array.prototype.slice.call(row.querySelectorAll('input[data-sku-part]')).forEach(function (input) {
                input.addEventListener('input', sync);
                if (input.getAttribute('data-sku-part') === 'box') {
                    input.addEventListener('input', function () { updateBoxHint(input); });
                }
            });
            updateBoxHint(row.querySelector('input[data-sku-part="box"]'));
            var pinyin = row.querySelector('input[data-sku-part="pinyin"]');
            if (pinyin) {
                pinyin.addEventListener('input', function () {
                    rows().forEach(function (other) {
                        if (other === row) { return; }
                        var target = other.querySelector('input[data-sku-part="pinyin"]');
                        if (target && String(target.value || '').trim() === '') { target.value = pinyin.value; }
                    });
                    sync();
                });
            }
        }

        function addRow() {
            if (rows().length >= max) {
                if (warn) {
                    warn.textContent = (config.skuTooMany || '').replace(':count', max);
                    warn.style.display = '';
                }
                return;
            }
            var row = template.cloneNode(true);
            Array.prototype.slice.call(row.querySelectorAll('input[data-sku-part]')).forEach(function (input) { input.value = ''; });
            var pinyin = row.querySelector('input[data-sku-part="pinyin"]');
            if (pinyin) { pinyin.value = defaultPinyin(); }
            var templateBox = template.querySelector('input[data-sku-part="box"]');
            var box = row.querySelector('input[data-sku-part="box"]');
            if (box) { box.value = templateBox && templateBox.value.trim() !== '' ? templateBox.value : boxDefault; }
            builder.appendChild(row);
            bindRow(row);
            var focusTarget = pinyin && pinyin.value !== '' ? row.querySelector('input[data-sku-part="attribute"]') : pinyin;
            if (focusTarget) { focusTarget.focus(); }
            sync();
        }

        bindRow(template);

        // 编辑模式：从 hidden input 回显已有 SKU
        var existing = String(hidden.value || '').trim();
        if (existing !== '') {
            var skuList = existing.split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
            if (skuList.length > 0) {
                // 清空模板行的输入
                Array.prototype.slice.call(template.querySelectorAll('input[data-sku-part]')).forEach(function (input) { input.value = ''; });
                skuList.forEach(function (sku, index) {
                    var row = index === 0 ? template : template.cloneNode(true);
                    if (index > 0) {
                        Array.prototype.slice.call(row.querySelectorAll('input[data-sku-part]')).forEach(function (input) { input.value = ''; });
                    }
                    // 去掉前缀，解析 pinyin-attribute-box
                    var rest = sku;
                    if (prefix !== '' && rest.indexOf(prefix) === 0) { rest = rest.slice(prefix.length); }
                    var parts = rest.split('-');
                    var box = parts.pop() || '';
                    var attribute = parts.pop() || '';
                    var pinyin = parts.join('-');
                    var pinyinInput = row.querySelector('[data-sku-part="pinyin"]');
                    var attrInput = row.querySelector('[data-sku-part="attribute"]');
                    var boxInput = row.querySelector('[data-sku-part="box"]');
                    if (pinyinInput) { pinyinInput.value = pinyin; }
                    if (attrInput) { attrInput.value = attribute; }
                    if (boxInput) { boxInput.value = box; }
                    if (index > 0) {
                        builder.appendChild(row);
                        bindRow(row);
                    }
                });
            }
        }

        sync();
    }

    function initializeRichEditor(root) {
        root.querySelectorAll('[data-product-request-editor]').forEach(function (editor) {
            if (editor.dataset.productRequestEditorReady === '1') { return; }
            editor.dataset.productRequestEditorReady = '1';
            var content = editor.querySelector('[data-editor-content]');
            var fileInput = editor.querySelector('[data-editor-image-input]');
            var form = editor.closest('form');
            var input = form ? form.querySelector('[data-editor-input]') : null;
            if (!content || !input) { return; }

            content.innerHTML = input.value || '';
            var savedRange = null;

            function sync() { input.value = content.innerHTML; }

            function saveRange() {
                var selection = window.getSelection();
                if (selection && selection.rangeCount > 0 && content.contains(selection.anchorNode)) {
                    savedRange = selection.getRangeAt(0).cloneRange();
                }
            }

            function restoreRange() {
                if (!savedRange) { return; }
                var selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(savedRange);
            }

            function insertHtml(html) {
                content.focus();
                restoreRange();
                document.execCommand('insertHTML', false, html);
                saveRange();
                sync();
            }

            function upload(file) {
                if (!isSupported(file)) { notify('warning', config.invalidFile); return; }
                var token = 'u' + Date.now() + Math.random().toString(36).slice(2);
                function placeholderNode() { return content.querySelector('[data-editor-uploading="' + token + '"]'); }
                function dropPlaceholder() {
                    var node = placeholderNode();
                    if (node && node.parentNode) { node.parentNode.removeChild(node); }
                    sync();
                }
                insertHtml('<span class="product-request-rich-uploading" data-editor-uploading="' + token + '">' + (config.imageUploading || '') + '</span>');
                var data = new FormData();
                data.append('image', file);
                fetch(editor.getAttribute('data-upload-url'), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': config.csrfToken || '', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: data
                }).then(function (response) {
                    return response.json().catch(function () { return {}; }).then(function (payload) {
                        return { response: response, payload: payload };
                    });
                }).then(function (result) {
                    if (!result.response.ok || !result.payload.url) {
                        dropPlaceholder();
                        notify('warning', result.payload.message || config.imageUploadFailed);
                        return;
                    }
                    var image = document.createElement('img');
                    image.src = result.payload.url;
                    image.alt = file.name || '';
                    var node = placeholderNode();
                    if (node && node.parentNode) { node.parentNode.replaceChild(image, node); }
                    else { content.appendChild(image); }
                    sync();
                }).catch(function () {
                    dropPlaceholder();
                    notify('warning', config.imageUploadFailed);
                });
            }
            editor.erpEditorUpload = upload;

            ['keyup', 'mouseup', 'input', 'blur'].forEach(function (name) {
                content.addEventListener(name, saveRange);
            });
            content.addEventListener('input', sync);
            content.addEventListener('blur', sync);

            editor.querySelectorAll('[data-editor-command]').forEach(function (button) {
                var command = button.getAttribute('data-editor-command');
                button.addEventListener('mousedown', function (event) { event.preventDefault(); });
                button.addEventListener('click', function () {
                    if (command === 'insertImage') {
                        if (fileInput) { fileInput.click(); }
                        return;
                    }
                    content.focus();
                    restoreRange();
                    document.execCommand(command, false, null);
                    saveRange();
                    sync();
                });
            });

            if (fileInput) {
                fileInput.addEventListener('change', function () {
                    var files = inputFiles(fileInput);
                    fileInput.value = '';
                    files.forEach(function (file) { upload(file); });
                });
            }

            sync();
        });
    }

    function initialize(root) {
        try {
            initializeUploads(root);
        } catch (error) {
            reportInitializationError('upload initializer failed', error);
        }
        try {
            initializeSkuBuilder(root);
        } catch (error) {
            reportInitializationError('sku builder initializer failed', error);
        }
        try {
            initializeReuse(root);
        } catch (error) {
            reportInitializationError('reuse initializer failed', error);
        }
        try {
            initializeSkuSuggestions(root);
        } catch (error) {
            reportInitializationError('sku suggestions initializer failed', error);
        }
        try {
            initializeRadioChoices(root);
        } catch (error) {
            reportInitializationError('radio initializer failed', error);
        }
        try {
            initializeRichEditor(root);
        } catch (error) {
            reportInitializationError('rich editor initializer failed', error);
        }
    }

    function imageLightbox() {
        var existing = document.querySelector('[data-product-request-image-lightbox]');
        if (existing) { return existing; }
        var lightbox = document.createElement('div');
        lightbox.className = 'product-request-image-lightbox';
        lightbox.dataset.productRequestImageLightbox = 'true';
        lightbox.hidden = true;
        var content = document.createElement('div');
        content.className = 'product-request-image-lightbox-content';
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'product-request-image-lightbox-close';
        close.textContent = '\u00d7';
        var image = document.createElement('img');
        image.alt = '';
        content.appendChild(close);
        content.appendChild(image);
        lightbox.appendChild(content);
        document.body.appendChild(lightbox);
        function closeLightbox() {
            lightbox.hidden = true;
            document.body.classList.remove('product-request-lightbox-open');
        }
        close.addEventListener('click', closeLightbox);
        lightbox.addEventListener('click', function (event) {
            if (event.target === lightbox) { closeLightbox(); }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !lightbox.hidden) { closeLightbox(); }
        });
        return lightbox;
    }

    function openImageLightbox(target) {
        var source = target.getAttribute('data-product-request-image-url') || target.getAttribute('href');
        if (!source && target.tagName === 'IMG') { source = target.currentSrc || target.src; }
        if (!source) {
            var nested = target.querySelector ? target.querySelector('img') : null;
            source = nested ? (nested.currentSrc || nested.src) : '';
        }
        if (!source) { return; }
        var lightbox = imageLightbox();
        var image = lightbox.querySelector('img');
        image.src = source;
        image.alt = target.getAttribute('data-product-request-image-alt') || (target.querySelector('img') || {}).alt || '';
        lightbox.hidden = false;
        document.body.classList.add('product-request-lightbox-open');
    }

    function deleteRecord(button) {
        var url = button.getAttribute('data-delete-url');
        if (!url || button.disabled) { return; }
        if (config.deleteConfirm && !window.confirm(config.deleteConfirm)) { return; }
        button.disabled = true;
        var row = button.closest ? button.closest('tr') : null;
        var xhr = new XMLHttpRequest();
        xhr.open('DELETE', url, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-Product-Request-Modal', '1');
        xhr.setRequestHeader('Accept', 'application/json');
        if (config.csrfToken) { xhr.setRequestHeader('X-CSRF-TOKEN', config.csrfToken); }
        xhr.addEventListener('load', function () {
            var payload = {};
            try { payload = JSON.parse(xhr.responseText) || {}; } catch (error) { payload = {}; }
            if (xhr.status < 200 || xhr.status >= 300) {
                button.disabled = false;
                notify('warning', payload.message || config.deleteFailed);
                return;
            }
            notify('success', payload.message || '');
            if (!row || !row.parentNode) {
                window.location.reload();
                return;
            }
            var body = row.parentNode;
            body.removeChild(row);
            if (!body.querySelector('tr')) { window.location.reload(); }
        });
        xhr.addEventListener('error', function () {
            button.disabled = false;
            notify('warning', config.deleteFailed);
        });
        xhr.send();
    }

    document.addEventListener('click', function (event) {
        var confirmBtn = event.target.closest ? event.target.closest('[data-product-request-confirm]') : null;
        if (confirmBtn) {
            if (!window.confirm(confirmBtn.getAttribute('data-product-request-confirm'))) {
                event.preventDefault();
            }
            return;
        }
        var deleteButton = event.target.closest ? event.target.closest('[data-product-request-delete]') : null;
        if (deleteButton) {
            event.preventDefault();
            deleteRecord(deleteButton);
            return;
        }
        var imagePreview = event.target.closest ? event.target.closest('[data-product-request-image-preview]') : null;
        if (imagePreview) {
            event.preventDefault();
            openImageLightbox(imagePreview);
            return;
        }
        var opener = event.target.closest ? event.target.closest('[data-product-request-modal-url]') : null;
        if (!opener) { return; }
        event.preventDefault();
        openEditor(opener.getAttribute('data-product-request-modal-url'));
    });

    document.addEventListener('paste', function (event) {
        var active = document.activeElement;
        var richEditor = active && active.closest ? active.closest('[data-product-request-editor]') : null;
        if (richEditor && typeof richEditor.erpEditorUpload === 'function') {
            var richFiles = event.clipboardData ? event.clipboardData.files : null;
            if (richFiles && richFiles.length) {
                event.preventDefault();
                richEditor.erpEditorUpload(richFiles[0]);
            }
            return;
        }
        var container = active && active.closest ? active.closest('[data-product-request-upload]') : null;
        if (!container) {
            var host = active && active.closest ? active.closest('[data-product-request-paste-host]') : null;
            container = host ? host.querySelector('[data-product-request-upload]') : null;
        }
        if (!container) { return; }
        var input = container.querySelector('input[type="file"]');
        if (!input) { return; }
        var files = event.clipboardData ? event.clipboardData.files : null;
        if (!files || !files.length) { return; }
        event.preventDefault();
        addFiles(container, input, parseInt(container.getAttribute('data-max-files') || '1', 10), files);
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.matches || !form.matches('[data-product-request-modal-form]')) { return; }
        event.preventDefault();
        clearValidationErrors(form);
        var submitter = event.submitter || null;
        var isRejected = submitter && submitter.name === 'result' && submitter.value === 'rejected';
        // 审核不通过时只需填写理由，跳过上传必填校验
        if (!isRejected && !validateRequiredUploads(form)) { return; }
        var formData = new FormData(form);
        if (submitter && submitter.name && !formData.has(submitter.name)) {
            formData.append(submitter.name, submitter.value);
        }
        var buttons = form.querySelectorAll('[type="submit"]');
        buttons.forEach(function (button) { button.disabled = true; });
        var progress = submitProgress(form);
        var progressBar = progress ? progress.querySelector('[data-submit-progress-bar]') : null;
        var progressText = progress ? progress.querySelector('[data-submit-progress-text]') : null;
        var progressTimer = null;
        var progressValue = 0;
        function setProgress(value) {
            if (!progress) { return; }
            progressValue = Math.max(0, Math.min(100, value));
            progress.hidden = false;
            if (progressBar) { progressBar.style.width = progressValue + '%'; }
            if (progressText) { progressText.textContent = (config.submitProgress || '') + ' ' + Math.round(progressValue) + '%'; }
        }
        function bumpProgress(value) {
            if (value > progressValue) { setProgress(value); }
        }
        function startProgress() {
            setProgress(3);
            progressTimer = window.setInterval(function () {
                if (progressValue >= 90) { return; }
                bumpProgress(progressValue + Math.max(1, Math.round((90 - progressValue) * 0.08)));
            }, 300);
        }
        function stopProgress() {
            if (progressTimer) { window.clearInterval(progressTimer); progressTimer = null; }
        }
        function resetProgress() {
            stopProgress();
            if (!progress) { return; }
            progress.hidden = true;
            if (progressBar) { progressBar.style.width = '0%'; }
            if (progressText) { progressText.textContent = ''; }
        }
        startProgress();
        var xhr = new XMLHttpRequest();
        xhr.open((form.getAttribute('method') || 'post').toUpperCase(), form.getAttribute('action'), true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-Product-Request-Modal', '1');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.addEventListener('progress', function (event) {
            if (event.lengthComputable && event.total > 0) {
                bumpProgress(5 + Math.round((event.loaded / event.total) * 85));
            }
        });
        xhr.addEventListener('load', function () {
            var payload = {};
            try { payload = JSON.parse(xhr.responseText) || {}; } catch (error) { payload = {}; }
            if (xhr.status === 422) {
                showValidationErrors(form, payload.errors || {});
                return;
            }
            if (xhr.status < 200 || xhr.status >= 300) {
                notify('warning', payload.message || config.submitFailed);
                return;
            }
            stopProgress();
            setProgress(100);
            window.setTimeout(function () {
                hideEditor();
                notify('success', payload.message || '');
                window.location.assign(payload.redirect || window.location.href);
            }, 420);
        });
        xhr.addEventListener('error', function () { notify('warning', config.submitFailed); });
        xhr.addEventListener('loadend', function () {
            if (!(progress && xhr.status >= 200 && xhr.status < 300)) { resetProgress(); }
            buttons.forEach(function (button) { button.disabled = false; });
        });
        xhr.send(formData);
    });

    if (window.jQuery && editorModal) {
        window.jQuery(editorModal).on('hidden.bs.modal', function () {
            setEditorMessage(config.loading, false);
        });
    }
}());
