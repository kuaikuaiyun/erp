(function ($) {
    'use strict';

    var translations = window.ErpAssistantDomainStoreBatchUpdateTranslations || {};

    function translate(key, replacements) {
        var message = translations[key] || key;

        Object.keys(replacements || {}).forEach(function (name) {
            message = message.split('__' + name + '__').join(String(replacements[name]));
        });

        return message;
    }

    function selectedStoreIds() {
        return $('.assistant-row-selector.is-selected').map(function () {
            return $(this).data('store-id');
        }).get();
    }

    function setField(form, field) {
        var valueArea = form.find('[data-batch-update-value-area]');
        var controls = form.find('[data-batch-value-control]');
        var active = controls.filter('[data-batch-value-control="' + field + '"]');
        var valueInputs = controls.find('[data-batch-value-input]');
        var submit = form.find('[data-batch-update-submit]');

        controls.prop('hidden', true);
        valueInputs.prop('disabled', true);
        valueArea.prop('hidden', field === '');

        if (field === '' || !active.length) {
            submit.prop('disabled', true);
            return;
        }

        active.prop('hidden', false);
        active.find('[data-batch-value-input]').prop('disabled', false).first().trigger('focus');
        form.find('[data-batch-update-value-label]').text(form.find('[data-batch-update-field] option:selected').text());
        submit.prop('disabled', selectedStoreIds().length === 0);
    }

    function syncSelectedIds(form) {
        var selectedIds = selectedStoreIds();
        var inputs = form.find('#assistant-batch-update-inputs');

        inputs.empty();
        selectedIds.forEach(function (id) {
            $('<input>', { type: 'hidden', name: 'selected_ids[]', value: id }).appendTo(inputs);
        });

        form.find('[data-batch-update-selection-count]').text(translate('selectionCount', { count: selectedIds.length }));
        return selectedIds;
    }

    function resetForm(form) {
        form.get(0).reset();
        form.find('[data-batch-value-control]').prop('hidden', true);
        form.find('[data-batch-value-input]').prop('disabled', true);
        form.find('[data-batch-update-value-area]').prop('hidden', true);
        form.find('[data-batch-update-submit]').prop('disabled', true);
        form.find('#assistant-batch-update-inputs').empty();
    }

    $(function () {
        var modal = $('#assistant-batch-update-modal');
        var form = $('#assistant-batch-update-form');

        if (!modal.length || !form.length) {
            return;
        }

        modal.on('show.bs.modal', function (event) {
            if (selectedStoreIds().length === 0) {
                event.preventDefault();
                window.alert(translate('selectBeforeUpdate'));
                return;
            }

            resetForm(form);
            syncSelectedIds(form);
        });

        form.on('change', '[data-batch-update-field]', function () {
            setField(form, String($(this).val() || ''));
        });

        form.on('submit', function (event) {
            var selectedIds = syncSelectedIds(form);
            var field = String(form.find('[data-batch-update-field]').val() || '');
            var activeInput = form.find('[data-batch-value-control="' + field + '"] [data-batch-value-input]').first();

            if (selectedIds.length === 0) {
                event.preventDefault();
                window.alert(translate('selectBeforeUpdate'));
                return;
            }

            if (field === '') {
                event.preventDefault();
                window.alert(translate('chooseField'));
                return;
            }

            if (activeInput.prop('required') && $.trim(String(activeInput.val() || '')) === '') {
                event.preventDefault();
                window.alert(translate('requiredValue'));
            }
        });
    });
})(jQuery);
