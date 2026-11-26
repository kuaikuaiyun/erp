(function () {
    'use strict';

    const options = JSON.parse(document.querySelector('#enterprise-login-options').textContent);
    const form = document.querySelector('#admin-login-form');
    const captchaStatus = document.querySelector('#login-captcha-status');
    const panel = document.querySelector('[data-scan-panel]');
    const dialog = document.querySelector('[data-scan-dialog]');
    let cancelScan;
    let scanTimer;
    let flow;
    let frame;

    function status(element, message) {
        if (!element) return;
        element.textContent = message;
        element.hidden = !message;
    }

    const eye = document.querySelector('[data-login-eye]');
    eye?.addEventListener('click', () => {
        const password = form.elements.password;
        const revealed = password.type === 'password';
        password.type = revealed ? 'text' : 'password';
        eye.setAttribute('aria-pressed', String(revealed));
        eye.setAttribute('aria-label', revealed ? options.hidePassword : options.showPassword);
        eye.querySelector('i').className = revealed ? 'fa fa-eye-slash' : 'fa fa-eye';
    });

    window.erpCaptchaVerified = () => status(captchaStatus, '');
    window.erpCaptchaFailed = () => status(captchaStatus, options.captchaFailed);
    window.erpCaptchaExpired = () => status(captchaStatus, options.captchaExpired);
    window.addEventListener('error', event => {
        if (event.target?.matches?.('[data-captcha-script]')) window.erpCaptchaFailed();
    }, true);
    form?.addEventListener('submit', event => {
        const provider = options.captchaProvider;
        const name = provider === 'turnstile' ? 'cf-turnstile-response'
            : provider === 'recaptcha_v2' ? 'g-recaptcha-response' : null;
        if (name && !form.elements.namedItem(name)?.value) {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (captchaStatus.hidden) status(captchaStatus, options.captchaRequired);
        }
    }, true);

    if (!panel) return;
    const scanStatus = panel.querySelector('[data-scan-status]');
    const frameHost = panel.querySelector('[data-scan-frame]');
    const official = panel.querySelector('[data-scan-official]');
    const retry = panel.querySelector('[data-retry-scan]');

    function stopScan() {
        cancelScan?.abort();
        window.clearTimeout(scanTimer);
        flow = null;
        frame = null;
        frameHost.replaceChildren();
        official.removeAttribute('href');
        official.setAttribute('aria-disabled', 'true');
    }

    async function startScan() {
        stopScan();
        const controller = new AbortController();
        cancelScan = controller;
        retry.disabled = true;
        status(scanStatus, options.scanLoading);

        const timeout = window.setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(panel.dataset.scanUrl, {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            });
            const data = await response.json();
            if (!response.ok) throw Error(data.message || options.scanFailed);
            if (controller.signal.aborted || cancelScan !== controller) return;

            flow = data;
            frame = document.createElement('iframe');
            frame.title = options.scanTitle;
            frame.referrerPolicy = 'no-referrer';
            frame.src = data.frame_url;
            frame.addEventListener('error', () => {
                frameHost.replaceChildren();
                status(scanStatus, options.scanFailed);
            });
            frameHost.replaceChildren(frame);
            official.href = data.authorize_url;
            official.removeAttribute('aria-disabled');
            status(scanStatus, '');
            scanTimer = window.setTimeout(() => {
                stopScan();
                status(scanStatus, options.scanExpired);
            }, Math.max(0, Number(data.expires_at) * 1000 - Date.now()));
        } catch (error) {
            if (cancelScan === controller && (!dialog || dialog.open)) {
                status(scanStatus, error.name === 'AbortError' ? options.scanFailed : error.message || options.scanFailed);
            }
        } finally {
            window.clearTimeout(timeout);
            if (cancelScan === controller) retry.disabled = false;
        }
    }

    window.addEventListener('message', event => {
        if (!flow || event.origin !== 'https://login.dingtalk.com' || event.source !== frame?.contentWindow) return;
        const data = event.data;
        if (!data || typeof data !== 'object') return;
        if (data.success !== true || typeof data.redirectUrl !== 'string') {
            status(scanStatus, options.scanFailed);
            return;
        }
        try {
            const returned = new URL(data.redirectUrl);
            const target = new URL(flow.callback_url);
            if (returned.origin !== target.origin || returned.pathname !== target.pathname
                || returned.searchParams.get('state') !== flow.state || Date.now() >= Number(flow.expires_at) * 1000) return;
            const code = returned.searchParams.get('authCode') || returned.searchParams.get('code');
            if (!code || code.length > 1024) return;
            target.searchParams.set('authCode', code);
            target.searchParams.set('state', flow.state);
            stopScan();
            window.location.replace(target.toString());
        } catch (_) {
            status(scanStatus, options.scanFailed);
        }
    });

    document.querySelector('[data-open-scan]')?.addEventListener('click', () => {
        dialog.showModal();
        startScan();
    });
    document.querySelector('[data-close-scan]')?.addEventListener('click', () => {
        stopScan();
        dialog.close();
    });
    dialog?.addEventListener('close', stopScan);
    retry.addEventListener('click', startScan);
    if (panel.hasAttribute('data-auto-scan')) startScan();
}());
