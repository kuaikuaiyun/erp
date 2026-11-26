(function () {
    'use strict';
    if (window.ErpDingCompanies) {
        window.ErpDingCompanies.init();
        return;
    }
    var active;
    var initializedRoot = null;
    function init() {
        var root = document.querySelector('[data-ding-companies]');
        if (!root || root === initializedRoot) return;
        var config = root.querySelector('[data-companies-config]');
        var messages = root.querySelector('[data-companies-messages]');
        // PJAX can replace content before its external scripts finish loading.
        if (!config || !messages || !window.ErpDingUi) return;
        var options = JSON.parse(config.textContent);
        var translations = JSON.parse(messages.textContent);
        if (active) active();
        var ui = window.ErpDingUi,
            t = ui.translator(translations),
            editing = null,
            directory = null,
            syncs = new Set();
        function q(s, p) {
            return (p || root).querySelector(s);
        }
        function modal(n) {
            return q('[data-modal="' + n + '"]');
        }
        function api(path, body, method, controller) {
            return ui.api(path, {
                t: t,
                csrf: options.csrf,
                body: body,
                method: method,
                controller: controller,
            });
        }
        function companyUrl(id, suffix) {
            return options.save_url + '/' + id + (suffix || '');
        }
        ui.wire(root, t);
        async function refresh() {
            var result = await api(options.base_url);
            options.companies = result.companies;
            render();
        }
        var recovery = q('[data-page-recovery]'),
            retry = q('[data-retry-refresh]'),
            reopen = q('[data-reopen-login]');
        async function savedStatus(message, redirectUrl) {
            var pageStatus = q('[data-page-status]');
            reopen.hidden = !redirectUrl;
            if (redirectUrl) {
                retry.hidden = true;
                recovery.hidden = false;
                reopen.href = redirectUrl;
                ui.status(pageStatus, t('saved_sign_in_again'));
                reopen.focus();
                return;
            }
            try {
                await refresh();
                recovery.hidden = retry.hidden = true;
                ui.status(pageStatus, message);
            } catch (_) {
                recovery.hidden = retry.hidden = false;
                ui.status(pageStatus, t('saved_retry_refresh'), true);
                retry.focus();
            }
        }
        reopen.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            location.assign(reopen.href);
        });
        retry.onclick = async function () {
            retry.disabled = true;
            try {
                await savedStatus(t('list_refreshed'));
            } finally {
                retry.disabled = false;
                if (!retry.hidden) retry.focus();
            }
        };
        var form = q('[data-company-form]');
        var credentials = q('[data-credentials]');
        form.addEventListener('invalid', function (event) {
            if (credentials.contains(event.target)) credentials.open = true;
        }, true);
        function updateCompanyFields() {
            var hasCredentials = !!(editing?.id || form.elements.existing_company_id?.value);
            form.elements.corp_id.readOnly = hasCredentials;
            form.elements.app_secret.required = !hasCredentials;
            form.elements.app_secret.placeholder = hasCredentials ? t('secret_saved') : '';
        }
        function updateCallback() {
            var domain = form.elements.domain.value.trim().toLowerCase();
            var valid = /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])$/i.test(domain);
            form.elements.redirect_uri.value = valid ? options.callback_template.replace(':domain', domain) : '';
            q('[data-copy-callback]').disabled = !valid;
            ui.status(q('[data-copy-status]'), '');
        }
        function updateCredentials() {
            if (!options.can_configure_login) return;
            var section = q('[data-credentials]');
            section.hidden = !editing?.id && !form.elements.dingtalk_enabled.checked;
            section.querySelectorAll('input').forEach(function (input) {
                input.disabled = section.hidden;
            });
            var picker = form.elements.existing_company_id;
            picker.disabled = section.hidden || !editing?.login_site || !!editing?.id || picker.options.length < 2;
            q('[data-existing-company]').hidden = picker.disabled;
            updateCallback();
        }
        function showLogo(url) {
            var logo = q('[data-current-logo]');
            var image = q('img', logo);
            logo.hidden = !url;
            form.elements.remove_logo.checked = false;
            form.elements.remove_logo.disabled = !url;
            if (url) image.src = url;
            else image.removeAttribute('src');
        }
        function edit(company) {
            editing = company || null;
            form.reset();
            q('[data-edit-title]').textContent = t(company ? 'edit_company' : 'add');

            form.elements.name.value = company?.name || '';
            form.elements.corp_id.value = company?.corp_id || '';
            form.elements.app_key.value = company?.app_key || '';
            form.elements.enabled.checked = !!company?.enabled;
            if (options.can_configure_login) {
                var site = company?.login_site;
                var picker = form.elements.existing_company_id;
                picker.replaceChildren(new Option(t('new_dingtalk_company'), ''));
                if (site && !company.id) {
                    options.companies.forEach(function (candidate) {
                        if (candidate.id && !candidate.archived && !candidate.login_site) {
                            var label = candidate.name + ' (' + candidate.corp_id + ')';
                            picker.add(new Option(label, candidate.id));
                        }
                    });
                }
                form.elements.domain.value = site?.domain || '';
                form.elements.display_name.value = site?.display_name || company?.name || '';
                form.elements.password_enabled.checked = site ? site.password_enabled : true;
                form.elements.dingtalk_enabled.checked = site ? site.dingtalk_enabled : !!company?.enabled;
                showLogo(site?.logo_url);
                updateCredentials();
            } else {
                form.elements.redirect_uri.value = company?.redirect_uri || options.callback_url;
            }
            credentials.open = !options.can_configure_login || !company?.id;
            updateCompanyFields();
            ui.status(q('[data-status]', form), '');
            modal('edit').showModal();
            modal('edit').scrollTop = 0;
        }
        if (options.can_configure_login) {
            form.elements.domain.addEventListener('input', updateCallback);
            form.elements.existing_company_id.addEventListener('change', function () {
                var company = options.companies.find(candidate => String(candidate.id) === this.value);
                form.elements.corp_id.value = company?.corp_id || '';
                form.elements.app_key.value = company?.app_key || '';
                form.elements.app_secret.value = '';
                updateCompanyFields();
            });
            form.elements.dingtalk_enabled.addEventListener('change', function () {
                if (this.checked) {
                    form.elements.enabled.checked = true;
                    credentials.open = true;
                }
                updateCredentials();
            });
            q('[data-copy-callback]').addEventListener('click', async function () {
                var callback = form.elements.redirect_uri;
                try {
                    await navigator.clipboard.writeText(callback.value);
                    ui.status(q('[data-copy-status]'), t('callback_copied'));
                } catch (_) {
                    callback.focus();
                    callback.select();
                    ui.status(q('[data-copy-status]'), t('callback_selected'));
                }
            });
        }
        async function sync(company, button, progress) {
            if (syncs.has(company.id)) return;
            syncs.add(company.id);
            button.disabled = true;
            try {
                var done = false;
                while (!done && root.isConnected) {
                    var result = await api(companyUrl(company.id, '/sync'), { restart: false });
                    done = result.done;
                    progress.textContent = t('sync_progress', {
                        done: result.processed,
                        remaining: result.remaining,
                    });
                }
                if (root.isConnected) {
                    await refresh();
                    ui.status(q('[data-page-status]'), t('sync_complete'));
                }
            } catch (e) {
                if (root.isConnected) ui.status(q('[data-page-status]'), e.message, true);
            } finally {
                syncs.delete(company.id);
                button.disabled = false;
                if (root.isConnected) render();
            }
        }
        function details(company) {
            q('[data-directory-title]').textContent = company.name;
            modal('directory').showModal();
            if (directory) directory.cancel();
            directory = ui.directory(q('[data-company-directory]'), {
                t: t,
                fetch: function (dept, cursor, refresh, controller) {
                    var u = new URL(companyUrl(company.id, '/directory'), location.href);
                    Object.entries({ department: dept, cursor: cursor, refresh: refresh ? 1 : 0 }).forEach(
                        function (p) {
                            u.searchParams.set(p[0], p[1]);
                        },
                    );
                    return api(u.toString(), null, 'GET', controller);
                },
            });
        }
        function render() {
            var container = q('[data-company-cards]');
            container.replaceChildren();
            var companies = options.companies.filter(function (c) {
                return !c.archived || q('[data-show-archived]').checked;
            });
            if (!companies.length) {
                container.append(ui.el('div', t('empty_companies'), 'dc-empty'));
                return;
            }
            companies.forEach(function (c) {
                var card = ui.el('article', null, 'dc-card'),
                    head = ui.el('header'),
                    name = ui.el('div');
                name.append(ui.el('h4', c.name));
                if (c.login_site) name.append(ui.el('small', c.login_site.domain, 'dc-card-domain'));
                if (c.corp_id) name.append(ui.el('small', c.corp_id));
                head.append(name);
                if (c.id) {
                    head.append(ui.el(
                        'span',
                        t(c.archived ? 'archived_state' : c.enabled ? 'company_enabled' : 'company_disabled'),
                        'dc-badge' + (c.enabled ? '' : ' off'),
                    ));
                }
                card.append(head);

                var tools = ui.el('div', null, 'dg-toolbar'),
                    foot = ui.el('footer');
                tools.append(ui.button(t('edit_company'), function () {
                    edit(c);
                }));
                foot.append(tools);
                if (c.login_site) {
                    var methods = ui.el('div', null, 'dc-login-methods');
                    if (c.login_site.password_enabled) {
                        methods.append(ui.el('span', t('password_method'), 'dc-badge'));
                    }
                    if (c.login_site.dingtalk_enabled && c.enabled && !c.archived) {
                        methods.append(ui.el('span', t('dingtalk_method'), 'dc-badge'));
                    }
                    card.append(methods);
                }
                if (!c.id) {
                    card.append(foot);
                    container.append(card);
                    return;
                }
                var metrics = ui.el('div', null, 'dc-metrics');
                [
                    ['members_count', c.member_count],
                    ['paired_members', c.paired_members],
                    ['paired_accounts', c.paired_accounts],
                ].forEach(function (pair) {
                    var box = ui.el('div');
                    box.append(
                        ui.el('strong', pair[1] == null ? '—' : String(pair[1])),
                        ui.el('span', t(pair[0])),
                    );
                    metrics.append(box);
                });
                var info = ui.el('div', null, 'dc-card-info');
                info.append(
                    ui.el('p', t('last_synced') + '：' + (c.synced_at || t('not_synced'))),
                    ui.el('p', t('count_hint')),
                );
                var progress = ui.el('div', c.sync_pending ? t('sync_pending') : '', 'dc-sync-progress');
                var detailsButton = ui.button(t('details_company'), function () {
                    details(c);
                });
                detailsButton.disabled = !c.enabled;
                tools.append(detailsButton);
                var syncButton = ui.button(t('sync_company'), function () {
                    sync(c, syncButton, progress);
                });
                syncButton.disabled = !c.enabled || syncs.has(c.id);
                tools.append(syncButton);
                if (!c.archived)
                    foot.append(
                        ui.button(
                            t('archive_company'),
                            async function () {
                                if (!confirm(t('archive_confirm', { name: c.name }))) return;
                                this.disabled = true;
                                try {
                                    var r = await api(companyUrl(c.id), null, 'DELETE');
                                    try {
                                        await refresh();
                                        ui.status(q('[data-page-status]'), r.message);
                                    } catch (_) {
                                        ui.status(q('[data-page-status]'), t('refresh_failed'), true);
                                    }
                                } catch (e) {
                                    ui.status(q('[data-page-status]'), e.message, true);
                                    this.disabled = false;
                                }
                            },
                            'btn btn-default btn-sm dc-archive-action',
                        ),
                    );
                card.append(metrics, info, progress, foot);
                container.append(card);
            });
        }
        q('[data-show-archived]').onchange = render;
        q('[data-company-add]').onclick = function () {
            edit(null);
        };
        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            var body;
            if (options.can_configure_login) {
                var domain = form.elements.domain.value.trim().toLowerCase();
                if (editing) {
                    var oldDomain = editing.login_site?.domain;
                    var moving = oldDomain === location.hostname && domain !== oldDomain;
                    if (!confirm(t(moving ? 'domain_change_confirm' : 'change_confirm'))) return;
                }
                body = new FormData(form);
                body.set('login_configuration', '1');
                ['password_enabled', 'dingtalk_enabled', 'remove_logo', 'enabled'].forEach(function (key) {
                    body.set(key, form.elements[key].checked ? '1' : '0');
                });
                if (editing?.login_site?.id) body.set('site_id', editing.login_site.id);
                if (editing?.id) body.set('id', editing.id);
            } else {
                if (editing && !confirm(t('change_confirm'))) return;
                body = Object.fromEntries(new FormData(form));
                body.enabled = form.elements.enabled.checked;
                if (editing?.id) body.id = editing.id;
            }
            var saved = false;
            await ui.busy(form, t, async function () {
                var result = await api(options.save_url, body);
                saved = true;
                editing = { id: result.id, login_site: result.login_site };
                form.elements.app_secret.value = '';
                if (options.can_configure_login) {
                    form.elements.logo.value = '';
                }
                modal('edit').close();
                await savedStatus(result.message, result.redirect_url);
            });
            updateCompanyFields();
            updateCredentials();
            if (saved && options.can_configure_login && editing?.login_site) {
                showLogo(editing.login_site.logo_url);
            }
        });
        modal('directory').addEventListener('close', function () {
            if (directory) directory.cancel();
        });
        if (options.open_site) {
            var selected = options.companies.find(function (company) {
                return company.login_site?.id === options.open_site;
            });
            if (selected?.archived) q('[data-show-archived]').checked = true;
            if (selected) edit(selected);
        }
        render();
        active = function () {
            if (directory) directory.cancel();
            root.querySelectorAll('dialog[open]').forEach(function (d) {
                d.close();
            });
        };
        initializedRoot = root;
        root.dataset.initialized = '1';
    }
    window.ErpDingCompanies = { init: init };
    document.addEventListener('load', init, true);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    if (window.jQuery)
        window
            .jQuery(document)
            .on('pjax:end.erpDingCompanies', init)
            .on('pjax:beforeReplace.erpDingCompanies', function () {
                if (active) active();
            });
})();
