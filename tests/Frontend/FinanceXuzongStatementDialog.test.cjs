const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('resources/views/portals/finance/partials/xuzong-statement-dialog.blade.php', 'utf8')
    .match(/<script>([\s\S]+?)<\/script>/)[1];

function harness({ rejectSave = false, rejectReload = false } = {}) {
    const handlers = {};
    const status = { textContent: '' };
    const save = {};
    let reloads = 0;
    let readCount = 0;
    const posts = [];
    const body = { querySelector: () => ({}), replaceChildren() {},
        addEventListener: (event, handler) => { handlers[`body:${event}`] = handler; } };
    const back = { addEventListener: (event, handler) => { handlers.back = handler; } };
    const dialog = { querySelector: selector => ({ '[data-statement-body]': body,
        '[data-statement-status]': status, '[data-statement-save]': save, '[data-statement-back]': back })[selector],
        addEventListener: (event, handler) => { handlers[`dialog:${event}`] = handler; },
        showModal() {}, close() { handlers['dialog:close'](); } };
    const document = { getElementById: id => id === 'xuzong-statement-dialog' ? dialog : null,
        addEventListener: (event, handler) => { handlers[`document:${event}`] = handler; } };
    const window = { location: { href: 'http://local/finance?reference=kept', search: '?reference=kept', reload: () => { reloads++; } } };
    const fetch = async (url, options = {}) => {
        if (options.method === 'POST') {
            posts.push(String(url));
            return { ok: !rejectSave,
                json: async () => rejectSave ? { errors: { statement: ['该月份已锁定。'] } } : {} };
        }
        readCount++;
        return { ok: !(rejectReload && readCount > 1), text: async () => '<form id="xuzong-order-edit-form"></form>' };
    };
    vm.runInNewContext(source, { document, window, fetch, URL, URLSearchParams, FormData: class { set() {} } });
    return { status, save, posts, get reloads() { return reloads; },
        open: () => handlers['document:click']({ target: { closest: () => ({ href: '/statement/1' }) }, preventDefault() {} }),
        submit: () => handlers['body:submit']({ target: {
            action: { toString: () => '[object HTMLInputElement]' },
            getAttribute: name => name === 'action' ? '/statement/1' : null,
        }, preventDefault() {} }),
        back: () => handlers.back() };
}

test('saving then returning refreshes the current filtered list', async () => {
    const page = harness();
    await page.open();
    await page.submit();
    assert.deepEqual(page.posts, ['/statement/1']);
    assert.equal(page.status.textContent, '结算明细已更新。');
    assert.equal(page.reloads, 0);
    page.back();
    assert.equal(page.reloads, 1);
});

test('a rejected save keeps the form and exposes the validation reason', async () => {
    const page = harness({ rejectSave: true });
    await page.open();
    await page.submit();
    assert.equal(page.status.textContent, '该月份已锁定。');
    assert.equal(page.save.disabled, false);
    page.back();
    assert.equal(page.reloads, 0);
});

test('a successful save is acknowledged even if reloading the dialog fails', async () => {
    const page = harness({ rejectReload: true });
    await page.open();
    await page.submit();
    assert.match(page.status.textContent, /^已保存/);
    page.back();
    assert.equal(page.reloads, 1);
});
