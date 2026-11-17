@extends('portal.layout')
@section('page_title',__('review_center.copy.text_114'))
@section('content')
@include('portals.review-center.operation-tasks.partials.head')
@php
    $taskHost = parse_url($task->landing_url, PHP_URL_HOST) ?: $task->task_no;
    $feedbackCount = $task->assets->sum(fn ($asset) => $asset->dailyRecords->count());
    $currentTaskReviews = $task->reviews->where('target_version', $task->version)->keyBy('channel');
    $taskStatus = $currentTaskReviews->where('result', 'rejected')->isNotEmpty() ? __('review_center.copy.text_108') : ($currentTaskReviews->count() >= 2 && $currentTaskReviews->where('result', 'approved')->count() >= 2 ? __('review_center.copy.text_012') : __('review_center.copy.text_010'));
@endphp
<section class="content ot-page ot-task-detail-page">
    <div class="ot-detail-head"><div><span class="ot-eyebrow">{{ __('review_center.copy.text_115') }}</span><h1>{{ __('review_center.copy.text_114') }}</h1><small>{{ $task->task_no }} · {{ $task->owner_name }} · {{ __('operation_tasks.filters.first') }} {{ \App\Portals\OperationTasks\Support\OperationTaskTime::format($task->created_at) }} · {{ __('operation_tasks.filters.latest') }} {{ \App\Portals\OperationTasks\Support\OperationTaskTime::format($task->submitted_at) }}</small></div><div class="ot-detail-head-actions"><a class="btn btn-default" href="{{ route('review-center.operation-tasks.reviews.index') }}"><i class="fa fa-arrow-left"></i> {{ __('review_center.copy.text_116') }}</a><a class="btn btn-default" href="{{ route('review-center.operation-tasks.reviews.index') }}">{{ __('review_center.copy.text_117') }}</a></div></div>
    <div class="ot-detail-stats"><div><small>{{ __('review_center.copy.text_118') }}</small><b>{{ $taskStatus }}</b><span>{{ __('review_center.copy.text_119') }}</span></div><div><small>{{ __('review_center.copy.text_120') }}</small><b>{{ $task->assets->count() }}</b><span>{{ __('review_center.copy.text_121') }}</span></div><div><small>{{ __('review_center.copy.text_122') }}</small><b>{{ $task->assets->whereNull('retired_at')->count() }}</b><span>{{ __('review_center.copy.text_123') }}</span></div><div><small>{{ __('review_center.copy.text_124') }}</small><b>{{ $feedbackCount }}</b><span>{{ __('review_center.copy.text_125') }}</span></div></div>
    <div class="ot-current-task-card"><div class="ot-current-task-title"><div><div class="ot-task-tags"><span class="ot-badge">{{ $task->page_type === 'category' ? __('review_center.copy.text_016') : __('review_center.copy.text_017') }}</span><span class="ot-badge orange">{{ $task->task_type === 'iteration' ? __('review_center.copy.text_110') : __('review_center.copy.text_019') }}</span><span class="ot-badge {{ $taskStatus === __('review_center.copy.text_108') ? 'red' : ($taskStatus === __('review_center.copy.text_010') ? 'orange' : '') }}">{{ $taskStatus }}</span></div><h2>{{ $taskHost }}</h2><p>{{ $task->selection_objective }}</p></div><a class="btn btn-default btn-sm" target="_blank" rel="noopener" href="{{ $task->landing_url }}">{{ __('review_center.copy.text_126') }} <i class="fa fa-external-link"></i></a></div></div>
    <div class="ot-detail-tabs" role="tablist"><button class="is-active" type="button" data-detail-tab="assets">{{ __('review_center.copy.text_127') }} <span>{{ $task->assets->count() }}</span></button><button type="button" data-detail-tab="logic">{{ __('review_center.copy.text_128') }}</button><button type="button" data-detail-tab="history">{{ __('review_center.copy.text_129') }}</button></div>
    <section class="ot-detail-pane is-active" data-detail-pane="assets"><div class="ot-pane-head"><div><h3>{{ __('review_center.copy.text_130') }}</h3><span>{{ __('review_center.copy.text_131') }}</span></div></div><div class="ot-detail-assets">
        @forelse($task->assets as $asset)
            @php
                $assetReviews = $asset->reviews->where('target_version', $asset->version);
                $assetStatus = $asset->retired_at ? __('review_center.copy.text_109') : ($assetReviews->where('result','rejected')->isNotEmpty() ? __('review_center.copy.text_108') : ($assetReviews->count() >= 2 && $assetReviews->where('result','approved')->count() >= 2 ? __('review_center.copy.text_012') : __('review_center.copy.text_010')));
                $assetSpend = (float) $asset->dailyRecords->sum('spend_usd');
                $assetOrders = (int) $asset->dailyRecords->sum('orders');
                $weightedRevenue = (float) $asset->dailyRecords->sum(fn ($record) => (float) $record->spend_usd * (float) ($record->roi ?? 0));
            @endphp
            <article class="ot-detail-asset-card {{ $asset->retired_at ? 'is-retired' : '' }}"><div class="ot-detail-asset-media">@if($asset->file_path)@if(str_starts_with((string) $asset->mime_type,'video/'))<button type="button" class="ot-detail-video-trigger" data-toggle="modal" data-target="#ot-detail-video-modal" data-video-src="{{ route('review-center.operation-tasks.assets.file',$asset) }}" data-video-name="{{ $asset->original_name }}"><i class="fa fa-play-circle" aria-hidden="true"></i><span>{{ __('operation_tasks.video.preview') }}</span></button>@else<img class="erp-zoomable" src="{{ route('review-center.operation-tasks.assets.file',$asset) }}" alt="{{ $asset->original_name }}">@endif
