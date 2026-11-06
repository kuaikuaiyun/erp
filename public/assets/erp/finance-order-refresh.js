(function () {
    'use strict';
    var open = document.getElementById('finance-order-refresh-open');
    var dialog = document.getElementById('finance-order-refresh-dialog');
    if (!open || !dialog) return;
    var form = dialog.querySelector('form');
    var submit = form.querySelector('[type="submit"]');
    var account = form.querySelector('[data-order-refresh-accounts]');
    var checks = Array.from(account.querySelectorAll('[name="accounts[]"]'));
    var all = account.querySelector('[data-order-refresh-all]');
    function selection() {
        var count = checks.filter(function (c) { return c.checked; }).length;
        all.checked = checks.length > 0 && count === checks.length;
        all.indeterminate = count > 0 && count < checks.length;
    }
    all.addEventListener('change', function () { checks.forEach(function (c) { c.checked = all.checked; }); selection(); });
    checks.forEach(function (c) { c.addEventListener('change', selection); });
    var message = dialog.querySelector('[data-order-refresh-message]');
    var results = dialog.querySelector('[data-order-refresh-results]');
    var busy = false, submitting = false, polling = false, restoring = false, timer = null, batch = null;
    var failedSubmissions = [];
    var labels = {queued:'排队中', running:'同步中', success:'成功', failed:'失败', skipped:'已跳过'};
    function controls() {
        submit.disabled = submitting || restoring; account.disabled = submitting;
        open.innerHTML = '<i class="fa fa-refresh" aria-hidden="true"></i> '+(busy ? '订单更新中（查看进度）' : '刷新订单');
    }
    function render(rows) {
        results.hidden = false;
        var tbody = results.querySelector('tbody');
        tbody.replaceChildren();
        rows.concat(failedSubmissions).forEach(function (row) {
            var tr = document.createElement('tr');
            [row.account, labels[row.status] || row.status, row.status === 'queued' ? '0（尚未开始）' : String(row.count || 0), row.error || row.warning || '—'].forEach(function (text) {
                var td = document.createElement('td'); td.textContent = text; tr.appendChild(td);
            });
            tr.style.color = row.status === 'failed' ? '#d9534f' : (row.status === 'success' ? '#00876b' : '');
            tbody.appendChild(tr);
        });
        var succeeded = rows.filter(function (r) { return r.status === 'success'; }).length;
        var failed = rows.filter(function (r) { return r.status === 'failed'; }).length + failedSubmissions.length;
        var pending = rows.filter(function (r) { return r.status === 'queued' || r.status === 'running'; }).length;
        var count = rows.reduce(function (sum, row) { return sum + Number(row.count || 0); }, 0);
        message.textContent = '累计已处理 '+count+' 条；成功 '+succeeded+' 个，失败 '+failed+' 个，等待或同步中 '+pending+' 个。';
        message.style.color = failed ? '#d9534f' : '#31516b';
    }
    async function poll() {
        if (!batch || !dialog.open || polling) return;
        polling = true;
        try {
            var response = await fetch(results.dataset.statusUrl.replace('__batch__', batch), {
                credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json', 'X-Requested-With':'XMLHttpRequest'}
            });
            if (!response.ok) {
                if ([401,403,404,419].includes(response.status)) {
                    busy = false; controls();
                    message.textContent = '无法读取刷新记录，可能已过期或权限已变更，请刷新页面确认。';
                    batch = null; return;
                }
                throw new Error('status');
            }
            var data = await response.json();
            failedSubmissions = [];
            render(data.results || []);
            busy = !data.done; controls();
            if (busy && dialog.open) timer = setTimeout(poll, 2000);
            else if (data.done) document.dispatchEvent(new CustomEvent('finance:transactions-synced'));
        } catch (error) {
            message.textContent = '进度暂时读取失败，正在重试；这不代表订单同步失败。';
            if (dialog.open) timer = setTimeout(poll, 5000);
        } finally { polling = false; }
    }
    open.addEventListener('click', function () {
        dialog.showModal();
        if (batch) { clearTimeout(timer); poll(); } else { restore(); }
    });
    async function restore() {
        if (restoring || submitting || batch) return;
        restoring = true; controls();
        try {
            var response = await fetch(form.action, {credentials:'same-origin', cache:'no-store',
                headers:{Accept:'application/json', 'X-Requested-With':'XMLHttpRequest'}});
            if (!response.ok) throw new Error('restore');
            var data = await response.json();
            if (data.batch && !submitting && !batch) {
                batch = data.batch;
                failedSubmissions = [];
                render(data.results || []);
                busy = !data.done;
                if (dialog.open && busy) poll();
            }
        } catch (error) {
            if (dialog.open) message.textContent = '上次刷新记录暂时读取失败，请关闭后重试；后台更新不会因此取消。';
        } finally { restoring = false; controls(); }
    }
    restore();
    dialog.querySelector('[data-order-refresh-close]').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('close', function () { clearTimeout(timer); });
    window.addEventListener('pagehide', function () { clearTimeout(timer); });
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (submitting || restoring || !form.reportValidity()) return;
        if (!checks.some(function (c) { return c.checked; })) { message.textContent = '请至少选择一个 PayPal 账户。'; return; }
        var body = new FormData(form);
        busy = true; submitting = true; controls(); clearTimeout(timer);
        message.textContent = '正在提交订单刷新…';
        try {
            var response = await fetch(form.action, {method:'POST', body:body, credentials:'same-origin',
                headers:{Accept:'application/json', 'X-Requested-With':'XMLHttpRequest'}});
            var data = await response.json();
            if (data.batch) {
                batch = data.batch;
                failedSubmissions = (data.failed || []).map(function (name) {
                    return {account:name, status:'failed', count:0, error:'后台任务提交失败，请稍后重试。'};
                });
                render(data.results || []);
                await poll();
            } else {
                busy = false; controls();
                message.textContent = data.errors ? Object.values(data.errors).flat().join(' ') : (data.message || '提交失败，请稍后重试。');
            }
        } catch (error) {
            busy = false; controls();
            message.textContent = '无法确认提交结果，请稍后重试；同一账户不会重复排队。';
        } finally { submitting = false; controls(); }
    });
})();
