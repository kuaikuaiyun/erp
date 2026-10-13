(function ($) {
    'use strict';

    var modal = $('#assistant-domain-store-editor-modal');
    var body = $('#assistant-domain-store-editor-body');
    var title = $('#assistant-domain-store-editor-title');
    var translations = window.ErpAssistantDomainStoreModalTranslations || {};
    var state = {
        dirty: false,
        saving: false,
        allowClose: false,
        currentUrl: '',
        request: null,
        requestVersion: 0,
    };

    if (!modal.length || !body.length) {
        return;
    }

    function text(key) {
        return translations[key] || '';
    }

    function escapeHtml(value) {
        return $('<div>').text(String(value || '')).html();
    }

    function notify(type, message) {
        if (window.ErpAdminNotifier && typeof window.ErpAdminNotifier.notify === 'function') {
            window.ErpAdminNotifier.notify({
                type: type,
                title: type === 'success' ? text('successTitle') : text('errorTitle'),
                message: message,
            });
            return;
        }

        window.alert(message);
    }

    function modalRequestUrl(url) {
        return url + (url.indexOf('?') === -1 ? '?' : '&') + 'modal=1';
    }

    function showLoading() {
        body.html('<div class="assistant-modal-loading"><i class="fa fa-spinner fa-spin"></i>&nbsp; ' + escapeHtml(text('loading')) + '</div>');
    }

    function clearForm() {
        state.requestVersion += 1;

        if (state.request && state.request.readyState !== 4) {
            state.request.abort();
        }

        state.request = null;
        state.currentUrl = '';
        state.dirty = false;
        state.saving = false;
        body.empty();
        modal.removeClass('is-saving');
    }

    function mayDiscardChanges() {
        return !state.dirty || window.confirm(text('discardChanges'));
    }

    function requestClose() {
        if (state.saving || !mayDiscardChanges()) {
            return;
        }

        state.allowClose = true;
        modal.modal('hide');
    }

    function markDirty() {
        if (!state.saving) {
            state.dirty = true;
        }
    }

    function setButtonChoice(group, input, value, required) {
        input.val(value);
        group.find('.assistant-option-choice').each(function () {
            var choice = $(this);
            var selected = String(choice.attr('data-option-value')) === String(value) && (required || value !== '');

            choice.toggleClass('is-selected', selected).attr('aria-pressed', selected ? 'true' : 'false');
        });
    }

    function initializeOptionGroups(root) {
        root.find('[data-option-group]').each(function () {
            var group = $(this);
            var input = group.find('input[type="hidden"]').first();
            var required = String(group.attr('data-required')) === 'true';

            group.off('click.assistantModalOption', '.assistant-option-choice')
                .on('click.assistantModalOption', '.assistant-option-choice', function () {
                    var choice = $(this);
                    var value = String(choice.attr('data-option-value'));
                    var next = !required && choice.hasClass('is-selected') ? '' : value;

                    setButtonChoice(group, input, next, required);
                    markDirty();
                });

            setButtonChoice(group, input, String(input.val() || ''), required);
        });

        root.find('[data-domain-check-option-group]').each(function () {
            var group = $(this);
            var input = group.find('input[type="hidden"]').first();
            var displayStatus = String(group.attr('data-display-status') || input.val() || '');

            function sync() {
                group.find('.assistant-option-choice').each(function () {
                    var choice = $(this);
                    var selected = String(choice.attr('data-option-value')) === displayStatus;

                    choice.toggleClass('is-selected', selected).attr('aria-pressed', selected ? 'true' : 'false');
                });
            }

            group.off('click.assistantModalDomainCheck', '.assistant-option-choice')
                .on('click.assistantModalDomainCheck', '.assistant-option-choice', function () {
                    displayStatus = String($(this).attr('data-option-value'));
                    input.val(displayStatus);
                    sync();
                    markDirty();
                });

            sync();
        });
    }

    function initializeTagEditors(root) {
        var tagTranslations = window.ErpAssistantDomainStoreTagTranslations || {};

        function translate(key, replacements) {
            var value = tagTranslations[key] || key;

            Object.keys(replacements || {}).forEach(function (name) {
                value = value.split('__' + name + '__').join(String(replacements[name]));
            });

            return value;
        }

        root.find('[data-tag-input-group]').each(function () {
            var group = $(this);
            var editor = group.find('[data-tag-editor]').first();
            var input = editor.find('.assistant-tag-entry').first();
            var fieldName = String(editor.attr('data-field-name') || '');

            function normalize(value) {
                return $.trim(String(value || ''));
            }

            function hasValue(value) {
                return editor.find('.assistant-tag-chip').filter(function () {
                    return String($(this).attr('data-tag-value')) === value;
                }).length > 0;
            }

            function syncPresets() {
                group.find('.assistant-tag-preset').each(function () {
                    var preset = $(this);
                    var selected = hasValue(normalize(preset.attr('data-tag-value')));

                    preset.toggleClass('is-selected', selected).attr('aria-pressed', selected ? 'true' : 'false');
                });
            }

            function addValue(rawValue) {
                var value = normalize(rawValue);

                if (!value || hasValue(value)) {
                    return false;
                }

                var chip = $('<span>', { class: 'assistant-tag-chip', 'data-tag-value': value });
                $('<span>', { class: 'assistant-tag-chip-label', text: value }).appendTo(chip);
                $('<input>', { type: 'hidden', name: fieldName, value: value }).appendTo(chip);
                $('<button>', {
                    type: 'button',
                    class: 'assistant-tag-remove',
                    title: translate('remove'),
                    'aria-label': translate('removeTag', { name: value }),
                }).append($('<i>', { class: 'fa fa-times' })).appendTo(chip);
                chip.insertBefore(input);

                return true;
            }

            function addValues(rawValue) {
                var changed = false;

                String(rawValue || '').split(/[,，;；\r\n]+/).forEach(function (value) {
                    changed = addValue(value) || changed;
                });
                input.val('');
                syncPresets();

                if (changed) {
                    markDirty();
                }
            }

            editor.off('.assistantModalTag')
                .on('click.assistantModalTag', function (event) {
                    if (!$(event.target).closest('.assistant-tag-chip').length) {
                        input.trigger('focus');
                    }
                });
            input.off('.assistantModalTag')
                .on('keydown.assistantModalTag', function (event) {
                    if (event.key === 'Enter' || [',', '，', ';', '；'].indexOf(event.key) !== -1) {
                        event.preventDefault();
                        addValues(input.val());
                    }
                })
                .on('blur.assistantModalTag', function () {
                    addValues(input.val());
                })
                .on('paste.assistantModalTag', function (event) {
                    var clipboard = event.originalEvent.clipboardData;
                    var pasted = clipboard ? clipboard.getData('text') : '';

                    if (/[,，;；\r\n]/.test(pasted)) {
                        event.preventDefault();
                        addValues(pasted);
                    }
                });
            group.off('.assistantModalTag', '.assistant-tag-remove')
                .on('click.assistantModalTag', '.assistant-tag-remove', function () {
                    $(this).closest('.assistant-tag-chip').remove();
                    syncPresets();
                    markDirty();
                    input.trigger('focus');
                })
                .on('click.assistantModalTag', '.assistant-tag-preset', function () {
                    var value = normalize($(this).attr('data-tag-value'));

                    if (hasValue(value)) {
                        editor.find('.assistant-tag-chip').filter(function () {
                            return String($(this).attr('data-tag-value')) === value;
                        }).remove();
                    } else if (!addValue(value)) {
                        return;
                    }

                    syncPresets();
                    markDirty();
                });
            syncPresets();
        });
    }

    function initializeSingleOptionGroups(root) {
        root.find('[data-single-option-group]').each(function () {
            var group = $(this);
            var input = group.find('input.form-control').first();

            function syncChoices() {
                var currentValue = $.trim(String(input.val() || ''));

                group.find('.assistant-single-option-choice').each(function () {
                    var choice = $(this);
                    var selected = String(choice.attr('data-option-value')) === currentValue && currentValue !== '';

                    choice.toggleClass('is-selected', selected).attr('aria-pressed', selected ? 'true' : 'false');
                });
            }

            group.off('click.assistantModalSingle', '.assistant-single-option-choice')
                .on('click.assistantModalSingle', '.assistant-single-option-choice', function () {
                    input.val(String($(this).attr('data-option-value') || '')).trigger('input').trigger('change').trigger('focus');
                    markDirty();
                });
            input.off('input.assistantModalSingle change.assistantModalSingle')
                .on('input.assistantModalSingle change.assistantModalSingle', syncChoices);
            syncChoices();
        });
    }

    function initializeShopifyAndEmail(root) {
        var siteBuilder = root.find('#site_builder');
        var shopifySection = root.find('#assistant-shopify-app-section');
        var domain = root.find('#domain');
        var email = root.find('#email');
        var lastAutoValue = null;

        function syncShopify() {
            shopifySection.prop('hidden', $.trim(String(siteBuilder.val() || '')).toLowerCase() !== 'shopify');
        }

        function hostnameFromInput(rawValue) {
            var value = $.trim(String(rawValue || ''));

            if (!value) {
                return '';
            }

            try {
                return new URL(/^[a-z][a-z0-9+.-]*:\/\//i.test(value) ? value : 'https://' + value).hostname;
            } catch (error) {
                return '';
            }
        }

        function suggestEmail() {
            var hostname = hostnameFromInput(domain.val());
            var currentValue = $.trim(String(email.val() || ''));

            if (!hostname) {
                return;
            }

            var suggestedValue = 'support@' + hostname;

            if (currentValue === '' || (lastAutoValue !== null && currentValue === lastAutoValue)) {
                email.val(suggestedValue);
                lastAutoValue = suggestedValue;
            }
        }

        siteBuilder.off('input.assistantModalShopify change.assistantModalShopify')
            .on('input.assistantModalShopify change.assistantModalShopify', syncShopify);
        domain.off('input.assistantModalEmail change.assistantModalEmail blur.assistantModalEmail')
            .on('input.assistantModalEmail change.assistantModalEmail blur.assistantModalEmail', suggestEmail);
        email.off('input.assistantModalEmail').on('input.assistantModalEmail', function () {
            if (lastAutoValue !== null && $.trim(String(email.val() || '')) !== lastAutoValue) {
                lastAutoValue = null;
            }
        });
        syncShopify();
    }

    function initializeMultiOptionGroups(root) {
        root.find('[data-multi-option-group]').each(function () {
            var group = $(this);

            group.off('click.assistantModalMulti', '.assistant-multi-option-choice')
                .on('click.assistantModalMulti', '.assistant-multi-option-choice', function () {
                    var choice = $(this);
                    var selected = !choice.hasClass('is-selected');
                    var value = String(choice.attr('data-option-value'));
                    var input = group.find('.assistant-multi-option-input').filter(function () {
                        return String($(this).val()) === value;
                    });

                    choice.toggleClass('is-selected', selected).attr('aria-pressed', selected ? 'true' : 'false');
                    input.prop('disabled', !selected);
                    markDirty();
                });
        });
    }

    function clearValidationErrors(form) {
        form.find('.assistant-modal-validation-error').remove();
        form.find('.form-group').removeClass('has-error assistant-modal-has-error');
    }

    function showValidationErrors(form, response) {
        var errors = response && response.errors ? response.errors : {};
        var messages = [];
        var alert = $('<div class="alert alert-danger assistant-modal-form-alert" role="alert"></div>');

        clearValidationErrors(form);
        Object.keys(errors).forEach(function (name) {
            var fieldName = name.split('.')[0];
            var field = form.find('[name]').filter(function () {
                var candidate = String($(this).attr('name') || '');

                return candidate === name || candidate === fieldName || candidate === fieldName + '[]';
            }).first();
            var group = field.closest('.form-group');
            var message = Array.isArray(errors[name]) ? errors[name][0] : errors[name];

            if (message) {
                messages.push(message);
            }

            if (group.length && message) {
                group.addClass('has-error assistant-modal-has-error');
                $('<span class="help-block assistant-modal-validation-error"></span>').text(message).appendTo(group);
            }
        });

        if (!messages.length) {
            messages.push(response && response.message ? response.message : text('saveFailed'));
        }

        $('<strong></strong>').text(messages[0]).appendTo(alert);
        form.prepend(alert);
        body.scrollTop(0);
    }

    function setSaving(form, saving) {
        var submit = form.find('[data-modal-submit]');

        state.saving = saving;
        modal.toggleClass('is-saving', saving);
        submit.each(function () {
            var button = $(this);

            if (!button.data('original-label')) {
                button.data('original-label', button.html());
            }

            button.prop('disabled', saving).html(saving ? '<i class="fa fa-spinner fa-spin"></i> ' + escapeHtml(text('saving')) : button.data('original-label'));
        });
        form.find('.assistant-modal-cancel').prop('disabled', saving);
    }

    function refreshList() {
        return $.ajax({
            url: window.location.href,
            method: 'GET',
            dataType: 'html',
            cache: false,
        }).done(function (response) {
            var parsed = $('<div>').append($.parseHTML(response, document, false));
            var utility = parsed.find('.assistant-list-utility-bar').first();
            var list = parsed.find('.assistant-domain-stores-list').first();

            if (!utility.length || !list.length) {
                return;
            }

            $('.assistant-list-utility-bar').first().replaceWith(utility);
            $('.assistant-domain-stores-list').first().replaceWith(list);

            if (window.ErpAssistantDomainStores && typeof window.ErpAssistantDomainStores.refresh === 'function') {
                window.ErpAssistantDomainStores.refresh($('.assistant-list-utility-bar, .assistant-domain-stores-list'));
            }

            if (window.ErpAssistantDomainStoreColumnResize && typeof window.ErpAssistantDomainStoreColumnResize.initialize === 'function') {
                window.ErpAssistantDomainStoreColumnResize.initialize(document);
            }
        });
    }

    function submitForm(form) {
        if (state.saving) {
            return;
        }

        form.find('.assistant-tag-entry').trigger('blur');
        clearValidationErrors(form);
        setSaving(form, true);

        $.ajax({
            url: form.attr('action'),
            method: String(form.attr('method') || 'POST').toUpperCase(),
            data: form.serialize(),
            dataType: 'json',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).done(function (response) {
            state.dirty = false;
            state.allowClose = true;
            modal.modal('hide');
            refreshList().always(function () {
                notify('success', response && response.message ? response.message : text('successTitle'));
            });
        }).fail(function (xhr) {
            showValidationErrors(form, xhr.responseJSON || null);
        }).always(function () {
            setSaving(form, false);
        });
    }

    function initializeForm() {
        var form = body.find('.assistant-domain-store-form[data-modal-form]').first();

        if (!form.length) {
            return;
        }

        initializeOptionGroups(form);
        initializeTagEditors(form);
        initializeSingleOptionGroups(form);
        initializeShopifyAndEmail(form);
        initializeMultiOptionGroups(form);
        form.find('[data-project-quick-option-group]').trigger('assistant:project-choice-sync');
        form.off('input.assistantModalDirty change.assistantModalDirty', 'input, textarea, select')
            .on('input.assistantModalDirty change.assistantModalDirty', 'input, textarea, select', markDirty)
            .off('submit.assistantModal')
            .on('submit.assistantModal', function (event) {
                event.preventDefault();
                submitForm(form);
            })
            .off('click.assistantModalCancel', '.assistant-modal-cancel')
            .on('click.assistantModalCancel', '.assistant-modal-cancel', requestClose);
    }

    function openEditor(trigger) {
        var url = String(trigger.attr('href') || '');

        if (!url || state.saving) {
            return;
        }

        if (modal.hasClass('in') && !mayDiscardChanges()) {
            return;
        }

        clearForm();
        state.allowClose = false;
        state.currentUrl = url;
        var requestVersion = state.requestVersion;
        title.text(String(trigger.attr('data-editor-title') || ''));
        showLoading();
        modal.modal('show');

        var request = $.ajax({
            url: modalRequestUrl(url),
            method: 'GET',
            dataType: 'html',
            cache: false,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).done(function (response) {
            if (state.requestVersion !== requestVersion || state.currentUrl !== url) {
                return;
            }

            body.html(response);
            initializeForm();
            state.dirty = false;
        }).fail(function (xhr, status) {
            if (status === 'abort' || state.requestVersion !== requestVersion || state.currentUrl !== url) {
                return;
            }

            body.html('<div class="alert alert-danger assistant-modal-form-alert" role="alert">' + escapeHtml(text('loadFailed')) + '</div>');
        }).always(function () {
            if (state.request === request) {
                state.request = null;
            }
        });
        state.request = request;
    }

    $(document).on('click', '.assistant-editor-trigger', function (event) {
        event.preventDefault();
        openEditor($(this));
    });

    modal.on('hide.bs.modal', function (event) {
        if (state.allowClose) {
            return;
        }

        if (state.saving || !mayDiscardChanges()) {
            event.preventDefault();
        }
    }).on('hidden.bs.modal', function () {
        clearForm();
        state.allowClose = false;
    });

    modal.on('click', '.assistant-editor-close', function () {
        requestClose();
    });
})(jQuery);
