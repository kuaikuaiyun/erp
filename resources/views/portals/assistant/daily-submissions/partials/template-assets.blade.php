@once
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/operation-daily-submission-template.css') }}?v=20261124-3">
    @endpush
    @push('scripts')
        <script>
            document.querySelectorAll('.daily-submission-template-page select.form-control:not(.js-store-binding):not([name="operation_department_id"]):not([name="operation_department_ids[]"]):not([multiple])').forEach(function (select) {
                select.setAttribute('data-filter-multiselect', '');
                var selectedOption = select.options[select.selectedIndex];
                select.dataset.placeholder = selectedOption ? selectedOption.text : '请选择';
                select.dataset.searchPlaceholder = '输入关键词搜索';
            });
        </script>
        <script src="{{ asset('js/assistant-filter-multiselect.js') }}?v=20261124-2"></script>
    @endpush
@endonce
