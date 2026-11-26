(function () {
    'use strict';
    if (window.ErpDingUi) return;
    function el(tag, text, cls) {
        var n = document.createElement(tag);
        if (text != null) n.textContent = text;
        if (cls) n.className = cls;
        return n;
    }
    function button(text, fn, cls) {
        var b = el('button', text, cls || 'btn btn-default btn-sm');
        b.type = 'button';
        b.onclick = fn;
        return b;
    }
    function translator(messages) {
        return function (key, values) {
            return String(messages[key] || key).replace(/:([A-Za-z_][A-Za-z0-9_]*)/g, function (m, k) {
                return Object.prototype.hasOwnProperty.call(values || {}, k) ? String(values[k]) : m;
            });
        };
    }
    function status(node, message, error) {
        if (!node) return;
        node.hidden = !message;
        node.textContent = message || '';
        node.dataset.error = error ? '1' : '0';
    }
    async function api(path, options) {
        options = options || {};
        var c = options.controller || new AbortController(),
            expired = false,
            timer = setTimeout(function () {
                expired = true;
                c.abort();
            }, 30000),
            t = options.t;
        var multipart = options.body instanceof FormData;
        try {
            var r = await fetch(path, {
                method: options.method || (options.body ? 'POST' : 'GET'),
                credentials: 'same-origin',
                cache: 'no-store',
                signal: c.signal,
                headers: {
                    Accept: 'application/json',
                    ...(multipart ? {} : { 'Content-Type': 'application/json' }),
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': options.csrf || '',
                },
                body: multipart ? options.body : options.body ? JSON.stringify(options.body) : undefined,
            });
            if (r.redirected) throw Error(t('session_expired'));
            var data;
            try {
                data = await r.json();
            } catch (_) {
                throw Error(t('request_error'));
            }
            if (!r.ok)
                throw Error(
                    data.errors
                        ? Object.values(data.errors).flat().join('\n')
                        : data.message || t('request_error'),
                );
            return data;
        } catch (e) {
            if (expired) throw Error(t('timeout'));
            if (e.name === 'TypeError') throw Error(t('request_error'));
            throw e;
        } finally {
            clearTimeout(timer);
        }
    }
    async function busy(form, t, work) {
        if (form.getAttribute('aria-busy') === 'true') return;
        var save = form.querySelector('[data-save]'),
            text = save.textContent,
            controls = Array.from(form.querySelectorAll('button,input,select')).map(function (n) {
                var old = n.disabled;
                n.disabled = true;
                return [n, old];
            });
        form.setAttribute('aria-busy', 'true');
        save.textContent = t('saving');
        status(form.querySelector('[data-status]'), '');
        try {
            await work();
        } catch (e) {
            if (form.isConnected) status(form.querySelector('[data-status]'), e.message, true);
        } finally {
            form.setAttribute('aria-busy', 'false');
            save.textContent = text;
            controls.forEach(function (p) {
                if (p[0].isConnected) p[0].disabled = p[1];
            });
        }
    }
    function wire(root, t) {
        root.querySelectorAll('[data-close]').forEach(function (b) {
            b.onclick = function () {
                var d = b.closest('dialog'),
                    f = d.querySelector('form[aria-busy="true"]');
                if (f) status(f.querySelector('[data-status]'), t('busy_close'));
                else d.close();
            };
        });
        root.querySelectorAll('dialog').forEach(function (d) {
            d.addEventListener('cancel', function (e) {
                if (d.querySelector('form[aria-busy="true"]')) e.preventDefault();
            });
        });
    }
    function mount(container, options) {
        var t = options.t,
            generation = 0,
            controller = null,
            current = null,
            items = [],
            cursor = 0,
            more = false,
            loading = false,
            seenCursors = new Set([0]),
            nodes = new Map(),
            selectedUserId = null;
        container.replaceChildren();
        var toolbar = el('div', null, 'dg-directory-tools'),
            search = el('input'),
            refresh = button(t('refresh'), function () {
                load(false, true);
            }),
            hint = el('div', null, 'dg-banner'),
            layout = el('div', null, 'dg-directory-layout'),
            tree = el('ul', null, 'dg-tree'),
            right = el('div'),
            list = el('div', null, 'dg-member-list'),
            count = el('div', null, 'dg-help'),
            loadMore = button(
                t('load_more'),
                function () {
                    load(true, false);
                },
                'btn btn-default btn-block',
            ),
            label = el('label', t('member_filter'), 'dg-field');
        search.type = 'search';
        search.className = 'form-control';
        search.placeholder = t('search_placeholder');
        label.append(search);
        tree.setAttribute('role', 'tree');
        toolbar.append(refresh);
        right.append(label, count, list, loadMore);
        layout.append(tree, right);
        container.append(toolbar, hint, layout, el('p', t('refresh_hint'), 'dg-help'));
        function node(id, name, parent) {
            if (nodes.has(id)) return nodes.get(id);
            var li = el('li'),
                children = el('ul'),
                b = button(
                    name,
                    function () {
                        current = { id: id, name: name };
                        load(false, false);
                    },
                    'dg-tree-button',
                );
            li.setAttribute('role', 'treeitem');
            children.setAttribute('role', 'group');
            li.append(b, children);
            (parent || tree).append(li);
            var n = { li: li, button: b, children: children };
            nodes.set(id, n);
            return n;
        }
        function render() {
            list.replaceChildren();
            var term = search.value.trim().toLocaleLowerCase(),
                filtered = items.filter(function (m) {
                    return (m.name + ' ' + m.user_id).toLocaleLowerCase().includes(term);
                });
            count.textContent = t('loaded_count', { count: items.length, visible: filtered.length });
            filtered.forEach(function (m) {
                var row = el('div', null, 'dg-directory-member'),
                    info = el('div');
                info.append(el('strong', m.name), el('small', m.user_id, 'dg-help'));
                row.append(info);
                row.dataset.memberId = m.user_id;
                if (options.choose) {
                    var choose = button(
                        t('select'),
                        function () {
                            options.choose(m);
                        },
                        'btn btn-primary btn-sm',
                    );
                    choose.dataset.memberChoose = '';
                    row.append(choose);
                } else {
                    var mappings = el('div', null, 'dg-directory-mappings');
                    (m.accounts || []).forEach(function (a) {
                        mappings.append(el('span', a.name + ' (' + a.username + ') · #' + a.id, 'dg-tag'));
                    });
                    if (!(m.accounts || []).length) mappings.textContent = t('no_pair');
                    row.append(mappings);
                }
                list.append(row);
            });
            updateSelection();
            if (!filtered.length) list.append(el('div', t(term ? 'no_matches' : 'no_members'), 'dg-empty'));
            loadMore.hidden = !more;
            loadMore.disabled = loading;
        }
        function updateSelection() {
            if (!options.choose) return;
            list.querySelectorAll('[data-member-id]').forEach(function (row) {
                var chosen = row.dataset.memberId === selectedUserId;
                var choose = row.querySelector('[data-member-choose]');
                row.dataset.selected = chosen ? 'true' : 'false';
                choose.textContent = chosen ? '✓ ' + t('member_selected') : t('select');
                choose.setAttribute('aria-pressed', chosen ? 'true' : 'false');
                choose.disabled = chosen;
            });
        }
        async function load(append, fresh) {
            if (loading && append) return;
            if (controller) controller.abort();
            controller = new AbortController();
            var token = ++generation;
            if (!append) {
                items = [];
                cursor = 0;
                more = false;
                seenCursors = new Set([0]);
                search.value = '';
            }
            loading = true;
            render();
            status(hint, t('loading'));
            nodes.forEach(function (n, id) {
                n.li.setAttribute('aria-selected', id === current.id ? 'true' : 'false');
            });
            try {
                var result = await options.fetch(current.id, cursor, fresh, controller);
                if (token !== generation) return;
                if (
                    !Array.isArray(result.members) ||
                    !Array.isArray(result.departments) ||
                    typeof result.has_more !== 'boolean' ||
                    (result.has_more &&
                        (!Number.isInteger(result.next_cursor) || seenCursors.has(result.next_cursor)))
                )
                    throw Error(t('load_failed'));
                result.members.forEach(function (m) {
                    if (typeof m.name !== 'string' || typeof m.user_id !== 'string')
                        throw Error(t('load_failed'));
                    if (
                        !items.some(function (x) {
                            return x.user_id === m.user_id;
                        })
                    )
                        items.push(m);
                });
                var parent = nodes.get(current.id);
                result.departments.forEach(function (d) {
                    if (!Number.isInteger(d.id) || typeof d.name !== 'string') throw Error(t('load_failed'));
                    node(d.id, d.name, parent.children);
                });
                more = result.has_more;
                cursor = result.next_cursor;
                if (more) seenCursors.add(cursor);
                status(hint, '');
            } catch (e) {
                if (token === generation && e.name !== 'AbortError') status(hint, e.message, true);
            } finally {
                if (token === generation) {
                    loading = false;
                    render();
                }
            }
        }
        search.oninput = render;
        node(1, t('root'));
        current = { id: 1, name: t('root') };
        load(false, false);
        return {
            setSelection: function (member) {
                selectedUserId = member ? member.user_id : null;
                updateSelection();
            },
            cancel: function () {
                generation++;
                if (controller) controller.abort();
            },
            refresh: function () {
                load(false, true);
            },
        };
    }
    window.ErpDingUi = {
        el: el,
        button: button,
        translator: translator,
        status: status,
        api: api,
        busy: busy,
        wire: wire,
        directory: mount,
    };
})();
