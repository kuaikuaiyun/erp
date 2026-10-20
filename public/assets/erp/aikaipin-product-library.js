(function (window, document) {
    'use strict';

    var config = window.ErpAikaipinProductLibraryConfig || {};
    var form = document.querySelector('[data-aikaipin-filter-form]');
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-aikaipin-product]'));
    var emptyRow = document.querySelector('[data-aikaipin-empty-row]');
    var count = document.querySelector('[data-aikaipin-record-count]');
    var selectAll = document.querySelector('[data-aikaipin-select-all]');
    var selectedCount = document.querySelector('[data-aikaipin-selected-count]');
    var batchButtons = Array.prototype.slice.call(document.querySelectorAll('[data-aikaipin-batch-action]'));
    var visibilityModal = document.getElementById('aikaipin-visibility-modal');
    var visibilityTitle = document.querySelector('[data-aikaipin-visibility-title]');
    var visibilityDescription = document.querySelector('[data-aikaipin-visibility-description]');
    var visibilityConfirm = document.querySelector('[data-aikaipin-visibility-confirm]');
    var imagePreviewModal = document.getElementById('aikaipin-image-preview-modal');
    var imagePreviewTitle = document.querySelector('[data-aikaipin-image-preview-title]');
    var imagePreviewStage = document.querySelector('[data-aikaipin-image-preview-stage]');
    var imagePreviewPosition = document.querySelector('[data-aikaipin-image-preview-position]');
    var imagePreviewThumbnails = document.querySelector('[data-aikaipin-image-preview-thumbnails]');
    var imagePreviewPrevious = document.querySelector('[data-aikaipin-image-preview-prev]');
    var imagePreviewNext = document.querySelector('[data-aikaipin-image-preview-next]');
    var uploadModal = document.getElementById('aikaipin-upload-modal');
    var uploadForm = document.querySelector('[data-aikaipin-upload-form]');
    var uploadName = document.querySelector('[data-aikaipin-upload-name]');
    var skuInput = document.querySelector('[data-aikaipin-sku-input]');
    var skuTags = document.querySelector('[data-aikaipin-sku-tags]');
    var categoryInput = document.querySelector('[data-aikaipin-category-input]');
    var categoryChoices = document.querySelector('[data-aikaipin-category-choices]');
    var uploadImages = document.querySelector('[data-aikaipin-upload-images]');
    var imagePreviews = document.querySelector('[data-aikaipin-image-previews]');
    var referenceFile = document.querySelector('[data-aikaipin-reference-file]');
    var referenceFileName = document.querySelector('[data-aikaipin-reference-file-name]');
    var packageFile = document.querySelector('[data-aikaipin-package-file]');
    var packageFileName = document.querySelector('[data-aikaipin-package-file-name]');
    var uploadProgress = document.querySelector('[data-aikaipin-upload-progress]');
    var uploadProgressBar = document.querySelector('[data-aikaipin-upload-progress-bar]');
    var uploadSubmit = document.querySelector('[data-aikaipin-upload-submit]');
    var uploadSkus = [];
    var uploadImageFiles = [];
    var uploadTimer = null;
    var uploadCategories = loadCategories();
    var previewImages = [];
    var previewImageIndex = 0;
    var previewProductTitle = '';
    var filters = {
        keyword: document.querySelector('[data-aikaipin-filter="keyword"]'),
        category: document.querySelector('[data-aikaipin-filter="category"]'),
        source: document.querySelector('[data-aikaipin-filter="source"]'),
        type: document.querySelector('[data-aikaipin-filter="type"]')
    };

    function notify(type, title, message) {
        if (window.ErpAdminNotifier && typeof window.ErpAdminNotifier.notify === 'function') {
            window.ErpAdminNotifier.notify({
                type: type,
                title: title,
                message: message
            });
        }
    }

    function filterValue(name) {
        return filters[name] ? String(filters[name].value || '').toLowerCase() : '';
    }

    function applyFilters() {
        var keyword = filterValue('keyword');
        var category = filterValue('category');
        var source = filterValue('source');
        var type = filterValue('type');
        var visible = 0;

        rows.forEach(function (row) {
            var matches = (!keyword || String(row.getAttribute('data-keyword') || '').indexOf(keyword) !== -1)
                && (!category || row.getAttribute('data-category') === category)
                && (!source || row.getAttribute('data-source') === source)
                && (!type || row.getAttribute('data-type') === type);

            row.hidden = !matches;
            if (matches) {
                visible += 1;
            }
        });

        if (emptyRow) {
            emptyRow.hidden = visible !== 0;
        }

        if (count) {
            count.textContent = visible + ' / ' + rows.length;
        }

        refreshSelection();
    }

    function clearFilters() {
        if (config.serverFilters) {
            window.location.assign(config.filterResetUrl || (form ? form.action : window.location.pathname));
            return;
        }

        Object.keys(filters).forEach(function (name) {
            if (filters[name]) {
                filters[name].value = '';
            }
        });

        applyFilters();
    }

    function selectionBoxes() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-aikaipin-product-select]'));
    }

    function visibleSelectionBoxes() {
        return selectionBoxes().filter(function (box) {
            return !box.closest('[data-aikaipin-product]').hidden;
        });
    }

    function selectedSelectionBoxes() {
        return selectionBoxes().filter(function (box) {
            return box.checked;
        });
    }

    function replaceCount(template, value) {
        return String(template || '').replace(':count', String(value));
    }

    function refreshSelection() {
        var visibleBoxes = visibleSelectionBoxes();
        var selectedBoxes = selectedSelectionBoxes();
        var selectedTotal = selectedBoxes.length;

        if (selectAll) {
            selectAll.checked = visibleBoxes.length > 0 && visibleBoxes.every(function (box) {
                return box.checked;
            });
            selectAll.indeterminate = !selectAll.checked && visibleBoxes.some(function (box) {
                return box.checked;
            });
        }

        batchButtons.forEach(function (button) {
            button.disabled = selectedTotal === 0;
        });

        if (selectedCount) {
            selectedCount.textContent = selectedTotal > 0
                ? replaceCount(config.selectedCount, selectedTotal)
                : config.selectedNone;
        }
    }

    function openVisibilityModal(mode) {
        var selectedTotal = selectedSelectionBoxes().length;
        if (selectedTotal === 0) {
            notify('warning', config.selectionRequiredTitle, config.selectionRequiredMessage);
            return;
        }

        if (visibilityTitle) {
            visibilityTitle.textContent = mode === 'enable'
                ? config.enableVisibilityTitle
                : config.cancelVisibilityTitle;
        }
        if (visibilityDescription) {
            visibilityDescription.textContent = replaceCount(
                mode === 'enable' ? config.enableVisibilityDescription : config.cancelVisibilityDescription,
                selectedTotal
            );
        }
        if (visibilityConfirm) {
            visibilityConfirm.textContent = mode === 'enable'
                ? config.enableVisibilityConfirm
                : config.cancelVisibilityConfirm;
            visibilityConfirm.className = mode === 'enable'
                ? 'btn btn-primary'
                : 'btn btn-warning';
        }

        if (visibilityModal && window.jQuery) {
            window.jQuery(visibilityModal).modal('show');
        }
    }

    function previewImageUrl(image) {
        if (image && typeof image === 'object') {
            return String(image.url || '');
        }

        return typeof image === 'string' ? image : '';
    }

    function previewModalIsOpen() {
        return Boolean(imagePreviewModal && window.jQuery && window.jQuery(imagePreviewModal).hasClass('in'));
    }

    function renderImagePreview() {
        var total = previewImages.length;
        var image = previewImages[previewImageIndex];
        var imageUrl = previewImageUrl(image);

        if (!imagePreviewStage || total === 0) {
            return;
        }

        imagePreviewStage.innerHTML = '';
        if (imageUrl) {
            var previewImage = document.createElement('img');
            previewImage.src = imageUrl;
            previewImage.alt = replaceTokens(config.imagePreviewImageAlt, {number: previewImageIndex + 1});
            imagePreviewStage.appendChild(previewImage);
        } else {
            var placeholder = document.createElement('div');
            placeholder.className = 'aikaipin-image-preview-placeholder aikaipin-image-preview-placeholder--' + ((previewImageIndex % 3) + 1);
            placeholder.innerHTML = '<i class="fa fa-picture-o" aria-hidden="true"></i><span class="sr-only"></span>';
            placeholder.querySelector('.sr-only').textContent = config.imagePreviewPlaceholder;
            imagePreviewStage.appendChild(placeholder);
        }

        if (imagePreviewTitle) {
            imagePreviewTitle.textContent = replaceTokens(config.imagePreviewTitle, {name: previewProductTitle});
        }
        if (imagePreviewPosition) {
            imagePreviewPosition.textContent = replaceTokens(config.imagePreviewPosition, {
                current: previewImageIndex + 1,
                total: total
            });
        }
        if (imagePreviewPrevious) {
            imagePreviewPrevious.disabled = total <= 1;
        }
        if (imagePreviewNext) {
            imagePreviewNext.disabled = total <= 1;
        }
        if (imagePreviewThumbnails) {
            imagePreviewThumbnails.innerHTML = '';
            previewImages.forEach(function (item, index) {
                var thumbnail = document.createElement('button');

                thumbnail.type = 'button';
                thumbnail.className = 'aikaipin-image-preview-thumbnail aikaipin-image-preview-thumbnail--' + ((index % 3) + 1)
                    + (index === previewImageIndex ? ' active' : '');
                thumbnail.setAttribute('role', 'tab');
                thumbnail.setAttribute('aria-selected', index === previewImageIndex ? 'true' : 'false');
                thumbnail.setAttribute('aria-label', replaceTokens(config.imagePreviewThumbnail, {number: index + 1}));
                thumbnail.setAttribute('data-aikaipin-image-preview-index', String(index));
                thumbnail.innerHTML = '<i class="fa fa-picture-o" aria-hidden="true"></i>';
                imagePreviewThumbnails.appendChild(thumbnail);
            });
        }
    }

    function setPreviewImage(index) {
        if (previewImages.length === 0) {
            return;
        }

        previewImageIndex = (index + previewImages.length) % previewImages.length;
        renderImagePreview();
    }

    function openImagePreview(button) {
        var rawImages = button.getAttribute('data-aikaipin-preview-images') || '[]';

        try {
            previewImages = JSON.parse(rawImages);
        } catch (error) {
            previewImages = [];
        }

        if (!Array.isArray(previewImages) || previewImages.length === 0) {
            return;
        }

        previewImageIndex = 0;
        previewProductTitle = button.getAttribute('data-aikaipin-preview-title') || '';
        renderImagePreview();

        if (imagePreviewModal && window.jQuery) {
            window.jQuery(imagePreviewModal).modal('show');
        }
    }

    function replaceTokens(template, tokens) {
        var result = String(template || '');

        Object.keys(tokens).forEach(function (key) {
            result = result.replace(':' + key, String(tokens[key]));
        });

        return result;
    }

    function fileSize(bytes) {
        if (bytes < 1024 * 1024) {
            return Math.ceil(bytes / 1024) + ' KB';
        }

        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function renderSkus() {
        if (!skuTags) {
            return;
        }

        skuTags.innerHTML = '';
        uploadSkus.forEach(function (sku) {
            var tag = document.createElement('span');
            var removeButton = document.createElement('button');

            tag.className = 'aikaipin-sku-tag';
            tag.appendChild(document.createTextNode(sku));
            removeButton.type = 'button';
            removeButton.setAttribute('aria-label', sku);
            removeButton.setAttribute('data-aikaipin-remove-sku', sku);
            removeButton.innerHTML = '&times;';
            tag.appendChild(removeButton);
            skuTags.appendChild(tag);
        });
    }

    function addSku() {
        var value = skuInput ? String(skuInput.value || '').trim() : '';
        if (!value) {
            notify('warning', config.skuEmptyTitle, config.skuEmptyMessage);
            return;
        }

        if (uploadSkus.indexOf(value) === -1) {
            uploadSkus.push(value);
            renderSkus();
        }

        skuInput.value = '';
        skuInput.focus();
    }

    function defaultCategories() {
        return normalizeCategories(config.initialCategories || []);
    }

    function normalizeCategories(categories) {
        var knownValues = {};

        return (Array.isArray(categories) ? categories : []).reduce(function (normalized, category) {
            var value = String(category && category.value ? category.value : '').trim();
            var label = String(category && category.label ? category.label : '').trim();

            if (!value || !label || knownValues[value]) {
                return normalized;
            }

            knownValues[value] = true;
            normalized.push({
                value: value,
                label: label
            });

            return normalized;
        }, []);
    }

    function loadCategories() {
        return defaultCategories();
    }

    function selectedCategories() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-aikaipin-category-choice]')).filter(function (input) {
            return input.checked;
        }).map(function (input) {
            return input.value;
        });
    }

    function syncCategoryChoices() {
        Array.prototype.slice.call(document.querySelectorAll('[data-aikaipin-category-choice]')).forEach(function (input) {
            input.closest('label').classList.toggle('active', input.checked);
        });
    }

    function renderCategories(selected) {
        var selectedValues = selected || selectedCategories();

        if (!categoryChoices) {
            return;
        }

        categoryChoices.innerHTML = '';
        uploadCategories.forEach(function (category) {
            var tag = document.createElement('span');
            var choice = document.createElement('label');
            var input = document.createElement('input');
            var removeButton = document.createElement('button');
            var isSelected = selectedValues.indexOf(category.value) !== -1;

            tag.className = 'aikaipin-category-tag';
            choice.className = 'btn btn-default btn-sm' + (isSelected ? ' active' : '');
            input.className = 'aikaipin-category-choice-input';
            input.type = 'checkbox';
            input.name = 'categories[]';
            input.value = category.value;
            input.checked = isSelected;
            input.setAttribute('data-aikaipin-category-choice', '');
            choice.appendChild(input);
            choice.appendChild(document.createTextNode(category.label));

            removeButton.type = 'button';
            removeButton.className = 'aikaipin-category-remove';
            removeButton.setAttribute('data-aikaipin-remove-category', category.value);
            removeButton.setAttribute('aria-label', replaceTokens(config.removeCategoryAria, {name: category.label}));
            removeButton.innerHTML = '<i class="fa fa-times" aria-hidden="true"></i>';

            tag.appendChild(choice);
            tag.appendChild(removeButton);
            categoryChoices.appendChild(tag);
        });
    }

    function categoryRequest(url, method, body) {
        return window.fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken || ''
            },
            body: body ? JSON.stringify(body) : null
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (payload) {
                if (!response.ok) {
                    throw new Error(payload.message || config.categoryDuplicateMessage);
                }

                return payload;
            });
        });
    }

    function addCategory() {
        var label = categoryInput ? String(categoryInput.value || '').trim() : '';

        if (!label) {
            notify('warning', config.categoryEmptyTitle, config.categoryEmptyMessage);
            return;
        }

        var selectedValues = selectedCategories();

        categoryInput.disabled = true;
        categoryRequest(config.categoryStoreUrl, 'POST', {name: label}).then(function (payload) {
            var newCategory = payload.category;

            if (!newCategory || !newCategory.value || !newCategory.label) {
                throw new Error(config.categoryDuplicateMessage);
            }

            selectedValues.push(String(newCategory.value));
            uploadCategories.push({
                value: String(newCategory.value),
                label: String(newCategory.label)
            });
            renderCategories(selectedValues);
            categoryInput.value = '';
            notify('success', config.categoryCreatedTitle, payload.message || config.categoryCreatedMessage);
        }).catch(function (error) {
            notify('warning', config.categoryDuplicateTitle, error.message || config.categoryDuplicateMessage);
        }).then(function () {
            categoryInput.disabled = false;
            categoryInput.focus();
        });
    }

    function removeCategory(value) {
        var selectedValues = selectedCategories().filter(function (selectedValue) {
            return selectedValue !== value;
        });
        var deleteUrl = String(config.categoryDeleteUrlTemplate || '').replace('__category__', encodeURIComponent(value));

        categoryRequest(deleteUrl, 'DELETE').then(function (payload) {
            uploadCategories = uploadCategories.filter(function (category) {
                return category.value !== value;
            });
            renderCategories(selectedValues);
            notify('success', config.categoryDeletedTitle, payload.message || config.categoryDeletedMessage);
        }).catch(function (error) {
            notify('warning', config.categoryDuplicateTitle, error.message || config.categoryDuplicateMessage);
        });
    }

    function releaseImageUrls() {
        uploadImageFiles.forEach(function (item) {
            if (item.url) {
                window.URL.revokeObjectURL(item.url);
            }
        });
    }

    function renderImagePreviews() {
        if (!imagePreviews) {
            return;
        }

        imagePreviews.innerHTML = '';
        uploadImageFiles.forEach(function (item, index) {
            var preview = document.createElement('div');
            var image = document.createElement('img');
            var removeButton = document.createElement('button');

            preview.className = 'aikaipin-image-preview';
            image.src = item.url;
            image.alt = item.file.name;
            removeButton.type = 'button';
            removeButton.className = 'aikaipin-image-preview-remove';
            removeButton.setAttribute('aria-label', item.file.name);
            removeButton.setAttribute('data-aikaipin-remove-image', String(index));
            removeButton.innerHTML = '&times;';
            preview.appendChild(image);
            preview.appendChild(removeButton);
            imagePreviews.appendChild(preview);
        });
    }

    function addImages(files) {
        var available = Math.max(0, 3 - uploadImageFiles.length);
        var accepted = 0;
        var reachedLimit = files.length > available;

        files.forEach(function (file) {
            if (file.size > 20 * 1024 * 1024) {
                notify('warning', config.imageTooLargeTitle, replaceTokens(config.imageTooLargeMessage, {name: file.name}));
                return;
            }

            if (accepted >= available) {
                return;
            }

            uploadImageFiles.push({
                file: file,
                url: window.URL.createObjectURL(file)
            });
            accepted += 1;
        });

        if (reachedLimit) {
            notify('warning', config.imageLimitTitle, config.imageLimitMessage);
        }

        renderImagePreviews();
    }

    function packageExtension(file) {
        var name = String(file.name || '').toLowerCase();
        var dot = name.lastIndexOf('.');

        return dot === -1 ? '' : name.slice(dot + 1);
    }

    function updateFileName(element, file) {
        if (!element) {
            return;
        }

        element.textContent = file
            ? replaceTokens(config.fileSelected, {name: file.name, size: fileSize(file.size)})
            : config.fileUnselected;
    }

    function validatePackage(file) {
        var extensions = ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz'];

        if (extensions.indexOf(packageExtension(file)) === -1) {
            notify('warning', config.archiveInvalidTitle, config.archiveInvalidMessage);
            return false;
        }

        if (file.size > 150 * 1024 * 1024) {
            notify('warning', config.archiveTooLargeTitle, config.archiveTooLargeMessage);
            return false;
        }

        return true;
    }

    function syncChoiceButtons() {
        if (!uploadForm) {
            return;
        }

        Array.prototype.slice.call(uploadForm.querySelectorAll('[data-toggle="buttons"] input')).forEach(function (input) {
            input.closest('label').classList.toggle('active', input.checked);
        });
    }

    function resetUploadModal() {
        if (!uploadForm) {
            return;
        }

        if (uploadTimer) {
            window.clearInterval(uploadTimer);
            uploadTimer = null;
        }

        uploadForm.reset();
        releaseImageUrls();
        uploadSkus = [];
        uploadImageFiles = [];
        renderSkus();
        renderCategories([]);
        renderImagePreviews();
        updateFileName(referenceFileName, null);
        updateFileName(packageFileName, null);

        if (uploadProgress) {
            uploadProgress.hidden = true;
        }
        if (uploadProgressBar) {
            uploadProgressBar.style.width = '0%';
            uploadProgressBar.setAttribute('aria-valuenow', '0');
        }
        if (uploadSubmit) {
            uploadSubmit.disabled = false;
        }

        syncChoiceButtons();
    }

    function simulateUpload() {
        var progress = 0;

        if (uploadProgress) {
            uploadProgress.hidden = false;
        }
        if (uploadSubmit) {
            uploadSubmit.disabled = true;
        }

        uploadTimer = window.setInterval(function () {
            progress = Math.min(100, progress + 20);
            if (uploadProgressBar) {
                uploadProgressBar.style.width = progress + '%';
                uploadProgressBar.setAttribute('aria-valuenow', String(progress));
            }

            if (progress === 100) {
                window.clearInterval(uploadTimer);
                uploadTimer = null;
                if (uploadSubmit) {
                    uploadSubmit.disabled = false;
                }
                notify('success', config.uploadDemoTitle, config.uploadDemoMessage);
            }
        }, 180);
    }

    function openUploadModal() {
        if (uploadModal && window.jQuery) {
            window.jQuery(uploadModal).modal('show');
        }
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            if (!config.serverFilters) {
                event.preventDefault();
                applyFilters();
            }
        });
    }

    if (!config.serverFilters) {
        Object.keys(filters).forEach(function (name) {
            if (filters[name]) {
                filters[name].addEventListener('change', applyFilters);
                filters[name].addEventListener('input', applyFilters);
            }
        });
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            visibleSelectionBoxes().forEach(function (box) {
                box.checked = selectAll.checked;
            });
            refreshSelection();
        });
    }

    if (skuInput) {
        skuInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                addSku();
            }
        });
    }

    if (categoryInput) {
        categoryInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                addCategory();
            }
        });
    }

    if (uploadImages) {
        uploadImages.addEventListener('change', function () {
            addImages(Array.prototype.slice.call(uploadImages.files || []));
            uploadImages.value = '';
        });
    }

    if (referenceFile) {
        referenceFile.addEventListener('change', function () {
            updateFileName(referenceFileName, referenceFile.files && referenceFile.files[0]);
        });
    }

    if (packageFile) {
        packageFile.addEventListener('change', function () {
            var file = packageFile.files && packageFile.files[0];
            if (file && !validatePackage(file)) {
                packageFile.value = '';
                file = null;
            }
            updateFileName(packageFileName, file);
        });
    }

    if (uploadForm) {
        uploadForm.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!uploadName || String(uploadName.value || '').trim() === '') {
                notify('warning', config.productNameRequiredTitle, config.productNameRequiredMessage);
                if (uploadName) {
                    uploadName.focus();
                }
                return;
            }

            if (!packageFile || !packageFile.files || !packageFile.files[0]) {
                notify('warning', config.archiveRequiredTitle, config.archiveRequiredMessage);
                if (packageFile) {
                    packageFile.focus();
                }
                return;
            }

            simulateUpload();
        });
    }

    if (uploadModal && window.jQuery) {
        window.jQuery(uploadModal).on('hidden.bs.modal', resetUploadModal);
    }

    if (imagePreviewModal && window.jQuery) {
        window.jQuery(imagePreviewModal).on('hidden.bs.modal', function () {
            previewImages = [];
            previewImageIndex = 0;
            previewProductTitle = '';
        });
    }

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-aikaipin-product-select]')) {
            refreshSelection();
        }

        if (event.target.matches('[data-aikaipin-category-choice]')) {
            syncCategoryChoices();
        }
    });

    document.addEventListener('click', function (event) {
        var imagePreviewButton = event.target.closest('[data-aikaipin-preview-image]');
        if (imagePreviewButton) {
            openImagePreview(imagePreviewButton);
            return;
        }

        var previousImageButton = event.target.closest('[data-aikaipin-image-preview-prev]');
        if (previousImageButton) {
            setPreviewImage(previewImageIndex - 1);
            return;
        }

        var nextImageButton = event.target.closest('[data-aikaipin-image-preview-next]');
        if (nextImageButton) {
            setPreviewImage(previewImageIndex + 1);
            return;
        }

        var imageThumbnail = event.target.closest('[data-aikaipin-image-preview-index]');
        if (imageThumbnail) {
            setPreviewImage(Number(imageThumbnail.getAttribute('data-aikaipin-image-preview-index')));
            return;
        }

        var addCategoryButton = event.target.closest('[data-aikaipin-add-category]');
        if (addCategoryButton) {
            addCategory();
            return;
        }

        var removeCategoryButton = event.target.closest('[data-aikaipin-remove-category]');
        if (removeCategoryButton) {
            removeCategory(removeCategoryButton.getAttribute('data-aikaipin-remove-category'));
            return;
        }

        var addSkuButton = event.target.closest('[data-aikaipin-add-sku]');
        if (addSkuButton) {
            addSku();
            return;
        }

        var removeSkuButton = event.target.closest('[data-aikaipin-remove-sku]');
        if (removeSkuButton) {
            uploadSkus = uploadSkus.filter(function (sku) {
                return sku !== removeSkuButton.getAttribute('data-aikaipin-remove-sku');
            });
            renderSkus();
            return;
        }

        var removeImageButton = event.target.closest('[data-aikaipin-remove-image]');
        if (removeImageButton) {
            var imageIndex = Number(removeImageButton.getAttribute('data-aikaipin-remove-image'));
            var removedImage = uploadImageFiles.splice(imageIndex, 1)[0];
            if (removedImage && removedImage.url) {
                window.URL.revokeObjectURL(removedImage.url);
            }
            renderImagePreviews();
            return;
        }

        var clearButton = event.target.closest('[data-aikaipin-clear-filters]');
        if (clearButton) {
            clearFilters();
            return;
        }

        var batchButton = event.target.closest('[data-aikaipin-batch-action]');
        if (batchButton) {
            openVisibilityModal(batchButton.getAttribute('data-aikaipin-batch-action'));
            return;
        }

        var confirmButton = event.target.closest('[data-aikaipin-visibility-confirm]');
        if (confirmButton) {
            if (visibilityModal && window.jQuery) {
                window.jQuery(visibilityModal).modal('hide');
            }
            notify('info', config.demoTitle, replaceCount(config.demoMessage, selectedSelectionBoxes().length));
            return;
        }

        var actionButton = event.target.closest('[data-aikaipin-action]');
        if (actionButton) {
            if (actionButton.getAttribute('data-aikaipin-action') === 'upload') {
                openUploadModal();
                return;
            }
            notify('info', config.comingSoonTitle, config.comingSoonMessage);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (!previewModalIsOpen()) {
            return;
        }

        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            setPreviewImage(previewImageIndex - 1);
        }

        if (event.key === 'ArrowRight') {
            event.preventDefault();
            setPreviewImage(previewImageIndex + 1);
        }
    });

    renderCategories([]);
    refreshSelection();
}(window, document));
