@php
    $dt = static fn (string $key, array $values = []) => __('payment.renewal_details.'.$key, $values);
    $pt = static fn (string $key) => __('payment.renewal_two_sections.'.$key);
    $money = static fn ($amount) => '$'.number_format((float)$amount, 2);
    $f = $detail['filters'];
    $p = $detail['pagination'];
    $c = $detail['calculation'];
    $scopeMethod = $f['detail_group'] === 'total' ? $pt('total') : $pt('method_names.'.substr($f['detail_group'],7));
    $methodName = static fn ($method) => $method === 'unknown' ? $dt('unknown') : $pt('method_names.'.$method);
@endphp
<div data-renewal-detail-fragment>
    <div class="payment-renewal-detail-scope">
        <strong>{{ $dt('scope',['method'=>$scopeMethod,'type'=>$pt('purchase_'.$f['detail_purchase']),'kind'=>$dt($f['detail_kind']),'from'=>substr($c['period_from'],0,10),'to'=>substr($c['period_to'],0,10),'timezone'=>$c['timezone'],'store'=>$detail['parent_store'] ?: $dt('all')]) }}</strong>
        <p>{{ $dt('scope_note') }}</p>
        <small>{{ $dt('generated',['time'=>$c['generated_at']]) }}</small>
    </div>
    <form data-renewal-detail-filter class="payment-renewal-detail-filter">
        <label class="payment-renewal-detail-search">{{ $dt('keyword') }}<input class="form-control" name="detail_keyword" value="{{ $f['detail_keyword'] }}" maxlength="100" placeholder="{{ $dt('keyword_hint') }}"></label>
        <label>{{ $dt('store') }}<select name="detail_store" class="form-control"><option value="">{{ $dt('all') }}</option>@foreach($detail['stores'] as $store)<option value="{{ $store }}" @selected($f['detail_store']===$store)>{{ $store }}</option>@endforeach</select></label>
        <label>{{ $dt('method') }}<select name="detail_method" class="form-control"><option value="">{{ $dt('all') }}</option>@foreach(['pp','cc','gp','ap','unknown'] as $method)<option value="{{ $method }}" @selected($f['detail_method']===$method)>{{ $methodName($method) }}</option>@endforeach</select></label>
        <label>{{ $dt('type') }}<select name="detail_type" class="form-control"><option value="">{{ $dt('all') }}</option>@foreach(['subscription','non_subscription'] as $type)<option value="{{ $type }}" @selected($f['detail_type']===$type)>{{ $pt('purchase_'.$type) }}</option>@endforeach</select></label>
        <label>{{ $dt('refund_state') }}<select name="detail_refund" class="form-control"><option value="">{{ $dt('all') }}</option><option value="yes" @selected($f['detail_refund']==='yes')>{{ $dt('refund_yes') }}</option><option value="no" @selected($f['detail_refund']==='no')>{{ $dt('refund_no') }}</option></select></label>
        <label>{{ $dt('delivery_state') }}<select name="detail_delivery" class="form-control"><option value="">{{ $dt('all') }}</option>@foreach(['delivered','undelivered'] as $state)<option value="{{ $state }}" @selected(($f['detail_delivery'] ?? '')===$state)>{{ $dt('delivery_'.$state) }}</option>@endforeach</select></label>
        <label>{{ $dt('paid_from') }}<input class="form-control" type="date" name="detail_from" value="{{ $f['detail_from'] }}" min="{{ substr($c['period_from'],0,10) }}" max="{{ substr($c['period_to'],0,10) }}"></label>
        <label>{{ $dt('paid_to') }}<input class="form-control" type="date" name="detail_to" value="{{ $f['detail_to'] }}" min="{{ substr($c['period_from'],0,10) }}" max="{{ substr($c['period_to'],0,10) }}"></label>
        <div class="payment-renewal-detail-filter-actions"><button class="btn btn-success" type="button" data-renewal-detail-export title="{{ $dt('export_all_note') }}">{{ $dt('export') }}</button><button class="btn btn-primary" type="submit">{{ $dt('query') }}</button><button class="btn btn-default" type="button" data-renewal-detail-reset>{{ $dt('reset') }}</button></div>
    </form>
    <p class="payment-renewal-detail-original">{{ $dt('scope_total',['orders'=>number_format($detail['scope']['orders']),'revenue'=>$money($detail['scope']['revenue']),'refunded'=>number_format($detail['scope']['refund_order_count']),'amount'=>$money($detail['scope']['refund_amount'])]) }}</p>
    <div class="payment-renewal-detail-totals" aria-label="{{ $dt('filtered') }}">
        @foreach(['orders'=>'order_count','revenue'=>'revenue','refund_order_count'=>'refund_orders','refund_amount'=>'refund_amount'] as $key=>$label)
            <div><span>{{ $dt('filtered') }} · {{ $dt($label) }}</span><strong>{{ in_array($key,['revenue','refund_amount'],true) ? $money($detail['summary'][$key]) : number_format($detail['summary'][$key]) }}</strong></div>
        @endforeach
    </div>
    <div class="payment-renewal-detail-scroll" tabindex="0" aria-label="{{ $dt('title') }}">
        <table class="payment-renewal-detail-table">
            <thead><tr>@foreach(['erp_id','order','transaction','bill_number','upsell_bill_number','paypal_transactions','collection_account','buyer_email','store','method','type','paid_at','revenue','refund_count','refund_amount','delivery_state'] as $column)<th>{{ $dt($column) }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse($detail['rows'] as $row)
                <tr data-renewal-detail-order="{{ $row['erp_order_id'] }}">
                    <td>{{ $row['erp_order_id'] }}</td><td>{{ $row['initial_order'] }}</td>
                    <td class="payment-renewal-detail-reference">{{ $row['transaction_id'] ?: '—' }}</td>
                    @foreach(['primary_bill_numbers','upsell_bill_numbers','transaction_ids','collection_accounts'] as $field)
                    <td class="payment-renewal-detail-reference">@forelse($row[$field] as $identifier)<span class="payment-renewal-detail-identifier">{{ $identifier }}</span>@empty<span>—</span>@endforelse</td>
                    @endforeach
                    <td class="payment-renewal-detail-email">{{ $row['buyer_email'] ?: '—' }}</td><td>{{ $row['store_name'] ?: '—' }}</td>
                    <td><span class="payment-method-badge">{{ $methodName($row['payment_method']) }}</span></td><td>{{ $pt('purchase_'.$row['purchase_type']) }}</td>
                    <td class="payment-renewal-detail-date">{{ $row['paid_at'] }}</td><td class="payment-renewal-detail-number">{{ $money($row['raw_amount']) }}</td>
                    <td><strong class="{{ $row['refund_count'] > 0 ? 'payment-renewal-has-refund' : '' }}">{{ $row['refund_count'] }}</strong>
                        @if($row['refund_count'] > 0)
                        <details class="payment-renewal-refund-records"><summary>{{ $dt('records') }}</summary>
                            @foreach($row['refund_details'] as $record)
                            <div><b>{{ $dt('record_amount') }}：{{ $money($record['amount']) }}</b><p>{{ $dt('reason') }}：{{ $record['reason'] ?: '—' }}</p><p>{{ $dt('status') }}：{{ $record['status'] ?: '—' }}</p><p>{{ $dt('time') }}：{{ $record['time'] }}</p></div>
                            @endforeach
                        </details>
                        @endif
                    </td><td class="payment-renewal-detail-number">{{ $money($row['raw_refund_amount']) }}</td><td>{{ $dt('delivery_'.$row['delivery_status']) }}</td>
                </tr>
            @empty
                <tr><td colspan="16" class="payment-renewal-detail-empty">{{ $dt('empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="payment-renewal-detail-pagination">
        <span>{{ $dt('pagination',['from'=>$p['from'],'to'=>$p['to'],'total'=>number_format($p['total'])]) }}</span>
        <label>{{ $dt('per_page') }} <select class="form-control" data-renewal-detail-per-page>@foreach([20,50,100] as $size)<option value="{{ $size }}" @selected($p['per_page']===$size)>{{ $size }}</option>@endforeach</select></label>
        <button type="button" class="btn btn-default btn-sm" data-renewal-detail-page="{{ $p['current_page']-1 }}" @disabled($p['current_page']<=1)>{{ $dt('previous') }}</button>
        <strong>{{ $dt('page',['page'=>$p['current_page'],'last'=>$p['last_page']]) }}</strong>
        <button type="button" class="btn btn-default btn-sm" data-renewal-detail-page="{{ $p['current_page']+1 }}" @disabled($p['current_page']>=$p['last_page'])>{{ $dt('next') }}</button>
        <form data-renewal-detail-jump><input type="number" class="form-control" min="1" max="{{ $p['last_page'] }}" value="{{ $p['current_page'] }}" aria-label="{{ $dt('page_number') }}" required><button type="submit" class="btn btn-default btn-sm">{{ $dt('jump') }}</button></form>
    </div>
</div>
