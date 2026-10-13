(function ($) {
    'use strict';

    $(function () {
        initializeMultiOptionGroups();
        initializeGuideCopyButtons();
    });

    function initializeMultiOptionGroups() {
        $('[data-multi-option-group]').each(function () {
            var group = $(this);

            group.on('click', '.assistant-multi-option-choice', function () {
                var choice = $(this);
                var selected = !choice.hasClass('is-selected');
                var value = String(choice.attr('data-option-value'));
                var input = group.find('.assistant-multi-option-input').filter(function () {
                    return String($(this).val()) === value;
                });

                choice.toggleClass('is-selected', selected)
                    .attr('aria-pressed', selected ? 'true' : 'false');
                input.prop('disabled', !selected);
            });
        });
    }

    function copyText(value) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(value);
        }

        return new Promise(function (resolve, reject) {
            var textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                document.execCommand('copy') ? resolve() : reject();
            } catch (error) {
                reject(error);
            }

            document.body.removeChild(textarea);
        });
    }

    function initializeGuideCopyButtons() {
        $(document).on('click', '.assistant-guide-copy', function () {
            var button = $(this);
            var copyLabel = button.attr('data-copy-label') || '';
            var copiedLabel = button.attr('data-copied-label') || copyLabel;
            var failedLabel = button.attr('data-copy-failed-label') || copyLabel;
            var value = String(button.attr('data-copy-value') || '');

            if (!value) {
                return;
            }

            copyText(value).then(function () {
                button.html('<i class="fa fa-check"></i> ' + $('<div>').text(copiedLabel).html())
                    .addClass('is-copied');
                window.setTimeout(function () {
                    button.html('<i class="fa fa-copy"></i> ' + $('<div>').text(copyLabel).html())
                        .removeClass('is-copied');
                }, 1200);
            }).catch(function () {
                button.html('<i class="fa fa-exclamation-triangle"></i> ' + $('<div>').text(failedLabel).html());
                window.setTimeout(function () {
                    button.html('<i class="fa fa-copy"></i> ' + $('<div>').text(copyLabel).html());
                }, 1800);
            });
        });
    }
})(jQuery);
