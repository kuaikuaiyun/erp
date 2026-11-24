<div class="modal {{ empty($showDetailLoading) ? 'fade ' : '' }}logistics-order-detail-modal" id="logistics-order-detail-modal" tabindex="-1" role="dialog" aria-labelledby="logistics-order-detail-modal-title"
    @if(!empty($showDetailLoading)) data-minimum-loading-ms="250" data-order-detail-failure="{{ __('logistics.orders.list.detail_failure') }}" @endif>
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="logistics-order-detail-modal-title"><i class="fa fa-file-text-o"></i> {{ __('logistics.orders.list.detail_title') }}</h4>
            </div>
            <div class="modal-body">
                <div class="logistics-order-detail-loading" data-order-detail-loading role="status"><i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i><span>{{ __('logistics.orders.list.detail_loading') }}</span></div>
                <div class="alert alert-danger logistics-order-detail-error" data-order-detail-error role="alert" hidden></div>
                <div data-order-detail-content hidden></div>
            </div>
            <div class="modal-footer">
                <a class="btn btn-primary" href="#" data-order-detail-edit hidden><i class="fa fa-pencil"></i> 编辑订单</a>
                <button type="button" class="btn btn-default" data-dismiss="modal">关闭</button>
            </div>
        </div>
    </div>
</div>