@elseif($asset->external_url)<a target="_blank" rel="noopener" href="{{ $asset->external_url }}"><i class="fa fa-external-link"></i> {{ __('review_center.copy.text_132') }}</a>@else<span>{{ __('review_center.copy.text_133') }}</span>@endif</div><div class="ot-detail-asset-body"><div class="ot-detail-asset-name"><b>{{ $asset->original_name ?: __('review_center.copy.text_111') }}</b><span class="ot-badge {{ in_array($assetStatus,[__('review_center.copy.text_109'),__('review_center.copy.text_108')],true) ? 'red' : ($assetStatus === __('review_center.copy.text_010') ? 'orange' : '') }}">{{ $assetStatus }}</span></div><p>{{ $asset->selling_point }}</p><small>v{{ $asset->version }} {{ __('review_center.copy.text_134') }} {{ \App\Portals\OperationTasks\Support\OperationTaskTime::format($asset->created_at, 'Y-m-d') }} · {{ $asset->uploader_name }} @if($asset->retired_at) {{ __('review_center.copy.text_135') }} {{ \App\Portals\OperationTasks\Support\OperationTaskTime::format($asset->retired_at) }}@endif</small><div class="ot-detail-asset-metrics"><span>{{ __('review_center.copy.text_136') }}{{ number_format($assetSpend,2) }}</span><span>{{ __('review_center.copy.text_137') }}{{ $assetOrders }}</span><span>{{ __('review_center.copy.text_138') }}{{ $assetSpend > 0 ? number_format($weightedRevenue / $assetSpend,2) : '—' }}</span></div></div><div class="ot-detail-feedback"><div class="ot-detail-feedback-head"><b>{{ __('review_center.copy.text_139') }}</b><span>{{ $asset->dailyRecords->count() }} {{ __('review_center.copy.text_140') }}</span></div><div class="table-responsive"><table class="table ot-table ot-detail-feedback-table"><thead><tr><th>{{ __('review_center.copy.text_141') }}</th><th>{{ __('review_center.copy.text_060') }}</th><th>{{ __('review_center.copy.text_061') }}</th><th>{{ __('review_center.copy.text_062') }}</th><th>{{ __('review_center.roi') }}</th><th>{{ __('review_center.copy.text_142') }}</th></tr></thead><tbody>@forelse($asset->dailyRecords->sortByDesc('data_date') as $record)<tr><td>{{ $record->data_date->format('Y-m-d') }}<small>v{{ $record->version }}</small></td><td>{{ $record->media }}</td><td>${{ number_format($record->spend_usd,2) }}</td><td>{{ $record->orders }}</td><td>{{ $record->roi !== null ? number_format($record->roi,2) : '—' }}</td><td><div>{{ $record->analysis ?: '—' }}</div><small>{{ $record->next_action ?: '—' }}</small></td></tr>@empty<tr><td colspan="6" class="text-center text-muted">{{ __('review_center.copy.text_143') }}</td></tr>@endforelse</tbody></table></div></div></article>
        @empty <div class="ot-empty-state"><i class="fa fa-picture-o"></i><b>{{ __('review_center.copy.text_144') }}</b><span>{{ __('review_center.copy.text_145') }}</span></div> @endforelse
    </div></section>
    <section class="ot-detail-pane" data-detail-pane="logic"><div class="ot-card"><div class="ot-card-head">{{ __('review_center.copy.text_146') }}</div><div class="ot-card-body ot-logic-fields"><div><b>{{ __('review_center.copy.text_077') }}</b><p><a target="_blank" rel="noopener" href="{{ $task->landing_url }}">{{ $task->landing_url }}</a></p></div><div><b>{{ __('review_center.copy.text_088') }}</b><p>{{ $task->selection_objective }}</p></div>@if(!empty($task->version_history))<details><summary>{{ __('review_center.copy.text_147') }}{{ count($task->version_history) }} {{ __('review_center.copy.text_148') }}</summary><pre>{{ json_encode($task->version_history, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre></details>@endif</div></div></section>
    <section class="ot-detail-pane" data-detail-pane="history">
        @include('portals.review-center.operation-tasks.partials.rectifications')
        <div class="ot-card ot-review-history-card"><div class="ot-card-head">{{ __('review_center.copy.text_149') }}</div><div class="table-responsive"><table class="table ot-table"><thead><tr><th>{{ __('review_center.copy.text_150') }}</th><th>{{ __('review_center.copy.text_151') }}</th><th>{{ __('review_center.copy.text_152') }}</th><th>{{ __('review_center.copy.text_153') }}</th><th>{{ __('review_center.copy.text_154') }}</th><th>{{ __('review_center.copy.text_155') }}</th></tr></thead><tbody>@forelse($reviewEvents as $event)<tr><td>{{ \App\Portals\OperationTasks\Support\OperationTaskTime::format($event->reviewed_at) }}</td><td>{{ $event->target_type === 'asset' ? __('review_center.copy.text_112').$event->target_id : __('review_center.copy.text_113') }} · v{{ $event->target_version }}</td><td>{{ $event->channel === 'leader' ? __('review_center.copy.text_004') : __('review_center.copy.text_005') }}</td><td><span class="ot-badge {{ $event->result === 'rejected' ? 'red' : '' }}">{{ $event->result === 'approved' ? __('review_center.copy.text_002') : __('review_center.copy.text_027') }}</span></td><td>{{ $event->reviewer_name }}</td><td>{{ $event->opinion ?: '—' }}</td></tr>@empty<tr><td colspan="6" class="text-center text-muted">{{ __('review_center.copy.text_156') }}</td></tr>@endforelse</tbody></table></div></div>
        <div class="ot-card ot-version-history-card"><div class="ot-card-head">{{ __('review_center.copy.text_157') }}</div><div class="ot-card-body">@forelse($task->assets as $asset)<details class="ot-version-item"><summary>{{ $asset->original_name ?: __('review_center.copy.text_111') }} {{ __('review_center.copy.text_158') }}{{ $asset->version }} {{ __('review_center.copy.text_159') }} {{ count($asset->version_history ?? []) }} {{ __('review_center.copy.text_140') }}</summary><pre>{{ json_encode($asset->version_history ?? [], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre></details>@empty<span class="text-muted">{{ __('review_center.copy.text_160') }}</span>@endforelse</div></div>
    </section>
</section>
<div class="modal fade ot-modal" id="ot-detail-video-modal" tabindex="-1" role="dialog" aria-labelledby="ot-detail-video-title"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('operation_tasks.video.close') }}">&times;</button><h4 class="modal-title" id="ot-detail-video-title">{{ __('operation_tasks.video.preview') }}</h4><p id="ot-detail-video-name"></p></div>
    <div class="modal-body"><video id="ot-detail-video-player" controls playsinline preload="none">{{ __('operation_tasks.video.unsupported') }}</video></div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('operation_tasks.video.close') }}</button></div>
</div></div></div>
@endsection
@push('styles')
<style>
.ot-current-task-title{align-items:flex-start}.ot-current-task-title>a{flex:0 0 auto;align-self:flex-start;white-space:nowrap;height:auto;min-height:32px;padding:6px 11px;line-height:20px}.ot-detail-asset-card.is-retired{opacity:.64;filter:grayscale(.85);background:#f3f5f6}.ot-detail-asset-card.is-retired .ot-detail-asset-media{filter:grayscale(1)}.ot-detail-asset-actions{flex-wrap:wrap}.ot-check{display:block;margin-top:18px;color:#45606b;font-weight:500}.ot-check input{margin-right:7px}.ot-review-history-card,.ot-version-history-card{margin-top:16px}.ot-version-item{padding:12px 0;border-bottom:1px solid #e5edef}.ot-version-item:last-child{border-bottom:0}.ot-version-item summary{cursor:pointer;color:#1b597c;font-weight:700}.ot-version-item pre{max-height:240px;margin:10px 0 0;padding:12px;overflow:auto;border:1px solid #e3ecef;border-radius:7px;background:#f8fafb;font-size:12px;white-space:pre-wrap}
.ot-detail-feedback{grid-column:1/-1;flex:0 0 100%;width:100%;margin-top:4px;border-top:1px solid #e2ebee;background:#fff}.ot-detail-feedback-head{display:flex;align-items:center;justify-content:space-between;padding:12px 14px 8px;color:#173f58}.ot-detail-feedback-head span{color:#78909c;font-size:12px;font-weight:400}.ot-detail-feedback .table-responsive{margin:0 14px 14px;border:1px solid #e3ecef;border-radius:6px}.ot-detail-feedback-table{margin-bottom:0}.ot-detail-feedback-table>thead>tr>th{background:#f5f8f9;color:#607784;font-weight:600;white-space:nowrap}.ot-detail-feedback-table>tbody>tr>td{vertical-align:middle}.ot-detail-feedback-table small{display:block;margin-top:3px;color:#78909c}.ot-detail-feedback-table td:last-child{min-width:260px}
.ot-detail-video-trigger{display:flex;width:100%;height:100%;flex-direction:column;align-items:center;justify-content:center;gap:12px;border:0;background:#edf4f7;color:#246c98;cursor:pointer}.ot-detail-video-trigger i{font-size:42px}.ot-detail-video-trigger:focus{outline:2px solid #246c98;outline-offset:-3px}#ot-detail-video-player{display:block;width:100%;max-height:65vh;background:#111}
</style>
@endpush
@push('scripts')
<script>
(function () {
    var videoModal = window.jQuery('#ot-detail-video-modal');
    var videoPlayer = document.getElementById('ot-detail-video-player');
    function releaseVideo() {
        videoPlayer.pause();
        videoPlayer.removeAttribute('src');
        videoPlayer.load();
    }
    videoModal.on('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) return;
        releaseVideo();
        document.getElementById('ot-detail-video-name').textContent = trigger.getAttribute('data-video-name') || '';
        videoPlayer.src = trigger.getAttribute('data-video-src');
        videoPlayer.preload = 'metadata';
        videoPlayer.load();
    });
    videoModal.on('hide.bs.modal', releaseVideo);
    window.addEventListener('pagehide', releaseVideo);
    var canEditTask = @json($canEditTask);
    if (!canEditTask) {
        document.querySelectorAll('[data-target="#ot-append-asset"], [data-target^="#ot-daily-"], [data-target^="#ot-retire-"]').forEach(function (button) { button.remove(); });
    }
    document.querySelectorAll('[data-detail-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            var name = button.getAttribute('data-detail-tab');
            document.querySelectorAll('[data-detail-tab]').forEach(function (item) { item.classList.toggle('is-active', item === button); });
            document.querySelectorAll('[data-detail-pane]').forEach(function (item) { item.classList.toggle('is-active', item.getAttribute('data-detail-pane') === name); });
        });
    });
}());
</script>
@endpush
