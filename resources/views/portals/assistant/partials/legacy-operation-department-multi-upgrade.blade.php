@once
@push('styles')
<link rel="stylesheet" href="{{ asset('vendor/laravel-admin/AdminLTE/plugins/select2/select2.min.css') }}">
<style>
.select2-container.operation-department-multi{min-width:250px}.select2-container.operation-department-multi .select2-selection--multiple{min-height:34px;border-color:#d2d6de}.select2-container.operation-department-multi .select2-selection__choice{background:#2d98c8!important;border-color:#2385b1!important;color:#fff!important}.select2-container.operation-department-multi .select2-selection__choice__remove{color:#fff!important}.select2-container--open{z-index:2050}
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
        if($.fn.select2){$select.select2({width:'250px',placeholder:'选择一个或多个部门',closeOnSelect:false,allowClear:true,containerCssClass:'operation-department-multi'});}
    });
})(jQuery);
</script>
@endpush
@endonce
