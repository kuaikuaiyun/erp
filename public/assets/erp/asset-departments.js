(function () {
    'use strict';
    var c = window.AssetDepartmentsConfig, t = c.text, dialog = document.getElementById('hr-department-dialog'), form = document.getElementById('hr-department-form'), fields = document.getElementById('hr-department-fields'), error = document.getElementById('hr-department-error'), save = document.getElementById('hr-department-save');
    var seq = 0, busy = false, recordId = null;
    function node(tag, text, cls) { var e = document.createElement(tag); if (text != null) e.textContent = text; if (cls) e.className = cls; return e; }
    function fail(message) { error.textContent = message || ''; error.hidden = !message; }
    async function api(path, method, data) {
        var response = await fetch(c.base + path, {method: method || 'GET', credentials: 'same-origin', headers: {Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}, body: data ? JSON.stringify(data) : undefined});
        var result = await response.json().catch(function () { return {}; }); if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join('\n') : (response.status >= 500 ? t.failed : result.message || t.failed)); return result;
    }
    async function open(id, action, page, search) {
        if (busy) return;
        var current = ++seq; recordId = id; fail(''); fields.replaceChildren(node('p', t.loading)); save.hidden = action !== 'edit'; save.disabled = true; document.getElementById('hr-department-title').textContent = action === 'members' ? t.department_members : t[action]; if (!dialog.open) dialog.showModal();
        try {
            var data = await api('/' + id + (action === 'members' ? '/members?page=' + (page || 1) + '&search=' + encodeURIComponent(search || '') : '')); if (current !== seq) return; fields.replaceChildren();
            if (action === 'members') {
                var toolbar = node('div', null, 'ar-tools'), input = node('input'), query = node('button', t.query, 'btn btn-primary'); input.className = 'form-control'; input.maxLength = 100; input.placeholder = t.search_placeholder; input.value = search || ''; input.setAttribute('aria-label',t.search); query.type = 'button'; query.onclick = function () { open(id, action, 1, input.value); }; input.onkeydown = function (e) { if (e.key === 'Enter') { e.preventDefault(); query.click(); } }; toolbar.append(input,query); fields.appendChild(toolbar);
                var table = node('table', null, 'table table-bordered'), head = node('tr'); [t.username,t.name,t.position,t.company,t.status].forEach(function (label) { head.appendChild(node('th',label)); }); table.appendChild(head);
                data.data.forEach(function (member) { var row = node('tr'); [member.username,member.name,t[member.position] || t.member,member.company || t.no_company,member.status ? t.enabled : t.disabled].forEach(function (value) { row.appendChild(node('td',value)); }); table.appendChild(row); });
                if (!data.data.length) fields.appendChild(node('p',t.empty)); var wrap = node('div',null,'table-responsive'); wrap.appendChild(table); fields.appendChild(wrap);
                var nav = node('div',null,'ar-tools'); [[-1,t.previous],[1,t.next]].forEach(function (pair) { var button = node('button',pair[1],'btn btn-default'); button.type = 'button'; button.disabled = data.current_page + pair[0] < 1 || data.current_page + pair[0] > data.last_page; button.onclick = function () { open(id,action,data.current_page + pair[0],search); }; nav.appendChild(button); }); nav.appendChild(node('span',data.current_page+' / '+data.last_page)); fields.appendChild(nav); return;
            }
            var grid = node('div',null,'ar-form-grid'); fields.appendChild(grid);
            ['name','short_name','slug','parent_name','sort'].forEach(function (key) { var label = node('label',t.department_fields[key]), input = node('input'); input.name = key; input.className='form-control'; input.type=key==='sort'?'number':'text'; input.value=data.record[key] == null ? '' : data.record[key]; input.disabled=action!=='edit' || ['slug','parent_name'].includes(key); if(key==='sort'){input.min=0;input.max=999999;}else input.maxLength=50; input.required=['name','sort'].includes(key); label.appendChild(input); grid.appendChild(label); }); save.disabled=false;
        } catch(e) { if(current===seq){fields.replaceChildren();fail(e.message);} }
    }
    form.onsubmit=async function(e){e.preventDefault();if(busy||save.hidden||save.disabled||!form.reportValidity())return;busy=true;save.disabled=true;var data={};['name','short_name','sort'].forEach(function(key){data[key]=fields.querySelector('[name='+key+']').value;});try{await api('/'+recordId,'PUT',data);window.location.reload();}catch(e){fail(e.message);}finally{busy=false;save.disabled=false;}};
    ['view','edit','members'].forEach(function(action){document.querySelectorAll('[data-department-'+action+']').forEach(function(button){button.onclick=function(){open(button.dataset['department'+action[0].toUpperCase()+action.slice(1)],action);};});});
    dialog.querySelectorAll('[data-department-close]').forEach(function(button){button.onclick=function(){if(!busy)dialog.close();};});dialog.addEventListener('cancel',function(e){if(busy)e.preventDefault();});dialog.addEventListener('close',function(){++seq;fields.replaceChildren();});
})();
