(function ($) {
    'use strict';

    var translations = window.ErpAssistantDomainStoreTagTranslations || {};

    function translate(key, replacements) {
        var message = translations[key] || key;

        Object.keys(replacements || {}).forEach(function (name) {
            message = message.split('__' + name + '__').join(String(replacements[name]));
        });

        return message;
    }

    $(function () {
        $('[data-tag-input-group]').each(function () {
            initializeTagEditor($(this));
        });

        $('[data-option-group]').each(function () {
            initializeOptionGroup($(this));
        });
    });

    function initializeOptionGroup(group) {
        var input = group.find('input[type="hidden"]').first();
        var required = String(group.data('required')) === 'true';

        function setValue(value) {
            input.val(value);

            group.find('.assistant-option-choice').each(function () {
                var choice = $(this);
                var selected = String(choice.attr('data-option-value')) === String(value) && value !== '';

                choice.toggleClass('is-selected', selected)
                    .attr('aria-pressed', selected ? 'true' : 'false');
            });
        }

        group.on('click', '.assistant-option-choice', function () {
            var choice = $(this);
            var value = String(choice.attr('data-option-value'));

            setValue(!required && choice.hasClass('is-selected') ? '' : value);
        });

        setValue(String(input.val() || ''));
    }

    function initializeTagEditor(group) {
        var editor = group.find('[data-tag-editor]');
        var input = editor.find('.assistant-tag-entry');
        var fieldName = editor.data('field-name');

        function normalize(value) {
            return $.trim(String(value || ''));
        }

        function hasValue(value) {
            return editor.find('.assistant-tag-chip').filter(function () {
                return $(this).attr('data-tag-value') === value;
            }).length > 0;
        }

        function syncPresetStates() {
            group.find('.assistant-tag-preset').each(function () {
                var preset = $(this);
                var selected = hasValue(normalize(preset.attr('data-tag-value')));

                preset.toggleClass('is-selected', selected)
                    .attr('aria-pressed', selected ? 'true' : 'false');
            });
        }

        function addValue(rawValue) {
            var value = normalize(rawValue);

            if (!value || hasValue(value)) {
                return;
            }

            var chip = $('<span>', {
                class: 'assistant-tag-chip',
                'data-tag-value': value
            });

            $('<span>', {
                class: 'assistant-tag-chip-label',
                text: value
            }).appendTo(chip);
            $('<input>', {
                type: 'hidden',
                name: fieldName,
                value: value
            }).appendTo(chip);
            $('<button>', {
                type: 'button',
                class: 'assistant-tag-remove',
                title: translate('remove'),
                'aria-label': translate('removeTag', { name: value })
            }).append($('<i>', { class: 'fa fa-times' })).appendTo(chip);

            chip.insertBefore(input);
        }

        function addValues(rawValue) {
            String(rawValue || '').split(/[、,，;；\r\n]+/).forEach(addValue);
            input.val('');
            syncPresetStates();
        }

        editor.on('click', function (event) {
            if (!$(event.target).closest('.assistant-tag-chip').length) {
                input.trigger('focus');
            }
        });

        input.on('keydown', function (event) {
            if (event.key === 'Enter' || [',', '，', ';', '；'].indexOf(event.key) !== -1) {
                event.preventDefault();
                addValues(input.val());
            }
        }).on('blur', function () {
            addValues(input.val());
        }).on('paste', function (event) {
            var clipboard = event.originalEvent.clipboardData;
            var pasted = clipboard ? clipboard.getData('text') : '';

            if (/[、,，;；\r\n]/.test(pasted)) {
                event.preventDefault();
                addValues(pasted);
            }
        });

        group.on('click', '.assistant-tag-remove', function () {
            $(this).closest('.assistant-tag-chip').remove();
            syncPresetStates();
            input.trigger('focus');
        }).on('click', '.assistant-tag-preset', function () {
            var preset = $(this);
            var value = normalize(preset.attr('data-tag-value'));

            if (hasValue(value)) {
                editor.find('.assistant-tag-chip').filter(function () {
                    return $(this).attr('data-tag-value') === value;
                }).remove();
            } else {
                addValue(value);
            }

            syncPresetStates();
        });

        group.closest('form').on('submit', function () {
            addValues(input.val());
        });

        syncPresetStates();
    }
})(jQuery);
