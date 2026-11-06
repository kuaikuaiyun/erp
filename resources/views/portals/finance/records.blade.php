@extends('portals.finance.layout', ['title' => __('finance.menus.'.$dataset)])
@push('styles')
@if(in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))
<link rel="stylesheet" href="{{ asset('assets/erp/logistics.css') }}?v=20261024-8">
<link rel="stylesheet" href="{{ asset('assets/erp/logistics-order-detail.css') }}?v=20261018-1">
<style>
.paypal-order-toolbar{flex-wrap:wrap}.paypal-order-toolbar .finance-pages{margin:0}
.logistics-orders-table.paypal-order-table{width:100%;min-width:1493px;table-layout:fixed}
.logistics-orders-table.paypal-order-table th,.logistics-orders-table.paypal-order-table td{white-space:normal}
.logistics-orders-table.paypal-order-table th:first-child,.logistics-orders-table.paypal-order-table td:first-child{width:68px;min-width:68px;max-width:68px}
.logistics-orders-table.paypal-order-table th:nth-child(2),.logistics-orders-table.paypal-order-table td:nth-child(2){width:190px;min-width:175px;max-width:none}
.logistics-orders-table.paypal-order-table th:nth-child(6),.logistics-orders-table.paypal-order-table td:nth-child(6){width:340px;min-width:280px;max-width:none}
.logistics-orders-table.paypal-order-table th:last-child{width:130px}
.logistics-orders-table.paypal-order-table th:nth-child(5){width:160px}
.logistics-table.paypal-order-table tbody td{vertical-align:middle!important;padding-top:10px!important;padding-bottom:10px!important}
.paypal-order-table .paypal-order-number,.paypal-order-table .paypal-order-origin,.paypal-order-table tbody td:last-child{text-align:center}
#logistics-order-detail-modal [data-order-detail-edit]{display:none!important}
#logistics-order-detail-modal .modal-dialog{width:min(1320px,calc(100vw - 32px));max-width:none;margin:18px auto}
#logistics-order-detail-modal .modal-body{padding:0;container-type:inline-size}
@container (max-width:1040px){
    #logistics-order-detail-modal .logistics-order-detail-item{grid-template-columns:58px minmax(0,1fr)}
    #logistics-order-detail-modal .logistics-order-detail-item-metrics{grid-column:1/-1}
}
@media(max-width:650px){#logistics-order-detail-modal .modal-dialog{width:calc(100vw - 12px);margin:6px auto}}
.paypal-detail-body{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;padding:18px;overflow:auto;max-height:70vh}
.paypal-detail-section{border:1px solid #dce6ef;border-radius:5px;padding:12px;min-width:0}
.paypal-detail-section h4{margin:0 0 10px;font-size:14px}.paypal-detail-section dl{display:grid;grid-template-columns:100px minmax(0,1fr);gap:4px 8px;margin:0}.paypal-detail-section dd{margin:0;overflow-wrap:anywhere;white-space:pre-line}
.paypal-detail-wide{grid-column:1/-1}@media(max-width:650px){.paypal-detail-body{grid-template-columns:1fr}}
.paypal-order-fields{display:grid;grid-template-columns:var(--paypal-label-width,68px) minmax(0,1fr);column-gap:6px;margin:0;font-size:12px;line-height:20px}
.paypal-order-table .logistics-order-identifiers{--paypal-label-width:40px}
.paypal-order-table .logistics-order-identifiers .paypal-order-fields{display:block}
.paypal-order-table .logistics-order-identifiers .paypal-order-fields dt{float:left;clear:left;margin-right:3px}
.paypal-order-table .logistics-order-identifiers .paypal-order-fields dd{display:flow-root;margin:0}
.paypal-order-table .logistics-order-identifiers .paypal-order-fields dd{overflow:visible;text-overflow:clip;white-space:normal;overflow-wrap:anywhere;word-break:break-word}
.paypal-order-table .logistics-subscription-information{--paypal-label-width:62px}
.paypal-order-table .logistics-payment-information{--paypal-label-width:80px}
.paypal-order-table .logistics-sale-product-details{--paypal-label-width:58px}
.paypal-order-fields dt{font-weight:800;color:#45617c;white-space:nowrap}
.paypal-order-fields dd{min-width:0;margin:0;color:#58738d;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.paypal-order-fields dd.is-positive{color:#168553;font-weight:700}
.paypal-order-fields dd.is-subscription{color:#5b54a6;font-weight:700}
.paypal-order-table .paypal-order-number,.paypal-order-table .paypal-order-origin{font-size:12px;color:#58738d;line-height:20px}
.paypal-order-table .logistics-sale-product-detail-code,.paypal-order-table .logistics-sale-product-detail-skus,.paypal-order-table .logistics-sale-product-detail-metrics,.paypal-order-table .logistics-sale-product-detail-name{font-size:12px;line-height:20px}
.paypal-order-table .logistics-sale-product-detail-metrics{flex-wrap:wrap;column-gap:10px;row-gap:0;white-space:normal}
.paypal-order-table .logistics-sale-product-detail-item + .logistics-sale-product-detail-item{margin-top:6px;padding-top:6px}
.logistics-filter-form .logistics-filter-actions select{width:155px}.paypal-filter-extra{margin-top:14px}.paypal-filter-extra .finance-condition-hint{margin:0 0 8px}
.paypal-filter-extra .finance-condition{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0}
.paypal-filter-extra .finance-condition select,.paypal-filter-extra .finance-condition input{width:240px}

.logistics-filter-box .logistics-filter-grid{grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:12px 24px;align-items:start}
.logistics-filter-box .logistics-filter-field{grid-template-columns:132px minmax(0,1fr);gap:10px;align-items:center;min-width:0}
.logistics-filter-box .logistics-filter-field>label{margin:0;text-align:right;line-height:20px;white-space:normal;overflow-wrap:anywhere}
.logistics-filter-box .logistics-filter-field .form-control{width:100%;min-width:0;margin:0}
.logistics-filter-box .form-control{height:34px;font-size:12px}
@media(max-width:767px){.logistics-filter-box .logistics-filter-grid{grid-template-columns:minmax(0,1fr);gap:12px}.logistics-filter-box .logistics-filter-field{grid-template-columns:132px minmax(0,1fr)}}
@media(max-width:420px){.logistics-filter-box .logistics-filter-field{grid-template-columns:minmax(0,1fr);gap:5px}.logistics-filter-box .logistics-filter-field>label{text-align:left}}
.logistics-filter-box .box-body{padding:14px 20px}
#finance-filters:not(.paypal-filters-expanded) .paypal-extra-filter{display:none}
#finance-filters .paypal-date-filters{display:flex;flex-wrap:nowrap;align-items:center;overflow-x:auto;margin-top:16px;padding:16px 0 4px;gap:20px;border-top:1px solid #edf1f5}
#finance-filters:not(.paypal-filters-expanded) .paypal-date-filters .logistics-date-range{border-left:0;padding-left:0}
#finance-filters .logistics-filter-actions{display:flex;align-items:center;gap:8px;padding-top:12px;margin-top:12px}
#finance-filters .logistics-filter-actions .form-control{margin:0;width:155px}

.logistics-orders-table.paypal-order-table .paypal-order-selection{width:38px;min-width:38px;max-width:38px;text-align:center}
.logistics-orders-table.paypal-order-table th:nth-child(2),.logistics-orders-table.paypal-order-table td:nth-child(2){width:68px;min-width:68px;max-width:68px}
.logistics-orders-table.paypal-order-table th:nth-child(3),.logistics-orders-table.paypal-order-table td:nth-child(3){width:190px;min-width:175px;max-width:none}
.logistics-orders-table.paypal-order-table th:nth-child(6),.logistics-orders-table.paypal-order-table td:nth-child(6){width:160px;min-width:160px;max-width:none}
.logistics-orders-table.paypal-order-table th:nth-child(7),.logistics-orders-table.paypal-order-table td:nth-child(7){width:340px;min-width:280px;max-width:none}

#finance-filters .paypal-date-filters .logistics-date-range{display:grid;grid-template-columns:auto minmax(0,1fr);flex:1 0 355px;align-items:center;gap:10px;border:0;padding:0}
#finance-filters .paypal-date-filters .logistics-date-range>label{margin:0;text-align:right;line-height:20px}
#finance-filters .paypal-date-filters .logistics-date-range>div{display:grid;grid-template-columns:minmax(0,1fr) 14px minmax(0,1fr);gap:8px;align-items:center}
#finance-filters .paypal-date-filters .logistics-date-range>div>span{text-align:center}
@media(max-width:600px){#finance-filters .paypal-date-filters .logistics-date-range{flex-basis:355px}}
.paypal-order-toolbar select[name=export_format]{width:155px;height:32px;flex:0 0 155px;margin:0}
.finance-system .paypal-order-toolbar{align-items:center;gap:12px}
.finance-system .paypal-order-toolbar.has-order-refresh .box-title{margin-right:0}
.finance-system .paypal-order-toolbar #finance-order-refresh-open{margin-right:auto}
.finance-system .paypal-order-toolbar .finance-numbered-pages{padding-top:0;margin:0;align-items:center}
.paypal-order-toolbar>.btn,.paypal-order-toolbar .finance-export-columns>summary{box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;height:32px;margin:0;padding:5px 12px;line-height:20px}
.paypal-order-toolbar .finance-export-columns{align-self:center;margin:0}
.finance-export-columns{position:relative}.finance-export-columns>summary{list-style:none}.finance-export-columns>summary::-webkit-details-marker{display:none}
.finance-export-column-panel{position:absolute;right:0;top:38px;width:560px;max-width:calc(100vw - 40px);padding:16px;background:#fff;border:1px solid #dce6ef;border-radius:6px;box-shadow:0 8px 24px #17324d26;z-index:20}
.finance-export-column-actions{display:flex;align-items:center;gap:8px;margin-bottom:12px}.finance-export-column-actions strong{margin-right:auto}
.finance-export-column-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;max-height:320px;overflow-y:auto}.finance-export-column-grid label{font-size:12px;font-weight:400;margin:0;overflow-wrap:anywhere}.finance-export-column-panel p{margin:12px 0 0;font-size:12px}

.logistics-orders-table.paypal-order-table{width:3350px;min-width:3350px}
.logistics-orders-table.paypal-order-table th:nth-child(n+9),.logistics-orders-table.paypal-order-table td:nth-child(n+9){width:210px;min-width:160px;max-width:250px}
.logistics-orders-table.paypal-order-table th:last-child,.logistics-orders-table.paypal-order-table td:last-child{width:100px;min-width:100px}
.logistics-orders-table.paypal-order-table th:nth-child(9),.logistics-orders-table.paypal-order-table td:nth-child(9){width:180px;min-width:180px;max-width:180px}
.logistics-orders-table.paypal-order-table th:last-child,.logistics-orders-table.paypal-order-table td:last-child:not([colspan]){position:sticky;right:0;width:100px;min-width:100px;max-width:100px;box-shadow:-2px 0 5px rgba(45,69,94,.08)}
.logistics-orders-table.paypal-order-table td{white-space:normal;overflow:visible;text-overflow:clip;overflow-wrap:anywhere}
.logistics-orders-table.paypal-order-table .paypal-order-fields dd{overflow:visible;text-overflow:clip;white-space:normal;overflow-wrap:anywhere;word-break:break-word}
.paypal-order-table .logistics-sale-product-detail-code>span,.paypal-order-table .logistics-sale-product-detail-sku>span{min-width:0;overflow:visible;text-overflow:clip;white-space:normal;overflow-wrap:anywhere}
.logistics-orders-table.paypal-order-table:not(.show-extra-columns){width:100%;min-width:1476px;table-layout:fixed}
.logistics-orders-table.paypal-order-table:not(.show-extra-columns) th:nth-child(n+9):not(:last-child),.logistics-orders-table.paypal-order-table:not(.show-extra-columns) td:nth-child(n+9):not(:last-child):not([colspan]){display:none}
.logistics-orders-table.paypal-order-table:not(.show-extra-columns) th:nth-child(4),.logistics-orders-table.paypal-order-table:not(.show-extra-columns) td:nth-child(4){width:240px;min-width:240px;max-width:none}
.logistics-orders-table.paypal-order-table:not(.show-extra-columns) th:nth-child(5),.logistics-orders-table.paypal-order-table:not(.show-extra-columns) td:nth-child(5){width:150px;min-width:150px;max-width:none}
.logistics-orders-table.paypal-order-table:not(.show-extra-columns) th:nth-child(6),.logistics-orders-table.paypal-order-table:not(.show-extra-columns) td:nth-child(6){width:130px;min-width:130px;max-width:none}
.logistics-orders-table.paypal-order-table:not(.show-extra-columns) th:nth-child(8),.logistics-orders-table.paypal-order-table:not(.show-extra-columns) td:nth-child(8){width:220px;min-width:220px;max-width:none}
</style>
@endif
<style>
.finance-system .finance-records-panel{border-top:0;border-radius:3px;background:#fff}
.finance-system .finance-records-panel .box-header{background:#fff;padding:12px 16px}
.finance-system .finance-records-panel .box-title{font-size:17px;font-weight:400}
.finance-system .finance-records-panel .box-body{padding:20px 22px 14px}
.finance-system .finance-records-filter{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px 28px;margin:0}
.finance-system .finance-records-filter .form-group{display:grid;grid-template-columns:104px minmax(0,1fr);align-items:center;min-width:0;margin:0;width:auto}
.finance-system .finance-records-panel .finance-records-filter .form-group>label{margin:0;padding-right:12px;text-align:right;font-size:13px;font-weight:400;line-height:35px;white-space:nowrap}
.finance-system .finance-records-filter .finance-date-range{grid-column:span 2}
.finance-system .finance-date-inputs{display:grid;grid-template-columns:minmax(0,1fr) 104px minmax(0,1fr);align-items:center;gap:14px}
.finance-system .finance-date-inputs span{text-align:center;color:#8395a7}
.finance-system .finance-records-panel .form-control{width:100%;height:35px;border:1px solid transparent;border-radius:0;background:#f1f3f6;box-shadow:none;font-size:13px}
.finance-system .finance-records-panel .form-control:focus{border-color:#338dc7;background:#fff}
.finance-system .finance-records-panel .form-control::placeholder{color:#97a3af}
.finance-system .finance-condition-hint{margin:18px 0 10px 104px;font-size:12px;color:#8493a2}
.finance-system .finance-records-panel #finance-conditions{display:grid;grid-template-columns:1fr;gap:12px;margin-left:104px}
.finance-system .finance-records-panel .finance-condition{display:grid;grid-template-columns:calc((100% - 264px)/3) 108px minmax(0,1fr) 60px;gap:12px;margin:0;align-items:center}
.finance-system .finance-records-panel .finance-condition .form-control{min-width:0;max-width:none}
.finance-system .finance-records-panel .finance-condition .btn,.finance-system .finance-filter-actions .btn{height:35px;display:inline-flex;align-items:center;justify-content:center;gap:4px;margin:0;white-space:nowrap}
.finance-system .finance-filter-actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px;border-top:1px solid #e8edf2;margin:18px 0 0;padding-top:14px}
.finance-system .finance-filter-actions .form-control{width:155px}
.finance-system .finance-records-data .box-body{padding:16px 22px}
.finance-system .finance-records-data .finance-table th:first-child,.finance-system .finance-records-data .finance-table td:first-child{width:36px;min-width:36px;max-width:36px;padding:8px;text-align:center;vertical-align:middle!important}
.finance-system .finance-records-data .finance-table input[type=checkbox]{margin:0;vertical-align:middle}
.finance-system .finance-records-data .finance-table-wrap{max-height:650px;overflow:auto;border:1px solid #e1eaf2;scrollbar-gutter:stable}
.finance-system .finance-records-data .finance-table{margin:0;width:max-content;min-width:100%;border-collapse:separate;border-spacing:0}
.finance-system .finance-records-data .finance-table th{position:sticky;top:0;z-index:2;padding:10px 12px;background:#eaf2f8;border-top:0}
.finance-system .finance-records-data .finance-table td{height:60px;padding:10px 12px;min-width:120px;max-width:240px;line-height:19px;background:#fff}
.finance-system .finance-records-data .finance-table tr:nth-child(even) td{background:#f8fafc}
.finance-system .finance-records-data .finance-table tr:hover td{background:#edf6fc}
.finance-system .finance-records-data .finance-table th:first-child,.finance-system .finance-records-data .finance-table td:first-child{position:sticky;left:0;z-index:3}
.finance-system .finance-records-data .finance-table th:nth-child(2),.finance-system .finance-records-data .finance-table td:nth-child(2){position:sticky;left:36px;z-index:3;box-shadow:2px 0 3px #173f6110}
.finance-system .finance-records-data .finance-table th:nth-child(-n+2){z-index:4}
.finance-record-cell{display:block;width:150px;max-height:38px;overflow:hidden;overflow-wrap:anywhere;white-space:pre-line;line-height:19px}
button.finance-record-cell{border:0;padding:0;background:transparent;text-align:left;color:inherit;font:inherit;line-height:19px;cursor:pointer;text-decoration:none}
button.finance-record-cell:hover{color:inherit;text-decoration:none}
button.finance-record-cell:focus-visible{outline:2px solid #338dc7;outline-offset:2px}
.finance-system .finance-records-data .finance-table .finance-cell-number{text-align:right;font-variant-numeric:tabular-nums}
.finance-cell-number .finance-record-cell{text-align:right}
.finance-system .finance-records-data .finance-table .finance-cell-id{min-width:210px;max-width:270px}
.finance-cell-id .finance-record-cell{width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.finance-record-detail{width:620px;max-width:calc(100vw - 32px);max-height:80vh;border:0;border-radius:8px;padding:0;color:#3b5870;box-shadow:0 16px 60px #173f6140}
.finance-record-detail::backdrop{background:#102c4866}.finance-record-detail header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5edf3}.finance-record-detail h3{margin:0;font-size:16px}.finance-record-detail header button{border:0;background:none;font-size:24px;color:#8393a1}.finance-record-detail pre{white-space:pre-wrap;overflow-wrap:anywhere;padding:20px;margin:0;border:0;background:#fff;max-height:60vh;overflow:auto;font:inherit;line-height:1.8}
@media(max-width:1100px){.finance-system .finance-records-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.finance-system .finance-records-panel .finance-condition{grid-template-columns:minmax(0,1fr) 108px minmax(0,1fr) 60px}}
@media(max-width:650px){.finance-system .finance-records-filter .finance-date-range{grid-column:auto}.finance-system .finance-date-inputs{grid-template-columns:minmax(0,1fr);gap:5px}.finance-system .finance-date-inputs span{display:none}}
@media(max-width:650px){.finance-system .finance-records-filter{grid-template-columns:1fr}.finance-system .finance-records-panel .box-body{padding:16px 12px}.finance-system .finance-records-filter .form-group{grid-template-columns:90px minmax(0,1fr)}.finance-system .finance-condition-hint{margin-left:90px}.finance-system .finance-records-panel #finance-conditions{margin-left:90px}.finance-system .finance-records-panel .finance-condition{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.finance-system .finance-records-panel .finance-condition button{justify-self:start}}
</style>
<style>.finance-system .finance-records-panel .finance-condition{grid-template-columns:minmax(160px,1fr) minmax(180px,2fr) 60px}.finance-system .finance-records-data .finance-table th:first-child,.finance-system .finance-records-data .finance-table td:first-child{width:auto;min-width:120px;max-width:240px;padding:10px 12px;text-align:left}.finance-system .finance-records-data .finance-table th:nth-child(2),.finance-system .finance-records-data .finance-table td:nth-child(2){position:static;box-shadow:none}@media(max-width:650px){.finance-system .finance-records-panel .finance-condition{grid-template-columns:minmax(0,1fr) 60px}.finance-condition [data-filter-field]{grid-column:1/-1}}</style>
@endpush
@section('finance_content')
@if($dataset === 'xuzong2-orders')
<p class="finance-note">数据同步自物流系统的信用卡订单，支持查询与导出。订单信息请在物流系统维护。</p>
@elseif(! in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))
<p class="finance-note">{{ __($canImport ? 'finance.records.note' : 'finance.records.order_note') }}</p>
<p class="finance-note">{{ __('finance.records.'.$definition['channel'].'_note') }}</p>
@endif
<div class="box {{ in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true) ? 'box-solid logistics-filter-box' : 'box-primary finance-records-panel' }}" ><div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter" aria-hidden="true"></i> {{ in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true) ? '筛选条件' : __('finance.records.filter_title') }}</h3></div><div class="box-body">
<form method="get" id="finance-filters" @if(in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true)) class="logistics-filter-form" @endif>
    <input type="hidden" name="per_page" value="{{ $records->perPage() }}">
    <div class="{{ in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true) ? 'logistics-filter-grid' : 'finance-filter finance-records-filter' }}">
        @if(in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))
            @foreach(\App\Portals\Finance\FinanceCatalog::PAYPAL_ORDER_FILTERS as $field => $label)
                @php
                    $commonField = in_array($field, ['id','shopify_order_number','tongtu_order_number','store_name','subscription_type','column_18','domain','column_13','currency','status'], true);
                @endphp
                @continue(preg_match('/_(from|to)$/', $field))
                <div class="logistics-filter-field {{ $commonField ? '' : 'paypal-extra-filter' }}" style="order:{{ $loop->index * 10 }}">
                    <label for="paypal-field-{{ $field }}">{{ $label }}</label>
                    @if(in_array($field, ['currency', 'status'], true) || isset($fixedFilterOptions[$field]))
                        <select id="paypal-field-{{ $field }}" class="form-control" name="order_fields[{{ $field }}]">
                            <option value="">全部</option>
                            @foreach($fixedFilterOptions[$field] ?? $conditionChoices[$field] ?? [] as $option)
                                <option value="{{ $option['value'] }}" @selected(($filters['order_fields'][$field] ?? '') === $option['value'])>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    @else
                        <input id="paypal-field-{{ $field }}" class="form-control" name="order_fields[{{ $field }}]" value="{{ $filters['order_fields'][$field] ?? '' }}" placeholder="请输入{{ $label }}" maxlength="191">
                    @endif
                </div>
            @endforeach
        @endif
        <div class="{{ in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true) ? 'logistics-filter-field' : 'form-group' }}"  style="order:195"><label for="filter-account">{{ __($definition['channel'] === 'paypal' ? 'finance.fields.paypal_account' : 'finance.fields.account') }}</label><select id="filter-account" class="form-control" name="account"><option value="">{{ __('finance.records.all') }}</option>@foreach($accounts as $account)<option value="{{ $account }}" @selected(($filters['account'] ?? '') === $account)>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($accountLabels->get($account, $account))) }}</option>@endforeach</select></div>
        <div class="{{ in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true) ? 'logistics-filter-field' : 'form-group' }}"  style="order:1000"><label for="filter-timezone">{{ __('finance.fields.timezone') }}</label><select id="filter-timezone" name="timezone" class="form-control">@foreach($timezones as $timezone)<option value="{{ $timezone }}" @selected($filters['timezone'] === $timezone)>{{ \App\Portals\Finance\FinanceCatalog::timezoneLabel($timezone) }}</option>@endforeach</select></div>
        <div class="{{ in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true) ? 'logistics-filter-field' : 'form-group' }}"  style="order:295"><label for="filter-reference">{{ __('finance.fields.reference') }}</label><input id="filter-reference" placeholder="请输入交易 / 平台单号" class="form-control" name="reference" value="{{ $filters['reference'] ?? '' }}" maxlength="191"></div>
        @if($definition['channel'] === 'paypal')<div class="{{ in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true) ? 'logistics-filter-field' : 'form-group' }}"  style="order:1010"><label for="filter-transaction-scope">{{ __('finance.fields.transaction_scope') }}</label><select id="filter-transaction-scope" class="form-control" name="transaction_scope">@foreach(__('finance.records.scopes') as $scope => $label)<option value="{{ $scope }}" @selected(($filters['transaction_scope'] ?? 'all') === $scope)>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($label)) }}</option>@endforeach</select></div>@endif
        @if(! in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))<div class="form-group finance-date-range"><label for="filter-from">日期区间</label><div class="finance-date-inputs"><input id="filter-from" type="date" class="form-control" name="from" value="{{ $filters['from'] ?? '' }}" aria-label="{{ __('finance.fields.from') }}"><span aria-hidden="true">—</span><input id="filter-to" type="date" class="form-control" name="to" value="{{ $filters['to'] ?? '' }}" aria-label="{{ __('finance.fields.to') }}"></div></div>@endif
    </div>
    @if(in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))
        <div class="logistics-filter-date-row paypal-date-filters">
            @foreach(['refunded'=>'实际退款时间','ordered'=>'下单时间','paid'=>'付款时间'] as $prefix => $label)
                <div class="logistics-filter-field logistics-date-range paypal-extra-filter"><label>{{ $label }}</label><div>
                    <input type="date" class="form-control" name="order_fields[{{ $prefix }}_from]" value="{{ $filters['order_fields'][$prefix.'_from'] ?? '' }}">
                    <span>—</span>
                    <input type="date" class="form-control" name="order_fields[{{ $prefix }}_to]" value="{{ $filters['order_fields'][$prefix.'_to'] ?? '' }}">
                </div></div>
            @endforeach

            <div class="logistics-filter-field logistics-date-range"><label for="filter-from">日期区间</label><div ><input id="filter-from" type="date" class="form-control" name="from" value="{{ $filters['from'] ?? '' }}" aria-label="{{ __('finance.fields.from') }}"><span aria-hidden="true">—</span><input id="filter-to" type="date" class="form-control" name="to" value="{{ $filters['to'] ?? '' }}" aria-label="{{ __('finance.fields.to') }}"></div></div>
        </div>
        <div class="paypal-filter-extra">
    @endif

    @if(! in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))
    <p class="finance-note finance-condition-hint">{{ __('finance.records.custom_filter_hint') }}</p>
    <div id="finance-conditions">
    @foreach($filters['conditions'] ?? [] as $index => $condition)<div class="finance-condition">
        <select required class="form-control" data-filter-field name="conditions[{{ $index }}][field]" aria-label="{{ __('finance.records.condition') }}"><option value="">{{ __('finance.records.choose_field') }}</option>@foreach($filterFields as $field => $fieldLabel)<option value="{{ $field }}" @selected($condition['field'] === $field)>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($fieldLabel)) }}</option>@endforeach</select>
        <input type="hidden" data-filter-operator value="equals">
        <input class="form-control" data-filter-value name="conditions[{{ $index }}][value]" value="{{ $condition['value'] ?? '' }}" maxlength="191" aria-label="{{ __('finance.records.filter_value') }}"><button class="btn btn-default" type="button" data-remove-filter>{{ __('finance.records.remove_filter') }}</button>
    </div>@endforeach
    </div>
    @endif
    @if(in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))</div>@endif
    <div class="{{ in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true) ? 'logistics-filter-actions' : 'finance-tools finance-filter-actions' }}">@if(in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))<button type="button" class="btn btn-default" id="paypal-toggle-filters" aria-expanded="false">更多筛选</button>@endif <button class="btn btn-primary"><i class="fa fa-search" aria-hidden="true"></i> {{ __('finance.search') }}</button><a class="btn btn-default" href="{{ route('finance.records', $dataset) }}">{{ __('finance.reset') }}</a>@if(! in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))<button type="button" class="btn btn-default" id="finance-add-filter">{{ __('finance.records.add_filter') }}</button>@endif
@if(! in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))<a id="finance-export-filtered" data-finance-select-export data-selection-records class="btn btn-default" href="{{ route('finance.records.export', ['dataset' => $dataset, ...$filters, 'export_scope' => 'filtered', 'export_format' => 'xlsx']) }}">导出当前筛选结果</a>@endif @if(! in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))<select name="export_format" class="form-control" aria-label="{{ __('finance.records.export_format') }}">@foreach(__('finance.records.formats') as $format => $label)<option value="{{ $format }}" @selected(($filters['export_format'] ?? 'xlsx') === $format)>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($label)) }}</option>@endforeach</select>@endif @if($canImport)<a class="btn btn-default" href="{{ route('finance.records.export', ['dataset' => $dataset, 'template' => 1]) }}">{{ __('finance.template') }}</a>@endif</div>
</form>
</div></div>
<div class="box {{ in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true) ? 'box-solid logistics-table-box' : 'finance-records-data' }}">
@if(! in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))<div class="box-body">@endif
<datalist id="finance-categories">@foreach($categories as $category)<option value="{{ $category }}">@endforeach</datalist>

@if(in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))
@include('portals.finance.partials.paypal-order-list')
@else
<p>{{ __('finance.records.count', ['count' => $records->total()]) }}</p>
<div class="finance-table-wrap"><table class="table table-hover table-bordered finance-table"><thead><tr><th class="finance-selection"><input type="checkbox" data-export-all aria-label="全选当前页"></th>
    <th>{{ __('finance.fields.account') }}</th>@if($dataset !== 'xuzong2-orders')<th>{{ __('finance.fields.category') }}</th>@endif<th>时区</th>
    @foreach($definition['headers'] as $header)<th>{{ \App\Portals\Finance\FinanceCatalog::simplified($header) }}</th>@endforeach
    @if(in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))<th>收支方向</th><th>月度人民币汇率</th><th>折合人民币</th>@endif
