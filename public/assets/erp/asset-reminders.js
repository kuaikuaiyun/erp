(function ($) {
    'use strict';
    var c = window.AssetRemindersConfig, t = c.text, dialog = document.getElementById('ar-modal');
    var form = document.getElementById('ar-form'), fields = document.getElementById('ar-fields'), error = document.getElementById('ar-error');
    var save = document.getElementById('ar-save'), mode = '', kind = '', recordId = null, version = null, busy = false, seq = 0;
    function node(tag, text, cls) { var e = document.createElement(tag); if (text != null) e.textContent = text; if (cls) e.className = cls; return e; }
    function fail(text) { error.textContent = text || ''; error.hidden = !text; if (text) dialog.querySelector('.ar-modal-body').scrollTop = 0; }
    async function api(path, method, data) {
        var response = await fetch(c.base + path, {method: method || 'GET', credentials: 'same-origin', headers: {
            Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }, body: data == null ? undefined : JSON.stringify(data)});
        var result = await response.json().catch(function () { return {}; });
        if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join('\n') : (response.status >= 500 ? t.failed : result.message || t.failed));
        return result;
    }
    function show(title, action) {
        ++seq; mode = action; fail(''); fields.replaceChildren(); save.hidden = action !== 'edit'; save.disabled = true; save.textContent = t.save;
        document.getElementById('ar-modal-title').textContent = title; if (!dialog.open) dialog.showModal();
        dialog.querySelector('.ar-modal-body').scrollTop = 0;
        return seq;
    }
    function field(grid, key, value, choices, type, hint) {
        var label = node('label', t.fields[key]), input = node(choices ? 'select' : type === 'textarea' ? 'textarea' : 'input');
        input.name = key; input.id = 'ar-input-' + key; input.className = 'form-control'; label.htmlFor = input.id;
        if (choices) {
            input.add(new Option(t.choose, ''));
            choices.forEach(function (choice) { input.add(new Option(choice.name, choice.id)); });
        } else if (type !== 'textarea') input.type = type || 'text';
        input.value = value == null ? '' : String(value); input.required = key !== 'remark' && key !== 'webhook_secret';
        input.maxLength = key === 'entry_url' || key === 'webhook' ? 1000 : key === 'remark' ? 500 : key === 'webhook_secret' ? 256 : key === 'name' ? 100 : 190;
        label.appendChild(input); if (hint) label.appendChild(node('p', hint, 'ar-hint')); grid.appendChild(label);
        return input;
    }
    function choices(map) { return Object.entries(map || {}).map(function (e) { return {id: e[0], name: e[1]}; }); }
    function times(grid, key, values) {
        var section = node('section', null, 'ar-time-section'), heading = node('label', t.fields[key]), rows = node('div'), add = node('button', t.time_add, 'btn btn-default btn-sm');
        section.dataset.times = key; add.type = 'button';
        function refresh() {
            Array.from(rows.children).forEach(function (row, index) { row.querySelector('.ar-round').textContent = t.reminder_rounds[index] || t.legacy_round; });
            add.disabled = rows.children.length >= 3;
        }
        function append(value, existing) {
            if (!existing && rows.children.length >= 3) return;
            var row = node('div', null, 'ar-time-row'), input = node('input'), remove = node('button', t.time_remove, 'btn btn-default btn-sm');
            input.type = 'time'; input.className = 'form-control'; input.value = value || ''; input.required = true; input.setAttribute('aria-label', t.fields[key]);
            remove.type = 'button'; remove.onclick = function () { row.remove(); refresh(); };
            row.append(node('span', '', 'ar-round'), input, remove); rows.appendChild(row); refresh();
        }
        add.onclick = function () { append(''); rows.lastElementChild.querySelector('input').focus(); };
        section.append(heading, rows, add); grid.appendChild(section); (values || []).forEach(function (value) { append(value, true); }); refresh();
        if ((values || []).length > 3) section.appendChild(node('p', t.legacy_times, 'ar-hint'));
    }
    function viewRecord(row) {
        var grid = node('div', null, 'ar-form-grid');
        Object.keys(t.fields).forEach(function (key) {
            if (row[key] == null || key === 'version') return;
            var value = row[key];
            if (key === 'department_id' && row.department_ids) return;
            if (key === 'department_ids') value = JSON.parse(value).map(function (id) { return c.departments[id] || id; }).join(' / ');
            if (key === 'department_id') value = c.departments[value] || value;
            if (key === 'company_id') value = c.companies[value] || value;
            if (key === 'group_id') value = c.groups[value] || value;
            if (key === 'type') value = t.types[value];
            if (key === 'enabled') value = Number(value) ? t.enabled : t.disabled;
            if (key === 'webhook_configured' || key === 'webhook_secret_configured') value = value ? t.configured : t.not_configured;
            if (key === 'work_times' || key === 'rest_times') value = JSON.parse(value).join(' / ') || '—';
            var box = node('div'); box.append(node('label', t.fields[key]), node('pre', value)); grid.appendChild(box);
        });
        fields.appendChild(grid);
    }
    async function edit(type, id, readonly) {
        if (busy) return;
        kind = type; recordId = id || null;
        var requestSeq = show(readonly ? t.view : id ? t.edit : t[type === 'groups' ? 'add_group' : 'add_rule'], readonly ? 'view' : 'edit');
        fields.appendChild(node('p', t.loading));
        try {
            var result = await Promise.all([readonly ? Promise.resolve(null) : api('/options'), id ? api('/' + type + '/' + id) : Promise.resolve({record: {}})]);
            if (seq !== requestSeq) return;
            fields.replaceChildren(); var options = result[0], row = result[1].record; version = row.version || null;
            if (readonly) { viewRecord(row); return; }
            var grid = node('div', null, 'ar-form-grid'); fields.appendChild(grid);
            var department = field(grid, 'department_id', row.department_id, options.departments);
            department.disabled = type === 'groups' && !!id;
            if (type === 'rules') {
                department.removeAttribute('name'); department.parentElement.remove();
                var section = node('section', null, 'ar-wide ar-department-picker'), search = node('input'), list = node('div', null, 'ar-department-list');
                var top = node('div', null, 'ar-heading'), count = node('span', '', 'ar-badge'), chips = node('div', null, 'ar-department-chips'), empty = node('p', t.department_no_match, 'ar-hint');
                search.className = 'form-control'; search.placeholder = t.department_search; search.setAttribute('aria-label', t.department_search);
                top.append(node('label', t.fields.department_ids), count); section.append(top, search, chips, list, empty); grid.appendChild(section);
                var selected = row.department_ids ? JSON.parse(row.department_ids) : row.department_id ? [row.department_id] : [];
                function refreshDepartments() {
                    chips.replaceChildren(); var checked = list.querySelectorAll('[data-department]:checked'); count.textContent = t.department_selected.replace(':count', checked.length);
                    Array.from(list.children).forEach(function (label) { var input = label.querySelector('input'); label.classList.toggle('is-selected', input.checked); label.hidden = !label.textContent.toLowerCase().includes(search.value.toLowerCase());
                        if (input.checked) { var chip = node('button', label.textContent + ' ×', 'ar-department-chip'); chip.type = 'button'; chip.setAttribute('aria-label', t.department_remove + ' ' + label.textContent); chip.onclick = function () { input.checked = false; refreshDepartments(); }; chips.appendChild(chip); }
                    });
                    chips.hidden = !checked.length; empty.hidden = Array.from(list.children).some(function (label) { return !label.hidden; });
                }
                options.departments.forEach(function (d) { var label = node('label', null, 'ar-department-option'), checkbox = node('input'); checkbox.type = 'checkbox'; checkbox.dataset.department = d.id; checkbox.checked = selected.map(String).includes(String(d.id)); label.append(checkbox, node('span', d.name)); checkbox.onchange = refreshDepartments; list.appendChild(label); });
                search.oninput = refreshDepartments; refreshDepartments();
            }
            var status = field(grid, 'enabled', row.enabled == null ? '0' : row.enabled, [{id: 0, name: t.disabled}, {id: 1, name: t.enabled}]);
            if (type === 'groups') {
                field(grid, 'name', row.name);
                field(grid, 'company_id', row.company_id, options.companies, null, t.company_hint);
                var webhook = field(grid, 'webhook', '', null, 'password', row.webhook_configured ? t.webhook_keep : t.webhook_hint);
                webhook.required = !row.webhook_configured; webhook.autocomplete = 'new-password'; webhook.spellcheck = false;
                var secret = field(grid, 'webhook_secret', '', null, 'password', row.webhook_secret_configured ? t.secret_keep : t.secret_hint);
                secret.autocomplete = 'new-password'; secret.spellcheck = false;
                if (row.webhook_secret_configured) {
                    var clearLabel = node('label'), clearSecret = node('input');
                    clearSecret.type = 'checkbox'; clearSecret.name = 'clear_webhook_secret'; clearLabel.append(clearSecret, document.createTextNode(' ' + t.fields.clear_webhook_secret));
                    grid.appendChild(clearLabel);
                }
                field(grid, 'remark', row.remark, null, 'textarea');
                var hint = node('p', t.config_hint, 'ar-hint ar-wide'); grid.appendChild(hint);
                if (!options.companies.length) grid.appendChild(node('p', t.no_company, 'ar-hint ar-wide'));
            } else {
                var typeSelect = field(grid, 'type', row.type || 'recap', choices(t.types)); typeSelect.disabled = !!id;
                var group = field(grid, 'group_id', row.group_id, options.groups.map(function (g) { return {id: g.id, name: g.name}; }));
                var attendance = field(grid, 'attendance_group_id', '', []), refresh = node('button', t.refresh, 'btn btn-default btn-sm'), info = node('p', '', 'ar-week');
                refresh.type = 'button'; attendance.parentElement.append(refresh, info);
                var attendanceSeq = 0;
                async function loadAttendance(force) {
                    var n = ++attendanceSeq, selectedGroup = options.groups.find(function (g) { return String(g.id) === group.value; });
                    attendance.replaceChildren(new Option(t.choose, '')); info.textContent = ''; attendance.disabled = true;
                    if (!selectedGroup) return;
                    refresh.disabled = true; info.textContent = t.loading;
                    try {
                        var result = await api('/attendance?company_id=' + encodeURIComponent(selectedGroup.company_id) + (force ? '&refresh=1' : ''));
                        if (n !== attendanceSeq || seq !== requestSeq) return;
                        result.groups.forEach(function (g) { attendance.add(new Option(g.group_name, g.group_id)); });
                        if (String(row.group_id) === group.value) attendance.value = String(row.attendance_group_id || '');
                        attendance.disabled = false; info.textContent = t.calendar_hint; fail('');
                    } catch (e) { if (seq === requestSeq) { info.textContent = e.message; fail(e.message); } }
                    finally { if (n === attendanceSeq && seq === requestSeq) refresh.disabled = false; }
                }
                group.onchange = function () { loadAttendance(false); }; refresh.onclick = function () { loadAttendance(true); };
                var start = field(grid, 'starts_on', row.starts_on || options.yesterday, null, 'date', t.start_hint); start.disabled = !!id; start.min = options.yesterday;
                field(grid, 'entry_url', id ? row.entry_url : c.defaultEntryUrl, null, 'url', t.url_hint);
                times(grid, 'work_times', row.work_times ? JSON.parse(row.work_times) : []);
                times(grid, 'rest_times', row.rest_times ? JSON.parse(row.rest_times) : []);
                grid.appendChild(node('p', t.times_hint, 'ar-hint ar-wide'));
                if (group.value) await loadAttendance(false);
            }
            if (seq === requestSeq) save.disabled = false;
        } catch (e) { if (seq === requestSeq) { fields.replaceChildren(); fail(e.message); } }
    }
    async function message(action, id) {
        if (busy) return;
        var n = show(t[action], action); fields.appendChild(node('p', t.loading));
        try {
            var data = await api('/rules/' + id + '/preview'); if (seq !== n) return;
            fields.replaceChildren(node('pre', data.message));
            if (action === 'test') {
                fields.prepend(node('p', t.test_confirm, 'alert alert-warning'));
                var confirm = node('button', t.test, 'btn btn-primary'); confirm.type = 'button'; fields.appendChild(confirm);
                confirm.onclick = async function () {
                    if (busy) return;
                    busy = true; confirm.disabled = true; confirm.textContent = t.saving;
                    try { var result = await api('/rules/' + id + '/test', 'POST', {confirmed: true}); fields.replaceChildren(node('p', result.message, 'alert alert-success')); }
                    catch (e) { fail(e.message); }
                    finally { busy = false; confirm.textContent = t.test; /* Deliberate new preview required before retry. */ }
                };
            }
        } catch (e) { if (seq === n) { fields.replaceChildren(); fail(e.message); } }
    }
    async function runs(id, page) {
        if (busy) return;
        var n = show(t.runs, 'runs'); fields.appendChild(node('p', t.loading));
        try {
            var result = await api('/runs?group_id=' + encodeURIComponent(id) + '&page=' + (page || 1)); if (seq !== n) return;
            fields.replaceChildren();
            result.data.forEach(function (run) {
                var item = node('details', null, 'ar-run'), date = new Date(run.created_at.replace(' ', 'T') + 'Z');
                item.appendChild(node('summary', date.toLocaleString('zh-CN', {timeZone: 'Asia/Shanghai'}) + ' · ' + (run.is_test ? t.message.test + ' · ' : '') + t.types[run.type] + ' · ' + (t.statuses[run.status] || run.status)));
                if (run.error) item.appendChild(node('p', run.error, 'alert alert-warning'));
                if (run.message) item.appendChild(node('pre', run.message)); fields.appendChild(item);
            });
            if (!result.data.length) fields.appendChild(node('p', t.empty));
            var nav = node('div', null, 'ar-tools');
            if (result.current_page > 1) { var prev = node('button', t.previous, 'btn btn-default'); prev.type = 'button'; prev.onclick = function () { runs(id, result.current_page - 1); }; nav.appendChild(prev); }
            nav.appendChild(node('span', result.current_page + ' / ' + result.last_page));
            if (result.current_page < result.last_page) { var next = node('button', t.next, 'btn btn-default'); next.type = 'button'; next.onclick = function () { runs(id, result.current_page + 1); }; nav.appendChild(next); } fields.appendChild(nav);
        } catch (e) { if (seq === n) { fields.replaceChildren(); fail(e.message); } }
    }
    async function remove(type, id) {
        if (busy) return;
        show(t[type === 'rules' ? 'stop' : 'remove'], 'remove'); fields.appendChild(node('p', t.remove_confirm, 'alert alert-warning'));
        var confirm = node('button', t[type === 'rules' ? 'stop' : 'remove'], 'btn btn-danger'); confirm.type = 'button'; fields.appendChild(confirm);
        confirm.onclick = async function () { if (busy) return; busy = true; confirm.disabled = true; try { await api('/' + type + '/' + id, 'DELETE'); window.location.reload(); } catch (e) { fail(e.message); confirm.disabled = false; busy = false; } };
    }
    form.addEventListener('submit', async function (event) {
        event.preventDefault(); if (busy || mode !== 'edit' || save.disabled || !form.reportValidity()) return;
        var data = {version: version}; fields.querySelectorAll('[name]').forEach(function (e) { data[e.name] = e.value; }); data.enabled = data.enabled === '1';
        if (kind === 'rules') data.department_ids = Array.from(fields.querySelectorAll('[data-department]:checked')).map(function (input) { return Number(input.dataset.department); });
        var clearSecret = fields.querySelector('[name=clear_webhook_secret]'); if (clearSecret) data.clear_webhook_secret = clearSecret.checked;
        fields.querySelectorAll('[data-times]').forEach(function (section) { data[section.dataset.times] = Array.from(section.querySelectorAll('input[type=time]')).map(function (input) { return input.value; }); });
        busy = true; fail(''); save.textContent = t.saving; form.setAttribute('aria-busy', 'true');
        var states = Array.from(form.querySelectorAll('input,select,textarea,button')).map(function (e) { return [e, e.disabled]; }); states.forEach(function (s) { s[0].disabled = true; });
        try { await api('/' + kind + (recordId ? '/' + recordId : ''), recordId ? 'PUT' : 'POST', data); window.location.reload(); }
        catch (e) { busy = false; form.removeAttribute('aria-busy'); states.forEach(function (s) { s[0].disabled = s[1]; }); save.textContent = t.save; fail(e.message); }
    });
    document.querySelectorAll('[data-ar-guide]').forEach(function (button) {
        button.onclick = function () {
            if (busy) return;
            var key = button.dataset.arGuide, template = document.getElementById('ar-guide-' + key);
            if (!template) return;
            show(t.guides[key].title, 'guide'); fields.appendChild(template.content.cloneNode(true));
        };
    });
    document.querySelectorAll('[data-ar-create]').forEach(function (b) { b.onclick = function () { edit(b.dataset.arCreate, null, false); }; });
    document.querySelectorAll('[data-ar-edit]').forEach(function (b) { b.onclick = function () { edit(b.dataset.arEdit, b.dataset.id, false); }; });
    document.querySelectorAll('[data-ar-view]').forEach(function (b) { b.onclick = function () { edit(b.dataset.arView, b.dataset.id, true); }; });
    document.querySelectorAll('[data-ar-preview]').forEach(function (b) { b.onclick = function () { message('preview', b.dataset.arPreview); }; });
    document.querySelectorAll('[data-ar-test]').forEach(function (b) { b.onclick = function () { message('test', b.dataset.arTest); }; });
    document.querySelectorAll('[data-ar-remove]').forEach(function (b) { b.onclick = function () { remove(b.dataset.arRemove, b.dataset.id); }; });
    document.querySelectorAll('[data-ar-runs]').forEach(function (b) { b.onclick = function () { runs(b.dataset.arRuns, 1); }; });
    dialog.querySelectorAll('[data-ar-close]').forEach(function (b) { b.onclick = function () { if (!busy) dialog.close(); }; });
    dialog.addEventListener('cancel', function (event) { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', function () { ++seq; fields.replaceChildren(); form.removeAttribute('aria-busy'); });
})(window.jQuery);
