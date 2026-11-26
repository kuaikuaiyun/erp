@php
    $copy = 'baokuan_general_performance_report.';
    $reportUrl = route($operationRoute.'.index');
    $query = array_filter($filters, static fn ($value) => $value !== '');
    $operatorNames = $operators->mapWithKeys(fn ($operator) => [(int) $operator->id => $operator->name ?: $operator->username]);
    $money = static fn ($amount) => '$'.number_format((float) $amount, 2);
    $yuan = static fn ($amount) => '¥'.number_format((float) $amount, 2);
    $percent = static fn ($value) => rtrim(rtrim(number_format($value * 100, 2), '0'), '.');
    $adLabel = $performanceFormula ? 'book_spend' : 'ad_spend';
    $resultCards = [
        ['revenue', $money($summary['revenue']), 'usd', 'money', 'blue'],
        ['orders', number_format($summary['order_count']), 'order_unit', 'shopping-cart', 'green'],
        ['average', $money($summary['average_order_value']), 'usd', 'calculator', 'cyan'],
        ['stores', number_format($summary['store_count']), 'store_unit', 'shopping-bag', 'gray'],
        ['roi', $summary['roi'] === null ? '—' : number_format($summary['roi'], 2), null, 'line-chart', 'orange'],
    ];
    $costCards = [[$adLabel, $money($summary['ad_spend']), 'usd', 'bullhorn', 'blue']];
    if ($performanceFormula) {
        $resultCards[] = ['profit', $yuan($summary['profit_rmb']), 'rmb', 'area-chart', 'green'];
        $costCards[] = ['service_fee', $money($summary['service_fee']), 'usd', 'handshake-o', 'cyan'];
        $costCards[] = ['store_fee', $money($summary['store_fee']), 'usd', 'credit-card', 'gray'];
        $costCards[] = ['misc_fee', $yuan($summary['misc_fee_rmb']), 'rmb', 'file-text-o', 'orange'];
    }
    $costColumns = [$adLabel => 'usd'];
    if ($performanceFormula) {
        $costColumns += ['service_fee' => 'usd', 'store_fee' => 'usd', 'misc_fee' => 'rmb'];
    }
    // Keep the paginator's records and group accounts within this page by their actual owner.
    $groups = $rows->getCollection()->groupBy(static function ($row): string {
        return (int) $row->owner_user_id > 0
            ? 'operator|'.$row->report_date.'|'.$row->owner_user_id.'|'.$row->department_name
            : 'store|'.$row->report_date.'|'.$row->store_id;
    });
    $platformLabel = static fn ($platform) => match(strtolower((string) $platform)) {
        'xshoppy', 'xshopy' => 'XShoppy',
        'shopline' => 'Shopline',
        'shopify' => 'Shopify',
        default => $platform ?: '—',
    };
@endphp

