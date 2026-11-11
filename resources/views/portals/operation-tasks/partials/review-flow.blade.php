@php
    $flowEvents = collect($record->review_flow_events ?? []);
    $hasSubmissionEvent = $flowEvents->contains(fn ($event) => $event->channel === 'operation');
    $flowRows = collect();

    if (! $hasSubmissionEvent) {
        $flowRows->push([
            'text' => ($record->user_name ?? $record->owner_name ?? '运营').' 提交审核',
            'time' => \App\Portals\OperationTasks\Support\OperationTaskTime::format($record->submitted_at, 'm-d H:i'),
            'state' => 'submitted',
            'opinion' => null,
        ]);
    }

    foreach ($flowEvents as $event) {
        $action = match ($event->result) {
            'approved' => '已处理通过',
            'rejected' => '已处理驳回',
            'resubmitted' => '修改后再次提交',
            default => '提交审核',
        };
        $flowRows->push([
            'text' => ($event->reviewer_name ?: '未知人员').' '.$action,
            'time' => \App\Portals\OperationTasks\Support\OperationTaskTime::format($event->reviewed_at, 'm-d H:i'),
            'state' => $event->result,
            'opinion' => $event->opinion,
        ]);
    }

@endphp

<div class="ot-review-flow">
    @foreach($flowRows->take(-3) as $row)
        <div class="ot-review-flow-item is-{{ $row['state'] }}">
            <i></i><div><b>{{ $row['text'] }}</b><small>{{ $row['time'] ?: '—' }}</small></div>
        </div>
    @endforeach
    @if($flowRows->count() > 3)
        <details class="ot-review-flow-history"><summary>查看全部 {{ $flowRows->count() }} 条记录</summary><div>
            @foreach($flowRows as $row)<p><b>{{ $row['text'] }}</b><small>{{ $row['time'] ?: '—' }}</small>@if($row['opinion'])<span>{{ $row['opinion'] }}</span>@endif</p>@endforeach
        </div></details>
    @endif
</div>
