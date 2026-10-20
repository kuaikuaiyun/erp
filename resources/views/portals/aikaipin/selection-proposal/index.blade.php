@extends('portal.layout')

@section('page_title', __('aikaipin.menus.selection_proposals'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('aikaipin.'.$key, $replace);
    $clientConfig = [
        'page' => 'selection-proposal',
        'noRecords' => $t('selection_proposal.messages.no_records'),
        'selection' => [
            'tagEmptyTitle' => $t('selection_proposal.messages.tag_empty_title'),
            'tagEmptyMessage' => $t('selection_proposal.messages.tag_empty_message'),
            'imageLimitTitle' => $t('selection_proposal.messages.image_limit_title'),
            'imageLimitMessage' => $t('selection_proposal.messages.image_limit_message'),
            'imageTooLargeTitle' => $t('selection_proposal.messages.image_too_large_title'),
            'imageTooLargeMessage' => $t('selection_proposal.messages.image_too_large_message', ['name' => ':name']),
            'nameRequiredTitle' => $t('selection_proposal.messages.name_required_title'),
            'nameRequiredMessage' => $t('selection_proposal.messages.name_required_message'),
            'submitTitle' => $t('selection_proposal.messages.submit_title'),
            'submitMessage' => $t('selection_proposal.messages.submit_message'),
            'removeItem' => $t('selection_proposal.actions.remove_item', ['name' => ':name']),
        ],
        'viewTitle' => $t('selection_proposal.messages.view_title'),
        'viewMessage' => $t('selection_proposal.messages.view_message'),
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
<section class="content aikaipin-submission-page" data-aikaipin-submission-page="selection-proposal">
    <div class="box box-solid aikaipin-submission-filter-box">
        <div class="box-body">
            <form class="aikaipin-submission-filter-form" data-aikaipin-list-filter-form>
                <div class="aikaipin-submission-filter-grid">
                    <div class="aikaipin-submission-filter-field">
                        <label for="aikaipin-selection-proposal-keyword">{{ $t('selection_proposal.filters.keyword') }}</label>
                        <input id="aikaipin-selection-proposal-keyword" class="form-control" type="search" autocomplete="off"
                            placeholder="{{ $t('selection_proposal.filters.keyword_placeholder') }}" data-aikaipin-list-filter>
                    </div>
                    <div class="aikaipin-submission-filter-actions">
                        <button class="btn btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ $t('selection_proposal.filters.search') }}</button>
                        <button class="btn btn-default" type="reset"><i class="fa fa-undo" aria-hidden="true"></i> {{ $t('selection_proposal.filters.reset') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid aikaipin-submission-list-box">
        <div class="box-header with-border aikaipin-submission-list-toolbar">
            <div class="aikaipin-submission-list-toolbar-actions">
                <button class="btn btn-primary btn-sm" type="button" data-aikaipin-open-modal="selection">
                    <i class="fa fa-plus" aria-hidden="true"></i> {{ $t('selection_proposal.actions.new') }}
                </button>
            </div>
        </div>
        <div class="box-body no-padding">
            <div class="table-responsive">
                <table class="table table-hover aikaipin-submission-table">
                    <thead>
                    <tr>
                        <th>{{ $t('selection_proposal.table.proposal_code') }}</th>
                        <th>{{ $t('selection_proposal.table.product_name') }}</th>
                        <th>{{ $t('selection_proposal.table.specifications') }}</th>
                        <th>{{ $t('selection_proposal.table.attributes') }}</th>
                        <th>{{ $t('selection_proposal.table.updated_at') }}</th>
                        <th class="aikaipin-submission-operation-column">{{ $t('selection_proposal.table.operation') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($proposals as $proposal)
                        @php
                            $proposalName = trans('aikaipin.'.$proposal['name_key']);
                            $specifications = array_map(static fn (string $key): string => trans('aikaipin.'.$key), $proposal['specification_keys']);
                            $attributes = array_map(static fn (string $key): string => trans('aikaipin.'.$key), $proposal['attribute_keys']);
                            $keywords = strtolower(implode(' ', array_merge([$proposal['code'], $proposalName], $specifications, $attributes)));
                        @endphp
                        <tr data-aikaipin-list-row data-keyword="{{ $keywords }}">
                            <td><span class="aikaipin-submission-code">{{ $proposal['code'] }}</span></td>
                            <td><strong class="aikaipin-submission-product-name">{{ $proposalName }}</strong></td>
                            <td>
                                <div class="aikaipin-submission-labels">
                                    @foreach ($specifications as $specification)
                                        <span class="label label-default">{{ $specification }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td>
                                <div class="aikaipin-submission-labels">
                                    @foreach ($attributes as $attribute)
                                        <span class="label label-info">{{ $attribute }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td><time datetime="{{ str_replace(' ', 'T', $proposal['updated_at']) }}">{{ $proposal['updated_at'] }}</time></td>
                            <td class="aikaipin-submission-operation-cell">
                                <button class="btn btn-default btn-sm" type="button" data-aikaipin-submission-view>
                                    <i class="fa fa-eye" aria-hidden="true"></i> {{ $t('selection_proposal.actions.view') }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    <tr data-aikaipin-list-empty-row hidden>
                        <td colspan="6">
                            <div class="aikaipin-submission-empty-state">
                                <i class="fa fa-search" aria-hidden="true"></i>
                                <p data-aikaipin-list-empty-message>{{ $t('selection_proposal.messages.no_records') }}</p>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<div id="aikaipin-selection-proposal-modal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content aikaipin-submission-form" data-aikaipin-selection-form>
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('selection_proposal.actions.cancel') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">{{ $t('selection_proposal.modal.title') }}</h4>
            </div>
            <div class="modal-body">
                <p class="aikaipin-submission-demo-note"><i class="fa fa-info-circle" aria-hidden="true"></i> {{ $t('selection_proposal.modal.demo_notice') }}</p>
                <section class="aikaipin-submission-form-section">
                    <header><span>1</span><h5>{{ $t('selection_proposal.modal.basic_information') }}</h5></header>
                    <div class="aikaipin-submission-form-section-body">
                        <div class="form-group no-margin">
                            <label for="aikaipin-selection-product-name">{{ $t('selection_proposal.fields.product_name') }}</label>
                            <input id="aikaipin-selection-product-name" class="form-control" type="text" maxlength="191" autocomplete="off" data-aikaipin-selection-product-name>
                        </div>
                    </div>
                </section>
                <section class="aikaipin-submission-form-section">
                    <header><span>2</span><h5>{{ $t('selection_proposal.modal.product_information') }}</h5></header>
                    <div class="aikaipin-submission-form-section-body">
                        <div class="form-group">
                            <label for="aikaipin-selection-specification">{{ $t('selection_proposal.fields.specifications') }}</label>
                            <div class="input-group">
                                <input id="aikaipin-selection-specification" class="form-control" type="text" autocomplete="off"
                                    placeholder="{{ $t('selection_proposal.fields.specification_placeholder') }}" data-aikaipin-tag-input="specification">
                                <span class="input-group-btn">
                                    <button class="btn btn-default" type="button" data-aikaipin-add-tag="specification"><i class="fa fa-plus" aria-hidden="true"></i> {{ $t('selection_proposal.actions.add_specification') }}</button>
                                </span>
                            </div>
                            <div class="aikaipin-submission-tags" data-aikaipin-tag-list="specification" aria-live="polite"></div>
                        </div>
                        <div class="form-group">
                            <label for="aikaipin-selection-attribute">{{ $t('selection_proposal.fields.attributes') }}</label>
                            <div class="input-group">
                                <input id="aikaipin-selection-attribute" class="form-control" type="text" autocomplete="off"
                                    placeholder="{{ $t('selection_proposal.fields.attribute_placeholder') }}" data-aikaipin-tag-input="attribute">
                                <span class="input-group-btn">
                                    <button class="btn btn-default" type="button" data-aikaipin-add-tag="attribute"><i class="fa fa-plus" aria-hidden="true"></i> {{ $t('selection_proposal.actions.add_attribute') }}</button>
                                </span>
                            </div>
                            <div class="aikaipin-submission-tags" data-aikaipin-tag-list="attribute" aria-live="polite"></div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 form-group">
                                <label for="aikaipin-selection-1688-url">{{ $t('selection_proposal.fields.link_1688') }}</label>
                                <input id="aikaipin-selection-1688-url" class="form-control" type="url" autocomplete="off">
                            </div>
                            <div class="col-sm-6 form-group">
                                <label for="aikaipin-selection-competitor-url">{{ $t('selection_proposal.fields.competitor_link') }}</label>
                                <input id="aikaipin-selection-competitor-url" class="form-control" type="url" autocomplete="off">
                            </div>
                        </div>
                        <div class="form-group no-margin">
                            <label for="aikaipin-selection-remark">{{ $t('selection_proposal.fields.remark') }}</label>
                            <textarea id="aikaipin-selection-remark" class="form-control" rows="3" maxlength="2000" placeholder="{{ $t('selection_proposal.fields.remark_placeholder') }}"></textarea>
                        </div>
                    </div>
                </section>
                <section class="aikaipin-submission-form-section aikaipin-submission-form-section-last">
                    <header><span>3</span><h5>{{ $t('selection_proposal.modal.attachments') }}</h5></header>
                    <div class="aikaipin-submission-form-section-body">
                        <input id="aikaipin-selection-images" class="aikaipin-submission-visually-hidden" type="file" accept="image/*" multiple data-aikaipin-selection-images>
                        <label class="aikaipin-submission-file-card" for="aikaipin-selection-images">
                            <span class="aikaipin-submission-file-icon"><i class="fa fa-picture-o" aria-hidden="true"></i></span>
                            <span>
                                <strong>{{ $t('selection_proposal.actions.select_images') }}</strong>
                                <small>{{ $t('selection_proposal.fields.images_hint') }}</small>
                            </span>
                            <i class="fa fa-angle-right" aria-hidden="true"></i>
                        </label>
                        <div class="aikaipin-submission-image-previews" data-aikaipin-selection-image-previews></div>
                    </div>
                </section>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('selection_proposal.actions.cancel') }}</button>
                <button type="submit" class="btn btn-primary" data-aikaipin-selection-submit><i class="fa fa-check" aria-hidden="true"></i> {{ $t('selection_proposal.actions.simulate_submit') }}</button>
            </div>
        </form>
    </div>
</div>
@endsection
