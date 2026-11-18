(function ($) {
    'use strict';
    var c = window.DisciplineConfig, t = c.text, members = new Map(c.members.map(function (m) { return [String(m.id), m]; }));
    var day = null, dayPage = 1, dayLast = 1, memberPage = 1, memberLast = 1, selected = null, record = null, mode = '', busy = false, dirty = false, logPage = 1, logLast = 1;
    var daySeq = 0, pickerSeq = 0, detailSeq = 0, logSeq = 0;
    function el(id) { return document.getElementById(id); }
    function node(tag, text, cls) { var n = document.createElement(tag); n.textContent = text == null ? '' : text; if (cls) n.className = cls; return n; }
    function replace(text, data) { Object.keys(data).forEach(function (key) { text = text.split(':' + key).join(String(data[key])); }); return text; }
    function error(id, message) { el(id).textContent = message || ''; el(id).hidden = !message; }
    function query(data) { var q = new URLSearchParams(); Object.keys(data).forEach(function (key) { if (data[key] !== '' && data[key] != null) q.set(key, data[key]); }); return q.toString(); }
    async function api(path, method, body) {
        var response = await fetch(c.base + path, {method: method || 'GET', credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, body: body ? JSON.stringify(body) : undefined});
        var data; try { data = await response.json(); } catch (_) { throw new Error(t.error); }
        if (!response.ok) { throw new Error(data.errors ? Object.values(data.errors).flat().join('\n') : (data.message || t.error)); }
        return data;
    }
    var summaries = new Map(c.summaries.map(function (s) { return [String(s.member_id) + '/' + s.date, s]; }));
    document.querySelectorAll('.discipline-cell').forEach(function (button) {
        var summary = summaries.get(button.dataset.member + '/' + button.dataset.date);
        if (summary) { button.appendChild(node('span', summary.summary)); if (summary.count > 1) button.appendChild(node('small', replace(t.more, {count: summary.count - 1}))); }
        button.addEventListener('click', function () {
            day = {member: members.get(button.dataset.member), date: button.dataset.date};
            dayPage = 1;
            if (!summary && c.canManage && day.member.departments.length) { editor('create', null, day.member, day.date); return; }
            el('discipline-day-title').textContent = replace(t.day_title, {name: day.member.name, date: day.date});
            el('discipline-day-add').hidden = !c.canManage || !day.member.departments.length;
            $('#discipline-day').modal('show'); loadDay(1);
        });
    });
    async function loadDay(page) {
        var seq = ++daySeq; dayPage = page; error('discipline-day-error', ''); el('discipline-records').replaceChildren(node('p', t.loading));
        try {
            var data = await api('/records?' + query({month:c.month, department_id:c.department, member_id:day.member.id, date:day.date, page:page}));
            if (seq !== daySeq) return;
            dayLast = data.last_page; el('discipline-records').replaceChildren();
            if (!data.records.length) el('discipline-records').appendChild(node('p', t.empty_day, 'text-muted'));
            data.records.forEach(function (r) {
                var box = node('div', '', 'discipline-record'); box.appendChild(node('div', r.department_name, 'text-muted')); box.appendChild(node('p', r.remarks)); box.appendChild(node('p', t.created_at + ': ' + r.created_at, 'text-muted'));
                [ ['view', t.view], ['edit', t.edit], ['delete', t.delete] ].forEach(function (item) {
                    if (item[0] !== 'view' && !r.writable) return;
                    var b = node('button', item[1], 'btn btn-sm ' + (item[0] === 'delete' ? 'btn-danger' : 'btn-default')); b.type='button';
                    b.addEventListener('click', function () { openRecord(r.id, item[0]); }); box.appendChild(b);
                }); el('discipline-records').appendChild(box);
            });
            el('discipline-day-page').textContent = replace(t.page, {page:dayPage, total:dayLast}); el('discipline-day-prev').disabled = dayPage <= 1; el('discipline-day-next').disabled = dayPage >= dayLast;
        } catch (e) { if (seq === daySeq) { el('discipline-records').replaceChildren(); error('discipline-day-error', e.message); } }
    }
    async function openRecord(id, action) {
        var seq = ++detailSeq;
        error('discipline-day-error', '');
        try { var data = await api('/records/' + id); if (seq !== detailSeq) return; $('#discipline-day').modal('hide'); editor(action, data); }
        catch (e) { error('discipline-day-error', e.message); }
    }
    function pickMember(member) {
        selected = member; el('discipline-member-name').textContent = member.name + (member.username ? ' (' + member.username + ')' : '');
        el('discipline-owner').replaceChildren(new Option(t.select_department, ''));
        member.departments.forEach(function (d) { el('discipline-owner').add(new Option(d.name, d.id)); });
        if (member.departments.length === 1) el('discipline-owner').value = member.departments[0].id;
    }
    function editor(action, data, member, date) {
        ++pickerSeq; ++logSeq;
        mode = action; record = data; selected = null; busy = false; logPage = 1; error('discipline-editor-error', '');
        el('discipline-form').reset(); el('discipline-member-name').textContent = t.select_member;
        el('discipline-owner').replaceChildren(new Option(t.select_department, ''));
        el('discipline-editor-title').textContent = action === 'create' ? t.add : action === 'delete' ? t.delete_title : action === 'edit' ? t.edit_record : t.record_detail;
        el('discipline-member-picker').hidden = action !== 'create' || !!member;
        el('discipline-save').hidden = action !== 'create' && action !== 'edit'; el('discipline-save').disabled = false; el('discipline-save').textContent = t.save;
        el('discipline-delete').hidden = action !== 'delete'; el('discipline-delete').disabled = false;
        el('discipline-delete-confirm').hidden = action !== 'delete';
        ['discipline-date','discipline-remarks'].forEach(function (id) { el(id).disabled = action === 'view' || action === 'delete'; });
        el('discipline-owner').disabled = action !== 'create'; el('discipline-timestamps').textContent = '';
        el('discipline-audit').hidden = !data || !data.logs;
        if (data) {
            el('discipline-member-name').textContent = data.member_name; el('discipline-owner').add(new Option(data.department_name, data.department_id)); el('discipline-owner').value = data.department_id;
            el('discipline-date').value = data.record_date; el('discipline-remarks').value = data.remarks;
            el('discipline-timestamps').textContent = t.created_at + ': ' + data.created_at + ' / ' + t.updated_at + ': ' + data.updated_at;
            renderLogs(data);
        } else {
            if (member) pickMember(member); else findMembers(1);
            el('discipline-date').value = date || c.month + '-01';
        }
        $('#discipline-editor').modal({backdrop:'static', keyboard:false});
    }
    function renderLogs(data) {
        logPage = data.log_page || 1; logLast = data.log_last_page || 1;
        el('discipline-audit-rows').replaceChildren();
        (data.logs || []).forEach(function (log) {
            var box = node('div', '', 'discipline-record'); box.appendChild(node('p', log.action + ' · ' + log.actor + ' · ' + log.at));
            [['before', log.before], ['after', log.after]].forEach(function (item) {
                if (item[1]) box.appendChild(node('pre', t[item[0]] + '\n' + item[1].record_date.substring(0,10) + ' · ' + item[1].department_name + '\n' + item[1].remarks));
            }); el('discipline-audit-rows').appendChild(box);
        });
        el('discipline-log-prev').disabled = logPage <= 1; el('discipline-log-next').disabled = logPage >= logLast;
    }
    async function logs(page) { var seq = ++logSeq; try { var data = await api('/records/' + record.id + '?page=' + page); if(seq === logSeq) renderLogs(data); } catch(e) { if(seq === logSeq) error('discipline-editor-error', e.message); } }
    async function findMembers(page) {
        var seq = ++pickerSeq; el('discipline-member-results').replaceChildren(node('span', t.loading));
        try {
            var data = await api('/members?' + query({month:c.month, search:el('discipline-member-search').value, page:page}));
            if (seq !== pickerSeq) return;
            memberPage = data.page; memberLast = data.last_page; el('discipline-member-results').replaceChildren();
            if (!data.members.length) el('discipline-member-results').appendChild(node('span', t.no_members));
            data.members.forEach(function (m) { var button = node('button', m.name + ' (' + m.username + ')', 'btn btn-default btn-sm'); button.type = 'button'; button.addEventListener('click', function () { pickMember(m); }); el('discipline-member-results').appendChild(button); });
            el('discipline-member-prev').disabled = memberPage <= 1; el('discipline-member-next').disabled = memberPage >= memberLast;
        } catch(e) { if (seq === pickerSeq) { el('discipline-member-results').replaceChildren(); error('discipline-editor-error', e.message); } }
    }
    async function submit(remove) {
        if (busy) return;
        var data;
        if (remove) data = {version:record.version};
        else {
            if (!el('discipline-form').reportValidity()) return;
            if (mode === 'create' && !selected) { error('discipline-editor-error', t.required); return; }
            data = {record_date:el('discipline-date').value, remarks:el('discipline-remarks').value};
            if (record) data.version = record.version;
            else { data.member_id = selected.id; data.department_id = el('discipline-owner').value; }
        }
        busy = true; error('discipline-editor-error', ''); el('discipline-save').disabled = true; el('discipline-delete').disabled = true;
        try {
            await api('/records' + (record ? '/' + record.id : ''), remove ? 'DELETE' : record ? 'PUT' : 'POST', data);
            dirty = true; busy = false; $('#discipline-editor').modal('hide'); window.location.reload();
        } catch (e) { busy = false; error('discipline-editor-error', e.message); el('discipline-save').disabled = false; el('discipline-delete').disabled = false; }
    }
    el('discipline-form').addEventListener('submit', function (event) { event.preventDefault(); submit(false); });
    el('discipline-delete').addEventListener('click', function () { submit(true); });
    if (el('discipline-add')) el('discipline-add').addEventListener('click', function () { day = null; editor('create'); });
    el('discipline-day-add').addEventListener('click', function () { $('#discipline-day').modal('hide'); editor('create', null, day.member, day.date); });
    el('discipline-day-prev').addEventListener('click', function () { if(dayPage>1) loadDay(dayPage-1); });
    el('discipline-day-next').addEventListener('click', function () { if(dayPage<dayLast) loadDay(dayPage+1); });
    el('discipline-member-find').addEventListener('click', function () { findMembers(1); });
    el('discipline-member-search').addEventListener('keydown', function (event) { if(event.key === 'Enter') { event.preventDefault(); findMembers(1); } });
    el('discipline-member-prev').addEventListener('click', function () { if(memberPage>1) findMembers(memberPage-1); });
    el('discipline-member-next').addEventListener('click', function () { if(memberPage<memberLast) findMembers(memberPage+1); });
    el('discipline-log-prev').addEventListener('click', function () { if(logPage>1) logs(logPage-1); });
    el('discipline-log-next').addEventListener('click', function () { if(logPage<logLast) logs(logPage+1); });
    $('#discipline-editor').on('hide.bs.modal', function (event) { if(busy) event.preventDefault(); });
    $('#discipline-editor').on('hidden.bs.modal', function () { ++pickerSeq; ++logSeq; if(day && !dirty) { $('#discipline-day').modal('show'); loadDay(dayPage); } });
    $('#discipline-day, #discipline-editor').on('hidden.bs.modal', function () { if($('.modal.in').length) $('body').addClass('modal-open'); });
})(window.jQuery);