</tr></thead><tbody>
@forelse($records as $record)
    @php
        $group = $groups->get($record->domain);
        $accounting = $accountingTransactions->get($record->id);
    @endphp
    <tr><td class="finance-selection"><input type="checkbox" data-export-row value="{{ $record->id }}" aria-label="选择此行"></td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($accountLabels->get($record->account, $record->account))) }}</td>@if($dataset !== 'xuzong2-orders')<td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($accounting ? ($accounting->category ?: __('finance.records.unclassified')) : ($record->raw_type ? ($types->get($record->raw_type) ?: __('finance.records.unclassified')) : '—'))) }}</td>@endif<td>{{ \App\Portals\Finance\FinanceCatalog::timezoneLabel($filters['timezone']) }}</td>
    @foreach($definition['headers'] as $index => $header)
        @php
            $cellValue = $group && $index === ($definition['owner'] ?? -1) ? $group->owner
                : ($group && $index === ($definition['team'] ?? -1) ? $group->team
                : ($definition['channel'] === 'paypal' && $index === ($definition['type'] ?? -1)
                    ? \App\Portals\Finance\FinancePayPalEventTypes::label((string) ($record->payload[$header] ?? ''))
                    : \App\Portals\Finance\FinanceCatalog::displayValue($dataset, $index, $record->payload[$header] ?? '')));
        @endphp
        <td @class(['finance-cell-number' => in_array('column_'.$index, \App\Portals\Finance\FinanceCatalog::numericFilterFields($dataset), true) || \App\Portals\Finance\FinanceCatalog::simplified($header) === '数量', 'finance-cell-id' => preg_match('/ID|编号|号码|单号/u', \App\Portals\Finance\FinanceCatalog::simplified($header))])>@if(mb_strlen($cellValue) > 16 || str_contains($cellValue, "\n"))<button type="button" class="finance-record-cell" data-cell-detail data-cell-title="{{ \App\Portals\Finance\FinanceCatalog::simplified($header) }}" data-cell-value="{{ $cellValue }}" title="点击查看完整内容" aria-haspopup="dialog">{{ $cellValue }}</button>@else<span class="finance-record-cell">{{ $cellValue }}</span>@endif</td>
    @endforeach
    @if(in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))<td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($accounting?->direction ? __('finance.accounting.directions.'.$accounting->direction) : '未分类')) }}</td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($accounting?->monthly_rate ?? '待配置')) }}</td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($accounting?->cny_amount ?? '待配置')) }}</td>@endif
    </tr>
