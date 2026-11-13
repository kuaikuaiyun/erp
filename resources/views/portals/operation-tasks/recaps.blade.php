@extends('portal.layout')

@section('page_title', '每日复盘')

@include('portals.operation-tasks.partials.head')

@section('content')

@php
    $recapRevenue = (float) ($yesterdayRecap?->total_revenue_usd ?? 0);
    $recapSpend = (float) ($yesterdayRecap?->total_spend_usd ?? 0);
    $recapRoi = $recapSpend > 0 ? $recapRevenue / $recapSpend : null;
    $formRecap = $editingRecap;
    $savedAnswers = collect($formRecap?->question_answers ?? [])->keyBy('question_id');
    $initialMedia = old('media', collect($formRecap?->media_items ?? [])->values()->all());
    $workflowLabels = [
        'pending_leader' => __('operation_tasks.recap_review.pending'),
        'pending_department' => __('operation_tasks.recap_review.pending'),
        'rejected' => '已驳回待修改',
        'approved' => '审核通过',
    ];
    $workflowClass = fn ($status) => match ($status ?? 'approved') {
        'approved' => 'is-approved',
        'rejected' => 'is-rejected',
        default => 'is-pending',
    };
@endphp

<section class="content ot-page ot-recap-page">
    <div class="ot-simple-page-head">
        <div>
            <h1>每日复盘</h1>
            <p>填写昨日数据，再明确今天的动作。</p>
        </div>
        @if(! $yesterdayRecap || ($yesterdayRecap->workflow_status ?? 'approved') === 'rejected')
            <a class="btn ot-primary" href="{{ $yesterdayRecap ? route('operation-tasks.recaps.edit', ['recap' => $yesterdayRecap->id]) : route('operation-tasks.recaps.index', ['new' => 1]) }}"><i class="fa fa-plus"></i> {{ __($yesterdayRecap ? 'operation_tasks.recap_form.edit' : 'operation_tasks.recap_form.new') }}</a>

        @endif
    </div>

    <div class="ot-recap-kpis">
        <div class="ot-recap-kpi"><span>我的昨日复盘</span><strong>{{ $yesterdayRecap ? '已填写' : '待填写' }}</strong><small>数据日期 {{ $yesterday }} · {{ $user->name ?: $user->username }}</small></div>
        <div class="ot-recap-kpi"><span>昨日估算营业额</span><strong>{{ $yesterdayRecap ? '$'.number_format($recapRevenue, 2) : '—' }}</strong><small>统一 USD 填写</small></div>
        <div class="ot-recap-kpi"><span>昨日估算广告费</span><strong>{{ $yesterdayRecap ? '$'.number_format($recapSpend, 2) : '—' }}</strong><small>所选媒体花费自动合计</small></div>
        <div class="ot-recap-kpi"><span>估算 ROI · 营业额 / 广告费</span><strong>{{ $recapRoi !== null ? number_format($recapRoi, 2) : '—' }}</strong><small>自动计算，不等同于净利润</small></div>
    </div>

    <div class="ot-card ot-recap-guide">
        <div class="ot-card-head">
            <span>复盘内容与今日测试逻辑</span>
            <div>
                @if($canManageRecapQuestions)
                    <button type="button" class="btn btn-default btn-xs" data-toggle="modal" data-target="#ot-question-modal"><i class="fa fa-cog"></i> 管理问题</button>
                @endif
                <span class="ot-badge">简短、具体、有依据</span>
            </div>
        </div>
        <div class="ot-card-body ot-guide-grid">
            @forelse($recapQuestions as $index => $question)
                <div class="ot-guide-question">
                    <b><em>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</em>{{ $question->title }}@if($question->is_required) <span class="text-danger">*</span>@endif</b>
                    @if($question->description)<p>{{ $question->description }}</p>@endif
                    @if($yesterdayRecap)
                        @php
                            $guideAnswer = collect($yesterdayRecap->question_answers ?? [])->firstWhere('question_id', $question->id);
                        @endphp
                        @php
                            if (is_array($guideAnswer) && trim((string) ($guideAnswer['answer'] ?? '')) !== '') {
                                echo '<div class="ot-guide-answer">'.e($guideAnswer['answer']).'</div>';
                            }
                        @endphp
                    @endif
                </div>
            @empty
                <p class="ot-guide-note">当前未配置复盘问题，系统管理员可点击“管理问题”新增。</p>
            @endforelse
        </div>
    </div>

    <form method="get" class="ot-filters ot-search-filter-form">@include('portals.operation-tasks.partials.search-filters')<button class="btn ot-primary">{{ __('operation_tasks.filters.search') }}</button></form>
    <div class="ot-card">
        <div class="ot-card-head"><span>复盘记录</span><span class="ot-badge">{{ $recaps->total() }} 条</span></div>
        <div class="table-responsive">
            <table class="table ot-table ot-recap-table">
                <thead>
                    <tr><th>数据日期</th><th>运营 / 媒体</th><th>经营汇总</th><th>二级主管审核</th><th>一级主管审核</th><th>当前状态 / 驳回意见</th><th>操作</th></tr>
                </thead>
                <tbody>
                    @forelse($recaps as $recap)
                        @php
                            $status = $recap->workflow_status ?? 'approved';
                            $leaderStatus = $recap->leader_review_status ?? 'approved';
                            $departmentStatus = $recap->department_review_status ?? 'approved';
                            $reviewChannel = $recap->review_channel;
                        @endphp
                        <tr>
                            <td><b>{{ $recap->data_date->format('Y-m-d') }}</b><small>v{{ $recap->version }}</small></td>
                            <td><b>{{ $recap->user_name }}</b><small>{{ collect($recap->media_items)->pluck('name')->filter()->implode('、') }}</small></td>
                            <td><b>${{ number_format($recap->total_revenue_usd, 2) }}</b><small>单量 {{ $recap->total_orders }} · 广告费 ${{ number_format($recap->total_spend_usd, 2) }} · ROI {{ $recap->total_spend_usd > 0 ? number_format($recap->total_revenue_usd / $recap->total_spend_usd, 2) : '—' }}</small></td>
                            <td><span class="ot-badge {{ $leaderStatus === 'approved' ? '' : ($leaderStatus === 'rejected' ? 'red' : 'orange') }}">{{ $leaderStatus === 'approved' ? '已通过' : ($leaderStatus === 'rejected' ? '已驳回' : '待审核') }}</span></td>
                            <td><span class="ot-badge {{ $departmentStatus === 'approved' ? '' : ($departmentStatus === 'rejected' ? 'red' : 'orange') }}">{{ $departmentStatus === 'approved' ? '已通过' : ($departmentStatus === 'rejected' ? '已驳回' : '待审核') }}</span></td>
                            <td>
                                <span class="ot-badge {{ $workflowClass($status) }}">{{ $workflowLabels[$status] ?? '审核通过' }}</span>
                                @if($status === 'rejected' && $recap->last_rejection_opinion)<small class="ot-recap-rejection">{{ $recap->last_rejection_opinion }}</small>@endif
                            </td>
                            <td>
                                <button type="button" class="btn btn-default btn-xs" data-toggle="modal" data-target="#ot-recap-detail-{{ $recap->id }}">查看详情</button>
                                @if((int) $recap->user_id === (int) $user->id && $status === 'rejected')
                                    <a class="btn btn-default btn-xs" href="{{ route('operation-tasks.recaps.edit', ['recap' => $recap->id]) }}">{{ __('operation_tasks.recap_form.edit_button') }}</a>
                                @elseif((int) $recap->user_id === (int) $user->id)
                                    <button type="button" class="btn btn-default btn-xs ot-recap-edit-locked" aria-disabled="true" data-recap-edit-locked>{{ __('operation_tasks.recap_edit.button') }}</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="ot-recap-empty">暂无复盘记录。填写后会在这里保留历史。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('portals.operation-tasks.partials.pagination', [
            'paginator' => $recaps,
            'perPageName' => 'recap_per_page',
            'activeTab' => 'recaps',
        ])
    </div>

    @foreach($recaps as $recap)
        @php
            $status = $recap->workflow_status ?? 'approved';
            $reviewChannel = $recap->review_channel;
            $answers = collect($recap->question_answers ?? []);
        @endphp
        <div class="modal fade ot-modal ot-recap-detail-modal" id="ot-recap-detail-{{ $recap->id }}" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">{{ $recap->data_date->format('Y-m-d') }} 复盘 · {{ $recap->user_name }}</h4></div>
            <div class="modal-body">
                <table class="table ot-table"><thead><tr><th>媒体</th><th>花费</th><th>单量</th><th>营业额</th><th>ROI</th></tr></thead><tbody>
                    @foreach($recap->media_items as $item)
                        <tr><td>{{ $item['name'] ?? '—' }}</td><td>${{ number_format((float) ($item['spend'] ?? 0), 2) }}</td><td>{{ $item['orders'] ?? 0 }}</td><td>${{ number_format((float) ($item['revenue'] ?? 0), 2) }}</td><td>{{ (float) ($item['spend'] ?? 0) > 0 ? number_format((float) ($item['revenue'] ?? 0) / (float) $item['spend'], 2) : '—' }}</td></tr>
                    @endforeach
                </tbody></table>
                @foreach($answers as $answer)
                    <div class="ot-recap-answer"><b>{{ $answer['title'] ?? '复盘问题' }}</b><p>{{ $answer['answer'] ?? '—' }}</p></div>
                @endforeach
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div>
        </div></div></div>

    @endforeach

    <div class="modal fade ot-modal" id="ot-recap-modal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
        <form class="ot-form" method="post" action="{{ $formRecap ? route('operation-tasks.recaps.resubmit', ['recap' => $formRecap->id]) : route('operation-tasks.recaps.store') }}">@csrf
            <input type="hidden" name="recap_mode" value="{{ $formRecap ? 'edit' : 'new' }}">
            <input type="hidden" name="recap_id" value="{{ $formRecap?->id }}">
            <input type="hidden" name="recap_version" value="{{ $formRecap ? old('recap_version', $formRecap->version) : '' }}">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">{{ $formRecap ? __('operation_tasks.recap_form.edit_title', ['date' => $formRecap->data_date->format('Y-m-d'), 'version' => $formRecap->version]) : __('operation_tasks.recap_form.new') }}</h4></div>
            <div class="modal-body">
                <div class="ot-fixed-business-date"><span>{{ __('operation_tasks.recap_form.date') }}</span><b>{{ $formRecap ? $formRecap->data_date->format('Y-m-d') : $yesterday }}</b><small>{{ __($formRecap ? 'operation_tasks.recap_form.edit_hint' : 'operation_tasks.recap_form.new_hint') }}</small></div>
                <div class="form-group"><label>媒体经营数据 *</label><div id="ot-media-list"></div><button type="button" class="btn btn-default btn-sm" id="ot-add-media"><i class="fa fa-plus"></i> 添加媒体</button></div>
                <div class="ot-grid">
                    @foreach($recapQuestions as $question)
                        @php($saved = $savedAnswers->get($question->id))
                        <div class="ot-full form-group"><label>{{ $question->title }} @if($question->is_required)*@endif</label>@if($question->description)<p class="help-block">{{ $question->description }}</p>@endif<textarea class="form-control" rows="3" name="answers[{{ $question->id }}]" @if($question->is_required) required @endif>{{ old('answers.'.$question->id, $saved['answer'] ?? '') }}</textarea></div>
                    @endforeach
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button class="btn ot-primary">保存复盘</button></div>
        </form>
    </div></div></div>

    @if($canManageRecapQuestions)
        <div class="modal fade ot-modal" id="ot-question-modal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
            <form method="post" action="{{ route('operation-tasks.recaps.questions.store') }}">@csrf
                <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">管理复盘问题</h4></div>
                <div class="modal-body" id="ot-question-list">
                    @foreach($allRecapQuestions as $question)
                        <div class="ot-question-row"><input type="hidden" name="questions[{{ $loop->index }}][id]" value="{{ $question->id }}"><div><label>问题 *</label><input class="form-control" name="questions[{{ $loop->index }}][title]" value="{{ $question->title }}" required></div><div><label>说明</label><textarea class="form-control" name="questions[{{ $loop->index }}][description]">{{ $question->description }}</textarea></div><label><input type="checkbox" name="questions[{{ $loop->index }}][is_required]" value="1" @checked($question->is_required)> 必填</label></div>
                    @endforeach
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button class="btn ot-primary">保存问题模板</button></div>
            </form>
        </div></div></div>
    @endif
