@extends('portal.layout')

@section('page_title', __('ai_products.operation.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('ai_products.'.$key, $replace);
@endphp
@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-requests.css?v=20261022-01">
<style>
.ai-status-badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;line-height:16px;white-space:nowrap}
.ai-status-pending{background:#fcf8e3;color:#8a6d3b;border:1px solid #faebcc}
.ai-status-approved{background:#dff0d8;color:#3c763d;border:1px solid #d6e9c6}
.ai-status-rejected{background:#fdeaea;color:#a94442;border:1px solid #f2cfcf}
.ai-product-option-category{color:#8a8a8a;font-size:12px;margin-left:6px}
.ai-product-modal-hint{display:block;margin-top:6px;color:#999;font-size:12px}
</style>
@endpush

@section('content')
<section class="content product-request-page">
    <header class="content-header"><h1>{{ $t('operation.title') }}</h1></header>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if(isset($errors) && $errors->any())<div class="alert alert-danger"><ul class="list-unstyled" style="margin:0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="box box-solid product-request-filter-box"><div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> {{ $t('filters.keyword') }}</h3></div><div class="box-body"><form class="product-request-filter-form" method="get" action="{{ route('ai-products.operation.index') }}"><div class="product-request-filter-grid"><div class="product-request-filter-field"><label>{{ $t('filters.keyword') }}</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ $t('filters.keyword_placeholder') }}"></div><div class="product-request-filter-field"><label>{{ $t('fields.review_status') }}</label><select class="form-control" name="review_status"><option value="">{{ $t('common.all') }}</option>@foreach($statusLabels as $value => $label)<option value="{{ $value }}" @selected($filters['review_status'] === $value)>{{ $label }}</option>@endforeach</select></div><div class="product-request-filter-field"><label>{{ $t('filters.per_page') }}</label><select class="form-control" name="per_page">@foreach([20, 50, 100] as $perPage)<option value="{{ $perPage }}" @selected($filters['per_page'] === $perPage)>{{ $perPage }}</option>@endforeach</select></div></div><div class="product-request-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ $t('common.search') }}</button><a class="btn btn-default" href="{{ route('ai-products.operation.index') }}">{{ $t('common.reset') }}</a></div></form></div></div>

    <div class="box box-solid product-request-table-box"><div class="box-header with-border product-request-list-toolbar"><h3 class="box-title">{{ $t('operation.list_title') }}</h3><span class="product-request-toolbar-spacer"></span><button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#ai-product-create-modal"><i class="fa fa-plus"></i> {{ $t('operation.create_button') }}</button></div><div class="box-body table-responsive no-padding product-request-table-scroll"><table class="table table-hover product-request-table"><thead><tr><th class="product-request-sticky-left">{{ $t('fields.report_no') }}</th><th>{{ $t('fields.placement_link') }}</th><th>{{ $t('fields.reporter') }}</th><th>{{ $t('fields.remark') }}</th><th>{{ $t('fields.review_status') }}</th><th>{{ $t('fields.submitted_at') }}</th><th>{{ $t('fields.reviewed_at') }}</th></tr></thead><tbody>
        @forelse($submissions as $submission)
            <tr><td class="product-request-sticky-left"><strong>{{ $submission->report_no }}</strong></td><td class="product-request-link-wrap"><a href="{{ $submission->placement_link }}" target="_blank" rel="noopener" title="{{ $submission->placement_link }}">{{ $submission->placement_link }}</a></td><td>{{ $submission->operation_display_name }}</td><td class="product-request-name-cell" title="{{ $submission->review_remark }}">{{ $submission->review_remark ?: '—' }}</td><td><span class="ai-status-badge ai-status-{{ $submission->review_status }}">{{ $statusLabels[$submission->review_status] ?? $submission->review_status }}</span></td><td>{{ optional($submission->created_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</td><td>{{ $submission->reviewed_at ? $submission->reviewed_at->timezone('Asia/Shanghai')->format('Y-m-d H:i') : '—' }}</td></tr>
        @empty
            <tr><td colspan="7" class="product-request-empty">{{ $t('common.no_records') }}</td></tr>
        @endforelse
    </tbody></table></div>{{ $submissions->links('portals.product-requests.partials.pagination') }}</div>

    <div class="modal fade" id="ai-product-create-modal" tabindex="-1" role="dialog" aria-labelledby="ai-product-create-title">
        <div class="modal-dialog" role="document">
            <form method="post" action="{{ route('ai-products.operation.store') }}" class="modal-content">
                @csrf
                <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('common.close') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="ai-product-create-title"><i class="fa fa-rocket"></i> {{ $t('operation.create_title') }}</h4></div>
                <div class="modal-body">
                    <div class="form-group{{ $errors->has('placement_link') ? ' has-error' : '' }}">
                        <label for="ai-product-placement-link">{{ $t('fields.placement_link') }} <span class="text-danger">*</span></label>
                        <input type="url" class="form-control" id="ai-product-placement-link" name="placement_link" value="{{ old('placement_link') }}" placeholder="{{ $t('fields.placement_link_placeholder') }}" required>
                        @if($errors->has('placement_link'))<span class="help-block">{{ $errors->first('placement_link') }}</span>@endif
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('common.cancel') }}</button><button type="submit" class="btn btn-primary"><i class="fa fa-paper-plane"></i> {{ $t('operation.create_button') }}</button></div>
            </form>
        </div>
    </div>
    <script>
        @if($errors->has('placement_link'))
        jQuery(function ($) { $('#ai-product-create-modal').modal('show'); });
        @endif
    </script>
@endsection
