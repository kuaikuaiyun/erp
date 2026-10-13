(function ($) {
    'use strict';

    var translations = window.ErpAssistantDomainStoreTranslations || {};

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

    function updateBatchCheckState() {
        var selected = selectedStoreIds();
        var form = $('#assistant-batch-check-form');
        var inputs = $('#assistant-batch-check-inputs');
        var button = $('#assistant-batch-check-button');

        if (!form.length) {
            return;
        }

        inputs.empty();
        selected.forEach(function (id) {
            $('<input>', { type: 'hidden', name: 'selected_ids[]', value: id }).appendTo(inputs);
        });
        form.prop('hidden', selected.length === 0);
        button.prop('disabled', false)
            .html('<i class="fa fa-refresh"></i> ' + translate('batchCheckCount', { count: selected.length }));
    }

    $(function () {
        updateBatchCheckState();

        $(document).on('click', '.assistant-select-all, .assistant-row-selector', function () {
            window.setTimeout(updateBatchCheckState, 0);
        });

        $(document).on('submit', '#assistant-batch-check-form', function (event) {
            var selected = selectedStoreIds();

            if (selected.length === 0) {
                event.preventDefault();
                updateBatchCheckState();
                return;
            }

            if (selected.length > 20) {
                event.preventDefault();
                window.alert(translate('batchCheckMaxRecords'));
                return;
            }

            if (!window.confirm(translate('batchCheckConfirm', { count: selected.length }))) {
                event.preventDefault();
                return;
            }

            $('#assistant-batch-check-button')
                .prop('disabled', true)
                .html('<i class="fa fa-spinner fa-spin"></i> ' + translate('batchCheckInProgress', { count: selected.length }));
        });
    });
})(jQuery);
