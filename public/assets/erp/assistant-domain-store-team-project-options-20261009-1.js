(function ($) {
    'use strict';

    function config() {
        return window.ErpAssistantDomainStoreQuickOptionConfig || {};
    }

    function message(key) {
        var translations = config().translations || {};

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

    function escapeHtml(value) {
        return $('<div>').text(value).html();
    }

    function appendOption(group, field, value, canDelete) {
        var presets = group.find(field === 'team' ? '.assistant-tag-presets' : '.assistant-project-option-presets').first();
        var selector = field === 'team' ? '.assistant-tag-preset' : '.assistant-project-option-choice';
        var existing = presets.find(selector).filter(function () {
            var attribute = field === 'team' ? 'data-tag-value' : 'data-option-value';

            return String($(this).attr(attribute)) === value;
        }).first();

        if (existing.length) {
            return existing;
        }

        var wrapper = $('<span>', { class: 'assistant-custom-quick-option-wrap' });
        var choice = $('<button>', {
            type: 'button',
            class: 'btn btn-default btn-sm ' + (field === 'team' ? 'assistant-tag-preset' : 'assistant-project-option-choice'),
        }).attr(field === 'team' ? 'data-tag-value' : 'data-option-value', value)
            .text(value)
            .appendTo(wrapper);

        if (canDelete) {
            $('<button>', {
                type: 'button',
                class: 'assistant-remove-team-project-quick-option',
                'data-option-value': value,
                'aria-label': message('delete'),
                title: message('delete'),
            }).append($('<i>', { class: 'fa fa-times' })).appendTo(wrapper);
        }

        wrapper.appendTo(presets);

        return choice;
    }

    function syncProjectChoices(group) {
        var currentValue = $.trim(String(group.find('input[name="project"]').first().val() || ''));

        group.find('.assistant-project-option-choice').each(function () {
            var choice = $(this);
            var selected = String(choice.attr('data-option-value')) === currentValue && currentValue !== '';

            choice.toggleClass('is-selected', selected).attr('aria-pressed', selected ? 'true' : 'false');
        });
    }

    $(document).on('click', '.assistant-project-option-choice', function () {
        var choice = $(this);
        var group = choice.closest('[data-project-quick-option-group]');

        group.find('input[name="project"]').first()
            .val(String(choice.attr('data-option-value') || ''))
            .trigger('input')
            .trigger('change')
            .trigger('focus');
        syncProjectChoices(group);
    });

    $(document).on('assistant:project-choice-sync', '[data-project-quick-option-group]', function () {
        syncProjectChoices($(this));
    });

    $(function () {
        $('[data-project-quick-option-group]').each(function () {
            syncProjectChoices($(this));
        });
    });

    $(document).on('mousedown', '.assistant-add-team-project-quick-option', function () {
        var button = $(this);
        var group = button.closest('[data-quick-option-field]');

        if (String(group.attr('data-quick-option-field') || '') === 'team') {
            button.data('team-option-value', $.trim(String(group.find('.assistant-tag-entry').first().val() || '')));
        }
    });

    $(document).on('click', '.assistant-add-team-project-quick-option', function () {
        var button = $(this);
        var group = button.closest('[data-quick-option-field]');
        var field = String(group.attr('data-quick-option-field') || '');
        var input = field === 'team'
            ? group.find('.assistant-tag-entry').first()
            : group.find('input[name="project"]').first();
        var value = field === 'team'
            ? $.trim(String(button.data('team-option-value') || input.val() || ''))
            : $.trim(String(input.val() || ''));
        var optionConfig = config();

        button.removeData('team-option-value');

        if (!value) {
            input.trigger('focus');
            showButtonFeedback(button, message('empty'), false);
            return;
        }

        if (!optionConfig.url || (field !== 'team' && field !== 'project')) {
            showButtonFeedback(button, message('failed'), false);
            return;
        }

        button.prop('disabled', true);

        $.ajax({
            url: optionConfig.url,
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

            var choice = appendOption(group, field, String(option.value), true);

            if (field === 'team') {
                var hasTag = group.find('.assistant-tag-chip').filter(function () {
                    return String($(this).attr('data-tag-value')) === String(option.value);
                }).length > 0;

                if (!hasTag) {
                    choice.trigger('click');
                }
            } else {
                choice.trigger('click');
            }

            showButtonFeedback(button, message('added'), true);
        }).fail(function (xhr) {
            showButtonFeedback(button, xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : message('failed'), false);
        }).always(function () {
            button.prop('disabled', false);
        });
    });

    $(document).on('click', '.assistant-remove-team-project-quick-option', function (event) {
        event.preventDefault();
        event.stopPropagation();

        var button = $(this);
        var group = button.closest('[data-quick-option-field]');
        var field = String(group.attr('data-quick-option-field') || '');
        var value = String(button.attr('data-option-value') || '');
        var optionConfig = config();

        if (!optionConfig.deleteUrl || (field !== 'team' && field !== 'project') || !value) {
            showDeleteFailure(button, message('deleteFailed'));
            return;
        }

        button.prop('disabled', true);

        $.ajax({
            url: optionConfig.deleteUrl,
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

            if (field === 'project') {
                group.find('input[name="project"]').trigger('input').trigger('change');
                syncProjectChoices(group);
            }
        }).fail(function (xhr) {
            showDeleteFailure(button, xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : message('deleteFailed'));
        }).always(function () {
            button.prop('disabled', false);
        });
    });
})(jQuery);
