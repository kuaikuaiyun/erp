@push('styles')
    <link rel="stylesheet" href="{{ request()->getSchemeAndHttpHost() }}/assets/erp/operation-tasks.css?v=20261111-10">
    <style>
        .ot-simple-page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:0 0 14px}
        .ot-simple-page-head h1{display:inline;margin:0;color:#173f58;font-size:25px;font-weight:700;line-height:36px}
        .ot-simple-page-head p{display:inline;margin:0 0 0 10px;color:#758996;font-size:14px}
        .ot-simple-page-head .btn{flex:0 0 auto;min-width:108px}
        .ot-tests-page{max-width:none!important;margin:0!important}
        .ot-test-review-card .ot-recap-review-table-wrap{overflow-x:auto}.ot-test-review-table{width:100%;min-width:1540px!important;table-layout:fixed}.ot-test-review-table th:nth-child(1),.ot-test-review-table td:nth-child(1){width:165px}.ot-test-review-table th:nth-child(2),.ot-test-review-table td:nth-child(2){width:100px}.ot-test-review-table th:nth-child(3),.ot-test-review-table td:nth-child(3){width:155px}.ot-test-review-table th:nth-child(4),.ot-test-review-table td:nth-child(4){width:90px}.ot-test-review-table th:nth-child(5),.ot-test-review-table td:nth-child(5){width:65px}.ot-test-review-table th:nth-child(6),.ot-test-review-table td:nth-child(6){width:125px}.ot-test-review-table th:nth-child(7),.ot-test-review-table td:nth-child(7){width:185px}.ot-test-review-table th:nth-child(8),.ot-test-review-table td:nth-child(8),.ot-test-review-table th:nth-child(9),.ot-test-review-table td:nth-child(9){width:145px}.ot-test-review-table th:nth-child(10),.ot-test-review-table td:nth-child(10){width:175px}.ot-test-review-table th:nth-child(11),.ot-test-review-table td:nth-child(11){width:190px}.ot-test-review-table td{padding:12px!important;vertical-align:middle!important}.ot-test-review-table .test-task-no b,.ot-test-review-table .test-owner b{display:block;color:#173f5a}.ot-test-review-table .test-team{color:#617985}.ot-test-review-table .test-version{text-align:center;white-space:nowrap}.ot-test-review-table .test-asset-info>b,.ot-test-review-table .test-asset-info>small{display:block}.ot-test-review-table .test-asset-info>small{margin-top:3px;color:#718692}.test-asset-daily-status{display:grid;gap:3px;margin-top:7px}.test-asset-daily-status span{display:block;padding:3px 6px;border-radius:4px;font-size:11px;line-height:1.4;white-space:nowrap}.test-asset-daily-status .is-filled{background:#e8f6f1;color:#218873}.test-asset-daily-status .is-empty{background:#fff5e8;color:#b8751a}.ot-test-review-table .ot-current-review-state{word-break:normal}.ot-test-review-table .ot-current-review-state>span,.ot-test-review-table .ot-current-review-state>small{display:block}.ot-test-review-table .recap-actions{white-space:nowrap}.ot-test-review-table .recap-actions .btn{display:inline-flex;align-items:center;justify-content:center;min-width:0;margin:1px;padding:4px 7px;font-size:11px;line-height:18px;vertical-align:middle}.ot-test-review-table .recap-actions .btn i{margin-right:3px}
        .ot-daily-review-table{min-width:1250px!important}.ot-daily-review-table th:nth-child(1),.ot-daily-review-table td:nth-child(1){width:180px}.ot-daily-review-table th:nth-child(2),.ot-daily-review-table td:nth-child(2){width:110px}.ot-daily-review-table th:nth-child(3),.ot-daily-review-table td:nth-child(3){width:190px}.ot-daily-review-table th:nth-child(4),.ot-daily-review-table td:nth-child(4),.ot-daily-review-table th:nth-child(5),.ot-daily-review-table td:nth-child(5){width:105px}.ot-daily-review-table th:nth-child(6),.ot-daily-review-table td:nth-child(6),.ot-daily-review-table th:nth-child(7),.ot-daily-review-table td:nth-child(7){width:80px}.ot-daily-review-table th:nth-child(8),.ot-daily-review-table td:nth-child(8){width:290px}.ot-daily-review-table th:nth-child(9),.ot-daily-review-table td:nth-child(9){width:90px}.ot-daily-review-table td{padding:12px!important;vertical-align:middle!important}.ot-daily-review-table .recap-date,.ot-daily-review-table .recap-revenue,.ot-daily-review-table .recap-spend,.ot-daily-review-table .recap-orders,.ot-daily-review-table .recap-roi,.ot-daily-review-table .recap-actions{text-align:center;white-space:nowrap}.ot-daily-review-table .recap-owner b{color:#173f5a}.ot-daily-review-table .recap-team{color:#617985}
        .ot-test-review-table th,.ot-test-review-table td,.ot-daily-review-table th,.ot-daily-review-table td{text-align:center!important}.ot-test-review-table .recap-stage>.ot-review-flow,.ot-daily-review-table .recap-stage>.ot-review-flow{width:max-content;max-width:100%;margin:0 auto}.ot-test-review-table .recap-actions,.ot-daily-review-table .recap-actions{text-align:center!important}
        .ot-review-flow{min-width:220px;padding:2px 0}.ot-recap-review-table .recap-stage>.ot-review-flow{display:block;align-items:initial;justify-content:initial}.ot-recap-review-table .recap-stage>.ot-review-flow>.ot-review-flow-item{position:relative;display:flex;align-items:flex-start;justify-content:flex-start;gap:10px;margin:0;padding:0 0 15px;border:0}.ot-recap-review-table .recap-stage>.ot-review-flow>.ot-review-flow-item+.ot-review-flow-item{margin:0;padding-top:0;border-top:0}.ot-review-flow-item:not(:last-of-type):before{content:"";position:absolute;left:5px;top:12px;bottom:0;width:1px;background:#d7e3e8}.ot-review-flow-item>i{flex:0 0 11px;width:11px;height:11px;margin-top:4px;border:2px solid #9bb0ba;border-radius:50%;background:#fff}.ot-review-flow-item>div{display:flex!important;align-items:baseline;flex-wrap:wrap;gap:4px 8px;min-width:0;text-align:left}.ot-review-flow-item b,.ot-review-flow-item small{display:inline}.ot-review-flow-item span{display:block}.ot-review-flow-item b{color:#24495d;font-size:12px;line-height:1.45;white-space:normal;word-break:break-word}.ot-review-flow-item small{margin:0;color:#8a9ba4;font-size:10px;white-space:nowrap}.ot-review-flow-item span{flex-basis:100%;margin-top:4px;padding:5px 7px;border-radius:5px;background:#fff3f1;color:#a84b48;font-size:11px;line-height:1.45;white-space:normal}.ot-review-flow-item.is-approved>i{border-color:#27a17f;background:#27a17f}.ot-review-flow-item.is-rejected>i{border-color:#dc6868;background:#dc6868}.ot-review-flow-item.is-submitted>i,.ot-review-flow-item.is-resubmitted>i{border-color:#4a91c4;background:#4a91c4}.ot-review-flow-item.is-current>i{border-color:#e3a547;background:#fff5df}.ot-review-flow-item.is-current b{color:#a66d13}.ot-review-flow-history{margin:-3px 0 2px 20px}.ot-review-flow-history summary{cursor:pointer;color:#2788b8;font-size:11px}.ot-review-flow-history>div{position:absolute;z-index:20;width:360px;max-height:280px;margin-top:6px;padding:10px 12px;overflow:auto;border:1px solid #d9e4e9;border-radius:7px;background:#fff;box-shadow:0 8px 24px rgba(24,54,70,.16)}.ot-review-flow-history p{margin:0;padding:7px 0;border-bottom:1px solid #edf2f4}.ot-review-flow-history p:last-child{border:0}.ot-review-flow-history b,.ot-review-flow-history small,.ot-review-flow-history span{display:block}.ot-review-flow-history b{font-size:12px}.ot-review-flow-history small{color:#899aa3;font-size:10px}.ot-review-flow-history span{margin-top:3px;color:#a84b48;font-size:11px}
        .ot-recap-page{max-width:1540px;margin:0 auto}.ot-recap-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.ot-recap-kpi{min-height:116px;padding:18px 20px;border:1px solid #e1eaed;border-radius:12px;background:#fff;box-shadow:0 5px 18px rgba(34,61,76,.04)}.ot-recap-kpi span,.ot-recap-kpi small{display:block;color:#718692;font-size:12px}.ot-recap-kpi strong{display:block;margin:9px 0 5px;color:#173f58;font-size:28px;line-height:1.1}.ot-recap-guide .ot-guide-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px}.ot-guide-question{min-height:82px}.ot-guide-question b{display:block;color:#193f55;line-height:1.55}.ot-guide-question em{display:inline-grid;width:25px;height:25px;margin-right:8px;place-items:center;border-radius:7px;background:#e8f6f1;color:#218873;font-style:normal;font-size:11px}.ot-guide-question p{margin:9px 0 0;color:#718692;line-height:1.6}.ot-recap-table th{text-align:left;white-space:nowrap}.ot-recap-table td{vertical-align:middle}.ot-recap-table td small{display:block;margin-top:4px;color:#718692;font-size:11px}.ot-recap-empty{text-align:center!important;padding:55px!important;color:#81929a}.ot-recap-rejection{display:block;margin-top:5px;color:#b84c52;white-space:pre-wrap}.ot-recap-page .box-footer{padding:14px 18px}@media(max-width:900px){.ot-recap-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.ot-recap-guide .ot-guide-grid{grid-template-columns:1fr}}@media(max-width:600px){.ot-recap-kpis{grid-template-columns:1fr}}
        .ot-fixed-business-date{display:grid;grid-template-columns:auto 1fr;gap:3px 12px;padding:12px 14px;border:1px solid #d6e3e8;border-radius:7px;background:#f7fbfc}.ot-fixed-business-date span{align-self:center;color:#657d89;font-size:12px}.ot-fixed-business-date b{align-self:center;color:#173f58;font-size:16px}.ot-fixed-business-date small{grid-column:1/-1;color:#81939c;font-size:11px}
        @media(max-width:650px){.ot-simple-page-head{align-items:flex-start;flex-direction:column}.ot-simple-page-head p{display:block;margin:2px 0 0}}

        /* 审核中心：测品任务沿用每日复盘的稳定表格骨架，避免状态与操作列互相挤压。 */
        .ot-test-review-card .ot-recap-review-table-wrap{overflow-x:auto;border:1px solid #d6e2e8;border-radius:8px;background:#fff}
        .ot-test-review-table{width:100%;min-width:1360px!important;table-layout:fixed!important;border-collapse:collapse}
        .ot-test-review-table>thead>tr>th,
        .ot-test-review-table>tbody>tr>td{padding:10px 8px!important;border-right:1px solid #d6e2e8;border-bottom:1px solid #d6e2e8;text-align:center!important;vertical-align:middle!important;line-height:1.45;white-space:normal;overflow-wrap:anywhere}
        .ot-test-review-table>thead>tr>th:last-child,
        .ot-test-review-table>tbody>tr>td:last-child{border-right:0}
        .ot-test-review-table>tbody>tr:last-child>td{border-bottom:0}
        .ot-test-review-table>thead>tr>th:nth-child(1),.ot-test-review-table>tbody>tr>td:nth-child(1){width:145px!important}
        .ot-test-review-table>thead>tr>th:nth-child(2),.ot-test-review-table>tbody>tr>td:nth-child(2){width:80px!important}
        .ot-test-review-table>thead>tr>th:nth-child(3),.ot-test-review-table>tbody>tr>td:nth-child(3){width:125px!important}
        .ot-test-review-table>thead>tr>th:nth-child(4),.ot-test-review-table>tbody>tr>td:nth-child(4){width:75px!important}
        .ot-test-review-table>thead>tr>th:nth-child(5),.ot-test-review-table>tbody>tr>td:nth-child(5){width:55px!important}
        .ot-test-review-table>thead>tr>th:nth-child(6),.ot-test-review-table>tbody>tr>td:nth-child(6){width:110px!important}
        .ot-test-review-table>thead>tr>th:nth-child(7),.ot-test-review-table>tbody>tr>td:nth-child(7){width:105px!important}
        .ot-test-review-table>thead>tr>th:nth-child(8),.ot-test-review-table>tbody>tr>td:nth-child(8),
        .ot-test-review-table>thead>tr>th:nth-child(9),.ot-test-review-table>tbody>tr>td:nth-child(9){width:125px!important}
        .ot-test-review-table>thead>tr>th:nth-child(10),.ot-test-review-table>tbody>tr>td:nth-child(10){width:185px!important}
        .ot-test-review-table>thead>tr>th:nth-child(11),.ot-test-review-table>tbody>tr>td:nth-child(11){width:185px!important}
        .ot-test-review-table .test-asset-info>b,
        .ot-test-review-table .test-asset-info>small{display:block}
        .ot-test-review-table .test-reviewer-cell .ot-stage-status{display:flex;align-items:center;justify-content:center;flex-direction:column;gap:5px}
        .ot-test-review-table .test-reviewer-cell .ot-stage-status small{display:block;margin:0;color:#607884;font-size:11px;line-height:1.35;word-break:break-word}
        .ot-test-review-table .ot-current-review-state>span,
        .ot-test-review-table .ot-current-review-state>small{display:block;margin:0 auto;max-width:100%}
        .ot-test-review-table .ot-current-review-state>small{margin-top:5px;color:#b64d4d;font-size:11px;line-height:1.4;white-space:normal;word-break:break-word}
        .ot-test-review-table .recap-actions{padding-left:6px!important;padding-right:6px!important;white-space:normal!important}
        .ot-test-review-table .ot-review-actions{display:flex;align-items:center;justify-content:center;gap:5px;flex-wrap:nowrap}
        .ot-test-review-table .ot-review-actions .btn{display:inline-flex;align-items:center;justify-content:center;min-width:48px;margin:0!important;padding:5px 8px;font-size:11px;line-height:18px;white-space:nowrap}

        /* 测品任务：每条素材仅展示北京时间当天是否已填写日报。 */
        .ot-test-table .test-asset-daily-status{display:grid;gap:3px;margin-top:7px}
        .ot-test-table .test-asset-daily-status span{display:block;padding:3px 6px;border-radius:5px;font-size:11px;line-height:1.35;white-space:normal}
        .ot-test-table .test-asset-daily-status .is-filled{background:#e8f6f1;color:#218873}
        .ot-test-table .test-asset-daily-status .is-empty{background:#fff5e8;color:#b8751a}
        /* 审核中心同时展示两级主管当前状态与完整审核流转。 */
        .ot-test-review-table{min-width:1500px!important}
        .ot-test-review-table>thead>tr>th:nth-child(5),.ot-test-review-table>tbody>tr>td:nth-child(5){width:125px!important}
        .ot-test-review-table>thead>tr>th:nth-child(6),.ot-test-review-table>tbody>tr>td:nth-child(6){width:145px!important}
        .ot-test-review-table>thead>tr>th:nth-child(7),.ot-test-review-table>tbody>tr>td:nth-child(7),
        .ot-test-review-table>thead>tr>th:nth-child(8),.ot-test-review-table>tbody>tr>td:nth-child(8){width:125px!important}
        .ot-test-review-table>thead>tr>th:nth-child(9),.ot-test-review-table>tbody>tr>td:nth-child(9){width:285px!important}
        .ot-test-review-table>thead>tr>th:nth-child(10),.ot-test-review-table>tbody>tr>td:nth-child(10){width:175px!important}
        /* 每日复盘审核：两级主管列等宽，审核流转列预留完整时间轴空间。 */
        .ot-daily-review-table{min-width:1500px!important;table-layout:fixed!important}
        .ot-daily-review-table>thead>tr>th:nth-child(8),.ot-daily-review-table>tbody>tr>td:nth-child(8),
        .ot-daily-review-table>thead>tr>th:nth-child(9),.ot-daily-review-table>tbody>tr>td:nth-child(9){width:140px!important;min-width:140px!important}
        .ot-daily-review-table>thead>tr>th:nth-child(10),.ot-daily-review-table>tbody>tr>td:nth-child(10){width:285px!important;min-width:285px!important}
        .ot-daily-review-table>thead>tr>th:nth-child(8),
        .ot-daily-review-table>thead>tr>th:nth-child(9),
        .ot-daily-review-table>thead>tr>th:nth-child(10){white-space:nowrap}
        .ot-review-page .ot-test-review-table>tbody>tr>td.recap-stage,
        .ot-review-page .ot-daily-review-table>tbody>tr>td.recap-stage{text-align:left!important}
        .ot-review-page .recap-stage>.ot-review-flow{width:100%;min-width:0;max-width:100%;margin:0}
        .ot-review-flow-item.is-approved>span{background:#eaf7f2;color:#247c66}
        .ot-review-flow-item>.ot-review-flow-content{display:block!important;flex:1;min-width:0;text-align:left}
        .ot-review-flow-item .ot-review-flow-main{display:flex;align-items:baseline;gap:7px;min-width:0;white-space:nowrap}
        .ot-review-flow-item .ot-review-flow-main b{display:inline;white-space:nowrap;word-break:normal}
        .ot-review-flow-item .ot-review-flow-main small{display:inline;flex:0 0 auto;margin:0;white-space:nowrap}
        .ot-review-flow-item .ot-review-flow-main .ot-review-flow-version{color:#4381a4;font-weight:700}
        .ot-review-flow-item .ot-review-flow-reason{display:block;margin:4px 0 0;padding:0;border:0;background:transparent;color:#a84b48;font-size:11px;line-height:1.5;white-space:normal;word-break:break-word}
    </style>
@endpush

@php
    $operationTaskNotification = null;
    if ($errors->any()) {
        $operationTaskNotification = ['type' => 'error', 'title' => __('operation_tasks.validation.failed_title'), 'message' => (string) $errors->first()];
    } elseif (session('error')) {
        $operationTaskNotification = ['type' => 'error', 'title' => __('operation_tasks.validation.error_title'), 'message' => (string) session('error')];
    } elseif (session('warning')) {
        $operationTaskNotification = ['type' => 'warning', 'title' => __('operation_tasks.validation.warning_title'), 'message' => (string) session('warning')];
    } elseif (session('success')) {
        $operationTaskNotification = ['type' => 'success', 'title' => __('operation_tasks.validation.success_title'), 'message' => (string) session('success')];
    }
@endphp

@if ($operationTaskNotification !== null)
    @push('scripts')
        <script>
            (function (notification) {
                if (window.ErpAdminNotifier) {
                    window.ErpAdminNotifier.notify(notification);
                } else {
                    window.alert(notification.message);
                }
            })({!! json_encode($operationTaskNotification, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!});
        </script>
    @endpush
@endif

@push('scripts')
<script>
(function () {
    function updateDrop(zone, input) {
        var file = input.files && input.files[0];
        var name = zone.querySelector('[data-file-name]');
        zone.classList.toggle('is-filled', !!file);
        if (name) name.textContent = file ? file.name : '尚未选择文件';
    }

    document.addEventListener('change', function (event) {
        if (!event.target.matches('.ot-file-drop input[type="file"]')) return;
        updateDrop(event.target.closest('.ot-file-drop'), event.target);
    });

    document.addEventListener('dragover', function (event) {
        var zone = event.target.closest && event.target.closest('.ot-file-drop');
        if (!zone) return;
        event.preventDefault();
        zone.classList.add('is-dragover');
    });

    document.addEventListener('dragleave', function (event) {
        var zone = event.target.closest && event.target.closest('.ot-file-drop');
        if (!zone || zone.contains(event.relatedTarget)) return;
        zone.classList.remove('is-dragover');
    });

    document.addEventListener('drop', function (event) {
        var zone = event.target.closest && event.target.closest('.ot-file-drop');
        if (!zone) return;
        event.preventDefault();
        zone.classList.remove('is-dragover');
        var input = zone.querySelector('input[type="file"]');
        var files = event.dataTransfer && event.dataTransfer.files;
        if (!input || !files || !files.length) return;
        try {
            var transfer = new DataTransfer();
            transfer.items.add(files[0]);
            input.files = transfer.files;
        } catch (error) {
            input.files = files;
        }
        updateDrop(zone, input);
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });
})();
</script>
@endpush

@push('styles')
<style>
.ot-field-error{display:block;margin:5px 0;color:#b63c35;font-size:12px;grid-column:1/-1;line-height:1.5}.ot-form-invalid{border-color:#d76960!important}.ot-form-validation-summary{margin:12px 16px;white-space:pre-wrap}.ot-form-validation-summary[hidden]{display:none!important}
</style>
@endpush
@push('scripts')
<script>
(function () {
    'use strict';
    var copy = @json(__('operation_tasks.validation'));
    var messages = @json(__('operation_tasks.messages'));
    var serial = 0;
    var limits = {
        landing_url:2048, selection_objective:10000, 'assets.*.selling_point':1000, 'assets.*.url':2048,
        selling_point:1000, url:2048, analysis:5000, next_action:5000, retired_reason:5000,
        opinion:5000, task_opinion:5000, 'assets.*.opinion':5000, plan_note:5000,
        restored_media:100, restored_ad_identifier:191, restored_notes:5000, custom_media:100,
        'media.*.custom_name':100, 'answers.*':10000, 'questions.*.title':500, 'questions.*.description':10000
    };
    function text(key, values, source) {
        var value = (source || copy)[key] || '';
        Object.keys(values || {}).forEach(function (token) { value = value.split(':' + token).join(String(values[token])); });
        return value;
    }
    function key(field) { return String(field.name || '').replace(/\[([^\]]*)\]/g, '.$1').replace(/\.\d+(?=\.|$)/g, '.*'); }
    function label(field) { return (copy.attributes || {})[key(field)] || copy.field; }
    function belongs(form) { return form && form.tagName === 'FORM' && (form.matches('.ot-form,.ot-unified-audit-form') || String(form.action).indexOf('/fashion/operation-tasks/') !== -1); }
    function controls(form) { return Array.prototype.slice.call(form.elements).filter(function (field) { return !field.disabled && field.matches('input,select,textarea') && field.type !== 'hidden' && ['button','submit','reset'].indexOf(field.type) === -1; }); }
    function clear(field) {
        field.classList.remove('ot-form-invalid');
        field.removeAttribute('aria-invalid');
        if (field.otError) {
            var described = (field.getAttribute('aria-describedby') || '').split(' ').filter(function (id) { return id && id !== field.otError.id; });
            if (described.length) field.setAttribute('aria-describedby', described.join(' ')); else field.removeAttribute('aria-describedby');
            field.otError.remove(); field.otError = null;
        }
    }
    function show(field, message) {
        clear(field);
        field.classList.add('ot-form-invalid');
        field.setAttribute('aria-invalid','true');
        var error = document.createElement('span');
        error.className = 'ot-field-error'; error.id = 'ot-field-error-' + (++serial); error.textContent = message;
        field.insertAdjacentElement('afterend',error); field.otError = error;
        field.setAttribute('aria-describedby', ((field.getAttribute('aria-describedby') || '') + ' ' + error.id).trim());
    }
    function fail(form, field, message) {
        if (field) show(field,message);
        var summary = form.querySelector('.ot-form-validation-summary');
        if (!summary) { summary = document.createElement('div'); summary.className = 'alert alert-danger ot-form-validation-summary'; summary.setAttribute('role','alert'); form.insertBefore(summary,form.firstChild); }
        summary.hidden = false; summary.textContent = message;
        if (field) {
            if (!field.matches('input,select,textarea,button')) field.tabIndex = -1;
            field.scrollIntoView({behavior:'smooth',block:'center'});
            field.focus({preventScroll:true});
        }
        if (window.ErpAdminNotifier) window.ErpAdminNotifier.notify({type:'error',title:copy.notice,message:message});
    }
    function controlError(field, form) {
        var value = String(field.value || '').trim(), name = key(field), attribute = label(field);
        var group = (field.type === 'radio' || field.type === 'checkbox') ? controls(form).filter(function (item) { return item.name === field.name; }) : [];
        if (field.required && ((group.length && !group.some(function (item) { return item.checked; })) || (!group.length && !value && !(field.files && field.files.length)))) return text(field.matches('select') || group.length ? 'choose' : 'required',{attribute:attribute});
        if (field.type === 'file') {
            var maximum = form.id === 'ot-create-test-form' ? 100 : 50;
            for (var i=0;i<(field.files || []).length;i++) {
                var file = field.files[i];
                if (file.size <= 0 || file.size > maximum * 1024 * 1024) return text('file_size',{max:maximum});
                if (!/\.(jpe?g|png|webp|gif|mp4|mov|webm)$/i.test(file.name)) return copy.file_type;
            }
            return '';
        }
        if (field.validity && field.validity.badInput) return text(field.type === 'date' ? 'date' : 'numeric',{attribute:attribute});
        if (!value || group.length) return '';
        var maximumLength = limits[name];
        if (maximumLength && Array.from(value).length > maximumLength) return text('max',{attribute:attribute,max:maximumLength});
        if (field.type === 'url' && field.validity.typeMismatch) return text('url',{attribute:attribute});
        if (field.type === 'number') {
            var number = Number(value);
            if (!Number.isFinite(number)) return text('numeric',{attribute:attribute});
            if ((name === 'orders' || name === 'media.*.orders' || name === 'single_target' || name === 'category_target') && (!/^[+-]?(0|[1-9]\d*)$/.test(value) || !Number.isInteger(number))) return text('integer',{attribute:attribute});
            if (field.min !== '' && number < Number(field.min)) return text('minimum',{attribute:attribute,min:field.min});
            if (field.max !== '' && number > Number(field.max)) return text('maximum',{attribute:attribute,max:field.max});
            if ((name === 'single_target' || name === 'category_target') && number > 30) return text('maximum',{attribute:attribute,max:30});
            if (field.validity.stepMismatch) return text('step',{attribute:attribute});
        }
        if (field.type === 'date') {
            if (field.max && value > field.max) return text('date_before',{attribute:attribute,date:field.max});
            if (field.min && value < field.min) return text('date_after',{attribute:attribute,date:field.min});
        }
        return '';
    }
    function check(form) {
        var first = null;
        controls(form).forEach(function (field) { clear(field); var message = controlError(field,form); if (message) { show(field,message); first = first || {field:field,message:message}; } });
        function add(field,message) { if (!field) return; show(field,message); first = first || {field:field,message:message}; }
        var pageType = form.querySelector('[name="page_type"]');
        if (pageType) {
            var rows = form.querySelectorAll(form.id === 'ot-create-test-form' ? '.ot-asset-input' : '.ot-edit-asset-row');
            var minimum = 1;
            if (rows.length < minimum) add(pageType,text('asset_count',{type:text(pageType.value === 'category' ? 'category' : 'single'),min:minimum,count:rows.length}));
            rows.forEach(function (row,index) {
                var file = row.querySelector('input[type="file"]'), url = row.querySelector('input[name$="[url]"]'), token = row.querySelector('input[name$="[upload_token]"]');
                var uploaded = form.id === 'ot-create-test-form' ? !!(token && token.value) : (row.getAttribute('data-has-file') === '1' || !!(file && file.files && file.files.length));
                if (!uploaded && !(url && url.value.trim())) add(url || file,text('asset_required',{number:index+1}));
            });
        }
        var directFile = form.querySelector('input[type="file"][name="file"]'), directUrl = form.querySelector('input[name="url"]');
        if (directFile && directUrl && !(directFile.files && directFile.files.length) && !directUrl.value.trim()) add(directUrl,copy.direct_asset_required);
        var custom = form.querySelector('[name="custom_media"]'), media = form.querySelector('[name="media"]');
        if (custom && media && media.value === '其他' && !custom.value.trim()) add(custom,text('custom_media_required',{},messages));
        var mediaRows = form.querySelectorAll('.ot-media-row'), seen = {};
        mediaRows.forEach(function (row) {
            var mediaSelect = row.querySelector('select'), customName = row.querySelector('input[name$="[custom_name]"]');
            if (!mediaSelect) return;
            var name = mediaSelect.value === '其他' ? String(customName ? customName.value : '').trim() : mediaSelect.value.trim();
            if (mediaSelect.value === '其他' && !name) add(customName || mediaSelect,text('custom_media_required',{},messages));
            name = name.toLowerCase();
            if (name && Object.prototype.hasOwnProperty.call(seen,name)) add(mediaSelect,text('duplicate_media',{},messages));
            if (name) seen[name] = true;
        });
        var plan = form.querySelector('[name="plan_mode"]');
        if (plan && plan.value === 'mixed') ['single_target','category_target'].forEach(function (name) { var target = form.querySelector('[name="'+name+'"]'); if (target && Number(target.value) < 1) add(target,text('mixed_required',{},messages)); });
        var taskResult = form.querySelector('[name="task_result"]'), taskOpinion = form.querySelector('[name="task_opinion"]');
        if (taskResult && taskResult.value === 'rejected') {
            if (taskOpinion && !taskOpinion.value.trim()) add(taskOpinion,text('task_opinion_required',{},messages));
            var scopes = form.querySelectorAll('[name="rejection_scopes[]"]:checked');
            if (!scopes.length) add(form.querySelector('[name="rejection_scopes[]"]') || taskResult,text('scope_required',{},messages));
            var assetScope = form.querySelector('[name="rejection_scopes[]"][value="asset"]:checked');
            if (assetScope && !Array.prototype.some.call(form.querySelectorAll('select[name$="[result]"]'),function (item) { return item.value === 'rejected'; })) add(assetScope,text('reject_asset_required',{},messages));
        }
        form.querySelectorAll('select[name$="[result]"]').forEach(function (result) {
            var name = result.name.replace(/\[result\]$/,'[opinion]');
            var opinion = Array.prototype.find.call(form.elements,function (field) { return field.name === name; });
            if (result.value === 'rejected' && opinion && !opinion.value.trim()) add(opinion,text('asset_opinion_required',{},messages));
        });
        var result = form.querySelector('[name="result"]'), opinion = form.querySelector('[name="opinion"]');
        if (result && result.value === 'rejected' && opinion && !opinion.value.trim()) add(opinion,text('task_opinion_required',{},messages));
        var accepted = form.querySelector('[name="restore_confirmed"]');
        if (accepted && !accepted.checked) add(accepted,text('choose',{attribute:label(accepted)}));
        [['submitted_from','submitted_to'],['from','to']].forEach(function (names) {
            if (names[0] === 'from' && /\/dashboard(?:\?|$)/.test(form.action)) return;
            var from = form.querySelector('[name="'+names[0]+'"]'), to = form.querySelector('[name="'+names[1]+'"]');
            if (from && to && from.value && to.value && from.value > to.value) add(to,copy.date_range);
        });
        return first;
    }
    function conditions(form) {
        var media = form.querySelector('[name="media"]'), custom = form.querySelector('[name="custom_media"]');
        if (media && custom) custom.required = media.value === '其他';
        var result = form.querySelector('[name="result"]'), opinion = form.querySelector('[name="opinion"]');
        if (result && opinion) opinion.required = result.value === 'rejected';
        var taskResult = form.querySelector('[name="task_result"]'), taskOpinion = form.querySelector('[name="task_opinion"]');
        if (taskResult && taskOpinion) taskOpinion.required = taskResult.value === 'rejected';
        form.querySelectorAll('select[name$="[result]"]').forEach(function (select) {
            var opinionName = select.name.replace(/\[result\]$/,'[opinion]');
            var target = Array.prototype.find.call(form.elements,function (field) { return field.name === opinionName; });
            if (target) target.required = select.value === 'rejected';
        });
    }
    function bind() { document.querySelectorAll('form').forEach(function (form) { if (belongs(form)) { form.noValidate = true; conditions(form); } }); }
    document.addEventListener('submit',function (event) {
        if (!belongs(event.target)) return;
        var error = check(event.target);
        if (error) { event.preventDefault(); event.stopImmediatePropagation(); fail(event.target,error.field,error.message); }
    },true);
    document.addEventListener('input',function (event) {
        var field = event.target, form = field.form;
        if (!belongs(form) || !field.matches('input,textarea,select')) return;
        var message = controlError(field,form);
        if (message) show(field,message); else clear(field);
        var summary = form.querySelector('.ot-form-validation-summary'); if (summary) summary.hidden = true;
    });
    document.addEventListener('change',function (event) {
        var field = event.target, form = field.form;
        if (belongs(form)) conditions(form);
        if (belongs(form) && field.matches('input[type="file"]')) { var message = controlError(field,form); if (message) show(field,message); else clear(field); }
    });
    window.OperationTaskForms = {text:text,fail:fail,check:check,clear:clear};
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',bind); else bind();
})();
</script>
@endpush
