const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/erp/finance-report-preparing.js', 'utf8');

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
