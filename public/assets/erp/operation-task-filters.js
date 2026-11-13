(function(){
function init(){
 var combos=[];
 document.querySelectorAll('[data-search-combobox]').forEach(function(root){
   var input=root.querySelector('input'),clear=root.querySelector('[data-clear-search]'),toggle=root.querySelector('[data-search-toggle]'),list=root.querySelector('[role="listbox"]'),empty=root.querySelector('[data-search-empty]');
   var seen=new Set(),options=Array.from(root.querySelectorAll('[data-search-option]')).filter(function(option){var value=option.getAttribute('data-value');if(seen.has(value)){option.remove();return false;}seen.add(value);return true;});
   var visible=[],active=-1;
   document.body.appendChild(list);
   function position(){
     if(list.hidden)return;
     var rect=input.getBoundingClientRect(),height=window.innerHeight,width=document.documentElement.clientWidth;
     if(!rect.width||rect.bottom<=0||rect.top>=height){close();return;}
     var gap=4,edge=8,below=Math.max(0,height-rect.bottom-gap-edge),above=Math.max(0,rect.top-gap-edge);
     var upward=below<Math.min(230,list.scrollHeight)&&above>below;
     var available=upward?above:below;
     list.style.width=Math.min(rect.width,width-edge*2)+'px';
     list.style.maxHeight=Math.min(230,available)+'px';
     list.style.left=Math.max(edge,Math.min(rect.left,width-rect.width-edge))+'px';
     list.style.top=(upward?Math.max(edge,rect.top-gap-Math.min(list.scrollHeight,230,available)):rect.bottom+gap)+'px';
   }
   function close(){list.hidden=true;input.setAttribute('aria-expanded','false');toggle.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');active=-1;options.forEach(function(option){option.classList.remove('is-active');option.setAttribute('aria-selected','false');});}
   function highlight(index){active=index;options.forEach(function(option){var selected=option===visible[active];option.classList.toggle('is-active',selected);option.setAttribute('aria-selected',String(selected));});if(visible[active]){input.setAttribute('aria-activedescendant',visible[active].id);visible[active].scrollIntoView({block:'nearest'});}else input.removeAttribute('aria-activedescendant');}
   function open(){combos.forEach(function(combo){if(combo.root!==root)combo.close();});var term=input.value.trim().toLocaleLowerCase();visible=options.filter(function(option){option.hidden=!option.getAttribute('data-value').toLocaleLowerCase().includes(term);return !option.hidden;});empty.hidden=visible.length!==0;list.hidden=false;input.setAttribute('aria-expanded','true');toggle.setAttribute('aria-expanded','true');clear.hidden=!input.value;highlight(-1);position();}
   function choose(option){input.value=option.getAttribute('data-value');clear.hidden=false;input.dispatchEvent(new Event('input',{bubbles:true}));input.focus();close();}
   options.forEach(function(option,index){option.id=list.id+'-'+index;option.addEventListener('click',function(){choose(option);});});
   list.addEventListener('mousedown',function(event){if(event.target.closest('[data-search-option]'))event.preventDefault();});
   input.addEventListener('input',open);input.addEventListener('focus',open);
   input.addEventListener('keydown',function(event){if(event.key==='ArrowDown'||event.key==='ArrowUp'){event.preventDefault();if(list.hidden)open();if(visible.length)highlight(active<0?(event.key==='ArrowDown'?0:visible.length-1):(active+(event.key==='ArrowDown'?1:visible.length-1))%visible.length);}else if(event.key==='Enter'&&!list.hidden&&active>=0){event.preventDefault();choose(visible[active]);}else if(event.key==='Escape'){close();}else if(event.key==='Tab')close();});
   clear.addEventListener('click',function(){input.value='';clear.hidden=true;input.dispatchEvent(new Event('input',{bubbles:true}));input.focus();});
   toggle.addEventListener('click',function(){if(list.hidden)open();else close();});
   root.addEventListener('focusout',function(event){if(!root.contains(event.relatedTarget)&&!list.contains(event.relatedTarget))close();});
   clear.hidden=!input.value;combos.push({root:root,list:list,close:close,position:position});
 });
 document.addEventListener('click',function(event){combos.forEach(function(combo){if(!combo.root.contains(event.target)&&!combo.list.contains(event.target))combo.close();});});
 window.addEventListener('resize',function(){combos.forEach(function(combo){combo.position();});});
 document.addEventListener('scroll',function(event){combos.forEach(function(combo){if(!combo.list.contains(event.target))combo.position();});},true);
 window.addEventListener('pagehide',function(){combos.forEach(function(combo){combo.close();});});
 var dashboard=document.getElementById('ot-dashboard-form');
 document.querySelectorAll('form[method="get"]').forEach(function(form){var key=dashboard&&form===dashboard?'dashboard_tab':'review_tab';if(!dashboard&&!document.querySelector('[data-review-tab]'))return;var hidden=form.querySelector('input[name="'+key+'"]')||document.createElement('input');hidden.type='hidden';hidden.name=key;hidden.value=new URL(location.href).searchParams.get(key)||(dashboard?'tests':'recaps');form.appendChild(hidden);document.querySelectorAll(dashboard?'[data-dashboard-tab]':'[data-review-tab]').forEach(function(button){button.addEventListener('click',function(){hidden.value=button.getAttribute(dashboard?'data-dashboard-tab':'data-review-tab');var url=new URL(location.href);url.searchParams.set(key,hidden.value);history.replaceState({},'',url);});});});
 if(dashboard){var current=new URL(location.href).searchParams.get('dashboard_tab')==='recaps'?'recaps':'tests';var button=document.querySelector('[data-dashboard-tab="'+(current==='recaps'?'recaps':'tests')+'"]');document.querySelectorAll('[data-dashboard-tab]').forEach(function(node){node.classList.toggle('is-active',node.getAttribute('data-dashboard-tab')===current);});document.querySelectorAll('[data-dashboard-pane]').forEach(function(node){node.classList.toggle('is-active',node.getAttribute('data-dashboard-pane')===current);});}
 document.addEventListener('click',function(event){var anchor=event.target.closest('a');if(!anchor||!anchor.closest('.ot-filter-shortcuts,.ot-filter-actions'))return;var url=new URL(anchor.href,location.href);if(url.pathname!==location.pathname)return;var tab=new URL(location.href).searchParams.get('review_tab');if(tab)url.searchParams.set('review_tab',tab);anchor.href=url.toString();});
 try{var message=JSON.parse(sessionStorage.getItem('operation-task-review-success')||'null');if(message){sessionStorage.removeItem('operation-task-review-success');if(window.ErpAdminNotifier)window.ErpAdminNotifier.notify({type:'success',title:message.title,message:message.message});}}catch(_){}
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();