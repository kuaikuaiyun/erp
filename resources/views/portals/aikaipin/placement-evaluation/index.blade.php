@extends('portal.layout')

@section('page_title', __('aikaipin.menus.placement_evaluations'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('aikaipin.'.$key, $replace);
    $clientConfig = [
        'page' => 'placement-evaluation',
        'noRecords' => $t('placement_evaluation.messages.no_records'),
        'viewTitle' => $t('placement_evaluation.messages.view_title'),
        'viewMessage' => $t('placement_evaluation.messages.view_message'),
        'placement' => [
            'catalogProducts' => array_map(static fn (array $product): array => [
                'code' => $product['code'],
                'name' => trans('aikaipin.'.$product['name_key']),
            ], $products),
            'platformOptions' => trans('aikaipin.placement_evaluation.platform_options'),
            'currencyOptions' => trans('aikaipin.placement_evaluation.currency_options'),
            'platformLabel' => $t('placement_evaluation.fields.platform'),
            'currencyLabel' => $t('placement_evaluation.fields.currency'),
            'actualSpendLabel' => $t('placement_evaluation.fields.actual_spend'),
            'actualSpendPlaceholder' => $t('placement_evaluation.fields.actual_spend_placeholder'),
            'ordersLabel' => $t('placement_evaluation.fields.orders'),
            'ordersPlaceholder' => $t('placement_evaluation.fields.orders_placeholder'),
            'dataRowTitle' => $t('placement_evaluation.fields.data_row_title', ['number' => ':number']),
            'removeData' => $t('placement_evaluation.actions.remove_data'),
            'relatedProductRequiredTitle' => $t('placement_evaluation.messages.related_product_required_title'),
            'relatedProductRequiredMessage' => $t('placement_evaluation.messages.related_product_required_message'),
            'dateRequiredTitle' => $t('placement_evaluation.messages.date_required_title'),
            'dateRequiredMessage' => $t('placement_evaluation.messages.date_required_message'),
            'dateRangeTitle' => $t('placement_evaluation.messages.date_range_title'),
            'dateRangeMessage' => $t('placement_evaluation.messages.date_range_message'),
            'dataRequiredTitle' => $t('placement_evaluation.messages.data_required_title'),
            'dataRequiredMessage' => $t('placement_evaluation.messages.data_required_message'),
            'submitTitle' => $t('placement_evaluation.messages.submit_title'),
            'submitMessage' => $t('placement_evaluation.messages.submit_message'),
        ],
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/aikaipin-submissions.css?v=20261020-1">
@endpush

@push('scripts')
    <script>window.ErpAikaipinSubmissionConfig = @json($clientConfig);</script>
    <script src="{{ $portalOrigin }}/assets/erp/aikaipin-submissions.js?v=20261019-1"></script>
@endpush

@section('content')
<section class="content aikaipin-submission-page" data-aikaipin-submission-page="placement-evaluation">
    <div class="box box-solid aikaipin-submission-filter-box">
        <div class="box-body">
            <form class="aikaipin-submission-filter-form" data-aikaipin-list-filter-form>
                <div class="aikaipin-submission-filter-grid">
                    <div class="aikaipin-submission-filter-field">
                        <label for="aikaipin-placement-evaluation-keyword">{{ $t('placement_evaluation.filters.keyword') }}</label>
                        <input id="aikaipin-placement-evaluation-keyword" class="form-control" type="search" autocomplete="off"
                            placeholder="{{ $t('placement_evaluation.filters.keyword_placeholder') }}" data-aikaipin-list-filter>
                    </div>
                    <div class="aikaipin-submission-filter-actions">
                        <button class="btn btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ $t('placement_evaluation.filters.search') }}</button>
                        <button class="btn btn-default" type="reset"><i class="fa fa-undo" aria-hidden="true"></i> {{ $t('placement_evaluation.filters.reset') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid aikaipin-submission-list-box">
        <div class="box-header with-border aikaipin-submission-list-toolbar">
            <div class="aikaipin-submission-list-toolbar-actions">
                <button class="btn btn-primary btn-sm" type="button" data-aikaipin-open-modal="placement">
                    <i class="fa fa-plus" aria-hidden="true"></i> {{ $t('placement_evaluation.actions.new') }}
                </button>
            </div>
        </div>
        <div class="box-body no-padding">
            <div class="table-responsive">
                <table class="table table-hover aikaipin-submission-table">
                    <thead>
                    <tr>
                        <th>{{ $t('placement_evaluation.table.evaluation_code') }}</th>
                        <th>{{ $t('placement_evaluation.table.related_product') }}</th>
                        <th>{{ $t('placement_evaluation.table.test_period') }}</th>
                        <th>{{ $t('placement_evaluation.table.placement_data') }}</th>
                        <th>{{ $t('placement_evaluation.table.updated_at') }}</th>
                        <th class="aikaipin-submission-operation-column">{{ $t('placement_evaluation.table.operation') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($evaluations as $evaluation)
                        @php
                            $productName = trans('aikaipin.'.$evaluation['product_name_key']);
                            $keywords = strtolower($evaluation['code'].' '.$evaluation['product_code'].' '.$productName);
                        @endphp
                        <tr data-aikaipin-list-row data-keyword="{{ $keywords }}">
                            <td><span class="aikaipin-submission-code">{{ $evaluation['code'] }}</span></td>
                            <td>
                                <strong class="aikaipin-submission-product-name">{{ $productName }}</strong>
                                <span class="aikaipin-submission-product-code">{{ $evaluation['product_code'] }}</span>
                            </td>
                            <td><span class="aikaipin-submission-date-range">{{ $evaluation['start_date'] }} <i class="fa fa-long-arrow-right" aria-hidden="true"></i> {{ $evaluation['end_date'] }}</span></td>
                            <td>
                                <div class="aikaipin-placement-summary">
                                    @foreach ($evaluation['records'] as $record)
                                        <span>{{ trans('aikaipin.placement_evaluation.platform_options.'.$record['platform']) }} · {{ trans('aikaipin.placement_evaluation.currency_options.'.$record['currency']) }} {{ $record['spend'] }} · {{ $record['orders'] }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td><time datetime="{{ str_replace(' ', 'T', $evaluation['updated_at']) }}">{{ $evaluation['updated_at'] }}</time></td>
                            <td class="aikaipin-submission-operation-cell">
                                <button class="btn btn-default btn-sm" type="button" data-aikaipin-submission-view>
                                    <i class="fa fa-eye" aria-hidden="true"></i> {{ $t('placement_evaluation.actions.view') }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    <tr data-aikaipin-list-empty-row hidden>
                        <td colspan="6">
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
    </div>
</section>

<div id="aikaipin-placement-evaluation-modal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content aikaipin-submission-form" data-aikaipin-placement-form>
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('placement_evaluation.actions.cancel') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">{{ $t('placement_evaluation.modal.title') }}</h4>
            </div>
            <div class="modal-body">
                <p class="aikaipin-submission-demo-note"><i class="fa fa-info-circle" aria-hidden="true"></i> {{ $t('placement_evaluation.modal.demo_notice') }}</p>
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
                        <textarea id="aikaipin-placement-remark" class="form-control" rows="3" maxlength="2000" placeholder="{{ $t('placement_evaluation.fields.remark_placeholder') }}"></textarea>
                    </div>
                </section>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('placement_evaluation.actions.cancel') }}</button>
                <button type="submit" class="btn btn-primary" data-aikaipin-placement-submit><i class="fa fa-check" aria-hidden="true"></i> {{ $t('placement_evaluation.actions.simulate_submit') }}</button>
            </div>
        </form>
    </div>
</div>
@endsection
