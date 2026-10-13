(function ($) {
    'use strict';

    $(function () {
        var config = window.ErpAssistantDomainStoreQuickOptionConfig || {};
        var translations = config.translations || {};

        function message(key) {
            return translations[key] || '';
        }

        function showButtonFeedback(button, text, success) {
            var original = button.data('original-label');

            if (!original) {
                original = button.html();
                button.data('original-label', original);
            }

            button.toggleClass('is-saved', success).text(text);
            window.setTimeout(function () {
                button.removeClass('is-saved').html(original);
            }, 1400);
        }

        function appendChoice(group, value) {
            var presets = group.find('.assistant-single-option-presets').first();
            var existing = presets.find('.assistant-single-option-choice').filter(function () {
                return String($(this).attr('data-option-value')) === value;
            }).first();

            if (existing.length) {
                return existing;
            }

            var wrapper = $('<span>', {
                class: 'assistant-custom-quick-option-wrap',
            });
            var choice = $('<button>', {
                type: 'button',
                class: 'btn btn-default btn-sm assistant-single-option-choice assistant-custom-quick-option',
                'data-option-value': value,
            }).text(value).appendTo(wrapper);

            $('<button>', {
                type: 'button',
                class: 'assistant-remove-quick-option',
                'data-option-value': value,
                'aria-label': message('delete'),
                title: message('delete'),
            }).append($('<i>', { class: 'fa fa-times' })).appendTo(wrapper);

            wrapper.appendTo(presets);

            return choice;
        }

        function showDeleteFailure(button, text) {
            var originalTitle = button.data('original-title');

            if (!originalTitle) {
                originalTitle = button.attr('title') || message('delete');
                button.data('original-title', originalTitle);
            }

            button.attr('title', text || message('deleteFailed')).addClass('is-delete-failed');
            window.setTimeout(function () {
                button.attr('title', originalTitle).removeClass('is-delete-failed');
            }, 1800);
        }

        $(document).on('click', '.assistant-add-quick-option', function () {
            var button = $(this);
            var group = button.closest('[data-single-option-group]');
            var input = group.find('input.form-control').first();
            var field = String(group.attr('data-quick-option-field') || '');
            var value = $.trim(String(input.val() || ''));

            if (!value) {
                input.trigger('focus');
                showButtonFeedback(button, message('empty'), false);
                return;
            }

            if (!config.url || !field) {
                showButtonFeedback(button, message('failed'), false);
                return;
            }

            button.prop('disabled', true);

            $.ajax({
                url: config.url,
                method: 'POST',
                dataType: 'json',
                data: {
                    _token: $('meta[name="csrf-token"]').attr('content'),
                    field: field,
                    value: value,
                },
            }).done(function (response) {
                var option = response && response.option ? response.option : null;

                if (!option || String(option.field) !== field || !option.value) {
                    showButtonFeedback(button, message('failed'), false);
                    return;
                }

                appendChoice(group, String(option.value));
                input.val(String(option.value)).trigger('input').trigger('change').trigger('focus');
                showButtonFeedback(button, message('added'), true);
            }).fail(function (xhr) {
                var error = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : message('failed');

                showButtonFeedback(button, error, false);
            }).always(function () {
                button.prop('disabled', false);
            });
        });

        $(document).on('click', '.assistant-remove-quick-option', function (event) {
            event.preventDefault();
            event.stopPropagation();

            var button = $(this);
            var group = button.closest('[data-single-option-group]');
            var field = String(group.attr('data-quick-option-field') || '');
            var value = String(button.attr('data-option-value') || '');

            if (!config.deleteUrl || !field || !value) {
                showDeleteFailure(button, message('deleteFailed'));
                return;
            }

            button.prop('disabled', true);

            $.ajax({
                url: config.deleteUrl,
                method: 'DELETE',
                dataType: 'json',
                data: {
                    _token: $('meta[name="csrf-token"]').attr('content'),
                    field: field,
                    value: value,
                },
            }).done(function (response) {
                var option = response && response.option ? response.option : null;

                if (!option || String(option.field) !== field || String(option.value) !== value) {
                    showDeleteFailure(button, message('deleteFailed'));
                    return;
                }

                button.closest('.assistant-custom-quick-option-wrap').remove();
            }).fail(function (xhr) {
                var error = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : message('deleteFailed');

                showDeleteFailure(button, error);
            }).always(function () {
                button.prop('disabled', false);
            });
        });
    });
})(jQuery);
