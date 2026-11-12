<div class="modal fade ot-modal" id="{{ $modalId }}" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" action="{{ route('operation-tasks.reviews.store') }}">@csrf
            <input type="hidden" name="target_type" value="{{ $targetType }}"><input type="hidden" name="target_id" value="{{ $target->id }}"><input type="hidden" name="channel" value="{{ $channel }}">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal">×</button><h4 class="modal-title">{{ $label }}</h4><p>{{ $targetType === 'task' ? $target->task_no : ($target->original_name ?: '链接素材') }} · 当前版本 v{{ $target->version }}</p></div>
            <div class="modal-body"><div class="form-group"><label>审核结果 *</label><select class="form-control" name="result"><option value="approved" @selected(optional($review)->result === 'approved')>通过</option><option value="rejected" @selected(optional($review)->result === 'rejected')>驳回并整改</option></select></div><div class="form-group"><label>驳回对象</label><input type="hidden" name="rejection_scope" value="{{ $defaultScope === 'asset' ? 'asset' : 'task' }}"><p class="form-control-static"><b>{{ $defaultScope === 'asset' ? '对应素材' : '落地页' }}</b></p></div><div class="form-group"><label>审核意见</label><textarea class="form-control" rows="4" name="opinion" placeholder="通过意见可选；驳回必须填写明确的整改意见">{{ optional($review)->opinion }}</textarea><p class="help-block">驳回后系统仅创建对应整改事项，不会调用广告平台接口。</p></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button class="btn ot-primary"><i class="fa fa-check"></i> 保存审核</button></div>
        </form>
    </div></div>
</div>
