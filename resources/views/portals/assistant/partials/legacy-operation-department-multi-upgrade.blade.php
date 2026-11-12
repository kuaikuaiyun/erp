@once
@include('portals.assistant.partials.daily-submission-pagination-styles')
@push('styles')
<link rel="stylesheet" href="{{ asset('vendor/laravel-admin/AdminLTE/plugins/select2/select2.min.css') }}">
<style>
.operation-department-picker{width:410px;max-width:100%}.operation-department-picker-main{position:relative}.select2-container.operation-department-multi{display:block;width:100%!important;min-width:330px}.select2-container.operation-department-multi .select2-selection--multiple{height:36px;min-height:36px;border:1px solid #cddde8;border-radius:6px;background:#fff;padding:1px 116px 1px 5px;box-shadow:none;overflow:hidden;transition:.18s}.select2-container.operation-department-multi.select2-container--focus .select2-selection--multiple,.select2-container.operation-department-multi.select2-container--open .select2-selection--multiple{border-color:#3c9bd0;box-shadow:0 0 0 2px rgba(60,155,208,.12)}.select2-container.operation-department-multi .select2-selection__rendered{display:flex;align-items:center;min-height:32px;overflow:hidden;white-space:nowrap}.select2-container.operation-department-multi .select2-selection__choice{flex:0 0 auto;margin:3px 4px 2px 0!important;padding:2px 7px!important;border:1px solid #b9dcf1!important;border-radius:11px!important;background:#eaf6fd!important;color:#2479aa!important;font-size:11px}.select2-container.operation-department-multi .select2-selection__choice__remove{margin-right:4px!important;color:#6ba7c8!important}.select2-container.operation-department-multi .select2-search__field{margin-top:5px!important}.operation-department-count{display:none}.operation-department-actions{position:absolute;right:4px;top:4px;z-index:3;display:flex;align-items:center;gap:2px;height:28px;padding-left:4px;border-left:1px solid #dbe7ee;background:#fff}.operation-department-action{width:34px;height:26px;border:0;border-radius:5px;background:#edf6fb;padding:0;color:#347da5;font-size:12px;line-height:26px;text-align:center;transition:.15s}.operation-department-action:hover{background:#dceefa;color:#176b98}.operation-department-action.is-invert{background:#fff5db;color:#8b690d}.operation-department-action.is-clear{margin:0;background:#f3f5f7;color:#71818d}.operation-department-action span{display:none}.select2-container--open{z-index:2050}.select2-results__option[aria-selected=true]{background:#e6f4fc!important;color:#2479aa!important}.select2-results__option--highlighted[aria-selected]{background:#3c96c6!important;color:#fff!important}
</style>
@endpush
@push('scripts')
<script src="{{ asset('vendor/laravel-admin/AdminLTE/plugins/select2/select2.full.min.js') }}"></script>
<script>
(function($){
    $('select[name="operation_department_id"]').each(function(){
        var $select=$(this), selected=@json(collect($filters['operation_department_ids'] ?? [])->map(fn($id)=>(string)$id)->values());
        $select.attr('name','operation_department_ids[]').attr('multiple','multiple');
        $select.find('option[value=""]').remove();
        $select.val(selected);
        var $picker=$('<div class="operation-department-picker"></div>'),$main=$('<div class="operation-department-picker-main"></div>'),$count=$('<span class="operation-department-count"></span>'),$actions=$('<div class="operation-department-actions"><button type="button" class="operation-department-action" data-action="all" title="全选部门"><i class="fa fa-check-square-o"></i><span>全选</span></button><button type="button" class="operation-department-action is-invert" data-action="invert" title="反选部门"><i class="fa fa-exchange"></i><span>反选</span></button><button type="button" class="operation-department-action is-clear" data-action="clear" title="清空选择"><i class="fa fa-times"></i><span>清空</span></button></div>');
        $select.wrap($picker);$select.wrap($main);$select.after($count);$select.closest('.operation-department-picker-main').append($actions);
        function refreshCount(){var total=$select.find('option').length,current=($select.val()||[]).length;$count.text(current?('已选 '+current+'/'+total):('共 '+total+' 个'));}
        if($.fn.select2){$select.select2({width:'100%',placeholder:'请选择一个或多个部门',closeOnSelect:false,allowClear:false,containerCssClass:'operation-department-multi'});}
        $select.on('change',refreshCount);
        $actions.on('click','[data-action]',function(){var action=$(this).data('action'),all=$select.find('option').map(function(){return this.value;}).get(),current=$select.val()||[];if(action==='all'){$select.val(all);}else if(action==='invert'){$select.val(all.filter(function(value){return current.indexOf(value)===-1;}));}else{$select.val([]);}$select.trigger('change');});
        refreshCount();
    });
})(jQuery);
</script>
@endpush
@endonce
