@extends('portal.layout')

@section('page_title', __('aikaipin.menus.placement_evaluations'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('aikaipin.'.$key, $replace);
    $placementRouteNames = $placementRouteNames ?? [
        'index' => 'aikaipin.placement-evaluations.index',
        'store' => 'aikaipin.placement-evaluations.store',
        'product_options' => 'aikaipin.placement-evaluations.product-options',
        'product_show' => 'aikaipin.placement-evaluations.products.show',
        'show' => 'aikaipin.placement-evaluations.show',
        'update' => 'aikaipin.placement-evaluations.update',
        'destroy' => 'aikaipin.placement-evaluations.destroy',
        'image' => 'aikaipin.product-library.images.show',
    ];
    $clientConfig = [
        'page' => 'placement-evaluation',
        'serverFilters' => true,
        'filterResetUrl' => route($placementRouteNames['index']),
        'csrfToken' => csrf_token(),
        'noRecords' => $t('placement_evaluation.messages.no_records'),
        'placement' => [
            'storeUrl' => route($placementRouteNames['store']),
            'productOptionsUrl' => route($placementRouteNames['product_options']),
            'showUrlTemplate' => route($placementRouteNames['show'], ['evaluation' => '__evaluation__']),
            'updateUrlTemplate' => route($placementRouteNames['update'], ['evaluation' => '__evaluation__']),
            'destroyUrlTemplate' => route($placementRouteNames['destroy'], ['evaluation' => '__evaluation__']),
            'platformOptions' => trans('aikaipin.placement_evaluation.platform_options'),
            'currencyOptions' => trans('aikaipin.placement_evaluation.currency_options'),
            'newTitle' => $t('placement_evaluation.modal.title'),
            'editTitle' => $t('placement_evaluation.modal.edit_title'),
            'saveLabel' => $t('placement_evaluation.actions.save'),
            'saveChangesLabel' => $t('placement_evaluation.actions.save_changes'),
            'savingLabel' => $t('upload.saving_product'),
            'loadingProducts' => $t('placement_evaluation.messages.loading_products'),
            'productSearchFailed' => $t('placement_evaluation.messages.product_search_failed'),
            'removeData' => $t('placement_evaluation.actions.remove_data'),
            'deleteMessage' => $t('placement_evaluation.actions.delete_confirm'),
            'deleteTitle' => $t('placement_evaluation.messages.delete_title'),
            'deleteFailedTitle' => $t('placement_evaluation.messages.save_failed_title'),
            'saveFailedTitle' => $t('placement_evaluation.messages.save_failed_title'),
            'saveFailedMessage' => $t('placement_evaluation.messages.save_failed_message'),
            'createdTitle' => $t('placement_evaluation.messages.created_title'),
            'createdMessage' => $t('placement_evaluation.messages.created_message'),
            'updatedTitle' => $t('placement_evaluation.messages.updated_title'),
            'updatedMessage' => $t('placement_evaluation.messages.updated_message'),
            'deletedTitle' => $t('placement_evaluation.messages.deleted_title'),
            'deletedMessage' => $t('placement_evaluation.messages.deleted_message'),
            'relatedProductRequiredTitle' => $t('placement_evaluation.messages.related_product_required_title'),
            'relatedProductRequiredMessage' => $t('placement_evaluation.messages.related_product_required_message'),
            'dateRequiredTitle' => $t('placement_evaluation.messages.date_required_title'),
            'dateRequiredMessage' => $t('placement_evaluation.messages.date_required_message'),
            'dateRangeTitle' => $t('placement_evaluation.messages.date_range_title'),
            'dateRangeMessage' => $t('placement_evaluation.messages.date_range_message'),
            'dataRequiredTitle' => $t('placement_evaluation.messages.data_required_title'),
            'dataRequiredMessage' => $t('placement_evaluation.messages.data_required_message'),
            'dataRowTitle' => $t('placement_evaluation.fields.data_row_title', ['number' => ':number']),
            'platformLabel' => $t('placement_evaluation.fields.platform'),
            'currencyLabel' => $t('placement_evaluation.fields.currency'),
            'actualSpendLabel' => $t('placement_evaluation.fields.actual_spend'),
            'actualSpendPlaceholder' => $t('placement_evaluation.fields.actual_spend_placeholder'),
            'ordersLabel' => $t('placement_evaluation.fields.orders'),
            'ordersPlaceholder' => $t('placement_evaluation.fields.orders_placeholder'),
            'roiLabel' => $t('placement_evaluation.fields.roi'),
            'roiPlaceholder' => $t('placement_evaluation.fields.roi_placeholder'),
            'productDisplay' => $t('placement_evaluation.fields.product_display', ['code' => ':code', 'name' => ':name']),
            'dateRangeDisplay' => $t('placement_evaluation.fields.date_range', ['start' => ':start', 'end' => ':end']),
            'detailLabels' => [
                'evaluationCode' => $t('placement_evaluation.table.evaluation_code'),
                'relatedProduct' => $t('placement_evaluation.table.related_product'),
                'testPeriod' => $t('placement_evaluation.table.test_period'),
                'submitter' => $t('placement_evaluation.fields.submitter'),
                'createdAt' => $t('placement_evaluation.fields.created_at'),
                'remark' => $t('placement_evaluation.fields.remark'),
                'records' => $t('placement_evaluation.table.placement_data'),
                'noValue' => $t('placement_evaluation.fields.no_value'),
                'recordSummary' => $t('placement_evaluation.table.record_summary', ['platform' => ':platform', 'currency' => ':currency', 'spend' => ':spend', 'orders' => ':orders', 'roi' => ':roi']),
            ],
        ],
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261125-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/aikaipin-submissions.css?v=20261125-3">
    <style>
    .operation-placement-template.flt{padding:15px}.operation-placement-template .operation-placement-filters{margin-bottom:15px;padding:18px 20px 15px}.operation-placement-filter-grid{display:grid;grid-template-columns:minmax(260px,1.6fr) repeat(4,minmax(150px,1fr));align-items:start;gap:14px 18px}.operation-placement-filter-grid>label>input{margin-top:8px}.operation-placement-filter-grid .operation-ai-template-picker-field{min-width:0}.operation-placement-filter-grid .flt-field-label{margin-bottom:8px}.operation-placement-filter-grid .flt-picker-panel{z-index:108}.operation-placement-filter-grid .operation-ai-template-picker-search{min-height:34px!important;margin-bottom:5px;padding:7px 10px!important}.operation-placement-filter-grid .flt-picker-options{margin-top:0}.operation-placement-filter-grid .flt-picker-options label{font-weight:400}.operation-placement-filter-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:13px}.operation-placement-records{overflow:hidden}.operation-placement-records .flt-toolbar{padding:12px 18px}.operation-placement-table-scroll{max-height:calc(100vh - 300px);overflow:auto}.operation-placement-table{min-width:1320px!important;margin:0!important;border-collapse:separate!important;border-spacing:0!important;table-layout:fixed}.operation-placement-table thead th{position:sticky;top:0;z-index:4;padding:11px 10px!important;background:#eaf4fa!important;color:#345c75!important;border-right:1px solid #dce8ef!important}.operation-placement-table tbody td{padding:11px 10px!important;border-right:1px solid #e7eef3!important;border-bottom:1px solid #e7eef3!important}.operation-placement-table tbody tr:nth-child(odd)>td{background:#fff}.operation-placement-table tbody tr:nth-child(even)>td{background:#f3f9fd}.operation-placement-table tbody tr:hover>td{background:#eaf5fb!important}.operation-ai-template-pagination{border-top:1px solid #e3edf4}.operation-ai-template-pagination .operation-ai-pagination-total{margin-right:auto}.operation-ai-template-pagination .operation-ai-page-size{margin-right:0}.operation-ai-template-pagination .is-disabled{pointer-events:none;opacity:.45}.operation-ai-template-pagination form{display:flex;align-items:center;gap:8px;margin:0}.operation-ai-template-pagination input[type=hidden]{display:none}.operation-ai-template-pagination .operation-ai-page-jump input{width:64px!important}@media(max-width:1400px){.operation-placement-filter-grid{grid-template-columns:repeat(3,minmax(180px,1fr))}}@media(max-width:900px){.operation-placement-filter-grid{grid-template-columns:repeat(2,minmax(180px,1fr))}}@media(max-width:650px){.operation-placement-template.flt{padding:10px}.operation-placement-filter-grid{grid-template-columns:1fr}.operation-ai-template-pagination{justify-content:center}.operation-ai-template-pagination .operation-ai-pagination-total,.operation-ai-template-pagination .operation-ai-page-size{width:100%;margin:0;justify-content:center}}
    </style>
@endpush

@push('scripts')
    <script>window.ErpAikaipinSubmissionConfig = @json($clientConfig);</script>
    <script src="{{ $portalOrigin }}/assets/erp/aikaipin-placement-evaluation.js?v=20261118-1"></script>
    <script>
    document.addEventListener('DOMContentLoaded',function(){
        var pickers=Array.prototype.slice.call(document.querySelectorAll('[data-operation-ai-template-picker]'));
        pickers.forEach(function(picker){
            var summary=picker.querySelector('[data-picker-summary]'),search=picker.querySelector('[data-picker-search]'),options=picker.querySelectorAll('[data-picker-option]'),empty=picker.querySelector('[data-picker-empty]');
            picker.querySelectorAll('input[type="radio"]').forEach(function(input){input.addEventListener('change',function(){summary.textContent=input.closest('label').querySelector('span').textContent;picker.removeAttribute('open');});});
            if(search)search.addEventListener('input',function(){var keyword=search.value.trim().toLowerCase(),visible=0;options.forEach(function(option){var show=!keyword||option.textContent.toLowerCase().indexOf(keyword)!==-1;option.hidden=!show;if(show)visible++;});empty.hidden=visible!==0;});
            picker.addEventListener('toggle',function(){if(picker.open&&search){search.value='';options.forEach(function(option){option.hidden=false;});empty.hidden=true;setTimeout(function(){search.focus();},0);}});
        });
        document.addEventListener('click',function(event){pickers.forEach(function(picker){if(!picker.contains(event.target))picker.removeAttribute('open');});});
    });
    </script>
@endpush

@section('content')
<section class="content aikaipin-submission-page flt operation-placement-template" data-aikaipin-submission-page="placement-evaluation">
    <form class="flt-card flt-filters operation-placement-filters" method="get" action="{{ route($placementRouteNames['index']) }}" data-aikaipin-list-filter-form>
        <div class="operation-placement-filter-grid">
            <label>{{ $t('placement_evaluation.filters.keyword') }}<input type="search" name="keyword" value="{{ $filters['keyword'] }}" autocomplete="off" placeholder="{{ $t('placement_evaluation.filters.keyword_placeholder') }}" data-aikaipin-list-filter></label>
            @include('portals.operation.ai-product-library.partials.filter-single-picker',['label'=>__('aikaipin.filters.category'),'name'=>'category','selected'=>$filters['category'],'options'=>[''=>__('aikaipin.filters.all')] + collect($categories)->mapWithKeys(fn($category)=>[$category=>$category])->all(),'searchable'=>true])
            @include('portals.operation.ai-product-library.partials.filter-single-picker',['label'=>__('aikaipin.filters.source'),'name'=>'source','selected'=>$filters['source'],'options'=>[''=>__('aikaipin.filters.all')] + trans('aikaipin.upload.source_options')])
            @include('portals.operation.ai-product-library.partials.filter-single-picker',['label'=>__('aikaipin.filters.type'),'name'=>'type','selected'=>$filters['type'],'options'=>[''=>__('aikaipin.filters.all')] + trans('aikaipin.upload.type_options')])
            @include('portals.operation.ai-product-library.partials.filter-single-picker',['label'=>'测评状态','name'=>'evaluation_status','selected'=>$filters['evaluation_status'],'options'=>[''=>'全部状态','evaluated'=>'已测评','unevaluated'=>'未测评']])
            <input type="hidden" name="per_page" value="{{ $filters['per_page'] ?? 15 }}">
        </div>
        <div class="operation-placement-filter-actions"><button class="flt-btn flt-primary" type="submit"><i class="fa fa-search"></i> {{ $t('placement_evaluation.filters.search') }}</button><a class="flt-btn" href="{{ route($placementRouteNames['index']) }}"><i class="fa fa-refresh"></i> {{ $t('placement_evaluation.filters.reset') }}</a></div>
    </form>

    <div class="flt-card flt-records operation-placement-records">
        <div class="flt-toolbar"><div class="flt-selection"><i class="fa fa-line-chart"></i><span>共 {{ $products->total() }} 个产品</span></div></div>
        <div class="flt-table-scroll operation-placement-table-scroll">
            <div>
                <table class="operation-placement-table aikaipin-submission-table aikaipin-placement-evaluation-table">
                    <thead>
                    <tr>
                        <th class="aikaipin-placement-product-image-column">产品图片</th><th class="aikaipin-placement-product-code-column">产品编号</th><th class="aikaipin-placement-product-name-column">产品名称</th><th class="aikaipin-placement-product-category-column">产品分类</th>
                        <th class="aikaipin-placement-product-source-column">来源平台 / 类型</th><th class="aikaipin-placement-product-total-column">投放总数据</th><th class="aikaipin-placement-product-roi-column">平均 ROI</th><th class="aikaipin-placement-product-count-column">测评人数</th><th class="aikaipin-placement-product-updated-column">更新时间</th><th class="aikaipin-placement-product-status-column">测评状态</th><th class="aikaipin-placement-product-action-column">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($products as $product)
                        @php
                            $evaluations = $product->placementEvaluations;
                            $records = $evaluations->flatMap->records;
                            $averageRoi = $records->whereNotNull('roi')->avg(fn($record) => (float) $record->roi);
                            $evaluatorCount = $evaluations->pluck('created_by')->unique()->count();
                            $updatedAt = $evaluations->max('updated_at') ?: $product->updated_at;
                            $images = $product->imageFiles();
                        @endphp
                        <tr data-aikaipin-list-row>
                            <td class="aikaipin-placement-product-image-cell">@if($images !== [])<img src="{{ route($placementRouteNames['image'], [$product, 0, 'thumb' => 1, 'v' => $product->updated_at?->getTimestamp()]) }}" loading="lazy" decoding="async" width="52" height="52" style="width:52px;height:52px;object-fit:cover;border-radius:6px">@else — @endif</td>
                            <td class="aikaipin-placement-product-code-cell"><code>{{ $product->product_code }}</code></td>
                            <td class="aikaipin-placement-product-name-cell"><strong>{{ $product->name }}</strong></td>
                            <td class="aikaipin-placement-product-category-cell">@foreach($product->categoryValues() as $category)<span class="label label-default">{{ $category }}</span> @endforeach</td>
                            <td class="aikaipin-placement-product-source-cell">{{ trans('aikaipin.upload.source_options.'.$product->source_platform) }}<br><small>{{ trans('aikaipin.upload.type_options.'.$product->product_type) }}</small></td>
                            <td class="aikaipin-placement-product-total-cell">
                                @forelse($records->groupBy(fn($record) => strtolower((string) $record->platform)) as $platform => $platformRecords)
                                    @php
                                        $platformTranslationKey = 'aikaipin.placement_evaluation.platform_options.'.$platform;
                                        $platformTranslation = trans($platformTranslationKey);
                                        $platformLabel = $platformTranslation !== $platformTranslationKey ? $platformTranslation : ucfirst($platform);
                                    @endphp
                                    <div class="aikaipin-placement-platform-summary">
                                        <div class="aikaipin-placement-platform-name">
                                            <i class="fa fa-bullhorn" aria-hidden="true"></i>
                                            <span>{{ $platformLabel }}</span>
                                        </div>
                                        <div class="aikaipin-placement-platform-metrics">
                                            @foreach($platformRecords->groupBy(fn($record) => strtoupper((string) $record->currency)) as $currency => $currencyRecords)
                                                <div class="aikaipin-placement-platform-metric">
                                                    <span class="aikaipin-placement-platform-spend">{{ $currency }} {{ number_format($currencyRecords->sum('actual_spend'), 2) }}</span>
                                                    <span class="aikaipin-placement-platform-orders">{{ $currencyRecords->sum('orders') }} 单</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @empty
                                    —
                                @endforelse
                            </td>
                            <td class="aikaipin-placement-product-roi-cell">@if($averageRoi !== null)<strong>{{ number_format($averageRoi, 2) }}</strong>@else — @endif</td>
                            <td class="aikaipin-placement-product-count-cell"><span class="badge bg-light-blue">{{ $evaluatorCount }}</span></td>
                            <td class="aikaipin-placement-product-updated-cell">{{ $updatedAt?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                            <td class="aikaipin-placement-product-status-cell">@if($evaluations->isNotEmpty())<span class="flt-status">已测评</span>@else<span class="flt-status" data-status="pending">未测评</span>@endif</td>
                            <td class="aikaipin-placement-product-action-cell"><button class="flt-row-action" type="button" data-aikaipin-product-placement-view data-url="{{ route($placementRouteNames['product_show'], $product) }}"><i class="fa fa-eye"></i> 查看详情</button></td>
                        </tr>
                    @endforeach
                    <tr data-aikaipin-list-empty-row @if ($products->isNotEmpty()) hidden @endif>
                        <td colspan="11">
                            <div class="aikaipin-submission-empty-state">
                                <i class="fa fa-search" aria-hidden="true"></i>
                                <p data-aikaipin-list-empty-message>{{ $t('placement_evaluation.messages.no_records') }}</p>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @if ($products->isNotEmpty())
            @include('portals.operation.ai-product-library.partials.pagination', ['paginator' => $products])
        @endif
    </div>
</section>

<div id="aikaipin-placement-evaluation-modal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content aikaipin-submission-form" novalidate data-aikaipin-placement-form>
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('placement_evaluation.actions.cancel') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" data-aikaipin-placement-form-title>{{ $t('placement_evaluation.modal.title') }}</h4>
            </div>
            <div class="modal-body">
                <p class="aikaipin-submission-demo-note"><i class="fa fa-info-circle" aria-hidden="true"></i> {{ $t('placement_evaluation.modal.real_notice') }}</p>
                <section class="aikaipin-submission-form-section aikaipin-related-product-section">
                    <header><span>1</span><h5>{{ $t('placement_evaluation.modal.test_information') }}</h5></header>
                    <div class="aikaipin-submission-form-section-body">
                        <div class="form-group aikaipin-related-product-group">
                            <label for="aikaipin-placement-related-product">{{ $t('placement_evaluation.fields.related_product') }}</label>
                            <input id="aikaipin-placement-related-product" class="form-control" type="search" autocomplete="off"
                                placeholder="{{ $t('placement_evaluation.fields.related_product_placeholder') }}" data-aikaipin-related-product-input>
                            <div class="aikaipin-related-product-suggestions" hidden data-aikaipin-related-product-suggestions></div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 form-group">
                                <label for="aikaipin-placement-start-date">{{ $t('placement_evaluation.fields.start_date') }}</label>
                                <input id="aikaipin-placement-start-date" class="form-control" type="date" data-aikaipin-placement-start-date>
                            </div>
                            <div class="col-sm-6 form-group no-margin">
                                <label for="aikaipin-placement-end-date">{{ $t('placement_evaluation.fields.end_date') }}</label>
                                <input id="aikaipin-placement-end-date" class="form-control" type="date" data-aikaipin-placement-end-date>
                            </div>
                        </div>
                    </div>
                </section>
                <section class="aikaipin-submission-form-section">
                    <header><span>2</span><h5>{{ $t('placement_evaluation.modal.placement_data') }}</h5></header>
                    <div class="aikaipin-submission-form-section-body">
                        <div class="aikaipin-placement-data-rows" data-aikaipin-placement-data-rows></div>
                        <button class="btn btn-default btn-sm aikaipin-add-placement-data" type="button" data-aikaipin-add-placement-data>
                            <i class="fa fa-plus" aria-hidden="true"></i> {{ $t('placement_evaluation.actions.add_data') }}
                        </button>
                    </div>
                </section>
                <section class="aikaipin-submission-form-section aikaipin-submission-form-section-last">
                    <header><span>3</span><h5>{{ $t('placement_evaluation.fields.remark') }}</h5></header>
                    <div class="aikaipin-submission-form-section-body">
                        <textarea id="aikaipin-placement-remark" class="form-control" rows="3" maxlength="2000" placeholder="{{ $t('placement_evaluation.fields.remark_placeholder') }}" data-aikaipin-placement-remark></textarea>
                    </div>
                </section>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('placement_evaluation.actions.cancel') }}</button>
                <button type="button" class="btn btn-primary" data-aikaipin-placement-submit><i class="fa fa-check" aria-hidden="true"></i> {{ $t('placement_evaluation.actions.save') }}</button>
            </div>
        </form>
    </div>
</div>

<div id="aikaipin-placement-evaluation-detail-modal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content aikaipin-submission-detail-modal">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('placement_evaluation.actions.close') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">{{ $t('placement_evaluation.modal.detail_title') }}</h4>
            </div>
            <div class="modal-body" data-aikaipin-placement-detail-body></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('placement_evaluation.actions.close') }}</button></div>
        </div>
    </div>
</div>
@endsection