</section>
@endsection

@push('scripts')
<script>
(function () {
    var mediaOptions = @json($mediaOptions);
    var initialMedia = @json($initialMedia);
    var list = document.getElementById('ot-media-list');
    var index = 0;
    function escapeHtml(value) { var node = document.createElement('div'); node.textContent = value == null ? '' : String(value); return node.innerHTML; }
    function addMedia(data) {
        data = data || {};
        var i = index++;
        var options = mediaOptions.map(function (name) { return '<option value="' + escapeHtml(name) + '" ' + (name === data.name ? 'selected' : '') + '>' + escapeHtml(name) + '</option>'; }).join('');
        var row = document.createElement('div');
        row.className = 'ot-media-row';
        row.innerHTML = '<select class="form-control" name="media[' + i + '][name]" required><option value="">选择媒体</option>' + options + '</select><input class="form-control" type="number" min="0" step="0.01" name="media[' + i + '][spend]" value="' + escapeHtml(data.spend == null ? '' : data.spend) + '" placeholder="广告费" required><input class="form-control" type="number" min="0" step="1" name="media[' + i + '][orders]" value="' + escapeHtml(data.orders == null ? '' : data.orders) + '" placeholder="单量" required><input class="form-control" type="number" min="0" step="0.01" name="media[' + i + '][revenue]" value="' + escapeHtml(data.revenue == null ? '' : data.revenue) + '" placeholder="营业额" required>';
        var custom = document.createElement('input');
        custom.className = 'form-control'; custom.name = 'media[' + i + '][custom_name]';
        custom.placeholder = window.OperationTaskForms.text('custom_media_required', {}, @json(__('operation_tasks.messages')));
        custom.value = data.custom_name || (data.name === '其他' ? '' : ''); custom.style.gridColumn = '1 / -1';
        row.appendChild(custom);
        var select = row.querySelector('select');
        if (data.name && mediaOptions.indexOf(data.name) === -1) { select.value = '其他'; custom.value = data.name; }
        function customMedia() { var other = select.value === '其他'; custom.required = other; custom.hidden = !other; custom.style.display = other ? '' : 'none'; }
        select.addEventListener('change',customMedia); customMedia();
        list.appendChild(row);
    }
    (initialMedia.length ? initialMedia : [{}]).forEach(addMedia);
    document.getElementById('ot-add-media').addEventListener('click', function () { addMedia({}); });
    var open = document.getElementById('ot-open-recap');
    if (open) open.addEventListener('click', function () { $('#ot-recap-modal').modal('show'); });
    @if($errors->any() || $openRecapModal)
        $('#ot-recap-modal').modal('show');
    @endif
}());
</script>
@endpush

@push('styles')
<style>
.ot-recap-page .ot-recap-edit-locked,.ot-recap-page .ot-recap-edit-locked:hover,.ot-recap-page .ot-recap-edit-locked:focus{background:#f0f2f4;border-color:#d9dfe4;color:#929da6;cursor:not-allowed;box-shadow:none}
</style>
@endpush
@push('scripts')
<script>
(function (message, title) {
    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-recap-edit-locked]');
        if (!button) return;
        event.preventDefault();
        if (window.ErpAdminNotifier) {
            window.ErpAdminNotifier.notify({type: 'warning', title: title, message: message});
        }
    });
})(@json(__('operation_tasks.recap_edit.locked')), @json(__('operation_tasks.recap_edit.notice')));
</script>
@endpush
