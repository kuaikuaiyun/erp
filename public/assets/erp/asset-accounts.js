(function ($) {
    'use strict';
    var c = window.AssetAccountsConfig, t = c.text;
    var recordId = null, mode = '', busy = false, sequence = 0, departmentOptions = [], permissionControls = [], activePicker = null, membershipSequence = 0;
    var modal = $('#hr-modal'), form = document.getElementById('hr-form'), fields = document.getElementById('hr-fields');
    var save = document.getElementById('hr-save'), error = document.getElementById('hr-error'), body = document.getElementById('hr-modal-body');

    function node(tag, text, cls) {
        var element = document.createElement(tag);
        if (text != null) element.textContent = text;
        if (cls) element.className = cls;
        return element;
    }
    function showError(message) {
        error.textContent = message || '';
        error.hidden = !message;
        if (message) body.scrollTop = 0;
    }
    async function api(path, method, data) {
        var response = await fetch(c.base + path, {
            method: method || 'GET', credentials: 'same-origin',
            headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
            body: data ? JSON.stringify(data) : undefined
        });
        var result = await response.json().catch(function () { return {}; });
        if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join('\n') : (response.status >= 500 ? t.failed : (result.message || t.failed)));
        return result;
    }
    function closePicker() {
        if (activePicker && $.fn.select2 && $(activePicker).data('select2')) $(activePicker).select2('close');
        activePicker = null;
    }
    function fitPicker(select) {
        if (!select || !modal[0].contains(select) || !$(select).data('select2')) return;
        var dropdown = $('.hr-select-dropdown:visible').last()[0];
        if (!dropdown) return;
        var shell = dropdown.closest('.select2-container'), list = dropdown.querySelector('.select2-results__options');
        if (!shell || !list) return;
        var anchor = $(select).next('.select2-container')[0].getBoundingClientRect(), bounds = body.getBoundingClientRect(), parent = modal[0].getBoundingClientRect();
        if (anchor.bottom < bounds.top || anchor.top > bounds.bottom) { closePicker(); return; }
        var search = dropdown.querySelector('.select2-search--dropdown'), searchHeight = search ? search.offsetHeight : 0;
        var below = bounds.bottom - anchor.bottom - searchHeight - 4, above = anchor.top - bounds.top - searchHeight - 4;
        var upward = below < 120 && above > below, room = upward ? above : below;
        list.style.maxHeight = Math.max(32, Math.min(200, Math.floor(room))) + 'px';
        var width = Math.min(anchor.width, bounds.width - 8);
        shell.style.width = width + 'px';
        dropdown.style.width = '100%';
        var height = dropdown.getBoundingClientRect().height;
        var top = upward ? anchor.top - height : anchor.bottom;
        top = Math.max(bounds.top + 2, Math.min(top, bounds.bottom - height - 2));
        shell.style.top = (top - parent.top + modal[0].scrollTop) + 'px';
        shell.style.left = (Math.max(bounds.left + 4, Math.min(anchor.left, bounds.right - width - 4)) - parent.left + modal[0].scrollLeft) + 'px';
        $(dropdown).toggleClass('select2-dropdown--above', upward).toggleClass('select2-dropdown--below', !upward);
    }
    function picker(select, parent, multiple) {
        if (!$.fn.select2) return;
        $(select).select2({
            width: '100%', dropdownParent: parent || $(document.body), minimumResultsForSearch: 0,
            dropdownCssClass: 'hr-select-dropdown', placeholder: multiple ? t.all : t.choose_department,
            language: {noResults: function () { return t.no_matches; }, searching: function () { return t.loading; }, errorLoading: function () { return t.failed; }}
        }).on('select2:open.hrUi', function () {
            activePicker = select;
            $('.hr-select-dropdown .select2-search__field').attr('placeholder', t.department_search).attr('aria-label', t.department_search);
            window.requestAnimationFrame(function () { fitPicker(select); });
        }).on('select2:close.hrUi', function () { if (activePicker === select) activePicker = null; });
    }
    picker(document.getElementById('hr-departments'), null, true);
    body.addEventListener('scroll', function () { closePicker(); });
    $(window).on('resize.hrAccountPicker', function () { window.requestAnimationFrame(function () { if (activePicker) fitPicker(activePicker); }); });

    function section(title, hint) {
        var element = node('section', null, 'hr-form-section');
        element.appendChild(node('h4', title, 'hr-section-title'));
        if (hint) element.appendChild(node('p', hint, 'hr-hint'));
        fields.appendChild(element);
        return element;
    }
    function field(grid, key, value, type, disabled) {
        var group = node('div', null, 'form-group'), label = node('label', t[key]), input = node(type === 'select' ? 'select' : 'input');
        input.name = key; input.className = 'form-control'; input.id = 'hr-input-' + key; label.htmlFor = input.id;
        if (type === 'select') {
            var choices = key === 'status' ? {'1': t.enabled, '0': t.disabled} : {employed: t.employed, resigned: t.resigned};
            Object.entries(choices).forEach(function (entry) { input.add(new Option(entry[1], entry[0])); });
        } else {
            input.type = type || 'text';
            if (input.type === 'password') { input.autocomplete = 'new-password'; input.minLength = 8; input.required = mode === 'create'; }
            if (key === 'username') input.maxLength = 190;
            if (key === 'name') input.maxLength = 255;
        }
        input.value = value == null ? '' : value; input.disabled = !!disabled;
        if (['name', 'username'].includes(key)) input.required = true;
        group.append(label, input); grid.appendChild(group);
        return input;
    }
    function refreshDepartments(container) {
        var selects = Array.from(container.querySelectorAll('.hr-dept'));
        selects.forEach(function (select) {
            Array.from(select.options).forEach(function (option) {
                option.disabled = !!option.value && selects.some(function (other) { return other !== select && other.value === option.value; });
                $(option).removeData('data');
            });
            $(select).trigger('change.select2');
        });
        var used = new Set(selects.map(function (select) { return select.value; }).filter(Boolean));
        var add = container.parentElement.querySelector('[data-add-membership]');
        if (add) add.disabled = used.size >= departmentOptions.length;
        var count = container.parentElement.querySelector('[data-membership-count]');
        if (count) count.textContent = t.membership_count.replace(':count', String(used.size));
    }
    function membership(container, membership) {
        var index = ++membershipSequence, row = node('div', null, 'hr-membership hr-membership-editable');
        var header = node('div', null, 'hr-membership-head'), remove = node('button', t.remove, 'btn btn-default btn-sm');
        header.append(node('strong', t.membership_item), remove); remove.type = 'button';
        var controls = node('div', null, 'hr-membership-controls'), departmentGroup = node('div', null, 'form-group'), positionGroup = node('div', null, 'form-group');
        var dept = node('select'), position = node('select'), departmentLabel = node('label', t.department), positionLabel = node('label', t.position), notice = node('p', null, 'hr-position-notice');
        dept.className = 'form-control hr-dept'; dept.required = true; dept.id = 'hr-department-' + index; departmentLabel.htmlFor = dept.id;
        position.className = 'form-control hr-position'; position.required = true; position.id = 'hr-position-' + index; positionLabel.htmlFor = position.id;
        notice.id = 'hr-position-notice-' + index; position.setAttribute('aria-describedby', notice.id); notice.setAttribute('aria-live', 'polite');
        dept.add(new Option('', ''));
        departmentOptions.forEach(function (department) { dept.add(new Option(department.label, department.id)); });
        dept.value = membership.department_id || ''; dept.dataset.lastValue = dept.value;
        function positions() {
            var previous = position.value || membership.position || 'member';
            var department = departmentOptions.find(function (option) { return String(option.id) === dept.value; });
            position.replaceChildren(new Option(t.choose_position, ''));
            ['manager', 'deputy', 'member'].forEach(function (key) {
                var available = !department || !department.position_availability || !department.position_availability[key] || department.position_availability[key].enabled;
                var option = new Option(t[key] + (available ? '' : t.position_unavailable), key); option.disabled = !available; position.add(option);
            });
            position.disabled = !department;
            position.value = department && Array.from(position.options).some(function (option) { return option.value === previous && !option.disabled; }) ? previous : '';
            notice.textContent = department ? (department.position_notice || t.position_hint) : t.choose_department_first;
            notice.classList.toggle('is-warning', !!department && !position.value);
            notice.hidden = false;
        }
        $(dept).on('select2:selecting.hrMembership', function (event) {
            var data = event.params && event.params.args && event.params.args.data;
            if (data && data.id && Array.from(container.querySelectorAll('.hr-dept')).some(function (other) { return other !== dept && other.value === String(data.id); })) event.preventDefault();
        }).on('change.hrMembership', function () {
            if (dept.value && Array.from(container.querySelectorAll('.hr-dept')).some(function (other) { return other !== dept && other.value === dept.value; })) {
                dept.value = dept.dataset.lastValue || ''; $(dept).trigger('change.select2'); showError(t.duplicate_department); return;
            }
            dept.dataset.lastValue = dept.value; positions(); refreshDepartments(container);
        });
        remove.onclick = function () { closePicker(); if ($.fn.select2 && $(dept).data('select2')) $(dept).select2('destroy'); row.remove(); refreshDepartments(container); };
        departmentGroup.append(departmentLabel, dept); positionGroup.append(positionLabel, position); controls.append(departmentGroup, positionGroup);
        row.append(header, controls, notice); container.appendChild(row); positions(); picker(dept, modal); refreshDepartments(container);
    }
    function basic(account, readonly) {
        var info = section(t.basic_information), grid = node('div', null, 'hr-basic-grid'); info.appendChild(grid);
        field(grid, 'username', account.username, 'text', readonly || account.administrator); field(grid, 'name', account.name, 'text', readonly);
        var employment = section(t.employment_information), employmentGrid = node('div', null, 'hr-basic-grid'); employment.appendChild(employmentGrid);
        field(employmentGrid, 'hired_at', account.hired_at, 'date', readonly); field(employmentGrid, 'resigned_at', account.resigned_at, 'date', readonly);
        field(employmentGrid, 'employment_status', account.employment_status || 'employed', 'select', readonly); field(employmentGrid, 'status', account.status == null ? 1 : account.status, 'select', readonly || account.administrator);
        if (!readonly) {
            var security = section(t.account_security), passwordArea = node('div', null, 'hr-basic-grid'); passwordArea.id = 'hr-password-fields';
            var password = field(passwordArea, 'password', '', 'password', false), confirmation = field(passwordArea, 'password_confirmation', '', 'password', false);
            if (mode !== 'create') {
                var toggle = node('button', t.change_password, 'btn btn-default btn-sm hr-password-toggle'); toggle.type = 'button'; toggle.setAttribute('aria-controls', passwordArea.id); toggle.setAttribute('aria-expanded', 'false');
                passwordArea.hidden = true; password.disabled = true; confirmation.disabled = true;
                toggle.onclick = function () {
                    var expand = passwordArea.hidden; passwordArea.hidden = !expand; password.disabled = !expand; confirmation.disabled = !expand;
                    toggle.setAttribute('aria-expanded', String(expand)); toggle.textContent = expand ? t.cancel_password_change : t.change_password;
                    if (expand) password.focus(); else { password.value = ''; confirmation.value = ''; }
                };
                security.appendChild(toggle);
            }
            security.append(passwordArea, node('p', t.password_hint, 'hr-hint'));
        }
        if (account.administrator) fields.appendChild(node('p', t.system_locked, 'hr-retained'));
        var memberships = section(t.memberships, readonly ? null : t.memberships_hint), rows = node('div', null, 'hr-membership-list');
        var retained = (account.memberships || []).filter(function (item) { return readonly || !item.editable; });
        if (retained.length) {
            if (!readonly) memberships.appendChild(node('h5', t.retained_heading, 'hr-retained-title'));
            retained.forEach(function (item) { memberships.appendChild(node('p', item.department_name + ' · ' + t[item.position] + (readonly ? '' : ' · ' + t.retained), 'hr-retained')); });
        }
        memberships.appendChild(rows);
        if (!readonly && !account.administrator) {
            var tools = node('div', null, 'hr-membership-tools'), add = node('button', t.add_membership, 'btn btn-default btn-sm'), count = node('span', '', 'hr-hint');
            add.type = 'button'; add.setAttribute('data-add-membership', ''); count.setAttribute('data-membership-count', ''); tools.append(add, count); memberships.appendChild(tools);
            add.onclick = function () {
                membership(rows, {});
                var last = rows.lastElementChild;
                if (!last) return;
                last.scrollIntoView({block: 'nearest'});
                window.requestAnimationFrame(function () {
                    window.requestAnimationFrame(function () {
                        if (!last.isConnected || !modal.hasClass('in')) return;
                        var select = last.querySelector('.hr-dept');
                        if ($.fn.select2) $(select).select2('open'); else select.focus();
                    });
                });
            };
            (account.memberships || []).filter(function (item) { return item.editable; }).forEach(function (item) { membership(rows, item); });
            if (!rows.children.length && departmentOptions.length) membership(rows, {});
            refreshDepartments(rows);
        } else if (!retained.length) memberships.appendChild(node('p', t.no_membership, 'hr-hint'));
    }
    function permissions(data) {
        permissionControls = []; fields.appendChild(node('p', t.permissions_hint, 'hr-hint')); var seen = new Set();
        function append(nodes, depth) {
            (nodes || []).forEach(function (item) {
                if (item.permission_id && !seen.has(item.permission_id)) {
                    seen.add(item.permission_id); var row = node('label', null, 'hr-permission'), name = node('span', item.title), select = node('select'); row.style.marginLeft = (depth * 12) + 'px'; select.className = 'form-control';
                    select.add(new Option(t.inherit, '')); select.add(new Option(t.allow, 'allow')); select.add(new Option(t.deny, 'deny'));
                    var override = data.overrides[item.permission_id]; select.value = override ? override.effect : ''; select.dataset.permission = item.permission_id; row.append(name, select); fields.appendChild(row); permissionControls.push(select);
                } else if (!item.permission_id) fields.appendChild(node('h4', item.title));
                append(item.children, depth + 1);
            });
        }
        append(data.tree, 0);
    }
    function destroyPickers() {
        closePicker(); if ($.fn.select2) $(fields).find('.hr-dept').each(function () { if ($(this).data('select2')) $(this).select2('destroy'); });
    }
    async function open(action, id) {
        if (busy) return;
        var seq = ++sequence; mode = action; recordId = id || null; destroyPickers(); showError(''); fields.replaceChildren(node('p', t.loading, 'hr-loading'));
        save.hidden = action === 'view'; save.disabled = true; save.textContent = action === 'delete' ? t.delete_confirm : t.save;
        document.getElementById('hr-modal-title').textContent = action === 'create' ? t.add : t[action]; modal.modal({backdrop: 'static'}); body.scrollTop = 0;
        try {
            var data = await api(action === 'create' ? '/options' : '/' + id + (action === 'permissions' ? '/permissions' : '')); if (seq !== sequence) return; fields.replaceChildren();
            if (action === 'permissions') permissions(data);
            else if (action === 'delete') { fields.appendChild(node('p', t.delete_warning, 'alert alert-warning')); fields.appendChild(node('p', data.account.name + ' (' + data.account.username + ')')); (data.account.memberships || []).forEach(function (item) { fields.appendChild(node('p', item.department_name + ' · ' + t[item.position])); }); }
            else { departmentOptions = data.departments; basic(data.account || {}, action === 'view'); }
            save.disabled = false;
        } catch (e) { if (seq === sequence) { fields.replaceChildren(); showError(e.message); } }
    }
    form.addEventListener('submit', async function (event) {
        event.preventDefault(); if (busy || save.disabled || mode === 'view') return;
        var incomplete = fields.querySelector('.hr-dept:invalid, .hr-position:invalid');
        if (incomplete) { showError(incomplete.classList.contains('hr-dept') ? t.choose_department : t.choose_position); if ($.fn.select2 && $(incomplete).data('select2')) $(incomplete).select2('open'); else incomplete.focus(); return; }
        if (!form.reportValidity()) return;
        var data = {}, path = recordId ? '/' + recordId : '', method = recordId ? 'PUT' : 'POST';
        if (mode === 'delete') method = 'DELETE';
        else if (mode === 'permissions') { path += '/permissions'; data.permissions = {}; permissionControls.forEach(function (control) { if (control.value) data.permissions[control.dataset.permission] = control.value; }); }
        else {
            ['username', 'name', 'hired_at', 'resigned_at', 'employment_status', 'status', 'password', 'password_confirmation'].forEach(function (key) { var input = document.getElementById('hr-input-' + key); data[key] = input.disabled && input.type === 'password' ? '' : input.value; });
            data.memberships = Array.from(fields.querySelectorAll('.hr-membership-editable')).map(function (row) { return {department_id: Number(row.querySelector('.hr-dept').value), position: row.querySelector('.hr-position').value}; });
        }
        closePicker(); busy = true; showError(''); form.setAttribute('aria-busy', 'true'); var states = Array.from(form.querySelectorAll('input,select,button')).map(function (input) { return [input, input.disabled]; });
        states.forEach(function (state) { state[0].disabled = true; }); save.textContent = t.saving;
        try { await api(path, method, data); window.location.reload(); }
        catch (e) { busy = false; form.removeAttribute('aria-busy'); states.forEach(function (state) { if (state[0].isConnected) state[0].disabled = state[1]; }); save.textContent = mode === 'delete' ? t.delete_confirm : t.save; showError(e.message); }
    });
    document.querySelectorAll('[data-hr-view],[data-hr-edit],[data-hr-delete],[data-hr-permissions]').forEach(function (button) {
        button.addEventListener('click', function () { var key = ['view', 'edit', 'delete', 'permissions'].find(function (name) { return button.dataset['hr' + name[0].toUpperCase() + name.slice(1)] !== undefined; }); open(key, button.dataset['hr' + key[0].toUpperCase() + key.slice(1)]); });
    });
    if (document.getElementById('hr-add')) document.getElementById('hr-add').onclick = function () { open('create'); };
    modal.on('hide.bs.modal', function (event) { if (busy) event.preventDefault(); else closePicker(); });
    modal.on('hidden.bs.modal', function () { ++sequence; destroyPickers(); fields.replaceChildren(); form.removeAttribute('aria-busy'); });
})(window.jQuery);
