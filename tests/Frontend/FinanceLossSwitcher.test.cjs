const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('public/assets/erp/finance-loss-switcher.js', 'utf8');

function screen() {
    const handlers = {};
    const navigations = [];
    const root = {
        dataset: { lossLoadedScopes: JSON.stringify(['Shopify', 'PayPal', '徐总信用卡']) },
        querySelector(selector) {
            if (selector === '[data-loss-panels]' || selector === '[data-loss-stats]') return { textContent: '[]' };
            if (selector === '[data-loss-categories]') return { textContent: '{}' };
            if (selector === '.loss-selection') return { querySelector: () => ({}) };
            return {};
        },
        addEventListener(name, callback) { handlers[name] = callback; },
    };
    const window = {
        location: { href: 'http://caiwu.kky.com/finance/reports/losses?loss_scope=叶总支付', assign: url => navigations.push(url) },
        addEventListener(name, callback) { handlers[name] = callback; },
    };
    vm.runInNewContext(source, { document: { querySelector: () => root, getElementById: () => null }, window, URL });
    return { handlers, navigations };
}

test('an uncomputed source follows its link instead of showing an empty local table', () => {
    const { handlers } = screen();
    let prevented = false;
    handlers.click({ button: 0, target: { closest: () => ({ href: 'http://caiwu.kky.com/finance/reports/losses?loss_scope=叶总支付' }) },
        preventDefault: () => { prevented = true; } });
    assert.equal(prevented, false);
});

test('history navigation to an uncomputed source requests its data', () => {
    const { handlers, navigations } = screen();
    handlers.popstate();
    assert.equal(navigations.length, 1);
    assert.equal(new URL(navigations[0]).searchParams.get('loss_scope'), '叶总支付');
});
