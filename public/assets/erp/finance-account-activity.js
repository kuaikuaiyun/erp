(function () {
    'use strict';
    var modal = document.getElementById('finance-account-activity');
    if (!modal) return;
    var accounts = JSON.parse(document.getElementById('finance-payment-account-activities').textContent);
    var copy = JSON.parse(document.getElementById('finance-activity-copy').textContent);
    var report = modal.querySelector('#finance-activity-report');
    var sync = modal.querySelector('#finance-activity-sync');
    var result = modal.querySelector('[data-activity-message]');
    var updated = modal.querySelector('[data-activity-last-updated]');
    var noData = updated.textContent.trim();
    var selectedKey = null;
    var busy = false;

    function showMessage(key, text, ok) {
        if (key !== selectedKey) return;
        result.textContent = text;
        result.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger');
        result.hidden = false;
    }
    function update() {
        var account = accounts[selectedKey];
        if (!account) return;
        report.querySelector('[data-export-submit]').disabled = busy || !account.record_count;
        var date = account.last_updated_at;
        if (date) {
            var parsed = new Date(date.indexOf('T') === -1 ? date.replace(' ', 'T') + 'Z' : date);
            updated.textContent = isNaN(parsed.getTime()) ? date : parsed.toLocaleString('zh-CN', {timeZone: 'Asia/Shanghai', hour12: false}) + ' +08:00';
        } else {
            updated.textContent = noData;
        }
        modal.querySelector('#finance-report-selection-hint').textContent = account.record_count
            ? copy.selection.replace(':account', account.name).replace(':count', account.record_count) : copy.empty;
        if (sync) {
            sync.querySelector('[data-activity-sync-submit]').disabled = busy || !account.sync_allowed;
            modal.querySelector('[data-activity-sync-unavailable]').hidden = account.sync_allowed;
        }
    }
    function open(key) {
        var account = accounts[key];
        if (!account) return;
        selectedKey = key;
        modal.querySelector('#finance-account-activity-title').textContent = account.name + ' · 活动';
        report.reset();
        report.querySelector('[data-activity-report-account]').value = account.account;
        report.querySelector('[data-activity-selected-account]').value = account.account;
        report.elements.to.min = report.elements.from.value;
        if (sync) {
            sync.reset();
            sync.action = account.sync_url;
            sync.querySelector('[data-activity-account-name]').textContent = account.name;
            sync.elements.to.min = sync.elements.from.value;
        }
        result.hidden = true;
        update();
        $(modal).modal('show');
    }
    async function responseError(response) {
        var data = await response.json().catch(function () { return {}; });
        return new Error(data.errors ? Object.values(data.errors).flat().join(' ') : (data.error || data.message || copy.failed));
    }
    async function download(response) {
        var disposition = response.headers.get('Content-Disposition') || '';
        if (!response.ok || !/attachment/i.test(disposition)) throw await responseError(response);
        var encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i);
        var plain = disposition.match(/filename="?([^";]+)"?/i);
        var filename = encoded ? decodeURIComponent(encoded[1]) : (plain ? plain[1] : 'paypal-report.xlsx');
        var url = URL.createObjectURL(await response.blob());
        var link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-payment-account-activity]');
        if (button) open(button.dataset.paymentAccountActivity);
    });
    report.addEventListener('submit', async function (event) {
        if (event.defaultPrevented) return;
        event.preventDefault();
        if (busy || !selectedKey || !accounts[selectedKey].record_count) return;
        var key = selectedKey;
        var query = new URLSearchParams(new FormData(report));
        busy = true;
        update();
        showMessage(key, copy.working, true);
        try {
            var response = await fetch(report.action + '?' + query.toString(), {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            await download(response);
            showMessage(key, copy.downloaded, true);
        } catch (error) {
            showMessage(key, error.message || copy.failed, false);
        } finally {
            busy = false;
            update();
        }
    });
    if (sync) {
        sync.elements.from.addEventListener('change', function () { sync.elements.to.min = sync.elements.from.value; });
        sync.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (busy || !selectedKey || !accounts[selectedKey].sync_allowed) return;
            var key = selectedKey;
            var account = accounts[key];
            var body = new FormData(sync);
            busy = true;
            update();
            showMessage(key, copy.working, true);
            try {
                var response = await fetch(account.sync_url, {method: 'POST', headers: {'Accept': 'application/json'}, body: body, credentials: 'same-origin'});
                if ((response.headers.get('Content-Disposition') || '').match(/attachment/i)) {
                    await download(response);
                    account.record_count = Number(response.headers.get('X-Finance-Record-Count')) || account.record_count;
                    account.last_updated_at = response.headers.get('X-Finance-Last-Updated') || account.last_updated_at;
                    showMessage(key, copy.downloaded, true);
                } else {
                    var data = await response.json();
                    if (typeof data.record_count === 'number') account.record_count = data.record_count;
                    if (Object.prototype.hasOwnProperty.call(data, 'last_updated_at')) account.last_updated_at = data.last_updated_at;
                    if (!response.ok || data.ok !== true) {
                        var details = data.errors ? Object.values(data.errors).flat().join(' ') : (data.error || data.message || copy.failed);
                        if (data.count) details += ' ' + copy.partial.replace(':count', data.count);
                        throw new Error(details);
                    }
                    showMessage(key, copy.synced.replace(':count', data.count), true);
                }
            } catch (error) {
                showMessage(key, error.message || copy.failed, false);
            } finally {
                if (account.record_count) document.dispatchEvent(new CustomEvent('finance:transactions-synced', {detail: {key: key}}));
                busy = false;
                update();
            }
        });
    }
})();
