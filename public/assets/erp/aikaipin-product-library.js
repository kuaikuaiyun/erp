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
    var uploadLandingUrl = document.querySelector('[data-aikaipin-upload-landing-url]');
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
    var uploadTitle = document.querySelector('[data-aikaipin-upload-title]');
    var uploadSkus = [];
    var uploadImageFiles = [];
    var existingImageFiles = [];
    var removedImageIds = [];
    var existingReferenceFile = null;
    var existingPackageFile = null;
    var editingProductId = null;
    var isProductSubmitting = false;
    var uploadTimer = null;
    var packageUploadToken = '';
    var uploadSubmitHtml = uploadSubmit ? uploadSubmit.innerHTML : '';
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
            visibilityConfirm.setAttribute('data-aikaipin-visibility-action', mode);
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
            input.name = 'category_ids[]';
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

    function responsePayload(response) {
        return response.json().catch(function () {
            return {};
        }).then(function (payload) {
            if (!response.ok) {
                var fieldErrors = payload.errors || {};
                var firstField = Object.keys(fieldErrors)[0];
                var firstMessage = firstField && fieldErrors[firstField] && fieldErrors[firstField][0];
                throw new Error(firstMessage || payload.message || config.productSaveFailedMessage);
            }

            return payload;
        });
    }

    function jsonRequest(url, method, body) {
        return window.fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken || ''
            },
            body: body ? JSON.stringify(body) : null
        }).then(responsePayload);
    }

    function formDataRequest(url, formData) {
        return window.fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken || ''
            },
            body: formData
        }).then(responsePayload);
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

    function addImagePreview(item, removeAttribute, removeValue) {
        var preview = document.createElement('div');
        var image = document.createElement('img');
        var removeButton = document.createElement('button');

        preview.className = 'aikaipin-image-preview';
        image.src = item.url;
        image.alt = item.name || (item.file && item.file.name) || '';
        removeButton.type = 'button';
        removeButton.className = 'aikaipin-image-preview-remove';
        removeButton.setAttribute('aria-label', image.alt);
        removeButton.setAttribute(removeAttribute, String(removeValue));
        removeButton.innerHTML = '&times;';
        preview.appendChild(image);
        preview.appendChild(removeButton);
        imagePreviews.appendChild(preview);
    }

    function renderImagePreviews() {
        if (!imagePreviews) {
            return;
        }

        imagePreviews.innerHTML = '';
        existingImageFiles.forEach(function (item) {
            addImagePreview(item, 'data-aikaipin-remove-existing-image', item.id);
        });
        uploadImageFiles.forEach(function (item, index) {
            addImagePreview(item, 'data-aikaipin-remove-image', index);
        });
    }

    function addImages(files) {
        var available = Math.max(0, 3 - existingImageFiles.length - uploadImageFiles.length);
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
                url: window.URL.createObjectURL(file),
                name: file.name
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

    function updateExistingFileName(element, file) {
        if (!element) {
            return;
        }

        element.textContent = file
            ? replaceTokens(config.fileExisting, {name: file.name})
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

    function setUploadMode(productId) {
        editingProductId = productId || null;
        uploadSubmitHtml = '<i class="fa fa-' + (editingProductId ? 'save' : 'upload') + '" aria-hidden="true"></i> '
            + String(editingProductId ? config.saveChangesLabel : config.saveProductLabel);

        if (uploadTitle) {
            uploadTitle.textContent = editingProductId ? config.editTitle : config.uploadTitle;
        }
        setUploadSubmitState(false);
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
        existingImageFiles = [];
        removedImageIds = [];
        existingReferenceFile = null;
        existingPackageFile = null;
        packageUploadToken = '';
        isProductSubmitting = false;
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
        syncChoiceButtons();
        setUploadMode(null);
    }

    function setUploadSubmitState(isBusy, label) {
        if (!uploadSubmit) {
            return;
        }

        uploadSubmit.disabled = isBusy;
        uploadSubmit.innerHTML = isBusy
            ? '<i class="fa fa-spinner fa-spin" aria-hidden="true"></i> ' + String(label || config.savingProduct)
            : uploadSubmitHtml;
    }

    function updateUploadProgress(received, total) {
        var percentage = total > 0 ? Math.min(100, Math.floor((received / total) * 100)) : 0;

        if (uploadProgress) {
            uploadProgress.hidden = false;
        }
        if (uploadProgressBar) {
            uploadProgressBar.style.width = percentage + '%';
            uploadProgressBar.setAttribute('aria-valuenow', String(percentage));
        }
    }

    function packageFingerprint(file) {
        var value = [file.name, file.size, file.lastModified, 5 * 1024 * 1024].join('|');
        var hash = 2166136261;
        var index;

        for (index = 0; index < value.length; index += 1) {
            hash ^= value.charCodeAt(index);
            hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24);
        }

        return ('00000000' + (hash >>> 0).toString(16)).slice(-8).repeat(8);
    }

    function packagePartUrl(token, chunkIndex) {
        return String(config.packageUploadPartUrlTemplate || '')
            .replace('__token__', encodeURIComponent(token))
            .replace('__chunk__', String(chunkIndex));
    }

    function packageCompleteUrl(token) {
        return String(config.packageUploadCompleteUrlTemplate || '').replace('__token__', encodeURIComponent(token));
    }

    function uploadPackage(file) {
        var totalChunks = Math.ceil(file.size / (5 * 1024 * 1024));

        return jsonRequest(config.packageUploadInitializeUrl, 'POST', {
            client_fingerprint: packageFingerprint(file),
            original_name: file.name,
            mime_type: file.type || null,
            total_size: file.size,
            total_chunks: totalChunks
        }).then(function (session) {
            var uploaded = {};
            var chunkIndex = 0;

            packageUploadToken = session.upload_token;
            (session.uploaded_chunks || []).forEach(function (index) {
                uploaded[Number(index)] = true;
            });
            updateUploadProgress(Number(session.received_size || 0), file.size);

            function uploadNextPart() {
                while (chunkIndex < totalChunks && uploaded[chunkIndex]) {
                    chunkIndex += 1;
                }

                if (chunkIndex >= totalChunks) {
                    return jsonRequest(packageCompleteUrl(packageUploadToken), 'POST').then(function (completed) {
                        packageUploadToken = completed.upload_token;
                        updateUploadProgress(file.size, file.size);
                        return completed;
                    });
                }

                var start = chunkIndex * 5 * 1024 * 1024;
                var end = Math.min(file.size, start + 5 * 1024 * 1024);
                var partData = new FormData();
                var currentIndex = chunkIndex;

                partData.append('chunk', file.slice(start, end), file.name + '.part');
                return formDataRequest(packagePartUrl(packageUploadToken, currentIndex), partData).then(function (updatedSession) {
                    uploaded[currentIndex] = true;
                    chunkIndex = currentIndex + 1;
                    updateUploadProgress(Number(updatedSession.received_size || 0), file.size);
                    return uploadNextPart();
                });
            }

            if (session.status === 'completed') {
                updateUploadProgress(file.size, file.size);
                return session;
            }

            return uploadNextPart();
        });
    }

    function submitProduct() {
        var packageFileToUpload = packageFile && packageFile.files && packageFile.files[0];
        var isEditing = editingProductId !== null;
        var packageRequest;

        if (isProductSubmitting) {
            return;
        }

        if (!uploadName || String(uploadName.value || '').trim() === '') {
            notify('warning', config.productNameRequiredTitle, config.productNameRequiredMessage);
            uploadName.focus();
            setUploadSubmitState(false);
            return;
        }

        if (!packageFileToUpload && !existingPackageFile) {
            notify('warning', config.archiveRequiredTitle, config.archiveRequiredMessage);
            packageFile.focus();
            setUploadSubmitState(false);
            return;
        }

        isProductSubmitting = true;
        if (packageFileToUpload) {
            setUploadSubmitState(true, config.uploadingProductPackage);
            packageRequest = uploadPackage(packageFileToUpload);
        } else {
            setUploadSubmitState(true, config.savingProduct);
            packageRequest = Promise.resolve(null);
        }

        packageRequest.then(function (session) {
            var formData = new FormData(uploadForm);
            var requestUrl = isEditing
                ? String(config.productUpdateUrlTemplate || '').replace('__product__', encodeURIComponent(editingProductId))
                : config.productStoreUrl;

            uploadSkus.forEach(function (sku) {
                formData.append('skus[]', sku);
            });
            uploadImageFiles.forEach(function (item) {
                formData.append('images[]', item.file, item.file.name);
            });
            if (referenceFile && referenceFile.files && referenceFile.files[0]) {
                formData.append('reference_file', referenceFile.files[0], referenceFile.files[0].name);
            }
            removedImageIds.forEach(function (id) {
                formData.append('remove_image_ids[]', String(id));
            });
            if (session && session.upload_token) {
                formData.append('package_upload_token', session.upload_token);
            }
            if (isEditing) {
                formData.append('_method', 'PUT');
            }
            setUploadSubmitState(true, config.savingProduct);

            return formDataRequest(requestUrl, formData);
        }).then(function (payload) {
            notify(
                'success',
                isEditing ? config.productUpdatedTitle : config.productCreatedTitle,
                payload.message || (isEditing ? config.productUpdatedMessage : config.productCreatedMessage)
            );
            isProductSubmitting = false;
            if (uploadModal && window.jQuery) {
                window.jQuery(uploadModal).modal('hide');
            }
            window.setTimeout(function () {
                window.location.assign(payload.redirect_url || window.location.href);
            }, 400);
        }).catch(function (error) {
            notify('error', config.productSaveFailedTitle, error.message || config.productSaveFailedMessage);
            isProductSubmitting = false;
            setUploadSubmitState(false);
        });
    }

    function openUploadModal() {
        resetUploadModal();
        if (uploadModal && window.jQuery) {
            window.jQuery(uploadModal).modal('show');
        }
    }

    function productEditUrl(productId) {
        return String(config.productEditUrlTemplate || '').replace('__product__', encodeURIComponent(productId));
    }

    function setRadioValue(name, value) {
        if (!uploadForm) {
            return;
        }

        Array.prototype.slice.call(uploadForm.querySelectorAll('input[name="' + name + '"]')).forEach(function (input) {
            input.checked = input.value === String(value || '');
        });
    }

    function setAuthorizedDepartments(values) {
        var selected = (values || []).map(String);

        if (!uploadForm) {
            return;
        }

        Array.prototype.slice.call(uploadForm.querySelectorAll('input[name="authorized_department_ids[]"]')).forEach(function (input) {
            input.checked = selected.indexOf(input.value) !== -1;
        });
    }

    function openEditModal(productId) {
        if (!productId) {
            return;
        }

        jsonRequest(productEditUrl(productId), 'GET').then(function (product) {
            resetUploadModal();
            setUploadMode(Number(product.id));
            uploadName.value = product.name || '';
            if (uploadLandingUrl) {
                uploadLandingUrl.value = product.landing_page_url || '';
            }
            setRadioValue('source_platform', product.source_platform);
            setRadioValue('product_type', product.product_type);
            uploadSkus = Array.isArray(product.skus) ? product.skus.slice() : [];
            existingImageFiles = Array.isArray(product.images) ? product.images.slice() : [];
            existingReferenceFile = product.reference_file || null;
            existingPackageFile = product.package_file || null;
            renderSkus();
            renderCategories(Array.isArray(product.category_ids) ? product.category_ids.map(String) : []);
            renderImagePreviews();
            updateExistingFileName(referenceFileName, existingReferenceFile);
            updateExistingFileName(packageFileName, existingPackageFile);
            setAuthorizedDepartments(product.authorized_department_ids);
            syncChoiceButtons();

            if (uploadModal && window.jQuery) {
                window.jQuery(uploadModal).modal('show');
            }
        }).catch(function (error) {
            notify('error', config.productSaveFailedTitle, error.message || config.productSaveFailedMessage);
        });
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
            var file = referenceFile.files && referenceFile.files[0];
            if (file) {
                updateFileName(referenceFileName, file);
                return;
            }
            updateExistingFileName(referenceFileName, existingReferenceFile);
        });
    }

    if (packageFile) {
        packageFile.addEventListener('change', function () {
            var file = packageFile.files && packageFile.files[0];
            if (file && !validatePackage(file)) {
                packageFile.value = '';
                file = null;
            }
            if (file) {
                updateFileName(packageFileName, file);
                return;
            }
            updateExistingFileName(packageFileName, existingPackageFile);
        });
    }

    if (uploadForm) {
        uploadForm.addEventListener('submit', function (event) {
            event.preventDefault();
            event.stopImmediatePropagation();
            event.stopPropagation();
            submitProduct();
        }, true);
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

        var removeExistingImageButton = event.target.closest('[data-aikaipin-remove-existing-image]');
        if (removeExistingImageButton) {
            var existingImageId = Number(removeExistingImageButton.getAttribute('data-aikaipin-remove-existing-image'));
            existingImageFiles = existingImageFiles.filter(function (image) {
                return Number(image.id) !== existingImageId;
            });
            if (removedImageIds.indexOf(existingImageId) === -1) {
                removedImageIds.push(existingImageId);
            }
            renderImagePreviews();
            return;
        }

        var uploadSubmitButton = event.target.closest('[data-aikaipin-upload-submit]');
        if (uploadSubmitButton) {
            event.preventDefault();
            submitProduct();
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
            var selectedProducts = selectedSelectionBoxes().map(function (box) {
                return Number(box.value);
            });
            var action = confirmButton.getAttribute('data-aikaipin-visibility-action');
            var originalLabel = confirmButton.innerHTML;

            if (selectedProducts.length === 0) {
                notify('warning', config.selectionRequiredTitle, config.selectionRequiredMessage);
                return;
            }

            confirmButton.disabled = true;
            jsonRequest(config.visibilityUpdateUrl, 'POST', {
                action: action,
                product_ids: selectedProducts
            }).then(function (payload) {
                if (visibilityModal && window.jQuery) {
                    window.jQuery(visibilityModal).modal('hide');
                }
                notify('success', config.visibilityUpdatedTitle, payload.message || '');
            }).catch(function (error) {
                notify('error', config.productSaveFailedTitle, error.message || config.productSaveFailedMessage);
            }).then(function () {
                confirmButton.disabled = false;
                confirmButton.innerHTML = originalLabel;
            });
            return;
        }

        var actionButton = event.target.closest('[data-aikaipin-action]');
        if (actionButton) {
            if (actionButton.getAttribute('data-aikaipin-action') === 'upload') {
                openUploadModal();
                return;
            }
            if (actionButton.getAttribute('data-aikaipin-action') === 'edit') {
                openEditModal(Number(actionButton.getAttribute('data-aikaipin-product-id')));
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
