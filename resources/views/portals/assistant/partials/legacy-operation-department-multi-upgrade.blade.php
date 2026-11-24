@once
@push('scripts')
<script>
(function () {
    var selected = @json(collect($filters['operation_department_ids'] ?? [])->map(fn ($id) => (string) $id)->values());
    document.querySelectorAll('select[name="operation_department_id"]').forEach(function (select) {
        select.name = 'operation_department_ids[]';
        select.multiple = true;
        select.dataset.filterMultiselect = '';
        select.dataset.placeholder = '全部部门';
        select.dataset.searchPlaceholder = '输入部门名称搜索';
        select.dataset.selectAllText = '全选';
        select.dataset.clearText = '清空选择';
        Array.prototype.slice.call(select.options).forEach(function (option) {
            if (option.value === '') {
                option.remove();
                return;
            }
            option.selected = selected.indexOf(String(option.value)) !== -1;
        });
    });
})();
</script>
@endpush
@endonce
