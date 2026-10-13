@php
    $t = static fn (string $key, array $replace = []): string => __('assistant.domain_stores.'.$key, $replace);
@endphp

@forelse ($logs as $log)
    @php
        $changes = collect($log->changes ?? [])->except('owner_department')->all();
        $eventLabel = match ($log->event) {
            'created' => $t('history.event_created'),
            'imported' => $t('history.event_imported'),
            default => $t('history.event_updated'),
        };
    @endphp
    <article class="assistant-history-card">
        <header class="assistant-history-card-header">
            <div>
                <strong>{{ $log->actor_name_snapshot ?: $t('common.unknown_operator') }}</strong>
                <span class="text-muted">{{ $eventLabel }} · {{ $log->created_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</span>
            </div>
            <button type="button" class="btn btn-default btn-xs assistant-history-toggle" aria-expanded="false">
                <i class="fa fa-angle-down"></i> {{ $t('history.expand') }}
            </button>
        </header>
        <div class="assistant-history-change-list" hidden>
            @forelse ($changes as $field => $change)
                @php
                    $label = \App\Models\AssistantDomainStore::historyFieldLabels()[$field] ?? $field;
                    $prefix = $log->event === 'updated' ? $t('history.field_updated', ['field' => $label]) : $label;
                @endphp
                <div class="assistant-history-change">
                    <span class="assistant-history-change-name">{{ $prefix }}：</span>
                    <span class="assistant-history-change-before">{{ \App\Models\AssistantDomainStore::displayHistoryValue($field, $change['before'] ?? null) }}</span>
                    <i class="fa fa-long-arrow-right" aria-hidden="true"></i>
                    <span class="assistant-history-change-after">{{ \App\Models\AssistantDomainStore::displayHistoryValue($field, $change['after'] ?? null) }}</span>
                </div>
            @empty
                <p class="text-muted">{{ $t('history.no_field_changes') }}</p>
            @endforelse
        </div>
    </article>
@empty
    <p class="text-muted assistant-history-empty">{{ $t('history.empty') }}</p>
@endforelse
