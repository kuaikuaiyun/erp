(function () {
    'use strict';
    async function run(plan, progress) {
        var waitingSince = Date.now();
        while (plan.waiting) {
            progress('正在同步最新数据，完成后自动显示报表…');
            if (Date.now() - waitingSince > 180000) throw new Error('数据更新仍在进行，请稍后重试。');
            await new Promise(function (resolve) { setTimeout(resolve, 2000); });
            var poll = await fetch(plan.poll_url, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
            if (!poll.ok || !(poll.headers.get('Content-Type') || '').includes('application/json')) throw new Error('查询失败，请重试。');
            plan = await poll.json();
            if (!Array.isArray(plan.months)) throw new Error('查询失败，请重试。');
        }
        var next = 0;
        var completed = plan.months.filter(function (month) { return month.completed; }).length;
        var failure = null;
        async function worker() {
            while (!failure && next < plan.months.length) {
                var month = plan.months[next++];
                if (month.completed) continue;
                progress('正在计算 ' + month.month + '（已完成 ' + completed + '/' + plan.months.length + '）…');
                try {
                    var response = await fetch(month.url, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
                    var data = (response.headers.get('Content-Type') || '').includes('application/json') ? await response.json() : {};
                    if (!response.ok || data.state !== 'ready') throw new Error(data.message || '该月份计算失败，请重试。');
                    month.completed = true;
                    completed++;
                } catch (error) {
                    failure = failure || error;
                }
            }
        }
        // Keep server load bounded. Wait for in-flight parts before enabling
        // retry, so it cannot duplicate a request that is still finishing.
        var concurrency = Math.max(1, Math.min(2, Number(plan.concurrency) || 1));
        await Promise.all(Array.from({length: concurrency}, worker));
        if (failure) throw failure;
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
