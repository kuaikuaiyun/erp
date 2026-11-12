@php
    $flowEvents = collect($record->review_flow_events ?? []);
    $hasSubmissionEvent = $flowEvents->contains(fn ($event) => $event->channel === 'operation');
    $flowRows = collect();

    if (! $hasSubmissionEvent) {
        $flowRows->push([
            'text' => ($record->user_name ?? $record->owner_name ?? '运营').' 提交审核',
            'time' => \App\Portals\OperationTasks\Support\OperationTaskTime::format($record->submitted_at, 'm-d H:i'),
            'version' => (int) ($record->version ?? 0),
            'state' => 'submitted',
            'reason' => null,
        ]);
    }

    foreach ($flowEvents as $event) {
        $action = match ($event->result) {
            'approved' => '已处理通过',
            'rejected' => '已处理驳回',
            'resubmitted' => '修改后再次提交',
            default => '提交审核',
        };
        $scopeLabels = collect(explode(',', (string) $event->rejection_scope))
            ->filter()
            ->map(fn ($scope) => match ($scope) {
                'task' => '落地页修改',
                'asset' => '素材问题修改',
                default => null,
            })
            ->filter()
            ->implode('、');
        $opinion = trim((string) $event->opinion);
        $reason = $opinion !== ''
            ? trim(($event->result === 'rejected' && $scopeLabels !== '' ? $scopeLabels.'：' : '').$opinion)
            : null;

        $flowRows->push([
            'text' => ($event->reviewer_name ?: '未知人员').' '.$action,
            'time' => \App\Portals\OperationTasks\Support\OperationTaskTime::format($event->reviewed_at, 'm-d H:i'),
            'version' => in_array($event->result, ['submitted', 'resubmitted'], true)
                ? (int) ($event->target_version ?? 0)
                : 0,
            'state' => $event->result,
            'reason' => $reason,
        ]);
    }
@endphp

<div class="ot-review-flow">
    @foreach($flowRows->take(-3) as $row)
        <div class="ot-review-flow-item is-{{ $row['state'] }}">
            <i></i>
            <div class="ot-review-flow-content">
                <div class="ot-review-flow-main">
                    <b>{{ $row['text'] }}</b>
                    @if(! empty($row['version']))
                        <small class="ot-review-flow-version">v{{ $row['version'] }}</small>
                    @endif
                    <small>{{ $row['time'] ?: '—' }}</small>
                </div>
                @if($row['reason'])
                    <p class="ot-review-flow-reason" title="{{ $row['reason'] }}">原因：{{ \Illuminate\Support\Str::limit($row['reason'], 60) }}</p>
                @endif
            </div>
        </div>
    @endforeach
    @if($flowRows->count() > 3)
        <details class="ot-review-flow-history">
            <summary>查看全部 {{ $flowRows->count() }} 条记录</summary>
            <div>
                @foreach($flowRows as $row)
                    <p>
                        <b>{{ $row['text'] }}</b>
                        @if(! empty($row['version']))<small class="ot-review-flow-version">v{{ $row['version'] }}</small>@endif
                        <small>{{ $row['time'] ?: '—' }}</small>
                        @if($row['reason'])<span>原因：{{ $row['reason'] }}</span>@endif
                    </p>
                @endforeach
            </div>
        </details>
    @endif
</div>
