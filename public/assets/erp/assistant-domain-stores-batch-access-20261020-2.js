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

    function refreshTrigger() {
        $('#assistant-batch-access-trigger').prop('hidden', selectedStoreIds().length === 0);
    }

    function setMode(form, mode) {
        var revoke = mode === 'revoke';
        var method = form.find('[data-access-method]');
        var submit = form.find('[data-submit-button]');
        var icon = form.find('[data-submit-icon]');

        form.attr('data-access-mode', mode)
            .attr('action', revoke ? form.attr('data-revoke-url') : form.attr('data-grant-url'));
        method.prop('disabled', !revoke);

        form.find('[data-access-mode-choice]').each(function () {
            var button = $(this);
            var selected = button.attr('data-access-mode-choice') === mode;
            button.toggleClass('is-selected', selected)
                .toggleClass('btn-primary', selected && !revoke)
                .toggleClass('btn-warning', selected && revoke)
                .toggleClass('btn-default', !selected)
                .attr('aria-pressed', selected ? 'true' : 'false');
        });

        submit.toggleClass('btn-primary', !revoke)
            .toggleClass('btn-warning', revoke);
        icon.toggleClass('fa-share', !revoke)
            .toggleClass('fa-ban', revoke);
        form.find('[data-submit-text]').text(revoke ? form.attr('data-revoke-label') : form.attr('data-grant-label'));
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
        var form = $('.assistant-batch-access-form');
        refreshTrigger();

        $(document).on('click', '.assistant-row-selector, .assistant-select-all', function () {
            window.setTimeout(refreshTrigger, 0);
        });

        form.closest('.modal').on('show.bs.modal', function () {
            form.find('.assistant-batch-access-choice')
                .removeClass('is-selected')
                .attr('aria-pressed', 'false');
            setMode(form, 'grant');
            syncForm(form);
        });

        form.on('click', '[data-access-mode-choice]', function () {
            setMode(form, $(this).attr('data-access-mode-choice'));
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
})(jQuery);
