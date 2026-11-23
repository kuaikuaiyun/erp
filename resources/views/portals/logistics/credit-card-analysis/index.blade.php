@extends('portal.layout')

@section('page_title', __('credit_card_analysis.title'))

@php
    $origin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn ($key, $replace = []) => __('credit_card_analysis.'.$key, $replace);
    $text = static fn ($value) => filled($value) ? (string) $value : __('credit_card_analysis.unknown');
    $date = static fn ($value) => $value ? $value->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') : __('credit_card_analysis.unknown');
    $flag = static fn ($value) => __($value === null ? 'credit_card_analysis.unhandled' : ($value ? 'credit_card_analysis.yes' : 'credit_card_analysis.no'));
    $money = static fn ($value) => $value === null ? __('credit_card_analysis.unknown') : number_format((float) $value, 2).' USD';
    $choices = ['yes'=>$t('yes'), 'no'=>$t('no')];
    $selects = [
        'store_id'=>$stores->mapWithKeys(fn ($store) => [$store->id=>($store->shop_name ?: '#'.$store->id).($store->store_prefix ? ' / '.$store->store_prefix : '')])->all(),
        'category'=>$categories, 'reason'=>array_combine($reasons, $reasons), 'matched'=>['yes'=>$t('matched'),'no'=>$t('unmatched')],
        'subscription_type'=>$subscriptionTypes->mapWithKeys(fn ($value) => [$value=>$value])->all(),
        'post_upsell'=>$choices, 'repurchase'=>$choices, 'subscription_cancelled'=>$choices, 'add_on_cancelled'=>$choices,
        'handled'=>['yes'=>$t('handled'),'no'=>$t('unhandled')], 'shipped'=>$choices,
        'logistics_status'=>\App\Models\LogisticsOrder::logisticsStatusOptions(),
    ];
    $filterKeys = ['store_id','keyword','collection_account','category','reason','matched','customer_email','refund_amount','order_amount',
        'subscription_type','post_upsell','repurchase','subscription_cancelled','add_on_cancelled','handled',
        'shipped','logistics_status','tracking_number','tongtu_order_number','item_keyword','note'];
    $hasFilters = collect($filters)->except('per_page')->contains(fn ($value) => $value !== '');
    $columns = ['basic','order','amount','subscription','refund','logistics','product','handling','actions'];
@endphp

@push('styles')
<link rel="stylesheet" href="{{ $origin }}/assets/erp/logistics.css?v=20261109-1">
<link rel="stylesheet" href="{{ $origin }}/assets/erp/credit-card-analysis.css?v=20261123-1">
@endpush

