(function () {
    'use strict';
    async function run(plan, progress) {
        for (var index = 0; index < plan.months.length; index++) {
            var month = plan.months[index];
            progress('正在计算 ' + month.month + '（' + (index + 1) + '/' + plan.months.length + '）…');
            var response = await fetch(month.url, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
            var data = (response.headers.get('Content-Type') || '').includes('application/json') ? await response.json() : {};
            if (!response.ok || data.state !== 'ready') throw new Error(data.message || '该月份计算失败，请重试。');
        }
        return plan.resume_url;
    }
    window.financePrepareReport = async function (url, progress) {
        var target = new URL(url, window.location.href);
        if (!/\/finance\/reports\/(summary|losses)$/.test(target.pathname)) return url;
        if (target.searchParams.get('download_missing') === '1') return url;
        target.searchParams.set('_finance_plan', '1');
        var response = await fetch(target.href, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
        if (!(response.headers.get('Content-Type') || '').includes('application/json')) return url;
        var plan = await response.json();
        if (!response.ok) throw new Error(plan.message || '查询失败，请重试。');
        if (!Array.isArray(plan.months)) return url;
        return run(plan, progress || function () {});
    };
    var payload = document.querySelector('[data-finance-source-plan]');
    if (!payload) return;
    var status = document.querySelector('[data-finance-source-progress]');
    var retry = document.querySelector('[data-finance-source-retry]');
    var plan = JSON.parse(payload.textContent);
    async function start() {
        retry.hidden = true;
        try {
            var url = await run(plan, function (message) { status.textContent = message; });
            status.textContent = '计算完成，正在显示结果…';
            window.location.replace(url);
        } catch (error) {
            status.textContent = error.message || '查询失败，请重试。';
            retry.hidden = false;
        }
    }
    retry.addEventListener('click', start);
    start();
}());
