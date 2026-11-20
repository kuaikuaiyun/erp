@push('styles')
<style>
.daily-response-modal .modal-content{border:0;border-radius:12px;overflow:hidden}.daily-response-modal .modal-header{padding:16px 20px;background:#f4f9fc;border-bottom:1px solid #dceaf2}.daily-response-modal.is-rejected .modal-header{background:#fff5f5;border-bottom-color:#f3d6d6}.daily-response-modal .modal-title{color:#28546f;font-weight:600}.daily-response-modal.is-rejected .modal-title{color:#b93838}.daily-response-modal .modal-body{padding:20px}.daily-response-reason{min-height:140px;padding:10px 12px;line-height:1.65;resize:vertical}.daily-response-modal.is-rejected .daily-response-reason{border-color:#d9a7a7}.daily-response-reason:focus{border-color:#3c8dbc;box-shadow:0 0 0 2px rgba(60,141,188,.15)}.daily-response-modal.is-rejected .daily-response-reason:focus{border-color:#d9534f;box-shadow:0 0 0 2px rgba(217,83,79,.15)}.daily-response-hint{margin:8px 0 0;color:#758796;font-size:12px}
</style>
@endpush

<div class="modal fade daily-response-modal" id="dailyResponseModal" tabindex="-1" role="dialog" aria-labelledby="dailyResponseModalTitle"><div class="modal-dialog" role="document"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title" id="dailyResponseModalTitle"></h4></div><div class="modal-body"><label for="dailyResponseText">处理内容 <span class="text-danger">*</span></label><textarea id="dailyResponseText" class="form-control daily-response-reason" maxlength="5000" placeholder="请填写处理结果"></textarea><p class="daily-response-hint" id="dailyResponseHint"></p></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button type="button" class="btn btn-success" id="dailyResponseConfirm"><i class="fa fa-check"></i> 确认提交</button></div></div></div></div>

@push('scripts')
<script>
(function ($) {
    var form = null;
    var decision = null;
    var modal = $('#dailyResponseModal');
    var text = $('#dailyResponseText');
    var title = $('#dailyResponseModalTitle');
    var hint = $('#dailyResponseHint');
    var confirmButton = $('#dailyResponseConfirm');

    $(document).on('click', '.daily-response-trigger', function () {
        form = this.closest('form');
        decision = this.getAttribute('data-decision');
        var rejected = decision === 'rejected';
        title.html('<i class="fa fa-' + (rejected ? 'times-circle' : 'check-circle') + '"></i> ' + (rejected ? '驳回需求' : '回复并标记已处理'));
        text.attr('placeholder', rejected ? '请填写驳回原因' : '请填写处理结果').val(form.querySelector('[name="response_text"]').value || '');
        hint.text(rejected ? '驳回后会记录驳回原因。' : '确认后会保存回复内容并将该需求标记为已处理。');
        modal.toggleClass('is-rejected', rejected);
        confirmButton.toggleClass('btn-danger', rejected).toggleClass('btn-success', !rejected)
            .html('<i class="fa fa-' + (rejected ? 'times' : 'check') + '"></i> 确认' + (rejected ? '驳回' : '提交'));
        modal.modal('show');
        window.setTimeout(function () { text.trigger('focus'); }, 180);
    });

    confirmButton.on('click', function () {
        var value = $.trim(text.val());
        if (!value || !form || !decision) {
            text.trigger('focus');
            return;
        }
        form.querySelector('[name="response_text"]').value = value;
        form.querySelector('[name="decision"]').value = decision;
        this.disabled = true;
        form.submit();
    });

    modal.on('hidden.bs.modal', function () {
        form = null;
        decision = null;
        text.val('');
        confirmButton.prop('disabled', false);
    });
})(jQuery);
</script>
@endpush
