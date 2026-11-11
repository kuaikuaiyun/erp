@push('styles')
    <link rel="stylesheet" href="{{ request()->getSchemeAndHttpHost() }}/assets/erp/operation-tasks.css?v=20261111-5">
    <style>
        .ot-simple-page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:0 0 14px}
        .ot-simple-page-head h1{display:inline;margin:0;color:#173f58;font-size:25px;font-weight:700;line-height:36px}
        .ot-simple-page-head p{display:inline;margin:0 0 0 10px;color:#758996;font-size:14px}
        .ot-simple-page-head .btn{flex:0 0 auto;min-width:108px}
        .ot-tests-page{max-width:none!important;margin:0!important}
        @media(max-width:650px){.ot-simple-page-head{align-items:flex-start;flex-direction:column}.ot-simple-page-head p{display:block;margin:2px 0 0}}
    </style>
@endpush

@php
    $operationTaskNotification = null;

    if (session('success')) {
        $operationTaskNotification = [
            'type' => 'success',
            'title' => '操作成功',
            'message' => (string) session('success'),
        ];
    } elseif (session('error')) {
        $operationTaskNotification = [
            'type' => 'error',
            'title' => '操作失败',
            'message' => (string) session('error'),
        ];
    } elseif ($errors->any()) {
        $operationTaskNotification = [
            'type' => 'error',
            'title' => '操作未完成',
            'message' => (string) $errors->first(),
        ];
    }
@endphp

@if ($operationTaskNotification !== null)
    @push('scripts')
        <script>
            (function (notification) {
                if (window.ErpAdminNotifier) {
                    window.ErpAdminNotifier.notify(notification);
                }
            })({!! json_encode($operationTaskNotification, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!});
        </script>
    @endpush
@endif
