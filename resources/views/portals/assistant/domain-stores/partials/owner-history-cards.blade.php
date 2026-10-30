@forelse (($ownerHistories ?? collect()) as $ownerHistory)
    <article class="assistant-history-card">
        <header class="assistant-history-card-header">
            <div>
                <strong>{{ match ($ownerHistory->event) { 'baseline' => '初始归属', 'cleared' => '取消归属', 'assigned' => '设置归属', default => '转移归属' } }}</strong>
                <span class="text-muted">{{ $ownerHistory->effective_from?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</span>
            </div>
        </header>
        <div class="assistant-history-change-list">
            <div class="assistant-history-change">
                <span class="assistant-history-change-name">真实归属人：</span>
                <span class="assistant-history-change-after">{{ $ownerHistory->owner_name_snapshot ?: '未分配' }}</span>
            </div>
            <div class="assistant-history-change">
                <span class="assistant-history-change-name">操作人：</span>
                <span class="assistant-history-change-after">{{ $ownerHistory->actor_name_snapshot ?: '系统' }}</span>
            </div>
        </div>
    </article>
@empty
    <p class="text-muted assistant-history-empty">暂无真实归属人变更记录。</p>
@endforelse
