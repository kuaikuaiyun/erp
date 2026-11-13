@once
<div class="modal fade ot-modal" id="ot-delete-confirm" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">{{ __('operation_tasks.management.delete_title') }}</h4></div>
        <div class="modal-body">{{ __('operation_tasks.management.delete_hint') }}</div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('operation_tasks.management.cancel') }}</button><button type="button" class="btn btn-danger" data-confirm-delete>{{ __('operation_tasks.management.confirm_delete') }}</button></div>
    </div></div>
</div>
@push('scripts')
<script>
(function () {
    var pendingForm = null;
    var modal = window.jQuery('#ot-delete-confirm');
    document.addEventListener('submit', function (event) {
        if (!event.target.matches('[data-delete-form]') || event.target.dataset.deleteConfirmed === '1') return;
        event.preventDefault();
        pendingForm = event.target;
        modal.modal('show');
    });
    document.querySelector('[data-confirm-delete]').addEventListener('click', function () {
        if (!pendingForm) return;
        this.disabled = true;
        pendingForm.dataset.deleteConfirmed = '1';
        pendingForm.requestSubmit();
    });
    modal.on('hidden.bs.modal', function () {
        pendingForm = null;
        document.querySelector('[data-confirm-delete]').disabled = false;
    });
}());
</script>
@endpush
@endonce
