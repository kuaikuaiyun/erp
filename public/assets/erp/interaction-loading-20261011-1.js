(function (window, document, $) {
    'use strict';

    var config = window.ErpInteractionLoadingConfig || {};
    var tokens = [];

    function copy(key, fallback) {
        return config[key] || fallback;
    }

    function escapeHtml(value) {
        return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function validTarget(target) {
        return target && target.nodeType === 1 && document.documentElement.contains(target);
    }

    function resolveTarget(options) {
        if (options && validTarget(options.target)) {
            return options.target;
        }

        if (options && typeof options.target === 'string') {
            var localTarget = options.form && options.form.closest
                ? options.form.closest(options.target)
                : null;

            return localTarget || document.querySelector(options.target);
        }

        if (options && options.mode === 'modal') {
            return options.form && options.form.closest ? options.form.closest('.modal-content, .modal-dialog, .modal') : null;
        }

        if (options && options.mode === 'region') {
            return document.getElementById('pjax-container') || document.querySelector('.content-wrapper') || document.body;
        }

        return document.body;
    }

    function loaderMode(options) {
        return options && options.mode ? options.mode : 'page';
    }

    function begin(options) {
        options = options || {};
        var mode = loaderMode(options);
        var target = resolveTarget(options);
        var node = null;

        if (mode === 'region' && validTarget(target)) {
            target.classList.add('erp-loading-region');
        }

        if (mode !== 'button' && mode !== 'download' && validTarget(target)) {
            node = document.createElement('div');
            node.className = 'erp-interaction-loader erp-interaction-loader--' + mode;
            node.setAttribute('role', 'status');
            node.setAttribute('aria-live', 'polite');
            node.innerHTML = '<span class="erp-interaction-loader__content"><i class="erp-interaction-loader__spinner" aria-hidden="true"></i><span>'
                + escapeHtml(options.message || copy('loading', 'Loading…')) + '</span></span>';
            target.appendChild(node);
        }

        var token = {
            node: node,
            button: options.button || null,
            form: options.form || null,
            mode: mode,
            target: target,
            ended: false
        };

        if (token.button) {
            lockButton(token.button, options.buttonText || options.message || copy('processing', 'Processing…'));
        }

        tokens.push(token);
        return token;
    }

    function end(token) {
        if (!token || token.ended) {
            return;
        }

        token.ended = true;
        if (token.node && token.node.parentNode) {
            token.node.parentNode.removeChild(token.node);
        }
        if (token.button) {
            unlockButton(token.button);
        }
        if (token.form) {
            delete token.form.dataset.erpLoadingPending;
            delete token.form.dataset.erpLoadingToken;
        }
        tokens = tokens.filter(function (item) { return item !== token; });
    }

    function endAll() {
        tokens.slice().forEach(end);
    }

    function buttonLabel(button) {
        if (!button) {
            return '';
        }
        return button.tagName && button.tagName.toLowerCase() === 'input' ? button.value : button.innerHTML;
    }

    function lockButton(button, text) {
        if (!button || button.dataset.erpLoadingLocked === '1') {
            return;
        }
        button.dataset.erpLoadingLocked = '1';
        button.dataset.erpLoadingOriginal = buttonLabel(button);
        button.disabled = true;
        button.classList.add('erp-loading-button');

        if (button.tagName && button.tagName.toLowerCase() === 'input') {
            button.value = text;
            return;
        }

        button.innerHTML = '<i class="erp-loading-button__spinner" aria-hidden="true"></i><span>' + escapeHtml(text) + '</span>';
    }

    function unlockButton(button) {
        if (!button || button.dataset.erpLoadingLocked !== '1') {
            return;
        }
        button.disabled = false;
        button.classList.remove('erp-loading-button');
        if (button.tagName && button.tagName.toLowerCase() === 'input') {
            button.value = button.dataset.erpLoadingOriginal || button.value;
        } else {
            button.innerHTML = button.dataset.erpLoadingOriginal || button.innerHTML;
        }
        delete button.dataset.erpLoadingLocked;
        delete button.dataset.erpLoadingOriginal;
    }

    function formSubmitter(form, event) {
        if (event && event.submitter) {
            return event.submitter;
        }
        return form.querySelector('[type="submit"]:not([disabled])');
    }

    function submissionMessage(form, mode) {
        if (form.getAttribute('data-loading-text')) {
            return form.getAttribute('data-loading-text');
        }
        if (mode === 'download') {
            return copy('exporting', 'Exporting…');
        }
        if ((form.getAttribute('method') || 'get').toLowerCase() === 'get') {
            return copy('searching', 'Loading…');
        }
        return copy('submitting', 'Submitting…');
    }

    function resolveFormMode(form) {
        var requested = form.getAttribute('data-loading');
        if (requested) {
            return requested;
        }
        return 'button';
    }

    function isManagedElsewhere(form) {
        return form.matches('[data-modal-form], [data-product-request-modal-form], [data-batch-edit-form], [pjax-container]');
    }

    function beginForm(form, options) {
        options = options || {};
        if (!form || form.dataset.erpLoadingPending === '1') {
            return null;
        }

        var mode = options.mode || resolveFormMode(form);
        if (mode === 'off' || mode === 'none') {
            return null;
        }

        form.dataset.erpLoadingPending = '1';
        var token = begin({
            form: form,
            button: options.button || formSubmitter(form, options.event),
            mode: mode,
            target: options.target || form.getAttribute('data-loading-target'),
            message: options.message || submissionMessage(form, mode),
            buttonText: options.buttonText || submissionMessage(form, mode)
        });
        form.dataset.erpLoadingToken = String(tokens.indexOf(token));

        if (mode === 'download') {
            window.setTimeout(function () { end(token); }, 15000);
            window.addEventListener('focus', function restoreDownloadButton() {
                window.removeEventListener('focus', restoreDownloadButton);
                window.setTimeout(function () { end(token); }, 160);
            });
        }

        return token;
    }

    function endForm(form) {
        tokens.slice().forEach(function (token) {
            if (token.form === form) {
                end(token);
            }
        });
    }

    function shouldSkipLink(link, event) {
        if (!link || !link.href || link.getAttribute('data-loading') === 'off' || link.hasAttribute('download')) {
            return true;
        }
        if (event && (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) {
            return true;
        }
        if (link.target && link.target !== '_self') {
            return true;
        }
        if (link.hasAttribute('data-toggle') || link.hasAttribute('data-dismiss')) {
            return true;
        }
        var raw = link.getAttribute('href') || '';
        if (!raw || raw === '#' || raw.charAt(0) === '#' || raw.indexOf('javascript:') === 0) {
            return true;
        }
        try {
            var url = new window.URL(link.href, window.location.origin);
            return url.origin !== window.location.origin;
        } catch (error) {
            return true;
        }
    }

    function loadingSource(element) {
        return element.closest ? (element.closest('[data-loading]') || element) : element;
    }

    function linkMode(link) {
        return loadingSource(link).getAttribute('data-loading') || 'none';
    }

    function handleNativeSubmit(event) {
        var form = event.target;
        if (!form || !form.matches || isManagedElsewhere(form) || form.getAttribute('data-loading') === 'off') {
            return;
        }
        if (form.id === 'admin-login-form' && window.ErpLoginSubmitGuard) {
            return;
        }
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            return;
        }
        if (form.dataset.erpLoadingPending === '1') {
            event.preventDefault();
            return;
        }

        // Inline confirmations and page modules can cancel a submit after this
        // listener runs.  Delay the lock until every listener has made that decision.
        form.dataset.erpLoadingPending = 'checking';
        Promise.resolve().then(function () {
            if (event.defaultPrevented) {
                delete form.dataset.erpLoadingPending;
                return;
            }
            if (form.dataset.erpLoadingPending === 'checking') {
                delete form.dataset.erpLoadingPending;
                beginForm(form, { event: event });
            }
        });
    }

    function handleAutoSubmit(event) {
        var select = event.target;
        if (!select || !select.matches || !select.matches('select[onchange*="form.submit"]')) {
            return;
        }
        var form = select.form;
        if (form && !form.dataset.erpLoadingPending) {
            beginForm(form, { mode: resolveFormMode(form), button: null });
        }
    }

    document.addEventListener('submit', handleNativeSubmit, true);
    document.addEventListener('change', handleAutoSubmit, true);
    document.addEventListener('click', function (event) {
        var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (shouldSkipLink(link, event)) {
            return;
        }

        // Let page-specific handlers run first. Modal editor links can be
        // placed inside a filter form and prevent the native navigation;
        // starting that form's regional loader before their handler runs
        // leaves an orphaned overlay after the modal is closed.
        Promise.resolve().then(function () {
            if (event.defaultPrevented || !document.documentElement.contains(link)) {
                return;
            }

            // Ladmin already turns ordinary admin links into PJAX requests.
            if (window.jQuery && window.jQuery.pjax && document.getElementById('app')) {
                return;
            }

            var mode = linkMode(link);
            if (mode === 'off' || mode === 'none') {
                return;
            }
            if (mode === 'download') {
                lockButton(link, copy('exporting', 'Exporting…'));
                window.setTimeout(function () { unlockButton(link); }, 15000);
                return;
            }
            begin({
                mode: mode,
                button: mode === 'button' ? link : null,
                target: loadingSource(link).getAttribute('data-loading-target'),
                message: copy('loading', 'Loading…')
            });
        });
    }, true);
    window.addEventListener('pageshow', endAll);

    window.ErpInteractionLoading = {
        begin: begin,
        end: end,
        endAll: endAll,
        beginForm: beginForm,
        endForm: endForm,
        lockButton: lockButton,
        unlockButton: unlockButton
    };
})(window, document, window.jQuery);
