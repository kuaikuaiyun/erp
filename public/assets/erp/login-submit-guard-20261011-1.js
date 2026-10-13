(function (window, document) {
    'use strict';

    var form = document.getElementById('admin-login-form');
    var config = window.ErpInteractionLoadingConfig || {};
    var token = null;

    if (!form) {
        return;
    }

    function text(key, fallback) {
        return config[key] || fallback;
    }

    function button() {
        return form.querySelector('[type="submit"]');
    }

    function begin() {
        if (token || form.dataset.loginSubmitting === '1') {
            return false;
        }
        form.dataset.loginSubmitting = '1';
        if (window.ErpInteractionLoading) {
            token = window.ErpInteractionLoading.begin({
                mode: 'button',
                form: form,
                button: button(),
                message: text('logging_in', 'Signing in…'),
                buttonText: text('logging_in', 'Signing in…')
            });
        } else if (button()) {
            button().disabled = true;
        }
        return true;
    }

    function end() {
        if (token && window.ErpInteractionLoading) {
            window.ErpInteractionLoading.end(token);
        } else if (button()) {
            button().disabled = false;
        }
        token = null;
        delete form.dataset.loginSubmitting;
    }

    form.addEventListener('submit', function (event) {
        if (form.hasAttribute('data-tencent-options-url') && form.getAttribute('data-tencent-captcha-verified') !== '1') {
            return;
        }
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            return;
        }
        if (!begin()) {
            event.preventDefault();
        }
    }, true);

    window.addEventListener('pageshow', end);
    window.ErpLoginSubmitGuard = { begin: begin, end: end };
})(window, document);
