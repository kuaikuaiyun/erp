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
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/aikaipin-submissions.css?v=20261023-1">
@endpush

@push('scripts')
    <script>window.ErpAikaipinSubmissionConfig = @json($clientConfig);</script>
    <script src="{{ $portalOrigin }}/assets/erp/aikaipin-placement-evaluation.js?v=20261020-1"></script>
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
                <span>{{ trans_choice('aikaipin.placement_evaluation.list.record_count', $evaluations->total(), ['count' => $evaluations->total()]) }}</span>
            </div>
            <div class="aikaipin-submission-list-toolbar-actions">
                <button class="btn btn-primary btn-sm" type="button" data-aikaipin-open-placement-modal>
                    <i class="fa fa-plus" aria-hidden="true"></i> {{ $t('placement_evaluation.actions.new') }}
                </button>
            </div>
        </div>
        <div class="box-body no-padding">
            <div class="table-responsive">
                <table class="table table-hover aikaipin-submission-table aikaipin-placement-evaluation-table">
                    <thead>
                    <tr>
                        <th class="aikaipin-placement-code-column">{{ $t('placement_evaluation.table.evaluation_code') }}</th>
                        <th class="aikaipin-placement-product-column">{{ $t('placement_evaluation.table.related_product') }}</th>
                        <th class="aikaipin-placement-period-column">{{ $t('placement_evaluation.table.test_period') }}</th>
                        <th class="aikaipin-placement-data-column">{{ $t('placement_evaluation.table.placement_data') }}</th>
                        <th class="aikaipin-placement-remark-column">{{ $t('placement_evaluation.fields.remark') }}</th>
                        @if ($canReviewAllPlacementEvaluations)
                            <th class="aikaipin-placement-submitter-column">{{ $t('placement_evaluation.table.submitter') }}</th>
                        @endif
                        <th class="aikaipin-placement-updated-at-column">{{ $t('placement_evaluation.table.updated_at') }}</th>
                        <th class="aikaipin-submission-operation-column">{{ $t('placement_evaluation.table.operation') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($evaluations as $evaluation)
                        @php
                            $keywords = strtolower($evaluation->evaluation_code.' '.$evaluation->product_code.' '.$evaluation->product_name);
                            $updatedAt = $evaluation->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i');
                            $canEditEvaluation = (int) $evaluation->created_by === (int) $user->getKey();
                        @endphp
                        <tr data-aikaipin-list-row data-keyword="{{ $keywords }}">
                            <td class="aikaipin-placement-code-cell"><span class="aikaipin-submission-code">{{ $evaluation->evaluation_code }}</span></td>
                            <td class="aikaipin-placement-product-cell">
                                <strong class="aikaipin-submission-product-name">{{ $evaluation->product_name }}</strong>
                                <span class="aikaipin-submission-product-code">{{ $evaluation->product_code }}</span>
                            </td>
                            <td class="aikaipin-placement-period-cell"><span class="aikaipin-submission-date-range">{{ $evaluation->test_started_on?->format('Y-m-d') }} <i class="fa fa-long-arrow-right" aria-hidden="true"></i> {{ $evaluation->data_ended_on?->format('Y-m-d') }}</span></td>
                            <td class="aikaipin-placement-data-cell">
                                <div class="aikaipin-placement-summary">
                                    @foreach ($evaluation->records as $record)
                                        <span>{{ $t('placement_evaluation.table.record_summary', [
                                            'platform' => $t('placement_evaluation.platform_options.'.$record->platform),
                                            'currency' => $t('placement_evaluation.currency_options.'.$record->currency),
                                            'spend' => number_format((float) $record->actual_spend, 2, '.', ''),
                                            'orders' => (int) $record->orders,
                                        ]) }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="aikaipin-placement-remark-cell">
                                <span class="aikaipin-submission-list-remark" @if ($evaluation->remark) title="{{ $evaluation->remark }}" @endif>{{ $evaluation->remark ?: $t('placement_evaluation.fields.no_value') }}</span>
                            </td>
                            @if ($canReviewAllPlacementEvaluations)
                                <td class="aikaipin-placement-submitter-cell">{{ $evaluation->created_by_name }}</td>
                            @endif
                            <td class="aikaipin-placement-updated-at-cell"><time datetime="{{ $evaluation->updated_at?->toAtomString() }}">{{ $updatedAt }}</time></td>
                            <td class="aikaipin-submission-operation-cell">
                                <div class="btn-group btn-group-sm" role="group" aria-label="{{ $t('placement_evaluation.table.operation') }}">
                                    <button class="btn btn-default" type="button" data-aikaipin-placement-view data-aikaipin-evaluation-id="{{ $evaluation->getKey() }}">
                                        <i class="fa fa-eye" aria-hidden="true"></i> {{ $t('placement_evaluation.actions.view') }}
                                    </button>
                                    @if ($canEditEvaluation)
                                        <button class="btn btn-primary" type="button" data-aikaipin-placement-edit data-aikaipin-evaluation-id="{{ $evaluation->getKey() }}">
                                            <i class="fa fa-pencil" aria-hidden="true"></i> {{ $t('placement_evaluation.actions.edit') }}
                                        </button>
                                        <button class="btn btn-default" type="button" data-aikaipin-placement-delete data-aikaipin-evaluation-id="{{ $evaluation->getKey() }}">
                                            <i class="fa fa-trash-o" aria-hidden="true"></i> {{ $t('placement_evaluation.actions.delete') }}
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    <tr data-aikaipin-list-empty-row @if ($evaluations->isNotEmpty()) hidden @endif>
                        <td colspan="{{ $canReviewAllPlacementEvaluations ? 8 : 7 }}">
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
        @if ($evaluations->hasPages())
            <div class="box-footer clearfix aikaipin-submission-pagination"><div class="pull-right">{{ $evaluations->links() }}</div></div>
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
