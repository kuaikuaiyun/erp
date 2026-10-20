(function (window, document) {
    'use strict';

    var config = window.ErpAikaipinSubmissionConfig || {};
    var listForm = document.querySelector('[data-aikaipin-list-filter-form]');
    var listFilter = document.querySelector('[data-aikaipin-list-filter]');
    var listRows = Array.prototype.slice.call(document.querySelectorAll('[data-aikaipin-list-row]'));
    var listEmptyRow = document.querySelector('[data-aikaipin-list-empty-row]');

    var selectionModal = document.getElementById('aikaipin-selection-proposal-modal');
    var selectionForm = document.querySelector('[data-aikaipin-selection-form]');
    var selectionProductName = document.querySelector('[data-aikaipin-selection-product-name]');
    var selectionImageInput = document.querySelector('[data-aikaipin-selection-images]');
    var selectionImagePreviews = document.querySelector('[data-aikaipin-selection-image-previews]');
    var selectionTags = {
        specification: [],
        attribute: []
    };
    var selectionImageFiles = [];

    var placementConfig = config.placement || {};
    var placementModal = document.getElementById('aikaipin-placement-evaluation-modal');
    var placementForm = document.querySelector('[data-aikaipin-placement-form]');
    var relatedProductInput = document.querySelector('[data-aikaipin-related-product-input]');
    var relatedProductSuggestions = document.querySelector('[data-aikaipin-related-product-suggestions]');
    var placementStartDate = document.querySelector('[data-aikaipin-placement-start-date]');
    var placementEndDate = document.querySelector('[data-aikaipin-placement-end-date]');
    var placementRowsContainer = document.querySelector('[data-aikaipin-placement-data-rows]');
    var selectedRelatedProduct = null;
    var placementRows = [];

    function notify(type, title, message) {
        if (window.ErpAdminNotifier && typeof window.ErpAdminNotifier.notify === 'function') {
            window.ErpAdminNotifier.notify({
                type: type,
                title: title,
                message: message
            });
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

    function applyListFilter() {
        var keyword = String(listFilter && listFilter.value ? listFilter.value : '').trim().toLowerCase();
        var visible = 0;

        listRows.forEach(function (row) {
            var matches = !keyword || String(row.getAttribute('data-keyword') || '').indexOf(keyword) !== -1;
            row.hidden = !matches;
            if (matches) {
                visible += 1;
            }
        });

        if (listEmptyRow) {
            listEmptyRow.hidden = visible !== 0;
        }
    }

    function resetListFilter() {
        if (listFilter) {
            listFilter.value = '';
        }

        applyListFilter();
    }

    function openModal(mode) {
        var modal = mode === 'selection' ? selectionModal : placementModal;

        if (modal && window.jQuery) {
            window.jQuery(modal).modal('show');
        }
    }

    function tagInput(type) {
        return document.querySelector('[data-aikaipin-tag-input="' + type + '"]');
    }

    function tagList(type) {
        return document.querySelector('[data-aikaipin-tag-list="' + type + '"]');
    }

    function renderTags(type) {
        var list = tagList(type);

        if (!list) {
            return;
        }

        list.innerHTML = '';
        selectionTags[type].forEach(function (tagValue) {
            var tag = document.createElement('span');
            var remove = document.createElement('button');

            tag.className = 'aikaipin-submission-tag';
            tag.appendChild(document.createTextNode(tagValue));
            remove.type = 'button';
            remove.setAttribute('data-aikaipin-remove-tag', tagValue);
            remove.setAttribute('data-aikaipin-remove-tag-type', type);
            remove.setAttribute('aria-label', replaceTokens(config.selection.removeItem, {name: tagValue}));
            remove.innerHTML = '<i class="fa fa-times" aria-hidden="true"></i>';
            tag.appendChild(remove);
            list.appendChild(tag);
        });
    }

    function addTag(type) {
        var input = tagInput(type);
        var value = input ? String(input.value || '').trim() : '';

        if (!value) {
            notify('warning', config.selection.tagEmptyTitle, config.selection.tagEmptyMessage);
            return;
        }

        if (selectionTags[type].indexOf(value) === -1) {
            selectionTags[type].push(value);
            renderTags(type);
        }

        input.value = '';
        input.focus();
    }

    function releaseSelectionImageUrls() {
        selectionImageFiles.forEach(function (image) {
            if (image.url) {
                window.URL.revokeObjectURL(image.url);
            }
        });
    }

    function renderSelectionImages() {
        if (!selectionImagePreviews) {
            return;
        }

        selectionImagePreviews.innerHTML = '';
        selectionImageFiles.forEach(function (image, index) {
            var preview = document.createElement('div');
            var element = document.createElement('img');
            var remove = document.createElement('button');

            preview.className = 'aikaipin-submission-image-preview';
            element.src = image.url;
            element.alt = image.file.name;
            remove.type = 'button';
            remove.setAttribute('data-aikaipin-remove-selection-image', String(index));
            remove.setAttribute('aria-label', replaceTokens(config.selection.removeItem, {name: image.file.name}));
            remove.innerHTML = '<i class="fa fa-times" aria-hidden="true"></i>';
            preview.appendChild(element);
            preview.appendChild(remove);
            selectionImagePreviews.appendChild(preview);
        });
    }

    function addSelectionImages(files) {
        var available = Math.max(0, 3 - selectionImageFiles.length);
        var accepted = 0;
        var reachedLimit = files.length > available;

        files.forEach(function (file) {
            if (file.size > 20 * 1024 * 1024) {
                notify('warning', config.selection.imageTooLargeTitle, replaceTokens(config.selection.imageTooLargeMessage, {name: file.name}));
                return;
            }

            if (accepted >= available) {
                return;
            }

            selectionImageFiles.push({
                file: file,
                url: window.URL.createObjectURL(file)
            });
            accepted += 1;
        });

        if (reachedLimit) {
            notify('warning', config.selection.imageLimitTitle, config.selection.imageLimitMessage);
        }

        renderSelectionImages();
    }

    function resetSelectionForm() {
        if (!selectionForm) {
            return;
        }

        selectionForm.reset();
        selectionTags = {
            specification: [],
            attribute: []
        };
        releaseSelectionImageUrls();
        selectionImageFiles = [];
        renderTags('specification');
        renderTags('attribute');
        renderSelectionImages();
    }

    function defaultPlacementRow() {
        return {
            platform: 'facebook',
            currency: 'usd',
            actualSpend: '',
            orders: ''
        };
    }

    function createPlacementSelect(field, options, value, index) {
        var select = document.createElement('select');

        select.className = 'form-control';
        select.setAttribute('data-aikaipin-placement-field', field);
        select.setAttribute('data-aikaipin-placement-row-index', String(index));
        Object.keys(options || {}).forEach(function (optionValue) {
            var option = document.createElement('option');
            option.value = optionValue;
            option.textContent = options[optionValue];
            option.selected = optionValue === value;
            select.appendChild(option);
        });

        return select;
    }

    function createPlacementInput(field, value, index, placeholder, step) {
        var input = document.createElement('input');

        input.className = 'form-control';
        input.type = 'number';
        input.min = '0';
        input.step = step;
        input.value = value;
        input.placeholder = placeholder;
        input.setAttribute('data-aikaipin-placement-field', field);
        input.setAttribute('data-aikaipin-placement-row-index', String(index));

        return input;
    }

    function createPlacementField(labelText, control) {
        var column = document.createElement('div');
        var group = document.createElement('div');
        var label = document.createElement('label');

        column.className = 'col-sm-3';
        group.className = 'form-group';
        label.textContent = labelText;
        group.appendChild(label);
        group.appendChild(control);
        column.appendChild(group);

        return column;
    }

    function renderPlacementRows() {
        if (!placementRowsContainer) {
            return;
        }

        placementRowsContainer.innerHTML = '';
        placementRows.forEach(function (rowData, index) {
            var row = document.createElement('div');
            var header = document.createElement('div');
            var title = document.createElement('strong');
            var remove = document.createElement('button');
            var controls = document.createElement('div');

            row.className = 'aikaipin-placement-data-row';
            header.className = 'aikaipin-placement-data-row-header';
            title.textContent = replaceTokens(placementConfig.dataRowTitle, {number: index + 1});
            remove.type = 'button';
            remove.className = 'btn btn-link';
            remove.setAttribute('data-aikaipin-remove-placement-data', String(index));
            remove.setAttribute('aria-label', placementConfig.removeData);
            remove.innerHTML = '<i class="fa fa-trash-o" aria-hidden="true"></i>';
            remove.disabled = placementRows.length === 1;
            header.appendChild(title);
            header.appendChild(remove);

            controls.className = 'row';
            controls.appendChild(createPlacementField(
                placementConfig.platformLabel,
                createPlacementSelect('platform', placementConfig.platformOptions, rowData.platform, index)
            ));
            controls.appendChild(createPlacementField(
                placementConfig.currencyLabel,
                createPlacementSelect('currency', placementConfig.currencyOptions, rowData.currency, index)
            ));
            controls.appendChild(createPlacementField(
                placementConfig.actualSpendLabel,
                createPlacementInput('actualSpend', rowData.actualSpend, index, placementConfig.actualSpendPlaceholder, '0.01')
            ));
            controls.appendChild(createPlacementField(
                placementConfig.ordersLabel,
                createPlacementInput('orders', rowData.orders, index, placementConfig.ordersPlaceholder, '1')
            ));

            row.appendChild(header);
            row.appendChild(controls);
            placementRowsContainer.appendChild(row);
        });
    }

    function renderRelatedProductSuggestions(query) {
        if (!relatedProductSuggestions) {
            return;
        }

        var normalizedQuery = String(query || '').trim().toLowerCase();
        var products = Array.isArray(placementConfig.catalogProducts) ? placementConfig.catalogProducts : [];
        var matches = products.filter(function (product) {
            var keyword = String(product.code + ' ' + product.name).toLowerCase();
            return !normalizedQuery || keyword.indexOf(normalizedQuery) !== -1;
        }).slice(0, 8);

        relatedProductSuggestions.innerHTML = '';
        if (matches.length === 0) {
            relatedProductSuggestions.hidden = true;
            return;
        }

        matches.forEach(function (product) {
            var option = document.createElement('button');
            var name = document.createElement('strong');
            var code = document.createElement('span');

            option.type = 'button';
            option.className = 'aikaipin-related-product-option';
            option.setAttribute('data-aikaipin-related-product-choice', product.code);
            name.textContent = product.name;
            code.textContent = product.code;
            option.appendChild(name);
            option.appendChild(code);
            relatedProductSuggestions.appendChild(option);
        });
        relatedProductSuggestions.hidden = false;
    }

    function selectRelatedProduct(code) {
        var products = Array.isArray(placementConfig.catalogProducts) ? placementConfig.catalogProducts : [];
        selectedRelatedProduct = products.find(function (product) {
            return product.code === code;
        }) || null;

        if (!selectedRelatedProduct || !relatedProductInput) {
            return;
        }

        relatedProductInput.value = selectedRelatedProduct.code + ' · ' + selectedRelatedProduct.name;
        if (relatedProductSuggestions) {
            relatedProductSuggestions.hidden = true;
        }
    }

    function resetPlacementForm() {
        if (!placementForm) {
            return;
        }

        placementForm.reset();
        selectedRelatedProduct = null;
        placementRows = [defaultPlacementRow()];
        renderPlacementRows();
        if (relatedProductSuggestions) {
            relatedProductSuggestions.hidden = true;
            relatedProductSuggestions.innerHTML = '';
        }
    }

    function submitSelectionProposal() {
        if (!selectionProductName || String(selectionProductName.value || '').trim() === '') {
            notify('warning', config.selection.nameRequiredTitle, config.selection.nameRequiredMessage);
            if (selectionProductName) {
                selectionProductName.focus();
            }
            return;
        }

        notify('success', config.selection.submitTitle, config.selection.submitMessage);
    }

    function hasValidPlacementData() {
        return placementRows.some(function (row) {
            return String(row.actualSpend || '').trim() !== '' && String(row.orders || '').trim() !== '';
        });
    }

    function submitPlacementEvaluation() {
        if (!selectedRelatedProduct) {
            notify('warning', placementConfig.relatedProductRequiredTitle, placementConfig.relatedProductRequiredMessage);
            if (relatedProductInput) {
                relatedProductInput.focus();
            }
            return;
        }

        var startDate = placementStartDate ? placementStartDate.value : '';
        var endDate = placementEndDate ? placementEndDate.value : '';
        if (!startDate || !endDate) {
            notify('warning', placementConfig.dateRequiredTitle, placementConfig.dateRequiredMessage);
            return;
        }

        if (endDate < startDate) {
            notify('warning', placementConfig.dateRangeTitle, placementConfig.dateRangeMessage);
            return;
        }

        if (!hasValidPlacementData()) {
            notify('warning', placementConfig.dataRequiredTitle, placementConfig.dataRequiredMessage);
            return;
        }

        notify('success', placementConfig.submitTitle, placementConfig.submitMessage);
    }

    if (listForm) {
        listForm.addEventListener('submit', function (event) {
            event.preventDefault();
            applyListFilter();
        });

        listForm.addEventListener('reset', function () {
            window.setTimeout(resetListFilter, 0);
        });
    }

    if (listFilter) {
        listFilter.addEventListener('input', applyListFilter);
    }

    Array.prototype.slice.call(document.querySelectorAll('[data-aikaipin-tag-input]')).forEach(function (input) {
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                addTag(input.getAttribute('data-aikaipin-tag-input'));
            }
        });
    });

    if (selectionImageInput) {
        selectionImageInput.addEventListener('change', function () {
            addSelectionImages(Array.prototype.slice.call(selectionImageInput.files || []));
            selectionImageInput.value = '';
        });
    }

    if (relatedProductInput) {
        relatedProductInput.addEventListener('input', function () {
            selectedRelatedProduct = null;
            renderRelatedProductSuggestions(relatedProductInput.value);
        });

        relatedProductInput.addEventListener('focus', function () {
            renderRelatedProductSuggestions(relatedProductInput.value);
        });
    }

    if (selectionForm) {
        selectionForm.addEventListener('submit', function (event) {
            event.preventDefault();
            submitSelectionProposal();
        });
    }

    if (placementForm) {
        placementForm.addEventListener('submit', function (event) {
            event.preventDefault();
            submitPlacementEvaluation();
        });
    }

    if (selectionModal && window.jQuery) {
        window.jQuery(selectionModal).on('hidden.bs.modal', resetSelectionForm);
    }

    if (placementModal && window.jQuery) {
        window.jQuery(placementModal).on('hidden.bs.modal', resetPlacementForm);
    }

    document.addEventListener('input', function (event) {
        if (!event.target.matches('[data-aikaipin-placement-field]')) {
            return;
        }

        var index = Number(event.target.getAttribute('data-aikaipin-placement-row-index'));
        var field = event.target.getAttribute('data-aikaipin-placement-field');
        if (placementRows[index] && field) {
            placementRows[index][field] = event.target.value;
        }
    });

    document.addEventListener('change', function (event) {
        if (!event.target.matches('[data-aikaipin-placement-field]')) {
            return;
        }

        var index = Number(event.target.getAttribute('data-aikaipin-placement-row-index'));
        var field = event.target.getAttribute('data-aikaipin-placement-field');
        if (placementRows[index] && field) {
            placementRows[index][field] = event.target.value;
        }
    });

    document.addEventListener('click', function (event) {
        var openModalButton = event.target.closest('[data-aikaipin-open-modal]');
        if (openModalButton) {
            openModal(openModalButton.getAttribute('data-aikaipin-open-modal'));
            return;
        }

        var addTagButton = event.target.closest('[data-aikaipin-add-tag]');
        if (addTagButton) {
            addTag(addTagButton.getAttribute('data-aikaipin-add-tag'));
            return;
        }

        var removeTagButton = event.target.closest('[data-aikaipin-remove-tag]');
        if (removeTagButton) {
            var type = removeTagButton.getAttribute('data-aikaipin-remove-tag-type');
            var value = removeTagButton.getAttribute('data-aikaipin-remove-tag');
            if (selectionTags[type]) {
                selectionTags[type] = selectionTags[type].filter(function (tagValue) {
                    return tagValue !== value;
                });
                renderTags(type);
            }
            return;
        }

        var removeImageButton = event.target.closest('[data-aikaipin-remove-selection-image]');
        if (removeImageButton) {
            var imageIndex = Number(removeImageButton.getAttribute('data-aikaipin-remove-selection-image'));
            var removedImage = selectionImageFiles.splice(imageIndex, 1)[0];
            if (removedImage && removedImage.url) {
                window.URL.revokeObjectURL(removedImage.url);
            }
            renderSelectionImages();
            return;
        }

        var productChoice = event.target.closest('[data-aikaipin-related-product-choice]');
        if (productChoice) {
            selectRelatedProduct(productChoice.getAttribute('data-aikaipin-related-product-choice'));
            return;
        }

        var addPlacementDataButton = event.target.closest('[data-aikaipin-add-placement-data]');
        if (addPlacementDataButton) {
            placementRows.push(defaultPlacementRow());
            renderPlacementRows();
            return;
        }

        var removePlacementDataButton = event.target.closest('[data-aikaipin-remove-placement-data]');
        if (removePlacementDataButton && placementRows.length > 1) {
            placementRows.splice(Number(removePlacementDataButton.getAttribute('data-aikaipin-remove-placement-data')), 1);
            renderPlacementRows();
            return;
        }

        var viewButton = event.target.closest('[data-aikaipin-submission-view]');
        if (viewButton) {
            notify('info', config.viewTitle, config.viewMessage);
            return;
        }

        if (!event.target.closest('.aikaipin-related-product-group') && relatedProductSuggestions) {
            relatedProductSuggestions.hidden = true;
        }
    });

    renderTags('specification');
    renderTags('attribute');
    renderSelectionImages();
    placementRows = [defaultPlacementRow()];
    renderPlacementRows();
    applyListFilter();
}(window, document));
