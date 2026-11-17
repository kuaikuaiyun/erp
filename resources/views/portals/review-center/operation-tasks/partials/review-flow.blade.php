@php
    $flowEvents = collect($record->review_flow_events ?? []);
    $hasSubmissionEvent = $flowEvents->contains(fn ($event) => $event->channel === 'operation');
    $flowRows = collect();

    if (! $hasSubmissionEvent) {
        $flowRows->push([
            'text' => ($record->user_name ?? $record->owner_name ?? __('review_center.copy.text_043')).__('review_center.copy.text_173'),
            'time' => \App\Portals\OperationTasks\Support\OperationTaskTime::format($record->submitted_at, 'm-d H:i'),
            'version' => (int) ($record->version ?? 0),
            'state' => 'submitted',
            'reason' => null,
        ]);
    }

    foreach ($flowEvents as $event) {
        $action = match ($event->result) {
            'approved' => __('review_center.copy.text_174'),
            'rejected' => __('review_center.copy.text_175'),
            'resubmitted' => __('review_center.copy.text_176'),
            default => __('review_center.copy.text_177'),
        };
        $scopeLabels = collect(explode(',', (string) $event->rejection_scope))
            ->filter()
            ->map(fn ($scope) => match ($scope) {
                'task' => __('review_center.copy.text_178'),
                'asset' => __('review_center.copy.text_179'),
                default => null,
            })
            ->filter()
            ->implode('、');
        $opinion = trim((string) $event->opinion);
        $reason = $opinion !== ''
            ? trim(($event->result === 'rejected' && $scopeLabels !== '' ? $scopeLabels.'：' : '').$opinion)
            : null;

        $flowRows->push([
            'text' => ($event->reviewer_name ?: __('review_center.copy.text_180')).' '.$action,
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
                    <p class="ot-review-flow-reason" title="{{ $row['reason'] }}">{{ __('review_center.copy.text_181') }}{{ \Illuminate\Support\Str::limit($row['reason'], 60) }}</p>
                @endif
            </div>
        </div>
    @endforeach
    @if($flowRows->count() > 3)
        <details class="ot-review-flow-history">
            <summary>{{ __('review_center.copy.text_182') }} {{ $flowRows->count() }} {{ __('review_center.copy.text_183') }}</summary>
            <div>
                @foreach($flowRows as $row)
                    <p>
                        <b>{{ $row['text'] }}</b>
                        @if(! empty($row['version']))<small class="ot-review-flow-version">v{{ $row['version'] }}</small>@endif
                        <small>{{ $row['time'] ?: '—' }}</small>
                        @if($row['reason'])<span>{{ __('review_center.copy.text_181') }}{{ $row['reason'] }}</span>@endif
                    </p>
                @endforeach
            </div>
        </details>
    @endif
</div>
