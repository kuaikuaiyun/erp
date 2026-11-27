(function () {
    'use strict';

    if (window.erpSessionStatusMonitor || !window.fetch || !window.HTMLDialogElement) return;
    window.erpSessionStatusMonitor = true;

    const interval = 30000;
    let timer;
    let checking = false;
    let expired = false;

    function showExpired(data) {
        expired = true;
        const dialog = document.createElement('dialog');
        dialog.className = 'erp-session-expired';
        dialog.setAttribute('aria-labelledby', 'erp-session-expired-message');

        const icon = document.createElement('div');
        icon.className = 'erp-session-expired__icon';
        icon.setAttribute('aria-hidden', 'true');
        const clock = document.createElement('span');
        clock.className = 'erp-session-expired__clock';
        icon.append(clock);

        const title = document.createElement('h2');
        title.id = 'erp-session-expired-message';
        title.textContent = data.title || data.message;
        const button = document.createElement('button');
        button.type = 'button';
        button.autofocus = true;
        button.textContent = data.action;
        button.addEventListener('click', () => location.assign(data.login_url));
        dialog.addEventListener('cancel', event => event.preventDefault());
        document.addEventListener('submit', event => {
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);

        dialog.append(icon, title, button);
        document.body.append(dialog);
        dialog.showModal();
    }

    async function check() {
        clearTimeout(timer);
        if (checking || expired || document.hidden) return;
        checking = true;
        let delay = interval;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch('/auth/session-status', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            if (!response.ok && response.status !== 401) return;
            const data = await response.json();
            if (response.status === 401 && data.state === 'expired') {
                showExpired(data);
            } else if (response.ok && data.state === 'active' && Number.isFinite(data.remaining_seconds)) {
                delay = Math.min(interval, Math.max(1000, data.remaining_seconds * 1000 + 200));
            }
        } catch (_) {
            // A network error does not establish that the session has expired.
        } finally {
            clearTimeout(timeout);
            checking = false;
            if (!expired && !document.hidden) timer = setTimeout(check, delay);
        }
    }

    document.addEventListener('visibilitychange', check);
    window.addEventListener('focus', check);
    window.addEventListener('pageshow', event => { if (event.persisted) check(); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', check, { once: true });
    else check();
}());
