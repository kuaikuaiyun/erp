(function (window, document) {
    'use strict';

    var config = window.ErpAikaipinSubmissionConfig || {};
    var placementConfig = config.placement || {};
    var placementModal = document.getElementById('aikaipin-placement-evaluation-modal');
    var detailModal = document.getElementById('aikaipin-placement-evaluation-detail-modal');
    var placementForm = document.querySelector('[data-aikaipin-placement-form]');
    var formTitle = document.querySelector('[data-aikaipin-placement-form-title]');
    var submitButton = document.querySelector('[data-aikaipin-placement-submit]');
    var relatedProductInput = document.querySelector('[data-aikaipin-related-product-input]');
    var relatedProductSuggestions = document.querySelector('[data-aikaipin-related-product-suggestions]');
    var placementStartDate = document.querySelector('[data-aikaipin-placement-start-date]');
    var placementEndDate = document.querySelector('[data-aikaipin-placement-end-date]');
    var placementRemark = document.querySelector('[data-aikaipin-placement-remark]');
    var placementRowsContainer = document.querySelector('[data-aikaipin-placement-data-rows]');
    var detailBody = document.querySelector('[data-aikaipin-placement-detail-body]');
    var selectedRelatedProduct = null;
    var placementRows = [];
    var editingEvaluationId = null;
    var submitting = false;
    var productSearchTimer = null;
    var productSearchSequence = 0;

    function notify(type, title, message) {
        if (window.ErpAdminNotifier && typeof window.ErpAdminNotifier.notify === 'function') {
            window.ErpAdminNotifier.notify({type: type, title: title, message: message});
        }
    }

    function replaceTokens(template, tokens) {
        var text = String(template || '');

        Object.keys(tokens).forEach(function (key) {
            text = text.replace(':' + key, String(tokens[key]));
        });

        return text;
    }

    function responsePayload(response) {
        return response.json().catch(function () {
            return {};
        }).then(function (payload) {
            if (!response.ok) {
                var errors = payload.errors || {};
                var firstField = Object.keys(errors)[0];
                var firstMessage = firstField && errors[firstField] && errors[firstField][0];
                throw new Error(firstMessage || payload.message || placementConfig.saveFailedMessage);
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

    function evaluationUrl(template, evaluationId) {
        return String(template || '').replace('__evaluation__', encodeURIComponent(evaluationId));
    }

    function defaultPlacementRow() {
        return {
            platform: 'facebook',
            currency: 'usd',
            actual_spend: '',
            orders: ''
        };
    }

    function createSelect(field, options, value, index) {
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

    function createInput(field, value, index, placeholder, step) {
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

    function createField(labelText, control) {
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
            controls.appendChild(createField(
                placementConfig.platformLabel,
                createSelect('platform', placementConfig.platformOptions, rowData.platform, index)
            ));
            controls.appendChild(createField(
                placementConfig.currencyLabel,
                createSelect('currency', placementConfig.currencyOptions, rowData.currency, index)
            ));
            controls.appendChild(createField(
                placementConfig.actualSpendLabel,
                createInput('actual_spend', rowData.actual_spend, index, placementConfig.actualSpendPlaceholder, '0.01')
            ));
            controls.appendChild(createField(
                placementConfig.ordersLabel,
                createInput('orders', rowData.orders, index, placementConfig.ordersPlaceholder, '1')
            ));
            row.appendChild(header);
            row.appendChild(controls);
            placementRowsContainer.appendChild(row);
        });
    }

    function clearProductSuggestions() {
        if (!relatedProductSuggestions) {
            return;
        }

        relatedProductSuggestions.hidden = true;
        relatedProductSuggestions.innerHTML = '';
    }

    function renderProductSuggestions(products) {
        if (!relatedProductSuggestions) {
            return;
        }

        relatedProductSuggestions.innerHTML = '';
        if (!products.length) {
            relatedProductSuggestions.hidden = true;
            return;
        }

        products.forEach(function (product) {
            var button = document.createElement('button');
            var name = document.createElement('strong');
            var code = document.createElement('span');

            button.type = 'button';
            button.className = 'aikaipin-related-product-option';
            button.setAttribute('data-aikaipin-placement-product-id', String(product.id));
            button.setAttribute('data-aikaipin-placement-product-code', product.code || '');
            button.setAttribute('data-aikaipin-placement-product-name', product.name || '');
            name.textContent = product.name || '';
            code.textContent = product.code || '';
            button.appendChild(name);
            button.appendChild(code);
            relatedProductSuggestions.appendChild(button);
        });
        relatedProductSuggestions.hidden = false;
    }

    function loadProductOptions(keyword) {
        var sequence = ++productSearchSequence;
        var separator = String(placementConfig.productOptionsUrl || '').indexOf('?') === -1 ? '?' : '&';
        var url = String(placementConfig.productOptionsUrl || '') + separator + 'keyword=' + encodeURIComponent(keyword || '');

        if (!relatedProductSuggestions) {
            return;
        }

        relatedProductSuggestions.hidden = false;
        relatedProductSuggestions.textContent = placementConfig.loadingProducts || '';
        window.fetch(url, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        }).then(responsePayload).then(function (payload) {
            if (sequence !== productSearchSequence) {
                return;
            }
            renderProductSuggestions(Array.isArray(payload.products) ? payload.products : []);
        }).catch(function (error) {
            if (sequence !== productSearchSequence) {
                return;
            }
            clearProductSuggestions();
            notify('error', placementConfig.saveFailedTitle, error.message || placementConfig.productSearchFailed);
        });
    }

    function selectRelatedProduct(product) {
        selectedRelatedProduct = {
            id: Number(product.id),
            code: product.code || '',
            name: product.name || ''
        };
        if (relatedProductInput) {
            relatedProductInput.value = replaceTokens(placementConfig.productDisplay, {
                code: selectedRelatedProduct.code,
                name: selectedRelatedProduct.name
            });
        }
        clearProductSuggestions();
    }

    function setSubmitState(isBusy) {
        if (!submitButton) {
            return;
        }

        var label = editingEvaluationId ? placementConfig.saveChangesLabel : placementConfig.saveLabel;
        submitButton.disabled = isBusy;
        submitButton.innerHTML = isBusy
            ? '<i class="fa fa-spinner fa-spin" aria-hidden="true"></i> ' + String(placementConfig.savingLabel)
            : '<i class="fa fa-check" aria-hidden="true"></i> ' + String(label);
    }

    function setMode(evaluationId) {
        editingEvaluationId = evaluationId || null;
        if (formTitle) {
            formTitle.textContent = editingEvaluationId ? placementConfig.editTitle : placementConfig.newTitle;
        }
        setSubmitState(false);
    }

    function resetPlacementForm() {
        if (!placementForm) {
            return;
        }

        placementForm.reset();
        selectedRelatedProduct = null;
        placementRows = [defaultPlacementRow()];
        editingEvaluationId = null;
        submitting = false;
        clearProductSuggestions();
        renderPlacementRows();
        setMode(null);
    }

    function openNewEvaluation() {
        resetPlacementForm();
        if (placementModal && window.jQuery) {
            window.jQuery(placementModal).modal('show');
        }
    }

    function loadEvaluation(evaluationId) {
        return jsonRequest(evaluationUrl(placementConfig.showUrlTemplate, evaluationId), 'GET');
    }

    function openEditEvaluation(evaluationId) {
        loadEvaluation(evaluationId).then(function (evaluation) {
            if (!evaluation.can_edit) {
                throw new Error(placementConfig.saveFailedMessage);
            }

            resetPlacementForm();
            setMode(Number(evaluation.id));
            selectRelatedProduct(evaluation.product || {});
            if (placementStartDate) {
                placementStartDate.value = evaluation.test_started_on || '';
            }
            if (placementEndDate) {
                placementEndDate.value = evaluation.data_ended_on || '';
            }
            if (placementRemark) {
                placementRemark.value = evaluation.remark || '';
            }
            placementRows = Array.isArray(evaluation.records) && evaluation.records.length
                ? evaluation.records.map(function (record) {
                    return {
                        platform: record.platform || 'facebook',
                        currency: record.currency || 'usd',
                        actual_spend: record.actual_spend || '',
                        orders: record.orders === 0 ? '0' : (record.orders || '')
                    };
                })
                : [defaultPlacementRow()];
            renderPlacementRows();
            if (placementModal && window.jQuery) {
                window.jQuery(placementModal).modal('show');
            }
        }).catch(function (error) {
            notify('error', placementConfig.saveFailedTitle, error.message || placementConfig.saveFailedMessage);
        });
    }

    function detailField(label, value) {
        var field = document.createElement('div');
        var labelElement = document.createElement('dt');
        var valueElement = document.createElement('dd');

        field.className = 'aikaipin-submission-detail-field';
        labelElement.textContent = label;
        valueElement.textContent = value || placementConfig.detailLabels.noValue;
        field.appendChild(labelElement);
        field.appendChild(valueElement);

        return field;
    }

    function formatDateTime(value) {
        if (!value) {
            return '';
        }

        var date = new Date(value);
        return isNaN(date.getTime()) ? value : date.toLocaleString();
    }

    function formatRecordSummary(record) {
        return replaceTokens(placementConfig.detailLabels.recordSummary, {
            platform: (placementConfig.platformOptions || {})[record.platform] || record.platform,
            currency: (placementConfig.currencyOptions || {})[record.currency] || record.currency,
            spend: record.actual_spend,
            orders: record.orders
        });
    }

    function showEvaluationDetail(evaluationId) {
        loadEvaluation(evaluationId).then(function (evaluation) {
            var labels = placementConfig.detailLabels || {};
            var grid = document.createElement('dl');
            var remarkField;
            var recordsSection = document.createElement('section');
            var recordsTitle = document.createElement('h5');

            grid.className = 'aikaipin-submission-detail-grid';
            grid.appendChild(detailField(labels.evaluationCode, evaluation.evaluation_code));
            grid.appendChild(detailField(labels.relatedProduct, replaceTokens(placementConfig.productDisplay, {
                code: (evaluation.product || {}).code || '',
                name: (evaluation.product || {}).name || ''
            })));
            grid.appendChild(detailField(labels.testPeriod, replaceTokens(placementConfig.dateRangeDisplay, {
                start: evaluation.test_started_on || '',
                end: evaluation.data_ended_on || ''
            })));
            grid.appendChild(detailField(labels.submitter, evaluation.created_by_name));
            grid.appendChild(detailField(labels.createdAt, formatDateTime(evaluation.created_at)));
            remarkField = detailField(labels.remark, evaluation.remark);
            remarkField.classList.add('aikaipin-submission-detail-wide');
            grid.appendChild(remarkField);

            recordsSection.className = 'aikaipin-submission-detail-records';
            recordsTitle.textContent = labels.records;
            recordsSection.appendChild(recordsTitle);
            if (Array.isArray(evaluation.records) && evaluation.records.length) {
                var recordList = document.createElement('div');
                recordList.className = 'aikaipin-submission-detail-record-list';
                evaluation.records.forEach(function (record) {
                    var item = document.createElement('div');
                    item.textContent = formatRecordSummary(record);
                    recordList.appendChild(item);
                });
                recordsSection.appendChild(recordList);
            } else {
                var noRecords = document.createElement('p');
                noRecords.textContent = labels.noValue;
                recordsSection.appendChild(noRecords);
            }

            detailBody.innerHTML = '';
            detailBody.appendChild(grid);
            detailBody.appendChild(recordsSection);
            if (detailModal && window.jQuery) {
                window.jQuery(detailModal).modal('show');
            }
        }).catch(function (error) {
            notify('error', placementConfig.saveFailedTitle, error.message || placementConfig.saveFailedMessage);
        });
    }

    function validPlacementRecords() {
        return placementRows.length > 0 && placementRows.every(function (row) {
            var spend = String(row.actual_spend || '').trim();
            var orders = String(row.orders || '').trim();

            return spend !== '' && orders !== ''
                && isFinite(Number(spend)) && Number(spend) >= 0
                && isFinite(Number(orders)) && Number(orders) >= 0 && Number(orders) % 1 === 0;
        });
    }

    function submitEvaluation() {
        var startDate = placementStartDate ? placementStartDate.value : '';
        var endDate = placementEndDate ? placementEndDate.value : '';
        var isEditing = editingEvaluationId !== null;
        var url;

        if (submitting) {
            return;
        }
        if (!selectedRelatedProduct || !selectedRelatedProduct.id) {
            notify('warning', placementConfig.relatedProductRequiredTitle, placementConfig.relatedProductRequiredMessage);
            if (relatedProductInput) {
                relatedProductInput.focus();
            }
            return;
        }
        if (!startDate || !endDate) {
            notify('warning', placementConfig.dateRequiredTitle, placementConfig.dateRequiredMessage);
            return;
        }
        if (endDate < startDate) {
            notify('warning', placementConfig.dateRangeTitle, placementConfig.dateRangeMessage);
            return;
        }
        if (!validPlacementRecords()) {
            notify('warning', placementConfig.dataRequiredTitle, placementConfig.dataRequiredMessage);
            return;
        }

        submitting = true;
        setSubmitState(true);
        url = isEditing ? evaluationUrl(placementConfig.updateUrlTemplate, editingEvaluationId) : placementConfig.storeUrl;
        jsonRequest(url, isEditing ? 'PUT' : 'POST', {
            product_id: selectedRelatedProduct.id,
            test_started_on: startDate,
            data_ended_on: endDate,
            remark: placementRemark ? placementRemark.value : '',
            records: placementRows
        }).then(function (payload) {
            notify(
                'success',
                isEditing ? placementConfig.updatedTitle : placementConfig.createdTitle,
                payload.message || (isEditing ? placementConfig.updatedMessage : placementConfig.createdMessage)
            );
            if (placementModal && window.jQuery) {
                window.jQuery(placementModal).modal('hide');
            }
            window.setTimeout(function () {
                window.location.assign(payload.redirect_url || window.location.href);
            }, 350);
        }).catch(function (error) {
            submitting = false;
            setSubmitState(false);
            notify('error', placementConfig.saveFailedTitle, error.message || placementConfig.saveFailedMessage);
        });
    }

    function deleteEvaluation(evaluationId) {
        if (!window.confirm(placementConfig.deleteMessage || '')) {
            return;
        }

        jsonRequest(evaluationUrl(placementConfig.destroyUrlTemplate, evaluationId), 'DELETE').then(function (payload) {
            notify('success', placementConfig.deletedTitle, payload.message || placementConfig.deletedMessage);
            window.setTimeout(function () {
                window.location.assign(payload.redirect_url || window.location.href);
            }, 250);
        }).catch(function (error) {
            notify('error', placementConfig.deleteFailedTitle, error.message || placementConfig.saveFailedMessage);
        });
    }

    if (relatedProductInput) {
        relatedProductInput.addEventListener('input', function () {
            selectedRelatedProduct = null;
            window.clearTimeout(productSearchTimer);
            productSearchTimer = window.setTimeout(function () {
                loadProductOptions(relatedProductInput.value);
            }, 180);
        });

        relatedProductInput.addEventListener('focus', function () {
            loadProductOptions(relatedProductInput.value);
        });
    }

    if (placementForm) {
        placementForm.addEventListener('submit', function (event) {
            event.preventDefault();
            event.stopImmediatePropagation();
            event.stopPropagation();
            submitEvaluation();
        }, true);
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
        var openButton = event.target.closest('[data-aikaipin-open-placement-modal]');
        if (openButton) {
            openNewEvaluation();
            return;
        }

        var productButton = event.target.closest('[data-aikaipin-placement-product-id]');
        if (productButton) {
            selectRelatedProduct({
                id: productButton.getAttribute('data-aikaipin-placement-product-id'),
                code: productButton.getAttribute('data-aikaipin-placement-product-code'),
                name: productButton.getAttribute('data-aikaipin-placement-product-name')
            });
            return;
        }

        var addDataButton = event.target.closest('[data-aikaipin-add-placement-data]');
        if (addDataButton) {
            placementRows.push(defaultPlacementRow());
            renderPlacementRows();
            return;
        }

        var removeDataButton = event.target.closest('[data-aikaipin-remove-placement-data]');
        if (removeDataButton && placementRows.length > 1) {
            placementRows.splice(Number(removeDataButton.getAttribute('data-aikaipin-remove-placement-data')), 1);
            renderPlacementRows();
            return;
        }

        var submit = event.target.closest('[data-aikaipin-placement-submit]');
        if (submit) {
            event.preventDefault();
            submitEvaluation();
            return;
        }

        var viewButton = event.target.closest('[data-aikaipin-placement-view]');
        if (viewButton) {
            showEvaluationDetail(Number(viewButton.getAttribute('data-aikaipin-evaluation-id')));
            return;
        }

        var editButton = event.target.closest('[data-aikaipin-placement-edit]');
        if (editButton) {
            openEditEvaluation(Number(editButton.getAttribute('data-aikaipin-evaluation-id')));
            return;
        }

        var deleteButton = event.target.closest('[data-aikaipin-placement-delete]');
        if (deleteButton) {
            deleteEvaluation(Number(deleteButton.getAttribute('data-aikaipin-evaluation-id')));
        }
    });

    resetPlacementForm();
}(window, document));
