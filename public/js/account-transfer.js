(function () {
    'use strict';
    if (window.ErpAccountTransfer) return;
    var active = null;
    function create(root) {
        var cfg = JSON.parse(
                root.querySelector('[data-transfer-config]').textContent,
            ),
            t = cfg.texts;
        var dialog = root.querySelector('dialog'),
            file = root.querySelector('[data-transfer-file]');
        var password = root.querySelector('[data-transfer-password]'),
            save = root.querySelector('[data-transfer-save]');
        var preview = root.querySelector('[data-transfer-preview]'),
            table = root.querySelector('[data-transfer-table]');
        var status = root.querySelector('[data-transfer-status]'),
            mode,
            token = null,
            busy = false,
            controller = null,
            generation = 0,
            source = null;
        function say(message, error) {
            status.textContent = message;
            status.dataset.error = error ? '1' : '0';
        }
        function controls() {
            save.disabled = busy || (mode === 'import' && !token);
            preview.disabled = file.disabled = password.disabled = busy;
            save.textContent = busy
                ? t.working
                : mode === 'import'
                  ? t.confirm
                  : t.export;
            dialog.setAttribute('aria-busy', busy ? 'true' : 'false');
            root.querySelectorAll('[data-transfer-close]').forEach(
                function (button) {
                    button.disabled = busy;
                },
            );
        }
        async function request(action, body, blob) {
            var current = new AbortController();
            controller = current;
            var timer = setTimeout(function () {
                current.abort();
            }, 120000);
            try {
                var response = await fetch(cfg.base + '/' + action, {
                    method: body ? 'POST' : 'GET',
                    body: body,
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: current.signal,
                    headers: {
                        Accept: blob
                            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/json'
                            : 'application/json',
                        'X-CSRF-TOKEN': cfg.csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                if (response.redirected) throw Error(t.expired);
                if (
                    blob &&
                    response.ok &&
                    (response.headers.get('Content-Type') || '').includes(
                        'spreadsheetml',
                    )
                )
                    return await response.blob();
                var data = await response.json();
                if (!response.ok)
                    throw Error(
                        data.errors
                            ? Object.values(data.errors).flat().join('\n')
                            : data.message || t.failed,
                    );
                if (blob) throw Error(t.failed);
                return data;
            } finally {
                clearTimeout(timer);
                if (controller === current) controller = null;
            }
        }
        function render(data) {
            var el = document.createElement('table'),
                head = el.createTHead().insertRow();
            [
                t.line,
                t.fields.username,
                t.fields.name,
                t.fields.company_name,
                t.fields.department_codes,
                t.fields.department_positions,
                t.issues,
            ].forEach(function (label) {
                var th = document.createElement('th');
                th.textContent = label;
                head.appendChild(th);
            });
            var errors = new Map(
                data.errors.map(function (e) {
                    return [e.line, e.messages.join('\n')];
                }),
            );
            var body = el.createTBody();
            data.rows.forEach(function (row) {
                var tr = body.insertRow();
                [
                    row.line,
                    row.username,
                    row.name,
                    row.company,
                    row.departments,
                    row.positions,
                    errors.get(row.line) || '—',
                ].forEach(function (value) {
                    tr.insertCell().textContent = value;
                });
            });
            table.replaceChildren(el);
        }
        function close() {
            generation++;
            if (controller) controller.abort();
            password.value = '';
            file.value = '';
            token = null;
            busy = false;
            table.replaceChildren();
            dialog.close();
        }
        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            if (!busy) close();
        });
        root.querySelectorAll('[data-transfer-close]').forEach(
            function (button) {
                button.onclick = function () {
                    if (!busy) close();
                };
            },
        );
        file.onchange = function () {
            token = null;
            password.value = '';
            table.replaceChildren();
            say('');
            controls();
        };
        preview.onclick = async function () {
            if (busy) return;
            if (!file.files.length) return say(t.required_file, true);
            token = null;
            password.value = '';
            busy = true;
            controls();
            say(t.working);
            var version = ++generation,
                body = new FormData();
            body.append('file', file.files[0]);
            try {
                var data = await request('preview', body);
                if (version !== generation) return;
                token = data.token;
                render(data);
                say(
                    (data.errors.length ? t.invalid : t.ready).replace(
                        ':count',
                        data.errors.length || data.count,
                    ),
                    !!data.errors.length,
                );
            } catch (error) {
                if (version === generation)
                    say(
                        error.name === 'AbortError' ||
                            error instanceof TypeError ||
                            error instanceof SyntaxError
                            ? t.failed
                            : error.message,
                        true,
                    );
            } finally {
                if (version === generation) {
                    busy = false;
                    controls();
                }
            }
        };
        save.onclick = async function () {
            if (busy) return;
            var action,
                body,
                exportMode = mode === 'export';
            if (!exportMode) {
                if (!token) return;
                if (password.value.length < 8 || password.value.length > 255)
                    return say(t.password_invalid, true);
                action = 'commit';
                body = new FormData();
                body.append('token', token);
                body.append('password', password.value);
            } else {
                var query = new URLSearchParams(),
                    scope = root.querySelector(
                        'input[name="account-transfer-scope"]:checked',
                    ).value;
                if (scope === 'selected') {
                    var ids = Array.from(
                        source.querySelectorAll(
                            'table tbody input[type="checkbox"]:checked',
                        ),
                    )
                        .map(function (el) {
                            return el.dataset.id;
                        })
                        .filter(Boolean);
                    if (!ids.length) return say(t.no_selection, true);
                    ids.forEach(function (id) {
                        query.append('account_ids[]', id);
                    });
                } else {
                    new URLSearchParams(location.search).forEach(
                        function (value, key) {
                            if (['username', 'name'].includes(key))
                                query.append(key, value);
                            else if (/^department_ids(?:\[\d*\])?$/.test(key))
                                query.append('department_ids[]', value);
                        },
                    );
                }
                action = 'export?' + query.toString();
            }
            busy = true;
            controls();
            say(t.working);
            var version = ++generation;
            try {
                var data = await request(action, body, exportMode);
                if (version !== generation) return;
                if (exportMode) {
                    var url = URL.createObjectURL(data),
                        link = document.createElement('a');
                    link.href = url;
                    link.download = 'accounts.xlsx';
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    setTimeout(function () {
                        URL.revokeObjectURL(url);
                    }, 10000);
                    say(t.downloaded);
                } else {
                    say(data.message);
                    token = null;
                    password.value = '';
                }
            } catch (error) {
                if (version === generation)
                    say(
                        error.name === 'AbortError' ||
                            error instanceof TypeError ||
                            error instanceof SyntaxError
                            ? t.failed
                            : error.message,
                        true,
                    );
            } finally {
                password.value = '';
                if (version === generation) {
                    busy = false;
                    controls();
                }
            }
        };
        return {
            open: function (button) {
                if (busy) return;
                source = button.closest('.box') || document;
                mode = button.dataset.accountTransfer;
                token = null;
                table.replaceChildren();
                password.value = '';
                file.value = '';
                say('');
                root.querySelector('h3').textContent = t[mode];
                root.querySelector('[data-transfer-import]').hidden =
                    mode !== 'import';
                root.querySelector('[data-transfer-export]').hidden =
                    mode !== 'export';
                controls();
                if (!dialog.open) dialog.showModal();
            },
            destroy: close,
            root: root,
        };
    }
    function init() {
        var root = document.querySelector('[data-account-transfer-root]');
        if (active && active.root !== root) {
            active.destroy();
            active = null;
        }
        if (root && !active) active = create(root);
        document
            .querySelectorAll('[data-account-transfer]')
            .forEach(function (button) {
                var target =
                    button.closest('.box') &&
                    button
                        .closest('.box')
                        .querySelector('.box-header .pull-right');
                if (target && !target.contains(button))
                    target.appendChild(button);
            });
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-account-transfer]');
        if (button) {
            event.preventDefault();
            init();
            if (active) active.open(button);
        }
    });
    window.ErpAccountTransfer = { init: init };
    if (window.jQuery)
        window
            .jQuery(document)
            .on('pjax:end.accountTransfer', init)
            .on('pjax:beforeReplace.accountTransfer', function () {
                if (active) {
                    active.destroy();
                    active = null;
                }
            });
    if (document.readyState === 'loading')
        document.addEventListener('DOMContentLoaded', init);
    else init();
})();
