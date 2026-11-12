@php
    $departmentInputName = $inputName ?? 'department_ids';
    $departmentSelectedIds = collect($selectedIds ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
    $departmentOptions = collect($departments ?? []);
    $departmentRootIds = $departmentOptions->pluck('id')->map(fn ($id) => (int) $id);
    $departmentRoots = $departmentOptions->filter(fn ($department) => ! $departmentRootIds->contains((int) $department->parent_id));
    $departmentRootsWithChildren = $departmentRoots->filter(
        fn ($root) => $departmentOptions->contains(fn ($department) => (int) $department->parent_id === (int) $root->id)
    );
    $departmentFirstExpandableRootId = (int) ($departmentRootsWithChildren->first()?->id ?? 0);
@endphp
<div class="form-group assistant-department-filter">
    <label>{{ $label ?? '运营部门' }}</label>
    <div class="dropdown assistant-department-dropdown">
        <button class="assistant-department-toggle dropdown-toggle" type="button" data-toggle="dropdown">
            <span data-selected-label>{{ count($departmentSelectedIds) ? '已选择 '.count($departmentSelectedIds).' 个部门' : '全部部门' }}</span>
            <span class="caret"></span>
        </button>
        <div class="dropdown-menu assistant-department-menu @if($departmentRootsWithChildren->isEmpty()) is-flat-menu @endif">
            <div class="assistant-department-columns @if($departmentRootsWithChildren->isEmpty()) is-flat @endif">
                <div class="assistant-department-roots">
                    @foreach($departmentRoots as $root)
                        @php($hasDepartmentChildren = $departmentOptions->contains(fn ($department) => (int) $department->parent_id === (int) $root->id))
                        @if($hasDepartmentChildren)
                            <button type="button" class="assistant-department-root @if((int)$root->id===$departmentFirstExpandableRootId) active @endif" data-root="{{ $root->id }}">
                                <span>{{ $root->name }}</span><i class="fa fa-angle-right"></i>
                            </button>
                        @else
                            <label class="assistant-department-leaf">
                                <input type="checkbox" name="{{ $departmentInputName }}[]" value="{{ $root->id }}" @checked(in_array((int)$root->id,$departmentSelectedIds,true))>
                                <span>{{ $root->name }}</span>
                            </label>
                        @endif
                    @endforeach
                </div>
                <div class="assistant-department-children">
                    @foreach($departmentRootsWithChildren as $root)
                        <div class="assistant-department-panel @if((int)$root->id===$departmentFirstExpandableRootId) active @endif" data-panel="{{ $root->id }}">
                            <label><input type="checkbox" name="{{ $departmentInputName }}[]" value="{{ $root->id }}" @checked(in_array((int)$root->id,$departmentSelectedIds,true))> <b>{{ $root->name }}</b></label>
                            @foreach($departmentOptions->where('parent_id',$root->id) as $department)
                                <label><input type="checkbox" name="{{ $departmentInputName }}[]" value="{{ $department->id }}" @checked(in_array((int)$department->id,$departmentSelectedIds,true))> {{ $department->name }}</label>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="assistant-department-actions">
                <button type="button" class="assistant-department-select-all"><i class="fa fa-check-square-o"></i> 全选部门</button>
                <button type="button" class="assistant-department-clear"><i class="fa fa-times-circle"></i> 清空选择</button>
            </div>
        </div>
    </div>
</div>
@once
@push('styles')
<style>
.assistant-department-actions{display:grid;grid-template-columns:1fr 1fr;border-top:1px solid #d7e7f0}.assistant-department-actions button{padding:9px 12px;border:0;background:#fff;color:#1684bb;text-align:left}.assistant-department-actions button+button{border-left:1px solid #d7e7f0}.assistant-department-actions button:hover{background:#edf7fc}
.assistant-department-menu.is-flat-menu{width:300px}
.assistant-department-filter,.assistant-department-dropdown{position:relative;z-index:30}.assistant-department-menu{z-index:2050!important}
.assistant-department-filter{min-width:250px}.assistant-department-dropdown{width:100%}.assistant-department-toggle{display:flex;align-items:center;justify-content:space-between;width:100%;height:34px;padding:6px 10px;border:1px solid #d2d6de;border-radius:4px;background:#fff;color:#555;text-align:left}.assistant-department-menu{width:430px;padding:0;border:1px solid #79b9dc;border-radius:5px;box-shadow:0 8px 24px rgba(28,78,108,.18)}.assistant-department-columns{display:grid;grid-template-columns:190px 1fr;min-height:230px}.assistant-department-columns.is-flat{display:block;min-height:0}.assistant-department-columns.is-flat .assistant-department-roots{max-height:310px;border-right:0;overflow-y:auto}.assistant-department-columns.is-flat .assistant-department-children{display:none}.assistant-department-menu:has(.assistant-department-columns.is-flat){width:300px}.assistant-department-roots{padding:7px;background:#f1f8fc;border-right:1px solid #d7e7f0}.assistant-department-root,.assistant-department-leaf{display:flex;align-items:center;width:100%;min-height:36px;margin:0;padding:8px;border:0;border-radius:4px;background:transparent;color:#315e77;text-align:left;font-weight:400}.assistant-department-root{justify-content:space-between}.assistant-department-root:hover,.assistant-department-leaf:hover{background:#fff}.assistant-department-root.active{background:#fff;color:#1785bd;font-weight:700}.assistant-department-leaf{gap:7px;cursor:pointer}.assistant-department-leaf input{margin:0}.assistant-department-leaf:has(input:checked){background:#e0f2fb;color:#1479a8;font-weight:700}.assistant-department-panel{display:none;padding:10px;max-height:280px;overflow-y:auto}.assistant-department-panel.active{display:block}.assistant-department-panel label{display:block;padding:6px 3px;font-weight:400;white-space:normal}.assistant-department-clear{width:100%;padding:9px 12px;border:0;border-top:1px solid #d7e7f0;background:#fff;color:#1684bb;text-align:left}.assistant-department-filter.has-selection .assistant-department-toggle{border-color:#4aa3d2;color:#176f9d;font-weight:600}@media(max-width:767px){.assistant-department-menu{width:min(430px,90vw)}.assistant-department-columns{grid-template-columns:150px 1fr}}
.assistant-department-filter,.assistant-department-dropdown{position:relative;z-index:30}.assistant-department-menu{z-index:2050!important}
</style>
@endpush
@push('scripts')
<script>(function(){document.querySelectorAll('.assistant-department-select-all').forEach(function(button){if(button.dataset.ready)return;button.dataset.ready='1';button.addEventListener('click',function(event){event.preventDefault();event.stopPropagation();var dropdown=button.closest('.assistant-department-dropdown');if(!dropdown)return;dropdown.querySelectorAll('input[type="checkbox"]').forEach(function(input){input.checked=true;input.dispatchEvent(new Event('change',{bubbles:true}));});});});})();</script>
<script>(function(){document.querySelectorAll('.assistant-department-dropdown').forEach(function(dropdown){if(dropdown.dataset.ready)return;dropdown.dataset.ready='1';var toggle=dropdown.querySelector('.assistant-department-toggle'),label=dropdown.querySelector('[data-selected-label]');function refresh(){var count=dropdown.querySelectorAll('input[type="checkbox"]:checked').length;label.textContent=count?'已选择 '+count+' 个部门':'全部部门';dropdown.closest('.assistant-department-filter').classList.toggle('has-selection',count>0);}dropdown.querySelectorAll('[data-root]').forEach(function(button){button.addEventListener('click',function(event){event.preventDefault();event.stopPropagation();dropdown.querySelectorAll('[data-root], [data-panel]').forEach(function(item){item.classList.remove('active')});button.classList.add('active');dropdown.querySelector('[data-panel="'+button.dataset.root+'"]').classList.add('active');});});dropdown.querySelectorAll('input[type="checkbox"]').forEach(function(input){input.addEventListener('change',refresh)});dropdown.querySelector('.assistant-department-clear').addEventListener('click',function(event){event.preventDefault();event.stopPropagation();dropdown.querySelectorAll('input[type="checkbox"]').forEach(function(input){input.checked=false});refresh();});dropdown.querySelector('.dropdown-menu').addEventListener('click',function(event){event.stopPropagation()});refresh();});})();</script>
@endpush
@endonce