@empty<tr><td colspan="{{ count($definition['headers']) + 1 + (in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true) ? 6 : ($dataset === 'xuzong2-orders' ? 2 : 3)) }}">{{ __('finance.records.empty') }}</td></tr>@endforelse
</tbody></table></div>
@include('portals.finance.pagination', ['paginator' => $records])
</div>@endif
</div>
<dialog class="finance-record-detail" id="finance-record-detail"><header><h3></h3><button type="button" aria-label="关闭">&times;</button></header><pre></pre></dialog>
@if(in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))
@include('portals.logistics.partials.order-detail-modal')
@endif
@if($canManage && $canImport)
<div class="box"><div class="box-header with-border"><h3 class="box-title">{{ __('finance.import') }}</h3></div><div class="box-body">
    <p class="finance-note">{{ __('finance.records.import_note') }}</p>
    <form method="post" action="{{ route('finance.records.import', $dataset) }}" enctype="multipart/form-data" class="finance-filter">@csrf
        <div class="form-group"><label for="import-account">{{ __($definition['channel'] === 'paypal' ? 'finance.fields.paypal_account' : 'finance.fields.account') }}</label><input id="import-account" name="account" list="finance-account-options" class="form-control" value="{{ old('account') }}" required maxlength="191"><datalist id="finance-account-options">@foreach($accounts as $account)<option value="{{ $account }}">@endforeach</datalist></div>
        <div class="form-group"><label for="source-timezone">{{ __('finance.fields.source_timezone') }}</label><select id="source-timezone" name="source_timezone" class="form-control">@foreach($timezones as $timezone)<option @selected(old('source_timezone', 'Asia/Shanghai') === $timezone)>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($timezone)) }}</option>@endforeach</select></div>
        <div class="form-group"><label for="import-file">{{ __('finance.fields.file') }}</label><input id="import-file" type="file" name="file" accept=".xlsx,.csv,.tsv,.txt" required></div><div class="form-group"><button class="btn btn-primary">{{ __('finance.import') }}</button></div>
    </form>
