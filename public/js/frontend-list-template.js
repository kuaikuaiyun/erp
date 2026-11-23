(function () {
    'use strict';
    if (window.ErpFrontendListTemplate) return;
    var active = null;

    function create(root) {
        var messages = JSON.parse(root.querySelector('[data-flt-texts]').textContent);
        var form = root.querySelector('[data-flt-filter]');
        var body = root.querySelector('[data-flt-rows]');
        var dialog = root.querySelector('[data-flt-dialog]');
        var editor = root.querySelector('[data-flt-editor]');
        var feedback = root.querySelector('[data-flt-feedback]');
        var records = [], filtered = [], selected = new Set(), pickers = {};
        var page = 1, size = 50, applied = {}, action = null, currentId = null, nextId = 121, pendingDeleteIds = [];

        function t(key, values) {
            return String(messages[key] || key).replace(/:([a-z_]+)/g, function (match, name) {
                return values && Object.prototype.hasOwnProperty.call(values, name) ? values[name] : match;
            });
        }
        function node(tag, className, text) {
            var el = document.createElement(tag);
            if (className) el.className = className;
            if (text !== undefined) el.textContent = text;
            return el;
        }
        function say(text, error) {
            feedback.textContent = text;
            feedback.hidden = !text;
            feedback.dataset.error = error ? '1' : '0';
        }
        function seed() {
            return Array.from({ length: 120 }, function (_, index) {
                var id = index + 1;
                return {
                    id: id, code: 'DEMO-' + String(id).padStart(5, '0'),
                    name: t('record_name', { number: String(id).padStart(3, '0') }),
                    company: messages.companies[index % messages.companies.length],
                    department: messages.departments[(index + Math.floor(index / 6)) % messages.departments.length],
                    owner: messages.owners[(index * 5 + Math.floor(index / 6)) % messages.owners.length],
                    status: ['active', 'active', 'pending', 'active', 'disabled'][index % 5],
                    time: '2026-11-' + String(1 + index % 23).padStart(2, '0') + 'T' + String(9 + index % 9).padStart(2, '0') + ':' + String(index % 60).padStart(2, '0'),
                    note: t('demo_note')
                };
            });
        }
        function makePicker(host) {
            var key = host.dataset.fltPicker, multi = host.dataset.multiple === '1';
            var values = new Set(), choices = messages[key];
            var details = node('details', 'flt-picker'), summary = node('summary', '', t('all'));
            summary.setAttribute('aria-labelledby', host.getAttribute('aria-labelledby'));
            var panel = node('div', 'flt-picker-panel'), options = node('div', 'flt-picker-options');
            var search = null;
            if (host.dataset.searchable === '1') {
                search = node('input'); search.type = 'search'; search.placeholder = t('search_options');
                search.setAttribute('aria-label', t('search_options')); panel.appendChild(search);
                search.addEventListener('input', drawOptions);
            }
            function refreshLabel() {
                summary.textContent = values.size === 0 ? t('all') : (multi ? t('selected_options', { names: Array.from(values).join('、'), count: values.size }) : Array.from(values)[0]);
                summary.title = Array.from(values).join('、') || t('all');
            }
            function drawOptions() {
                options.replaceChildren();
                var visible = choices.filter(function (choice) { return !search || choice.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase()); });
                if (!visible.length) options.appendChild(node('p', '', t('no_options')));
                visible.forEach(function (choice) {
                    var label = node('label'), input = node('input');
                    input.type = multi ? 'checkbox' : 'radio'; input.name = 'flt-picker-' + key;
                    input.value = choice; input.checked = values.has(choice);
                    input.addEventListener('change', function () {
                        if (!multi) values.clear();
                        if (input.checked) values.add(choice); else values.delete(choice);
                        refreshLabel();
                        if (!multi) { details.open = false; summary.focus(); }
                    });
                    label.append(input, node('span', '', choice)); options.appendChild(label);
                });
            }
            var clear = node('button', 'flt-picker-clear', t('clear_choice')); clear.type = 'button';
            clear.onclick = function () { values.clear(); if (search) search.value = ''; refreshLabel(); drawOptions(); };
            panel.append(options, clear); details.append(summary, panel); host.replaceChildren(details);
            details.addEventListener('toggle', function () {
                if (details.open) {
                    Object.values(pickers).forEach(function (other) { if (other.details !== details) other.details.open = false; });
                    drawOptions(); if (search) search.focus();
                }
            });
            drawOptions();
            return { details: details, values: values, clear: function () { values.clear(); if (search) search.value = ''; details.open = false; refreshLabel(); drawOptions(); } };
        }
        root.querySelectorAll('[data-flt-picker]').forEach(function (host) { pickers[host.dataset.fltPicker] = makePicker(host); });

        function apply() {
            var keyword = (applied.keyword || '').toLocaleLowerCase();
            filtered = records.filter(function (row) {
                return (!keyword || (row.name + ' ' + row.code).toLocaleLowerCase().includes(keyword)) &&
                    (!applied.status || row.status === applied.status) &&
                    (!applied.companies || !applied.companies.length || applied.companies.includes(row.company)) &&
                    (!applied.departments || !applied.departments.length || applied.departments.includes(row.department)) &&
                    (!applied.owners || !applied.owners.length || applied.owners.includes(row.owner)) &&
                    (!applied.start || row.time >= applied.start) && (!applied.end || row.time <= applied.end);
            });
            page = Math.max(1, Math.min(page, Math.ceil(filtered.length / size) || 1));
            render();
        }
        function visibleRows() { return filtered.slice((page - 1) * size, page * size); }
        function updateSelection() {
            var visible = visibleRows(), count = visible.filter(function (row) { return selected.has(row.id); }).length;
            var all = root.querySelector('[data-flt-all]');
            all.checked = visible.length > 0 && count === visible.length;
            all.indeterminate = count > 0 && count < visible.length; all.disabled = !visible.length;
            root.querySelector('[data-flt-selection-count]').textContent = t('selected_records', { count: selected.size });
            root.querySelector('[data-flt-clear-selected]').disabled = selected.size === 0;
            root.querySelector('[data-flt-bulk-delete]').disabled = selected.size === 0;
            body.querySelectorAll('[data-flt-check-id]').forEach(function (input) {
                input.checked = selected.has(Number(input.dataset.fltCheckId));
                input.closest('tr').dataset.selected = input.checked ? '1' : '0';
            });
        }
        function render() {
            body.replaceChildren();
            visibleRows().forEach(function (row) {
                var tr = node('tr'), checkCell = node('td'), check = node('input');
                check.type = 'checkbox'; check.dataset.fltCheckId = row.id;
                check.setAttribute('aria-label', t('check_record', { id: row.id })); checkCell.appendChild(check); tr.appendChild(checkCell);
                tr.appendChild(node('td', '', row.id));
                var name = node('td', 'flt-name'); name.append(node('strong', '', row.name), node('small', '', row.code)); tr.appendChild(name);
                ['company', 'department', 'owner'].forEach(function (key) { tr.appendChild(node('td', '', row[key])); });
                var status = node('td'), badge = node('span', 'flt-status', messages.statuses[row.status]); badge.dataset.status = row.status; status.appendChild(badge); tr.appendChild(status);
                tr.appendChild(node('td', 'flt-time', row.time.replace('T', ' ')));
                var actions = node('td');
                ['view', 'edit', 'delete'].forEach(function (key) {
                    var button = node('button', 'flt-row-action', t(key)); button.type = 'button'; button.dataset.action = key; button.dataset.id = row.id; actions.appendChild(button);
                });
                tr.appendChild(actions); body.appendChild(tr);
            });
            root.querySelector('[data-flt-empty]').hidden = filtered.length !== 0;
            root.querySelector('[data-flt-total]').textContent = t('records', { count: filtered.length });
            var pages = Math.ceil(filtered.length / size) || 1;
            root.querySelector('[data-flt-page-label]').textContent = t('page_label', { page: page, pages: pages });
            root.querySelector('[data-flt-previous]').disabled = page <= 1;
            root.querySelector('[data-flt-next]').disabled = page >= pages;
            var jump = root.querySelector('[data-flt-jump] input'); jump.max = pages; jump.value = '';
            updateSelection();
        }
        function reset() {
            applied = {}; page = 1; selected.clear();
            Object.values(pickers).forEach(function (picker) { picker.clear(); });
            say(''); apply();
        }
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var start = form.elements.start.value, end = form.elements.end.value;
            if (start && end && start > end) { say(t('invalid_range'), true); return; }
            applied = { keyword: form.elements.keyword.value.trim(), status: form.elements.status.value, start: start, end: end };
            Object.keys(pickers).forEach(function (key) { applied[key] = Array.from(pickers[key].values); pickers[key].details.open = false; });
            page = 1; say(''); apply();
        });
        form.addEventListener('reset', reset);
        root.querySelector('[data-flt-restore]').onclick = function () { records = seed(); nextId = 121; form.reset(); say(t('restored')); };
        root.querySelector('[data-flt-clear-selected]').onclick = function () { selected.clear(); updateSelection(); };
        root.querySelector('[data-flt-all]').onchange = function (event) {
            visibleRows().forEach(function (row) { if (event.target.checked) selected.add(row.id); else selected.delete(row.id); }); updateSelection();
        };
        body.addEventListener('change', function (event) {
            var input = event.target.closest('[data-flt-check-id]'); if (!input) return;
            if (input.checked) selected.add(Number(input.dataset.fltCheckId)); else selected.delete(Number(input.dataset.fltCheckId)); updateSelection();
        });
        root.querySelector('[data-flt-size]').onchange = function (event) { size = Number(event.target.value); page = 1; render(); };
        root.querySelector('[data-flt-previous]').onclick = function () { if (page > 1) { page--; render(); } };
        root.querySelector('[data-flt-next]').onclick = function () { if (page < Math.ceil(filtered.length / size)) { page++; render(); } };
        root.querySelector('[data-flt-jump]').onsubmit = function (event) {
            event.preventDefault();
            var value = Number(event.currentTarget.querySelector('input').value), pages = Math.ceil(filtered.length / size) || 1;
            if (!Number.isInteger(value) || value < 1 || value > pages) { say(t('invalid_page', { pages: pages }), true); return; }
            page = value; say(''); render();
        };
        function close() { if (dialog.open) dialog.close(); action = null; currentId = null; pendingDeleteIds = []; }
        root.querySelectorAll('[data-flt-close]').forEach(function (button) { button.onclick = close; });
        dialog.addEventListener('cancel', function (event) { event.preventDefault(); close(); });
        function addEditField(parent, key, value, choices) {
            var label = node('label', key === 'name' || key === 'note' ? 'flt-wide' : '', key === 'note' ? t('note') : messages.columns[key]);
            var input = node(choices ? 'select' : key === 'note' ? 'textarea' : 'input'); input.name = key;
            if (choices) Object.entries(choices).forEach(function (entry) { var option = node('option', '', entry[1]); option.value = entry[0]; input.appendChild(option); });
            else { input.maxLength = key === 'note' ? 500 : 100; if (key === 'name') input.required = true; }
            input.value = value; label.appendChild(input); parent.appendChild(label);
        }
        function openRecord(mode, row) {
            action = mode; currentId = row ? row.id : null;
            root.querySelector('#flt-dialog-title').textContent = t(action + '_title');
            var content = root.querySelector('[data-flt-dialog-content]'); content.replaceChildren();
            var submit = root.querySelector('[data-flt-dialog-submit]'); submit.hidden = action === 'view'; submit.classList.toggle('flt-danger', action === 'delete' || action === 'bulk_delete');
            submit.textContent = t(action === 'delete' || action === 'bulk_delete' ? 'confirm_delete' : action === 'create' ? 'create_save' : 'save');
            if (action === 'view') {
                var list = node('dl');
                Object.keys(messages.columns).filter(function (key) { return key !== 'actions'; }).concat('note').forEach(function (key) {
                    list.append(node('dt', '', key === 'note' ? t('note') : messages.columns[key]), node('dd', '', key === 'status' ? messages.statuses[row.status] : key === 'time' ? row.time.replace('T', ' ') : row[key]));
                }); content.appendChild(list);
            } else if (action === 'bulk_delete') {
                content.appendChild(node('p', 'flt-delete-copy', t('bulk_delete_hint', { count: pendingDeleteIds.length })));
                var review = node('ul', 'flt-delete-review');
                records.filter(function (item) { return pendingDeleteIds.includes(item.id); }).forEach(function (item) { review.appendChild(node('li', '', item.code + ' · ' + item.name)); });
                content.appendChild(review);
            } else if (action === 'delete') content.appendChild(node('p', 'flt-delete-copy', t('delete_hint', { name: row.name })));
            else {
                var grid = node('div', 'flt-edit-grid');
                addEditField(grid, 'name', row.name);
                ['company', 'department', 'owner'].forEach(function (key) { var choices = {}; messages[key === 'company' ? 'companies' : key === 'department' ? 'departments' : 'owners'].forEach(function (value) { choices[value] = value; }); addEditField(grid, key, row[key], choices); });
                addEditField(grid, 'status', row.status, messages.statuses); addEditField(grid, 'note', row.note); content.appendChild(grid);
            }
            if (!dialog.open) dialog.showModal(); dialog.scrollTop = 0;
            var focus = content.querySelector('input'); if (focus) focus.focus();
        }
        body.addEventListener('click', function (event) {
            var button = event.target.closest('[data-action]'); if (!button) return;
            var row = records.find(function (item) { return item.id === Number(button.dataset.id); }); if (!row) return;
            openRecord(button.dataset.action, row);
        });
        root.querySelector('[data-flt-create]').onclick = function () {
            openRecord('create', { name: '', company: (applied.companies || [])[0] || messages.companies[0], department: (applied.departments || [])[0] || messages.departments[0], owner: (applied.owners || [])[0] || messages.owners[0], status: applied.status || 'active', note: '' });
        };
        root.querySelector('[data-flt-bulk-delete]').onclick = function () {
            pendingDeleteIds = records.filter(function (row) { return selected.has(row.id); }).map(function (row) { return row.id; });
            if (pendingDeleteIds.length) openRecord('bulk_delete');
        };
        editor.addEventListener('submit', function (event) {
            event.preventDefault(); if (!['edit', 'delete', 'create', 'bulk_delete'].includes(action)) return;
            if (action === 'bulk_delete') {
                var removing = new Set(pendingDeleteIds), count = 0;
                records = records.filter(function (item) { if (!removing.has(item.id)) return true; selected.delete(item.id); count++; return false; });
                say(t('bulk_deleted', { count: count })); close(); apply(); return;
            }
            if (action === 'create') {
                var recordName = editor.elements.name.value.trim();
                if (!recordName) { editor.elements.name.setCustomValidity(messages.columns.name); editor.elements.name.reportValidity(); return; }
                var now = new Date();
                var created = { id: nextId, code: 'DEMO-' + String(nextId).padStart(5, '0'), name: recordName,
                    time: now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0') + 'T' + String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0') };
                ['company', 'department', 'owner', 'status', 'note'].forEach(function (key) { created[key] = editor.elements[key].value; });
                nextId++; records.unshift(created); page = 1; say(t('created')); close(); apply(); return;
            }
            var row = records.find(function (item) { return item.id === currentId; }); if (!row) return;
            if (action === 'delete') { records = records.filter(function (item) { return item.id !== row.id; }); selected.delete(row.id); say(t('deleted')); }
            else {
                var name = editor.elements.name.value.trim();
                if (!name) { editor.elements.name.setCustomValidity(messages.columns.name); editor.elements.name.reportValidity(); return; }
                row.name = name;
                ['company', 'department', 'owner', 'status', 'note'].forEach(function (key) { row[key] = editor.elements[key].value; }); say(t('saved'));
            }
            close(); apply();
        });
        editor.addEventListener('input', function (event) { if (event.target.name === 'name') event.target.setCustomValidity(''); });
        function outside(event) { Object.values(pickers).forEach(function (picker) { if (!picker.details.contains(event.target)) picker.details.open = false; }); }
        function escape(event) { if (event.key === 'Escape') Object.values(pickers).forEach(function (picker) { if (picker.details.open) { picker.details.open = false; picker.details.querySelector('summary').focus(); } }); }
        document.addEventListener('click', outside); root.addEventListener('keydown', escape);
        // A PJAX history snapshot can contain an open attribute without a modal top layer.
        dialog.removeAttribute('open'); records = seed(); form.reset();
        return { root: root, destroy: function () { close(); document.removeEventListener('click', outside); root.removeEventListener('keydown', escape); } };
    }
    function init() {
        var root = document.querySelector('[data-frontend-list-template]');
        if (active && active.root !== root) { active.destroy(); active = null; }
        if (root && !active) active = create(root);
    }
    window.ErpFrontendListTemplate = { init: init };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
    if (window.jQuery) window.jQuery(document).on('pjax:end.frontendListTemplate', init).on('pjax:beforeReplace.frontendListTemplate', function () { if (active) { active.destroy(); active = null; } });
})();
