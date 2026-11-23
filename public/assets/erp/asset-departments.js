(function () {
    'use strict';
    var c = window.AssetDepartmentsConfig, t = c.text, dialog = document.getElementById('hr-department-dialog'), form = document.getElementById('hr-department-form'), fields = document.getElementById('hr-department-fields'), error = document.getElementById('hr-department-error'), save = document.getElementById('hr-department-save');
    document.querySelectorAll('[data-department-toggle]').forEach(function (button) {
        button.onclick = function () {
            var expanded = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', String(expanded)); button.setAttribute('aria-label', expanded ? t.department_collapse : t.department_expand);
            document.querySelectorAll('[data-department-child]').forEach(function (row) { if (row.dataset.departmentChild === button.dataset.departmentToggle) row.hidden = !expanded; });
        };
    });
    var seq = 0, busy = false, recordId = null;
    function node(tag, text, cls) { var e = document.createElement(tag); if (text != null) e.textContent = text; if (cls) e.className = cls; return e; }
    function fail(message) { error.textContent = message || ''; error.hidden = !message; }
    async function api(path, method, data) {
        var response = await fetch(c.base + path, {method: method || 'GET', credentials: 'same-origin', headers: {Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}, body: data ? JSON.stringify(data) : undefined});
        var result = await response.json().catch(function () { return {}; }); if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join('\n') : (response.status >= 500 ? t.failed : result.message || t.failed)); return result;
    }
    async function open(id, action, page, search) {
        if (busy) return;
        if (action === 'members') return openMembers(id);
        dialog.classList.remove('hd-members-dialog');
        var current = ++seq; recordId = id; fail(''); fields.replaceChildren(node('p', t.loading)); save.hidden = action !== 'edit'; save.disabled = true; document.getElementById('hr-department-title').textContent = action === 'members' ? t.department_members : t[action]; if (!dialog.open) dialog.showModal();
        try {
            var data = await api('/' + id + (action === 'members' ? '/members?page=' + (page || 1) + '&search=' + encodeURIComponent(search || '') : '')); if (current !== seq) return; fields.replaceChildren();
            var grid = node('div',null,'ar-form-grid'); fields.appendChild(grid);
            ['name','short_name','slug','parent_name','sort'].forEach(function (key) { var label = node('label',t.department_fields[key]), input = node('input'); input.name = key; input.className='form-control'; input.type=key==='sort'?'number':'text'; input.value=data.record[key] == null ? '' : data.record[key]; input.disabled=action!=='edit' || ['slug','parent_name'].includes(key); if(key==='sort'){input.min=0;input.max=999999;}else input.maxLength=50; input.required=['name','sort'].includes(key); label.appendChild(input); grid.appendChild(label); }); save.disabled=false;
        } catch(e) { if(current===seq){fields.replaceChildren();fail(e.message);} }
    }
    async function openMembers(id) {
        if (busy) return;
        var current = ++seq, mt = t.member_tool, selected = new Map(), candidateSeq = 0, memberSeq = 0, candidateLoading = false, optionsLoaded = false;
        recordId = id; fail(''); save.hidden = true; save.disabled = true; fields.replaceChildren(node('p',t.loading)); dialog.classList.add('hd-members-dialog'); document.getElementById('hr-department-title').textContent = t.department_members; if (!dialog.open) dialog.showModal();
        function valid() { return current === seq; }
        function field(parent,label,choices) {
            var box=node('label',label), input=node(choices?'select':'input'); input.className='form-control'; input.setAttribute('aria-label',label);
            if(choices) choices.forEach(function(option){input.add(new Option(option.name,option.id));}); else {input.type='search';input.maxLength=100;input.placeholder=t.search_placeholder;}
            box.appendChild(input);parent.appendChild(box);return input;
        }
        function button(label,callback,cls) {var input=node('button',label,cls||'btn btn-default btn-sm');input.type='button';input.onclick=callback;return input;}
        function section(title) {var box=node('section',null,'hd-member-section');box.appendChild(node('h4',title));fields.appendChild(box);return box;}
        function table(parent,headings) {var wrap=node('div',null,'table-responsive'), table=node('table',null,'table hd-member-table'), head=node('thead'), row=node('tr'), body=node('tbody');headings.forEach(function(label){row.appendChild(node('th',label));});head.appendChild(row);table.append(head,body);wrap.appendChild(table);parent.replaceChildren(wrap);return {head:row,body:body};}
        function tags(cell,items) {(items||[]).forEach(function(item){cell.appendChild(node('span',item.name,'hd-member-tag'));});if(!items||!items.length)cell.textContent='—';}
        function pagination(parent,data,load) {parent.replaceChildren(node('span',t.total.replace(':from',data.from||0).replace(':to',data.to||0).replace(':total',data.total)));var nav=node('div',null,'ar-tools');[[-1,t.previous],[1,t.next]].forEach(function(pair){var control=button(pair[1],function(){load(data.current_page+pair[0]);});control.disabled=data.current_page+pair[0]<1||data.current_page+pair[0]>data.last_page;nav.appendChild(control);});nav.appendChild(node('span',data.current_page+' / '+data.last_page));parent.appendChild(nav);}
        var memberSearch,memberBody,memberNav,memberPage=1,candidateBody,candidateNav,add,selectedCount,chips,search,company,department,notice;
        function drawCurrent(data) {
            document.getElementById('hr-department-title').textContent=t.department_members+' · '+data.department.name;
            var list=table(memberBody,[t.name,t.username,t.position,t.company,t.status,mt.other_departments]);
            data.data.forEach(function(member){var row=node('tr');row.append(node('td',member.name),node('td',member.username));var position=node('td'),status=node('td'),other=node('td');position.appendChild(node('span',t[member.position]||t.member,'hd-member-pill '+(member.position==='member'?'':'is-lead')));status.appendChild(node('span',member.status?t.enabled:t.disabled,'hd-member-pill '+(member.status?'is-active':'')));tags(other,member.departments);row.append(position,node('td',member.company||t.no_company),status,other);list.body.appendChild(row);});
            if(!data.data.length){var empty=node('td',t.empty,'hd-member-empty');empty.colSpan=6;var row=node('tr');row.appendChild(empty);list.body.appendChild(row);}
            pagination(memberNav,data,loadMembers);
            var counts=data.department.counts||{};
            document.querySelectorAll('[data-department-members="'+id+'"] .hd-capacity strong').forEach(function(control,index){var key=['manager','deputy','member'][index],value=Number(counts[key]||0);control.textContent=value+(index===0?'/1':index===1?'/2':'');control.closest('.hd-capacity').classList.toggle('is-full',index===0?value>=1:index===1?value>=2:false);});
        }
        async function loadMembers(page) {
            var request=++memberSeq;memberPage=page||memberPage;
            try{var data=await api('/'+id+'/members?page='+memberPage+'&search='+encodeURIComponent(memberSearch.value));if(valid()&&request===memberSeq)drawCurrent(data);}catch(e){if(valid()&&request===memberSeq)fail(e.message);}
        }
        function refreshSelected() {
            selectedCount.textContent=mt.selected.replace(':count',selected.size);add.disabled=busy||candidateLoading||!selected.size;chips.replaceChildren();
            selected.forEach(function(label,key){var chip=button(label+' ×',function(){selected.delete(key);refreshSelected();},'hd-member-chip');chip.setAttribute('aria-label',mt.remove+' '+label);chip.disabled=busy;chips.appendChild(chip);});
            var inputs=Array.from(candidateBody.querySelectorAll('[data-member-candidate]'));inputs.forEach(function(input){input.checked=selected.has(Number(input.value));});
            var all=candidateBody.querySelector('[data-member-all]');if(all){var count=inputs.filter(function(input){return input.checked;}).length;all.checked=inputs.length>0&&count===inputs.length;all.indeterminate=count>0&&count<inputs.length;}
            chips.hidden=!selected.size;
        }
        function choose(input) {var key=Number(input.value);if(input.checked){if(selected.size>=100&&!selected.has(key)){input.checked=false;fail(mt.limit);return;}selected.set(key,input.dataset.label);}else selected.delete(key);}
        function drawCandidates(data) {
            var list=table(candidateBody,['',t.name,t.username,t.company,mt.existing_departments]),all=node('input');all.type='checkbox';all.dataset.memberAll='true';all.setAttribute('aria-label',mt.select_all);list.head.firstChild.appendChild(all);
            all.onchange=function(){candidateBody.querySelectorAll('[data-member-candidate]').forEach(function(input){input.checked=all.checked;choose(input);});refreshSelected();};
            data.data.forEach(function(member){var row=node('tr'),cell=node('td'),input=node('input'),other=node('td');input.type='checkbox';input.value=member.id;input.dataset.memberCandidate='true';input.dataset.label=member.name+' ('+member.username+')';input.setAttribute('aria-label',input.dataset.label);input.onchange=function(){choose(input);refreshSelected();};cell.appendChild(input);tags(other,member.departments);row.append(cell,node('td',member.name),node('td',member.username),node('td',member.company||t.no_company),other);list.body.appendChild(row);});
            if(!data.data.length){var row=node('tr'),empty=node('td',mt.empty,'hd-member-empty');empty.colSpan=5;row.appendChild(empty);list.body.appendChild(row);}
            pagination(candidateNav,data,loadCandidates);refreshSelected();
        }
        async function loadCandidates(page) {
            var request=++candidateSeq;candidateLoading=true;refreshSelected();
            var query=new URLSearchParams({page:page||1,search:search.value,company_id:company.value,department_id:department.value});
            try{var result=await api('/'+id+'/candidates?'+query.toString());if(!valid()||request!==candidateSeq)return;
                if(!optionsLoaded){optionsLoaded=true;result.companies.forEach(function(item){company.add(new Option(item.name,item.id));});result.departments.forEach(function(item){department.add(new Option(item.name,item.id));});}
                drawCandidates(result.rows);
            }catch(e){if(valid()&&request===candidateSeq)fail(e.message);}finally{if(valid()&&request===candidateSeq){candidateLoading=false;refreshSelected();}}
        }
        try {
            var data=await api('/'+id+'/members');if(!valid())return;fields.replaceChildren();notice=node('p',null,'alert alert-success');notice.hidden=true;fields.appendChild(notice);
            var members=section(mt.current),filter=node('div',null,'hd-member-filter hd-current-filter');memberSearch=field(filter,t.search);filter.append(button(t.query,function(){loadMembers(1);},'btn btn-primary'),button(t.reset,function(){memberSearch.value='';loadMembers(1);}));memberSearch.onkeydown=function(e){if(e.key==='Enter'){e.preventDefault();loadMembers(1);}};members.appendChild(filter);memberBody=node('div');memberNav=node('div',null,'hd-member-pagination');members.append(memberBody,memberNav);drawCurrent(data);
            var candidates=section(mt.candidates);candidates.appendChild(node('p',mt.hint,'ar-hint'));
            if(!data.department.can_add){candidates.appendChild(node('p',data.department.writable&&data.department.has_children?mt.leaf_only:mt.readonly,'hd-member-note'));return;}
            var filters=node('div',null,'hd-member-filter');company=field(filters,mt.company,[{id:'',name:mt.all_companies},{id:'unassigned',name:t.no_company}]);department=field(filters,mt.department,[{id:'',name:mt.all_departments}]);search=field(filters,t.search);filters.append(button(t.query,function(){loadCandidates(1);},'btn btn-primary'),button(t.reset,function(){company.value='';department.value='';search.value='';loadCandidates(1);}));search.onkeydown=function(e){if(e.key==='Enter'){e.preventDefault();loadCandidates(1);}};candidates.appendChild(filters);
            candidateBody=node('div');candidateNav=node('div',null,'hd-member-pagination');var tools=node('div',null,'hd-member-addbar');selectedCount=node('span',null,'ar-hint');chips=node('div',null,'hd-member-chips');
            add=button(mt.add,async function(){if(busy||candidateLoading||!selected.size||!window.confirm(mt.confirm.replace(':count',selected.size)))return;
                busy=true;++candidateSeq;fail('');notice.hidden=true;var controls=Array.from(fields.querySelectorAll('input,select,button')),states=controls.map(function(input){return input.disabled;});controls.forEach(function(input){input.disabled=true;});
                try{var result=await api('/'+id+'/members','POST',{user_ids:Array.from(selected.keys())});selected.clear();notice.textContent=result.message;notice.hidden=false;await Promise.all([loadMembers(1),loadCandidates(1)]);}
                catch(e){fail(e.message);}finally{busy=false;controls.forEach(function(input,index){if(input.isConnected)input.disabled=states[index];});refreshSelected();}
            },'btn btn-success');tools.append(add,selectedCount);candidates.append(candidateBody,candidateNav,chips,tools);refreshSelected();await loadCandidates(1);
        } catch(e){if(valid()){fields.replaceChildren();fail(e.message);}}
    }
    form.onsubmit=async function(e){e.preventDefault();if(busy||save.hidden||save.disabled||!form.reportValidity())return;busy=true;save.disabled=true;var data={};['name','short_name','sort'].forEach(function(key){data[key]=fields.querySelector('[name='+key+']').value;});try{await api('/'+recordId,'PUT',data);window.location.reload();}catch(e){fail(e.message);}finally{busy=false;save.disabled=false;}};
    ['view','edit','members'].forEach(function(action){document.querySelectorAll('[data-department-'+action+']').forEach(function(button){button.onclick=function(){open(button.dataset['department'+action[0].toUpperCase()+action.slice(1)],action);};});});
    dialog.querySelectorAll('[data-department-close]').forEach(function(button){button.onclick=function(){if(!busy)dialog.close();};});dialog.addEventListener('cancel',function(e){if(busy)e.preventDefault();});dialog.addEventListener('close',function(){++seq;fields.replaceChildren();});
})();
