@extends('portal.layout')

@section('page_title', __('aikaipin.menus.selection_proposals'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('aikaipin.'.$key, $replace);
    $clientConfig = [
        'page' => 'selection-proposal',
        'serverFilters' => true,
        'filterResetUrl' => route('aikaipin.selection-proposals.index'),
        'csrfToken' => csrf_token(),
        'noRecords' => $t('selection_proposal.messages.no_records'),
        'selection' => [
            'tagEmptyTitle' => $t('selection_proposal.messages.tag_empty_title'),
            'tagEmptyMessage' => $t('selection_proposal.messages.tag_empty_message'),
            'imageLimitTitle' => $t('selection_proposal.messages.image_limit_title'),
            'imageLimitMessage' => $t('selection_proposal.messages.image_limit_message'),
            'imageRequiredTitle' => $t('selection_proposal.messages.image_required_title'),
            'imageRequiredMessage' => $t('selection_proposal.messages.image_required_message'),
            'imageTooLargeTitle' => $t('selection_proposal.messages.image_too_large_title'),
            'imageTooLargeMessage' => $t('selection_proposal.messages.image_too_large_message', ['name' => ':name']),
            'nameRequiredTitle' => $t('selection_proposal.messages.save_name_required_title'),
            'nameRequiredMessage' => $t('selection_proposal.messages.save_name_required_message'),
            'saveFailedTitle' => $t('selection_proposal.messages.save_failed_title'),
            'saveFailedMessage' => $t('selection_proposal.messages.save_failed_message'),
            'createdTitle' => $t('selection_proposal.messages.created_title'),
            'createdMessage' => $t('selection_proposal.messages.created_message'),
            'updatedTitle' => $t('selection_proposal.messages.updated_title'),
            'updatedMessage' => $t('selection_proposal.messages.updated_message'),
            'withdrawTitle' => $t('selection_proposal.messages.withdraw_title'),
            'withdrawMessage' => $t('selection_proposal.messages.withdraw_message'),
            'withdrawnTitle' => $t('selection_proposal.messages.withdrawn_title'),
            'withdrawnMessage' => $t('selection_proposal.messages.withdrawn_message'),
            'removeItem' => $t('selection_proposal.actions.remove_item', ['name' => ':name']),
            'newTitle' => $t('selection_proposal.modal.title'),
            'editTitle' => $t('selection_proposal.modal.edit_title'),
            'saveLabel' => $t('selection_proposal.actions.save'),
            'saveChangesLabel' => $t('selection_proposal.actions.save_changes'),
            'savingLabel' => $t('upload.saving_product'),
            'storeUrl' => route('aikaipin.selection-proposals.store'),
            'showUrlTemplate' => route('aikaipin.selection-proposals.show', ['proposal' => '__proposal__']),
            'updateUrlTemplate' => route('aikaipin.selection-proposals.update', ['proposal' => '__proposal__']),
            'withdrawUrlTemplate' => route('aikaipin.selection-proposals.withdraw', ['proposal' => '__proposal__']),
            'detailLabels' => [
                'proposalCode' => $t('selection_proposal.table.proposal_code'),
                'productName' => $t('selection_proposal.fields.product_name'),
                'specifications' => $t('selection_proposal.fields.specifications'),
                'attributes' => $t('selection_proposal.fields.attributes'),
                'link1688' => $t('selection_proposal.fields.link_1688'),
                'competitorLink' => $t('selection_proposal.fields.competitor_link'),
                'remark' => $t('selection_proposal.fields.remark'),
                'creator' => $t('selection_proposal.fields.creator'),
                'createdAt' => $t('selection_proposal.fields.created_at'),
                'withdrawnAt' => $t('selection_proposal.fields.withdrawn_at'),
                'images' => $t('selection_proposal.modal.attachments'),
                'noValue' => $t('selection_proposal.fields.no_value'),
            ],
        ],
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/aikaipin-submissions.css?v=20261020-2">
@endpush

@push('scripts')
    <script>window.ErpAikaipinSubmissionConfig = @json($clientConfig);</script>
    <script src="{{ $portalOrigin }}/assets/erp/aikaipin-selection-proposal.js?v=20261020-1"></script>
@endpush

@section('content')
<section class="content aikaipin-submission-page" data-aikaipin-submission-page="selection-proposal">
    <div class="box box-solid aikaipin-submission-filter-box">
        <div class="box-body">
            <form class="aikaipin-submission-filter-form" method="get" action="{{ route('aikaipin.selection-proposals.index') }}" data-aikaipin-list-filter-form>
                <div class="aikaipin-submission-filter-grid">
                    <div class="aikaipin-submission-filter-field">
                        <label for="aikaipin-selection-proposal-keyword">{{ $t('selection_proposal.filters.keyword') }}</label>
                        <input id="aikaipin-selection-proposal-keyword" class="form-control" type="search" name="keyword" value="{{ $filters['keyword'] }}" autocomplete="off"
                            placeholder="{{ $t('selection_proposal.filters.keyword_placeholder') }}" data-aikaipin-list-filter>
                    </div>
                    <div class="aikaipin-submission-filter-actions">
                        <button class="btn btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ $t('selection_proposal.filters.search') }}</button>
                        <a class="btn btn-default" href="{{ route('aikaipin.selection-proposals.index') }}"><i class="fa fa-undo" aria-hidden="true"></i> {{ $t('selection_proposal.filters.reset') }}</a>
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
                <table class="table table-hover aikaipin-submission-table aikaipin-selection-proposal-table">
                    <thead>
                    <tr>
                        <th>{{ $t('selection_proposal.table.proposal_code') }}</th>
                        <th>{{ $t('selection_proposal.table.product_name') }}</th>
                        <th>{{ $t('selection_proposal.table.specifications') }}</th>
                        <th>{{ $t('selection_proposal.table.attributes') }}</th>
                        @if ($canReviewAllSelectionProposals)
                            <th>{{ $t('selection_proposal.table.submitter') }}</th>
                        @endif
                        <th>{{ $t('selection_proposal.table.status') }}</th>
                        <th>{{ $t('selection_proposal.table.updated_at') }}</th>
                        <th class="aikaipin-submission-operation-column">{{ $t('selection_proposal.table.operation') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($proposals as $proposal)
                        @php
                            $specifications = $proposal->tags->where('type', \App\Models\AikaipinSelectionProposalTag::TYPE_SPECIFICATION)->pluck('value')->all();
                            $attributes = $proposal->tags->where('type', \App\Models\AikaipinSelectionProposalTag::TYPE_ATTRIBUTE)->pluck('value')->all();
                            $keywords = strtolower(implode(' ', array_merge([$proposal->proposal_code, $proposal->product_name], $specifications, $attributes)));
                            $updatedAt = $proposal->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i');
                            $canEditProposal = (int) $proposal->created_by === (int) $user->getKey() && $proposal->withdrawn_at === null;
                        @endphp
                        <tr data-aikaipin-list-row data-keyword="{{ $keywords }}">
                            <td><span class="aikaipin-submission-code">{{ $proposal->proposal_code }}</span></td>
                            <td><strong class="aikaipin-submission-product-name">{{ $proposal->product_name }}</strong></td>
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
                            @if ($canReviewAllSelectionProposals)
                                <td>{{ $proposal->created_by_name }}</td>
                            @endif
                            <td>
                                @if ($proposal->withdrawn_at)
                                    <span class="label label-default">{{ $t('selection_proposal.messages.status_withdrawn') }}</span>
                                @else
                                    <span class="label label-success">{{ $t('selection_proposal.messages.status_active') }}</span>
                                @endif
                            </td>
                            <td><time datetime="{{ $proposal->updated_at?->toAtomString() }}">{{ $updatedAt }}</time></td>
                            <td class="aikaipin-submission-operation-cell">
                                <div class="btn-group btn-group-sm" role="group" aria-label="{{ $t('selection_proposal.table.operation') }}">
                                    <button class="btn btn-default" type="button" data-aikaipin-selection-view data-aikaipin-proposal-id="{{ $proposal->getKey() }}">
                                        <i class="fa fa-eye" aria-hidden="true"></i> {{ $t('selection_proposal.actions.view') }}
                                    </button>
                                    @if ($canEditProposal)
                                        <button class="btn btn-primary" type="button" data-aikaipin-selection-edit data-aikaipin-proposal-id="{{ $proposal->getKey() }}">
                                            <i class="fa fa-pencil" aria-hidden="true"></i> {{ $t('selection_proposal.actions.edit') }}
                                        </button>
                                        <button class="btn btn-default" type="button" data-aikaipin-selection-withdraw data-aikaipin-proposal-id="{{ $proposal->getKey() }}">
                                            <i class="fa fa-reply" aria-hidden="true"></i> {{ $t('selection_proposal.actions.withdraw') }}
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    <tr data-aikaipin-list-empty-row @if ($proposals->isNotEmpty()) hidden @endif>
                        <td colspan="{{ $canReviewAllSelectionProposals ? 8 : 7 }}">
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
        @if ($proposals->total() > 0)
            <div class="box-footer clearfix aikaipin-submission-pagination">
                <div class="pull-right">{{ $proposals->links() }}</div>
            </div>
        @endif
    </div>
</section>

<div id="aikaipin-selection-proposal-modal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content aikaipin-submission-form" novalidate data-aikaipin-selection-form>
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('selection_proposal.actions.cancel') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" data-aikaipin-selection-form-title>{{ $t('selection_proposal.modal.title') }}</h4>
            </div>
            <div class="modal-body">
                <p class="aikaipin-submission-demo-note"><i class="fa fa-info-circle" aria-hidden="true"></i> {{ $t('selection_proposal.modal.real_notice') }}</p>
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
                                <input id="aikaipin-selection-specification" class="form-control" type="text" autocomplete="off" placeholder="{{ $t('selection_proposal.fields.specification_placeholder') }}" data-aikaipin-tag-input="specification">
                                <span class="input-group-btn"><button class="btn btn-default" type="button" data-aikaipin-add-tag="specification"><i class="fa fa-plus" aria-hidden="true"></i> {{ $t('selection_proposal.actions.add_specification') }}</button></span>
                            </div>
                            <div class="aikaipin-submission-tags" data-aikaipin-tag-list="specification" aria-live="polite"></div>
                        </div>
                        <div class="form-group">
                            <label for="aikaipin-selection-attribute">{{ $t('selection_proposal.fields.attributes') }}</label>
                            <div class="input-group">
                                <input id="aikaipin-selection-attribute" class="form-control" type="text" autocomplete="off" placeholder="{{ $t('selection_proposal.fields.attribute_placeholder') }}" data-aikaipin-tag-input="attribute">
                                <span class="input-group-btn"><button class="btn btn-default" type="button" data-aikaipin-add-tag="attribute"><i class="fa fa-plus" aria-hidden="true"></i> {{ $t('selection_proposal.actions.add_attribute') }}</button></span>
                            </div>
                            <div class="aikaipin-submission-tags" data-aikaipin-tag-list="attribute" aria-live="polite"></div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6 form-group">
                                <label for="aikaipin-selection-1688-url">{{ $t('selection_proposal.fields.link_1688') }}</label>
                                <input id="aikaipin-selection-1688-url" class="form-control" type="url" autocomplete="off" data-aikaipin-selection-1688-url>
                            </div>
                            <div class="col-sm-6 form-group">
                                <label for="aikaipin-selection-competitor-url">{{ $t('selection_proposal.fields.competitor_link') }}</label>
                                <input id="aikaipin-selection-competitor-url" class="form-control" type="url" autocomplete="off" data-aikaipin-selection-competitor-url>
                            </div>
                        </div>
                        <div class="form-group no-margin">
                            <label for="aikaipin-selection-remark">{{ $t('selection_proposal.fields.remark') }}</label>
                            <textarea id="aikaipin-selection-remark" class="form-control" rows="3" maxlength="2000" placeholder="{{ $t('selection_proposal.fields.remark_placeholder') }}" data-aikaipin-selection-remark></textarea>
                        </div>
                    </div>
                </section>
                <section class="aikaipin-submission-form-section aikaipin-submission-form-section-last">
                    <header><span>3</span><h5>{{ $t('selection_proposal.modal.attachments') }}</h5></header>
                    <div class="aikaipin-submission-form-section-body">
                        <input id="aikaipin-selection-images" class="aikaipin-submission-visually-hidden" type="file" accept="image/*" multiple data-aikaipin-selection-images>
                        <label class="aikaipin-submission-file-card" for="aikaipin-selection-images">
                            <span class="aikaipin-submission-file-icon"><i class="fa fa-picture-o" aria-hidden="true"></i></span>
                            <span><strong>{{ $t('selection_proposal.actions.select_images') }}</strong><small>{{ $t('selection_proposal.fields.images_hint') }}</small></span>
                            <i class="fa fa-angle-right" aria-hidden="true"></i>
                        </label>
                        <div class="aikaipin-submission-image-previews" data-aikaipin-selection-image-previews></div>
                    </div>
                </section>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('selection_proposal.actions.cancel') }}</button>
                <button type="button" class="btn btn-primary" data-aikaipin-selection-submit><i class="fa fa-check" aria-hidden="true"></i> {{ $t('selection_proposal.actions.save') }}</button>
            </div>
        </form>
    </div>
</div>

<div id="aikaipin-selection-proposal-detail-modal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content aikaipin-submission-detail-modal">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('selection_proposal.actions.close') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">{{ $t('selection_proposal.modal.detail_title') }}</h4>
            </div>
            <div class="modal-body" data-aikaipin-selection-detail-body></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('selection_proposal.actions.close') }}</button></div>
        </div>
    </div>
</div>
@endsection
