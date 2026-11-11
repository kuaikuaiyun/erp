@extends('portal.layout')
@section('page_title','测品任务')
@section('content')
@include('portals.operation-tasks.partials.head')
@php
    $singleTarget = (int) ($todayProgress['single_target'] ?? 0);
    $categoryTarget = (int) ($todayProgress['category_target'] ?? 0);
    $singleDone = (int) ($todayProgress['single_done'] ?? 0);
    $categoryDone = (int) ($todayProgress['category_done'] ?? 0);
@endphp
<section class="content ot-page ot-tests-page">
    <div class="ot-simple-page-head"><div><h1>测品任务</h1><p>提交后允许先投放，组长与审核部门当天独立审核</p></div><button type="button" class="btn ot-primary" data-toggle="modal" data-target="#ot-test-modal"><i class="fa fa-plus"></i> 新建任务</button></div>
    <div class="ot-test-goals">
        <div><small>今日测品计划</small><b>{{ $todayPlan ? ($todayProgress['label'] ?? '已确认') : '—' }}</b><strong>{{ $todayPlan ? '单品 '.$singleTarget.' 个 · 类目 '.$categoryTarget.' 个' : '等待主管确认' }}</strong><span>每日单独确认，混合计划同时核算两类目标</span></div>
        <div><small>今日计划完成</small><b>{{ $todayPlan ? ($singleDone + $categoryDone) : 0 }}</b><strong>{{ $todayPlan ? '单品 '.$singleDone.'/'.$singleTarget.' · 类目 '.$categoryDone.'/'.$categoryTarget : '尚未设置计划' }}</strong><span>单品至少 2 条、类目至少 3 条新素材</span></div>
        <div><small>今日任务已双通过</small><b>{{ $todayPassed }}</b><strong>待审核不影响先投放</strong><span>审核记录绑定当前版本</span></div>
    </div>
    <div class="ot-card">
        <div class="ot-card-head"><span>任务档案</span><span class="ot-hint">共 {{ $tasks->total() }} 条；点击任务详情可追加素材、填写日报和查看整改记录。</span></div>
        <div class="table-responsive">
            <table class="table ot-table ot-test-table">
                <thead><tr><th>任务历史 · 单品 / 类目</th><th>类型</th><th>平台</th><th>素材数</th><th>审核</th><th>操作</th></tr></thead>
                <tbody>
                @forelse($tasks as $task)
                    @php
                        $reviews = $task->reviews->where('target_version', $task->version);
                        $state = $reviews->where('result','rejected')->isNotEmpty() ? '需整改' : ($reviews->count() >= 2 && $reviews->where('result','approved')->count() >= 2 ? '已通过' : '待审核');
                        $media = $task->assets->flatMap(fn ($asset) => $asset->dailyRecords->pluck('media'))->filter()->unique()->values();
                    @endphp
                    <tr>
                        <td class="ot-task-info"><b>{{ $task->selection_objective ?: $task->task_no }}</b><small>提交 {{ optional($task->submitted_at)->format('m/d H:i') ?: '—' }} · {{ $task->owner_name }}</small></td>
                        <td><span>{{ $task->page_type === 'category' ? '类目页' : '单品页' }} · {{ $task->task_type === 'iteration' ? '迭代' : '新测试' }}</span></td>
                        <td>{{ $media->isNotEmpty() ? $media->join(' / ') : '未填写' }}</td>
                        <td><b>{{ $task->assets_count }}</b></td>
                        <td><span class="ot-badge {{ $state === '需整改' ? 'red' : ($state === '待审核' ? 'orange' : '') }}">{{ $state }}</span></td>
                        <td class="ot-task-action"><a class="btn btn-default btn-sm" href="{{ route('operation-tasks.tests.show',$task) }}"><i class="fa fa-eye"></i> 查看详情</a></td>
                    </tr>
                @empty
                    <tr class="ot-test-empty-row"><td colspan="6"><div class="ot-test-empty-state"><i class="fa fa-flask"></i><b>当前暂无测品任务</b><span>请使用页面右上角的“新建任务”创建首个测试档案。</span></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($tasks->hasPages())<div class="box-footer text-center">{{ $tasks->links() }}</div>@endif
    </div>
