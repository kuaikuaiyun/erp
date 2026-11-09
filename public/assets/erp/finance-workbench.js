(function () {
    'use strict';
    var root = document.getElementById('finance-workbench');
    if (!root) return;
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-balance-tab]'));
    function activate(tab) {
        var name = tab.getAttribute('data-balance-tab');
        tabs.forEach(function (button) {
            var active = button === tab;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', String(active));
            button.tabIndex = active ? 0 : -1;
            document.getElementById(button.getAttribute('aria-controls')).hidden = !active;
        });
        root.querySelectorAll('[data-balance-tab-input]').forEach(function (input) { input.value = name; });
        var url = new URL(window.location.href);
        url.searchParams.set('balance_tab', name);
        window.history.replaceState(window.history.state, '', url);
    }
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () { activate(tab); });
        tab.addEventListener('keydown', function (event) {
            var next;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = tabs.length - 1;
            if (next === undefined) return;
            event.preventDefault();
            activate(tabs[next]);
            tabs[next].focus();
        });
    });

    var dialog = document.getElementById('finance-sync-logs');
    if (!dialog) return;
    var content = dialog.querySelector('[data-sync-log-content]');
    var status = dialog.querySelector('[data-sync-log-status]');
    var retry = dialog.querySelector('[data-sync-log-retry]');
    var request = null;
    var pageUrl;

    async function loadSyncLogs(url, focusTable) {
        if (request) return;
        var controller = new AbortController();
        request = controller;
        pageUrl = url;
        status.textContent = '正在加载同步记录…';
        retry.hidden = true;
        content.setAttribute('aria-busy', 'true');
        try {
            var response = await fetch(url, {signal: controller.signal});
            if (!response.ok || response.redirected) throw new Error('Sync logs unavailable');
            var html = await response.text();
            if (controller.signal.aborted || !dialog.open) return;
            content.innerHTML = html;
            status.textContent = '';
            if (focusTable) content.querySelector('.finance-table-wrap').focus();
        } catch (error) {
            if (controller.signal.aborted || !dialog.open) return;
            status.textContent = '同步记录加载失败，请重试。';
            retry.hidden = false;
            if (focusTable) retry.focus();
        } finally {
            if (request === controller) {
                request = null;
                content.removeAttribute('aria-busy');
            }
        }
    }

    root.querySelectorAll('[data-open-sync-logs]').forEach(function (button) {
        button.addEventListener('click', function () {
            content.replaceChildren();
            dialog.showModal();
            loadSyncLogs(dialog.dataset.url, false);
        });
    });
    content.addEventListener('click', function (event) {
        var link = event.target.closest('[data-sync-log-page]');
        if (!link) return;
        event.preventDefault();
        loadSyncLogs(link.href, true);
    });
    retry.addEventListener('click', function () { loadSyncLogs(pageUrl, true); });
    dialog.addEventListener('close', function () {
        if (request) request.abort();
        request = null;
        content.removeAttribute('aria-busy');
    });
})();
