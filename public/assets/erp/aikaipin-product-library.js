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
    var productDeleteModal = document.getElementById('aikaipin-product-delete-modal');
    var productDeleteTitle = document.querySelector('[data-aikaipin-product-delete-title]');
    var productDeleteDescription = document.querySelector('[data-aikaipin-product-delete-description]');
    var productDeleteLinkedEvaluations = document.querySelector('[data-aikaipin-product-delete-linked-evaluations]');
    var productDeleteLinkedLabel = document.querySelector('[data-aikaipin-product-delete-linked-label]');
    var productDeleteLinkedList = document.querySelector('[data-aikaipin-product-delete-linked-list]');
    var productDeleteConfirm = document.querySelector('[data-aikaipin-product-delete-confirm]');
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
    var mainLandingField = document.querySelector('[data-aikaipin-main-landing-field]');
    var skuInput = document.querySelector('[data-aikaipin-sku-input]');
    var skuTags = document.querySelector('[data-aikaipin-sku-tags]');
    var categoryInput = document.querySelector('[data-aikaipin-category-input]');
    var categoryChoices = document.querySelector('[data-aikaipin-category-choices]');
    var uploadImages = document.querySelector('[data-aikaipin-upload-images]');
    var imagePreviews = document.querySelector('[data-aikaipin-image-previews]');
    var referenceFile = document.querySelector('[data-aikaipin-reference-file]');
    var referenceFileName = document.querySelector('[data-aikaipin-reference-file-name]');
    var materialVideoInputs = Array.prototype.slice.call(document.querySelectorAll('[data-aikaipin-material-video]'));
    var materialVideoList = document.querySelector('[data-aikaipin-material-video-list]');
    var productViewModal = document.getElementById('aikaipin-product-view-modal');
    var productViewTitle = document.querySelector('[data-aikaipin-product-view-title]');
    var productViewVideos = document.querySelector('[data-aikaipin-product-view-videos]');
    var productViewReference = document.querySelector('[data-aikaipin-product-view-reference]');
    var materialVideoPreviewModal = document.getElementById('aikaipin-material-video-preview-modal');
    var materialVideoPreviewTitle = document.querySelector('[data-aikaipin-material-video-preview-title]');
    var materialVideoPreviewPlayer = document.querySelector('[data-aikaipin-material-video-preview-player]');
    var materialVideoPreviewFrame = document.querySelector('[data-aikaipin-material-video-preview-frame]');
    var materialVideoPreviewOpen = document.querySelector('[data-aikaipin-material-video-preview-open]');
    var materialVideoPreviewError = document.querySelector('[data-aikaipin-material-video-preview-error]');
    var packageFile = document.querySelector('[data-aikaipin-package-file]');
    var packageFileName = document.querySelector('[data-aikaipin-package-file-name]');
    var uploadShopifyCsvList = document.querySelector('[data-aikaipin-upload-shopify-csv-list]');
    var addShopifyCsvPair = document.querySelector('[data-aikaipin-add-csv-pair]');
    var createShopifyCsvSection = document.querySelector('[data-aikaipin-create-shopify-csv]');
    var uploadProgress = document.querySelector('[data-aikaipin-upload-progress]');
    var uploadProgressBar = document.querySelector('[data-aikaipin-upload-progress-bar]');
    var productSaveProgress = document.querySelector('[data-aikaipin-product-save-progress]');
    var productSaveProgressBar = document.querySelector('[data-aikaipin-product-save-progress-bar]');
    var productSaveProgressPercent = document.querySelector('[data-aikaipin-product-save-progress-percent]');
    var productSaveProgressLabel = document.querySelector('[data-aikaipin-product-save-progress-label]');
    var productSaveProgressDetail = document.querySelector('[data-aikaipin-product-save-progress-detail]');
    var uploadSubmit = document.querySelector('[data-aikaipin-upload-submit]');
    var uploadTitle = document.querySelector('[data-aikaipin-upload-title]');
    var shopifyEditSection = document.querySelector('[data-aikaipin-shopify-edit-section]');
    var shopifyEditContent = document.querySelector('[data-aikaipin-shopify-edit-content]');
    var shopifyImportModal = document.getElementById('aikaipin-shopify-import-modal');
    var shopifyImportProduct = document.querySelector('[data-aikaipin-shopify-import-product]');
    var shopifyImportFile = document.querySelector('[data-aikaipin-shopify-import-file]');
    var shopifyImportPageName = document.querySelector('[data-aikaipin-shopify-page-name]');
    var shopifyImportLandingPageUrl = document.querySelector('[data-aikaipin-shopify-landing-page-url]');
    var shopifyImportFileName = document.querySelector('[data-aikaipin-shopify-import-file-name]');
    var shopifyImportPreview = document.querySelector('[data-aikaipin-shopify-import-preview]');
    var shopifyImportPreviewButton = document.querySelector('[data-aikaipin-shopify-import-preview-button]');
    var shopifyImportConfirm = document.querySelector('[data-aikaipin-shopify-import-confirm]');
    var uploadSkus = [];
    var uploadImageFiles = [];
    var existingImageFiles = [];
    var removedImageIds = [];
    var existingReferenceFile = null;
    var existingPackageFile = null;
    var existingMaterialVideos = [];
    var existingMaterialReferenceUrl = '';
    var removedMaterialVideoIds = [];
    var removedHistoryVideoIndexes = [];
    var editingProductId = null;
    var isProductSubmitting = false;
    var uploadTimer = null;
    var packageUploadToken = '';
    var referenceUploadToken = '';
    var activeUploadFileName = '';
    var productSaveStartedAt = 0;
    var uploadSubmitHtml = uploadSubmit ? uploadSubmit.innerHTML : '';
    var uploadCategories = loadCategories();
    var previewImages = [];
    var previewImageIndex = 0;
    var previewProductTitle = '';
    var pendingProductDeletion = null;
    var importingProductId = null;
    var shopifyImportToken = '';
    var editingProductPayload = null;
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

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[character];
        });
    }

    function templateUrl(template, productId) {
        return String(template || '').replace('__product__', encodeURIComponent(productId));
    }

    function productPageUrl(template, productId, pageId) {
        return templateUrl(template, productId).replace('__page__', encodeURIComponent(pageId));
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

    function productDeleteUrl(productId) {
        return String(config.productDeleteUrlTemplate || '').replace('__product__', encodeURIComponent(productId));
    }

    function renderLinkedEvaluations(evaluations) {
        if (!productDeleteLinkedList) {
            return;
        }

        productDeleteLinkedList.innerHTML = '';
        (Array.isArray(evaluations) ? evaluations : []).forEach(function (evaluation) {
            var item = document.createElement('li');
            item.textContent = String(evaluation && evaluation.code ? evaluation.code : '-');
            productDeleteLinkedList.appendChild(item);
        });
    }

    function setProductDeleteModalState(linkedEvaluations) {
        var hasLinkedEvaluations = Array.isArray(linkedEvaluations) && linkedEvaluations.length > 0;

        if (!pendingProductDeletion) {
            return;
        }

        if (productDeleteTitle) {
            productDeleteTitle.textContent = hasLinkedEvaluations
                ? config.productDeleteLinkedTitle
                : config.productDeleteTitle;
        }
        if (productDeleteDescription) {
            productDeleteDescription.textContent = hasLinkedEvaluations
                ? String(pendingProductDeletion.linkedMessage || '')
                : replaceTokens(config.productDeleteMessage, {
                    code: pendingProductDeletion.code,
                    name: pendingProductDeletion.name
                });
        }
        if (productDeleteLinkedEvaluations) {
            productDeleteLinkedEvaluations.hidden = !hasLinkedEvaluations;
        }
        if (productDeleteLinkedLabel) {
            productDeleteLinkedLabel.textContent = config.productDeleteLinkedListLabel || '';
        }
        if (hasLinkedEvaluations) {
            renderLinkedEvaluations(linkedEvaluations);
        }
        if (productDeleteConfirm) {
            productDeleteConfirm.textContent = hasLinkedEvaluations
                ? config.productDeleteContinue
                : config.productDeleteConfirm;
        }
    }

    function openProductDeleteModal(button) {
        pendingProductDeletion = {
            id: Number(button.getAttribute('data-aikaipin-product-id')),
            code: button.getAttribute('data-aikaipin-product-code') || '',
            name: button.getAttribute('data-aikaipin-product-name') || '',
            linkedEvaluations: [],
            linkedMessage: '',
            confirmedLinkedEvaluations: false
        };
        setProductDeleteModalState([]);

        if (productDeleteModal && window.jQuery) {
            window.jQuery(productDeleteModal).modal('show');
        }
    }

    function deleteProduct() {
        var originalLabel;
        var shouldRestoreOriginalLabel = true;

        if (!pendingProductDeletion || !pendingProductDeletion.id || !productDeleteConfirm) {
            return;
        }

        originalLabel = productDeleteConfirm.innerHTML;
        productDeleteConfirm.disabled = true;
        jsonRequest(productDeleteUrl(pendingProductDeletion.id), 'DELETE', {
            confirm_linked_evaluations: pendingProductDeletion.confirmedLinkedEvaluations
        }).then(function (payload) {
            if (productDeleteModal && window.jQuery) {
                window.jQuery(productDeleteModal).modal('hide');
            }
            notify('success', config.productDeletedTitle, payload.message || config.productDeletedMessage);
            window.setTimeout(function () {
                window.location.assign(payload.redirect_url || window.location.href);
            }, 350);
        }).catch(function (error) {
            var linkedEvaluations = error && error.payload && error.payload.linked_evaluations;

            if (error && error.code === 'product_has_linked_placement_evaluations' && !pendingProductDeletion.confirmedLinkedEvaluations) {
                pendingProductDeletion.linkedEvaluations = Array.isArray(linkedEvaluations) ? linkedEvaluations : [];
                pendingProductDeletion.linkedMessage = error.message || '';
                pendingProductDeletion.confirmedLinkedEvaluations = true;
                setProductDeleteModalState(pendingProductDeletion.linkedEvaluations);
                shouldRestoreOriginalLabel = false;
                return;
            }

            notify('error', config.productDeleteTitle, error.message || config.productDeleteFailedMessage);
        }).then(function () {
            productDeleteConfirm.disabled = false;
            if (shouldRestoreOriginalLabel) {
                productDeleteConfirm.innerHTML = originalLabel;
            }
        });
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
                var thumbnailUrl = previewImageUrl(item);
                if (thumbnailUrl) {
                    var thumbnailImage = document.createElement('img');
                    thumbnailImage.src = thumbnailUrl;
                    thumbnailImage.alt = replaceTokens(config.imagePreviewThumbnail, {number: index + 1});
                    thumbnail.appendChild(thumbnailImage);
                } else {
                    thumbnail.innerHTML = '<i class="fa fa-picture-o" aria-hidden="true"></i>';
                }
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

    function responsePayload(response) {
        return response.json().catch(function () {
            return {};
        }).then(function (payload) {
            if (!response.ok) {
                var fieldErrors = payload.errors || {};
                var firstField = Object.keys(fieldErrors)[0];
                var firstMessage = firstField && fieldErrors[firstField] && fieldErrors[firstField][0];
                var error = new Error(firstMessage || payload.message || config.productSaveFailedMessage);
                error.code = payload.code || '';
                error.payload = payload;
                error.status = response.status;
                throw error;
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

    function retryDelay(attempt) {
        return Math.min(5000, 700 * Math.pow(2, Math.max(0, attempt - 1)));
    }

    function isRetryableUploadError(error) {
        var status = Number(error && error.status || 0);

        return !status || [408, 425, 429, 500, 502, 503, 504].indexOf(status) !== -1;
    }

    function waitForUploadRetry(attempt, fileName) {
        var delay = retryDelay(attempt);

        if (productSaveProgressLabel) {
            productSaveProgressLabel.textContent = '上传连接中断，正在自动重试（第 ' + attempt + ' 次）';
        }
        if (productSaveProgressDetail) {
            productSaveProgressDetail.textContent = (fileName ? fileName + ' · ' : '') + Math.ceil(delay / 1000) + ' 秒后继续，已上传进度不会丢失';
        }

        return new Promise(function (resolve) {
            window.setTimeout(resolve, delay);
        });
    }

    function retryUploadRequest(factory, fileName, maxAttempts) {
        var limit = maxAttempts || 5;

        function run(attempt) {
            return factory().catch(function (error) {
                if (attempt >= limit || !isRetryableUploadError(error)) {
                    throw error;
                }

                return waitForUploadRetry(attempt, fileName).then(function () {
                    return run(attempt + 1);
                });
            });
        }

        return run(1);
    }

    function uploadJsonRequest(url, method, body, fileName) {
        return retryUploadRequest(function () {
            return jsonRequest(url, method, body);
        }, fileName, 5);
    }

    function readableUploadError(error, fileName) {
        var status = Number(error && error.status || 0);
        var original = String(error && error.message || '').trim();
        var prefix = fileName ? '文件“' + fileName + '”' : '文件';

        if (!status || /failed to fetch|networkerror|network request failed|上传连接中断/i.test(original)) {
            return prefix + '上传连接中断。已上传的分片会保留，请检查网络后再次点击保存按钮，系统将从断点继续上传。';
        }
        if (status === 413) {
            return prefix + '超过服务器允许的上传大小，请联系管理员调整 Nginx/PHP 上传限制后重试。';
        }
        if ([408, 504].indexOf(status) !== -1) {
            return prefix + '上传超时。已上传的分片会保留，请再次点击保存按钮继续上传。';
        }
        if (status >= 500) {
            return prefix + '上传时服务器暂时异常（HTTP ' + status + '），请稍后再次保存；已上传进度不会丢失。';
        }

        return original || config.productSaveFailedMessage;
    }

    function formDataRequest(url, formData, onUploadProgress) {
        if (typeof onUploadProgress !== 'function') {
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

        return new Promise(function (resolve, reject) {
            var request = new window.XMLHttpRequest();

            request.open('POST', url, true);
            request.timeout = 15 * 60 * 1000;
            request.setRequestHeader('Accept', 'application/json');
            request.setRequestHeader('X-CSRF-TOKEN', config.csrfToken || '');
            request.upload.addEventListener('progress', function (event) {
                if (event.lengthComputable) {
                    onUploadProgress(event.loaded, event.total);
                }
            });
            request.addEventListener('load', function () {
                var payload = {};

                try {
                    payload = request.responseText ? JSON.parse(request.responseText) : {};
                } catch (ignore) {
                    payload = {};
                }

                if (request.status >= 200 && request.status < 300) {
                    resolve(payload);
                    return;
                }

                if (request.status === 413) {
                    reject(new Error('上传内容超过服务器允许的大小，请调整 PHP 和 Web 服务器上传限制后重试。'));
                    return;
                }

                var fieldErrors = payload.errors || {};
                var firstField = Object.keys(fieldErrors)[0];
                var firstMessage = firstField && fieldErrors[firstField] && fieldErrors[firstField][0];
                var error = new Error(firstMessage || payload.message || config.productSaveFailedMessage);
                error.code = payload.code || '';
                error.payload = payload;
                error.status = request.status;
                reject(error);
            });
            request.addEventListener('error', function () {
                var error = new Error('上传连接中断');
                error.code = 'NETWORK_ERROR';
                error.status = 0;
                reject(error);
            });
            request.addEventListener('abort', function () {
                reject(new Error('上传已取消'));
            });
            request.addEventListener('timeout', function () {
                reject(new Error('服务器保存超时，请检查服务器执行时间限制后重试。'));
            });
            request.send(formData);
        });
    }

    function addCategory() {
        var label = categoryInput ? String(categoryInput.value || '').trim() : '';

        if (!label) {
            notify('warning', config.categoryEmptyTitle, config.categoryEmptyMessage);
            return;
        }

        var existing = uploadCategories.some(function (category) {
            return String(category.value || '').toLocaleLowerCase() === label.toLocaleLowerCase();
        });

        if (existing) {
            notify('warning', config.categoryDuplicateTitle, config.categoryDuplicateMessage);
            categoryInput.focus();
            return;
        }

        uploadCategories.push({value: label, label: label});
        renderCategories(selectedCategories().concat([label]));
        categoryInput.value = '';
        notify('success', config.categoryCreatedTitle, config.categoryCreatedMessage);
        categoryInput.focus();
    }

    function removeCategory(value) {
        var selectedValues = selectedCategories().filter(function (selectedValue) {
            return selectedValue !== value;
        });
        uploadCategories = uploadCategories.filter(function (category) {
            return category.value !== value;
        });
        renderCategories(selectedValues);
        notify('success', config.categoryDeselectedTitle, config.categoryDeselectedMessage);
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
            addImagePreview(item, 'data-aikaipin-remove-existing-image', item.index);
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
        if (createShopifyCsvSection) {
            createShopifyCsvSection.hidden = Boolean(editingProductId);
        }
        if (mainLandingField) {
            mainLandingField.hidden = !editingProductId;
        }
        setUploadSubmitState(false);
    }

    function shopifyCsvPairRows() {
        return uploadShopifyCsvList ? Array.prototype.slice.call(uploadShopifyCsvList.querySelectorAll('[data-aikaipin-csv-pair]')) : [];
    }

    function refreshShopifyCsvPairs() {
        var pairs = shopifyCsvPairRows();
        pairs.forEach(function (pair, index) {
            var number = pair.querySelector('[data-aikaipin-csv-pair-number]');
            var remove = pair.querySelector('[data-aikaipin-remove-csv-pair]');
            if (number) number.textContent = String(index + 1);
            if (remove) remove.disabled = pairs.length === 1;
        });
        if (addShopifyCsvPair) addShopifyCsvPair.disabled = pairs.length >= 10;
    }

    function addShopifyCsvPairRow() {
        if (!uploadShopifyCsvList) return;
        if (shopifyCsvPairRows().length >= 10) {
            notify('warning', '最多添加 10 个产品页', '一次最多上传 10 个 CSV 文件。');
            return;
        }
        var pair = document.createElement('div');
        pair.className = 'aikaipin-csv-link-row';
        pair.setAttribute('data-aikaipin-csv-pair', '');
        pair.innerHTML = '<div class="aikaipin-csv-link-index" data-aikaipin-csv-pair-number></div>' +
            '<div class="aikaipin-csv-link-file-input"><label>产品 CSV 文件 <span class="text-red">*</span></label>' +
            '<input class="form-control" type="file" name="shopify_csvs[]" accept=".csv,text/csv" required data-aikaipin-csv-file>' +
            '<small data-aikaipin-csv-file-meta>请选择一个 CSV 文件</small></div>' +
            '<div class="aikaipin-csv-link-input"><label>对应落地页链接 <span class="text-red">*</span></label>' +
            '<input class="form-control" type="url" name="shopify_landing_page_urls[]" maxlength="2048" placeholder="https://example.com/products/..." required data-aikaipin-csv-landing-url></div>' +
            '<button class="btn btn-danger btn-sm aikaipin-csv-pair-remove" type="button" data-aikaipin-remove-csv-pair title="删除这一组"><i class="fa fa-trash"></i></button>';
        uploadShopifyCsvList.appendChild(pair);
        refreshShopifyCsvPairs();
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
        existingMaterialVideos = [];
        existingMaterialReferenceUrl = '';
        removedMaterialVideoIds = [];
        removedHistoryVideoIndexes = [];
        packageUploadToken = '';
        referenceUploadToken = '';
        isProductSubmitting = false;
        renderSkus();
        renderCategories([]);
        renderImagePreviews();
        updateFileName(referenceFileName, null);
        updateFileName(packageFileName, null);
        renderMaterialVideos();
        if (uploadShopifyCsvList) {
            uploadShopifyCsvList.innerHTML = '';
            addShopifyCsvPairRow();
        }
        setUploadMode(null);
        renderShopifyEditData(null);
        editingProductPayload = null;

        if (uploadProgress) {
            uploadProgress.hidden = true;
        }
        if (uploadProgressBar) {
            uploadProgressBar.style.width = '0%';
            uploadProgressBar.setAttribute('aria-valuenow', '0');
        }
        resetProductSaveProgress();
        syncChoiceButtons();
    }

    function materialVideoTypeLabel(type) {
        return { flow: 'Flow 素材', traffic: '扒的素材', remix: '混剪素材' }[type] || '素材视频';
    }

    function materialVideoCardsHtml(items, referenceUrl, editable) {
        if (!items.length) return '<div class="aikaipin-material-video-empty"><i class="fa fa-film"></i> 暂无素材视频</div>';
        return items.map(function (item) {
            var media = item.preview_url
                ? '<button type="button" class="aikaipin-material-video-trigger" data-aikaipin-material-video-preview data-video-mode="' + (item.historical ? 'frame' : 'video') + '" data-video-src="' + escapeHtml(item.preview_url) + '" data-video-name="' + escapeHtml(item.name || '素材视频') + '"><i class="fa fa-play-circle"></i><span>点击播放素材视频</span></button>'
                : (item.pending
                    ? '<div class="aikaipin-material-video-history"><i class="fa fa-upload"></i><span>保存产品后即可在线播放</span></div>'
                    : '<div class="aikaipin-material-video-history"><i class="fa fa-file-code-o"></i><span>视频保存在历史 HTML 参考页中</span>' +
                        (referenceUrl ? '<a class="btn btn-info btn-xs" href="' + escapeHtml(referenceUrl) + '" target="_blank" rel="noopener noreferrer"><i class="fa fa-play"></i> 打开参考页播放</a>' : '') + '</div>');
            var deleteButton = '';
            if (editable) {
                if (item.pending) {
                    deleteButton = '<button type="button" class="aikaipin-material-video-delete" data-aikaipin-remove-pending-material-video="' + escapeHtml(item.type) + '" data-aikaipin-pending-video-index="' + Number(item.pending_index) + '"><i class="fa fa-times"></i> 移除待上传视频</button>';
                } else if (item.id) {
                    deleteButton = '<button type="button" class="aikaipin-material-video-delete" data-aikaipin-remove-material-video-id="' + Number(item.id) + '"><i class="fa fa-trash"></i> 删除视频</button>';
                } else if (item.historical && Number.isInteger(Number(item.history_index))) {
                    deleteButton = '<button type="button" class="aikaipin-material-video-delete" data-aikaipin-remove-history-video-index="' + Number(item.history_index) + '"><i class="fa fa-eye-slash"></i> 隐藏视频</button>';
                }
            }
            return '<article class="aikaipin-material-video-card' + (item.historical ? ' is-history' : '') + '">' + media +
                '<div class="aikaipin-material-video-card-info"><span class="aikaipin-material-video-type">' + escapeHtml(materialVideoTypeLabel(item.type)) + '</span>' +
                '<strong title="' + escapeHtml(item.name) + '">' + escapeHtml(item.name) + '</strong><small>' + escapeHtml(item.meta || item.size || '') + '</small>' + deleteButton + '</div></article>';
        }).join('');
    }

    function renderMaterialVideos() {
        if (!materialVideoList) return;
        var items = (existingMaterialVideos || []).map(function (video) {
            return {
                id: video.id || null,
                history_index: video.history_index,
                type: video.type,
                name: video.name,
                meta: video.historical ? '历史 HTML 视频' : (video.size || '已上传'),
                historical: Boolean(video.historical),
                preview_url: video.preview_url || ''
            };
        });
        materialVideoInputs.forEach(function (input) {
            var type = input.getAttribute('data-aikaipin-material-video');
            var files = Array.prototype.slice.call(input.files || []);
            var nameNode = document.querySelector('[data-aikaipin-material-video-name="' + type + '"]');
            if (nameNode) nameNode.textContent = files.length ? ('已选择 ' + files.length + ' 个文件') : ('可多选，单个最大 300MB（' + ({ flow: 'flow', traffic: 'spy', remix: 'mix' }[type] || type) + '）');
            files.forEach(function (file, pendingIndex) {
                items.push({ type: type, name: file.name, meta: (file.size / 1048576).toFixed(2) + ' MB · 待上传', historical: false, pending: true, pending_index: pendingIndex });
            });
        });
        materialVideoList.hidden = items.length === 0;
        materialVideoList.innerHTML = materialVideoCardsHtml(items, existingMaterialReferenceUrl, Boolean(editingProductId));
        bindMaterialVideoPlaybackErrors(materialVideoList);
    }

    function bindMaterialVideoPlaybackErrors(root) {
        return root;
    }

    function resetMaterialVideoPreview() {
        if (materialVideoPreviewPlayer) {
            materialVideoPreviewPlayer.pause();
            materialVideoPreviewPlayer.removeAttribute('src');
            materialVideoPreviewPlayer.classList.remove('is-active');
            materialVideoPreviewPlayer.load();
        }
        if (materialVideoPreviewFrame) {
            materialVideoPreviewFrame.removeAttribute('src');
            materialVideoPreviewFrame.classList.remove('is-active');
        }
        if (materialVideoPreviewError) materialVideoPreviewError.hidden = true;
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-aikaipin-material-video-preview]');
        if (!trigger || !materialVideoPreviewModal || !window.jQuery) return;
        var source = trigger.getAttribute('data-video-src') || '';
        if (!source) return;
        resetMaterialVideoPreview();
        if (materialVideoPreviewTitle) materialVideoPreviewTitle.textContent = trigger.getAttribute('data-video-name') || '素材视频';
        if (materialVideoPreviewOpen) materialVideoPreviewOpen.href = source;
        if (trigger.getAttribute('data-video-mode') === 'frame' && materialVideoPreviewFrame) {
            materialVideoPreviewFrame.src = source;
            materialVideoPreviewFrame.classList.add('is-active');
        } else if (materialVideoPreviewPlayer) {
            materialVideoPreviewPlayer.src = source;
            materialVideoPreviewPlayer.classList.add('is-active');
            materialVideoPreviewPlayer.load();
        }
        window.jQuery(materialVideoPreviewModal).modal('show');
    });
    if (materialVideoPreviewPlayer) {
        materialVideoPreviewPlayer.addEventListener('error', function () {
            if (materialVideoPreviewError) materialVideoPreviewError.hidden = false;
        });
    }
    if (materialVideoPreviewModal && window.jQuery) {
        window.jQuery(materialVideoPreviewModal)
            .on('shown.bs.modal', function () {
                var backdrops = document.querySelectorAll('.modal-backdrop');
                var backdrop = backdrops.length ? backdrops[backdrops.length - 1] : null;
                if (backdrop) backdrop.classList.add('aikaipin-material-video-preview-backdrop');
            })
            .on('hidden.bs.modal', function () {
                resetMaterialVideoPreview();
                Array.prototype.forEach.call(document.querySelectorAll('.aikaipin-material-video-preview-backdrop'), function (backdrop) {
                    backdrop.classList.remove('aikaipin-material-video-preview-backdrop');
                });
                if (document.querySelector('.modal.in')) document.body.classList.add('modal-open');
            });
    }

    function openProductViewModal(productId) {
        if (!productId || !productViewModal || !window.jQuery) return;
        productViewTitle.textContent = '产品详情';
        productViewVideos.innerHTML = '<div class="text-muted"><i class="fa fa-spinner fa-spin"></i> 正在加载素材视频...</div>';
        productViewReference.hidden = true;
        window.jQuery(productViewModal).modal('show');
        var url = String(config.productViewUrlTemplate || '').replace('__product__', encodeURIComponent(productId));
        jsonRequest(url, 'GET').then(function (product) {
            productViewTitle.textContent = (product.product_code || '') + ' · ' + (product.name || '产品详情');
            var referenceUrl = product.reference_url || '';
            productViewReference.hidden = !referenceUrl;
            if (referenceUrl) productViewReference.href = referenceUrl;
            productViewVideos.innerHTML = materialVideoCardsHtml(Array.isArray(product.material_videos) ? product.material_videos : [], referenceUrl, false);
            bindMaterialVideoPlaybackErrors(productViewVideos);
        }).catch(function (error) {
            productViewVideos.innerHTML = '<div class="alert alert-danger">' + escapeHtml(error.message || '素材视频加载失败') + '</div>';
        });
    }

    function shopifySummaryGrid(data) {
        var items = [
            ['Shopify 标题', data.title || '—'], ['Handle', data.handle || '—'],
            ['供应商', data.vendor || '—'], ['商品类型', data.product_type || '—'],
            ['状态', data.status || '—'], ['来源文件', data.source_file || '—'],
            ['导入时间', data.imported_at || '—'], ['商品描述', String(data.description_length || 0) + ' 字'],
            ['规格数量', String(data.variant_count || 0) + ' 个'], ['图片数量', String(data.image_count || 0) + ' 张']
        ];
        return '<div class="aikaipin-shopify-edit-meta">' + items.map(function (item) {
            return '<div><span>' + escapeHtml(item[0]) + '</span><strong>' + escapeHtml(item[1]) + '</strong></div>';
        }).join('') + '</div>';
    }

    function shopifyVariantsTable(data, limit) {
        var variants = Array.isArray(data.variants) ? data.variants.slice(0, limit || data.variants.length) : [];
        if (!variants.length) return '';
        return '<div class="aikaipin-shopify-edit-variants"><table class="table table-condensed"><thead><tr><th>SKU</th><th>选项</th><th>价格</th><th>库存</th></tr></thead><tbody>' +
            variants.map(function (variant) {
                return '<tr><td>' + escapeHtml(variant.sku) + '</td><td>' + escapeHtml((variant.options || []).join(' / ') || '—') +
                    '</td><td>$' + escapeHtml(variant.price) + '</td><td>' + escapeHtml(variant.inventory_quantity) + '</td></tr>';
            }).join('') + '</tbody></table></div>';
    }

    function decorateShopifyEditCards(productPages) {
        if (!shopifyEditContent || !editingProductId) return;

        var toolbar = document.createElement('div');
        toolbar.className = 'aikaipin-shopify-page-toolbar';
        toolbar.innerHTML = '<div><strong>' + (productPages.length ? '产品 CSV 与落地页' : '尚未添加产品 CSV') + '</strong>' +
            '<span>' + (productPages.length ? '每个 CSV 对应一个独立落地页链接' : '上传 CSV 后需填写一一对应的落地页链接') + '</span></div>' +
            '<button type="button" class="btn btn-info btn-sm" data-aikaipin-edit-add-product-page><i class="fa fa-plus"></i> 添加产品 CSV</button>';
        shopifyEditContent.insertBefore(toolbar, shopifyEditContent.firstChild);

        Array.prototype.slice.call(shopifyEditContent.querySelectorAll('.aikaipin-shopify-page-card')).forEach(function (card, index) {
            var page = productPages[index];
            if (!page) return;
            var heading = card.querySelector('.aikaipin-shopify-page-heading');
            var oldLanding = heading ? heading.querySelector('.aikaipin-shopify-page-link, .aikaipin-shopify-page-missing') : null;
            if (oldLanding) oldLanding.remove();
            if (heading) {
                var actions = document.createElement('div');
                actions.className = 'aikaipin-shopify-page-actions';
                actions.innerHTML = '<button type="button" class="btn btn-danger btn-xs" data-aikaipin-delete-product-page="' + escapeHtml(page.id) + '"><i class="fa fa-trash"></i> 删除 CSV</button>';
                heading.appendChild(actions);
            }
            var landing = document.createElement('div');
            landing.className = 'aikaipin-shopify-page-landing';
            landing.innerHTML = '<label>对应落地页链接</label><div class="input-group">' +
                '<input class="form-control" type="url" maxlength="2048" placeholder="https://example.com/products/..." value="' + escapeHtml(page.landing_page_url || '') + '" data-aikaipin-product-page-url="' + escapeHtml(page.id) + '">' +
                '<span class="input-group-btn"><button type="button" class="btn btn-primary" data-aikaipin-save-product-page="' + escapeHtml(page.id) + '"><i class="fa fa-save"></i> 保存链接</button></span></div>';
            if (heading) card.insertBefore(landing, heading.nextSibling);
        });
    }

    function openShopifyImportFromEdit() {
        if (!editingProductPayload || !editingProductId) return;
        var button = document.createElement('button');
        button.setAttribute('data-aikaipin-product-id', String(editingProductId));
        button.setAttribute('data-aikaipin-product-name', editingProductPayload.name || '');
        button.setAttribute('data-aikaipin-product-code', editingProductPayload.product_code || '');
        button.setAttribute('data-aikaipin-landing-page-url', '');
        if (uploadModal && window.jQuery) window.jQuery(uploadModal).modal('hide');
        window.setTimeout(function () { openShopifyImport(button); }, 220);
    }

    function saveProductPage(pageId, button) {
        var input = shopifyEditContent.querySelector('[data-aikaipin-product-page-url="' + pageId + '"]');
        var url = input ? String(input.value || '').trim() : '';
        if (url && !input.checkValidity()) {
            notify('warning', '链接格式不正确', '请填写以 http:// 或 https:// 开头的完整落地页链接。');
            input.focus();
            return;
        }
        button.disabled = true;
        jsonRequest(productPageUrl(config.productPageUpdateUrlTemplate, editingProductId, pageId), 'PATCH', { landing_page_url: url })
            .then(function (payload) { notify('success', '保存成功', payload.message || '落地页链接已保存。'); })
            .catch(function (error) { notify('error', '保存失败', error.message || '落地页链接保存失败。'); })
            .then(function () { button.disabled = false; });
    }

    function deleteProductPage(pageId, button) {
        if (!window.confirm('确认删除这个产品 CSV 及其产品页资料？此操作不会删除整个商品。')) return;
        button.disabled = true;
        jsonRequest(productPageUrl(config.productPageDeleteUrlTemplate, editingProductId, pageId), 'DELETE')
            .then(function (payload) {
                editingProductPayload.product_pages = payload.product_pages || [];
                editingProductPayload.shopify_data = payload.shopify_data || null;
                renderShopifyEditData(editingProductPayload.shopify_data, editingProductPayload.product_pages);
                notify('success', '删除成功', payload.message || '产品 CSV 已删除。');
            }).catch(function (error) {
                button.disabled = false;
                notify('error', '删除失败', error.message || '产品 CSV 删除失败。');
            });
    }

    function renderShopifyEditData(data, pages) {
        if (!shopifyEditSection || !shopifyEditContent) return;
        var productPages = Array.isArray(pages) ? pages : [];
        shopifyEditSection.hidden = !editingProductId && !data && !productPages.length;
        if (productPages.length) {
            shopifyEditContent.innerHTML = productPages.map(function (page, index) {
                var pageData = page.shopify_data || {};
                var landing = page.landing_page_url
                    ? '<a class="aikaipin-shopify-page-link" href="' + escapeHtml(page.landing_page_url) + '" target="_blank" rel="noopener noreferrer" title="' + escapeHtml(page.landing_page_url) + '"><i class="fa fa-external-link"></i> 打开落地页</a>'
                    : '<span class="aikaipin-shopify-page-missing"><i class="fa fa-exclamation-circle"></i> 未设置落地页</span>';
                return '<div class="aikaipin-shopify-page-card"><div class="aikaipin-shopify-page-heading"><h5><i class="fa fa-file-text-o"></i> ' +
                    escapeHtml(page.name || ('产品页 ' + (index + 1))) +
                    (page.is_default ? ' <span class="label label-primary">默认</span>' : '') + '</h5>' + landing + '</div>' +
                    shopifySummaryGrid(pageData) + shopifyVariantsTable(pageData) + '</div>';
            }).join('');
            decorateShopifyEditCards(productPages);
            return;
        }
        shopifyEditContent.innerHTML = data ? shopifySummaryGrid(data) + shopifyVariantsTable(data) : '';
        decorateShopifyEditCards([]);
    }

    function resetShopifyImport() {
        importingProductId = null;
        shopifyImportToken = '';
        if (shopifyImportPageName) shopifyImportPageName.value = '';
        if (shopifyImportLandingPageUrl) shopifyImportLandingPageUrl.value = '';
        if (shopifyImportFile) shopifyImportFile.value = '';
        if (shopifyImportFileName) { shopifyImportFileName.hidden = true; shopifyImportFileName.textContent = ''; }
        if (shopifyImportPreview) shopifyImportPreview.innerHTML = '';
        if (shopifyImportPreviewButton) { shopifyImportPreviewButton.hidden = false; shopifyImportPreviewButton.disabled = false; shopifyImportPreviewButton.innerHTML = '<i class="fa fa-search"></i> 校验并预览'; }
        if (shopifyImportConfirm) { shopifyImportConfirm.hidden = true; shopifyImportConfirm.disabled = false; shopifyImportConfirm.innerHTML = '<i class="fa fa-check"></i> 保存为新产品页'; }
    }

    function openShopifyImport(button) {
        resetShopifyImport();
        importingProductId = Number(button.getAttribute('data-aikaipin-product-id'));
        if (shopifyImportLandingPageUrl) shopifyImportLandingPageUrl.value = button.getAttribute('data-aikaipin-landing-page-url') || '';
        if (shopifyImportProduct) {
            shopifyImportProduct.innerHTML = '<strong>' + escapeHtml(button.getAttribute('data-aikaipin-product-name')) + '</strong><span>' +
                escapeHtml(button.getAttribute('data-aikaipin-product-code')) + '</span>';
        }
        if (shopifyImportModal && window.jQuery) window.jQuery(shopifyImportModal).modal('show');
    }

    function previewShopifyImport() {
        var file = shopifyImportFile && shopifyImportFile.files && shopifyImportFile.files[0];
        var pageName = shopifyImportPageName ? shopifyImportPageName.value.trim() : '';
        var landingPageUrl = shopifyImportLandingPageUrl ? shopifyImportLandingPageUrl.value.trim() : '';
        if (!pageName) {
            notify('warning', '请填写产品页名称', '请先填写便于上架时识别的产品页名称。');
            if (shopifyImportPageName) shopifyImportPageName.focus();
            return;
        }
        if (!landingPageUrl || (shopifyImportLandingPageUrl && !shopifyImportLandingPageUrl.checkValidity())) {
            notify('warning', '请填写有效的落地页链接', '每个 CSV 文件必须绑定一个以 http:// 或 https:// 开头的落地页链接。');
            if (shopifyImportLandingPageUrl) shopifyImportLandingPageUrl.focus();
            return;
        }
        if (!importingProductId || !file) {
            notify('warning', '请选择文件', '请选择 Shopify 导出的 CSV 商品文件。');
            return;
        }
        var data = new FormData();
        data.append('shopify_csv', file, file.name);
        data.append('page_name', pageName);
        data.append('landing_page_url', landingPageUrl);
        shopifyImportPreviewButton.disabled = true;
        shopifyImportPreviewButton.innerHTML = '<i class="fa fa-spinner fa-spin"></i> 正在校验...';
        formDataRequest(templateUrl(config.shopifyImportPreviewUrlTemplate, importingProductId), data).then(function (payload) {
            shopifyImportToken = payload.preview_token;
            var shopify = payload.shopify || {};
            shopifyImportPreview.innerHTML = '<div class="aikaipin-shopify-preview-card"><div class="aikaipin-shopify-preview-title"><i class="fa fa-check-circle"></i> 文件校验通过，匹配 ' +
                escapeHtml((payload.matched_skus || []).length) + ' 个 SKU</div>' + shopifySummaryGrid(shopify) + shopifyVariantsTable(shopify, 8) + '</div>';
            shopifyImportConfirm.hidden = false;
        }).catch(function (error) {
            shopifyImportToken = '';
            shopifyImportConfirm.hidden = true;
            shopifyImportPreview.innerHTML = '<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> ' + escapeHtml(error.message) + '</div>';
        }).then(function () {
            shopifyImportPreviewButton.disabled = false;
            shopifyImportPreviewButton.innerHTML = '<i class="fa fa-search"></i> 校验并预览';
        });
    }

    function confirmShopifyImport() {
        if (!shopifyImportToken || !importingProductId) return;
        shopifyImportConfirm.disabled = true;
        shopifyImportConfirm.innerHTML = '<i class="fa fa-spinner fa-spin"></i> 正在导入...';
        jsonRequest(templateUrl(config.shopifyImportStoreUrlTemplate, importingProductId), 'POST', {
            preview_token: shopifyImportToken,
            page_name: shopifyImportPageName ? shopifyImportPageName.value.trim() : '',
            landing_page_url: shopifyImportLandingPageUrl ? shopifyImportLandingPageUrl.value.trim() : ''
        }).then(function (payload) {
            notify('success', '导入成功', payload.message || 'Shopify 商品资料已补充。');
            if (shopifyImportModal && window.jQuery) window.jQuery(shopifyImportModal).modal('hide');
            window.setTimeout(function () { window.location.assign(window.location.href); }, 350);
        }).catch(function (error) {
            notify('error', '导入失败', error.message || 'Shopify 商品资料导入失败。');
            shopifyImportConfirm.disabled = false;
            shopifyImportConfirm.innerHTML = '<i class="fa fa-check"></i> 保存为新产品页';
        });
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

    function formatUploadSize(bytes) {
        var value = Number(bytes || 0);

        if (value >= 1024 * 1024 * 1024) {
            return (value / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
        }
        if (value >= 1024 * 1024) {
            return (value / (1024 * 1024)).toFixed(2) + ' MB';
        }
        if (value >= 1024) {
            return (value / 1024).toFixed(1) + ' KB';
        }

        return value + ' B';
    }

    function resetProductSaveProgress() {
        if (productSaveProgress) {
            productSaveProgress.hidden = true;
        }
        if (productSaveProgressBar) {
            productSaveProgressBar.style.width = '0%';
            productSaveProgressBar.setAttribute('aria-valuenow', '0');
        }
        if (productSaveProgressPercent) {
            productSaveProgressPercent.textContent = '0%';
        }
        if (productSaveProgressLabel) {
            productSaveProgressLabel.textContent = '正在上传文件，请勿关闭页面';
        }
        if (productSaveProgressDetail) {
            productSaveProgressDetail.textContent = '准备上传...';
        }
    }

    function updateProductSaveProgress(received, total) {
        var percentage = total > 0 ? Math.min(100, Math.round((received / total) * 100)) : 0;

        if (productSaveProgress) {
            productSaveProgress.hidden = false;
        }
        if (productSaveProgressBar) {
            productSaveProgressBar.style.width = percentage + '%';
            productSaveProgressBar.setAttribute('aria-valuenow', String(percentage));
        }
        if (productSaveProgressPercent) {
            productSaveProgressPercent.textContent = percentage + '%';
        }
        if (productSaveProgressLabel) {
            productSaveProgressLabel.textContent = percentage >= 100
                ? '文件上传完成，正在保存产品数据...'
                : '正在上传文件，请勿关闭页面';
        }
        if (productSaveProgressDetail) {
            productSaveProgressDetail.textContent = formatUploadSize(received) + ' / ' + formatUploadSize(total);
        }
    }

    function showProductSavingState(isEditing) {
        productSaveStartedAt = Date.now();
        updateProductSaveProgress(0, 0);
        if (productSaveProgressLabel) {
            productSaveProgressLabel.textContent = isEditing
                ? '正在保存产品修改，请勿关闭页面'
                : '正在保存产品，请勿关闭页面';
        }
        if (productSaveProgressDetail) {
            productSaveProgressDetail.textContent = '正在准备并提交数据...';
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

        activeUploadFileName = file.name;
        return uploadJsonRequest(config.packageUploadInitializeUrl, 'POST', {
            client_fingerprint: packageFingerprint(file),
            original_name: file.name,
            mime_type: file.type || null,
            total_size: file.size,
            total_chunks: totalChunks
        }, file.name).then(function (session) {
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
                    return uploadJsonRequest(packageCompleteUrl(packageUploadToken), 'POST', null, file.name).then(function (completed) {
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
                return retryUploadRequest(function () {
                    return formDataRequest(packagePartUrl(packageUploadToken, currentIndex), partData);
                }, file.name, 5).then(function (updatedSession) {
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

    function uploadReference(file) {
        var totalChunks = Math.ceil(file.size / (5 * 1024 * 1024));

        activeUploadFileName = file.name;
        return uploadJsonRequest(config.packageUploadInitializeUrl, 'POST', {
            client_fingerprint: packageFingerprint(file),
            original_name: file.name,
            mime_type: file.type || 'text/html',
            total_size: file.size,
            total_chunks: totalChunks
        }, file.name).then(function (session) {
            var uploaded = {};
            var chunkIndex = 0;

            referenceUploadToken = session.upload_token;
            (session.uploaded_chunks || []).forEach(function (index) {
                uploaded[Number(index)] = true;
            });
            updateProductSaveProgress(Number(session.received_size || 0), file.size);

            function uploadNextPart() {
                while (chunkIndex < totalChunks && uploaded[chunkIndex]) {
                    chunkIndex += 1;
                }
                if (chunkIndex >= totalChunks) {
                    return uploadJsonRequest(packageCompleteUrl(referenceUploadToken), 'POST', null, file.name).then(function (completed) {
                        referenceUploadToken = completed.upload_token;
                        updateProductSaveProgress(file.size, file.size);
                        return completed;
                    });
                }

                var start = chunkIndex * 5 * 1024 * 1024;
                var end = Math.min(file.size, start + 5 * 1024 * 1024);
                var currentIndex = chunkIndex;
                var partData = new FormData();
                partData.append('chunk', file.slice(start, end), file.name + '.part');

                return retryUploadRequest(function () {
                    return formDataRequest(packagePartUrl(referenceUploadToken, currentIndex), partData);
                }, file.name, 5).then(function (updatedSession) {
                    uploaded[currentIndex] = true;
                    chunkIndex = currentIndex + 1;
                    updateProductSaveProgress(Number(updatedSession.received_size || 0), file.size);
                    return uploadNextPart();
                });
            }

            return session.status === 'completed' ? session : uploadNextPart();
        });
    }

    function uploadMaterialVideoFiles(items) {
        var totalSize = items.reduce(function (sum, item) { return sum + item.file.size; }, 0);
        var completedSize = 0;
        var uploadedSessions = [];

        function uploadOne(item) {
            var file = item.file;
            var totalChunks = Math.ceil(file.size / (5 * 1024 * 1024));

            activeUploadFileName = file.name;
            return uploadJsonRequest(config.packageUploadInitializeUrl, 'POST', {
                client_fingerprint: packageFingerprint(file),
                original_name: file.name,
                mime_type: file.type || 'video/mp4',
                total_size: file.size,
                total_chunks: totalChunks
            }, file.name).then(function (session) {
                var uploaded = {};
                var chunkIndex = 0;
                var uploadToken = session.upload_token;

                (session.uploaded_chunks || []).forEach(function (index) {
                    uploaded[Number(index)] = true;
                });
                updateProductSaveProgress(completedSize + Number(session.received_size || 0), totalSize);

                function uploadNextPart() {
                    while (chunkIndex < totalChunks && uploaded[chunkIndex]) {
                        chunkIndex += 1;
                    }
                    if (chunkIndex >= totalChunks) {
                        return uploadJsonRequest(packageCompleteUrl(uploadToken), 'POST', null, file.name).then(function (completed) {
                            updateProductSaveProgress(completedSize + file.size, totalSize);
                            return completed;
                        });
                    }

                    var start = chunkIndex * 5 * 1024 * 1024;
                    var end = Math.min(file.size, start + 5 * 1024 * 1024);
                    var currentIndex = chunkIndex;
                    var partData = new FormData();
                    partData.append('chunk', file.slice(start, end), file.name + '.part');

                    return retryUploadRequest(function () {
                        return formDataRequest(packagePartUrl(uploadToken, currentIndex), partData);
                    }, file.name, 5).then(function (updatedSession) {
                        uploaded[currentIndex] = true;
                        chunkIndex = currentIndex + 1;
                        updateProductSaveProgress(completedSize + Number(updatedSession.received_size || 0), totalSize);
                        return uploadNextPart();
                    });
                }

                return session.status === 'completed' ? session : uploadNextPart();
            }).then(function (session) {
                completedSize += file.size;
                uploadedSessions.push({type: item.type, upload_token: session.upload_token});
            });
        }

        return items.reduce(function (promise, item) {
            return promise.then(function () { return uploadOne(item); });
        }, Promise.resolve()).then(function () { return uploadedSessions; });
    }

    function submitProduct() {
        var packageFileToUpload = packageFile && packageFile.files && packageFile.files[0];
        var referenceFileToUpload = referenceFile && referenceFile.files && referenceFile.files[0];
        var csvPairs = shopifyCsvPairRows();
        var shopifyCsvFiles = csvPairs.map(function (pair) {
            var input = pair.querySelector('[data-aikaipin-csv-file]');
            return input && input.files ? input.files[0] : null;
        }).filter(Boolean);
        var materialVideoFilesToUpload = [];
        materialVideoInputs.forEach(function (input) {
            var type = input.getAttribute('data-aikaipin-material-video');
            Array.prototype.slice.call(input.files || []).forEach(function (file) {
                materialVideoFilesToUpload.push({type: type, file: file});
            });
        });
        var isEditing = editingProductId !== null;
        var packageRequest;

        activeUploadFileName = '';

        if (isProductSubmitting) {
            return;
        }

        if (!uploadName || String(uploadName.value || '').trim() === '') {
            notify('warning', config.productNameRequiredTitle, config.productNameRequiredMessage);
            uploadName.focus();
            setUploadSubmitState(false);
            return;
        }

        if (!isEditing && !shopifyCsvFiles.length) {
            notify('warning', '请选择 Shopify 商品 CSV', '上传 Shopify 商品 CSV 文件为必填项，请选择文件后再保存。');
            var firstCsvInput = uploadShopifyCsvList && uploadShopifyCsvList.querySelector('[data-aikaipin-csv-file]');
            if (firstCsvInput) firstCsvInput.focus();
            setUploadSubmitState(false);
            return;
        }
        if (!isEditing) {
            var landingInputs = uploadShopifyCsvList ? Array.prototype.slice.call(uploadShopifyCsvList.querySelectorAll('[data-aikaipin-csv-landing-url]')) : [];
            var invalidLanding = landingInputs.find(function (input) { return !String(input.value || '').trim() || !input.checkValidity(); });
            var incompleteFile = csvPairs.find(function (pair) {
                var input = pair.querySelector('[data-aikaipin-csv-file]');
                return !input || !input.files || !input.files[0];
            });
            if (landingInputs.length !== shopifyCsvFiles.length || invalidLanding || incompleteFile) {
                notify('warning', '请完善落地页链接', '每个 CSV 文件都必须填写一个有效的落地页链接。');
                if (invalidLanding) invalidLanding.focus();
                else if (incompleteFile) incompleteFile.querySelector('[data-aikaipin-csv-file]').focus();
                setUploadSubmitState(false);
                return;
            }
        }

        isProductSubmitting = true;
        showProductSavingState(isEditing);
        if (packageFileToUpload) {
            setUploadSubmitState(true, config.uploadingProductPackage);
            packageRequest = uploadPackage(packageFileToUpload);
        } else {
            setUploadSubmitState(true, config.savingProduct);
            packageRequest = Promise.resolve(null);
        }

        packageRequest.then(function (session) {
            if (!referenceFileToUpload) {
                return { packageSession: session, referenceSession: null };
            }
            setUploadSubmitState(true, '正在分片上传参考页面...');
            return uploadReference(referenceFileToUpload).then(function (referenceSession) {
                return { packageSession: session, referenceSession: referenceSession };
            });
        }).then(function (sessions) {
            if (!materialVideoFilesToUpload.length) {
                sessions.materialVideoSessions = [];
                return sessions;
            }
            setUploadSubmitState(true, '正在分片上传素材视频...');
            return uploadMaterialVideoFiles(materialVideoFilesToUpload).then(function (materialVideoSessions) {
                sessions.materialVideoSessions = materialVideoSessions;
                return sessions;
            });
        }).then(function (sessions) {
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
            removedImageIds.forEach(function (index) {
                formData.append('remove_image_indexes[]', String(index));
            });
            removedMaterialVideoIds.forEach(function (id) {
                formData.append('remove_material_video_ids[]', String(id));
            });
            removedHistoryVideoIndexes.forEach(function (index) {
                formData.append('remove_history_video_indexes[]', String(index));
            });
            if (sessions.packageSession && sessions.packageSession.upload_token) {
                formData.append('package_upload_token', sessions.packageSession.upload_token);
            }
            if (sessions.referenceSession && sessions.referenceSession.upload_token) {
                formData.append('reference_upload_token', sessions.referenceSession.upload_token);
            }
            materialVideoInputs.forEach(function (input) {
                if (input.name) formData.delete(input.name);
            });
            (sessions.materialVideoSessions || []).forEach(function (session) {
                formData.append('material_video_upload_tokens[' + session.type + '][]', session.upload_token);
            });
            if (isEditing) {
                formData.append('_method', 'PUT');
            }
            activeUploadFileName = '';
            setUploadSubmitState(true, config.savingProduct);
            showProductSavingState(isEditing);

            return formDataRequest(requestUrl, formData, updateProductSaveProgress);
        }).then(function (payload) {
            var remaining = Math.max(0, 900 - (Date.now() - productSaveStartedAt));

            return new Promise(function (resolve) {
                window.setTimeout(function () {
                    resolve(payload);
                }, remaining);
            });
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
                window.location.assign(isEditing ? window.location.href : (payload.redirect_url || window.location.href));
            }, 400);
        }).catch(function (error) {
            var uploadErrorMessage = readableUploadError(error, activeUploadFileName);

            notify('error', config.productSaveFailedTitle, uploadErrorMessage);
            isProductSubmitting = false;
            setUploadSubmitState(false);
            if (productSaveProgressLabel) {
                productSaveProgressLabel.textContent = uploadErrorMessage;
            }
            if (productSaveProgressDetail && activeUploadFileName) {
                productSaveProgressDetail.textContent = '失败文件：' + activeUploadFileName + '；再次保存时会从已完成分片继续';
            }
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
            editingProductPayload = product;
            uploadName.value = product.name || '';
            if (uploadLandingUrl) {
                uploadLandingUrl.value = product.landing_page_url || '';
            }
            setRadioValue('source_platform', product.source_platform);
            setRadioValue('product_type', product.product_type);
            setRadioValue('is_published', product.is_published ? '1' : '0');
            uploadSkus = Array.isArray(product.skus) ? product.skus.slice() : [];
            existingImageFiles = Array.isArray(product.images) ? product.images.slice() : [];
            existingReferenceFile = product.reference_file || null;
            existingPackageFile = product.package_file || null;
            existingMaterialVideos = Array.isArray(product.material_videos) ? product.material_videos.slice() : [];
            existingMaterialReferenceUrl = product.reference_url || '';
            renderSkus();
            renderCategories(Array.isArray(product.categories) ? product.categories.map(String) : []);
            renderImagePreviews();
            updateExistingFileName(referenceFileName, existingReferenceFile);
            updateExistingFileName(packageFileName, existingPackageFile);
            renderMaterialVideos();
            setAuthorizedDepartments(product.authorized_department_ids);
            renderShopifyEditData(product.shopify_data || null, product.product_pages || []);
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

    materialVideoInputs.forEach(function (input) {
        input.addEventListener('change', renderMaterialVideos);
    });

    if (addShopifyCsvPair) addShopifyCsvPair.addEventListener('click', addShopifyCsvPairRow);
    if (uploadShopifyCsvList) {
        uploadShopifyCsvList.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-aikaipin-remove-csv-pair]');
            if (!remove || remove.disabled) return;
            var pair = remove.closest('[data-aikaipin-csv-pair]');
            if (pair) pair.remove();
            refreshShopifyCsvPairs();
        });
        uploadShopifyCsvList.addEventListener('change', function (event) {
            var input = event.target.closest('[data-aikaipin-csv-file]');
            if (!input) return;
            var file = input.files && input.files[0];
            var meta = input.closest('[data-aikaipin-csv-pair]').querySelector('[data-aikaipin-csv-file-meta]');
            if (meta) meta.textContent = file ? file.name + ' · ' + (file.size / 1048576).toFixed(2) + ' MB' : '请选择一个 CSV 文件';
        });
    }

    if (shopifyImportFile) {
        shopifyImportFile.addEventListener('change', function () {
            var file = shopifyImportFile.files && shopifyImportFile.files[0];
            shopifyImportToken = '';
            shopifyImportConfirm.hidden = true;
            shopifyImportPreview.innerHTML = '';
            shopifyImportFileName.textContent = file ? '已选择：' + file.name : '';
            shopifyImportFileName.hidden = !file;
        });
    }
    if (shopifyImportPreviewButton) shopifyImportPreviewButton.addEventListener('click', previewShopifyImport);
    if (shopifyImportConfirm) shopifyImportConfirm.addEventListener('click', confirmShopifyImport);
    if (shopifyImportModal && window.jQuery) window.jQuery(shopifyImportModal).on('hidden.bs.modal', resetShopifyImport);

    if (shopifyEditContent) {
        shopifyEditContent.addEventListener('click', function (event) {
            var addButton = event.target.closest('[data-aikaipin-edit-add-product-page]');
            if (addButton) {
                openShopifyImportFromEdit();
                return;
            }
            var saveButton = event.target.closest('[data-aikaipin-save-product-page]');
            if (saveButton) {
                saveProductPage(Number(saveButton.getAttribute('data-aikaipin-save-product-page')), saveButton);
                return;
            }
            var deleteButton = event.target.closest('[data-aikaipin-delete-product-page]');
            if (deleteButton) {
                deleteProductPage(Number(deleteButton.getAttribute('data-aikaipin-delete-product-page')), deleteButton);
            }
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

    if (productViewModal && window.jQuery) {
        window.jQuery(productViewModal).on('hidden.bs.modal', function () {
            Array.prototype.slice.call(productViewModal.querySelectorAll('video')).forEach(function (video) {
                video.pause();
                video.removeAttribute('src');
                video.load();
            });
        });
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
                return Number(image.index) !== existingImageId;
            });
            if (removedImageIds.indexOf(existingImageId) === -1) {
                removedImageIds.push(existingImageId);
            }
            renderImagePreviews();
            return;
        }

        var removeMaterialVideoButton = event.target.closest('[data-aikaipin-remove-material-video-id]');
        if (removeMaterialVideoButton) {
            var materialVideoId = Number(removeMaterialVideoButton.getAttribute('data-aikaipin-remove-material-video-id'));
            existingMaterialVideos = existingMaterialVideos.filter(function (video) {
                return Number(video.id) !== materialVideoId;
            });
            if (removedMaterialVideoIds.indexOf(materialVideoId) === -1) {
                removedMaterialVideoIds.push(materialVideoId);
            }
            renderMaterialVideos();
            return;
        }

        var removePendingMaterialVideoButton = event.target.closest('[data-aikaipin-remove-pending-material-video]');
        if (removePendingMaterialVideoButton) {
            var pendingVideoType = removePendingMaterialVideoButton.getAttribute('data-aikaipin-remove-pending-material-video');
            var pendingVideoIndex = Number(removePendingMaterialVideoButton.getAttribute('data-aikaipin-pending-video-index'));
            var pendingVideoInput = materialVideoInputs.find(function (input) {
                return input.getAttribute('data-aikaipin-material-video') === pendingVideoType;
            });
            if (pendingVideoInput) {
                var pendingFiles = Array.prototype.slice.call(pendingVideoInput.files || []);
                if (typeof window.DataTransfer === 'function') {
                    var transfer = new window.DataTransfer();
                    pendingFiles.forEach(function (file, index) {
                        if (index !== pendingVideoIndex) transfer.items.add(file);
                    });
                    pendingVideoInput.files = transfer.files;
                } else {
                    pendingVideoInput.value = '';
                }
                renderMaterialVideos();
            }
            return;
        }

        var removeHistoryVideoButton = event.target.closest('[data-aikaipin-remove-history-video-index]');
        if (removeHistoryVideoButton) {
            var historyVideoIndex = Number(removeHistoryVideoButton.getAttribute('data-aikaipin-remove-history-video-index'));
            existingMaterialVideos = existingMaterialVideos.filter(function (video) {
                return Number(video.history_index) !== historyVideoIndex;
            });
            if (removedHistoryVideoIndexes.indexOf(historyVideoIndex) === -1) {
                removedHistoryVideoIndexes.push(historyVideoIndex);
            }
            renderMaterialVideos();
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

        var productDeleteConfirmButton = event.target.closest('[data-aikaipin-product-delete-confirm]');
        if (productDeleteConfirmButton) {
            deleteProduct();
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
            if (actionButton.getAttribute('data-aikaipin-action') === 'view') {
                openProductViewModal(Number(actionButton.getAttribute('data-aikaipin-product-id')));
                return;
            }
            if (actionButton.getAttribute('data-aikaipin-action') === 'shopify-import') {
                openShopifyImport(actionButton);
                return;
            }
            if (actionButton.getAttribute('data-aikaipin-action') === 'delete') {
                openProductDeleteModal(actionButton);
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
