@php
    $detailLabels = [
        'common' => __('fashion_logistics_order_list'),
        'nbc' => $detailPlatform === 'nbc' ? __('nbc_orders') : [],
    ];
@endphp
<div class="modal fashion-order-modal fashion-order-detail-modal" id="fashion-order-detail-modal"
     tabindex="-1" role="dialog" aria-labelledby="fashion-order-detail-title" data-platform="{{ $detailPlatform }}">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ $listText('close') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="fashion-order-detail-title"><i class="fa fa-file-text-o" aria-hidden="true"></i> {{ $detailTitle }}</h4>
            </div>
            <div class="modal-body" data-fashion-detail-body aria-busy="false">
                <div class="fashion-detail-loading" data-fashion-detail-loading role="status" hidden>
                    <span class="flt-spinner" aria-hidden="true"></span>
                    <span>{{ $listText('loading_detail') }}</span>
                </div>
                <div class="fashion-detail-error" data-fashion-detail-error role="alert" hidden></div>
                <div class="fashion-detail-content" data-fashion-detail-content hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="flt-btn" data-dismiss="modal">{{ $listText('close') }}</button>
            </div>
        </div>
    </div>
    <script type="application/json" data-fashion-detail-labels>@json($detailLabels)</script>
</div>
