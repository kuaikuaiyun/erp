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
            previewUrl = null,
            logoFile = null,
            logoBase = null,
            logoUndo = null,
            syncs = new Set();
        function q(s, p) {
            return (p || root).querySelector(s);
        }
        function modal(n) {
            return q('[data-modal="' + n + '"]');
        }
        async function upload(path, body) {
            var controller = new AbortController(),
                expired = false;
            var timer = setTimeout(function () {
                expired = true;
                controller.abort();
            }, 30000);
            try {
                var response = await fetch(path, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': options.csrf,
                    },
                });
                if (response.redirected) throw Error(t('session_expired'));
                var data;
                try {
                    data = await response.json();
                } catch (_) {
                    throw Error(t('request_error'));
                }
                if (!response.ok)
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
        function api(path, body, method, controller) {
            if (body instanceof FormData) return upload(path, body);
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
        function edit(company) {
            editing = company || null;
            var form = q('[data-company-form]');
            form.reset();
            q('[data-edit-title]').textContent = t(company ? 'edit_company' : 'add');
            [
                'name',
                'corp_id',
                'app_key',
                'redirect_uri',
                'callback_owner_key',
                'login_slug',
                'login_display_name',
                'login_page_title',
                'login_welcome',
            ].forEach(function (k) {
                form.elements[k].value = company
                    ? company[k] || ''
                    : k === 'redirect_uri'
                      ? options.callback_url
                      : '';
            });
            form.elements.corp_id.readOnly = !!company;
            form.elements.enabled.checked = company ? company.enabled : false;
            ['app_secret', 'callback_token', 'callback_aes_key'].forEach(function (k) {
                form.elements[k].value = '';
                form.elements[k].required = !company;
                form.elements[k].placeholder = company ? t('secret_saved') : '';
            });
            logoFile = null;
            logoUndo = null;
            logoBase = company && company.logo_url ? { url: company.logo_url, file: null } : null;
            form.elements.logo_remove.value = '0';
            renderLogo();
            ui.status(q('[data-status]', form), '');
            modal('edit').showModal();
            modal('edit').scrollTop = 0;
        }
        function releasePreview() {
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = null;
        }
        function renderLogo() {
            releasePreview();
            var form = q('[data-company-form]');
            var removing = form.elements.logo_remove.value === '1';
            var file = logoFile || (!removing && logoBase && logoBase.file);
            var url = !removing && logoBase && logoBase.url;
            var pending = !!logoFile || removing || !!logoUndo;
            var hasLogo = !!file || !!url;
            if (file) {
                previewUrl = URL.createObjectURL(file);
                url = previewUrl;
            }
            var img = q('[data-logo-preview]');
            img.hidden = !hasLogo;
            if (hasLogo) img.src = url;
            else img.removeAttribute('src');
            q('[data-logo-default]').hidden = hasLogo;
            q('.dc-logo-card').dataset.pending = pending ? 'true' : 'false';
            q('[data-logo-status]').textContent = t(
                logoFile
                    ? 'logo_upload_pending'
                    : pending
                      ? 'logo_default_pending'
                      : hasLogo
                        ? 'logo_saved'
                        : 'default_logo',
            );
            q('[data-logo-filename]').textContent = logoFile ? logoFile.name : '';
            q('[data-logo-filename]').hidden = !logoFile;
            q('[data-logo-upload-label]').textContent = t(hasLogo ? 'replace_logo' : 'upload_logo');
            var busy = form.getAttribute('aria-busy') === 'true';
            q('[data-logo-upload]').disabled = busy;
            q('[data-logo-reset]').disabled = busy || !hasLogo;
            q('[data-logo-undo]').hidden = !pending;
            q('[data-logo-undo]').disabled = busy;
        }
        function copyLink(input) {
            (async function () {
                try {
                    if (navigator.clipboard && window.isSecureContext)
                        await navigator.clipboard.writeText(input.value);
                    else {
                        input.focus();
                        input.select();
                        if (!document.execCommand('copy')) throw Error();
                    }
                    ui.status(q('[data-page-status]'), t('copied'));
                } catch (_) {
                    input.focus();
                    input.select();
                    ui.status(q('[data-page-status]'), t('copy_failed'), true);
                }
            })();
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
                name.append(ui.el('h4', c.name), ui.el('small', c.corp_id));
                head.append(
                    name,
                    ui.el(
                        'span',
                        t(c.archived ? 'archived_state' : c.enabled ? 'company_enabled' : 'company_disabled'),
                        'dc-badge' + (c.enabled ? '' : ' off'),
                    ),
                );
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
                    ui.el('p', t('events_address') + '：' + c.events_url),
                );
                if (c.login_url) {
                    var link = ui.el('input');
                    link.type = 'text';
                    link.readOnly = true;
                    link.value = c.login_url;
                    link.className = 'form-control';
                    link.setAttribute('aria-label', t('entry_link'));
                    info.append(
                        ui.el('label', t('entry_link'), 'dg-help'),
                        link,
                        ui.button(t('copy_link'), function () {
                            copyLink(link);
                        }),
                    );
                } else info.append(ui.el('p', t('missing_slug')));
                var tools = ui.el('div', null, 'dg-toolbar'),
                    foot = ui.el('footer'),
                    progress = ui.el('div', c.sync_pending ? t('sync_pending') : '', 'dc-sync-progress');
                tools.append(
                    ui.button(t('edit_company'), function () {
                        edit(c);
                    }),
                    ui.button(t('details_company'), function () {
                        details(c);
                    }),
                );
                var syncButton = ui.button(t('sync_company'), function () {
                    sync(c, syncButton, progress);
                });
                syncButton.disabled = !c.enabled || syncs.has(c.id);
                tools.append(syncButton);
                tools.children[1].disabled = !c.enabled;
                foot.append(tools);
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
                            'btn btn-default btn-sm',
                        ),
                    );
                card.append(head, metrics, info, progress, foot);
                container.append(card);
            });
        }
        q('[data-show-archived]').onchange = render;
        q('[data-company-add]').onclick = function () {
            edit(null);
        };
        var companyForm = q('[data-company-form]');
        q('[data-logo-upload]').onclick = function () {
            companyForm.elements.logo.click();
        };
        companyForm.elements.logo.addEventListener('change', function () {
            var file = companyForm.elements.logo.files[0];
            if (!file) return;
            logoFile = file;
            logoUndo = null;
            companyForm.elements.logo_remove.value = '0';
            companyForm.elements.logo.value = '';
            renderLogo();
        });
        q('[data-logo-reset]').onclick = function () {
            logoUndo = { file: logoFile, remove: companyForm.elements.logo_remove.value };
            logoFile = null;
            companyForm.elements.logo.value = '';
            companyForm.elements.logo_remove.value = logoBase ? '1' : '0';
            renderLogo();
        };
        q('[data-logo-undo]').onclick = function () {
            logoFile = logoUndo ? logoUndo.file : null;
            companyForm.elements.logo_remove.value = logoUndo ? logoUndo.remove : '0';
            logoUndo = null;
            renderLogo();
        };
        modal('edit').addEventListener('close', function () {
            releasePreview();
            logoFile = null;
            logoUndo = null;
            logoBase = null;
            companyForm.elements.logo.value = '';
            companyForm.elements.logo_remove.value = '0';
        });
        q('[data-company-form]').elements.app_key.addEventListener('change', function (e) {
            var owner = q('[data-company-form]').elements.callback_owner_key;
            if (!editing && !owner.value) owner.value = e.target.value;
        });
        q('[data-company-form]').addEventListener('submit', async function (e) {
            e.preventDefault();
            var form = e.currentTarget;
            if (editing && !confirm(t('change_confirm'))) return;
            var body = { enabled: form.elements.enabled.checked };
            [
                'name',
                'login_slug',
                'login_display_name',
                'login_page_title',
                'login_welcome',
                'corp_id',
                'app_key',
                'app_secret',
                'redirect_uri',
                'callback_token',
                'callback_aes_key',
                'callback_owner_key',
            ].forEach(function (k) {
                body[k] = form.elements[k].value;
            });
            body.logo_remove = form.elements.logo_remove.value === '1';
            var removingLogo = body.logo_remove;
            if (editing) body.id = editing.id;
            if (logoFile) {
                var multipart = new FormData();
                Object.keys(body).forEach(function (k) {
                    multipart.append(k, typeof body[k] === 'boolean' ? (body[k] ? '1' : '0') : body[k]);
                });
                multipart.append('logo', logoFile);
                body = multipart;
            }
            await ui.busy(form, t, async function () {
                var r = await api(options.save_url, body);
                ['app_secret', 'callback_token', 'callback_aes_key'].forEach(function (k) {
                    form.elements[k].value = '';
                });
                logoBase = logoFile ? { file: logoFile, url: null } : removingLogo ? null : logoBase;
                logoFile = null;
                logoUndo = null;
                form.elements.logo.value = '';
                form.elements.logo_remove.value = '0';
                editing = { id: r.id };
                form.elements.corp_id.readOnly = true;
                ['app_secret', 'callback_token', 'callback_aes_key'].forEach(function (k) {
                    form.elements[k].required = false;
                    form.elements[k].placeholder = t('secret_saved');
                });
                try {
                    await refresh();
                    modal('edit').close();
                    ui.status(q('[data-page-status]'), r.message);
                } catch (_) {
                    ui.status(q('[data-status]', form), t('refresh_failed'), true);
                }
            });
            if (root.isConnected) renderLogo();
        });
        modal('directory').addEventListener('close', function () {
            if (directory) directory.cancel();
        });
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
