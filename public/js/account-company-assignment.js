(function () {
    'use strict';
    if (window.ErpAccountCompanyAssignment) return;
    var active = null;
    function create(root) {
        var options = JSON.parse(root.querySelector('[data-company-options]').textContent);
        var messages = JSON.parse(root.querySelector('[data-company-messages]').textContent);
        var dialog = root.querySelector('[data-company-dialog]');
        var form = root.querySelector('form');
        var choice = root.querySelector('[data-company-choice]');
        var save = root.querySelector('[data-company-submit]');
        var retry = root.querySelector('[data-company-retry]');
        var result = root.querySelector('[data-company-result]');
        var ids = [], busy = false, loading = false, saved = false, generation = 0, controller = null;
        function t(key, values) {
            return String(messages[key] || key).replace(/:([a-z_]+)/g, function (all, key) {
                return values && Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : all;
            });
        }
        function status(message, error) { result.textContent = message; result.hidden = !message; result.dataset.error = error ? '1' : '0'; }
        function controls() {
            form.setAttribute('aria-busy', busy ? 'true' : 'false');
            choice.disabled = busy || loading || saved;
            save.disabled = busy || loading || saved || !choice.value;
            save.textContent = t(busy ? 'saving' : 'save');
            retry.disabled = busy || loading || saved;
        }
        async function request(method, body) {
            var current = new AbortController(), expired = false;
            controller = current;
            var timer = setTimeout(function () { expired = true; current.abort(); }, 30000);
            try {
                var url = new URL(options.url, location.href);
                if (method === 'GET') ids.forEach(function (id) { url.searchParams.append('account_ids[]', id); });
                var response = await fetch(url, { method: method, credentials: 'same-origin', cache: 'no-store', signal: current.signal,
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': options.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: body ? JSON.stringify(body) : undefined });
                if (response.redirected) throw Error(t('expired'));
                var data;
                try { data = await response.json(); } catch (_) { throw Error(t('failed')); }
                if (!response.ok) throw Error(data.errors ? Object.values(data.errors).flat().join('\n') : data.message || t('failed'));
                return data;
            } catch (error) {
                if (expired) throw Error(t('timeout'));
                if (error.name === 'TypeError') throw Error(t('failed'));
                throw error;
            } finally { clearTimeout(timer); }
        }
        async function load() {
            var token = ++generation;
            loading = true; saved = false;
            choice.replaceChildren(new Option(t('choose'), ''));
            root.querySelector('[data-company-accounts]').replaceChildren();
            status(t('loading')); controls();
            try {
                var data = await request('GET');
                if (token !== generation || !root.isConnected) return;
                data.accounts.forEach(function (account) {
                    var item = document.createElement('li'), detail = document.createElement('small');
                    item.textContent = account.name + ' (' + account.username + ') · #' + account.id;
                    detail.textContent = t('current') + '：' + account.company;
                    item.append(detail); root.querySelector('[data-company-accounts]').append(item);
                });
                data.companies.forEach(function (company) { choice.add(new Option(company.name, company.id)); });
                status(data.companies.length ? '' : t('none'));
            } catch (error) { if (token === generation && error.name !== 'AbortError') status(error.message, true); }
            finally { if (token === generation) { loading = false; controls(); } }
        }
        root.querySelectorAll('[data-company-close]').forEach(function (button) {
            button.onclick = function () { if (busy) status(t('busy'), true); else dialog.close(); };
        });
        dialog.addEventListener('cancel', function (event) { if (busy) event.preventDefault(); });
        dialog.addEventListener('close', function () { generation++; if (controller) controller.abort(); });
        choice.onchange = controls;
        retry.onclick = load;
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (busy || loading || saved || !choice.value) return;
            busy = true; controls(); status('');
            var token = generation;
            try {
                var data = await request('POST', { account_ids: ids.slice(), company_id: Number(choice.value) });
                if (token !== generation || !root.isConnected) return;
                data.account_ids.forEach(function (id) {
                    var node = document.querySelector('[data-account-company="' + Number(id) + '"]');
                    if (node) node.textContent = data.company;
                });
                saved = true; status(data.message);
            } catch (error) { if (token === generation && error.name !== 'AbortError') status(error.message, true); }
            finally { busy = false; if (token === generation) controls(); }
        });
        return { root: root, open: function (button) {
            if (busy) return;
            var box = button.closest('.box');
            var checked = box ? Array.from(box.querySelectorAll('table tbody input[type="checkbox"]:checked')) : [];
            ids = Array.from(new Set(checked.map(function (input) { return Number(input.dataset.id || input.value); }).filter(function (id) { return Number.isSafeInteger(id) && id > 0; })));
            if (!ids.length || ids.length > 200) { if (window.toastr) window.toastr.warning(t(ids.length ? 'limit' : 'empty')); else window.alert(t(ids.length ? 'limit' : 'empty')); return; }
            root.querySelector('[data-company-review]').textContent = t('review', { count: ids.length });
            if (!dialog.open) dialog.showModal();
            dialog.scrollTop = 0;
            load();
        }, cancel: function () { generation++; if (controller) controller.abort(); if (dialog.open) dialog.close(); } };
    }
    function placeButton() {
        var root = document.querySelector('[data-account-company-root]');
        // PJAX history may restore a cloned open attribute without a modal top layer.
        if (root && (!active || active.root !== root)) {
            var dialog = root.querySelector('[data-company-dialog]');
            if (dialog.open) dialog.close();
        }
        document.querySelectorAll('[data-account-company-open]').forEach(function (button) {
            var header = button.closest('.box-header');
            if (!header) return;
            var right = header.querySelector('.pull-right');
            if (!right) { right = document.createElement('div'); right.className = 'pull-right'; header.append(right); }
            if (button.parentElement !== right) right.prepend(button);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', placeButton); else placeButton();
    if (window.jQuery) window.jQuery(document).on('pjax:end.accountCompany', placeButton);
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-account-company-open]');
        if (!button) return;
        event.preventDefault();
        var root = document.querySelector('[data-account-company-root]');
        if (!root) return;
        if (!active || active.root !== root) { if (active) active.cancel(); active = create(root); }
        active.open(button);
    });
    if (window.jQuery) window.jQuery(document).on('pjax:beforeReplace.accountCompany', function () { if (active) active.cancel(); });
    window.ErpAccountCompanyAssignment = true;
})();
