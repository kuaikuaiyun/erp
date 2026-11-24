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
        var region = root.querySelector('[data-flt-region]');
        var tableScroll = root.querySelector('.flt-table-scroll'), emptyHost = root.querySelector('[data-flt-empty-host]');
        var emptyObserver = null;
        var busy = false, timer = null, destroyed = false, locked = new Map();
        var records = [], filtered = [], selected = new Set(), pickers = {};
        var page = 1, size = 50, applied = {}, action = null, currentId = null, nextId = 121, pendingDeleteIds = [], importFile = null;
        var baseline = '';
        var recordLoadTimer = null, recordLoadVersion = 0, recordState = 'closed';
        root.querySelectorAll('.flt-discard-dialog').forEach(function (stale) { stale.remove(); });
        var discardDialog = node('dialog', 'flt-dialog flt-discard-dialog');
        discardDialog.setAttribute('aria-labelledby', 'flt-discard-title');
        var discardHeader = node('header'), discardTitle = node('h3', '', t('discard_title'));
        discardTitle.id = 'flt-discard-title'; discardHeader.appendChild(discardTitle);
        var discardBody = node('div', 'flt-dialog-content', t('discard_hint'));
        var discardFooter = node('footer'), continueButton = node('button', 'flt-btn', t('continue_editing'));
        var discardButton = node('button', 'flt-btn flt-danger', t('discard_confirm'));
        continueButton.type = discardButton.type = 'button'; discardFooter.append(continueButton, discardButton);
        discardDialog.append(discardHeader, discardBody, discardFooter); root.appendChild(discardDialog);
        continueButton.onclick = function () { discardDialog.close(); };
        discardButton.onclick = function () { close(); };
        discardDialog.addEventListener('cancel', function (event) { event.preventDefault(); event.stopPropagation(); discardDialog.close(); });

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
            if (!text) return;
            if (window.ErpAdminNotifier) window.ErpAdminNotifier.notify({ type: error ? 'error' : 'success', message: text });
            else if (window.toastr) window.toastr[error ? 'error' : 'success'](text);
        }
        function lockControls() {
            root.querySelectorAll('input, select, textarea, button').forEach(function (el) {
                if (!locked.has(el)) locked.set(el, el.disabled);
                el.disabled = true;
            });
            root.querySelectorAll('.flt-picker').forEach(function (picker) { picker.open = false; });
        }
        function unlockControls() {
            locked.forEach(function (disabled, el) { el.disabled = disabled; }); locked.clear();
        }
        function runTask(work, trigger) {
            if (busy || destroyed) return;
            busy = true; region.setAttribute('aria-busy', 'true'); root.querySelector('[data-flt-loading]').hidden = false;
            root.querySelector('.flt-pagination').classList.add('is-loading');
            if (trigger) trigger.classList.add('is-loading');
            lockControls();
            // Give the browser time to paint the same loading state used by real asynchronous lists.
            timer = window.setTimeout(function () {
                timer = null;
                if (destroyed) return;
                try { work(); root.querySelector('.flt-table-scroll').scrollTop = 0; }
                catch (_) { say(t('loading_failed'), true); }
                finally {
                    busy = false; unlockControls(); render(); region.setAttribute('aria-busy', 'false');
                    root.querySelector('[data-flt-loading]').hidden = true;
                    root.querySelector('.flt-pagination').classList.remove('is-loading');
                    if (trigger) trigger.classList.remove('is-loading');
                }
            }, 220);
        }
        function extraFields(index) {
            return {
                business_type: messages.business_types[index % messages.business_types.length],
                source_channel: messages.source_channels[index % messages.source_channels.length],
                region: messages.regions[index % messages.regions.length],
                priority: messages.priorities[index % messages.priorities.length]
            };
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
                    note: t('demo_note'),
                    ...extraFields(index)
                };
            });
        }
        function makePicker(host, config) {
            config = config || {};
            var key = config.key || host.dataset.fltPicker, multi = host.dataset.multiple === '1';
            var source = config.choices || messages[key];
            var choices = Array.isArray(source) ? source.map(function (value) { return { value: value, label: value }; }) : Object.entries(source).map(function (entry) { return { value: entry[0], label: entry[1] }; });
            var values = new Set(config.backing && config.backing.value ? [config.backing.value] : []);
            var details = node('details', 'flt-picker'), summary = node('summary', '', t('all'));
            summary.id = 'flt-picker-summary-' + key;
            summary.setAttribute('aria-labelledby', host.getAttribute('aria-labelledby') + ' ' + summary.id);
            var panel = node('div', 'flt-picker-panel'), options = node('div', 'flt-picker-options');
            var search = null;
            if (host.dataset.searchable === '1') {
                search = node('input'); search.type = 'search'; search.placeholder = t('search_options');
                search.setAttribute('aria-label', t('search_options')); panel.appendChild(search);
                search.addEventListener('input', drawOptions);
            }
            function refreshLabel() {
                var labels = Array.from(values).map(function (value) { return choices.find(function (choice) { return choice.value === value; })?.label || value; });
                summary.textContent = labels.length === 0 ? (config.placeholder || t('all')) : (multi ? t('selected_options', { names: labels.join('、'), count: labels.length }) : labels[0]);
                summary.title = labels.join('、') || config.placeholder || t('all');
                if (config.backing) config.backing.value = Array.from(values)[0] || '';
            }
            function drawOptions() {
                options.replaceChildren();
                var available = config.showAll ? [{ value: '', label: t('all') }].concat(choices) : choices;
                var visible = available.filter(function (choice) { return !search || choice.label.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase()); });
                if (!visible.length) options.appendChild(node('p', '', t('no_options')));
                visible.forEach(function (choice) {
                    var label = node('label'), input = node('input');
                    input.type = multi ? 'checkbox' : 'radio'; input.name = 'flt-picker-' + key;
                    input.value = choice.value; input.checked = choice.value === '' ? !values.size : values.has(choice.value);
                    input.addEventListener('change', function () {
                        if (!multi) values.clear();
                        if (input.checked && choice.value !== '') values.add(choice.value); else values.delete(choice.value);
                        refreshLabel();
                        if (config.backing) { if (editor.contains(config.backing)) fieldError(config.backing, ''); summary.setAttribute('aria-invalid', 'false'); }
                    });
                    label.append(input, node('span', '', choice.label)); options.appendChild(label);
                });
            }
            function filteredChoices() {
                var keyword = search ? search.value.trim().toLocaleLowerCase() : '';
                return choices.filter(function (choice) {
                    return !keyword || choice.label.toLocaleLowerCase().includes(keyword);
                });
            }
            var clear = node('button', 'flt-picker-clear', t('clear_choice')); clear.type = 'button';
            clear.onclick = function () { values.clear(); if (search) search.value = ''; refreshLabel(); drawOptions(); };
            panel.append(options);
            if (config.allowClear !== false && !config.showAll) {
                var actions = node('div', 'flt-picker-actions');
                if (multi && host.dataset.selectAll === '1') {
                    var selectAll = node('button', 'flt-picker-select-all', t('select_all_options')); selectAll.type = 'button';
                    selectAll.onclick = function () {
                        filteredChoices().forEach(function (choice) { values.add(choice.value); });
                        refreshLabel(); drawOptions();
                    };
                    actions.append(selectAll);
                }
                actions.append(clear); panel.append(actions);
            }
            details.append(summary, panel); host.replaceChildren(details);
            summary.addEventListener('click', function () { details.classList.remove('is-positioned'); });
            function positionPanel() {
                if (!details.open || !dialog.contains(details)) return;
                details.classList.remove('is-positioned');
                var anchor = summary.getBoundingClientRect(), bounds = dialog.getBoundingClientRect();
                var bottom = Math.min(innerHeight - 10, bounds.bottom - 8), top = Math.max(10, bounds.top + 8);
                var below = Math.max(0, bottom - anchor.bottom - 6), above = Math.max(0, anchor.top - top - 6);
                panel.style.position = 'fixed'; panel.style.width = Math.min(anchor.width, innerWidth - 24) + 'px';
                panel.style.left = Math.max(12, Math.min(anchor.left, innerWidth - anchor.width - 12)) + 'px'; panel.style.right = 'auto';
                panel.style.top = '0px'; panel.style.maxHeight = 'none'; options.style.maxHeight = '210px';
                var naturalHeight = panel.getBoundingClientRect().height;
                var opensBelow = below >= naturalHeight || (above < naturalHeight && below >= above);
                var height = Math.max(1, Math.min(naturalHeight, opensBelow ? below : above));
                panel.style.maxHeight = height + 'px'; panel.style.overflowY = 'auto';
                options.style.maxHeight = Math.max(0, height - 22) + 'px';
                panel.style.top = (opensBelow ? anchor.bottom + 6 : Math.max(top, anchor.top - height - 6)) + 'px';
                details.classList.add('is-positioned');
            }
            details.addEventListener('toggle', function () {
                if (details.open) {
                    root.querySelectorAll('.flt-picker').forEach(function (other) { if (other !== details) other.open = false; });
                    drawOptions(); positionPanel(); if (search) search.focus();
                } else details.classList.remove('is-positioned');
            });
            drawOptions(); refreshLabel();
            return { details: details, summary: summary, position: positionPanel, values: values, clear: function () { values.clear(); if (search) search.value = ''; details.open = false; refreshLabel(); drawOptions(); } };
        }
        root.querySelectorAll('[data-flt-picker]').forEach(function (host) {
            pickers[host.dataset.fltPicker] = makePicker(host, host.dataset.fltPicker === 'statuses' ? { backing: form.elements.status, showAll: true } : {});
        });

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
            var stats = { all: filtered.length, active: 0, pending: 0, disabled: 0, selected: selected.size };
            filtered.forEach(function (row) { if (Object.prototype.hasOwnProperty.call(messages.statuses, row.status)) stats[row.status]++; });
            root.querySelectorAll('[data-flt-stat]').forEach(function (value) { value.textContent = stats[value.dataset.fltStat]; });
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
        function syncEmptyLayout() {
            if (emptyHost.hidden) return;
            emptyHost.style.width = tableScroll.querySelector('table').getBoundingClientRect().width + 'px';
            root.querySelector('[data-flt-empty]').style.width = tableScroll.clientWidth + 'px';
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
                ['business_type', 'source_channel', 'region', 'priority'].forEach(function (key) { tr.appendChild(node('td', '', row[key])); });
                var actions = node('td');
                ['view', 'edit', 'delete'].forEach(function (key) {
                    var button = node('button', 'flt-row-action', t(key)); button.type = 'button'; button.dataset.action = key; button.dataset.id = row.id; actions.appendChild(button);
                });
                tr.appendChild(actions); body.appendChild(tr);
            });
            root.querySelector('[data-flt-empty]').hidden = filtered.length !== 0;
            emptyHost.hidden = filtered.length !== 0;
            tableScroll.classList.toggle('is-empty', filtered.length === 0);
            if (!filtered.length) { tableScroll.scrollLeft = 0; tableScroll.scrollTop = 0; }
            syncEmptyLayout();
            root.querySelector('[data-flt-total]').textContent = t('records', { count: filtered.length });
            var pages = Math.ceil(filtered.length / size) || 1;
            root.querySelector('[data-flt-page-label]').textContent = t('page_label', { page: page, pages: pages });
            root.querySelector('[data-flt-previous]').disabled = page <= 1;
            root.querySelector('[data-flt-next]').disabled = page >= pages;
            var jump = root.querySelector('[data-flt-jump] input'); jump.max = pages; jump.value = '';
            updateSelection();
            if (busy) lockControls();
        }
        function reset(event) {
            if (busy) { if (event) event.preventDefault(); return; }
            applied = {}; page = 1; selected.clear();
            Object.values(pickers).forEach(function (picker) { picker.clear(); });
            say(''); apply();
        }
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (busy) return;
            var start = form.elements.start.value, end = form.elements.end.value;
            if (start && end && start > end) { say(t('invalid_range'), true); return; }
            var next = { keyword: form.elements.keyword.value.trim(), status: form.elements.status.value, start: start, end: end };
            Object.keys(pickers).forEach(function (key) { next[key] = Array.from(pickers[key].values); });
            runTask(function () { applied = next; page = 1; apply(); }, form.querySelector('[type=submit]'));
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
        root.querySelector('[data-flt-size]').onchange = function (event) { var nextSize = Number(event.target.value); runTask(function () { size = nextSize; page = 1; }); };
        root.querySelector('[data-flt-previous]').onclick = function (event) { if (page > 1) runTask(function () { page--; }, event.currentTarget); };
        root.querySelector('[data-flt-next]').onclick = function (event) { if (page < Math.ceil(filtered.length / size)) runTask(function () { page++; }, event.currentTarget); };
        root.querySelector('[data-flt-jump]').onsubmit = function (event) {
            event.preventDefault();
            if (busy) return;
            var value = Number(event.currentTarget.querySelector('input').value), pages = Math.ceil(filtered.length / size) || 1;
            if (!Number.isInteger(value) || value < 1 || value > pages) { say(t('invalid_page', { pages: pages }), true); return; }
            runTask(function () { page = value; }, event.currentTarget.querySelector('[type=submit]'));
        };
        function close() {
            cancelRecordLoad(); recordState = 'closed';
            if (discardDialog.open) discardDialog.close();
            closePickers();
            if (dialog.open) dialog.close();
            var fileInput = editor.querySelector('input[type=file]'); if (fileInput) fileInput.value = '';
            importFile = null; action = null; currentId = null; pendingDeleteIds = []; baseline = '';
            root.querySelector('[data-flt-dialog-content]').replaceChildren();
        }
        function editorSnapshot() {
            return JSON.stringify(['name', 'company', 'department', 'owner', 'status', 'note'].map(function (key) { return editor.elements[key]?.value || ''; }));
        }
        function requestClose() {
            if (discardDialog.open) return;
            if (recordState === 'ready' && dialog.open && (action === 'create' || action === 'edit') && editorSnapshot() !== baseline) {
                closePickers(); discardDialog.showModal(); continueButton.focus(); return;
            }
            close();
        }
        root.querySelectorAll('[data-flt-close]').forEach(function (button) { button.onclick = requestClose; });
        // File inputs emit a bubbling cancel event when the OS chooser is dismissed.
        // Only a cancel event from the dialog itself should close the modal.
        dialog.addEventListener('cancel', function (event) {
            if (event.target !== dialog) return;
            event.preventDefault(); requestClose();
        });
        function addEditField(parent, key, value, choices) {
            var field = node('div', 'flt-edit-field' + (key === 'name' || key === 'note' ? ' flt-wide' : ''));
            var label = node('label', '', key === 'note' ? t('note') : messages.columns[key]);
            var input = node(choices ? 'select' : key === 'note' ? 'textarea' : 'input'); input.name = key;
            input.id = 'flt-editor-' + key;
            label.htmlFor = input.id; label.id = input.id + '-label';
            if (key !== 'note') { label.appendChild(node('span', 'flt-required', ' *')); input.required = true; }
            if (choices) Object.entries(choices).forEach(function (entry) { var option = node('option', '', entry[1]); option.value = entry[0]; input.appendChild(option); });
            else { input.maxLength = key === 'note' ? 500 : 100; if (key === 'name') input.required = true; }
            var error = node('span', 'flt-field-error'); error.id = input.id + '-error'; error.hidden = true;
            input.setAttribute('aria-describedby', error.id);
            input.value = value; field.append(label, input);
            if (choices) {
                input.hidden = true;
                var host = node('div'); host.dataset.fltEditorPicker = key; host.setAttribute('aria-labelledby', label.id);
                field.appendChild(host);
                input.fltPicker = makePicker(host, { key: 'editor-' + key, choices: choices, backing: input, allowClear: false, placeholder: t('please_select') });
                input.fltPicker.summary.setAttribute('aria-describedby', error.id);
            }
            field.appendChild(error); parent.appendChild(field);
        }
        function fieldError(input, text) {
            input.setAttribute('aria-invalid', text ? 'true' : 'false');
            if (input.fltPicker) input.fltPicker.summary.setAttribute('aria-invalid', text ? 'true' : 'false');
            var error = editor.querySelector('#' + input.id + '-error');
            if (error) { error.textContent = text; error.hidden = !text; }
        }
        function validateEditor() {
            var first = null;
            editor.querySelectorAll('[required], [maxlength]').forEach(function (input) {
                var value = input.value.trim(), title = messages.columns[input.name] || t(input.name), error = '';
                if (input.required && !value) error = t('required_field', { field: title });
                else if (input.maxLength > 0 && value.length > input.maxLength) error = t('too_long', { field: title, max: input.maxLength });
                else if (input.tagName === 'SELECT' && !Array.from(input.options).some(function (option) { return option.value === value; })) error = t('invalid_choice', { field: title });
                fieldError(input, error); if (error && !first) first = input;
            });
            if (first) (first.fltPicker ? first.fltPicker.summary : first).focus(); return !first;
        }
        function cancelRecordLoad() {
            recordLoadVersion++; window.clearTimeout(recordLoadTimer); recordLoadTimer = null;
            root.querySelector('[data-flt-dialog-content]').setAttribute('aria-busy', 'false');
        }
        function openRecord(mode, row) {
            if (busy || destroyed || discardDialog.open) return;
            cancelRecordLoad();
            if (mode !== 'view' && mode !== 'edit') { recordState = 'ready'; renderRecord(mode, row); return; }
            action = mode; currentId = row.id; baseline = ''; recordState = 'loading';
            closePickers();
            root.querySelector('#flt-dialog-title').textContent = t(mode + '_title');
            var submit = root.querySelector('[data-flt-dialog-submit]');
            submit.hidden = mode === 'view'; submit.disabled = true; submit.classList.remove('flt-danger'); submit.textContent = t('save');
            var content = root.querySelector('[data-flt-dialog-content]');
            content.setAttribute('aria-busy', 'true');
            var loading = node('div', 'flt-dialog-loading'); loading.setAttribute('role', 'status');
            var spinner = node('span', 'flt-spinner'); spinner.setAttribute('aria-hidden', 'true');
            loading.append(spinner, node('span', '', t('loading'))); content.replaceChildren(loading);
            if (!dialog.open) dialog.showModal(); dialog.scrollTop = 0;
            var version = recordLoadVersion, id = row.id;
            recordLoadTimer = window.setTimeout(function () {
                recordLoadTimer = null;
                if (destroyed || version !== recordLoadVersion || !dialog.open) return;
                try {
                    var latest = records.find(function (item) { return item.id === id; });
                    if (!latest) throw Error('record-unavailable');
                    renderRecord(mode, latest); recordState = 'ready';
                } catch (_) {
                    recordState = 'error'; submit.disabled = true; content.replaceChildren();
                    var error = node('div', 'flt-dialog-load-error'); error.setAttribute('role', 'alert');
                    var retry = node('button', 'flt-btn flt-primary', t('reload_record')); retry.type = 'button';
                    retry.onclick = function () { openRecord(mode, { id: id }); };
                    error.append(node('p', '', t('record_load_failed')), retry); content.appendChild(error);
                } finally {
                    if (version === recordLoadVersion) content.setAttribute('aria-busy', 'false');
                }
            }, 300);
        }
        function renderRecord(mode, row) {
            if (busy) return;
            importFile = null;
            action = mode; currentId = row ? row.id : null;
            var title = root.querySelector('#flt-dialog-title');
            title.textContent = t(action + '_title');
            var content = root.querySelector('[data-flt-dialog-content]'); content.replaceChildren();
            var submit = root.querySelector('[data-flt-dialog-submit]'); submit.hidden = action === 'view'; submit.classList.toggle('flt-danger', action === 'delete' || action === 'bulk_delete');
            submit.disabled = false;
            submit.textContent = t(action === 'delete' || action === 'bulk_delete' ? 'confirm_delete' : action === 'create' ? 'create_save' : action === 'import' ? 'upload' : action === 'export' ? 'confirm_export' : 'save');
            if (action === 'import') {
                var label = node('label', '', t('file')), input = node('input');
                input.type = 'file'; input.name = 'demo_file'; input.id = 'flt-demo-file'; input.accept = '.xlsx,.xls,.csv'; input.required = true;
                input.className = 'flt-file-input'; label.htmlFor = input.id;
                label.appendChild(node('span', 'flt-required', ' *'));
                var zone = node('button', 'flt-dropzone'); zone.type = 'button';
                var icon = node('i', 'fa fa-cloud-upload'); icon.setAttribute('aria-hidden', 'true');
                zone.append(icon, node('strong', '', t('drop_file')), node('span', '', t('file_formats')));
                zone.setAttribute('aria-describedby', 'flt-demo-file-error');
                var info = node('div', 'flt-file-info', t('file_empty')), error = node('span', 'flt-field-error'); error.id = 'flt-demo-file-error'; error.hidden = true;
                info.setAttribute('aria-live', 'polite');
                input.setAttribute('aria-describedby', error.id); content.append(label, input, zone, error, info);
                var template = node('button', 'flt-template-download'); template.type = 'button';
                var templateIcon = node('i', 'fa fa-file-excel-o'); templateIcon.setAttribute('aria-hidden', 'true');
                template.append(templateIcon, node('span', '', t('download_template')));
                template.onclick = function () {
                    var fields = ['name', 'company', 'department', 'owner', 'status', 'note'];
                    var headers = fields.map(function (key) { return key === 'note' ? t('note') : messages.columns[key]; });
                    var sample = [t('record_name', { number: '001' }), messages.companies[0], messages.departments[0], messages.owners[0], messages.statuses.active, t('demo_note')];
                    var csv = '\uFEFF' + [headers, sample].map(function (row) {
                        return row.map(function (value) { return '"' + String(value).replace(/"/g, '""') + '"'; }).join(',');
                    }).join('\r\n') + '\r\n';
                    var url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
                    var link = node('a'); link.href = url; link.download = 'frontend-list-template.csv';
                    document.body.appendChild(link); link.click(); link.remove();
                    window.setTimeout(function () { URL.revokeObjectURL(url); }, 10000);
                };
                content.appendChild(template);
                var dragDepth = 0;
                function showFile() {
                    info.replaceChildren();
                    if (!importFile) { info.textContent = t('file_empty'); return; }
                    info.append(node('strong', '', importFile.name), node('span', '', t('file_size', { size: (importFile.size / 1024).toFixed(1) + ' KB' })));
                }
                function acceptFiles(files) {
                    if (!files.length) { showFile(); return; }
                    if (files.length !== 1) { fieldError(input, t('single_file')); return; }
                    if (!/\.(csv|xlsx|xls)$/i.test(files[0].name)) { fieldError(input, t('file_format_invalid')); return; }
                    importFile = files[0]; fieldError(input, ''); zone.removeAttribute('aria-invalid'); showFile();
                }
                zone.onclick = function () { input.click(); };
                zone.addEventListener('dragenter', function (event) { event.preventDefault(); dragDepth++; zone.classList.add('is-dragover'); });
                zone.addEventListener('dragover', function (event) { event.preventDefault(); if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy'; });
                zone.addEventListener('dragleave', function (event) { event.preventDefault(); dragDepth = Math.max(0, dragDepth - 1); if (!dragDepth) zone.classList.remove('is-dragover'); });
                zone.addEventListener('drop', function (event) {
                    event.preventDefault(); event.stopPropagation(); dragDepth = 0; zone.classList.remove('is-dragover');
                    if (event.dataTransfer) acceptFiles(Array.from(event.dataTransfer.files));
                });
                input.addEventListener('cancel', function (event) { event.stopPropagation(); showFile(); });
                input.onchange = function () {
                    acceptFiles(Array.from(input.files));
                };
            } else if (action === 'export') {
                ['filtered', 'selected'].forEach(function (scope) {
                    var label = node('label', 'flt-export-choice'), radio = node('input'), count = scope === 'filtered' ? filtered.length : selected.size;
                    radio.type = 'radio'; radio.name = 'export_scope'; radio.value = scope; radio.checked = scope === 'filtered'; radio.disabled = count === 0;
                    label.append(radio, node('span', '', t('export_' + scope, { count: count }))); content.appendChild(label);
                });
                submit.disabled = filtered.length === 0 && selected.size === 0;
                if (!filtered.length && selected.size) content.querySelector('[value=selected]').checked = true;
            } else if (action === 'view') {
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
            baseline = editorSnapshot();
            var focus = content.querySelector('.flt-dropzone') || content.querySelector('input'); if (focus) focus.focus();
        }
        body.addEventListener('click', function (event) {
            var button = event.target.closest('[data-action]'); if (!button) return;
            var row = records.find(function (item) { return item.id === Number(button.dataset.id); }); if (!row) return;
            openRecord(button.dataset.action, row);
        });
        root.querySelector('[data-flt-create]').onclick = function () {
            openRecord('create', { name: '', company: '', department: '', owner: '', status: '', note: '' });
        };
        root.querySelector('[data-flt-bulk-delete]').onclick = function () {
            pendingDeleteIds = records.filter(function (row) { return selected.has(row.id); }).map(function (row) { return row.id; });
            if (pendingDeleteIds.length) openRecord('bulk_delete');
        };
        root.querySelector('[data-flt-import]').onclick = function () { openRecord('import'); };
        root.querySelector('[data-flt-export]').onclick = function () { openRecord('export'); };
        editor.addEventListener('submit', function (event) {
            event.preventDefault(); if (busy || !['edit', 'delete', 'create', 'bulk_delete', 'import', 'export'].includes(action)) return;
            if (action === 'edit' && recordState !== 'ready') return;
            if (action === 'import') {
                var file = editor.elements.demo_file;
                if (!importFile) { fieldError(file, t('required_field', { field: t('file') })); editor.querySelector('.flt-dropzone').focus(); return; }
                close(); say(t('upload_done')); return;
            }
            if (action === 'export') { var scope = editor.querySelector('[name=export_scope]:checked:not(:disabled)'); if (!scope) return; close(); say(t('export_done')); return; }
            if ((action === 'create' || action === 'edit') && !validateEditor()) return;
            if (action === 'bulk_delete') {
                var removing = new Set(pendingDeleteIds), count = 0;
                records = records.filter(function (item) { if (!removing.has(item.id)) return true; selected.delete(item.id); count++; return false; });
                say(t('bulk_deleted', { count: count })); close(); apply(); return;
            }
            if (action === 'create') {
                var recordName = editor.elements.name.value.trim();
                var now = new Date();
                var created = { id: nextId, code: 'DEMO-' + String(nextId).padStart(5, '0'), name: recordName,
                    time: now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0') + 'T' + String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0') };
                Object.assign(created, extraFields(nextId - 1));
                ['company', 'department', 'owner', 'status', 'note'].forEach(function (key) { created[key] = editor.elements[key].value; });
                nextId++; records.unshift(created); page = 1; say(t('created')); close(); apply(); return;
            }
            var row = records.find(function (item) { return item.id === currentId; }); if (!row) return;
            if (action === 'delete') { records = records.filter(function (item) { return item.id !== row.id; }); selected.delete(row.id); say(t('deleted')); }
            else {
                var name = editor.elements.name.value.trim();
                row.name = name;
                ['company', 'department', 'owner', 'status', 'note'].forEach(function (key) { row[key] = editor.elements[key].value; }); say(t('saved'));
            }
            close(); apply();
        });
        editor.addEventListener('input', function (event) { if (event.target.id) fieldError(event.target, ''); });
        function closePickers() { root.querySelectorAll('.flt-picker').forEach(function (picker) { picker.open = false; }); }
        function outside(event) { root.querySelectorAll('.flt-picker').forEach(function (picker) { if (!picker.contains(event.target)) picker.open = false; }); }
        function escape(event) {
            if (event.key !== 'Escape') return;
            if (discardDialog.open) {
                event.preventDefault(); event.stopPropagation(); discardDialog.close(); return;
            }
            var opened = root.querySelector('.flt-picker[open]');
            if (opened) { event.preventDefault(); event.stopPropagation(); opened.open = false; opened.querySelector('summary').focus(); }
            else if (dialog.open) { event.preventDefault(); event.stopPropagation(); requestClose(); }
        }
        function scrollDialog(event) { if (event.target === dialog) closePickers(); }
        document.addEventListener('click', outside); root.addEventListener('keydown', escape);
        function resizeLayout() { closePickers(); syncEmptyLayout(); }
        window.addEventListener('resize', resizeLayout); dialog.addEventListener('scroll', scrollDialog);
        if (window.ResizeObserver) { emptyObserver = new ResizeObserver(syncEmptyLayout); emptyObserver.observe(tableScroll); }
        // A PJAX history snapshot can contain an open attribute without a modal top layer.
        dialog.removeAttribute('open'); records = seed(); form.reset();
        return { root: root, destroy: function () { destroyed = true; window.clearTimeout(timer); if (emptyObserver) emptyObserver.disconnect(); unlockControls(); close(); document.removeEventListener('click', outside); root.removeEventListener('keydown', escape); window.removeEventListener('resize', resizeLayout); dialog.removeEventListener('scroll', scrollDialog); } };
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
