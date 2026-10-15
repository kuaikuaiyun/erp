@extends('portal.layout')

@section('page_title', __('product_requests.link_issues.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('product_requests.'.$key, $replace);
    $coverFor = static fn ($item) => $item->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_OPERATION)->first();
    $remarkText = static function (?string $html): string {
        $text = str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = str_replace("\xC2\xA0", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    };
    $clientConfig = [
        'csrfToken' => csrf_token(), 'noticeTitle' => $t('link_issues.title'), 'loading' => $t('common.loading'),
        'loadFailed' => $t('common.modal_load_failed'), 'submitFailed' => $t('common.modal_submit_failed'),
    ];
@endphp
@push('styles')<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-requests.css?v=20261015-03">@endpush
@push('scripts')<script>window.ErpProductRequestConfig = @json($clientConfig);</script><script src="{{ $portalOrigin }}/assets/erp/product-requests.js?v=20261015-04"></script>@endpush

@section('content')
<section class="content product-request-page">
    <header class="content-header"><h1>{{ $t('link_issues.title') }}</h1><p>{{ $t('link_issues.subtitle') }}</p></header>
    <div class="box box-solid product-request-filter-box"><div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> {{ $t('filters.keyword') }}</h3></div><div class="box-body"><form class="product-request-filter-form" method="get" action="{{ route('product-requests.link-issues.index') }}" data-loading="region" data-loading-target=".product-request-table-box"><div class="product-request-filter-grid"><div class="product-request-filter-field"><label>{{ $t('filters.keyword') }}</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ $t('filters.keyword_placeholder') }}"></div><div class="product-request-filter-field"><label>{{ $t('filters.per_page') }}</label><select class="form-control" name="per_page">@foreach([20, 50, 100] as $perPage)<option value="{{ $perPage }}" @selected($filters['per_page'] === $perPage)>{{ $perPage }}</option>@endforeach</select></div></div><div class="product-request-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ $t('common.search') }}</button><a class="btn btn-default" href="{{ route('product-requests.link-issues.index') }}">{{ $t('common.reset') }}</a></div></form></div></div>
    <div class="box box-solid product-request-table-box"><div class="box-header with-border product-request-list-toolbar"><h3 class="box-title">{{ $t('link_issues.title') }}</h3></div><div class="box-body table-responsive no-padding product-request-table-scroll"><table class="table table-hover product-request-table"><thead><tr><th class="product-request-sticky-left">{{ $t('table.report_no') }}</th><th class="product-request-image-cell">{{ $t('table.product') }}</th><th class="product-request-sku-cell">{{ $t('table.skus') }}</th><th>{{ $t('table.operation_supplier_url') }}</th><th class="product-request-remark-cell">{{ $t('table.review_remark') }}</th><th>{{ $t('table.reviewer') }}</th><th>{{ $t('table.reviewed_at') }}</th><th class="product-request-sticky-right">{{ $t('common.operation') }}</th></tr></thead><tbody>
        @forelse($requests as $productRequest)
            @php
                $cover = $coverFor($productRequest);
                $rejectedReview = $productRequest->placementReviews->first(fn ($review) => $review->status === \App\Models\ProductRequestPlacementReview::STATUS_REJECTED);
                $reviewRemarkText = $rejectedReview ? $remarkText($rejectedReview->remark) : '';
                $reviewRemarkImages = $rejectedReview
                    ? $productRequest->attachments->where('stage', \App\Models\ProductRequestAttachment::STAGE_REVIEW)->take(3)
                    : collect();
            @endphp
            <tr><td class="product-request-sticky-left">{{ $productRequest->report_no }}</td><td>@if($cover)<img class="product-request-thumbnail" src="{{ route('product-requests.attachments.show', [$productRequest, $cover]) }}" alt="{{ $cover->original_name }}" data-product-request-image-preview data-product-request-image-alt="{{ $cover->original_name }}">@else{{ $t('common.not_available') }}@endif</td><td class="product-request-sku-cell">@include('portals.product-requests.partials.sku-tags', ['skus' => $productRequest->skus, 'empty' => $t('common.not_available')])</td><td class="product-request-ellipsis" title="{{ $productRequest->operation_supplier_url }}">@if($productRequest->operation_supplier_url)<a href="{{ $productRequest->operation_supplier_url }}" target="_blank" rel="noopener">{{ $productRequest->operation_supplier_url }}</a>@else{{ $t('common.not_available') }}@endif</td><td class="product-request-remark-cell">
                @if($rejectedReview)
                    @if($reviewRemarkText !== '')<span class="product-request-remark-text">{{ $reviewRemarkText }}</span> @endif
                    @foreach($reviewRemarkImages as $reviewRemarkImage)<img class="product-request-thumbnail" src="{{ route('product-requests.attachments.show', [$productRequest, $reviewRemarkImage]) }}" alt="{{ $reviewRemarkImage->original_name }}" data-product-request-image-preview data-product-request-image-alt="{{ $reviewRemarkImage->original_name }}"> @endforeach
                @else
                    {{ $t('common.not_available') }}
                @endif
            </td><td>{{ $rejectedReview->reviewer_name ?? $t('common.not_available') }}</td><td class="product-request-nowrap-cell">{{ $rejectedReview && $rejectedReview->reviewed_at ? $rejectedReview->reviewed_at->format('Y-m-d H:i') : $t('common.not_available') }}</td><td class="product-request-sticky-right product-request-actions"><a class="btn btn-default btn-xs product-request-action-button product-request-action-view" href="{{ route('product-requests.show', $productRequest) }}" data-product-request-modal-url="{{ route('product-requests.show', ['productRequest' => $productRequest, 'modal' => 1]) }}"><i class="fa fa-eye"></i> {{ $t('common.view') }}</a></td></tr>
        @empty
            <tr><td colspan="8" class="product-request-empty">{{ $t('common.no_records') }}</td></tr>
        @endforelse
    </tbody></table></div>@if($requests->hasPages())<div class="box-footer clearfix product-request-pagination" data-loading="region" data-loading-target=".product-request-table-box">{{ $requests->links() }}</div>@endif</div>
    @include('portals.product-requests.partials.modal')
</section>
@endsection
