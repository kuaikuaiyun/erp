@extends('portal.layout')
@section('page_title', __('nbc_orders.menu'))
@push('styles')
<style>
.nbc-page{color:#31536b}.nbc-head{margin:0 0 18px}.nbc-head h1{font-size:23px;font-weight:700;color:#163b55}.nbc-head p{color:#758c9d}.nbc-page .box{border:1px solid #d5e3ed;border-radius:8px;overflow:hidden}.nbc-filter{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:13px;padding:18px}.nbc-filter .form-group{margin:0}.nbc-filter label{font-size:12px;color:#597891}.nbc-filter .form-control{border-radius:5px;border-color:#d5e3ed}.nbc-actions{display:flex;align-items:end;gap:8px}.nbc-toolbar,.nbc-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 18px}.nbc-toolbar h3{margin:0;font-size:16px;font-weight:700}.nbc-table{margin:0}.nbc-table th{background:#eaf3f9;color:#285673;white-space:nowrap}.nbc-table td,.nbc-table th{padding:12px!important;vertical-align:middle!important;border-color:#d9e5ee!important}.nbc-table td{min-width:120px}.nbc-table .nbc-products{min-width:260px}.nbc-field{line-height:1.7;word-break:break-word}.nbc-field small{color:#7890a2;margin-right:5px}.nbc-product{display:flex;gap:8px;padding:5px 0;border-bottom:1px dashed #e2eaf0}.nbc-product img{width:36px;height:36px;object-fit:cover;border-radius:4px}.nbc-muted{color:#7890a2;font-size:12px}.nbc-modal .modal-dialog{width:94%;max-width:1320px}.nbc-modal .modal-content{border-radius:9px;overflow:hidden}.nbc-modal .modal-header{background:#f3f8fc}.nbc-modal .modal-body{background:#fafcfe;padding:18px}.nbc-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:18px}.nbc-card{border:1px solid #dde7ee;border-radius:6px;padding:12px;background:#fff;word-break:break-word}.nbc-card span{display:block;color:#7890a2;font-size:12px}.nbc-card strong{display:block;margin-top:6px}.nbc-raw{max-height:350px;white-space:pre-wrap;word-break:break-all}.nbc-page .btn{border-radius:5px}.nbc-sync-summary{padding:0 0 14px;color:#597891}.nbc-empty{text-align:center;padding:40px!important;color:#7890a2}.nbc-page-links{display:flex;gap:5px;align-items:center}.nbc-page-links .btn{min-width:32px}.nbc-modal .nbc-filter{padding:0 0 15px}.nbc-page .table-responsive,.nbc-modal .table-responsive{border:0}@media(max-width:1000px){.nbc-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.nbc-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.nbc-filter,.nbc-cards{grid-template-columns:1fr}.nbc-toolbar,.nbc-pagination{align-items:flex-start;flex-direction:column}.nbc-modal .modal-dialog{margin:10px auto}}
</style>
@endpush
@section('content')
@php
    $t = fn ($key, $replace = []) => __('nbc_orders.'.$key, $replace);
    $orderLabels = $t('orders'); $fulfillmentLabels = $t('fulfillments'); $sourceLabels = $t('sources');
@endphp
<section class="content nbc-page">
    <div class="nbc-head"><h1>{{ $t('menu') }}</h1><p>{{ $t('description') }}</p></div>
    @if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="box"><form method="get" action="{{ route('fashion-logistics.nbc-orders.index') }}" class="nbc-filter">
        <div class="form-group"><label for="nbc-keyword">{{ $t('keyword') }}</label><input id="nbc-keyword" class="form-control" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="{{ $t('keyword_hint') }}"></div>
        <div class="form-group"><label for="nbc-store">{{ $t('store') }}</label><select id="nbc-store" class="form-control" name="store_id"><option value="">{{ $t('all_stores') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string)($filters['store_id'] ?? '') === (string)$store->id)>{{ $store->shop_name ?: '#'.$store->id }} · {{ $t('platforms.'.strtolower(trim((string)$store->site_builder))) }}</option>@endforeach</select></div>
        @if($canSelectOperator)<div class="form-group"><label for="nbc-operator">{{ $t('operator') }}</label><select id="nbc-operator" class="form-control" name="operator_id"><option value="">{{ $t('all_operators') }}</option>@foreach($operators as $operator)<option value="{{ $operator->id }}" @selected((string)($filters['operator_id'] ?? '') === (string)$operator->id)>{{ $operator->name ?: $operator->username }}</option>@endforeach</select></div>@endif
        @foreach(['status' => ['order_status', $orderLabels], 'fulfillment_status' => ['fulfillment_status', $fulfillmentLabels], 'marketing_source' => ['marketing_source', $sourceLabels]] as $name => [$label, $options])
            <div class="form-group"><label for="nbc-{{ $name }}">{{ $t($label) }}</label><select id="nbc-{{ $name }}" class="form-control" name="{{ $name }}"><option value="">{{ $t('all') }}</option>@foreach($options as $value => $text)<option value="{{ $value }}" @selected((string)($filters[$name] ?? '') === (string)$value)>{{ $text }}</option>@endforeach</select></div>
        @endforeach
        @foreach(['date_from', 'date_to'] as $name)<div class="form-group"><label for="nbc-{{ $name }}">{{ $t($name) }}</label><input id="nbc-{{ $name }}" class="form-control" type="date" name="{{ $name }}" value="{{ $filters[$name] ?? '' }}"></div>@endforeach
        <div class="form-group"><label for="nbc-per-page">{{ $t('per_page') }}</label><select id="nbc-per-page" class="form-control" name="per_page">@foreach([20,50,100] as $size)<option value="{{ $size }}" @selected((int)($filters['per_page'] ?? 20) === $size)>{{ $size }}</option>@endforeach</select></div>
        <div class="nbc-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ $t('search') }}</button><a class="btn btn-default" href="{{ route('fashion-logistics.nbc-orders.index') }}">{{ $t('reset') }}</a></div>
    </form></div>
    <div class="box">
        <div class="nbc-toolbar"><h3>{{ $t('list') }} <small>{{ $orders->total() }}</small></h3><button type="button" class="btn btn-success" data-toggle="modal" data-target="#nbc-sync-modal">{{ $t('sync_title') }}</button></div>
        <div class="table-responsive"><table class="table table-bordered table-hover nbc-table"><thead><tr>@foreach(['order_info','logistics_info','customer_info','marketing_source','products','amount','actions'] as $key)<th>{{ $t($key) }}</th>@endforeach</tr></thead><tbody>
        @forelse($orders as $order)
            <tr>
                <td>@foreach(['date' => $order->order_created_at?->format('Y-m-d H:i:s'), 'platform' => $t('platforms.'.$order->source_platform), 'store' => $order->store_name, 'domain' => $order->source_domain, 'owner' => ($order->store?->ownerUser?->name ?: $order->store?->ownerUser?->username), 'user' => $order->store?->user_name, 'team' => implode(' / ', $order->store?->team ?? []), 'order_number' => $order->order_number, 'external_id' => $order->external_order_id] as $key => $value)<div class="nbc-field"><small>{{ $t($key) }}:</small>{{ $value ?: '—' }}</div>@endforeach</td>
                <td><div class="nbc-field"><small>{{ $t('order_status') }}:</small>{{ $orderLabels[$order->status] ?? $t('unknown') }}</div><div class="nbc-field"><small>{{ $t('payment_status') }}:</small>{{ $t('payments.230') }}</div><div class="nbc-field"><small>{{ $t('fulfillment_status') }}:</small>{{ $fulfillmentLabels[$order->fulfillment_status] ?? $t('unknown') }}</div></td>
                <td>@foreach(['customer' => $order->customer_name, 'email' => $order->customer_email, 'phone' => $order->customer_phone] as $key => $value)<div class="nbc-field"><small>{{ $t($key) }}:</small>{{ $value ?: '—' }}</div>@endforeach</td>
                <td>{{ $sourceLabels[$order->marketing_source] ?? $t('unknown') }}</td>
                <td class="nbc-products">@forelse($order->items->take(2) as $item)<div class="nbc-product">@if($item->image_url)<img src="{{ $item->image_url }}" alt="" loading="lazy" referrerpolicy="no-referrer">@endif<div>{{ $item->product_name ?: $t('unnamed') }}<div class="nbc-muted">{{ $t('sku') }}: {{ $item->sku ?: '—' }} · ×{{ rtrim(rtrim($item->quantity, '0'), '.') }}</div></div></div>@empty{{ $t('no_items') }}@endforelse @if($order->items->count() > 2)<div class="nbc-muted">{{ $t('more_items', ['count' => $order->items->count() - 2]) }}</div>@endif</td>
                <td><strong>{{ $order->currency }} {{ number_format((float)$order->total_amount, 2) }}</strong>@if((float)$order->discount_amount > 0)<div class="nbc-muted">{{ $t('discount') }}: {{ $order->discount_amount }}</div>@endif</td>
                <td><button type="button" class="btn btn-default btn-xs js-nbc-detail" data-url="{{ route('fashion-logistics.nbc-orders.show', $order) }}">{{ $t('detail') }}</button></td>
            </tr>
        @empty<tr><td colspan="7" class="nbc-empty">{{ $t('empty') }}</td></tr>@endforelse
        </tbody></table></div>
        <div class="nbc-pagination"><span>{{ $t('summary', ['total'=>$orders->total(), 'page'=>$orders->currentPage(), 'pages'=>$orders->lastPage()]) }}</span><nav class="nbc-page-links">
            @if($orders->previousPageUrl())<a class="btn btn-default btn-sm" href="{{ $orders->previousPageUrl() }}">{{ $t('previous') }}</a>@endif
            @foreach(collect([1,$orders->currentPage()-1,$orders->currentPage(),$orders->currentPage()+1,$orders->lastPage()])->filter(fn($p)=>$p>=1&&$p<=$orders->lastPage())->unique()->sort() as $page)<a class="btn btn-sm {{ $page === $orders->currentPage() ? 'btn-primary' : 'btn-default' }}" href="{{ $orders->url($page) }}">{{ $page }}</a>@endforeach
            @if($orders->nextPageUrl())<a class="btn btn-default btn-sm" href="{{ $orders->nextPageUrl() }}">{{ $t('next') }}</a>@endif
        </nav></div>
    </div>
    <div class="modal fade nbc-modal" id="nbc-detail-modal" tabindex="-1" role="dialog" aria-labelledby="nbc-detail-title"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><button class="close" type="button" data-dismiss="modal" aria-label="{{ $t('close') }}">×</button><h4 class="modal-title" id="nbc-detail-title">{{ $t('detail_title') }}</h4></div><div class="modal-body" id="nbc-detail-body"></div></div></div></div>
    <div class="modal fade nbc-modal" id="nbc-sync-modal" tabindex="-1" role="dialog" aria-labelledby="nbc-sync-title"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><button class="close" type="button" data-dismiss="modal" aria-label="{{ $t('close') }}">×</button><h4 class="modal-title" id="nbc-sync-title">{{ $t('sync_title') }}</h4><p>{{ $t('sync_hint') }}</p></div><div class="modal-body">
        <form id="nbc-sync-form" class="nbc-filter" data-url="{{ route('fashion-logistics.nbc-orders.sync-status') }}">
            <div class="form-group"><label for="nbc-sync-keyword">{{ $t('sync_keyword') }}</label><input class="form-control" id="nbc-sync-keyword" name="keyword"></div>
            <div class="form-group"><label for="nbc-sync-configuration">{{ $t('configuration') }}</label><select class="form-control" id="nbc-sync-configuration" name="configuration"><option value="">{{ $t('all') }}</option><option value="ready">{{ $t('ready') }}</option><option value="missing">{{ $t('missing') }}</option></select></div>
            <div class="form-group"><label for="nbc-sync-status">{{ $t('sync_status') }}</label><select class="form-control" id="nbc-sync-status" name="sync_status"><option value="">{{ $t('all') }}</option>@foreach($t('sync_states') as $value=>$text)<option value="{{ $value }}">{{ $text }}</option>@endforeach</select></div>
            <div class="nbc-actions"><select class="form-control" name="per_page" aria-label="{{ $t('per_page') }}">@foreach([20,50,100] as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach</select><button type="submit" class="btn btn-primary">{{ $t('search') }}</button></div>
        </form>
        <div id="nbc-sync-summary" class="nbc-sync-summary"></div><div class="table-responsive"><table class="table table-bordered nbc-table"><thead><tr>@foreach(['store','platform','operator','configuration','last_success','sync_status','total_count','today_count','last_error'] as $key)<th>{{ $t($key) }}</th>@endforeach</tr></thead><tbody id="nbc-sync-body"></tbody></table></div><div class="nbc-pagination"><span id="nbc-sync-range"></span><nav class="nbc-page-links" id="nbc-sync-pages"></nav></div>
    </div></div></div></div>
</section>
@endsection
@push('scripts')
<script>
(function () {
    'use strict';
    var labels = @json(__('nbc_orders'));
    var detailSequence = 0, syncSequence = 0, syncBusy = false;
    var form = document.getElementById('nbc-sync-form'), detailBody = document.getElementById('nbc-detail-body');
    function element(tag, text, className) { var node = document.createElement(tag); if (text != null) node.textContent = String(text); if (className) node.className = className; return node; }
    function value(v) { return v == null || v === '' ? '—' : String(v); }
    function label(group, key) { return labels[group][key] || labels.unknown; }
    function summary(p) { return labels.summary.replace(':total', p.total || 0).replace(':page', p.current_page || 1).replace(':pages', p.last_page || 1); }
    async function request(url) {
        var controller = new AbortController(), timer = setTimeout(function () { controller.abort(); }, 20000);
        try {
            var response = await fetch(url, {headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, signal:controller.signal});
            if (!response.ok) throw new Error(labels.load_error);
            return await response.json();
        } catch (error) { throw new Error(error.name === 'AbortError' ? labels.timeout : labels.load_error); }
        finally { clearTimeout(timer); }
    }
    document.addEventListener('click', async function (event) {
        var button = event.target.closest('.js-nbc-detail'); if (!button) return;
        var sequence = ++detailSequence;
        detailBody.textContent = labels.loading; $('#nbc-detail-modal').modal('show');
        try {
            var data = await request(button.dataset.url); if (sequence !== detailSequence) return;
            if (!data.order || !Array.isArray(data.items)) throw new Error(labels.load_error);
            var order = data.order, cards = element('div', null, 'nbc-cards'); detailBody.replaceChildren(cards);
            var fields = {platform:label('platforms',order.source_platform),order_number:order.order_number, store:order.store_name, domain:order.source_domain, amount:value(order.currency)+' '+value(order.total_amount), order_status:label('orders',order.status), payment_status:label('payments',order.payment_status), fulfillment_status:label('fulfillments',order.fulfillment_status), marketing_source:label('sources',order.marketing_source), customer:order.customer_name, email:order.customer_email, phone:order.customer_phone, address:order.shipping_address, transaction_id:order.transaction_id, payment_method:order.payment_method_title || order.payment_method, date:order.order_created_at, paid_at:order.paid_at_utc, modified_at:order.order_modified_at, synced_at:order.source_synced_at, note:order.customer_note};
            Object.keys(fields).forEach(function (key) { var card=element('div',null,'nbc-card'); card.append(element('span',labels[key]),element('strong',value(fields[key]))); cards.append(card); });
            detailBody.append(element('h4',labels.products));
            var table=element('table',null,'table table-bordered nbc-table'), head=element('tr');
            ['products','sku','quantity','subtotal','total'].forEach(function(key){head.append(element('th',labels[key]));}); table.append(head);
            data.items.forEach(function(item){var row=element('tr'); ['product_name','sku','quantity','subtotal','total'].forEach(function(key){row.append(element('td',value(item[key])));});table.append(row);});
            var wrap=element('div',null,'table-responsive'); wrap.append(table);detailBody.append(wrap);
            detailBody.append(element('h4',labels.raw),element('pre',JSON.stringify(order.source_payload || {},null,2),'nbc-raw'));
        } catch(error) { if(sequence === detailSequence) detailBody.textContent=error.message; }
    });
    $('#nbc-detail-modal').on('hidden.bs.modal',function(){detailSequence++;});
    $('#nbc-sync-modal').on('shown.bs.modal',function(){loadSync(1);});
    form.addEventListener('submit',function(event){event.preventDefault();loadSync(1);});
    async function loadSync(page) {
        if(syncBusy) return;
        syncBusy=true;var sequence=++syncSequence, submit=form.querySelector('button[type="submit"]');submit.disabled=true;
        var body=document.getElementById('nbc-sync-body'), metrics=document.getElementById('nbc-sync-summary'), pages=document.getElementById('nbc-sync-pages'),range=document.getElementById('nbc-sync-range');
        function message(text){var row=element('tr'),cell=element('td',text,'text-center');cell.colSpan=9;row.append(cell);body.replaceChildren(row);}
        message(labels.loading);pages.replaceChildren();metrics.textContent='';range.textContent='';
        var params=new URLSearchParams(new FormData(form));params.set('page',String(page));
        try {
            var data=await request(form.dataset.url+'?'+params.toString());if(sequence!==syncSequence)return;
            if(!Array.isArray(data.rows)||!data.pagination||!data.summary)throw new Error(labels.load_error);
            var sums=data.summary;metrics.textContent=labels.total_stores+': '+sums.total+' · '+labels.ready+': '+sums.ready+' · '+labels.missing_count+': '+sums.missing+' · '+labels.failed_count+': '+sums.failed;
            body.replaceChildren();if(!data.rows.length)message(labels.no_stores);
            data.rows.forEach(function(item){var row=element('tr');[item.handle,label('platforms',item.platform),item.operator,item.ready?labels.ready:labels.missing,item.last_succeeded_at||labels.never,label('sync_states',item.status),item.total_count,item.today_count,item.last_error].forEach(function(v){row.append(element('td',value(v)));});body.append(row);});
            var p=data.pagination;range.textContent=summary(p);
            function pageButton(text,target,disabled){var button=element('button',text,'btn btn-default btn-sm');button.type='button';button.disabled=disabled;button.addEventListener('click',function(){loadSync(target);});pages.append(button);}
            pageButton(labels.previous,p.current_page-1,p.current_page<=1);
            pages.append(element('span',p.current_page+' / '+p.last_page));
            pageButton(labels.next,p.current_page+1,p.current_page>=p.last_page);
        } catch(error){if(sequence===syncSequence)message(error.message);}
        finally{if(sequence===syncSequence){syncBusy=false;submit.disabled=false;}}
    }
}());
</script>
@endpush
