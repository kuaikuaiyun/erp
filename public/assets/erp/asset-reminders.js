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
            if (key === 'department_id' && (row.department_ids || row.owner_source_ids != null)) return;
            if (key === 'department_ids') value = JSON.parse(value).map(function (id) { return c.departments[id] || id; }).join(' / ');
            if (key === 'department_id') value = c.departments[value] || value;
            if (key === 'attendance_group_id' || key === 'attendance_group_name') return;
            if (key === 'calendar_company_id') value = c.calendarCompanies[value] || value;
            if (key === 'company_id') value = c.companies[value] || value;
            if (key === 'group_id') value = c.groups[value] || value;
            if (key === 'type') value = t.types[value];
            if (key === 'submission_types') value = JSON.parse(value).map(function (key) { return t.submission_types[key]; }).join(' / ');
            if (key === 'enabled') value = Number(value) ? t.enabled : t.disabled;
            if (key === 'webhook_configured' || key === 'webhook_secret_configured') value = value ? t.configured : t.not_configured;
            if (key === 'work_times' || key === 'rest_times') value = JSON.parse(value).join(' / ') || '—';
            var box = node('div'); box.append(node('label', t.fields[key]), node('pre', value)); grid.appendChild(box);
        });
        fields.appendChild(grid);
    }
    async function edit(type, id, readonly, notification) {
        if (busy) return;
        kind = type; recordId = id || null;
        var requestSeq = show(readonly ? t.view : id ? t.edit : t[notification ? 'add_notification' : type === 'groups' ? 'add_group' : 'add_rule'], readonly ? 'view' : 'edit');
        fields.appendChild(node('p', t.loading));
        try {
            var result = await Promise.all([readonly ? Promise.resolve(null) : api('/options'), id ? api('/' + type + '/' + id) : Promise.resolve({record: {}})]);
            if (seq !== requestSeq) return;
            fields.replaceChildren(); var options = result[0], row = result[1].record; version = row.version || null;
            if (readonly) { viewRecord(row); return; }
            var grid = node('div', null, 'ar-form-grid'); fields.appendChild(grid);
            var department = field(grid, 'department_id', row.department_id, options.departments);
            if (type === 'groups') { department.removeAttribute('name'); department.parentElement.remove(); grid.appendChild(node('p', t.group_owner_hint, 'ar-hint ar-wide')); }
            department.disabled = type === 'groups' && !!id;
            if (type === 'rules') {
                department.removeAttribute('name'); department.parentElement.remove();
                var section = node('section', null, 'ar-wide ar-department-picker'), search = node('input'), list = node('div', null, 'ar-department-list');
                var top = node('div', null, 'ar-heading'), count = node('span', '', 'ar-badge'), chips = node('div', null, 'ar-department-chips'), empty = node('p', t.department_no_match, 'ar-hint');
                search.className = 'form-control'; search.placeholder = t.department_search; search.setAttribute('aria-label', t.department_search);
                top.append(node('label', t.fields.department_ids), count); section.append(top, search, chips, list, empty); grid.appendChild(section);
                var selected = row.department_ids ? JSON.parse(row.department_ids) : row.department_id ? [row.department_id] : [];
                var groups = new Map();
                options.departments.forEach(function (d) {
                    var key = String(d.parent_id || d.id);
                    if (!groups.has(key)) groups.set(key, {name: d.parent_name || d.short_name || d.name, items: []});
                    if (d.parent_id || !d.has_children || selected.map(String).includes(String(d.id))) groups.get(key).items.push(d);
                });
                function refreshDepartments() {
                    chips.replaceChildren(); var checked = list.querySelectorAll('[data-department]:checked'); count.textContent = t.department_selected.replace(':count', checked.length);
                    Array.from(list.querySelectorAll('.ar-department-group')).forEach(function (group) {
                        var inputs = Array.from(group.querySelectorAll('[data-department]')), parent = group.querySelector('[data-department-parent]');
                        var chosen = inputs.filter(function (input) { return input.checked; }).length;
                        parent.checked = inputs.length > 0 && chosen === inputs.length; parent.indeterminate = chosen > 0 && chosen < inputs.length;
                        var matching = group.dataset.search.toLowerCase().includes(search.value.toLowerCase());
                        group.hidden = !matching;
                        var children = group.querySelector('.ar-department-children'), toggle = group.querySelector('.ar-department-toggle');
                        var expanded = search.value.trim() !== '' || group.dataset.expanded === 'true';
                        children.hidden = !expanded; toggle.setAttribute('aria-expanded', String(expanded)); toggle.setAttribute('aria-label', (expanded ? t.department_collapse : t.department_expand) + ' ' + toggle.dataset.title);
                        inputs.forEach(function (input) {
                            var label = input.closest('label'); label.classList.toggle('is-selected', input.checked);
                            if (input.checked) { var chip = node('button', input.dataset.label + ' ×', 'ar-department-chip'); chip.type = 'button'; chip.setAttribute('aria-label', t.department_remove + ' ' + input.dataset.label); chip.onclick = function () { input.checked = false; refreshDepartments(); }; chips.appendChild(chip); }
                        });
                    });
                    chips.hidden = !checked.length; empty.hidden = Array.from(list.children).some(function (group) { return !group.hidden; });
                }
                groups.forEach(function (data, key) {
                    var group = node('section', null, 'ar-department-group'), heading = node('div', null, 'ar-department-parent'), parent = node('input'), children = node('div', null, 'ar-department-children');
                    parent.type = 'checkbox'; parent.dataset.departmentParent = key; parent.disabled = !data.items.length;
                    var toggle = node('button', null, 'ar-department-toggle'); toggle.type = 'button'; toggle.dataset.title = data.name;
                    toggle.append(node('span', '▸', 'ar-department-arrow'), node('span', data.name));
                    children.id = 'ar-department-children-' + key; toggle.setAttribute('aria-controls', children.id);
                    parent.setAttribute('aria-label', t.department_select_all + ' ' + data.name);
                    group.dataset.expanded = 'false';
                    toggle.onclick = function () { group.dataset.expanded = group.dataset.expanded === 'true' ? 'false' : 'true'; refreshDepartments(); };
                    heading.append(parent, toggle); group.append(heading, children); group.dataset.search = data.name + ' ' + data.items.map(function (d) { return d.name; }).join(' ');
                    data.items.forEach(function (d) {
                        var label = node('label', null, 'ar-department-option'), checkbox = node('input'); checkbox.type = 'checkbox'; checkbox.dataset.department = d.id; checkbox.dataset.label = d.name; checkbox.checked = selected.map(String).includes(String(d.id));
                        label.append(checkbox, node('span', (d.short_name || d.name) + (d.has_children && !d.parent_id ? t.department_legacy : ''))); checkbox.onchange = refreshDepartments; children.appendChild(label);
                    });
                    if (!data.items.length) children.appendChild(node('p', t.department_no_children, 'ar-hint'));
                    parent.onchange = function () { children.querySelectorAll('[data-department]').forEach(function (input) { input.checked = parent.checked; }); refreshDepartments(); };
                    list.appendChild(group);
                });
                search.oninput = function () { if (!search.value.trim()) list.querySelectorAll('.ar-department-group').forEach(function (group) { group.dataset.expanded = 'false'; }); refreshDepartments(); }; refreshDepartments();
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
                var selectedType = row.type || (notification ? 'submission' : 'advertising');
                var typeSelect = field(grid, 'type', selectedType, choices(t.types).filter(function (choice) { return choice.id === selectedType; }));
                typeSelect.disabled = !!id || !!notification;
                if (selectedType === 'advertising') grid.appendChild(node('p', t.advertising_scope_hint, 'ar-hint ar-wide'));
                if (row.type === 'recap') { status.value = '0'; grid.appendChild(node('p', t.recap_disabled, 'ar-hint ar-wide')); }
                var group = field(grid, 'group_id', row.group_id, options.groups.map(function (g) { return {id: g.id, name: g.name}; }));
                var attendance = field(grid, 'calendar_company_id', '', []);
                function loadAttendance() {
                    var selectedGroup = options.groups.find(function (g) { return String(g.id) === group.value; });
                    attendance.replaceChildren(new Option(t.choose, ''));
                    var company = selectedGroup && options.companies.find(function (item) { return String(item.id) === String(selectedGroup.company_id); });
                    if (company) attendance.add(new Option(company.name, company.id));
                    if (String(row.group_id) === group.value && row.calendar_company_id) attendance.value = String(row.calendar_company_id);
                }
                attendance.parentElement.appendChild(node('p', t.calendar_hint, 'ar-hint'));
                group.onchange = loadAttendance;
                var start = field(grid, 'starts_on', row.starts_on || options.yesterday, null, 'date', t.start_hint); start.disabled = !!id; start.min = options.yesterday;
                field(grid, 'entry_url', id ? row.entry_url : c.defaultEntryUrl, null, 'url', t.url_hint);
                times(grid, 'work_times', row.work_times ? JSON.parse(row.work_times) : []);
                times(grid, 'rest_times', row.rest_times ? JSON.parse(row.rest_times) : []);
                grid.appendChild(node('p', t.times_hint, 'ar-hint ar-wide'));
                var submissions = node('section', null, 'ar-wide ar-submission-types'); submissions.dataset.submissions = 'true'; submissions.appendChild(node('label', t.fields.submission_types));
                var chosen = row.submission_types ? JSON.parse(row.submission_types) : [];
                Object.entries(t.submission_types).forEach(function (entry) { var label = node('label'), check = node('input'); check.type = 'checkbox'; check.dataset.submissionType = entry[0]; check.checked = chosen.includes(entry[0]); label.append(check, document.createTextNode(' ' + entry[1])); submissions.appendChild(label); }); grid.appendChild(submissions);
                function toggleKind() {
                    var instant = typeSelect.value === 'submission'; submissions.hidden = !instant;
                    [attendance.parentElement, start.parentElement].concat(Array.from(grid.querySelectorAll('[data-times]'))).forEach(function (box) { box.hidden = instant; box.querySelectorAll('input,select,button').forEach(function (input) { input.disabled = instant; }); });
                    if (!instant && group.value) loadAttendance(false);
                }
                typeSelect.onchange = toggleKind; toggleKind();
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
    async function remove(type, id, permanent) {
        if (busy) return;
        show(t[permanent ? 'remove' : type === 'rules' ? 'stop' : 'remove'], 'remove'); fields.appendChild(node('p', permanent ? t.rule_delete_confirm : t.remove_confirm, 'alert alert-warning'));
        var confirm = node('button', t[permanent ? 'remove' : type === 'rules' ? 'stop' : 'remove'], 'btn btn-danger'); confirm.type = 'button'; fields.appendChild(confirm);
        confirm.onclick = async function () { if (busy) return; busy = true; confirm.disabled = true; try { await api('/' + type + '/' + id + (permanent ? '?permanent=1' : ''), 'DELETE'); window.location.reload(); } catch (e) { fail(e.message); confirm.disabled = false; busy = false; } };
    }
    form.addEventListener('submit', async function (event) {
        event.preventDefault(); if (mode === 'calendar') { if (!busy) dialog.calendarSave(); return; } if (busy || mode !== 'edit' || save.disabled || !form.reportValidity()) return;
        var data = {version: version}; fields.querySelectorAll('[name]').forEach(function (e) { data[e.name] = e.value; }); data.enabled = data.enabled === '1';
        if (kind === 'rules') data.department_ids = Array.from(fields.querySelectorAll('[data-department]:checked')).map(function (input) { return Number(input.dataset.department); });
        if (data.type === 'submission') data.submission_types = Array.from(fields.querySelectorAll('[data-submission-type]:checked')).map(function (input) { return input.dataset.submissionType; });
        var clearSecret = fields.querySelector('[name=clear_webhook_secret]'); if (clearSecret) data.clear_webhook_secret = clearSecret.checked;
        fields.querySelectorAll('[data-times]').forEach(function (section) { data[section.dataset.times] = Array.from(section.querySelectorAll('input[type=time]')).map(function (input) { return input.value; }); });
        busy = true; fail(''); save.textContent = t.saving; form.setAttribute('aria-busy', 'true');
        var states = Array.from(form.querySelectorAll('input,select,textarea,button')).map(function (e) { return [e, e.disabled]; }); states.forEach(function (s) { s[0].disabled = true; });
        try { await api('/' + kind + (recordId ? '/' + recordId : ''), recordId ? 'PUT' : 'POST', data); window.location.reload(); }
        catch (e) { busy = false; form.removeAttribute('aria-busy'); states.forEach(function (s) { s[0].disabled = s[1]; }); save.textContent = t.save; fail(e.message); }
    });
    var calendarState = null;
    async function openCalendar() {
        if (busy) return;
        var requestSeq = show(t.calendar.title, 'calendar'), monthValue = new Date().toLocaleDateString('en-CA', {timeZone: 'Asia/Shanghai'}).slice(0, 7);
        var grid = node('div', null, 'ar-form-grid'); fields.appendChild(grid);
        var company = field(grid, 'company_id', '', []), month = field(grid, 'month', monthValue, null, 'month');
        company.parentElement.firstChild.nodeValue = t.calendar.company;
        var nav = node('div', null, 'ar-tools ar-wide'), body = node('div', null, 'ar-wide'); grid.append(nav, body);
        var dirty = false, loadedCompany = '', loadedMonth = monthValue, loadSeq = 0;
        function discard() { return !dirty || window.confirm(t.calendar.unsaved); }
        function draw() {
            body.replaceChildren(); if (!calendarState) return;
            var state = calendarState, writable = state.can_write;
            save.hidden = !writable; save.disabled = !writable;
            body.appendChild(node('p', state.version ? t.calendar.configured : t.calendar.unconfigured, 'ar-hint'));
            body.appendChild(node('p', t.calendar.shared_hint, 'ar-hint'));
            if (state.updated_at) body.appendChild(node('p', t.calendar.updated + ' ' + state.updated_by + ' · ' + new Date(state.updated_at.replace(' ', 'T') + 'Z').toLocaleString('zh-CN', {timeZone: 'Asia/Shanghai'}), 'ar-hint'));
            var tools = node('div', null, 'ar-tools'); body.appendChild(tools);
            [['weekdays', 'week'], ['all_work', 'work'], ['all_rest', 'rest']].forEach(function (item) {
                var button = node('button', t.calendar[item[0]], 'btn btn-default btn-sm'); button.type = 'button'; button.disabled = !writable;
                button.onclick = function () {
                    if (dirty && !window.confirm(t.calendar.replace)) return;
                    Object.keys(state.days).forEach(function (date) { var day = new Date(date + 'T12:00:00Z').getUTCDay(); state.days[date] = item[1] === 'week' ? (day === 0 || day === 6 ? 'rest' : 'work') : item[1]; }); dirty = true; draw();
                }; tools.appendChild(button);
            });
            var calendar = node('div', null, 'ar-calendar-grid'); body.appendChild(calendar);
            t.calendar.week.forEach(function (day) { calendar.appendChild(node('div', day, 'ar-calendar-week')); });
            var first = new Date(loadedMonth + '-01T12:00:00Z'), offset = (first.getUTCDay() + 6) % 7;
            for (var i = 0; i < offset; i++) calendar.appendChild(node('div'));
            Object.entries(state.days).forEach(function (entry) {
                var date = entry[0], value = entry[1], button = node('button', null, 'ar-calendar-day ' + (value || 'unset'));
                button.type = 'button'; button.disabled = !writable; button.setAttribute('aria-label', date + ' ' + (t.calendar[value] || t.calendar.unconfigured));
                button.append(node('strong', Number(date.slice(-2))), node('span', t.calendar[value] || t.calendar.unconfigured));
                button.onclick = function () { state.days[date] = value === 'work' ? 'rest' : 'work'; dirty = true; draw(); }; calendar.appendChild(button);
            });
        }
        async function load() {
            var current = ++loadSeq; calendarState = null; save.hidden = true; body.replaceChildren(node('p', t.loading)); fail('');
            if (!company.value || !/^\d{4}-\d{2}$/.test(month.value)) { body.replaceChildren(); return; }
            try {
                var result = await api('/calendar?company_id=' + encodeURIComponent(company.value) + '&month=' + encodeURIComponent(month.value));
                if (requestSeq !== seq || current !== loadSeq) return;
                loadedCompany = company.value; loadedMonth = month.value; dirty = false; calendarState = result;
                if (!result.days) { result.days = {}; var count = new Date(Number(loadedMonth.slice(0, 4)), Number(loadedMonth.slice(5)), 0).getDate(); for (var d = 1; d <= count; d++) result.days[loadedMonth + '-' + String(d).padStart(2, '0')] = null; }
                draw();
            } catch (e) { if (requestSeq === seq && current === loadSeq) { body.replaceChildren(); fail(e.message); } }
        }
        company.onchange = function () { if (!discard()) { company.value = loadedCompany; return; } dirty = false; load(); };
        month.onchange = function () { if (!discard()) { month.value = loadedMonth; return; } dirty = false; load(); };
        [-1, 1].forEach(function (delta) { var button = node('button', delta < 0 ? t.calendar.previous : t.calendar.next, 'btn btn-default btn-sm'); button.type = 'button'; button.onclick = function () { if (!discard()) return; var date = new Date(month.value + '-01T12:00:00Z'); date.setUTCMonth(date.getUTCMonth() + delta); month.value = date.toISOString().slice(0, 7); dirty = false; load(); }; nav.appendChild(button); });
        calendarState = null;
        dialog.calendarDirty = function () { return dirty; };
        dialog.calendarSave = async function () {
            if (!calendarState || !calendarState.can_write) return;
            if (Object.values(calendarState.days).some(function (value) { return value !== 'work' && value !== 'rest'; })) { fail(t.calendar.incomplete); return; }
            if (!window.confirm(t.calendar.impact)) return;
            busy = true; save.disabled = true; save.textContent = t.saving;
            var controls = Array.from(fields.querySelectorAll('input,select,button')); var states = controls.map(function (e) { return e.disabled; }); controls.forEach(function (e) { e.disabled = true; });
            try { await api('/calendar', 'PUT', {company_id: loadedCompany, month: loadedMonth, version: calendarState.version, days: calendarState.days}); dirty = false; await load(); }
            catch (e) { fail(e.message); }
            finally { busy = false; save.disabled = !calendarState || !calendarState.can_write; save.textContent = t.save; controls.forEach(function (e, i) { e.disabled = states[i]; }); }
        };
        try {
            var options = await api('/calendar?month=' + monthValue); if (seq !== requestSeq) return;
            options.companies.forEach(function (item) { company.add(new Option(item.name, item.id)); });
            if (options.companies.length) { company.value = options.companies[0].id; await load(); } else body.appendChild(node('p', t.no_company, 'ar-hint'));
        } catch (e) { if (seq === requestSeq) fail(e.message); }
    }
    document.querySelectorAll('[data-ar-calendar]').forEach(function (button) { button.onclick = openCalendar; });
    document.querySelectorAll('[data-ar-guide]').forEach(function (button) {
        button.onclick = function () {
            if (busy) return;
            var key = button.dataset.arGuide, template = document.getElementById('ar-guide-' + key);
            if (!template) return;
            show(t.guides[key].title, 'guide'); fields.appendChild(template.content.cloneNode(true));
        };
    });
    document.querySelectorAll('[data-ar-create]').forEach(function (b) { b.onclick = function () { edit(b.dataset.arCreate, null, false, b.hasAttribute('data-ar-notification')); }; });
    document.querySelectorAll('[data-ar-edit]').forEach(function (b) { b.onclick = function () { edit(b.dataset.arEdit, b.dataset.id, false); }; });
    document.querySelectorAll('[data-ar-view]').forEach(function (b) { b.onclick = function () { edit(b.dataset.arView, b.dataset.id, true); }; });
    document.querySelectorAll('[data-ar-preview]').forEach(function (b) { b.onclick = function () { message('preview', b.dataset.arPreview); }; });
    document.querySelectorAll('[data-ar-test]').forEach(function (b) { b.onclick = function () { message('test', b.dataset.arTest); }; });
    document.querySelectorAll('[data-ar-remove]').forEach(function (b) { b.onclick = function () { remove(b.dataset.arRemove, b.dataset.id); }; });
    document.querySelectorAll('[data-ar-delete]').forEach(function (b) { b.onclick = function () { remove('rules', b.dataset.arDelete, true); }; });
    document.querySelectorAll('[data-ar-runs]').forEach(function (b) { b.onclick = function () { runs(b.dataset.arRuns, 1); }; });
    dialog.querySelectorAll('[data-ar-close]').forEach(function (b) { b.onclick = function () { if (!busy && (mode !== 'calendar' || !dialog.calendarDirty() || window.confirm(t.calendar.unsaved))) dialog.close(); }; });
    dialog.addEventListener('cancel', function (event) { if (busy || (mode === 'calendar' && dialog.calendarDirty() && !window.confirm(t.calendar.unsaved))) event.preventDefault(); });
    dialog.addEventListener('close', function () { ++seq; fields.replaceChildren(); form.removeAttribute('aria-busy'); });
})(window.jQuery);
