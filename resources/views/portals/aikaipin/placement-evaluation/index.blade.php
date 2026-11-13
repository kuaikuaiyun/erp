@extends('portal.layout')

@section('page_title', __('aikaipin.menus.placement_evaluations'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('aikaipin.'.$key, $replace);
    $clientConfig = [
        'page' => 'placement-evaluation',
        'serverFilters' => true,
        'filterResetUrl' => route('aikaipin.placement-evaluations.index'),
        'csrfToken' => csrf_token(),
        'noRecords' => $t('placement_evaluation.messages.no_records'),
        'placement' => [
            'storeUrl' => route('aikaipin.placement-evaluations.store'),
            'productOptionsUrl' => route('aikaipin.placement-evaluations.product-options'),
            'showUrlTemplate' => route('aikaipin.placement-evaluations.show', ['evaluation' => '__evaluation__']),
            'updateUrlTemplate' => route('aikaipin.placement-evaluations.update', ['evaluation' => '__evaluation__']),
            'destroyUrlTemplate' => route('aikaipin.placement-evaluations.destroy', ['evaluation' => '__evaluation__']),
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
                'recordSummary' => $t('placement_evaluation.table.record_summary', ['platform' => ':platform', 'currency' => ':currency', 'spend' => ':spend', 'orders' => ':orders']),
            ],
        ],
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/aikaipin-submissions.css?v=20261113-4">
@endpush

@push('scripts')
    <script>window.ErpAikaipinSubmissionConfig = @json($clientConfig);</script>
    <script src="{{ $portalOrigin }}/assets/erp/aikaipin-placement-evaluation.js?v=20261113-3"></script>
@endpush

@section('content')
<section class="content aikaipin-submission-page" data-aikaipin-submission-page="placement-evaluation">
    <div class="box box-solid aikaipin-submission-filter-box">
        <div class="box-body">
            <form class="aikaipin-submission-filter-form" method="get" action="{{ route('aikaipin.placement-evaluations.index') }}" data-aikaipin-list-filter-form>
                <div class="aikaipin-submission-filter-grid">
                    <div class="aikaipin-submission-filter-field">
                        <label for="aikaipin-placement-evaluation-keyword">{{ $t('placement_evaluation.filters.keyword') }}</label>
                        <input id="aikaipin-placement-evaluation-keyword" class="form-control" type="search" name="keyword" value="{{ $filters['keyword'] }}" autocomplete="off"
                            placeholder="{{ $t('placement_evaluation.filters.keyword_placeholder') }}" data-aikaipin-list-filter>
                    </div>
                    <div class="aikaipin-submission-filter-actions">
                        <button class="btn btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ $t('placement_evaluation.filters.search') }}</button>
                        <a class="btn btn-default" href="{{ route('aikaipin.placement-evaluations.index') }}"><i class="fa fa-undo" aria-hidden="true"></i> {{ $t('placement_evaluation.filters.reset') }}</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid aikaipin-submission-list-box">
        <div class="box-header with-border aikaipin-submission-list-toolbar">
            <div class="aikaipin-submission-list-toolbar-summary">
                <i class="fa fa-line-chart" aria-hidden="true"></i>
                <span>共 {{ $products->total() }} 个产品</span>
            </div>
        </div>
        <div class="box-body no-padding">
            <div class="table-responsive">
                <table class="table table-hover aikaipin-submission-table aikaipin-placement-evaluation-table">
                    <thead>
                    <tr>
                        <th>产品图片</th><th>产品编号</th><th>产品名称</th><th>产品分类</th>
                        <th>来源平台 / 类型</th><th>投放总数据</th><th>测评人数</th><th>更新时间</th><th>测评状态</th><th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($products as $product)
                        @php
                            $evaluations = $product->placementEvaluations;
                            $records = $evaluations->flatMap->records;
                            $evaluatorCount = $evaluations->pluck('created_by')->unique()->count();
                            $updatedAt = $evaluations->max('updated_at') ?: $product->updated_at;
                            $images = $product->imageFiles();
                        @endphp
                        <tr data-aikaipin-list-row>
                            <td>@if($images !== [])<img src="{{ route('aikaipin.product-library.images.show', [$product, 0, 'thumb' => 1, 'v' => $product->updated_at?->getTimestamp()]) }}" loading="lazy" decoding="async" width="52" height="52" style="width:52px;height:52px;object-fit:cover;border-radius:6px">@else — @endif</td>
                            <td><code>{{ $product->product_code }}</code></td>
                            <td><strong>{{ $product->name }}</strong></td>
                            <td>@foreach($product->categoryValues() as $category)<span class="label label-default">{{ $category }}</span> @endforeach</td>
                            <td>{{ trans('aikaipin.upload.source_options.'.$product->source_platform) }}<br><small>{{ trans('aikaipin.upload.type_options.'.$product->product_type) }}</small></td>
                            <td>
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
                            <td><span class="badge bg-light-blue">{{ $evaluatorCount }}</span></td>
                            <td>{{ $updatedAt?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                            <td>@if($evaluations->isNotEmpty())<span class="label label-success">已测评</span>@else<span class="label label-danger">未测评</span>@endif</td>
                            <td><button class="btn btn-default btn-sm" type="button" data-aikaipin-product-placement-view data-url="{{ route('aikaipin.placement-evaluations.products.show', $product) }}"><i class="fa fa-eye"></i> 查看详情</button></td>
                        </tr>
                    @endforeach
                    <tr data-aikaipin-list-empty-row @if ($products->isNotEmpty()) hidden @endif>
                        <td colspan="10">
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
            <div class="box-footer aikaipin-submission-pagination">
                @include('portals.aikaipin.product-library.partials.pagination', ['products' => $products])
            </div>
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
