@extends('portal.layout')

@section('page_title', __('ai_products.review.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('ai_products.'.$key, $replace);
    $query = request()->query();
    $currentPage = $submissions->currentPage();
    $lastPage = max(1, $submissions->lastPage());
@endphp
@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261125-1">
<link rel="stylesheet" href="{{ $portalOrigin }}/css/ai-product-review-template.css?v=20261125-2">
@endpush
@push('scripts')<script src="{{ $portalOrigin }}/js/assistant-filter-multiselect.js?v=20261124-2"></script>@endpush

@section('content')
<section class="content flt ai-product-review-page">
    <header class="content-header"><h1>{{ $t('review.title') }}</h1><p>{{ $t('review.subtitle') }}</p></header>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if(isset($errors) && $errors->any())<div class="alert alert-danger"><ul class="list-unstyled" style="margin:0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <form class="flt-card flt-filters ai-product-review-filters" method="get" action="{{ route('ai-products.review.index') }}">
        <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
        <div class="ai-product-review-filter-grid">
            <label>{{ $t('filters.keyword') }}<input type="search" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ $t('filters.keyword_placeholder') }}" autocomplete="off"></label>
            <div class="form-group"><span class="flt-field-label">{{ $t('fields.review_status') }}</span><select name="review_status" data-filter-multiselect data-placeholder="{{ $t('common.all') }}" data-search-placeholder="搜索审核状态"><option value="">{{ $t('common.all') }}</option>@foreach($statusLabels as $value => $label)<option value="{{ $value }}" @selected($filters['review_status'] === $value)>{{ $label }}</option>@endforeach</select></div>
        </div>
        <div class="flt-filter-actions"><button class="flt-btn flt-primary" type="submit"><i class="fa fa-search"></i> {{ $t('common.search') }}</button><a class="flt-btn" href="{{ route('ai-products.review.index') }}"><i class="fa fa-refresh"></i> {{ $t('common.reset') }}</a></div>
    </form>

    <div class="flt-card ai-product-review-records"><div class="flt-toolbar"><div class="flt-selection"><strong>{{ $t('review.list_title') }}</strong><span class="flt-toolbar-divider"></span><span>共 {{ $submissions->total() }} 条记录</span></div></div><div class="ai-product-review-table-wrap"><table class="ai-product-review-table"><thead><tr><th>{{ $t('fields.report_no') }}</th><th>{{ $t('fields.placement_link') }}</th><th>{{ $t('fields.reporter') }}</th><th>{{ $t('fields.remark') }}</th><th>{{ $t('fields.review_status') }}</th><th>{{ $t('fields.reviewer') }}</th><th>{{ $t('fields.submitted_at') }}</th><th>{{ $t('fields.reviewed_at') }}</th><th>{{ $t('fields.operation') }}</th></tr></thead><tbody>
        @forelse($submissions as $submission)
            @php($statusTone = match ((string) $submission->review_status) {
                \App\Models\AiProductSubmission::REVIEW_PENDING => 'pending',
                \App\Models\AiProductSubmission::REVIEW_APPROVED => 'active',
                \App\Models\AiProductSubmission::REVIEW_REJECTED => 'rejected',
                default => 'disabled',
            })
            <tr><td class="ai-product-review-report"><strong>{{ $submission->report_no }}</strong></td><td class="ai-product-review-link"><a href="{{ $submission->placement_link }}" target="_blank" rel="noopener" title="{{ $submission->placement_link }}">{{ $submission->placement_link }}</a></td><td>{{ $submission->operation_display_name }}</td><td class="ai-product-review-remark" title="{{ $submission->review_remark }}">{{ $submission->review_remark ?: '—' }}</td><td><span class="flt-status" data-status="{{ $statusTone }}">{{ $statusLabels[$submission->review_status] ?? $submission->review_status }}</span></td><td>{{ $submission->reviewer_name ?: '—' }}</td><td class="ai-product-review-time">{{ optional($submission->created_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</td><td class="ai-product-review-time">{{ $submission->reviewed_at ? $submission->reviewed_at->timezone('Asia/Shanghai')->format('Y-m-d H:i') : '—' }}</td><td><div class="ai-review-actions">@if($submission->review_status === 'pending')<form method="post" action="{{ route('ai-products.review.approve', $submission) }}" onsubmit="return confirm('{{ $t('review.approve_confirm') }}')">@csrf<button type="submit" class="flt-row-action"><i class="fa fa-check"></i> {{ $t('review.approve_button') }}</button></form><button type="button" class="flt-row-action" data-action="delete" data-ai-reject data-url="{{ route('ai-products.review.reject', $submission) }}" data-report-no="{{ $submission->report_no }}"><i class="fa fa-times"></i> {{ $t('review.reject_button') }}</button>@else<span class="text-muted">—</span>@endif</div></td></tr>
        @empty
            <tr><td colspan="9" class="product-request-empty">{{ $t('common.no_records') }}</td></tr>
        @endforelse
    </tbody></table></div><nav class="flt-pagination ai-product-review-pagination" aria-label="{{ $t('review.list_title') }}分页"><span class="pagination-total">显示 {{ $submissions->firstItem() ?? 0 }}-{{ $submissions->lastItem() ?? 0 }} 条，共 {{ $submissions->total() }} 条</span><form class="page-size" method="get" action="{{ request()->url() }}">@foreach($query as $key => $value)@if(!in_array($key, ['page', 'per_page'], true) && !is_array($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach<label>每页显示 <select name="per_page" onchange="this.form.submit()">@foreach([20,50,100] as $size)<option value="{{ $size }}" @selected($submissions->perPage() === $size)>{{ $size }}</option>@endforeach</select> 条</label></form><a class="flt-btn {{ $submissions->onFirstPage() ? 'is-disabled' : '' }}" href="{{ $submissions->previousPageUrl() ?: '#' }}"><i class="fa fa-angle-left"></i> 上一页</a><strong>第 {{ $currentPage }} / {{ $lastPage }} 页</strong><a class="flt-btn {{ $submissions->hasMorePages() ? '' : 'is-disabled' }}" href="{{ $submissions->nextPageUrl() ?: '#' }}">下一页 <i class="fa fa-angle-right"></i></a><form class="page-jump" method="get" action="{{ request()->url() }}">@foreach($query as $key => $value)@if($key !== 'page' && !is_array($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach<label>跳至 <input type="number" name="page" min="1" max="{{ $lastPage }}" value="{{ $currentPage }}"> 页</label><button class="flt-btn flt-primary" type="submit">跳转</button></form></nav></div>

    <div class="modal fade" id="ai-product-reject-modal" tabindex="-1" role="dialog" aria-labelledby="ai-product-reject-title">
        <div class="modal-dialog" role="document">
            <form method="post" class="modal-content" id="ai-product-reject-form">
                @csrf
                <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('common.close') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="ai-product-reject-title"><i class="fa fa-times-circle"></i> {{ $t('review.reject_modal_title') }}</h4></div>
                <div class="modal-body">
                    <p class="text-muted" id="ai-product-reject-report-no"></p>
                    <div class="form-group">
                        <label for="ai-product-reject-remark">{{ $t('fields.review_remark') }} <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="ai-product-reject-remark" name="review_remark" rows="4" required placeholder="{{ $t('validation.remark_required') }}"></textarea>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('common.cancel') }}</button><button type="submit" class="btn btn-danger"><i class="fa fa-times"></i> {{ $t('review.reject_submit') }}</button></div>
            </form>
        </div>
    </div>
    @push('scripts')
    <script>
        (function () {
            var form = document.getElementById('ai-product-reject-form');
            var reportNo = document.getElementById('ai-product-reject-report-no');
            var remark = document.getElementById('ai-product-reject-remark');
            document.querySelectorAll('[data-ai-reject]').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!form || !window.jQuery) return;
                    form.setAttribute('action', button.getAttribute('data-url'));
                    if (reportNo) reportNo.textContent = button.getAttribute('data-report-no');
                    if (remark) remark.value = '';
                    window.jQuery('#ai-product-reject-modal').modal('show');
                });
            });
        })();
    </script>
    @endpush
@endsection
