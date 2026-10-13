(function ($) {
    'use strict';

    var translations = window.ErpAssistantDomainStoreBatchUpdateTranslations || {};
    var modal = $('#assistant-batch-update-modal');
    var body = $('#assistant-batch-update-content');
    var footer = $('#assistant-batch-update-footer');
    var state = {
        request: null,
        requestVersion: 0,
        selectedIds: [],
        dirty: false,
        saving: false,
        allowClose: false,
    };

    function translate(key, replacements) {
        var message = translations[key] || key;

        Object.keys(replacements || {}).forEach(function (name) {
            message = message.split('__' + name + '__').join(String(replacements[name]));
        });

        return message;
    }

    function escapeHtml(value) {
        return $('<div>').text(value || '').html();
    }

    function selectedStoreIds() {
        return $('.assistant-row-selector.is-selected').map(function () {
            return Number($(this).data('store-id'));
        }).get();
    }

    function notify(type, message) {
        if (window.ErpAdminNotifier && typeof window.ErpAdminNotifier.notify === 'function') {
            window.ErpAdminNotifier.notify({
                type: type,
                title: translate(type === 'success' ? 'successTitle' : 'errorTitle'),
                message: message,
            });
            return;
        }

        window.alert(message);
    }

    function clearModal() {
        state.requestVersion += 1;

        if (state.request && state.request.readyState !== 4) {
            state.request.abort();
        }

        state.request = null;
        state.selectedIds = [];
        state.dirty = false;
        state.saving = false;
        body.html('<div class="assistant-batch-editor-loading"><i class="fa fa-spinner fa-spin"></i> ' + escapeHtml(translate('loading')) + '</div>');
        footer.empty();
        modal.removeClass('is-saving');
    }

    function recordUrl(trigger, ids) {
        var base = String(trigger.attr('data-batch-editor-url') || '');
        var separator = base.indexOf('?') === -1 ? '?' : '&';
        var query = ids.map(function (id) {
            return 'selected_ids%5B%5D=' + encodeURIComponent(String(id));
        }).join('&');

        return base + separator + query;
    }

    function renderFooter() {
        footer.html(
            '<button type="button" class="btn btn-default" data-batch-editor-cancel>' + escapeHtml(translate('cancel')) + '</button>' +
            '<button type="submit" form="assistant-batch-edit-form" class="btn btn-primary" data-batch-editor-save><i class="fa fa-save"></i> ' + escapeHtml(translate('saveAll')) + '</button>'
        );
    }

    function activateRecord(recordId) {
        body.find('[data-batch-record-target="' + recordId + '"]').addClass('is-active');
        body.find('[data-batch-record-panel]').removeClass('is-active');
        body.find('[data-batch-record-panel="' + recordId + '"]').addClass('is-active');
    }

    function syncShopifyPanel(panel) {
        var builder = $.trim(String(panel.find('[data-batch-site-builder]').val() || '')).toLowerCase();

        panel.find('[data-batch-shopify-section]').prop('hidden', builder !== 'shopify');
    }

    function clearValidationErrors() {
        body.find('.assistant-batch-validation-error').remove();
        body.find('[data-batch-field]').removeClass('has-error');
        body.find('[data-batch-record-target]').removeClass('has-error');
        body.find('.assistant-batch-editor-alert').remove();
    }

    function showValidationErrors(response) {
        var errors = response && response.errors ? response.errors : {};
        var firstRecordId = null;
        var summary = response && response.message ? response.message : translate('saveFailed');

        clearValidationErrors();
        Object.keys(errors).forEach(function (key) {
            var match = key.match(/^records\.([^\.]+)\.([^\.]+)/);
            var message = Array.isArray(errors[key]) ? errors[key][0] : errors[key];

            if (!message) {
                return;
            }

            if (!match) {
                summary = message;
                return;
            }

            var recordId = match[1];
            var field = match[2];
            var panel = body.find('[data-batch-record-panel="' + recordId + '"]');
            var group = panel.find('[data-batch-field="' + field + '"]').first();

            if (firstRecordId === null) {
                firstRecordId = recordId;
                summary = message;
            }

            body.find('[data-batch-record-target="' + recordId + '"]').addClass('has-error');
            if (group.length) {
                group.addClass('has-error');
                $('<span class="help-block assistant-batch-validation-error"></span>').text(message).appendTo(group);
            }
        });

        $('<div class="alert alert-danger assistant-batch-editor-alert" role="alert"></div>').text(summary).prependTo(body.find('.assistant-batch-edit-form'));
        if (firstRecordId !== null) {
            activateRecord(firstRecordId);
        }
    }

    function setSaving(saving) {
        state.saving = saving;
        modal.toggleClass('is-saving', saving);
        footer.find('button').prop('disabled', saving);
        footer.find('[data-batch-editor-save]').html(saving
            ? '<i class="fa fa-spinner fa-spin"></i> ' + escapeHtml(translate('saving'))
            : '<i class="fa fa-save"></i> ' + escapeHtml(translate('saveAll')));
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

    function initializeEditor() {
        var form = body.find('[data-batch-edit-form]').first();

        if (!form.length) {
            return;
        }

        form.find('input[name="return_to"]').val(window.location.href);
        form.find('[data-batch-record-panel]').each(function () {
            syncShopifyPanel($(this));
        });
        renderFooter();

        form.on('input.assistantBatchEdit change.assistantBatchEdit', 'input, textarea, select', function () {
            state.dirty = true;
        }).on('input.assistantBatchBuilder change.assistantBatchBuilder', '[data-batch-site-builder]', function () {
            syncShopifyPanel($(this).closest('[data-batch-record-panel]'));
        }).on('submit.assistantBatchEdit', function (event) {
            event.preventDefault();

            if (state.saving) {
                return;
            }

            clearValidationErrors();
            setSaving(true);
            $.ajax({
                url: form.attr('action'),
                method: 'POST',
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
                    notify(response && response.type ? response.type : 'success', response && response.message ? response.message : translate('successTitle'));
                });
            }).fail(function (xhr) {
                showValidationErrors(xhr.responseJSON || null);
            }).always(function () {
                setSaving(false);
            });
        });

        body.off('click.assistantBatchRecord', '[data-batch-record-target]')
            .on('click.assistantBatchRecord', '[data-batch-record-target]', function () {
                activateRecord(String($(this).attr('data-batch-record-target')));
            });
    }

    function loadEditor(trigger) {
        var selectedIds = selectedStoreIds();

        if (selectedIds.length === 0) {
            window.alert(translate('selectBeforeUpdate'));
            return false;
        }

        if (selectedIds.length > 20) {
            window.alert(translate('maxRecords'));
            return false;
        }

        clearModal();
        state.selectedIds = selectedIds;
        var requestVersion = state.requestVersion;
        var request = $.ajax({
            url: recordUrl(trigger, selectedIds),
            method: 'GET',
            dataType: 'html',
            cache: false,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).done(function (response) {
            if (requestVersion !== state.requestVersion) {
                return;
            }

            body.html(response);
            initializeEditor();
            state.dirty = false;
        }).fail(function (xhr, status) {
            if (status === 'abort' || requestVersion !== state.requestVersion) {
                return;
            }

            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : translate('loadFailed');
            body.html('<div class="alert alert-danger assistant-batch-editor-alert" role="alert">' + escapeHtml(message) + '</div>');
        }).always(function () {
            if (state.request === request) {
                state.request = null;
            }
        });
        state.request = request;

        return true;
    }

    $(function () {
        if (!modal.length) {
            return;
        }

        modal.on('show.bs.modal', function (event) {
            var trigger = $(event.relatedTarget);

            if (!trigger.length || !loadEditor(trigger)) {
                event.preventDefault();
            }
        }).on('hide.bs.modal', function (event) {
            if (state.allowClose) {
                return;
            }

            if (state.saving || (state.dirty && !window.confirm(translate('discardChanges')))) {
                event.preventDefault();
            }
        }).on('hidden.bs.modal', function () {
            clearModal();
            state.allowClose = false;
        });

        footer.on('click', '[data-batch-editor-cancel]', function () {
            modal.modal('hide');
        });
    });
})(jQuery);
