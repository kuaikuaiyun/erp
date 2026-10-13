(function (window, document) {
    'use strict';

    var defaultOptions = {
        success: { timeOut: 3500, extendedTimeOut: 800 },
        info: { timeOut: 4500, extendedTimeOut: 1200 },
        warning: { timeOut: 6500, extendedTimeOut: 1800 },
        error: { timeOut: 8000, extendedTimeOut: 2400 }
    };

    function text(value) {
        return value === null || typeof value === 'undefined' ? '' : String(value);
    }

    function typeOf(value) {
        value = text(value).toLowerCase();

        return Object.prototype.hasOwnProperty.call(defaultOptions, value) ? value : 'info';
    }

    function number(value, fallback) {
        value = Number(value);

        return Number.isFinite(value) && value >= 0 ? value : fallback;
    }

    function optionsFor(notification) {
        var type = typeOf(notification.type);
        var options = {
            timeOut: defaultOptions[type].timeOut,
            extendedTimeOut: defaultOptions[type].extendedTimeOut
        };
        var custom = notification.options && typeof notification.options === 'object'
            ? notification.options
            : {};

        Object.keys(custom).forEach(function (key) {
            options[key] = custom[key];
        });

        options.timeOut = number(options.timeOut, defaultOptions[type].timeOut);
        options.extendedTimeOut = number(options.extendedTimeOut, defaultOptions[type].extendedTimeOut);

        return options;
    }

    function container() {
        var node = document.getElementById('erp-admin-notifications');

        if (node) {
            return node;
        }

        node = document.createElement('div');
        node.id = 'erp-admin-notifications';
        node.setAttribute('aria-live', 'polite');
        node.setAttribute('aria-relevant', 'additions');
        document.body.appendChild(node);

        return node;
    }

    function remove(node) {
        if (!node || node.getAttribute('data-notification-closing') === '1') {
            return;
        }

        node.setAttribute('data-notification-closing', '1');
        node.classList.add('is-leaving');
        window.setTimeout(function () {
            if (node.parentNode) {
                node.parentNode.removeChild(node);
            }
        }, 180);
    }

    function show(notification) {
        var type = typeOf(notification.type);
        var options = optionsFor(notification);
        var node = document.createElement('article');
        var closeButton = document.createElement('button');
        var title = text(notification.title);
        var message = text(notification.message);
        var timer;
        var progressFrame = null;
        var startedAt;
        var remaining = options.timeOut;

        function now() {
            return window.performance && typeof window.performance.now === 'function'
                ? window.performance.now()
                : Date.now();
        }

        function cancelProgressFrame() {
            if (progressFrame === null) {
                return;
            }

            if (typeof window.cancelAnimationFrame === 'function') {
                window.cancelAnimationFrame(progressFrame);
            } else {
                window.clearTimeout(progressFrame);
            }

            progressFrame = null;
        }

        function requestProgressFrame(callback) {
            if (typeof window.requestAnimationFrame === 'function') {
                return window.requestAnimationFrame(callback);
            }

            return window.setTimeout(callback, 16);
        }

        node.className = 'erp-admin-notification erp-admin-notification-' + type;
        node.setAttribute('role', type === 'error' || type === 'warning' ? 'alert' : 'status');
        node.setAttribute('data-notification-closing', '0');

        closeButton.type = 'button';
        closeButton.className = 'erp-admin-notification-close';
        closeButton.setAttribute('aria-label', '×');
        closeButton.innerHTML = '<i class="fa fa-times" aria-hidden="true"></i>';
        closeButton.addEventListener('click', function () {
            window.clearTimeout(timer);
            cancelProgressFrame();
            remove(node);
        });
        node.appendChild(closeButton);

        if (title) {
            var titleNode = document.createElement('div');
            titleNode.className = 'erp-admin-notification-title';
            titleNode.textContent = title;
            node.appendChild(titleNode);
        }

        if (message) {
            var messageNode = document.createElement('div');
            messageNode.className = 'erp-admin-notification-message';
            messageNode.textContent = message;
            node.appendChild(messageNode);
        }

        var progress = document.createElement('div');
        progress.className = 'erp-admin-notification-progress';
        node.appendChild(progress);
        container().insertBefore(node, container().firstChild);

        function schedule() {
            window.clearTimeout(timer);
            cancelProgressFrame();

            if (remaining <= 0) {
                progress.style.transform = 'scaleX(0)';
                return;
            }

            startedAt = now();
            progress.style.transform = 'scaleX(1)';
            timer = window.setTimeout(function () {
                cancelProgressFrame();
                progress.style.transform = 'scaleX(0)';
                remove(node);
            }, remaining);

            function drawProgress() {
                progressFrame = null;

                var elapsed = now() - startedAt;
                var scale = Math.max(0, 1 - (elapsed / remaining));
                progress.style.transform = 'scaleX(' + scale + ')';

                if (scale > 0) {
                    progressFrame = requestProgressFrame(drawProgress);
                }
            }

            progressFrame = requestProgressFrame(drawProgress);
        }

        node.addEventListener('mouseenter', function () {
            if (remaining <= 0) {
                return;
            }

            remaining = Math.max(0, remaining - (now() - startedAt));
            window.clearTimeout(timer);
            cancelProgressFrame();
        });
        node.addEventListener('mouseleave', function () {
            if (remaining > 0) {
                remaining = options.extendedTimeOut;
                schedule();
            }
        });

        schedule();
    }

    function notify(notifications) {
        if (!Array.isArray(notifications)) {
            notifications = [notifications];
        }

        notifications.filter(function (notification) {
            return notification && typeof notification === 'object';
        }).forEach(show);
    }

    window.ErpAdminNotifier = { notify: notify };
})(window, document);


