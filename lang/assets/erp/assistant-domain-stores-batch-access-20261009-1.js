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

    function refreshTriggers() {
        var hasSelection = selectedStoreIds().length > 0;
        $('.assistant-batch-access-trigger').prop('hidden', !hasSelection);
    }

    function syncForm(form) {
        var selectedIds = selectedStoreIds();
        var departmentIds = form.find('.assistant-batch-access-choice.is-selected').map(function () {
            return $(this).attr('data-department-id');
        }).get();
        var recordInputs = form.find('[data-selected-record-inputs]');
        var departmentInputs = form.find('[data-selected-department-inputs]');

        recordInputs.empty();
        departmentInputs.empty();
        selectedIds.forEach(function (id) {
            $('<input>', { type: 'hidden', name: 'selected_ids[]', value: id }).appendTo(recordInputs);
        });
        departmentIds.forEach(function (id) {
            $('<input>', { type: 'hidden', name: 'department_ids[]', value: id }).appendTo(departmentInputs);
        });
        form.find('[data-selection-summary]').text(translate('batchAccessSelectionCount', { count: selectedIds.length }));
        form.find('[data-submit-button]').prop('disabled', selectedIds.length === 0 || departmentIds.length === 0);
    }

    $(function () {
        refreshTriggers();

        $(document).on('click', '.assistant-row-selector, .assistant-select-all', function () {
            window.setTimeout(refreshTriggers, 0);
        });

        $('.assistant-batch-access-form').each(function () {
            var form = $(this);

            form.closest('.modal').on('show.bs.modal', function () {
                form.find('.assistant-batch-access-choice')
                    .removeClass('is-selected')
                    .attr('aria-pressed', 'false');
                syncForm(form);
            });

            form.on('click', '.assistant-batch-access-choice', function () {
                var choice = $(this);
                var selected = !choice.hasClass('is-selected');

                choice.toggleClass('is-selected', selected)
                    .attr('aria-pressed', selected ? 'true' : 'false');
                syncForm(form);
            });

            form.on('submit', function (event) {
                syncForm(form);

                if (form.find('[data-submit-button]').prop('disabled')) {
                    event.preventDefault();
                }
            });
        });
    });
})(jQuery);
