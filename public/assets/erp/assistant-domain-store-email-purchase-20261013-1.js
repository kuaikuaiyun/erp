(function ($) {
    'use strict';

    var config = window.ErpAssistantEmailPurchaseConfig || {};

    function labelClass(status) {
        if (status === 'current') {
            return 'label-success';
        }

        if (status === 'missing') {
            return 'label-warning';
        }

        return 'label-default';
    }

    function render(label, status, text) {
        label.attr('class', 'label ' + labelClass(status))
            .attr('data-status', status)
            .text(text || '');
    }

    $(function () {
        var email = $('#email');
        var label = $('[data-email-purchase-label]');
        if (!email.length || !label.length || !config.statusUrl) {
            return;
        }

        var timer = null;
        var requestId = 0;

        function lookup() {
            var value = $.trim(email.val() || '');
            requestId += 1;
            var currentRequestId = requestId;

            if (value === '') {
                render(label, 'never', config.empty || '');
                return;
            }

            render(label, 'loading', config.loading || '');
            $.get(config.statusUrl, { email: value })
                .done(function (response) {
                    if (currentRequestId !== requestId) {
                        return;
                    }

                    render(label, response.status || 'unsynced', response.label || '');
                })
                .fail(function () {
                    if (currentRequestId !== requestId) {
                        return;
                    }

                    render(label, 'unsynced', config.failed || '');
                });
        }

        email.on('input change blur', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(lookup, 350);
        });
    });
}(window.jQuery));
