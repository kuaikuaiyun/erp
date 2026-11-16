(function () {
    'use strict';
    var element = document.getElementById('ding-login-options');
    var frame = document.getElementById('ding-login-frame');
    var status = document.getElementById('ding-login-status');
    if (!element || !frame || !status) return;
    var options = JSON.parse(element.textContent);
    var finished = false;
    function show(message) {
        status.textContent = message;
        status.hidden = false;
    }
    var expiresIn = Math.max(0, options.expiresAt * 1000 - Date.now());
    var expiryTimer = window.setTimeout(function () {
        finished = true;
        frame.hidden = true;
        show(options.expired);
    }, Math.min(expiresIn, 2147483647));
    // Accept messages only from this official frame on the exact DingTalk origin.
    window.addEventListener('message', function (event) {
        if (finished || event.origin !== 'https://login.dingtalk.com' || event.source !== frame.contentWindow) return;
        var data = event.data;
        if (!data || typeof data !== 'object') return;
        if (data.success !== true || typeof data.redirectUrl !== 'string') {
            show(options.failed);
            return;
        }
        try {
            var returned = new URL(data.redirectUrl);
            var target = new URL(options.callback);
            if (returned.origin !== target.origin || returned.pathname !== target.pathname ||
                returned.searchParams.get('state') !== options.state || Date.now() >= options.expiresAt * 1000) return;
            var code = returned.searchParams.get('authCode') || returned.searchParams.get('code');
            if (!code || code.length > 1024) return;
            target.searchParams.set('authCode', code);
            target.searchParams.set('state', options.state);
            finished = true;
            window.clearTimeout(expiryTimer);
            window.location.replace(target.toString());
        } catch (_) {
            show(options.failed);
        }
    });
    frame.addEventListener('error', function () { show(options.failed); });
}());