@section('content')
<section class="content-header"><h1>{{ $t('title') }} <small>{{ $t('subtitle') }}</small></h1></section>
<section class="content credit-card-analysis-page" data-selected-template="{{ $t('selected_count',['count'=>':count']) }}" data-copied="{{ $t('copied') }}" data-copy-failed="{{ $t('copy_failed') }}" data-invalid-range="{{ $t('invalid_range') }}" data-select-required="{{ $t('validation.select_required') }}" data-handle-id="{{ old('handling_id') }}" data-export-error="{{ $errors->has('scope') || $errors->has('selected_ids') || $errors->has('selected_ids.*') ? '1' : '0' }}">
    @if(session('success'))<div class="alert alert-success"><i class="fa fa-check-circle"></i> {{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="box box-solid logistics-filter-box">
        <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> {{ $t('filters') }}</h3></div>
        <div class="box-body">
            <form method="get" action="{{ route('logistics.credit-card-analysis.index') }}" class="logistics-filter-form ca-filter-form">
                <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
                <div class="ca-filter-sections">
                    <div class="ca-filter-section"><div class="logistics-filter-grid">
                    @foreach($filterKeys as $key)
                        <div class="logistics-filter-field {{ in_array($key,['refund_amount','order_amount'],true) ? 'ca-amount-range' : '' }}">
                            <label for="ca-filter-{{ $key }}">{{ $t('fields.'.$key) }}</label>
                            @if(isset($selects[$key]))
                                <select id="ca-filter-{{ $key }}" class="form-control" name="{{ $key }}"><option value="">{{ $t('all') }}</option>@foreach($selects[$key] as $value=>$label)<option value="{{ $value }}" @selected((string)$filters[$key] === (string)$value)>{{ $label }}</option>@endforeach</select>
                            @elseif(in_array($key,['refund_amount','order_amount'],true))
                                <div data-ca-range><input id="ca-filter-{{ $key }}" class="form-control" type="number" min="0" step="0.01" max="99999999999999" name="{{ $key }}_min" value="{{ $filters[$key.'_min'] }}" placeholder="{{ $t('minimum') }}" aria-label="{{ $t('fields.'.$key).' '.$t('minimum') }}"><span>{{ $t('to') }}</span><input class="form-control" type="number" min="0" step="0.01" max="99999999999999" name="{{ $key }}_max" value="{{ $filters[$key.'_max'] }}" placeholder="{{ $t('maximum') }}" aria-label="{{ $t('fields.'.$key).' '.$t('maximum') }}"></div>
                            @elseif($key === 'keyword')
                                <div class="ca-clearable"><input id="ca-filter-keyword" class="form-control" name="keyword" maxlength="1000" value="{{ $filters['keyword'] }}" placeholder="{{ $t('placeholders.keyword') }}"><button class="ca-clear-input" type="button" aria-label="{{ $t('clear') }}" title="{{ $t('clear') }}"><i class="fa fa-times"></i></button></div>
                            @else
                                <input id="ca-filter-{{ $key }}" class="form-control" name="{{ $key }}" maxlength="1000" value="{{ $filters[$key] }}" placeholder="{{ $t('placeholders.'.(in_array($key,['note','item_keyword'],true) ? $key : 'text')) }}">
                            @endif
                        </div>
                    @endforeach
                    </div></div>
                    <div class="ca-filter-date-row">
                        @foreach(['date'=>'date','handled'=>'handled_date','order'=>'order_date'] as $prefix=>$label)
                        <div class="logistics-filter-field logistics-date-range"><label for="ca-filter-{{ $prefix }}-from">{{ $t('fields.'.$label) }}</label><div data-ca-range><input id="ca-filter-{{ $prefix }}-from" type="date" class="form-control" name="{{ $prefix }}_from" value="{{ $filters[$prefix.'_from'] }}" aria-label="{{ $t('fields.'.$label).' '.$t('minimum') }}"><span>{{ $t('to') }}</span><input type="date" class="form-control" name="{{ $prefix }}_to" value="{{ $filters[$prefix.'_to'] }}" aria-label="{{ $t('fields.'.$label).' '.$t('maximum') }}"></div></div>
                        @endforeach
                    </div>
                </div>
                <div class="ca-timezone text-muted">{{ $t('timezone') }}</div>
                <div class="logistics-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ $t('query') }}</button>@if($hasFilters)<a class="btn btn-default" href="{{ route('logistics.credit-card-analysis.index',['per_page'=>$filters['per_page']]) }}">{{ $t('reset') }}</a>@endif</div>
            </form>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border logistics-list-toolbar"><button class="btn btn-default btn-sm" type="button" data-toggle="modal" data-target="#ca-export-modal"><i class="fa fa-download"></i> {{ $t('export') }}</button><span class="credit-card-analysis-count">{{ $t('record_count',['count'=>$records->total()]) }}</span>@include('portals.logistics.partials.pagination',['paginator'=>$records,'filters'=>$filters,'routeName'=>'logistics.credit-card-analysis.index','loadingTarget'=>'.credit-card-analysis-scroll'])</div>
        <div class="box-body table-responsive no-padding credit-card-analysis-scroll">
            <table class="table table-hover logistics-table credit-card-analysis-table">
                <thead><tr><th class="ca-dispute-select-cell"><input id="ca-check-all" type="checkbox" aria-label="{{ $t('select_all') }}"></th>@foreach($columns as $column)<th>{{ $t('columns.'.$column) }}</th>@endforeach</tr></thead>
                <tbody>@forelse($records as $record)
                    @php
                        $order = $record->order;
                        $reference = $record->refund_reference ?: '#'.$record->id;
                        $latestNote = $analysis->latestNote($record);
                        $items = $order?->items ?? collect();
                        $shipped = $order ? in_array($order->logistics_status,[\App\Models\LogisticsOrder::LOGISTICS_SHIPPED,\App\Models\LogisticsOrder::LOGISTICS_IN_TRANSIT,\App\Models\LogisticsOrder::LOGISTICS_DELIVERED],true) : null;
                        $category = $analysis->category($record->refund_reason);
                    @endphp
                    <tr class="{{ $record->first_handled_at ? 'ca-record-handled' : '' }}">
                        <td class="ca-dispute-select-cell"><input class="ca-record-check" type="checkbox" value="{{ $record->id }}" aria-label="{{ $t('select_record',['reference'=>$reference]) }}"></td>
                        <td><div class="ca-block"><b>{{ $t('fields.store_id') }}：</b>{{ $text($order?->store_name) }}<br><b>{{ $t('fields.collection_account') }}：</b>{{ $text($order?->collection_account) }}<br>@foreach(['refund_reference','bill_number'] as $key)<b>{{ $t('fields.'.$key) }}：</b><span class="ca-mono">{{ $text($record->$key) }}</span>@if(filled($record->$key))<button class="ca-copy-button" type="button" data-copy-value="{{ $record->$key }}" title="{{ $t('copy') }}" aria-label="{{ $t('copy').' '.$t('fields.'.$key) }}"><i class="fa fa-copy"></i></button>@endif<br>@endforeach</div></td>
                        <td><div class="ca-block"><b>{{ $t('fields.customer_email') }}：</b>{{ $text($order?->buyer_email) }}<br><b>{{ $t('fields.shopify_order_number') }}：</b>{{ $text($order?->shopify_order_number) }}<br><b>{{ $t('fields.date') }}：</b>{{ $date($record->occurred_at) }}<br><b>{{ $t('fields.order_date') }}：</b>{{ $date($order?->order_created_at) }}<br><b>{{ $t('fields.matched') }}：</b>{{ $order ? $t('matched') : $t('unmatched') }}</div></td>
                        <td><div class="ca-block"><b>{{ $t('fields.refund_amount') }}：</b>{{ $money($record->refund_amount_usd) }}<br><b>{{ $t('fields.order_amount') }}：</b>{{ $money($order?->paid_amount_usd) }}</div></td>
                        <td><div class="ca-block"><b>{{ $t('fields.subscription_type') }}：</b>{{ $text($order?->subscription_type) }}<br><b>{{ $t('fields.post_upsell') }}：</b>{{ $order ? $flag($order->is_add_on === '已加购') : $t('unmatched') }}<br><b>{{ $t('fields.repurchase') }}：</b>{{ $order ? $flag(filled($order->subscription_origin_order_id)) : $t('unmatched') }}<br><b>{{ $t('fields.subscription_cancelled') }}：</b><span class="ca-manual">{{ $flag($record->subscription_cancelled) }}</span><br><b>{{ $t('fields.add_on_cancelled') }}：</b><span class="ca-manual">{{ $flag($record->add_on_cancelled) }}</span></div></td>
                        <td><div class="ca-block"><b>{{ $t('fields.category') }}：</b><span class="ca-category ca-category-{{ $category }}">{{ $categories[$category] }}</span><br><b>{{ $t('fields.reason') }}：</b>{{ $text($record->refund_reason) }}<br><b>{{ $t('fields.imported_at') }}：</b>{{ $date($record->last_imported_at) }}<br><b>{{ $t('fields.imported_by') }}：</b>{{ $text($record->imported_by_name) }}</div></td>
                        <td><div class="ca-block"><b>{{ $t('current_logistics') }}：</b>{{ $text($order?->logistics_status) }}<br><b>{{ $t('fields.shipped') }}：</b>{{ $order ? $flag($shipped) : $t('unmatched') }}<br><b>{{ $t('fields.tracking_number') }}：</b><span class="ca-mono">{{ $text($order?->tracking_number) }}</span></div></td>
                        <td><div class="ca-block ca-product-block"><b>{{ $t('fields.tongtu_order_number') }}：</b>{{ $text($order?->tongtu_order_number) }}<br><b>{{ $t('fields.sku') }}：</b>{{ $text($items->pluck('tongtu_sku')->filter()->unique()->implode(' | ')) }}<br><b>{{ $t('fields.product_name') }}：</b>{{ $text($items->map(fn ($item) => $item->tongtu_product_name ?: $item->product_title)->filter()->unique()->implode(' | ')) }}</div></td>
                        <td><div class="ca-block"><b>{{ $t('fields.handled') }}：</b>{{ $record->first_handled_at ? $t('handled') : $t('unhandled') }}<br><b>{{ $t('fields.first_handled_at') }}：</b>{{ $date($record->first_handled_at) }}<br><b>{{ $t('fields.first_handled_by') }}：</b>{{ $text($record->first_handled_by_name) }}<hr><b>{{ $t('fields.latest_note') }}：</b><div class="ca-latest-note">{{ $text($latestNote) }}</div>@if($record->operations->isNotEmpty())<button class="ca-operation-history-link" type="button" data-toggle="modal" data-target="#ca-history-{{ $record->id }}">{{ $t('history') }} ({{ $record->operations->count() }})</button>@endif</div></td>
                        <td><button class="btn btn-primary btn-sm" type="button" data-toggle="modal" data-target="#ca-handle-{{ $record->id }}"><i class="fa fa-pencil"></i> {{ $t('handle') }}</button></td>
                    </tr>
                @empty<tr><td colspan="10" class="text-center ca-empty">{{ $t('empty') }}</td></tr>@endforelse</tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="ca-export-modal" tabindex="-1" role="dialog" aria-labelledby="ca-export-title">
        <div class="modal-dialog" role="document"><form id="ca-export-form" class="modal-content" method="post" action="{{ route('logistics.credit-card-analysis.export') }}" data-loading="download">@csrf
            @foreach($filters as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('close') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="ca-export-title"><i class="fa fa-download"></i> {{ $t('export_title') }}</h4></div>
            <div class="modal-body"><p class="text-muted">{{ $t('export_notice') }}</p><h5 class="logistics-modal-section-title">{{ $t('export_scope') }}</h5>
                <label class="logistics-export-scope"><input type="radio" name="scope" value="selected"> <strong>{{ $t('export_selected') }}</strong><small id="ca-selected-count">{{ $t('selected_count',['count'=>0]) }}</small></label>
                <label class="logistics-export-scope"><input type="radio" name="scope" value="filtered" checked> <strong>{{ $t('export_filtered') }}</strong><small>{{ $t('record_count',['count'=>$records->total()]) }}</small></label>
                <div id="ca-selected-inputs"></div><p id="ca-export-error" class="text-danger" role="alert"></p>
            </div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('cancel') }}</button><button type="submit" class="btn btn-primary"><i class="fa fa-download"></i> {{ $t('confirm_export') }}</button></div>
        </form></div>
    </div>

    @foreach($records as $record)
        @php $reference = $record->refund_reference ?: '#'.$record->id; $oldHandle = (string)old('handling_id') === (string)$record->id; @endphp
        <div class="modal fade" id="ca-handle-{{ $record->id }}" tabindex="-1" role="dialog" aria-labelledby="ca-handle-title-{{ $record->id }}"><div class="modal-dialog" role="document"><form class="modal-content ca-handle-form" method="post" action="{{ route('logistics.credit-card-analysis.handle',$record) }}">@csrf
            <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}"><input type="hidden" name="handling_id" value="{{ $record->id }}">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('close') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="ca-handle-title-{{ $record->id }}">{{ $t('handle_title',['reference'=>$reference]) }}</h4></div>
            <div class="modal-body"><p class="text-muted">{{ $t('handling_hint') }}</p>
                @foreach(['subscription_cancelled','add_on_cancelled'] as $key)
                @php $value = $oldHandle ? old($key) : ($record->$key === null ? '' : ($record->$key ? 'yes' : 'no')); @endphp
                <div class="form-group"><label for="ca-{{ $record->id }}-{{ $key }}">{{ $t('fields.'.$key) }}</label><select id="ca-{{ $record->id }}-{{ $key }}" class="form-control" name="{{ $key }}"><option value="">{{ $t('unchanged') }}</option>@foreach($choices as $choice=>$label)<option value="{{ $choice }}" @selected($value === $choice)>{{ $label }}</option>@endforeach</select></div>
                @endforeach
                <div class="form-group"><label for="ca-note-{{ $record->id }}">{{ $t('fields.note') }}</label><textarea id="ca-note-{{ $record->id }}" class="form-control" name="note" maxlength="1000" rows="4" placeholder="{{ $t('note_hint') }}">{{ $oldHandle ? old('note') : $analysis->latestNote($record) }}</textarea></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('cancel') }}</button><button type="submit" class="btn btn-primary">{{ $t('save') }}</button></div>
        </form></div></div>
        <div class="modal fade" id="ca-history-{{ $record->id }}" tabindex="-1" role="dialog" aria-labelledby="ca-history-title-{{ $record->id }}"><div class="modal-dialog modal-lg" role="document"><div class="modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('close') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="ca-history-title-{{ $record->id }}">{{ $t('history_title',['reference'=>$reference]) }}</h4></div>
            <div class="modal-body ca-history-modal-body"><div class="table-responsive"><table class="table table-bordered ca-history-table"><thead><tr>@foreach(['operation_time','operator','subscription_cancelled','add_on_cancelled','note'] as $key)<th>{{ $t('fields.'.$key) }}</th>@endforeach</tr></thead><tbody>
                @forelse($record->operations as $operation)<tr><td>{{ $date($operation->created_at) }}</td><td>{{ $text($operation->operator_name) }}</td>@foreach(['subscription_cancelled','add_on_cancelled'] as $key)<td>{{ ($operation->payload[$key] ?? '') === 'yes' ? $t('yes') : ((($operation->payload[$key] ?? '') === 'no') ? $t('no') : $t('not_modified')) }}</td>@endforeach<td class="ca-history-note">{{ $text($operation->payload['note'] ?? null) }}</td></tr>@empty<tr><td colspan="5">{{ $t('no_history') }}</td></tr>@endforelse
            </tbody></table></div></div><div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">{{ $t('close') }}</button></div>
        </div></div></div>
    @endforeach
</section>
@endsection

@push('scripts')
<script src="{{ $origin }}/assets/erp/credit-card-analysis.js?v=20261123-1"></script>
@endpush
