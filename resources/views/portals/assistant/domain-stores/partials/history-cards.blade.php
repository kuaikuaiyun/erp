@php
    $t = static fn (string $key, array $replace = []): string => __('assistant.domain_stores.'.$key, $replace);
@endphp

@if (($ownerHistories ?? collect())->isNotEmpty())
    <section class="assistant-history-card">
        <header class="assistant-history-card-header">
            <div><strong>店铺归属变更记录</strong></div>
        </header>
        <div class="assistant-history-change-list">
            @foreach ($ownerHistories as $ownerHistory)
                <div class="assistant-history-change">
                    <span class="assistant-history-change-name">{{ match ($ownerHistory->event) { 'baseline' => '初始归属', 'cleared' => '取消归属', 'assigned' => '设置归属', default => '转移归属' } }}：</span>
                    <span class="assistant-history-change-after">{{ $ownerHistory->owner_name_snapshot ?: '未分配' }}</span>
                    <span class="text-muted">生效时间 {{ $ownerHistory->effective_from?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}；操作人 {{ $ownerHistory->actor_name_snapshot ?: '系统' }}</span>
                </div>
            @endforeach
        </div>
    </section>
@endif

@forelse ($logs as $log)
    @php
        $changes = collect($log->changes ?? [])->except(['owner_department', 'user_name'])->all();
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
