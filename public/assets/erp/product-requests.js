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
        Array.prototype.slice.call(incoming || []).forEach(function (file) {
            if (!isSupported(file)) {
                notify('warning', config.invalidFile);
                return;
            }
            if (current.length >= maxFiles) {
                notify('warning', (config.tooMany || '').replace(':count', maxFiles));
                return;
            }
            current.push(file);
        });
        syncInput(input, current);
        renderPreview(container, input);
    }

    function initializeUploads(root) {
        root.querySelectorAll('[data-product-request-upload]').forEach(function (container) {
            if (container.dataset.productRequestUploadReady === '1') { return; }
            container.dataset.productRequestUploadReady = '1';
            var input = container.querySelector('input[type="file"]');
            if (!input) { return; }
            var maxFiles = parseInt(container.getAttribute('data-max-files') || '1', 10);
            input.addEventListener('change', function () { renderPreview(container, input); });
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
            if (!input || container.getAttribute('data-required') !== '1' || inputFiles(input).length > 0) { return; }
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

        function closeReuse() {
            if (overlay) { overlay.hidden = true; }
        }

        function applyRecord(record) {
            ['standard_chinese_name', 'supplier_url', 'company_spu', 'sku_sequence', 'standard_sku', 'minimum_order_quantity', 'minimum_purchase_price_rmb', 'estimated_shipping_weight_g'].forEach(function (field) {
                var input = reuseForm.querySelector('[name="' + field + '"]');
                if (input) { input.value = record[field] || ''; }
            });
            ['stock_status', 'erp_binding_status'].forEach(function (field) {
                var input = reuseForm.querySelector('[name="' + field + '"][value="' + (record[field] || '') + '"]');
                if (input) { input.checked = true; }
            });
            if (selectedSource) { selectedSource.value = record.id; }
            if (selectedText) {
                selectedText.hidden = false;
                selectedText.textContent = (config.reuseSelected || '').replace(':report_no', record.report_no || '');
            }
            closeReuse();
        }

        function appendResult(record) {
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
            detail.textContent = [record.report_no, record.company_spu, record.supplier_url].filter(Boolean).join(' · ');
            content.appendChild(name);
            content.appendChild(detail);
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-success btn-sm';
            button.textContent = config.reuse || '';
            button.addEventListener('click', function () { applyRecord(record); });
            row.appendChild(content);
            row.appendChild(button);
            results.appendChild(row);
        }

        if (overlay) {
            overlay.querySelectorAll('[data-product-request-reuse-close]').forEach(function (button) {
                button.addEventListener('click', closeReuse);
            });
        }
        if (!reuseButton || !overlay || !results) { return; }
        reuseButton.addEventListener('click', function () {
            var name = reuseForm.querySelector('[name="standard_chinese_name"]');
            var url = reuseForm.querySelector('[name="supplier_url"]');
            if (!name || !url || !name.value.trim() || !url.value.trim()) {
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
            body.append('standard_chinese_name', name.value.trim());
            body.append('supplier_url', url.value.trim());
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
                    closeReuse();
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
                closeReuse();
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
        var checkButton = form ? form.querySelector('[data-sku-check]') : null;
        if (!panel || !list || !form) { return; }

        function updateSummary() {
            var checked = form.querySelectorAll('input[name="selected_skus[]"]:checked');
            var parts = [];
            for (var i = 0; i < checked.length; i++) { parts.push(checked[i].value); }
            if (summaryRow) { summaryRow.hidden = parts.length === 0; }
            if (summary) { summary.textContent = parts.length ? '已勾选 ' + parts.length + ' 个：' + parts.join(', ') : ''; }
        }

        function render(items) {
            list.innerHTML = '';
            if (countEl) { countEl.textContent = ''; }
            if (!items.length) {
                var empty = document.createElement('span');
                empty.className = 'sku-suggestion-empty';
                empty.textContent = '该链接在系统中暂无已录入 SKU';
                list.appendChild(empty);
                panel.hidden = false;
                updateSummary();
                return;
            }
            var total = 0;
            items.forEach(function (item) {
                (item.skus || []).forEach(function (sku) {
                    total += 1;
                    var label = document.createElement('label');
                    var checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.name = 'selected_skus[]';
                    checkbox.value = sku;
                    checkbox.addEventListener('change', updateSummary);
                    label.appendChild(checkbox);
                    label.appendChild(document.createTextNode(sku));
                    var source = document.createElement('span');
                    source.className = 'sku-source';
                    source.textContent = '（' + (item.report_no || '') + '）';
                    label.appendChild(source);
                    list.appendChild(label);
                });
            });
            if (countEl) { countEl.textContent = '共 ' + total + ' 个'; }
            panel.hidden = false;
            updateSummary();
        }

        function fetchSuggestions() {
            var value = input.value.trim();
            if (!/^https?:\/\//i.test(value)) { panel.hidden = true; if (summaryRow) { summaryRow.hidden = true; } return; }
            var query = '?supplier_url=' + encodeURIComponent(value) + (currentId ? '&current_id=' + encodeURIComponent(currentId) : '');
            fetch(url + query, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) { return response.ok ? response.json() : { items: [] }; })
                .then(function (payload) { render(payload.items || []); })
                .catch(function () { panel.hidden = true; });
        }

        if (checkButton) { checkButton.addEventListener('click', fetchSuggestions); }
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

    function initialize(root) {
        try {
            initializeUploads(root);
        } catch (error) {
            reportInitializationError('upload initializer failed', error);
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

    document.addEventListener('click', function (event) {
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
        var container = active && active.closest ? active.closest('[data-product-request-upload]') : null;
        if (!container) { return; }
        var input = container.querySelector('input[type="file"]');
        if (!input) { return; }
        event.preventDefault();
        addFiles(container, input, parseInt(container.getAttribute('data-max-files') || '1', 10), event.clipboardData ? event.clipboardData.files : []);
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.matches || !form.matches('[data-product-request-modal-form]')) { return; }
        event.preventDefault();
        clearValidationErrors(form);
        if (!validateRequiredUploads(form)) { return; }
        var submitter = event.submitter || null;
        var formData = new FormData(form);
        if (submitter && submitter.name && !formData.has(submitter.name)) {
            formData.append(submitter.name, submitter.value);
        }
        var buttons = form.querySelectorAll('[type="submit"]');
        buttons.forEach(function (button) { button.disabled = true; });
        fetch(form.getAttribute('action'), {
            method: (form.getAttribute('method') || 'post').toUpperCase(),
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Product-Request-Modal': '1', 'Accept': 'application/json' },
            body: formData
        }).then(function (response) {
            return response.json().then(function (payload) { return { response: response, payload: payload }; });
        }).then(function (result) {
            if (result.response.status === 422) {
                showValidationErrors(form, result.payload.errors || {});
                return;
            }
            if (!result.response.ok) {
                notify('warning', result.payload.message || config.submitFailed);
                return;
            }
            hideEditor();
            notify('success', result.payload.message || '');
            window.location.assign(result.payload.redirect || window.location.href);
        }).catch(function () {
            notify('warning', config.submitFailed);
        }).finally(function () {
            buttons.forEach(function (button) { button.disabled = false; });
        });
    });

    if (window.jQuery && editorModal) {
        window.jQuery(editorModal).on('hidden.bs.modal', function () {
            setEditorMessage(config.loading, false);
        });
    }
}());
