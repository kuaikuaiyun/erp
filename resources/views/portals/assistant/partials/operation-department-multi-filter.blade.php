@php
    $departmentInputName = $inputName ?? 'department_ids';
    $departmentSelectedIds = collect($selectedIds ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
    $departmentOptions = collect($departments ?? []);
@endphp
<div class="form-group assistant-department-filter">
    <label>{{ $label ?? '运营部门' }}</label>
    <details class="flt-picker assistant-department-picker @if(count($departmentSelectedIds)) has-selection @endif">
        <summary><span data-selected-label>{{ count($departmentSelectedIds) ? '已选择 '.count($departmentSelectedIds).' 个部门' : '全部部门' }}</span></summary>
        <div class="flt-picker-panel">
            <input type="search" class="form-control assistant-department-search" placeholder="输入关键词搜索选项" autocomplete="off">
            <div class="flt-picker-options">
                @foreach($departmentOptions as $department)
                    <label data-department-option data-search-text="{{ mb_strtolower((string) $department->name) }}">
                        <input type="checkbox" name="{{ $departmentInputName }}[]" value="{{ $department->id }}" @checked(in_array((int)$department->id,$departmentSelectedIds,true))>
                        <span>{{ $department->name }}</span>
                    </label>
                @endforeach
                <p class="assistant-department-empty" hidden>没有匹配的部门</p>
            </div>
            <div class="flt-picker-actions">
                <button type="button" class="flt-picker-select-all assistant-department-select-all">全选</button>
                <button type="button" class="flt-picker-clear assistant-department-clear">清空选择</button>
            </div>
        </div>
    </details>
</div>
@once
@push('styles')
<style>
.assistant-department-filter{position:relative;z-index:30;min-width:250px}.assistant-department-filter .flt-picker{position:relative;width:100%}.assistant-department-filter .flt-picker summary{position:relative;display:block;min-height:36px;padding:8px 32px 8px 11px;overflow:hidden;border:1px solid #ccdae6;border-radius:5px;background:#fff;color:#24455b;cursor:pointer;list-style:none;text-overflow:ellipsis;white-space:nowrap}.assistant-department-filter .flt-picker summary::-webkit-details-marker{display:none}.assistant-department-filter .flt-picker summary:after{content:'';position:absolute;right:12px;top:50%;width:6px;height:6px;border-right:1px solid #7b95a6;border-bottom:1px solid #7b95a6;transform:translateY(-65%) rotate(45deg);pointer-events:none}.assistant-department-filter .flt-picker[open]>summary{border-color:#339dd3;box-shadow:0 0 0 3px rgba(36,153,209,.1)}.assistant-department-filter .flt-picker[open]>summary:after{transform:translateY(-30%) rotate(225deg)}.assistant-department-filter .flt-picker.has-selection summary{color:#1779b1}.assistant-department-filter .flt-picker-panel{position:absolute;z-index:2050;top:calc(100% + 6px);right:0;left:0;min-width:250px;padding:10px;border:1px solid #d5e3ed;border-radius:7px;background:#fff;box-shadow:0 8px 30px rgba(40,77,104,.16)}.assistant-department-filter .assistant-department-search{width:100%;height:34px!important;min-height:34px!important;padding:7px 10px!important;border:1px solid #ccdae6!important;border-radius:5px!important}.assistant-department-filter .flt-picker-options{max-height:230px;margin-top:5px;overflow:auto}.assistant-department-filter .flt-picker-options label{display:flex;align-items:center;gap:10px;margin:0;padding:9px;border-radius:4px;color:#36576c;font-weight:400;cursor:pointer}.assistant-department-filter .flt-picker-options label:hover{background:#eff7fc}.assistant-department-filter .flt-picker-options label:has(input:checked){background:#eaf5fd;color:#1779b1}.assistant-department-filter .flt-picker-options input{width:15px;height:15px;margin:0;accent-color:#1884bf;flex:0 0 auto}.assistant-department-filter .flt-picker-options p{margin:0;padding:12px;color:#7c90a0;text-align:center}.assistant-department-filter .flt-picker-actions{display:flex;align-items:center;gap:4px;margin-top:5px;padding-top:4px;border-top:1px solid #e4edf3}.assistant-department-filter .flt-picker-clear,.assistant-department-filter .flt-picker-select-all{flex:0 0 auto;width:auto;padding:8px;border:0;background:none;color:#2188be;font-size:12px;text-align:left;cursor:pointer}.assistant-department-filter .flt-picker-clear:hover,.assistant-department-filter .flt-picker-select-all:hover{border-radius:4px;background:#eff7fc}@media(max-width:767px){.assistant-department-filter .flt-picker-panel{min-width:min(300px,90vw)}}
</style>
@endpush
@push('scripts')
<script>
(function(){
    function initialize(picker){
        if(picker.dataset.ready)return;
        picker.dataset.ready='1';
        var summary=picker.querySelector('[data-selected-label]');
        var search=picker.querySelector('.assistant-department-search');
        var options=Array.prototype.slice.call(picker.querySelectorAll('[data-department-option]'));
        var inputs=Array.prototype.slice.call(picker.querySelectorAll('input[type="checkbox"]'));
        var empty=picker.querySelector('.assistant-department-empty');
        function refresh(){
            var count=inputs.filter(function(input){return input.checked;}).length;
            summary.textContent=count?'已选择 '+count+' 个部门':'全部部门';
            picker.classList.toggle('has-selection',count>0);
        }
        function filter(){
            var keyword=(search.value||'').trim().toLocaleLowerCase();
            var visible=0;
            options.forEach(function(option){
                var matched=!keyword||(option.dataset.searchText||'').indexOf(keyword)!==-1;
                option.hidden=!matched;
                if(matched)visible++;
            });
            empty.hidden=visible>0;
        }
        inputs.forEach(function(input){input.addEventListener('change',refresh);});
        search.addEventListener('input',filter);
        search.addEventListener('keydown',function(event){event.stopPropagation();});
        picker.querySelector('.assistant-department-select-all').addEventListener('click',function(){
            options.forEach(function(option){if(!option.hidden)option.querySelector('input').checked=true;});
            refresh();
        });
        picker.querySelector('.assistant-department-clear').addEventListener('click',function(){
            inputs.forEach(function(input){input.checked=false;});
            search.value='';
            filter();
            refresh();
        });
        picker.addEventListener('toggle',function(){if(picker.open){filter();setTimeout(function(){search.focus();},0);}});
        refresh();
    }
    document.querySelectorAll('.assistant-department-picker').forEach(initialize);
    document.addEventListener('click',function(event){
        document.querySelectorAll('.assistant-department-picker[open]').forEach(function(picker){if(!picker.contains(event.target))picker.open=false;});
    });
    document.addEventListener('keydown',function(event){
        if(event.key!=='Escape')return;
        document.querySelectorAll('.assistant-department-picker[open]').forEach(function(picker){picker.open=false;picker.querySelector('summary').focus();});
    });
})();
</script>
@endpush
@endonce
