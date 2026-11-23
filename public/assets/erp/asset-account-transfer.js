(function () {
    'use strict';
    var c = window.AssetAccountsConfig, t = c.text, x = t.transfer, modal = document.getElementById('hr-transfer-dialog');
    var form = document.getElementById('hr-transfer-form'), fields = document.getElementById('hr-transfer-fields'), error = document.getElementById('hr-transfer-error'), save = document.getElementById('hr-transfer-save');
    var mode = '', busy = false, seq = 0, token = null, selected = [];
    function node(tag, text, cls) { var e = document.createElement(tag); if (text != null) e.textContent = text; if (cls) e.className = cls; return e; }
    function fail(message) { error.textContent = message || ''; error.hidden = !message; }
    function ids() { return Array.from(document.querySelectorAll('[data-hr-select]:checked')).map(function (e) { return Number(e.dataset.hrSelect); }); }
    async function api(path, method, data) {
        var multipart = data instanceof FormData, response = await fetch(c.base + '/transfer/' + path, {method: method || 'GET', credentials: 'same-origin', headers: Object.assign({Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, multipart ? {} : {'Content-Type': 'application/json'}), body: data ? (multipart ? data : JSON.stringify(data)) : undefined});
        var result = await response.json().catch(function () { return {}; });
        if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join('\n') : (response.status >= 500 ? t.failed : result.message || t.failed));
        return result;
    }
    function field(label, type) { var wrapper = node('label', label), input = node('input'); input.type = type; input.className = 'form-control'; input.required = true; wrapper.appendChild(input); fields.appendChild(wrapper); return input; }
    document.getElementById('hr-select-all').onchange = function () { var checked = this.checked; document.querySelectorAll('[data-hr-select]').forEach(function (e) { e.checked = checked; }); };
    async function open(action) {
        if (busy) return;
        selected = ids();
        if (action === 'export' || action === 'selected') {
            if (action === 'selected' && !selected.length) { window.alert(x.select_first); return; }
            var query = action === 'export' ? new URLSearchParams(window.location.search) : new URLSearchParams();
            query.delete('page'); selected.forEach(function (id) { if (action === 'selected') query.append('account_ids[]', id); });
            busy = true;
            try {
                var response = await fetch(c.base + '/transfer/export?' + query.toString(), {credentials: 'same-origin'});
                if (!response.ok) { var result = await response.json().catch(function () { return {}; }); throw new Error(result.errors ? Object.values(result.errors).flat().join('\n') : t.failed); }
                var url = URL.createObjectURL(await response.blob()), link = node('a'); link.href = url; link.download = 'hr-accounts.xlsx'; document.body.appendChild(link); link.click(); link.remove(); window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
            } catch (e) { window.alert(e.message); } finally { busy = false; }
            return;
        }
        if (action === 'company' && !selected.length) { window.alert(x.select_first); return; }
        var current = ++seq; mode = action; token = null; fields.replaceChildren(); fail(''); save.disabled = true; save.textContent = action === 'import' ? x.preview : t.save;
        document.getElementById('hr-transfer-title').textContent = x[action]; modal.showModal();
        if (action === 'import') {
            fields.appendChild(node('p', x.hint, 'ar-hint'));
            var link = node('a', x.template, 'btn btn-default'); link.href = c.base + '/transfer/template'; fields.appendChild(link);
            var file = field(x.file, 'file'); file.id = 'hr-transfer-file'; file.accept = '.xlsx';
            var password = field(x.password, 'password'); password.id = 'hr-transfer-password'; password.minLength = 8; password.maxLength = 255; password.autocomplete = 'new-password'; password.required = false;
            var preview = node('div'); preview.id = 'hr-transfer-preview'; fields.appendChild(preview);
            file.onchange = function () { token = null; preview.replaceChildren(); save.textContent = x.preview; };
            save.disabled = false;
        } else {
            fields.appendChild(node('p', x.company_hint, 'ar-hint'));
            fields.appendChild(node('p', selected.length + ' · ' + x.company));
            try { var options = await api('options'); if (seq !== current) return;
                var label = node('label', t.company), select = node('select'); select.id = 'hr-transfer-company'; select.className = 'form-control'; select.required = true; select.add(new Option(x.choose_company, ''));
                options.companies.forEach(function (company) { select.add(new Option(company.name, company.id)); }); label.appendChild(select); fields.appendChild(label); save.disabled = !options.companies.length;
            } catch (e) { if (seq === current) fail(e.message); }
        }
    }
    form.onsubmit = async function (event) {
        event.preventDefault(); if (busy || save.disabled || !form.reportValidity()) return;
        var password = document.getElementById('hr-transfer-password');
        if (mode === 'import' && token && password.value.length < 8) { password.required = true; password.reportValidity(); return; }
        if (mode === 'company' && !window.confirm(x.confirm_company)) return;
        busy = true; save.disabled = true; fail(''); save.textContent = t.saving;
        var controls = Array.from(fields.querySelectorAll('input,select')), states = controls.map(function (e) { return e.disabled; }); controls.forEach(function (e) { e.disabled = true; });
        try {
            if (mode === 'company') { await api('company', 'POST', {account_ids: selected, company_id: document.getElementById('hr-transfer-company').value}); window.location.reload(); return; }
            if (token) { await api('import', 'POST', {token: token, password: password.value}); password.value = ''; window.location.reload(); return; }
            var data = new FormData(); data.append('file', document.getElementById('hr-transfer-file').files[0]); var result = await api('preview', 'POST', data), preview = document.getElementById('hr-transfer-preview'); preview.replaceChildren(); token = result.token;
            if (result.errors.length) result.errors.forEach(function (row) { preview.appendChild(node('p', row.line + ': ' + row.messages.join(' / '), 'text-danger')); });
            else preview.appendChild(node('p', x.preview_ok));
            var table = node('table', null, 'table table-bordered'), head = node('tr'); [x.line,t.username,t.name,t.company,t.memberships,t.position].forEach(function (label) { head.appendChild(node('th',label)); }); table.appendChild(head);
            result.rows.forEach(function (row) { var tr = node('tr'); [row._line,row.username,row.name,row.company_name || row.company_id,row.department_codes,row.department_positions].forEach(function (value) { tr.appendChild(node('td', value)); }); table.appendChild(tr); });
            var wrap = node('div', null, 'table-responsive'); wrap.appendChild(table); preview.appendChild(wrap);
        } catch (e) { fail(e.message); }
        finally { busy = false; controls.forEach(function (e, i) { e.disabled = states[i]; }); save.disabled = false; save.textContent = mode === 'import' ? (token ? x.commit : x.preview) : t.save; }
    };
    document.querySelectorAll('[data-hr-transfer]').forEach(function (e) { e.onclick = function () { open(e.dataset.hrTransfer); }; });
    modal.querySelectorAll('[data-transfer-close]').forEach(function (e) { e.onclick = function () { if (!busy) modal.close(); }; });
    modal.addEventListener('cancel', function (e) { if (busy) e.preventDefault(); });
    modal.addEventListener('close', function () { ++seq; token = null; fields.replaceChildren(); });
})();
