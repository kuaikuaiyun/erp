(function ($) {
    'use strict';

    $(function () {
        $('[data-single-option-group]').each(function () {
            initializeSingleOptionGroup($(this));
        });

        initializeShopifyAppSection();
        initializeEmailSuggestion();
    });

    function initializeSingleOptionGroup(group) {
        var input = group.find('input.form-control').first();

        function syncChoices() {
            var currentValue = $.trim(String(input.val() || ''));

            group.find('.assistant-single-option-choice').each(function () {
                var choice = $(this);
                var selected = String(choice.attr('data-option-value')) === currentValue && currentValue !== '';

                choice.toggleClass('is-selected', selected)
                    .attr('aria-pressed', selected ? 'true' : 'false');
            });
        }

        group.on('click', '.assistant-single-option-choice', function () {
            input.val(String($(this).attr('data-option-value'))).trigger('input').trigger('change').trigger('focus');
        });

        input.on('input change', syncChoices);
        syncChoices();
    }

    function initializeShopifyAppSection() {
        var siteBuilder = $('#site_builder');
        var section = $('#assistant-shopify-app-section');

        if (!siteBuilder.length || !section.length) {
            return;
        }

        function syncVisibility() {
            section.prop('hidden', $.trim(String(siteBuilder.val() || '')).toLowerCase() !== 'shopify');
        }

        siteBuilder.on('input change', syncVisibility);
        syncVisibility();
    }

    function initializeEmailSuggestion() {
        var domain = $('#domain');
        var email = $('#email');
        var lastAutoValue = null;

        if (!domain.length || !email.length) {
            return;
        }

        function hostnameFromInput(rawValue) {
            var value = $.trim(String(rawValue || ''));

            if (!value) {
                return '';
            }

            try {
                return new URL(/^[a-z][a-z0-9+.-]*:\/\//i.test(value) ? value : 'https://' + value).hostname;
            } catch (error) {
                return '';
            }
        }

        function suggestEmail() {
            var hostname = hostnameFromInput(domain.val());
            var currentValue = $.trim(String(email.val() || ''));

            if (!hostname) {
                return;
            }

            var suggestedValue = 'support@' + hostname;

            if (currentValue === '' || (lastAutoValue !== null && currentValue === lastAutoValue)) {
                email.val(suggestedValue);
                lastAutoValue = suggestedValue;
            }
        }

        domain.on('input change blur', suggestEmail);
        email.on('input', function () {
            if (lastAutoValue !== null && $.trim(String(email.val() || '')) !== lastAutoValue) {
                lastAutoValue = null;
            }
        });
    }
})(jQuery);
