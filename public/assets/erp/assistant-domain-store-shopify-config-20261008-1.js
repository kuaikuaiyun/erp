(function ($) {
    'use strict';

    $(function () {
        var translations = window.ErpAssistantDomainStoreShopifyConfigTranslations || {};

        function translate(key) {
            return translations[key] || '';
        }

        function copyText(value) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(value);
            }

            return new Promise(function (resolve, reject) {
                var textarea = document.createElement('textarea');
                textarea.value = value;
                textarea.setAttribute('readonly', 'readonly');
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

        $(document).on('click', '.assistant-shopify-copy-button', function () {
            var button = $(this);
            var target = document.getElementById(button.attr('data-shopify-copy-target'));

            if (!target) {
                return;
            }

            var value = $(target).text().trim();
            if (!value) {
                return;
            }

            var originalLabel = translate('clickToCopy');
            copyText(value).then(function () {
                button.attr('title', translate('copied')).attr('aria-label', translate('copied'));
                window.setTimeout(function () {
                    button.attr('title', originalLabel).attr('aria-label', originalLabel);
                }, 1500);
            }).catch(function () {
                window.alert(translate('copyFailed'));
            });
        });
    });
})(jQuery);