<section class="content-header"><h1>{{ __($copy.'title') }}</h1></section>
<section class="content flt bg-performance" data-bg-performance>
    <form class="flt-card flt-filters" method="get" action="{{ $reportUrl }}" data-performance-filter data-loading="region" data-loading-target="#bg-performance-records">
        <input type="hidden" name="per_page" value="{{ $rows->perPage() }}">
        <div class="flt-filter-grid bg-performance-filters">
            <label>{{ __($copy.'start') }}<input type="date" name="date_from" value="{{ $filters['date_from'] }}"></label>
            <label>{{ __($copy.'end') }}<input type="date" name="date_to" value="{{ $filters['date_to'] }}"></label>
            @include('portals.fashion.partials.baokuan-general-single-picker', [
                'name' => 'timezone', 'label' => __($copy.'timezone'), 'options' => $timezoneOptions,
                'selected' => $filters['timezone'], 'searchable' => true,
            ])
            @include('portals.fashion.partials.baokuan-general-single-picker', [
                'name' => 'site_builder', 'label' => __($copy.'platform'),
                'options' => ['' => __($copy.'all_platforms')] + $siteBuilderOptions,
                'selected' => $filters['site_builder'],
            ])
            @if($canSelectOperator)
                @include('portals.fashion.partials.baokuan-general-single-picker', [
                    'name' => 'operator_id', 'label' => __($copy.'operator'),
                    'options' => ['' => __($copy.'all_operators')] + $operatorNames->all(),
                    'selected' => $filters['operator_id'], 'searchable' => true,
                    'keywords' => $operators->pluck('username', 'id')->all(),
                ])
            @endif
        </div>
        <div class="bg-performance-filter-foot">
            <div class="bg-performance-dates" aria-label="{{ __($copy.'quick_dates') }}">
                @foreach(['today', 'yesterday', 'last_7_days', 'this_month'] as $shortcut)
                    <button type="button" class="flt-btn" data-performance-date="{{ $shortcut }}">{{ __($copy.$shortcut) }}</button>
                @endforeach
            </div>
            <div class="flt-filter-actions">
                <button type="submit" class="flt-btn flt-primary"><i class="fa fa-search" aria-hidden="true"></i> {{ __('frontend_list_template.query') }}</button>
                <a class="flt-btn" href="{{ $reportUrl }}">{{ __('frontend_list_template.reset') }}</a>
            </div>
        </div>
    </form>

    @foreach(['results' => $resultCards, 'costs' => $costCards] as $groupTitle => $cards)
        <section aria-label="{{ __($copy.$groupTitle) }}">
            <h2 class="bg-performance-card-title">{{ __($copy.$groupTitle) }}</h2>
            <div class="flt-stat-grid bg-performance-stats bg-performance-stats-{{ $groupTitle }}">
                @foreach($cards as [$key, $value, $unit, $icon, $tone])
                    <article class="flt-stat" data-stat-tone="{{ $tone }}">
                        <span class="flt-stat-icon"><i class="fa fa-{{ $icon }}" aria-hidden="true"></i></span>
                        <div><h3>{{ __($copy.$key) }}</h3><p>
                            <strong @class(['bg-performance-negative' => $key === 'profit' && $summary['profit_rmb'] < 0])>{{ $value }}</strong>
                            @if($unit)<span>{{ __($copy.$unit) }}</span>@endif
                        </p></div>
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach

    <details class="flt-guidance">
        <summary><i class="fa fa-info-circle" aria-hidden="true"></i><strong>{{ __($copy.'rules') }}</strong>
            <span class="flt-guidance-toggle"><span class="flt-guidance-expand">{{ __('frontend_list_template.guidance_expand') }}</span><span class="flt-guidance-collapse">{{ __('frontend_list_template.guidance_collapse') }}</span></span>
        </summary>
        <ul class="flt-guidance-items">
            <li>{{ __($copy.($operationProject['code'] === 'fuzhuang' ? 'fashion_order_rule' : 'order_rule')) }}</li>
            <li>{{ __($copy.'advertising_rule') }}</li>
            @if($performanceFormula)
                <li>{{ __($copy.'fee_rule', ['fee' => $percent($performanceFormula['store_fee_rate'])]) }}</li>
                <li>{{ __($copy.'profit_rule', ['margin' => $percent($performanceFormula['gross_margin_rate']), 'rate' => number_format($performanceFormula['usd_to_rmb_rate'], 1)]) }}</li>
            @else
                <li>{{ __($copy.'actual_roi_rule') }}</li>
            @endif
        </ul>
    </details>

    <section class="flt-card flt-records" id="bg-performance-records" aria-label="{{ __($copy.'details') }}">
        <div class="flt-toolbar"><div class="flt-selection">{{ __('frontend_list_template.records', ['count' => $rows->total()]) }}</div></div>
        @if($rows->isNotEmpty())
            <div class="flt-table-scroll bg-performance-scroll" tabindex="0" role="region" aria-label="{{ __($copy.'details') }}">
                <table class="bg-performance-table">
                    <thead><tr>
                        @foreach(['operator_info', 'store_info', 'orders'] as $column)<th scope="col" class="bg-col-{{ $column }}">{{ __($copy.$column) }}</th>@endforeach
                        @foreach(['revenue', 'average'] as $column)<th scope="col">{{ __($copy.'with_unit', ['label' => __($copy.$column), 'unit' => __($copy.'usd')]) }}</th>@endforeach
                        <th scope="col" class="bg-col-media">{{ __($copy.'media', ['label' => __($copy.$adLabel)]) }}</th>
                        @foreach($costColumns as $column => $unit)<th scope="col">{{ __($copy.'with_unit', ['label' => __($copy.$column), 'unit' => __($copy.$unit)]) }}</th>@endforeach
                        <th scope="col">{{ __($copy.'roi') }}</th>
                        @if($performanceFormula)<th scope="col">{{ __($copy.'with_unit', ['label' => __($copy.'profit'), 'unit' => __($copy.'rmb')]) }}</th>@endif
                    </tr></thead>
                    @foreach($groups as $group)
                        <tbody class="bg-performance-group">
                            @foreach($group as $row)
                                <tr>
                                    @if($loop->first)
                                        <th scope="rowgroup" rowspan="{{ $group->count() }}" class="bg-performance-owner">
                                            <strong>{{ $row->owner_name ?: $row->owner_username ?: __($copy.'unbound') }}</strong>
                                            <small>{{ $row->department_name }}</small>
                                            <time datetime="{{ $row->report_date }}">{{ $row->report_date }}</time>
                                            <small>{{ __($copy.'hire_days') }} · {{ $row->hire_days === null ? '—' : __($copy.'days', ['count' => $row->hire_days]) }}</small>
                                        </th>
                                    @endif
                                    <td class="flt-name"><strong>{{ $row->shop_name ?: $row->domain }}</strong><small>{{ $row->domain ?: '—' }}</small><small>{{ $platformLabel($row->site_builder) }}</small></td>
                                    <td><button type="button" class="flt-row-action bg-performance-order-link" aria-haspopup="dialog" data-order-date="{{ $row->report_date }}" data-store-id="{{ $row->store_id }}" data-store-name="{{ $row->shop_name ?: $row->domain }}">{{ $row->order_count }}</button></td>
                                    <td class="bg-performance-money">{{ $money($row->revenue) }}</td>
                                    <td class="bg-performance-money">{{ $row->order_count > 0 ? $money($row->revenue / $row->order_count) : '—' }}</td>
                                    @if($loop->first)
                                        <td rowspan="{{ $group->count() }}" class="bg-performance-media">
                                            @forelse($row->media_metrics as $medium)
                                                <div><span>{{ $medium['name'] }}</span><span>{{ $money($medium['spend']) }}</span></div>
                                            @empty
                                                <small>{{ __($copy.'no_advertising') }}</small>
                                            @endforelse
                                        </td>
                                        <td class="bg-performance-money" rowspan="{{ $group->count() }}">{{ $money($row->book_ad_spend) }}</td>
                                        @if($performanceFormula)
                                            <td class="bg-performance-money" rowspan="{{ $group->count() }}">{{ $money($row->service_fee) }}</td>
                                            <td class="bg-performance-money" rowspan="{{ $group->count() }}">{{ $money($row->store_fee) }}</td>
                                            <td class="bg-performance-money" rowspan="{{ $group->count() }}">{{ $yuan($row->misc_fee_rmb) }}</td>
                                        @endif
                                        <td rowspan="{{ $group->count() }}">{{ $row->total_roi === null ? '—' : number_format($row->total_roi, 2) }}</td>
                                        @if($performanceFormula)
                                            <td @class(['bg-performance-money', 'bg-performance-negative' => $row->profit_rmb < 0]) rowspan="{{ $group->count() }}">{{ $yuan($row->profit_rmb) }}</td>
                                        @endif
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </table>
            </div>
        @else
            <div class="flt-empty"><i class="fa fa-search" aria-hidden="true"></i><h4>{{ __('frontend_list_template.empty_title') }}</h4><p>{{ __('frontend_list_template.empty_hint') }}</p></div>
        @endif
        <nav class="flt-pagination bg-performance-pagination" aria-label="{{ __('frontend_list_template.pagination') }}">
            <form method="get" action="{{ $reportUrl }}" data-loading="region" data-loading-target="#bg-performance-records">
                @foreach(array_diff_key($query, ['per_page' => true]) as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                <label>{{ __('frontend_list_template.per_page') }}
                    <select name="per_page" data-performance-page-size aria-label="{{ __('frontend_list_template.page_size') }}">
                        @foreach([20, 50, 100] as $size)<option value="{{ $size }}" @selected($rows->perPage() === $size)>{{ $size }}</option>@endforeach
                    </select>
                </label>
            </form>
            @if($rows->onFirstPage())<button type="button" class="flt-btn" disabled>{{ __('frontend_list_template.previous') }}</button>
            @else<a class="flt-btn" href="{{ $rows->previousPageUrl() }}" rel="prev">{{ __('frontend_list_template.previous') }}</a>@endif
            <strong>{{ __('frontend_list_template.page_label', ['page' => $rows->currentPage(), 'pages' => $rows->lastPage()]) }}</strong>
            @if($rows->hasMorePages())<a class="flt-btn" href="{{ $rows->nextPageUrl() }}" rel="next">{{ __('frontend_list_template.next') }}</a>
            @else<button type="button" class="flt-btn" disabled>{{ __('frontend_list_template.next') }}</button>@endif
            <form method="get" action="{{ $reportUrl }}" class="bg-performance-jump" data-loading="region" data-loading-target="#bg-performance-records">
                @foreach($query as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                <label>{{ __('frontend_list_template.jump_to') }}
                    <input type="number" name="{{ $rows->getPageName() }}" min="1" max="{{ $rows->lastPage() }}" step="1" required placeholder="{{ __('frontend_list_template.page_placeholder') }}">
                </label>
                <button type="submit" class="flt-btn flt-primary">{{ __('frontend_list_template.jump') }}</button>
            </form>
        </nav>
    </section>

    <div class="modal fade bg-performance-modal" tabindex="-1" role="dialog" aria-labelledby="bg-performance-order-title" data-performance-orders
        data-url="{{ route($operationRoute.'.orders') }}" data-timezone="{{ $filters['timezone'] }}"
        data-texts="{{ json_encode(__('baokuan_general_performance_report'), JSON_UNESCAPED_UNICODE) }}">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <header class="bg-performance-modal-header">
                    <h2 id="bg-performance-order-title">{{ __($copy.'order_details') }}</h2>
                    <button type="button" class="flt-icon-close" data-dismiss="modal" aria-label="{{ __('frontend_list_template.close') }}">×</button>
                </header>
                <div class="bg-performance-modal-body" data-performance-modal-body>
                    <div class="flt-dialog-loading" role="status" data-orders-loading hidden><span class="flt-spinner" aria-hidden="true"></span>{{ __('frontend_list_template.loading') }}</div>
                    <div class="flt-dialog-load-error" role="alert" data-orders-error hidden><p>{{ __($copy.'load_failed') }}</p><button type="button" class="flt-btn flt-primary" data-orders-retry>{{ __($copy.'retry') }}</button></div>
                    <div data-orders-loaded hidden>
                        <div class="bg-performance-order-summary">
                            <span>{{ __($copy.'orders') }} <strong data-order-total></strong></span>
                            <span>{{ __($copy.'revenue') }} <strong data-order-amount></strong></span>
                            <span>{{ __($copy.'timezone') }} <strong>UTC{{ $filters['timezone'] }}</strong></span>
                        </div>
                        <div class="flt-empty" data-orders-empty hidden>{{ __($copy.'no_orders') }}</div>
                        <div class="flt-table-scroll bg-performance-order-scroll" tabindex="0" role="region" aria-labelledby="bg-performance-order-title" data-order-table>
                            <table><thead><tr>
                                @foreach(['order_number', 'created_at', 'paid_at', 'customer', 'products', 'payment_method', 'order_status', 'amount'] as $column)
                                    <th scope="col">{{ __($copy.$column) }}</th>
                                @endforeach
                            </tr></thead><tbody data-order-rows></tbody></table>
                        </div>
                        <nav class="flt-pagination" data-orders-pagination aria-label="{{ __('frontend_list_template.pagination') }}">
                            <button type="button" class="flt-btn" data-order-previous>{{ __('frontend_list_template.previous') }}</button>
                            <strong data-order-page-label data-format="{{ __('frontend_list_template.page_label', ['page' => '__page__', 'pages' => '__pages__']) }}"></strong>
                            <button type="button" class="flt-btn" data-order-next>{{ __('frontend_list_template.next') }}</button>
                            <form data-order-jump data-loading="off">
                                <label>{{ __('frontend_list_template.jump_to') }} <input type="number" min="1" step="1" required placeholder="{{ __('frontend_list_template.page_placeholder') }}"></label>
                                <button type="submit" class="flt-btn flt-primary">{{ __('frontend_list_template.jump') }}</button>
                            </form>
                        </nav>
                    </div>
                </div>
                <footer class="bg-performance-modal-footer"><button type="button" class="flt-btn" data-dismiss="modal">{{ __('frontend_list_template.close') }}</button></footer>
            </div>
        </div>
    </div>
</section>

@push('scripts')
    <script src="{{ asset('js/baokuan-general-list-controls.js') }}?v=20261126-1" defer></script>
    <script src="{{ asset('js/baokuan-general-performance-report.js') }}?v=20261126-2" defer></script>
@endpush
