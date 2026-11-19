const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('public/assets/erp/finance-snapshot-status.js', 'utf8');

async function poll({ initial = 'pending', response = { state: 'ready' }, editing = false, noticePresent = true } = {}) {
    const scheduled = [];
    const replacements = [];
    const messages = [];
    const message = { replaceChildren: (...args) => messages.push(args) };
    const notice = { dataset: { financeSnapshotStatus: initial, financeSnapshotTime: '', financeSnapshotVersion: '' }, querySelector: () => message };
    const form = { addEventListener() {}, contains: () => editing };
    const document = {
        hidden: false,
        querySelector: (selector) => selector === '[data-finance-snapshot-status]' ? (noticePresent ? notice : null) : selector === 'form[method="get"]' ? form : null,
        addEventListener() {},
        createElement: () => ({}),
    };
    const window = {
        location: { href: 'http://caiwu.kky.com/finance/reports/summary?month=2026-11&view=transactions&report_snapshot=old', replace: (url) => replacements.push(url) },
        setTimeout: (callback) => scheduled.push(callback),
    };
    vm.runInNewContext(source, { document, window, URL, URLSearchParams, FormData: class { *[Symbol.iterator]() { yield ['month', '2026-11']; } }, fetch: async () => ({ ok: true, status: 200, json: async () => response }) });
    if (scheduled.length) await scheduled.shift()();
    return { replacements, messages, scheduled };
}

test('pending transaction page loads ready results without a timestamp', async () => {
    const { replacements } = await poll();
    assert.equal(replacements.length, 1);
    assert.equal(new URL(replacements[0]).searchParams.has('report_snapshot'), false);
});

test('ready page without a new version does not reload repeatedly', async () => {
    const { replacements, scheduled } = await poll({ initial: 'ready' });
    assert.equal(replacements.length, 0);
    assert.equal(scheduled.length, 1);
});

test('editing filters offers a result link instead of discarding edits', async () => {
    const { replacements, messages } = await poll({ editing: true });
    assert.equal(replacements.length, 0);
    assert.equal(messages[0][1].textContent, '查看已生成的结果');
});

test('pending state keeps polling and absent notice is safe', async () => {
    const pending = await poll({ response: { state: 'pending' } });
    assert.equal(pending.replacements.length, 0);
    assert.equal(pending.scheduled.length, 1);
    assert.equal((await poll({ noticePresent: false })).scheduled.length, 0);
});
