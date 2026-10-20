(function (window, document) {
    'use strict';

    var config = window.ErpAikaipinSubmissionConfig || {};
    var selectionConfig = config.selection || {};
    var selectionModal = document.getElementById('aikaipin-selection-proposal-modal');
    var detailModal = document.getElementById('aikaipin-selection-proposal-detail-modal');
    var selectionForm = document.querySelector('[data-aikaipin-selection-form]');
    var formTitle = document.querySelector('[data-aikaipin-selection-form-title]');
    var submitButton = document.querySelector('[data-aikaipin-selection-submit]');
    var productName = document.querySelector('[data-aikaipin-selection-product-name]');
    var link1688 = document.querySelector('[data-aikaipin-selection-1688-url]');
    var competitorUrl = document.querySelector('[data-aikaipin-selection-competitor-url]');
    var remark = document.querySelector('[data-aikaipin-selection-remark]');
    var imageInput = document.querySelector('[data-aikaipin-selection-images]');
    var imagePreviews = document.querySelector('[data-aikaipin-selection-image-previews]');
    var detailBody = document.querySelector('[data-aikaipin-selection-detail-body]');
    var tags = {specification: [], attribute: []};
    var newImages = [];
    var existingImages = [];
    var removedImageIds = [];
    var editingProposalId = null;
    var submitting = false;

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
                throw new Error(firstMessage || payload.message || selectionConfig.saveFailedMessage);
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

    function proposalUrl(template, proposalId) {
        return String(template || '').replace('__proposal__', encodeURIComponent(proposalId));
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
        tags[type].forEach(function (value) {
            var tag = document.createElement('span');
            var remove = document.createElement('button');

            tag.className = 'aikaipin-submission-tag';
            tag.appendChild(document.createTextNode(value));
            remove.type = 'button';
            remove.setAttribute('data-aikaipin-remove-tag', value);
            remove.setAttribute('data-aikaipin-remove-tag-type', type);
            remove.setAttribute('aria-label', replaceTokens(selectionConfig.removeItem, {name: value}));
            remove.innerHTML = '<i class="fa fa-times" aria-hidden="true"></i>';
            tag.appendChild(remove);
            list.appendChild(tag);
        });
    }

    function addTag(type) {
        var input = tagInput(type);
        var value = input ? String(input.value || '').trim() : '';

        if (!value) {
            notify('warning', selectionConfig.tagEmptyTitle, selectionConfig.tagEmptyMessage);
            return;
        }

        if (tags[type].indexOf(value) === -1) {
            tags[type].push(value);
            renderTags(type);
        }

        input.value = '';
        input.focus();
    }

    function releaseNewImageUrls() {
        newImages.forEach(function (image) {
            if (image.url) {
                window.URL.revokeObjectURL(image.url);
            }
        });
    }

    function appendImagePreview(image, attribute, value) {
        var preview = document.createElement('div');
        var element = document.createElement('img');
        var remove = document.createElement('button');

        preview.className = 'aikaipin-submission-image-preview';
        element.src = image.url;
        element.alt = image.name || (image.file && image.file.name) || '';
        remove.type = 'button';
        remove.setAttribute(attribute, String(value));
        remove.setAttribute('aria-label', replaceTokens(selectionConfig.removeItem, {name: element.alt}));
        remove.innerHTML = '<i class="fa fa-times" aria-hidden="true"></i>';
        preview.appendChild(element);
        preview.appendChild(remove);
        imagePreviews.appendChild(preview);
    }

    function renderImages() {
        if (!imagePreviews) {
            return;
        }

        imagePreviews.innerHTML = '';
        existingImages.forEach(function (image) {
            appendImagePreview(image, 'data-aikaipin-remove-existing-selection-image', image.id);
        });
        newImages.forEach(function (image, index) {
            appendImagePreview(image, 'data-aikaipin-remove-selection-image', index);
        });
    }

    function addImages(files) {
        var available = Math.max(0, 3 - existingImages.length - newImages.length);
        var accepted = 0;
        var reachedLimit = files.length > available;

        files.forEach(function (file) {
            if (file.size > 20 * 1024 * 1024) {
                notify('warning', selectionConfig.imageTooLargeTitle, replaceTokens(selectionConfig.imageTooLargeMessage, {name: file.name}));
                return;
            }
            if (accepted >= available) {
                return;
            }

            newImages.push({file: file, url: window.URL.createObjectURL(file), name: file.name});
            accepted += 1;
        });

        if (reachedLimit) {
            notify('warning', selectionConfig.imageLimitTitle, selectionConfig.imageLimitMessage);
        }

        renderImages();
    }

    function setSubmitState(isBusy) {
        if (!submitButton) {
            return;
        }

        var label = editingProposalId ? selectionConfig.saveChangesLabel : selectionConfig.saveLabel;
        submitButton.disabled = isBusy;
        submitButton.innerHTML = isBusy
            ? '<i class="fa fa-spinner fa-spin" aria-hidden="true"></i> ' + String(selectionConfig.savingLabel)
            : '<i class="fa fa-check" aria-hidden="true"></i> ' + String(label);
    }

    function setMode(proposalId) {
        editingProposalId = proposalId || null;
        if (formTitle) {
            formTitle.textContent = editingProposalId ? selectionConfig.editTitle : selectionConfig.newTitle;
        }
        setSubmitState(false);
    }

    function resetSelectionForm() {
        if (!selectionForm) {
            return;
        }

        selectionForm.reset();
        releaseNewImageUrls();
        tags = {specification: [], attribute: []};
        newImages = [];
        existingImages = [];
        removedImageIds = [];
        submitting = false;
        renderTags('specification');
        renderTags('attribute');
        renderImages();
        setMode(null);
    }

    function openNewProposal() {
        resetSelectionForm();
        if (selectionModal && window.jQuery) {
            window.jQuery(selectionModal).modal('show');
        }
    }

    function loadProposal(proposalId) {
        return jsonRequest(proposalUrl(selectionConfig.showUrlTemplate, proposalId), 'GET');
    }

    function openEditProposal(proposalId) {
        loadProposal(proposalId).then(function (proposal) {
            if (!proposal.can_edit) {
                throw new Error(selectionConfig.saveFailedMessage);
            }

            resetSelectionForm();
            setMode(Number(proposal.id));
            productName.value = proposal.product_name || '';
            link1688.value = proposal.link_1688 || '';
            competitorUrl.value = proposal.competitor_url || '';
            remark.value = proposal.remark || '';
            tags.specification = Array.isArray(proposal.specifications) ? proposal.specifications.slice() : [];
            tags.attribute = Array.isArray(proposal.attributes) ? proposal.attributes.slice() : [];
            existingImages = Array.isArray(proposal.images) ? proposal.images.slice() : [];
            renderTags('specification');
            renderTags('attribute');
            renderImages();

            if (selectionModal && window.jQuery) {
                window.jQuery(selectionModal).modal('show');
            }
        }).catch(function (error) {
            notify('error', selectionConfig.saveFailedTitle, error.message || selectionConfig.saveFailedMessage);
        });
    }

    function createDetailField(label, value) {
        var field = document.createElement('div');
        var labelElement = document.createElement('dt');
        var valueElement = document.createElement('dd');

        field.className = 'aikaipin-submission-detail-field';
        labelElement.textContent = label;
        valueElement.textContent = value || selectionConfig.detailLabels.noValue;
        field.appendChild(labelElement);
        field.appendChild(valueElement);

        return field;
    }

    function createDetailLink(label, url) {
        var field = document.createElement('div');
        var labelElement = document.createElement('dt');
        var valueElement = document.createElement('dd');

        field.className = 'aikaipin-submission-detail-field';
        labelElement.textContent = label;
        if (url) {
            var link = document.createElement('a');
            link.href = url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = url;
            valueElement.appendChild(link);
        } else {
            valueElement.textContent = selectionConfig.detailLabels.noValue;
        }
        field.appendChild(labelElement);
        field.appendChild(valueElement);

        return field;
    }

    function createDetailTags(label, values, style) {
        var field = document.createElement('div');
        var labelElement = document.createElement('dt');
        var valueElement = document.createElement('dd');

        field.className = 'aikaipin-submission-detail-field';
        labelElement.textContent = label;
        valueElement.className = 'aikaipin-submission-labels';
        if (!values || values.length === 0) {
            valueElement.textContent = selectionConfig.detailLabels.noValue;
        } else {
            values.forEach(function (value) {
                var tag = document.createElement('span');
                tag.className = 'label ' + style;
                tag.textContent = value;
                valueElement.appendChild(tag);
            });
        }
        field.appendChild(labelElement);
        field.appendChild(valueElement);

        return field;
    }

    function formatDate(value) {
        if (!value) {
            return '';
        }

        var date = new Date(value);
        return isNaN(date.getTime()) ? value : date.toLocaleString();
    }

    function showProposalDetail(proposalId) {
        loadProposal(proposalId).then(function (proposal) {
            var labels = selectionConfig.detailLabels || {};
            var grid = document.createElement('dl');
            var remarkField;
            var imageSection;

            grid.className = 'aikaipin-submission-detail-grid';
            grid.appendChild(createDetailField(labels.proposalCode, proposal.proposal_code));
            grid.appendChild(createDetailField(labels.productName, proposal.product_name));
            grid.appendChild(createDetailField(labels.creator, proposal.created_by_name));
            grid.appendChild(createDetailField(labels.createdAt, formatDate(proposal.created_at)));
            if (proposal.withdrawn_at) {
                grid.appendChild(createDetailField(labels.withdrawnAt, formatDate(proposal.withdrawn_at)));
            }
            grid.appendChild(createDetailTags(labels.specifications, proposal.specifications, 'label-default'));
            grid.appendChild(createDetailTags(labels.attributes, proposal.attributes, 'label-info'));
            grid.appendChild(createDetailLink(labels.link1688, proposal.link_1688));
            grid.appendChild(createDetailLink(labels.competitorLink, proposal.competitor_url));
            remarkField = createDetailField(labels.remark, proposal.remark);
            remarkField.classList.add('aikaipin-submission-detail-wide');
            grid.appendChild(remarkField);

            imageSection = document.createElement('section');
            imageSection.className = 'aikaipin-submission-detail-images';
            var imageTitle = document.createElement('h5');
            imageTitle.textContent = labels.images;
            imageSection.appendChild(imageTitle);
            if (proposal.images && proposal.images.length) {
                var imageGrid = document.createElement('div');
                imageGrid.className = 'aikaipin-submission-detail-image-grid';
                proposal.images.forEach(function (image) {
                    var imageLink = document.createElement('a');
                    var element = document.createElement('img');
                    imageLink.href = image.url;
                    imageLink.target = '_blank';
                    imageLink.rel = 'noopener noreferrer';
                    element.src = image.url;
                    element.alt = image.name;
                    imageLink.appendChild(element);
                    imageGrid.appendChild(imageLink);
                });
                imageSection.appendChild(imageGrid);
            } else {
                var noImage = document.createElement('p');
                noImage.textContent = labels.noValue;
                imageSection.appendChild(noImage);
            }

            detailBody.innerHTML = '';
            detailBody.appendChild(grid);
            detailBody.appendChild(imageSection);
            if (detailModal && window.jQuery) {
                window.jQuery(detailModal).modal('show');
            }
        }).catch(function (error) {
            notify('error', selectionConfig.saveFailedTitle, error.message || selectionConfig.saveFailedMessage);
        });
    }

    function submitProposal() {
        var isEditing = editingProposalId !== null;
        var finalImageCount = existingImages.length + newImages.length;
        var formData;
        var url;

        if (submitting) {
            return;
        }
        if (!productName || String(productName.value || '').trim() === '') {
            notify('warning', selectionConfig.nameRequiredTitle, selectionConfig.nameRequiredMessage);
            productName.focus();
            setSubmitState(false);
            return;
        }
        if (finalImageCount === 0) {
            notify('warning', selectionConfig.imageRequiredTitle, selectionConfig.imageRequiredMessage || selectionConfig.imageLimitMessage);
            setSubmitState(false);
            return;
        }

        submitting = true;
        setSubmitState(true);
        formData = new FormData();
        formData.append('product_name', String(productName.value || '').trim());
        formData.append('link_1688', link1688 ? link1688.value : '');
        formData.append('competitor_url', competitorUrl ? competitorUrl.value : '');
        formData.append('remark', remark ? remark.value : '');
        tags.specification.forEach(function (value) {
            formData.append('specifications[]', value);
        });
        tags.attribute.forEach(function (value) {
            formData.append('attributes[]', value);
        });
        newImages.forEach(function (image) {
            formData.append('images[]', image.file, image.file.name);
        });
        removedImageIds.forEach(function (id) {
            formData.append('remove_image_ids[]', String(id));
        });
        url = isEditing ? proposalUrl(selectionConfig.updateUrlTemplate, editingProposalId) : selectionConfig.storeUrl;
        if (isEditing) {
            formData.append('_method', 'PUT');
        }

        formDataRequest(url, formData).then(function (payload) {
            notify(
                'success',
                isEditing ? selectionConfig.updatedTitle : selectionConfig.createdTitle,
                payload.message || (isEditing ? selectionConfig.updatedMessage : selectionConfig.createdMessage)
            );
            if (selectionModal && window.jQuery) {
                window.jQuery(selectionModal).modal('hide');
            }
            window.setTimeout(function () {
                window.location.assign(payload.redirect_url || window.location.href);
            }, 350);
        }).catch(function (error) {
            submitting = false;
            setSubmitState(false);
            notify('error', selectionConfig.saveFailedTitle, error.message || selectionConfig.saveFailedMessage);
        });
    }

    function withdrawProposal(proposalId) {
        if (!window.confirm(selectionConfig.withdrawMessage || '')) {
            return;
        }

        jsonRequest(proposalUrl(selectionConfig.withdrawUrlTemplate, proposalId), 'POST').then(function (payload) {
            notify('success', selectionConfig.withdrawnTitle, payload.message || selectionConfig.withdrawnMessage);
            window.setTimeout(function () {
                window.location.assign(payload.redirect_url || window.location.href);
            }, 250);
        }).catch(function (error) {
            notify('error', selectionConfig.saveFailedTitle, error.message || selectionConfig.saveFailedMessage);
        });
    }

    Array.prototype.slice.call(document.querySelectorAll('[data-aikaipin-tag-input]')).forEach(function (input) {
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                addTag(input.getAttribute('data-aikaipin-tag-input'));
            }
        });
    });

    if (imageInput) {
        imageInput.addEventListener('change', function () {
            addImages(Array.prototype.slice.call(imageInput.files || []));
            imageInput.value = '';
        });
    }

    if (selectionForm) {
        selectionForm.addEventListener('submit', function (event) {
            event.preventDefault();
            event.stopImmediatePropagation();
            event.stopPropagation();
            submitProposal();
        }, true);
    }

    if (selectionModal && window.jQuery) {
        window.jQuery(selectionModal).on('hidden.bs.modal', resetSelectionForm);
    }

    document.addEventListener('click', function (event) {
        var openButton = event.target.closest('[data-aikaipin-open-modal="selection"]');
        if (openButton) {
            openNewProposal();
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
            if (tags[type]) {
                tags[type] = tags[type].filter(function (item) { return item !== value; });
                renderTags(type);
            }
            return;
        }

        var removeNewImageButton = event.target.closest('[data-aikaipin-remove-selection-image]');
        if (removeNewImageButton) {
            var newImage = newImages.splice(Number(removeNewImageButton.getAttribute('data-aikaipin-remove-selection-image')), 1)[0];
            if (newImage && newImage.url) {
                window.URL.revokeObjectURL(newImage.url);
            }
            renderImages();
            return;
        }

        var removeExistingImageButton = event.target.closest('[data-aikaipin-remove-existing-selection-image]');
        if (removeExistingImageButton) {
            var imageId = Number(removeExistingImageButton.getAttribute('data-aikaipin-remove-existing-selection-image'));
            existingImages = existingImages.filter(function (image) { return Number(image.id) !== imageId; });
            if (removedImageIds.indexOf(imageId) === -1) {
                removedImageIds.push(imageId);
            }
            renderImages();
            return;
        }

        var submit = event.target.closest('[data-aikaipin-selection-submit]');
        if (submit) {
            event.preventDefault();
            submitProposal();
            return;
        }

        var viewButton = event.target.closest('[data-aikaipin-selection-view]');
        if (viewButton) {
            showProposalDetail(Number(viewButton.getAttribute('data-aikaipin-proposal-id')));
            return;
        }

        var editButton = event.target.closest('[data-aikaipin-selection-edit]');
        if (editButton) {
            openEditProposal(Number(editButton.getAttribute('data-aikaipin-proposal-id')));
            return;
        }

        var withdrawButton = event.target.closest('[data-aikaipin-selection-withdraw]');
        if (withdrawButton) {
            withdrawProposal(Number(withdrawButton.getAttribute('data-aikaipin-proposal-id')));
        }
    });

    renderTags('specification');
    renderTags('attribute');
    renderImages();
}(window, document));
