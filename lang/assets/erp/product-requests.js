(function () {
    'use strict';

    var config = window.ErpProductRequestConfig || {};
    var uploads = document.querySelectorAll('[data-product-request-upload]');

    function isSupported(file) {
        var allowed = ['image/jpeg', 'image/png', 'image/webp'];
        return allowed.indexOf(file.type) !== -1 && file.size <= 5 * 1024 * 1024;
    }

    function message(text) {
        if (window.ErpAdminNotifier && text) {
            window.ErpAdminNotifier.notify({ type: 'warning', title: config.noticeTitle || '', message: text });
            return;
        }
        if (text) { window.alert(text); }
    }

    function inputFiles(input) {
        return Array.prototype.slice.call(input.files || []);
    }

    function syncInput(input, files) {
        var data = new DataTransfer();
        files.forEach(function (file) { data.items.add(file); });
        input.files = data.files;
    }

    function renderPreview(container, input, maxFiles) {
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
                renderPreview(container, input, maxFiles);
            });
            item.appendChild(image);
            item.appendChild(remove);
            preview.appendChild(item);
        });
    }

    function addFiles(container, input, maxFiles, files) {
        var current = inputFiles(input);
        Array.prototype.slice.call(files || []).forEach(function (file) {
            if (!isSupported(file)) {
                message(config.invalidFile);
                return;
            }
            if (current.length >= maxFiles) {
                message((config.tooMany || '').replace(':count', maxFiles));
                return;
            }
            current.push(file);
        });
        syncInput(input, current);
        renderPreview(container, input, maxFiles);
    }

    uploads.forEach(function (container) {
        var input = container.querySelector('input[type="file"]');
        if (!input) { return; }
        var maxFiles = parseInt(container.getAttribute('data-max-files') || '1', 10);
        container.addEventListener('click', function () { container.focus(); });
        input.addEventListener('change', function () {
            var selected = inputFiles(input).filter(isSupported).slice(0, maxFiles);
            if (selected.length !== inputFiles(input).length) {
                message(config.invalidFile);
            }
            syncInput(input, selected);
            renderPreview(container, input, maxFiles);
        });
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
            });
        });
        container.addEventListener('drop', function (event) { addFiles(container, input, maxFiles, event.dataTransfer.files); });
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

    var reuseForm = document.querySelector('[data-product-request-reuse-form]');
    if (!reuseForm) { return; }
    var reuseButton = reuseForm.querySelector('[data-product-request-reuse-check]');
    var modal = document.querySelector('[data-product-request-reuse-modal]');
    var results = modal ? modal.querySelector('[data-product-request-reuse-results]') : null;
    var selectedText = reuseForm.querySelector('[data-product-request-reuse-selected]');
    var selectedSource = reuseForm.querySelector('[name="reuse_source_id"]');

    function closeModal() {
        if (window.jQuery && modal) { window.jQuery(modal).modal('hide'); }
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
        closeModal();
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

    if (reuseButton && modal && results) {
        reuseButton.addEventListener('click', function () {
            var name = reuseForm.querySelector('[name="standard_chinese_name"]');
            var url = reuseForm.querySelector('[name="supplier_url"]');
            if (!name || !url || !name.value.trim() || !url.value.trim()) {
                message(config.reuseRequired);
                return;
            }
            results.innerHTML = '';
            if (window.jQuery) { window.jQuery(modal).modal('show'); }
            var body = new URLSearchParams();
            body.append('standard_chinese_name', name.value.trim());
            body.append('supplier_url', url.value.trim());
            body.append('current_id', reuseForm.getAttribute('data-current-id') || '');
            fetch(reuseForm.getAttribute('data-reuse-check-url'), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': config.csrfToken || '', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (response) { return response.json(); }).then(function (payload) {
                if (!payload.records || payload.records.length === 0) {
                    var empty = document.createElement('p');
                    empty.className = 'text-muted';
                    empty.textContent = config.reuseEmpty || '';
                    results.appendChild(empty);
                    return;
                }
                payload.records.forEach(appendResult);
            }).catch(function () { message(config.reuseFailed); closeModal(); });
        });
    }
}());
