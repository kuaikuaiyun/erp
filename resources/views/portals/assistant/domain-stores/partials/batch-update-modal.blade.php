<div class="modal fade assistant-batch-editor-modal" id="assistant-batch-update-modal" tabindex="-1" role="dialog" aria-labelledby="assistant-batch-update-title">
    <div class="modal-dialog assistant-batch-editor-modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close assistant-batch-editor-close" data-dismiss="modal" aria-label="{{ $t('common.close') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="assistant-batch-update-title"><i class="fa fa-pencil-square-o"></i> {{ $t('batch_update.title') }}</h4>
            </div>
            <div class="modal-body" id="assistant-batch-update-content">
                <div class="assistant-batch-editor-loading"><i class="fa fa-spinner fa-spin"></i> {{ $t('javascript.modal_loading') }}</div>
            </div>
            <div class="modal-footer" id="assistant-batch-update-footer"></div>
        </div>
    </div>
</div>