</section>
<div class="modal fade ot-modal" id="ot-test-modal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form class="ot-form" method="post" enctype="multipart/form-data" action="{{ route('operation-tasks.tests.store') }}">@csrf<div class="modal-header"><button type="button" class="close" data-dismiss="modal">×</button><h4 class="modal-title">新建测品任务</h4><p>明确选品理由、验证目标和对应 AI 素材后提交。</p></div><div class="modal-body"><div class="ot-grid"><div class="form-group"><label>落地页类型 *</label><select class="form-control" name="page_type" id="ot-page-type"><option value="single">单品页</option><option value="category">类目页</option></select></div><div class="form-group"><label>任务类型 *</label><select class="form-control" name="task_type"><option value="new">新测试</option><option value="iteration">已有测试迭代</option></select></div><div class="ot-full form-group"><label>落地页链接 *</label><input class="form-control" type="url" name="landing_url" value="{{ old('landing_url') }}" placeholder="https://..." required></div><div class="ot-full form-group"><label>选品理由与验证目标 *</label><textarea class="form-control" rows="4" name="selection_objective" required placeholder="卖给谁？为什么选这个品或类目？本次最想验证什么？">{{ old('selection_objective') }}</textarea></div></div><div class="form-group"><label>对应 AI 素材 *</label><div id="ot-assets"></div><button class="btn btn-default btn-sm" id="ot-add-asset" type="button"><i class="fa fa-plus"></i> 添加素材</button><p class="ot-hint" id="ot-asset-hint">单品至少 2 条本人 AI 新制作素材；类目至少 3 条。</p></div><label class="checkbox-inline"><input type="checkbox" name="ai_confirmed" value="1" required> 我确认以上素材由本人使用 AI 新制作，且表达与落地页商品一致。</label></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button class="btn ot-primary"><i class="fa fa-send"></i> 提交任务</button></div></form></div></div></div>
@push('styles')
<style>
    .ot-test-empty-row td{height:220px!important;padding:0!important;text-align:center!important;vertical-align:middle!important}
    .ot-test-empty-state{display:flex;min-height:220px;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:#738793}
    .ot-test-empty-state i{display:grid;width:34px;height:34px;place-items:center;border-radius:50%;background:#e8f5f0;color:#24947d;font-size:17px}
    .ot-test-empty-state b{color:#294b5d;font-size:15px;font-weight:700}
    .ot-test-empty-state span{font-size:12px;line-height:1.6}
</style>
@endpush
@push('scripts')
<script>
(function () {
    var form = document.querySelector('#ot-test-modal form');
    if (!form) return;

    form.addEventListener('submit', function (event) {
        var rows = form.querySelectorAll('.ot-asset-input');
        var invalid = null;

        Array.prototype.forEach.call(rows, function (row) {
            if (invalid) return;
            var file = row.querySelector('input[type="file"]');
            var url = row.querySelector('input[name$="[url]"]');
            var hasFile = !!(file && file.files && file.files.length);
            var hasUrl = !!(url && url.value.trim());
            if (!hasFile && !hasUrl) invalid = row;
        });

        if (!invalid) return;

        event.preventDefault();
        if (window.ErpAdminNotifier) {
            window.ErpAdminNotifier.notify({
                type: 'error',
                title: '请补充素材',
                message: '每条素材必须上传文件或填写素材链接。',
            });
        }

        invalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        var target = invalid.querySelector('input[name$="[url]"]') || invalid.querySelector('input[type="file"]');
        if (target) target.focus();
    });
})();
</script>
@endpush
@endsection
@push('styles')<style>
.ot-tests-page{max-width:1540px;margin:0 auto}.ot-tests-page .ot-head{margin-bottom:14px}.ot-test-goals{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:16px}.ot-test-goals>div{position:relative;min-height:110px;padding:17px 20px;border:1px solid #dce8ed;border-radius:10px;background:#fff;box-shadow:0 4px 16px rgba(25,57,73,.035)}.ot-test-goals>div:before{content:'';position:absolute;left:0;top:15px;bottom:15px;width:3px;border-radius:3px;background:#258b78}.ot-test-goals small{display:block;color:#708796;font-size:12px}.ot-test-goals b{display:inline-block;margin:8px 12px 0 0;color:#163d54;font-size:28px;line-height:1}.ot-test-goals strong{display:inline-block;color:#2f657b;font-size:12px;font-weight:700}.ot-test-goals span{display:block;margin-top:8px;color:#81929a;font-size:11px}.ot-tests-page .ot-card-head{min-height:54px;padding:14px 20px}.ot-test-table{table-layout:fixed}.ot-test-table th{height:38px;padding:9px 14px;vertical-align:middle;white-space:nowrap}.ot-test-table td{height:68px;padding:10px 14px;vertical-align:middle;border-color:#e1eaee;color:#294c5d;font-size:13px}.ot-test-table th:nth-child(1){width:34%}.ot-test-table th:nth-child(2){width:16%}.ot-test-table th:nth-child(3){width:16%}.ot-test-table th:nth-child(4){width:12%;text-align:center}.ot-test-table th:nth-child(5){width:10%;text-align:center}.ot-test-table th:nth-child(6){width:12%;text-align:center}.ot-test-table td:nth-child(4),.ot-test-table td:nth-child(5),.ot-test-table td:nth-child(6){text-align:center}.ot-test-table td b{display:block;color:#133e59;font-size:13px}.ot-test-table td small{display:block;overflow:hidden;margin-top:4px;color:#758c99;font-size:11px;line-height:1.35;text-overflow:ellipsis;white-space:nowrap}.ot-test-table .ot-badge{margin:1px 0;padding:3px 7px}.ot-task-action{text-align:center}.ot-test-table .btn{min-width:88px}.ot-test-table tr:hover td{background:#fbfdfd}.ot-tests-page .ot-hint{margin:0;font-size:12px}@media(max-width:1050px){.ot-test-table{min-width:880px}}@media(max-width:800px){.ot-test-goals{grid-template-columns:1fr}.ot-test-goals>div{min-height:92px}}
</style>@endpush
@push('scripts')<script>(function(){var list=document.getElementById('ot-assets'),add=document.getElementById('ot-add-asset'),type=document.getElementById('ot-page-type'),i=0;function row(){var n=i++,el=document.createElement('div');el.className='ot-asset-input';el.innerHTML='<div class="form-group"><label>表达卖点 / 测试目的 *</label><input class="form-control" name="assets['+n+'][selling_point]" required></div><div class="ot-grid"><div><label>素材文件</label><input class="form-control" type="file" name="assets['+n+'][file]" accept="image/*,video/*"></div><div><label>或素材链接</label><input class="form-control" type="url" name="assets['+n+'][url]" placeholder="https://..."></div></div>';list.appendChild(el)}add.onclick=row;type.onchange=function(){document.getElementById('ot-asset-hint').textContent=this.value==='category'?'类目页至少 3 条本人 AI 新制作素材。':'单品页至少 2 条本人 AI 新制作素材。'};row();row();@if($errors->any()||request()->boolean('new'))$('#ot-test-modal').modal('show');@endif})()</script>@endpush
