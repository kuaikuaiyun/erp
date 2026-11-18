const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class Element {
    constructor(html = '') {
        this.innerHTML = html;
        this.dataset = {};
        this.listeners = {};
        this.attributes = {};
        this.children = [];
        this.disabled = false;
    }
    addEventListener(name, listener) { this.listeners[name] = listener; }
    setAttribute(name, value) { this.attributes[name] = value; }
    removeAttribute(name) { delete this.attributes[name]; }
    replaceChildren(...children) { this.children = children; this.innerHTML = ''; }
    append(...children) { this.children.push(...children); }
    remove() {}
}

function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}

function environment(fetchResult) {
    const status = new Element();
    const buttons = [new Element('导出 Excel'), new Element('导出 Excel')];
    buttons.forEach(button => { button.dataset.renewalExportUrl = '/renewal-analysis/export?initial_store=Test'; });
    const page = new Element();
    page.dataset.renewalExportMessages = JSON.stringify({
        exporting: '正在导出…', error: '导出失败，请重试。', done: '文件已生成，下载已开始。', filename: '默认导出.xlsx'
    });
    page.querySelector = () => status;
    const downloads = [], requests = [], revoked = [];
    const document = {
        querySelector: () => page,
        querySelectorAll: () => buttons,
        getElementById: () => null,
        createTextNode: text => ({ textContent: text }),
        createElement: tag => {
            const element = new Element();
            if (tag === 'a') element.click = () => downloads.push(element.download);
            return element;
        },
        body: { appendChild() {} }
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/assets/erp/payment-renewal-details.js'), 'utf8'), {
        document, window: {}, fetch: (url, options) => { requests.push({ url, options }); return fetchResult; },
        URL: { createObjectURL: () => 'blob:test', revokeObjectURL: value => revoked.push(value) },
        setTimeout: callback => callback()
    });
    return { buttons, status, downloads, requests, revoked };
}

function response(overrides = {}) {
    return {
        ok: true, redirected: false,
        headers: { get: name => name === 'Content-Type' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : "attachment; filename*=UTF-8''%E8%AE%A2%E5%8D%95.xlsx" },
        blob: async () => ({}), ...overrides
    };
}

test('both export buttons stay disabled until the full file arrives, block duplicate requests and then restore', async () => {
    const network = deferred(), file = deferred();
    const state = environment(network.promise);
    const pending = state.buttons[0].listeners.click();
    assert.ok(state.buttons.every(button => button.disabled && button.attributes['aria-busy'] === 'true'));
    assert.equal(state.buttons[0].children[1].textContent, ' 正在导出…');
    await state.buttons[1].listeners.click();
    assert.equal(state.requests.length, 1);
    network.resolve(response({ blob: () => file.promise }));
    await new Promise(resolve => setImmediate(resolve));
    assert.ok(state.buttons.every(button => button.disabled));
    file.resolve({});
    await pending;
    assert.ok(state.buttons.every(button => !button.disabled && button.innerHTML === '导出 Excel'));
    assert.ok(state.buttons.every(button => !('aria-busy' in button.attributes)));
    assert.deepEqual(state.downloads, ['订单.xlsx']);
    assert.equal(state.status.textContent, '文件已生成，下载已开始。');
    assert.equal(state.revoked.length, 1);
});

test('HTTP validation errors restore both buttons and retain the localized error message', async () => {
    const state = environment(Promise.resolve(response({ ok: false, json: async () => ({ errors: { date: ['日期不正确'] } }) })));
    await state.buttons[0].listeners.click();
    assert.ok(state.buttons.every(button => !button.disabled && button.innerHTML === '导出 Excel'));
    assert.equal(state.status.textContent, '日期不正确');
    assert.equal(state.downloads.length, 0);
});

test('network failures restore buttons and use the translated fallback', async () => {
    const network = deferred();
    const state = environment(network.promise);
    const pending = state.buttons[0].listeners.click();
    network.reject(new TypeError('Failed to fetch'));
    await pending;
    assert.ok(state.buttons.every(button => !button.disabled && button.innerHTML === '导出 Excel'));
    assert.equal(state.status.textContent, '导出失败，请重试。');
});

test('login redirects and HTML responses are not downloaded as Excel', async () => {
    for (const bad of [
        response({ redirected: true, json: async () => ({}) }),
        response({ headers: { get: () => 'text/html' } })
    ]) {
        const state = environment(Promise.resolve(bad));
        await state.buttons[0].listeners.click();
        assert.ok(state.buttons.every(button => !button.disabled));
        assert.equal(state.downloads.length, 0);
        assert.equal(state.status.textContent, '导出失败，请重试。');
    }
});

test('an interrupted response body restores buttons instead of reporting success', async () => {
    const state = environment(Promise.resolve(response({ blob: async () => { throw new Error('connection reset'); } })));
    await state.buttons[0].listeners.click();
    assert.ok(state.buttons.every(button => !button.disabled));
    assert.equal(state.downloads.length, 0);
    assert.equal(state.status.textContent, '导出失败，请重试。');
});
