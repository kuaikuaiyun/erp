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

    function syncForm(form) {
        var selectedIds = selectedStoreIds();
        var selectedFields = form.find('[data-batch-field-enable]:checked').map(function () {
            return $(this).data('batch-field-enable');
        }).get();
        var inputs = form.find('#assistant-batch-update-inputs');

        inputs.empty();
        selectedIds.forEach(function (id) {
            $('<input>', { type: 'hidden', name: 'selected_ids[]', value: id }).appendTo(inputs);
        });

        form.find('[data-batch-field-enable]').each(function () {
            var checkbox = $(this);
            var field = checkbox.data('batch-field-enable');
            var enabled = checkbox.is(':checked');

            form.find('[data-batch-field="' + field + '"]').prop('disabled', !enabled);
            checkbox.closest('.assistant-batch-update-field').toggleClass('is-enabled', enabled);
        });

        form.find('[data-batch-update-selection-count]').text(translate('selectionCount', { count: selectedIds.length }));
        form.find('[data-batch-update-submit]').prop('disabled', selectedIds.length === 0 || selectedFields.length === 0);
    }

    function resetForm(form) {
        form.get(0).reset();
        form.find('[data-batch-field]').prop('disabled', true);
        form.find('.assistant-batch-update-field').removeClass('is-enabled');
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
            syncForm(form);
        });

        form.on('change', '[data-batch-field-enable]', function () {
            syncForm(form);
        });

        form.on('submit', function (event) {
            syncForm(form);

            if (selectedStoreIds().length === 0) {
                event.preventDefault();
                window.alert(translate('selectBeforeUpdate'));
                return;
            }

            if (form.find('[data-batch-field-enable]:checked').length === 0) {
                event.preventDefault();
                window.alert(translate('noFields'));
            }
        });
    });
})(jQuery);