</div></div>
@endif
<template id="finance-condition-template"><div class="finance-condition"><select required class="form-control" data-filter-field aria-label="{{ __('finance.records.condition') }}"><option value="">{{ __('finance.records.choose_field') }}</option>@foreach($filterFields as $field => $fieldLabel)<option value="{{ $field }}">{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($fieldLabel)) }}</option>@endforeach</select><input type="hidden" data-filter-operator value="equals"><input class="form-control" data-filter-value maxlength="191" aria-label="{{ __('finance.records.filter_value') }}"><button type="button" class="btn btn-default" data-remove-filter>{{ __('finance.records.remove_filter') }}</button></div></template>
@endsection
@push('scripts')
<script src="{{ asset('assets/erp/finance-download.js') }}?v=2"></script>
<script>
(function(){
    var toggle=document.getElementById('paypal-toggle-filters'), filterForm=document.getElementById('finance-filters');
    if(toggle){
        function expandFilters(expanded){filterForm.classList.toggle('paypal-filters-expanded',expanded);toggle.setAttribute('aria-expanded',String(expanded));toggle.textContent=expanded?'收起筛选':'更多筛选';}
        expandFilters(Array.from(filterForm.querySelectorAll('.paypal-extra-filter input,.paypal-extra-filter select')).some(function(input){return input.value!=='';}));
        toggle.addEventListener('click',function(){expandFilters(!filterForm.classList.contains('paypal-filters-expanded'));});
    }
    var allOrders=document.getElementById('paypal-order-check-all'), selectedExport=document.getElementById('paypal-export-selected');
    if(allOrders && selectedExport){
        var checks=Array.from(document.querySelectorAll('.paypal-order-check'));
        function refreshSelection(){
            var count=checks.filter(function(input){return input.checked;}).length;
            allOrders.checked=checks.length>0 && count===checks.length;
            allOrders.indeterminate=count>0 && count<checks.length;
            selectedExport.disabled=count===0;
            selectedExport.textContent=count?'导出所选（'+count+'）':'导出所选';
            document.getElementById('finance-export-filtered').title=count?'导出勾选的 '+count+' 条订单':'导出当前筛选结果';
        }
        allOrders.addEventListener('change',function(){checks.forEach(function(input){input.checked=allOrders.checked;});refreshSelection();});
        checks.forEach(function(input){input.addEventListener('change',refreshSelection);});
        selectedExport.addEventListener('click',function(){
            if(!document.getElementById('finance-filters').reportValidity())return;
            var query=new URLSearchParams(new FormData(document.getElementById('finance-filters')));
            query.set('export_scope','records');query.set('export_format',document.querySelector('[name="export_format"]').value);
            checks.filter(function(input){return input.checked;}).forEach(function(input){query.append('selected_ids[]',input.value);});
            var url=new URL(document.getElementById('finance-export-filtered').href);
            url.search=query.toString();window.financeDownload(url.toString(),selectedExport);
        });
        refreshSelection();
    }
    var dialog=document.getElementById('finance-record-detail');
    document.querySelectorAll('[data-cell-detail]').forEach(function(button){button.addEventListener('click',function(){dialog.querySelector('h3').textContent=button.dataset.cellTitle;dialog.querySelector('pre').textContent=button.dataset.cellValue;dialog.showModal();});});
    dialog.querySelector('button').addEventListener('click',function(){dialog.close();});
    var paypalModal=document.getElementById('logistics-order-detail-modal');
    if(paypalModal) document.querySelectorAll('[data-paypal-detail]:not([data-order-detail-url])').forEach(function(button){
        button.addEventListener('click',function(){
            var template=document.getElementById(button.dataset.paypalDetail);
            if(!template || !window.jQuery)return;
            paypalModal.querySelector('[data-order-detail-loading]').hidden=true;
            paypalModal.querySelector('[data-order-detail-error]').hidden=true;
            var content=paypalModal.querySelector('[data-order-detail-content]');
            content.hidden=false;
            var fallback=document.createElement('div');
            fallback.className='paypal-detail-body';
            fallback.appendChild(template.content.cloneNode(true));
            content.replaceChildren(fallback);
            window.jQuery(paypalModal).modal('show');
        });
    });
})();
</script>
<script>
document.querySelectorAll('.finance-export-columns').forEach(function(panel){
    var checks=Array.from(panel.querySelectorAll('[name="report_columns[]"]'));
    var storageKey='finance:'+@json($dataset)+':page-export-columns:v2';
    try {
        var saved=JSON.parse(localStorage.getItem(storageKey));
        if(Array.isArray(saved)&&saved.some(function(value){return checks.some(function(input){return input.value===value;});})){
            checks.forEach(function(input){input.checked=saved.includes(input.value);});
        }
    }catch(error){}
    function validate(){checks[0].setCustomValidity(checks.some(function(input){return input.checked;})?'':'请至少选择一个导出字段。');}
    panel.querySelector('[data-export-columns-all]').addEventListener('click',function(){checks.forEach(function(input){input.checked=true;});validate();});
    panel.querySelector('[data-export-columns-none]').addEventListener('click',function(){checks.forEach(function(input){input.checked=false;});validate();});
    checks.forEach(function(input){input.addEventListener('change',validate);});
    panel.querySelector('[data-export-columns-save]').addEventListener('click',function(){
        validate();
        if(!checks[0].reportValidity())return;
        try{
            localStorage.setItem(storageKey,JSON.stringify(checks.filter(function(input){return input.checked;}).map(function(input){return input.value;})));
            panel.open=false;
        }catch(error){panel.querySelector('[data-export-columns-message]').textContent='浏览器未允许保存设置，本次导出仍可使用所选字段。';}
    });
    document.getElementById('finance-filters').addEventListener('invalid',function(){panel.open=true;},true);
});
document.getElementById('finance-export-filtered').addEventListener('click',function(event){
    var form=document.getElementById('finance-filters');
    if(!form.reportValidity()){event.preventDefault();return;}
    var query=new URLSearchParams(new FormData(form));
    query.set('export_scope','filtered');
    var selected=Array.from(document.querySelectorAll('.paypal-order-check:checked'));
    if(selected.length){
        query.set('export_scope','records');
        selected.forEach(function(input){query.append('selected_ids[]',input.value);});
    }
    query.set('export_format',document.querySelector('[name="export_format"]').value);
    this.href=@json(route('finance.records.export', $dataset))+'?'+query.toString();
    event.preventDefault();
    window.financeDownload(this.href,this);
});
</script>
<script>
(function(){
    var box=document.getElementById('finance-conditions'),button=document.getElementById('finance-add-filter');
    if(!box || !button)return;
    var choices=@json($conditionChoices);
    function update(){
        Array.from(box.children).forEach(function(row,index){
            var field=row.querySelector('[data-filter-field]'),operator=row.querySelector('[data-filter-operator]'),value=row.querySelector('[data-filter-value]');
            if(row.dataset.field!==undefined&&row.dataset.field!==field.value)value.value='';
            row.dataset.field=field.value;
            field.name='conditions['+index+'][field]';operator.name='conditions['+index+'][operator]';operator.value='equals';value.name='conditions['+index+'][value]';
            value.required=!!field.value;value.placeholder='输入筛选内容或选择选项';
            var list=row.querySelector('datalist');
            if(!list){list=document.createElement('datalist');row.appendChild(list);}
            list.id='finance-filter-options-'+index;value.setAttribute('list',list.id);
            render(list,choices[field.value]||[]);
        });
        button.disabled=box.children.length>=10;
    }
    function render(list,items){list.replaceChildren();items.forEach(function(item){var option=document.createElement('option');option.value=item.value;option.label=item.label;list.appendChild(option);});}
    var timers=new WeakMap();
    box.addEventListener('input',function(event){
        var value=event.target;if(!value.matches('[data-filter-value]'))return;
        var row=value.closest('.finance-condition'),field=row.querySelector('[data-filter-field]').value;
        if(!field||choices[field])return;
        clearTimeout(timers.get(value));
        timers.set(value,setTimeout(async function(){
            var text=value.value,url=new URL(window.location.href);
            Array.from(url.searchParams.keys()).forEach(function(key){if(key.startsWith('conditions['))url.searchParams.delete(key);});
            url.searchParams.set('suggestion_field',field);url.searchParams.set('suggestion_q',text);
            try{var response=await fetch(url,{headers:{Accept:'application/json'}});if(!response.ok)return;var items=await response.json();if(value.value===text&&row.dataset.field===field)render(row.querySelector('datalist'),items);}catch(error){}
        },250));
    });
    button.addEventListener('click',function(){if(box.children.length>=10)return;box.appendChild(document.getElementById('finance-condition-template').content.cloneNode(true));update();box.lastElementChild.querySelector('[data-filter-field]').focus();});
    box.addEventListener('click',function(event){if(event.target.matches('[data-remove-filter]')){event.target.closest('.finance-condition').remove();update();}});
    box.addEventListener('change',update);update();
})();
</script>

@if(in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true))
<script src="{{ asset('assets/erp/logistics-order-detail.js') }}?v=20261014-1"></script>
@endif
@endpush
