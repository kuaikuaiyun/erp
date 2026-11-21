const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

// A minimal DOM fixture for the production script's delegated events and rendering.
class Element {
    constructor(tag = 'div', attrs = {}) {
        this.tagName = tag.toUpperCase();
        this.attrs = attrs;
        this.children = [];
        this.listeners = {};
        this.value = '';
        this.disabled = false;
        this.hidden = false;
        this.willValidate = false;
        this.validity = {valid: true};
        this._text = '';
        this.classList = {
            contains: c => this.className.split(' ').includes(c),
            add: (...c) => { this.className = [...new Set([...this.className.split(' ').filter(Boolean), ...c])].join(' '); },
            remove: (...c) => { this.className = this.className.split(' ').filter(x => !c.includes(x)).join(' '); },
        };
    }
    get name() { return this.attrs.name || ''; }
    get type() { return this.attrs.type || ''; }
    set type(value) { this.attrs.type = value; }
    get className() { return this.attrs.class || ''; }
    set className(v) { this.attrs.class = v; }
    get textContent() { return this._text + this.children.map(c => c.textContent).join(''); }
    set textContent(v) { this._text = String(v || ''); this.children = []; }
    get firstChild() { return this.children[0] || null; }
    setAttribute(k, v) { this.attrs[k] = String(v); }
    getAttribute(k) { return this.attrs[k] ?? null; }
    removeAttribute(k) { delete this.attrs[k]; }
    appendChild(c) { c.parentElement = this; this.children.push(c); return c; }
    insertBefore(c) { c.parentElement = this; this.children.unshift(c); }
    remove() { if (this.parentElement) this.parentElement.children = this.parentElement.children.filter(c => c !== this); }
    matches(selector) {
        return selector.split(',').some(part => {
            const s = part.trim();
            if (s.startsWith('.')) return this.classList.contains(s.slice(1));
            const match = s.match(/^(\w+)?(?:\[([^=\]]+)(?:="([^"]*)")?\])?$/);
            return !!match && (!match[1] || this.tagName === match[1].toUpperCase())
                && (!match[2] || Object.hasOwn(this.attrs, match[2]) && (match[3] === undefined || this.attrs[match[2]] === match[3]));
        });
    }
    querySelectorAll(selector) {
        return this.children.flatMap(c => [...(c.matches(selector) ? [c] : []), ...c.querySelectorAll(selector)]);
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    closest(selector) { return this.matches(selector) ? this : this.parentElement?.closest(selector) || null; }
    addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }
    getClientRects() { return this.hidden || this.parentElement && !this.parentElement.getClientRects().length ? [] : [1]; }
    focus() { this.focused = true; }
    blur() {}
    scrollIntoView() { this.scrolled = true; }
}

function fixture() {
    const form = new Element('form', {class: 'assistant-domain-store-form'});
    const node = form.appendChild(new Element('script', {'data-store-validation-config': ''}));
    const labels = {
        title: '请修正以下填写内容后再保存：', required: '请填写:field', invalid: '请检查:field',
        session: 'session', permission: 'permission', network: 'network', timeout: 'timeout',
        server: 'server', missing: 'missing', conflict: 'conflict', tooLarge: 'tooLarge', tooMany: 'tooMany', unexpected: 'unexpected',
    };
    node.textContent = JSON.stringify(labels);
    const handlers = {};
    const context = {window: {}, document: {
        createElement: tag => new Element(tag),
        addEventListener: (name, fn) => { (handlers[name] ||= []).push(fn); },
    }};
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/assets/erp/assistant-domain-store-required-fields.js'), 'utf8'), context);
    const field = (name, value, options = {}) => {
        const group = form.appendChild(new Element('div', {class: 'form-group'}));
        if (options.required) {
            group.setAttribute('data-store-required-field', name);
            group.setAttribute('data-store-required-message', name + ' required');
        }
        group.appendChild(new Element('label')).textContent = name;
        const input = group.appendChild(new Element('input', {name, type: options.type || 'text'}));
        input.value = value;
        input.disabled = !!options.disabled;
        if (options.native) { input.willValidate = true; input.validity = options.native; }
        return {group, input};
    };
    const submit = () => {
        let blocked = false;
        (handlers.submit || []).forEach(fn => fn({target: form, preventDefault() { blocked = true; }, stopImmediatePropagation() {}}));
        return blocked;
    };
    return {form, field, submit, api: context.window.ErpAssistantStoreValidation};
}

test('all missing and invalid fields are listed, marked, and focusable', () => {
    const f = fixture();
    const a = f.field('domain_expires_at', '', {required: true});
    const b = f.field('email_bound', '', {required: true, type: 'hidden'});
    b.group.appendChild(new Element('button'));
    f.field('email', 'invalid', {native: {valid: false, typeMismatch: true}});
    assert.equal(f.submit(), true);
    assert.equal(f.form.querySelectorAll('li').length, 3);
    assert.match(f.form.textContent, /domain_expires_at required/);
    assert.match(f.form.textContent, /email_bound required/);
    assert.match(f.form.textContent, /请检查email/);
    assert.equal(a.input.focused, true);
    assert.equal(a.group.classList.contains('has-error'), true);
    const emailLink = f.form.querySelectorAll('li')[1].querySelector('button');
    emailLink.listeners.click[0]();
    assert.equal(b.group.querySelector('button').focused, true);
});

test('explicit No and disabled reserve owner pass validation', () => {
    const f = fixture();
    f.field('email_bound', '0', {required: true, type: 'hidden'});
    f.field('operation_department_id', 'reserve', {required: true});
    f.field('owner_user_id', '', {required: true, disabled: true});
    assert.equal(f.submit(), false);
});

test('each server validation message is retained, including nested and unmapped errors', () => {
    const f = fixture();
    const a = f.field('team[]', 'Team A');
    f.api.failure(f.form, {status: 422, responseJSON: {errors: {'team.0': ['first error', 'second error'], unknown: ['third error']}}});
    assert.equal(f.form.querySelectorAll('li').length, 3);
    assert.equal(a.group.querySelectorAll('[data-store-validation-error]').length, 2);
    assert.match(f.form.textContent, /third error/);
});

test('request status categories preserve values and never display raw server HTML', () => {
    for (const [code, status, expected] of [[401, '', 'session'], [419, '', 'session'], [403, '', 'permission'], [0, '', 'network'], [0, 'timeout', 'timeout'], [408, '', 'timeout'], [404, '', 'missing'], [409, '', 'conflict'], [413, '', 'tooLarge'], [429, '', 'tooMany'], [500, '', 'server'], [502, '', 'server'], [200, 'parsererror', 'unexpected']]) {
        const f = fixture(), {input} = f.field('shop_name', 'Keep this value');
        f.api.failure(f.form, {status: code, responseText: '<html>Internal exception details</html>'}, status);
        assert.equal(f.form.querySelector('[data-store-validation-summary]').textContent, expected);
        assert.equal(input.value, 'Keep this value');
        assert.doesNotMatch(f.form.textContent, /Internal exception details/);
    }
});

test('retry replaces summaries and clears old markers', () => {
    const f = fixture(), a = f.field('shop_name', '', {required: true});
    assert.equal(f.submit(), true);
    assert.equal(f.submit(), true);
    assert.equal(f.form.querySelectorAll('[data-store-validation-summary]').length, 1);
    assert.equal(a.group.querySelectorAll('[data-store-validation-error]').length, 1);
    a.input.value = 'Fixed';
    assert.equal(f.submit(), false);
    assert.equal(f.form.querySelectorAll('[data-store-validation-summary]').length, 0);
    assert.equal(a.group.classList.contains('has-error'), false);
});

test('responseText JSON fallback is parsed and error text is inserted as text', () => {
    const f = fixture();
    f.field('domain', 'example.com');
    f.api.failure(f.form, {status: 422, responseText: JSON.stringify({errors: {domain: ['<script>unsafe()</script>']}})});
    assert.match(f.form.textContent, /<script>unsafe/);
    assert.equal(f.form.querySelectorAll('script').length, 1); // Only the trusted configuration node.
});

test('hidden sections containing server errors are revealed for correction', () => {
    const f = fixture(), a = f.field('nbc_app_token', 'token');
    a.group.hidden = true;
    f.api.failure(f.form, {status: 422, responseJSON: {errors: {nbc_app_token: ['Invalid token']}}});
    assert.equal(a.group.hidden, false);
    assert.equal(a.input.focused, true);
});
