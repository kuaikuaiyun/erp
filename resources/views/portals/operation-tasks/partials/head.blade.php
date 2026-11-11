@push('styles')
    <link rel="stylesheet" href="{{ request()->getSchemeAndHttpHost() }}/assets/erp/operation-tasks.css?v=20261111-9">
    <style>
        .ot-simple-page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:0 0 14px}
        .ot-simple-page-head h1{display:inline;margin:0;color:#173f58;font-size:25px;font-weight:700;line-height:36px}
        .ot-simple-page-head p{display:inline;margin:0 0 0 10px;color:#758996;font-size:14px}
        .ot-simple-page-head .btn{flex:0 0 auto;min-width:108px}
        .ot-tests-page{max-width:none!important;margin:0!important}
        .ot-recap-page{max-width:1540px;margin:0 auto}.ot-recap-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.ot-recap-kpi{min-height:116px;padding:18px 20px;border:1px solid #e1eaed;border-radius:12px;background:#fff;box-shadow:0 5px 18px rgba(34,61,76,.04)}.ot-recap-kpi span,.ot-recap-kpi small{display:block;color:#718692;font-size:12px}.ot-recap-kpi strong{display:block;margin:9px 0 5px;color:#173f58;font-size:28px;line-height:1.1}.ot-recap-guide .ot-guide-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px}.ot-guide-question{min-height:82px}.ot-guide-question b{display:block;color:#193f55;line-height:1.55}.ot-guide-question em{display:inline-grid;width:25px;height:25px;margin-right:8px;place-items:center;border-radius:7px;background:#e8f6f1;color:#218873;font-style:normal;font-size:11px}.ot-guide-question p{margin:9px 0 0;color:#718692;line-height:1.6}.ot-recap-table th{text-align:left;white-space:nowrap}.ot-recap-table td{vertical-align:middle}.ot-recap-table td small{display:block;margin-top:4px;color:#718692;font-size:11px}.ot-recap-empty{text-align:center!important;padding:55px!important;color:#81929a}.ot-recap-rejection{display:block;margin-top:5px;color:#b84c52;white-space:pre-wrap}.ot-recap-page .box-footer{padding:14px 18px}@media(max-width:900px){.ot-recap-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.ot-recap-guide .ot-guide-grid{grid-template-columns:1fr}}@media(max-width:600px){.ot-recap-kpis{grid-template-columns:1fr}}
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
