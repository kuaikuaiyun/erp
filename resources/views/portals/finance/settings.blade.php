@extends('portals.finance.layout', ['title' => __('finance.menus.'.$kind)])
@push('styles')
<style>
.finance-settings .box-body{padding:20px}.finance-settings-toolbar{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}.finance-settings-toolbar form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.finance-settings-toolbar label{margin:0 4px 0 0;font-weight:400}.finance-settings-toolbar input{width:260px}.finance-settings-toolbar .finance-tools{margin:0;align-items:center}.finance-settings .finance-table{table-layout:fixed}.finance-settings .finance-table th,.finance-settings .finance-table td{padding:12px 16px;vertical-align:middle}.finance-settings .finance-table th:last-child{width:140px}.finance-settings-count{margin:0 0 12px;color:#718398;font-size:12px}.finance-settings-dialog{width:520px;max-width:calc(100vw - 32px);max-height:85vh;overflow:auto;border:0;border-radius:8px;padding:0;color:#3b5870;box-shadow:0 16px 60px #173f6140}.finance-settings-dialog::backdrop{background:#102c4866}.finance-settings-dialog header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5edf3}.finance-settings-dialog h3{margin:0;font-size:17px}.finance-settings-dialog header button{border:0;background:none;font-size:24px;color:#8393a1}.finance-settings-dialog .box{margin:0;border:0;box-shadow:none}.finance-settings-dialog .box-header{display:none}.finance-settings-dialog .box-body{padding:20px}.finance-settings-dialog .finance-filter{display:block}.finance-settings-dialog .form-group{margin-bottom:16px}.finance-settings-dialog .form-control{width:100%}.finance-settings-dialog .finance-filter .form-group:last-child{margin-bottom:0;text-align:right}.finance-settings-dialog [hidden]{display:none!important}@media(max-width:650px){.finance-settings .box-body{padding:14px}.finance-settings-toolbar input{width:180px}.finance-settings .finance-table th,.finance-settings .finance-table td{padding:10px}}
</style>
<style>
#finance-import-dialog{width:600px;border-radius:0}#finance-import-dialog header{padding:16px;border-bottom:1px solid #eee;background:#fff}#finance-import-dialog h3{font-size:17px;font-weight:400;color:#444}.finance-import-body{padding:16px}.finance-import-drop{position:relative;display:flex;min-height:176px;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:24px;border:1px dashed #3498db;border-radius:5px;background:#f2faff;text-align:center;cursor:pointer;color:#3b5870}.finance-import-drop:hover,.finance-import-drop.is-dragging,.finance-import-drop:focus-within{background:#e7f5ff;border-color:#1689d1;outline:2px solid #b6def6;outline-offset:2px}.finance-import-drop .fa{font-size:30px;color:#2885b1}.finance-import-drop input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}.finance-import-drop small{color:#2585bd}.finance-import-template{display:inline-block;margin-top:16px;color:#288dcc}.finance-import-footer{display:flex;justify-content:flex-end;gap:8px;padding:14px 16px;border-top:1px solid #eee;background:#fff}.finance-import-footer .btn-success{background:#00a65a;border-color:#009d55}.finance-import-selected{margin:12px 0 0;color:#52748d;font-size:13px;overflow-wrap:anywhere}
</style>
@if($kind === 'domains')<style>.finance-settings .finance-table th:last-child{width:auto}</style>@endif
@endpush
@section('finance_content')
<div class="finance-settings">
<div class="box box-primary"><div class="box-body">
    <div class="finance-settings-toolbar"><form method="get"><input type="hidden" name="per_page" value="{{ $rows->perPage() }}"><label for="finance-search">搜索</label><input id="finance-search" class="form-control" name="q" value="{{ request('q') }}" placeholder="{{ $kind === 'types' ? '输入类型或分类' : '输入域名、使用人或团队' }}" maxlength="191">@if($kind === 'types')<label for="settings-category">交易分类</label><select id="settings-category" name="category" class="form-control" style="width:170px"><option value="">全部分类</option><option value="__unclassified" @selected(request('category') === '__unclassified')>待分类</option>@foreach($categoryOptions as $category)<option value="{{ $category }}" @selected(request('category') === $category)>{{ \App\Portals\Finance\FinanceCatalog::simplified($category) }}</option>@endforeach</select>@endif<button class="btn btn-primary">查询</button><a class="btn btn-default" href="{{ route('finance.settings', $kind) }}">重置</a></form>
    @if($kind === 'types')<div class="finance-tools">@if($canManage)<button type="button" class="btn btn-primary" data-new-setting>新增</button><button type="button" class="btn btn-default" data-open-import>导入</button>@endif<a class="btn btn-default" data-finance-select-export href="{{ route('finance.settings.export', ['kind' => $kind, ...request()->only('q', 'category')]) }}">导出</a><a class="btn btn-default" href="{{ route('finance.settings.export', ['kind' => $kind, 'template' => 1]) }}">下载模板</a></div>@endif</div>
    @if($kind === 'domains')<p class="help-block">数据同步自“域名与店铺信息管理”，此处仅供查看。</p>@endif
    <p class="finance-settings-count">共 {{ $rows->total() }} 条</p>
    <div class="finance-table-wrap"><table class="table table-hover finance-table"><thead><tr>@if($kind === 'types')<th class="finance-selection"><input type="checkbox" data-export-all aria-label="全选当前页"></th>@endif @foreach($fields as $field)<th>{{ __('finance.fields.'.$field) }}</th>@endforeach @if($canManage)<th>{{ __('finance.fields.actions') }}</th>@endif</tr></thead><tbody>
    @forelse($rows as $row)<tr>@if($kind === 'types')<td class="finance-selection"><input type="checkbox" data-export-row value="{{ \App\Portals\Finance\FinanceExportSelection::key(['raw_type' => $row->raw_type]) }}" aria-label="选择此行"></td>@endif @foreach($fields as $field)<td>{{ $kind === 'types' && $field === 'raw_type' ? \App\Portals\Finance\FinancePayPalEventTypes::label((string) $row->$field) : \App\Portals\Finance\FinanceCatalog::simplified($row->$field ?: __('finance.settings.unclassified')) }}</td>@endforeach
        @if($canManage)<td><button type="button" class="btn btn-default btn-xs finance-edit" data-values="{{ json_encode(array_intersect_key((array)$row, array_flip($fields)), JSON_UNESCAPED_UNICODE) }}">{{ __('finance.settings.edit') }}</button>@if($kind === 'types')<form method="post" action="{{ route('finance.settings.types.delete', ['type' => $row->id, ...request()->only('q', 'category', 'page')]) }}" style="display:inline" data-delete-type>@csrf @method('DELETE')<button class="btn btn-default btn-xs" style="color:#c94a4a">删除</button></form>@endif</td>@endif
    </tr>@empty<tr><td colspan="{{ count($fields) + ($kind === 'types' ? 1 : 0) + ($canManage ? 1 : 0) }}">{{ __('finance.settings.empty') }}</td></tr>@endforelse
    </tbody></table></div>
    @include('portals.finance.pagination', ['paginator' => $rows])
</div></div>
@if($canManage)
<dialog class="finance-settings-dialog" id="finance-setting-dialog"><header><h3 data-editor-title>新增</h3><button type="button" data-close-dialog aria-label="关闭">&times;</button></header>
<div class="box"><div class="box-header with-border"><h3 class="box-title">{{ __('finance.settings.save_title') }}</h3></div><div class="box-body">
    <form id="finance-setting-form" method="post" action="{{ route('finance.settings.save', $kind) }}" class="finance-filter">@csrf
        @foreach($fields as $field)<div class="form-group"><label for="setting-{{ $field }}">{{ __('finance.fields.'.$field) }}</label><input id="setting-{{ $field }}" class="form-control" name="{{ $field }}" value="{{ old($field) }}" required @if($kind === 'types' && $field === 'category') list="finance-category-options" placeholder="选择已有分类或输入新分类" @endif maxlength="{{ in_array($field, ['raw_type', 'domain']) ? 191 : 100 }}"></div>@endforeach
        @if($kind === 'types')<datalist id="finance-category-options">@foreach($categoryOptions as $category)<option value="{{ $category }}"></option>@endforeach</datalist><p class="help-block">可选择已有分类，也可输入新分类；同一类型的不同名称将同步更新。</p>@endif
        <div class="form-group"><button class="btn btn-primary">{{ __('finance.save') }}</button> <button type="button" data-close-dialog class="btn btn-default">{{ __('finance.cancel') }}</button></div>
    </form>
</div></div>
</dialog>
<dialog class="finance-settings-dialog" id="finance-import-dialog"><header><h3><i class="fa fa-upload" aria-hidden="true"></i> {{ $kind === 'types' ? '导入类型分类' : '导入域名分组' }}</h3><button type="button" data-close-dialog aria-label="关闭">&times;</button></header>
<form method="post" action="{{ route('finance.settings.import', $kind) }}" enctype="multipart/form-data" id="finance-settings-import-form">@csrf
    <div class="finance-import-body">
        <label class="finance-import-drop" for="settings-file"><i class="fa fa-cloud-upload" aria-hidden="true"></i><strong>拖拽文件到这里，或点击选择文件</strong><small>支持 XLSX、CSV，最大 10MB</small><input id="settings-file" type="file" name="file" accept=".xlsx,.csv" aria-label="选择导入文件" required></label>
        <p class="finance-import-selected" id="finance-import-selected" role="status" hidden></p>
        <a class="finance-import-template" href="{{ route('finance.settings.export', ['kind' => $kind, 'template' => 1]) }}"><i class="fa fa-file-excel-o" aria-hidden="true"></i> 下载导入模板（XLSX）</a>
    </div>
    <div class="finance-import-footer"><button type="button" data-close-dialog class="btn btn-default">取消</button><button class="btn btn-success"><i class="fa fa-upload" aria-hidden="true"></i> 确认导入</button></div>
</form>
</dialog>
@endif
</div>
@endsection
@push('scripts')
@if($canManage)
<script>
document.querySelectorAll('[data-delete-type]').forEach(function(form){form.addEventListener('submit',function(event){if(!confirm('确定删除这条类型分类设置？订单数据将保留。'))event.preventDefault();});});
var editor=document.getElementById('finance-setting-dialog'),importer=document.getElementById('finance-import-dialog');
if(editor){
    var form=document.getElementById('finance-setting-form');
    var raw=form.elements.raw_type,display;
    if(raw){display=document.createElement('input');display.className='form-control';display.readOnly=true;display.hidden=true;raw.after(display);}
    function openEditor(button){
        form.reset();
        if(raw){raw.type='text';display.hidden=true;}
        editor.querySelector('[data-editor-title]').textContent=button?'编辑分类':'新增';
        if(button){Object.entries(JSON.parse(button.dataset.values)).forEach(function(entry){form.elements[entry[0]].value=entry[1]||'';});
            if(raw){display.value=button.closest('tr').cells[0].textContent.trim();raw.type='hidden';display.hidden=false;}
        }
        editor.showModal();
        (button&&form.elements.category?form.elements.category:form.querySelector('input:not([type=hidden])')).focus();
    }
    document.querySelectorAll('.finance-edit').forEach(function(button){button.addEventListener('click',function(){openEditor(button);});});
    document.querySelector('[data-new-setting]').addEventListener('click',function(){openEditor(null);});
    document.querySelector('[data-open-import]').addEventListener('click',function(){importer.showModal();});
    var fileInput=document.getElementById('settings-file'),drop=fileInput.closest('.finance-import-drop');
    fileInput.addEventListener('change',function(){
        var label=document.getElementById('finance-import-selected'),file=this.files[0];
        this.setCustomValidity(file&&(!/\.(xlsx|csv)$/i.test(file.name)||file.size>10*1024*1024)?'请选择不超过10MB的XLSX或CSV文件。':'');
        label.hidden=!file;label.textContent=file?'已选择：'+file.name:'';
        if(file)this.reportValidity();
    });
    ['dragenter','dragover'].forEach(function(name){drop.addEventListener(name,function(event){event.preventDefault();drop.classList.add('is-dragging');});});
    drop.addEventListener('dragleave',function(){drop.classList.remove('is-dragging');});
    drop.addEventListener('drop',function(event){event.preventDefault();drop.classList.remove('is-dragging');if(event.dataTransfer.files.length){var transfer=new DataTransfer();transfer.items.add(event.dataTransfer.files[0]);fileInput.files=transfer.files;fileInput.dispatchEvent(new Event('change'));}});
    document.querySelectorAll('[data-close-dialog]').forEach(function(button){button.addEventListener('click',function(){button.closest('dialog').close();});});
    @if(old('raw_type') || old('domain'))editor.showModal();@endif
}
</script>
@endif
@endpush
