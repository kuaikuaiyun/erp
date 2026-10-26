@php($detailPrefix = 'payment.payment_orders.detail')
<div class="modal fade payment-order-modal payment-order-real-modal" id="payment-order-detail-modal" tabindex="-1" role="dialog" aria-labelledby="payment-order-modal-title" aria-hidden="true"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __($detailPrefix.'.close') }}"><span>&times;</span></button><h4 id="payment-order-modal-title">{{ __($detailPrefix.'.title') }}</h4><p data-payment-order-subtitle></p></div>
    <div class="modal-body"><div class="payment-order-detail-state" data-payment-order-loading><i class="fa fa-spinner fa-spin"></i>&nbsp; {{ __($detailPrefix.'.loading') }}</div><div class="payment-order-detail-state payment-order-detail-error" data-payment-order-error hidden></div><div data-payment-order-detail-body></div></div>
    <div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">{{ __($detailPrefix.'.close') }}</button></div>
</div></div></div>
