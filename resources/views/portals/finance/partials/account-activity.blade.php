@push('styles')
<style>
#finance-account-activity .modal-dialog{width:min(1120px,calc(100vw - 30px))}#finance-account-activity .modal-body{background:#f0f2f9;padding:18px}#finance-account-activity .finance-activity-heading{font-size:30px;padding:0 0 18px}#finance-account-activity .finance-activity-body{padding:24px 20px 14px}#finance-account-activity .finance-activity-field span{font-weight:400}#finance-account-activity .finance-filter .form-group{min-width:135px}#finance-account-activity .finance-filter .finance-activity-account{min-width:85px}#finance-account-activity .finance-activity-create{background:#111}#finance-account-activity .finance-activity-create:disabled{background:#888;opacity:1}#finance-account-activity .finance-activity-sync-title{margin:0}#finance-account-activity [data-activity-message][hidden],#finance-account-activity [data-activity-sync-unavailable][hidden]{display:none!important}#finance-account-activity [data-activity-message]{margin-bottom:16px;overflow-wrap:anywhere}#finance-account-activity .finance-filter{margin-bottom:0}@media(max-width:600px){#finance-account-activity .modal-body{padding:12px}#finance-account-activity .finance-filter .form-group{width:100%}}
</style>
@endpush
<div class="modal fade" id="finance-account-activity" tabindex="-1" role="dialog" aria-labelledby="finance-account-activity-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('finance.cancel') }}">&times;</button><h4 class="modal-title" id="finance-account-activity-title">{{ __('finance.paypal_profile.activity') }}</h4></div>
        <div class="modal-body">
            <div class="alert" data-activity-message role="status" aria-live="polite" hidden></div>
            @include('portals.finance.partials.activity-report')
            @if($canManage)
            <div class="box" id="finance-transaction-sync"><div class="box-header with-border"><h3 class="box-title">{{ __('finance.paypal.balance_changes') }}</h3></div><div class="box-body">
                <p class="finance-note">{{ __('finance.sync.transactions_help') }}</p>
                <p class="finance-note" data-activity-sync-unavailable hidden>{{ __('finance.activity.sync_unavailable') }}</p>
                <form method="post" id="finance-activity-sync" class="finance-filter" data-loading="off">
                    @csrf
                    <div class="form-group finance-activity-account"><label>{{ __('finance.fields.account') }}</label><p data-activity-account-name></p></div>
                    <div class="form-group"><label for="activity-sync-from">{{ __('finance.fields.from') }}</label><input id="activity-sync-from" type="date" name="from" class="form-control" value="{{ $filters['from'] }}" required></div>
                    <div class="form-group"><label for="activity-sync-to">{{ __('finance.fields.to') }}</label><input id="activity-sync-to" type="date" name="to" class="form-control" value="{{ $filters['to'] }}" required></div>
                    <div class="form-group"><label for="activity-sync-timezone">{{ __('finance.fields.source_timezone') }}</label><select id="activity-sync-timezone" name="timezone" class="form-control">@foreach(\App\Portals\Finance\FinanceWorkbookService::TIMEZONES as $timezone)<option @selected($timezone === $filters['timezone'])>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($timezone)) }}</option>@endforeach</select></div>
                    <div class="form-group"><label><input type="checkbox" name="export_after_sync" value="1"> {{ __('finance.activity.export_after_sync') }}</label><button class="btn btn-primary" data-activity-sync-submit disabled>{{ __('finance.sync.sync') }}</button></div>
                </form>
            </div></div>
            @endif
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('finance.activity.close') }}</button></div>
    </div></div>
</div>
<script type="application/json" id="finance-payment-account-activities">@json($paymentAccountActivities)</script>
<script type="application/json" id="finance-activity-copy">@json(__('finance.activity'))</script>
