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
