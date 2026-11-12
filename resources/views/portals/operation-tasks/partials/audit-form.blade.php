<div class="ot-audit-form-card {{ $allowed ? '' : 'is-readonly' }}">
    <b>{{ $label }}</b>
    <small>当前：{{ $review?->result === 'approved' ? '已通过' : ($review?->result === 'rejected' ? '已驳回' : '待审核') }}</small>
    @if($allowed)
        <form method="post" action="{{ route('operation-tasks.reviews.store') }}">
            @csrf
            <input type="hidden" name="target_type" value="{{ $targetType }}">
            <input type="hidden" name="target_id" value="{{ $targetId }}">
            <input type="hidden" name="channel" value="{{ $channel }}">
            <select class="form-control" name="result"><option value="approved" @selected($review?->result === 'approved')>通过</option><option value="rejected" @selected($review?->result === 'rejected')>驳回并整改</option></select>
            <input type="hidden" name="rejection_scope" value="{{ $isAsset ? 'asset' : 'task' }}">
            <div class="form-control-static"><b>{{ $isAsset ? '对应素材' : '落地页' }}</b></div>
            <textarea class="form-control" rows="3" name="opinion" placeholder="通过意见选填；驳回必须填写具体整改意见">{{ $review?->opinion }}</textarea>
            <button class="btn {{ $channel === 'department' ? 'btn-default' : 'ot-primary' }} btn-sm">保存{{ $label }}</button>
        </form>
    @else
        <p class="ot-hint">{{ $channel === 'leader' ? '仅任务所属二级部门的主管可审核。' : '仅该一级部门主管可审核。' }}</p>
    @endif
</div>
