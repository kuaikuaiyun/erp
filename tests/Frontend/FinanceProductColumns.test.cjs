const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('public/assets/erp/finance-settlement-columns.js', 'utf8');
const initialization = source.slice(0, source.indexOf('    let templates')) + '    return {display, fields};\n})();';

function columns(productField, saved) {
    const inputs = ['reference', productField].map(value => ({ value, dataset: { label: value } }));
    const dialog = {
        dataset: { newDisplayFields: productField, preferenceKey: 'columns' },
        querySelectorAll: () => inputs,
        querySelector: () => ({}),
        hasAttribute: () => false,
    };
    return JSON.parse(JSON.stringify(vm.runInNewContext(initialization, {
        document: { getElementById: id => id === 'settlement-column-settings' ? dialog : {}, querySelector: () => ({}) },
        localStorage: { getItem: () => JSON.stringify(saved) },
    })));
}

for (const field of ['product_name', '54']) {
    test(`introduces ${field} to existing display and export preferences`, () => {
        assert.deepEqual(columns(field, { display: ['reference'], fields: ['reference'] }), {
            display: ['reference', field], fields: ['reference', field],
        });
    });
    test(`keeps ${field} hidden after the user explicitly hides it`, () => {
        assert.deepEqual(columns(field, {
            display: ['reference'], fields: ['reference'], introducedDisplayFields: [field],
        }), { display: ['reference'], fields: ['reference'] });
    });
}
