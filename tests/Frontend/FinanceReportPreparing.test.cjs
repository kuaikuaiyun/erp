const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('public/assets/erp/finance-report-preparing.js', 'utf8');

test('overview tasks poll their own run without starting monthly HTTP calculations', async () => {
    const calls = [];
    const window = { location: { href: 'http://caiwu.kky.com/finance/reports/losses' } };
    const plans = [
        { waiting: true, background: 'overview', state: 'pending', message: '排队中', months: [], poll_url: '/poll' },
        { waiting: true, background: 'overview', state: 'running', message: '计算中', months: [], poll_url: '/poll' },
        { waiting: false, months: [], resume_url: '/ready?_overview_run=abc' },
    ];
    vm.runInNewContext(source, { window, URL, document: { querySelector: () => null },
        setTimeout: callback => callback(), fetch: async url => {
            calls.push(url);
            return { ok: true, headers: { get: () => 'application/json' }, json: async () => plans.shift() };
        } });
    assert.equal(await window.financePrepareReport(window.location.href), '/ready?_overview_run=abc');
    assert.deepEqual(calls.slice(1), ['/poll', '/poll']);
});

test('failed overview tasks expose the backend failure instead of polling indefinitely', async () => {
    const window = { location: { href: 'http://caiwu.kky.com/finance/reports/summary' } };
    let calls = 0;
    vm.runInNewContext(source, { window, URL, document: { querySelector: () => null },
        fetch: async () => { calls++; return { ok: true, headers: { get: () => 'application/json' },
            json: async () => ({ waiting: true, background: 'overview', state: 'failed', message: '后台计算失败', months: [] }) }; } });
    await assert.rejects(window.financePrepareReport(window.location.href), /后台计算失败/);
    assert.equal(calls, 1);
});

function harness(fail = false) {
    const calls = [];
    const window = { location: { href: 'http://caiwu.kky.com/finance/reports/losses' } };
    vm.runInNewContext(source, { window, URL, document: { querySelector: () => null },
        fetch: async (url) => {
            calls.push(url);
            const plan = new URL(url).searchParams.has('_finance_plan');
            return { ok: plan || !fail, headers: { get: () => 'application/json' },
                json: async () => plan ? { months: [{ month: '2026-09', url: 'http://caiwu.kky.com/month/09' },
                    { month: '2026-10', url: 'http://caiwu.kky.com/month/10' }], resume_url: 'http://caiwu.kky.com/ready' }
                    : (fail ? { message: '计算失败' } : { state: 'ready' }) };
        } });
    return { calls, prepare: window.financePrepareReport };
}

test('prepares each month sequentially before returning the report/download URL', async () => {
    const { calls, prepare } = harness();
    const progress = [];
    assert.equal(await prepare('http://caiwu.kky.com/finance/reports/losses?download=1', message => progress.push(message)), 'http://caiwu.kky.com/ready');
    assert.equal(calls.length, 3);
    assert.equal(calls[1], 'http://caiwu.kky.com/month/09');
    assert.equal(calls[2], 'http://caiwu.kky.com/month/10');
    assert.equal(progress.length, 2);
});

test('a failed month stops the range and exposes its error', async () => {
    const { calls, prepare } = harness(true);
    await assert.rejects(prepare('http://caiwu.kky.com/finance/reports/losses'), /计算失败/);
    assert.equal(calls.length, 2);
});

test('does not add requests to other financial pages', async () => {
    const { calls, prepare } = harness();
    const url = 'http://caiwu.kky.com/finance/reports/paypal-settlement';
    assert.equal(await prepare(url), url);
    assert.equal(calls.length, 0);
});

