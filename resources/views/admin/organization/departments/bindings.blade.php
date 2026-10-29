<style>
.binding-shell{border:0;border-radius:10px;box-shadow:0 5px 22px rgba(36,70,96,.08);overflow:hidden}.binding-shell>.box-header{padding:17px 20px;border-bottom:1px solid #e7eef4;background:linear-gradient(135deg,#fff,#f7fbfe)}.binding-shell>.box-header .box-title{font-size:18px;font-weight:700;color:#244861}.binding-shell>.box-body{padding:20px;background:#f4f7fa}.binding-intro{display:flex;align-items:flex-start;gap:12px;margin-bottom:20px;padding:14px 17px;border:1px solid #d9eaf5;border-radius:8px;background:#edf7fd;color:#41677f;line-height:1.7}.binding-intro-icon{display:flex;flex:0 0 32px;height:32px;align-items:center;justify-content:center;border-radius:50%;background:#3c9bcc;color:#fff;font-size:15px}.binding-list{display:grid;gap:16px}.binding-department{border:1px solid #dfe8ef;border-radius:9px;background:#fff;box-shadow:0 2px 8px rgba(38,72,96,.05);overflow:hidden}.binding-department-head{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:14px 17px;border-bottom:1px solid #e7edf2;background:#fbfdff}.binding-department-title{display:flex;align-items:center;gap:10px;color:#1f4965;font-size:15px;font-weight:700}.binding-department-icon{display:flex;width:34px;height:34px;align-items:center;justify-content:center;border-radius:8px;background:#e5f5fc;color:#2194c8}.binding-count{display:inline-block;margin-left:4px;padding:3px 9px;border-radius:12px;background:#eaf1f6;color:#60788a;font-size:12px;font-weight:400}.binding-row-tools .btn{margin-left:5px;border-radius:5px}.binding-options{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px;padding:16px}.binding-option{position:relative;display:flex;align-items:center;min-height:48px;margin:0;padding:9px 12px;border:1px solid #dfe7ed;border-radius:7px;background:#fff;color:#425b6d;font-weight:400;cursor:pointer;transition:.15s}.binding-option:hover{border-color:#68afd2;background:#f5fbfe;transform:translateY(-1px)}.binding-option input{position:absolute;opacity:0;pointer-events:none}.binding-option-mark{display:flex;flex:0 0 22px;height:22px;margin-right:9px;align-items:center;justify-content:center;border:1px solid #bdccd7;border-radius:5px;background:#fff;color:transparent;transition:.15s}.binding-option input:checked+.binding-option-mark{border-color:#3196c8;background:#3196c8;color:#fff}.binding-option:has(input:checked){border-color:#76badd;background:#edf8fd;color:#225f7e;box-shadow:0 0 0 1px rgba(49,150,200,.08)}.binding-option-name{display:flex;min-width:0;align-items:center;gap:7px}.binding-level{display:inline-flex;flex:0 0 auto;padding:2px 6px;border-radius:9px;background:#edf1f4;color:#7c8d99;font-size:10px}.binding-option:has(input:checked) .binding-level{background:#d8edf7;color:#317b9f}.binding-empty{padding:38px;text-align:center;border:1px dashed #ced9e1;border-radius:8px;background:#fff;color:#8495a3}.binding-actions{position:sticky;bottom:0;z-index:2;display:flex;align-items:center;justify-content:space-between;margin:20px -20px -20px;padding:14px 20px;border-top:1px solid #dfe7ed;background:rgba(255,255,255,.96);box-shadow:0 -3px 12px rgba(40,70,92,.06)}.binding-actions .btn{border-radius:5px}.binding-actions-global .btn{margin-right:6px}@media(max-width:767px){.binding-shell>.box-body{padding:12px}.binding-department-head,.binding-actions{align-items:flex-start;flex-direction:column}.binding-options{grid-template-columns:1fr;padding:12px}.binding-actions{margin:16px -12px -12px}.binding-actions>div:last-child{display:flex;width:100%;justify-content:flex-end}.binding-row-tools{white-space:nowrap}}
</style>
<div class="box binding-shell">
    <div class="box-header"><h3 class="box-title"><i class="fa fa-link text-aqua"></i> 运营部门与助理部门绑定</h3></div>
    <div class="box-body">
        <div class="binding-intro">
            <span class="binding-intro-icon"><i class="fa fa-info"></i></span>
            <div><strong>绑定规则</strong><br>每个助理部门可以绑定一个或多个运营部门。绑定后，助理系统选择店铺运营负责人时，只显示对应运营部门及其下级部门的在职人员。</div>
        </div>

        <form method="post" action="{{ admin_url('organization/departments/bindings') }}" pjax-container>
            @csrf @method('put')
            @if($errors->any())<div class="alert alert-danger"><i class="fa fa-warning"></i> {{ $errors->first() }}</div>@endif

            <div class="binding-list">
                @forelse($assistantDepartments as $assistantDepartment)
                    @php($selectedIds = collect(old('bindings.'.$assistantDepartment->id, $scopeMap[$assistantDepartment->id] ?? []))->map(fn($id)=>(int)$id))
                    <section class="binding-department" data-binding-group>
                        <header class="binding-department-head">
                            <div class="binding-department-title">
                                <span class="binding-department-icon"><i class="fa fa-users"></i></span>
                                <span>{{ $assistantDepartment->name }} <small class="binding-count">已选 <b data-binding-count>{{ $selectedIds->count() }}</b> 个运营部门</small></span>
                            </div>
                            <div class="binding-row-tools">
                                <button type="button" class="btn btn-default btn-xs" data-group-select><i class="fa fa-check-square-o"></i> 本组全选</button>
                                <button type="button" class="btn btn-default btn-xs" data-group-clear><i class="fa fa-square-o"></i> 本组清空</button>
                            </div>
                        </header>
                        <div class="binding-options">
                            @forelse($operationDepartments as $operationDepartment)
                                <label class="binding-option">
                                    <input type="checkbox" name="bindings[{{ $assistantDepartment->id }}][]" value="{{ $operationDepartment->id }}" @checked($selectedIds->contains((int)$operationDepartment->id))>
                                    <span class="binding-option-mark"><i class="fa fa-check"></i></span>
                                    <span class="binding-option-name"><span class="binding-level">{{ $operationDepartment->parent_id ? '二级' : '一级' }}</span>{{ $operationDepartment->name }}</span>
                                </label>
                            @empty
                                <div class="binding-empty">未找到运营部门，请先在部门管理中创建运营部门。</div>
                            @endforelse
                        </div>
                    </section>
                @empty
                    <div class="binding-empty"><i class="fa fa-users fa-2x"></i><p>未找到助理二级部门，请先在部门管理中创建助理部门。</p></div>
                @endforelse
            </div>

            <div class="binding-actions">
                <div class="binding-actions-global">
                    <button type="button" class="btn btn-default btn-sm" data-binding-select-all><i class="fa fa-check-square-o"></i> 全部选择</button>
                    <button type="button" class="btn btn-default btn-sm" data-binding-clear><i class="fa fa-square-o"></i> 全部清空</button>
                </div>
                <div><a class="btn btn-default" href="{{ admin_url('organization/departments') }}"><i class="fa fa-arrow-left"></i> 返回部门管理</a> <button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> 保存绑定关系</button></div>
            </div>
        </form>
    </div>
</div>
<script>
(function(){
    var root=document.querySelector('.binding-shell');if(!root)return;
    function boxes(scope){return scope.querySelectorAll('input[type="checkbox"]')}
    function refresh(group){var count=0;boxes(group).forEach(function(box){if(box.checked)count++});var target=group.querySelector('[data-binding-count]');if(target)target.textContent=count}
    function set(scope,checked){boxes(scope).forEach(function(box){box.checked=checked});scope.querySelectorAll('[data-binding-group]').forEach(refresh);if(scope.matches&&scope.matches('[data-binding-group]'))refresh(scope)}
    root.addEventListener('change',function(e){if(e.target.matches('input[type="checkbox"]'))refresh(e.target.closest('[data-binding-group]'))});
    root.addEventListener('click',function(e){var group=e.target.closest('[data-binding-group]');if(e.target.closest('[data-group-select]'))set(group,true);if(e.target.closest('[data-group-clear]'))set(group,false);if(e.target.closest('[data-binding-select-all]'))set(root,true);if(e.target.closest('[data-binding-clear]'))set(root,false)});
})();
</script>
