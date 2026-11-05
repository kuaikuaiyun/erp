<div id="settlement-conditions" style="display:grid;gap:10px;grid-column:1/-1">@foreach($filters['conditions'] ?? [] as $index => $condition)<div data-settlement-condition style="display:flex;gap:8px;flex-wrap:wrap"><select class="form-control" data-condition-field required aria-label="筛选字段" style="width:180px">@foreach($conditionFields as $field => $label)<option value="{{ $field }}" @selected($condition['field'] === $field)>{{ $label }}</option>@endforeach</select><input type="hidden" data-condition-operator value="equals"><input class="form-control" data-condition-value value="{{ $condition['value'] ?? '' }}" aria-label="筛选值" style="width:220px"><button class="btn btn-default" type="button" data-remove-condition>移除</button></div>@endforeach</div>
<template id="settlement-condition-template"><div data-settlement-condition style="display:flex;gap:8px;flex-wrap:wrap"><select class="form-control" data-condition-field required aria-label="筛选字段" style="width:180px"><option value="">请选择筛选字段</option>@foreach($conditionFields as $field => $label)<option value="{{ $field }}">{{ $label }}</option>@endforeach</select><input type="hidden" data-condition-operator value="equals"><input class="form-control" data-condition-value aria-label="筛选值" style="width:220px"><button class="btn btn-default" type="button" data-remove-condition>移除</button></div></template>
@push('scripts')
<script>
(function(){
var conditions=document.getElementById('settlement-conditions'),add=document.getElementById('settlement-add-condition');
function updateConditions(){
    Array.from(conditions.children).forEach(function(row,index){
        var field=row.querySelector('[data-condition-field]'),op=row.querySelector('[data-condition-operator]'),value=row.querySelector('[data-condition-value]');
        var changed=row.dataset.field!==undefined&&row.dataset.field!==field.value;
        if(changed)value.value='';row.dataset.field=field.value;
        field.name='conditions['+index+'][field]';op.name='conditions['+index+'][operator]';op.value='equals';value.name='conditions['+index+'][value]';
        value.type='text';value.required=!!field.value;value.placeholder='输入筛选内容或选择选项';
        var list=row.querySelector('datalist');if(!list){list=document.createElement('datalist');row.appendChild(list);}if(changed)list.replaceChildren();
        list.id='settlement-options-'+index;value.setAttribute('list',list.id);
    });add.disabled=conditions.children.length>=10;
}
var timers=new WeakMap();
function suggest(event){
    var value=event.target;if(!value.matches('[data-condition-value]'))return;
    var row=value.closest('[data-settlement-condition]'),field=row.querySelector('[data-condition-field]').value;if(!field)return;
    clearTimeout(timers.get(value));timers.set(value,setTimeout(async function(){
        var text=value.value,form=conditions.closest('form'),url=new URL(form.action);
        url.search=new URLSearchParams(new FormData(form));
        Array.from(url.searchParams.keys()).forEach(function(key){if(key.startsWith('conditions[')||['download','template','page'].includes(key))url.searchParams.delete(key);});
        url.searchParams.set('suggestion_field',field);url.searchParams.set('suggestion_q',text);
        try{var response=await fetch(url,{headers:{Accept:'application/json'}});if(!response.ok)return;var items=await response.json();if(value.value!==text||row.dataset.field!==field)return;var list=row.querySelector('datalist');list.replaceChildren();items.forEach(function(item){var option=document.createElement('option');option.value=item.value;option.label=item.label;list.appendChild(option);});}catch(error){}
    },250));
}
conditions.addEventListener('input',suggest);conditions.addEventListener('focusin',suggest);
add.addEventListener('click',function(){if(conditions.children.length<10)conditions.appendChild(document.getElementById('settlement-condition-template').content.cloneNode(true));updateConditions();});conditions.addEventListener('change',updateConditions);conditions.addEventListener('click',function(event){if(event.target.matches('[data-remove-condition]')){event.target.closest('[data-settlement-condition]').remove();updateConditions();}});updateConditions();
})();
</script>
@endpush
