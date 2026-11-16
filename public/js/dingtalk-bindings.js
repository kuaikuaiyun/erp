(function () {
    'use strict';
    if (window.ErpDingBindings) {
        window.ErpDingBindings.init();
        return;
    }
    var active = null;
    var initializedRoot = null;
    function init() {
        var root = document.querySelector('[data-ding-accounts]');
        if (!root) return;
        document.querySelectorAll('[data-ding-grants]').forEach(function (button) {
            var header = button.closest('.box-header');
            if (!header) return;
            var right = header.querySelector('.pull-right');
            if (!right) {
                right = document.createElement('div');
                right.className = 'pull-right';
                header.append(right);
            }
            if (button.parentElement !== right) right.prepend(button);
        });
        if (root === initializedRoot) return;
        var config = root.querySelector('[data-ding-config]');
        var messages = root.querySelector('[data-ding-messages]');
        // PJAX can replace content before its external scripts finish loading.
        if (!config || !messages || !window.ErpDingUi || !window.ErpAccountLabels) return;
        var opts = JSON.parse(config.textContent);
        var translations = JSON.parse(messages.textContent);
        if (active) active.cancel();
        var ui = window.ErpDingUi,
            t = ui.translator(translations),
            target = null,
            selected = null,
            directory = null,
            openRequest = null,
            subjectGeneration = 0,
            grants = null,
            logGeneration = 0,
            logKind = 'login',
            logPage = 1,
            logLast = 1;
        function q(s, p) {
            return (p || root).querySelector(s);
        }
        function qa(s, p) {
            return Array.from((p || root).querySelectorAll(s));
        }
        function modal(n) {
            return q('[data-modal="' + n + '"]');
        }
        function api(path, body, method, controller) {
            return ui.api(path, {
                body: body,
                method: method,
                controller: controller,
                csrf: opts.csrf,
                t: t,
            });
        }
        function path(s) {
            return opts.base + '/dingtalk/' + s;
        }
        function query(base, params) {
            var u = new URL(base, location.href);
            Object.keys(params).forEach(function (k) {
                u.searchParams.set(k, params[k]);
            });
            return u.toString();
        }
        function notify(message) {
            if (window.toastr) window.toastr.warning(message);
            else window.alert(message);
        }
        ui.wire(root, t);
        function selection() {
            var box = q('[data-selection]');
            box.replaceChildren();
            if (selected) {
                var company = q('[data-company]').selectedOptions[0];
                box.append(
                    ui.el('strong', (company ? company.textContent : '') + ' · ' + selected.name),
                    ui.el('small', selected.user_id, 'dg-help'),
                );
            } else box.textContent = t('no_selection');
            q('[data-selection-panel]').dataset.selected = selected ? 'true' : 'false';
            q('[data-selection-hint]').hidden = !selected;
            q('[data-clear-selection]').hidden = !selected;
            q('[data-clear-selection]').disabled = !selected;
            q('[data-save]', q('[data-binding-form]')).disabled = !selected;
            if (directory) directory.setSelection(selected);
        }
        q('[data-clear-selection]').onclick = function () {
            selected = null;
            selection();
        };
        function updateLabels() {
            var node = document.querySelector('[data-binding-labels="' + target.account.id + '"]');
            if (!node) return;
            var labels = target.bindings
                .filter(function (b) {
                    return b.active;
                })
                .map(function (b) {
                    return b.company + ' * ' + b.name;
                });
            if (labels.length) window.ErpAccountLabels.render(node, labels, t('binding'));
            else node.textContent = t('none');
        }
        function existing() {
            var box = q('[data-existing]');
            box.replaceChildren();
            if (!target.bindings.length) box.textContent = t('unbound');
            target.bindings.forEach(function (b) {
                var row = ui.el('div', null, 'dg-existing-row'),
                    info = ui.el('div');
                info.append(
                    ui.el('span', b.company + ' * ' + b.name),
                    ui.el('small', b.user_id + (b.active ? '' : ' · ' + t('inactive')), 'dg-help'),
                );
                row.append(info);
                if (b.removable)
                    row.append(
                        ui.button(t('remove'), function () {
                            if (!confirm(t('unbind_confirm', { name: b.name, account: target.account.name })))
                                return;
                            ui.busy(q('[data-binding-form]'), t, async function () {
                                var r = await api(path('bindings/' + b.id), null, 'DELETE');
                                await saved(r.message);
                            });
                        }),
                    );
                box.append(row);
            });
        }
        async function saved(message) {
            var status = q('[data-status]', q('[data-binding-form]'));
            ui.status(status, message);
            try {
                target = await api(opts.base + '/' + target.account.id + '/dingtalk');
                existing();
                updateLabels();
            } catch (_) {
                ui.status(status, t('refresh_failed'), true);
            }
        }
        function mountDirectory() {
            if (directory) directory.cancel();
            directory = null;
            selected = null;
            selection();
            var company = q('[data-company]').value,
                container = q('[data-directory]');
            container.replaceChildren();
            if (!company) return;
            directory = ui.directory(container, {
                t: t,
                choose: function (member) {
                    selected = member;
                    selection();
                },
                fetch: function (dept, cursor, refresh, controller) {
                    return api(
                        query(path('directory'), {
                            company_id: company,
                            department: dept,
                            cursor: cursor,
                            refresh: refresh ? 1 : 0,
                        }),
                        null,
                        'GET',
                        controller,
                    );
                },
            });
        }
        async function openBinding(id, button) {
            if (openRequest) openRequest.abort();
            if (directory) directory.cancel();
            var generation = ++subjectGeneration;
            openRequest = new AbortController();
            button.disabled = true;
            try {
                var result = await api(opts.base + '/' + id + '/dingtalk', null, 'GET', openRequest);
                if (generation !== subjectGeneration || !root.isConnected) return;
                if (!result.companies.length && (!opts.owner || !result.bindings.length)) {
                    notify(t('no_company'));
                    return;
                }
                target = result;
                q('[data-account-name]').textContent =
                    target.account.name + ' (' + target.account.username + ') · #' + target.account.id;
                var select = q('[data-company]');
                select.replaceChildren(new Option(t('choose'), ''));
                target.companies.forEach(function (c) {
                    select.add(new Option(c.name, c.id));
                });
                if (target.companies.length === 1) select.value = target.companies[0].id;
                existing();
                selected = null;
                selection();
                ui.status(q('[data-status]', q('[data-binding-form]')), '');
                modal('binding').showModal();
                mountDirectory();
            } catch (e) {
                if (e.name !== 'AbortError') notify(e.message);
            } finally {
                button.disabled = false;
            }
        }
        q('[data-company]').onchange = mountDirectory;
        modal('binding').addEventListener('close', function () {
            if (directory) directory.cancel();
            directory = null;
            selected = null;
            selection();
            subjectGeneration++;
        });
        q('[data-binding-form]').addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!selected || !target) return;
            var payload = {
                account_id: target.account.id,
                company_id: Number(q('[data-company]').value),
                user_id: selected.user_id,
            };
            await ui.busy(event.currentTarget, t, async function () {
                var r = await api(path('bindings'), payload);
                selected = null;
                await saved(r.message);
            });
            // Restore selection-dependent controls after the shared busy handler.
            selection();
        });
        function renderManagers() {
            var company = Number(q('[data-grant-company]').value),
                box = q('[data-managers]');
            box.replaceChildren();
            grants.managers.forEach(function (m) {
                var label = ui.el('label', null, 'dg-check'),
                    check = ui.el('input'),
                    info = ui.el('span', m.name);
                check.type = 'checkbox';
                check.value = m.id;
                check.checked = grants.grants.some(function (g) {
                    return Number(g.company_id) === company && Number(g.user_id) === Number(m.id);
                });
                check.disabled = !company;
                info.append(ui.el('small', m.username + ' · #' + m.id));
                label.append(check, info);
                box.append(label);
            });
            if (!grants.managers.length) box.append(ui.el('div', t('managers_empty'), 'dg-empty'));
            q('[data-manager-search]').value = '';
            countManagers();
        }
        function countManagers() {
            q('[data-grant-count]').textContent = t('selected_count', {
                count: qa('[data-managers] input:checked').length,
            });
        }
        async function openGrants(button) {
            button.disabled = true;
            try {
                grants = await api(path('company-grants'));
                var select = q('[data-grant-company]');
                select.replaceChildren(new Option(t('choose'), ''));
                grants.companies.forEach(function (c) {
                    select.add(new Option(c.name + (c.enabled ? '' : ' · ' + t('company_disabled')), c.id));
                });
                if (grants.companies.length === 1) select.value = grants.companies[0].id;
                renderManagers();
                ui.status(q('[data-status]', q('[data-grants-form]')), '');
                modal('grants').showModal();
            } catch (e) {
                notify(e.message);
            } finally {
                button.disabled = false;
            }
        }
        if (opts.owner) {
            q('[data-grant-company]').onchange = renderManagers;
            q('[data-managers]').onchange = countManagers;
            q('[data-manager-search]').oninput = function (e) {
                var term = e.target.value.trim().toLocaleLowerCase();
                qa('label', q('[data-managers]')).forEach(function (n) {
                    n.hidden = !n.textContent.toLocaleLowerCase().includes(term);
                });
            };
            q('[data-grant-all]').onclick = function () {
                qa('[data-managers] input:not(:disabled)').forEach(function (n) {
                    if (!n.closest('label').hidden) n.checked = true;
                });
                countManagers();
            };
            q('[data-grant-clear]').onclick = function () {
                qa('[data-managers] input').forEach(function (n) {
                    n.checked = false;
                });
                countManagers();
            };
            q('[data-grants-form]').addEventListener('submit', function (e) {
                e.preventDefault();
                if (!confirm(t('assign_confirm'))) return;
                var payload = {
                    company_id: Number(q('[data-grant-company]').value),
                    user_ids: qa('[data-managers] input:checked').map(function (n) {
                        return Number(n.value);
                    }),
                };
                ui.busy(e.currentTarget, t, async function () {
                    var r = await api(path('company-grants'), payload);
                    ui.status(q('[data-status]', q('[data-grants-form]')), r.message);
                    try {
                        grants = await api(path('company-grants'));
                    } catch (_) {
                        ui.status(q('[data-status]', q('[data-grants-form]')), t('refresh_failed'), true);
                    }
                });
            });
        }
        async function logs() {
            var generation = ++logGeneration,
                status = q('[data-log-status]');
            ui.status(status, t('loading'));
            q('[data-log-rows]').replaceChildren();
            q('[data-log-prev]').disabled = true;
            q('[data-log-next]').disabled = true;
            try {
                var r = await api(
                    query(path('audits'), { account_id: target.account.id, kind: logKind, page: logPage }),
                );
                if (generation !== logGeneration || !root.isConnected) return;
                r.rows.forEach(function (a) {
                    var row = ui.el('tr');
                    ['time', 'action', 'actor', 'source', 'ip', 'details'].forEach(function (k) {
                        row.append(ui.el('td', a[k] || '—'));
                    });
                    q('[data-log-rows]').append(row);
                });
                logPage = r.page;
                logLast = r.last_page;
                q('[data-log-page]').textContent = logPage + ' / ' + logLast;
                ui.status(status, r.rows.length ? '' : t('log_empty'));
                q('[data-log-prev]').disabled = logPage <= 1;
                q('[data-log-next]').disabled = logPage >= logLast;
            } catch (e) {
                if (generation === logGeneration) ui.status(status, e.message, true);
            }
        }
        qa('[data-logs]').forEach(function (b) {
            b.onclick = function () {
                logKind = b.dataset.logs;
                logPage = 1;
                q('[data-log-title]').textContent =
                    t(logKind === 'login' ? 'login_logs' : 'operation_logs') + ' · ' + target.account.name;
                modal('logs').showModal();
                logs();
            };
        });
        modal('logs').addEventListener('close', function () {
            logGeneration++;
        });
        q('[data-log-prev]').onclick = function () {
            if (logPage > 1) {
                logPage--;
                logs();
            }
        };
        q('[data-log-next]').onclick = function () {
            if (logPage < logLast) {
                logPage++;
                logs();
            }
        };
        q('[data-log-retry]').onclick = logs;
        active = {
            cancel: function () {
                if (directory) directory.cancel();
                if (openRequest) openRequest.abort();
                qa('dialog[open]').forEach(function (d) {
                    d.close();
                });
            },
            handle: function (event) {
                if (!root.isConnected) return;
                var b = event.target.closest('[data-ding-account]');
                if (b) {
                    event.preventDefault();
                    if (!b.disabled) openBinding(Number(b.dataset.dingAccount), b);
                    return;
                }
                b = event.target.closest('[data-ding-grants]');
                if (b && opts.owner) {
                    event.preventDefault();
                    if (!b.disabled) openGrants(b);
                }
            },
        };
        initializedRoot = root;
        root.dataset.initialized = '1';
    }
    document.addEventListener('click', function (e) {
        if (active) active.handle(e);
    });
    window.ErpDingBindings = { init: init };
    document.addEventListener('load', init, true);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    if (window.jQuery)
        window
            .jQuery(document)
            .on('pjax:end.erpDingAccounts', init)
            .on('pjax:beforeReplace.erpDingAccounts', function () {
                if (active) active.cancel();
            });
})();
