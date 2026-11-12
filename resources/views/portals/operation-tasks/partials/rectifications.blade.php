@php
    $requiredRectifications = $task->rectifications->where('status', 'required');
@endphp

<div class="ot-history-section" id="rectifications">
    <h4>整改记录</h4>
    <div class="ot-history-list">
        @forelse($task->rectifications->sortByDesc('id') as $rectification)
            <div class="ot-history-row ot-rectification-row">
                <span class="ot-badge {{ $rectification->status === 'required' ? 'red' : '' }}">
                    {{ ['required' => '待修改', 'resubmitted' => '已重新提交', 'restored' => '已完成', 'completed' => '已完成'][$rectification->status] ?? $rectification->status }}
                </span>
                <div>
                    <b>{{ $rectification->review_channel === 'leader' ? '二级主管' : '一级主管' }}驳回 · {{ $rectification->rejection_scope === 'asset' ? '对应素材' : '落地页与选品说明' }}</b>
                    <p>{{ $rectification->rejection_opinion }}</p>
                    <small>审核人：{{ $rectification->reviewer_name }} · 原版本 v{{ $rectification->target_version }}</small>
                </div>
                <div class="ot-history-action">
                    @if($rectification->status === 'required' && (int) $task->owner_user_id === (int) $user->id)
                        <button type="button" class="btn btn-warning btn-sm" data-toggle="modal" data-target="#ot-rectify-{{ $rectification->id }}">修改并重新提交</button>
                    @elseif($rectification->status === 'resubmitted')
                        <span>已提交 v{{ $rectification->resubmitted_task_version }}</span>
                    @endif
                </div>
            </div>
        @empty
            <div class="ot-history-empty">暂无整改事项。</div>
        @endforelse
    </div>
</div>

@foreach($requiredRectifications as $rectification)
    @php
        $sourceAsset = $rectification->asset;
    @endphp
    <div class="modal fade ot-modal" id="ot-rectify-{{ $rectification->id }}" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data" action="{{ route('operation-tasks.rectifications.resubmit', $rectification) }}" class="ot-form">
                    @csrf
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal">×</button>
                        <h4 class="modal-title">修改并重新提交</h4>
                        <p>{{ $rectification->review_channel === 'leader' ? '二级主管' : '一级主管' }}驳回：{{ $rectification->rejection_opinion }}</p>
                    </div>
                    <div class="modal-body">
                        @if($rectification->rejection_scope === 'asset' && $sourceAsset)
                            <div class="ot-grid">
                                <div class="ot-full"><label>表达卖点 / 测试目的 *</label><input class="form-control" name="selling_point" value="{{ $sourceAsset->selling_point }}" required></div>
                                <div class="ot-full"><label>素材标签 *</label><label class="radio-inline"><input type="radio" name="source_type" value="ai_created" {{ $sourceAsset->source_type === 'ai_created' ? 'checked' : '' }} required> AI制作</label><label class="radio-inline"><input type="radio" name="source_type" value="peer_copied" {{ $sourceAsset->source_type === 'peer_copied' ? 'checked' : '' }} required> 同行搬运</label></div>
                                <div><label>替换素材文件（不替换可留空）</label><input class="form-control" type="file" name="file" accept="image/*,video/*"></div>
                                <div><label>素材链接</label><input class="form-control" type="url" name="url" value="{{ $sourceAsset->external_url }}" placeholder="https://..."></div>
                            </div>
                        @else
                            <div class="ot-grid">
                                <div class="ot-full"><label>落地页链接 *</label><input class="form-control" type="url" name="landing_url" value="{{ $task->landing_url }}" required></div>
                                <div class="ot-full"><label>选品理由与验证目标 *</label><textarea class="form-control" rows="6" name="selection_objective" required>{{ $task->selection_objective }}</textarea></div>
                            </div>
                        @endif
                        <div class="form-group"><label>本次修改说明 *</label><textarea class="form-control" rows="3" name="rectification_content" required placeholder="说明针对驳回意见做了哪些修改"></textarea></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                        <button class="btn ot-primary">重新提交审核</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach

@if(request()->boolean('rectify') && $requiredRectifications->isNotEmpty())
    @push('scripts')
        <script>$(function(){ $('#ot-rectify-{{ $requiredRectifications->first()->id }}').modal('show'); });</script>
    @endpush
@endif