test('waits for the background sync before preparing source parts', async () => {
    const calls = [];
    const window = { location: { href: 'http://caiwu.kky.com/finance/reports/losses' } };
    const plans = [
        { waiting: true, months: [], poll_url: '/poll' },
        { waiting: true, months: [], poll_url: '/poll' },
        { months: [{ month: '2026-11', url: '/part' }], resume_url: '/ready' },
    ];
    vm.runInNewContext(source, { window, URL, document: { querySelector: () => null },
        setTimeout: callback => callback(), fetch: async url => {
            calls.push(url);
            return { ok: true, headers: { get: () => 'application/json' },
                json: async () => url === '/part' ? { state: 'ready' } : plans.shift() };
        } });
    assert.equal(await window.financePrepareReport(window.location.href), '/ready');
    assert.deepEqual(calls.slice(1), ['/poll', '/poll', '/part']);
});

test('a failed background sync poll reports failure without computing source parts', async () => {
    let calls = 0;
    const window = { location: { href: 'http://caiwu.kky.com/finance/reports/losses' } };
    vm.runInNewContext(source, { window, URL, document: { querySelector: () => null },
        setTimeout: callback => callback(), fetch: async () => ({ ok: ++calls === 1,
            headers: { get: () => 'application/json' },
            json: async () => ({ waiting: true, months: [], poll_url: '/poll' }) }) });
    await assert.rejects(window.financePrepareReport(window.location.href), /查询失败/);
    assert.equal(calls, 2);
});

test('retry resumes at the failed part without repeating successful requests', async () => {
    const handlers = {};
    const calls = [];
    const navigations = [];
    const status = {};
    const retry = { addEventListener: (name, handler) => { handlers[name] = handler; } };
    let failSecond = true;
    let finishFirst;
    const first = new Promise(resolve => { finishFirst = resolve; });
    const plan = { concurrency: 2, months: [{ month: '09 01—07', url: '/part/1' }, { month: '09 08—14', url: '/part/2' },
        { month: '09 15—21', url: '/part/3' }], resume_url: '/report/complete' };
    const document = { querySelector(selector) {
        if (selector === '[data-finance-source-plan]') return { textContent: JSON.stringify(plan) };
        if (selector === '[data-finance-source-progress]') return status;
        if (selector === '[data-finance-source-retry]') return retry;
    } };
    const window = { location: { replace: url => navigations.push(url) } };
    const fetch = async url => {
        calls.push(url);
        if (url === '/part/1') await first;
        const failed = url === '/part/2' && failSecond;
        return { ok: !failed, headers: { get: () => 'application/json' },
            json: async () => failed ? { message: '暂时失败' } : { state: 'ready' } };
    };
    vm.runInNewContext(source, { document, window, fetch, URL });
    // A failure cannot enable retry while the other request is still running.
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(retry.hidden, true);
    assert.deepEqual(navigations, []);
    finishFirst();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(status.textContent, '暂时失败');
    assert.equal(retry.hidden, false);
    assert.deepEqual(calls, ['/part/1', '/part/2']);
    assert.deepEqual(navigations, []);
    failSecond = false;
    await handlers.click();
    assert.deepEqual(calls, ['/part/1', '/part/2', '/part/2', '/part/3']);
    assert.deepEqual(navigations, ['/report/complete']);
});

test('parallel preparation is bounded and does not finish before every part succeeds', async () => {
    const pending = [];
    const calls = [];
    const plan = { concurrency: 99, months: [1, 2, 3].map(id => ({ month: String(id), url: '/part/' + id })),
        resume_url: '/complete' };
    const window = { location: { href: 'http://caiwu.kky.com/finance/reports/losses' } };
    vm.runInNewContext(source, { document: { querySelector: () => null }, window, URL, fetch: async url => {
        if (url.includes('_finance_plan')) return { ok: true, headers: { get: () => 'application/json' }, json: async () => plan };
        calls.push(url);
        return await new Promise(resolve => pending.push(() => resolve({ ok: true, headers: { get: () => 'application/json' },
            json: async () => ({ state: 'ready' }) })));
    } });
    let finished = false;
    const report = window.financePrepareReport(window.location.href).then(url => { finished = true; return url; });
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(calls, ['/part/1', '/part/2']);
    pending.shift()();
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(calls, ['/part/1', '/part/2', '/part/3']);
    assert.equal(finished, false);
    pending.shift()();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(finished, false);
    pending.shift()();
    assert.equal(await report, '/complete');
});
