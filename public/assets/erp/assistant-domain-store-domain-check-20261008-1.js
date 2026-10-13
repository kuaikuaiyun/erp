(function ($) {
    'use strict';

    $(function () {
        $('[data-domain-check-option-group]').each(function () {
            var group = $(this);
            var input = group.find('input[type="hidden"]').first();
            var displayStatus = String(group.attr('data-display-status') || input.val() || '');

            function syncChoices() {
                group.find('.assistant-option-choice').each(function () {
                    var choice = $(this);
                    var selected = String(choice.attr('data-option-value')) === displayStatus;

                    choice.toggleClass('is-selected', selected)
                        .attr('aria-pressed', selected ? 'true' : 'false');
                });
            }

            group.on('click', '.assistant-option-choice', function () {
                displayStatus = String($(this).attr('data-option-value'));
                input.val(displayStatus);
                syncChoices();
            });

            syncChoices();
        });
    });
})(jQuery);
