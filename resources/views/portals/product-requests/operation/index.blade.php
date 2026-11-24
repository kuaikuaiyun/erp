@extends('portal.layout')

@section('page_title', __('product_requests.operation.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('product_requests.'.$key, $replace);
    $coverFor = static fn ($item) => $item->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_OPERATION)->first();
    $normalizeSkus = static fn (?string $value): array => collect(explode(',', (string) $value))->map(fn (string $sku): string => trim($sku))->filter(fn (string $sku): bool => $sku !== '')->unique()->sort()->values()->all();
    $remarkText = static function (?string $html): string {
        $text = str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = str_replace("\xC2\xA0", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    };
    $clientConfig = [
        'csrfToken' => csrf_token(), 'noticeTitle' => $t('operation.title'), 'loading' => $t('common.loading'),
        'loadFailed' => $t('common.modal_load_failed'), 'submitFailed' => $t('common.modal_submit_failed'),
        'invalidFile' => $t('uploads.invalid_file'), 'tooMany' => $t('uploads.too_many', ['count' => ':count']),
        'removeImage' => $t('uploads.remove_image'), 'reuse' => $t('common.reuse'), 'reuseEmpty' => $t('procurement.reuse_empty'),
        'reuseSelected' => $t('procurement.reuse_selected', ['report_no' => ':report_no']),
        'reuseRequired' => $t('procurement.reuse_check_hint'), 'reuseFailed' => $t('common.modal_load_failed'),
        'skuIncomplete' => $t('help.sku_incomplete', ['count' => ':count']), 'skuDuplicated' => $t('help.sku_duplicated'),
        'skuTooMany' => $t('help.sku_too_many', ['count' => ':count']), 'skuSeparator' => $t('help.sku_separator'),
        'pasteHint' => $t('uploads.paste_hint'),
        'submitProgress' => $t('common.submit_progress'),
        'deleteConfirm' => $t('common.delete_confirm'), 'deleteFailed' => $t('common.delete_failed'),
        'noticeStorageKey' => 'product-request-operation-notice',
        'supplierUrlPrefix' => 'https://detail.1688.com/', 'supplierUrlInvalid' => $t('operation.link_prefix_error'),
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-requests.css?v=20261103-06">
    <link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261124-21">
    <style>
        .product-request-operation-template{padding:15px}.product-request-operation-template>.content-header{padding:0 0 15px}.product-request-operation-template>.content-header h1{margin:0}.product-request-operation-template>.content-header p{margin:6px 0 0;color:#718697}.product-request-operation-filters{margin-bottom:15px;padding:18px 20px 15px}.product-request-operation-filter-grid{display:grid;grid-template-columns:minmax(280px,2fr) minmax(175px,1fr) repeat(2,minmax(190px,1fr));gap:14px 18px;align-items:start}.product-request-operation-filter-grid>label>input{margin-top:8px}.product-request-operation-picker-field{min-width:0}.product-request-operation-picker-field>.flt-field-label{margin-bottom:8px}.product-request-operation-picker[open] summary:after{transform:translateY(-20%) rotate(225deg)}.product-request-operation-picker .flt-picker-panel{z-index:108}.product-request-operation-picker-search{min-height:34px!important;margin-bottom:5px;padding:7px 10px!important}.product-request-operation-picker .flt-picker-options{margin-top:0}.product-request-operation-picker .flt-picker-options label{font-weight:400}.product-request-operation-filter-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:13px}.product-request-operation-records{overflow:hidden;margin-bottom:0}.product-request-operation-records .flt-toolbar{padding:12px 18px}.product-request-operation-table-scroll{max-height:calc(100vh - 300px);overflow:auto!important}.product-request-operation-list{width:100%!important;min-width:1900px!important;margin:0!important;border-collapse:separate!important;border-spacing:0!important;table-layout:fixed!important}.product-request-operation-list th:nth-child(1){width:145px!important}.product-request-operation-list th:nth-child(2){width:105px!important}.product-request-operation-list th:nth-child(3){width:150px!important}.product-request-operation-list th:nth-child(4){width:255px!important}.product-request-operation-list th:nth-child(5){width:135px!important}.product-request-operation-list th:nth-child(6){width:135px!important}.product-request-operation-list th:nth-child(7){width:230px!important}.product-request-operation-list th:nth-child(8){width:110px!important;min-width:110px!important;max-width:none!important}.product-request-operation-list th:nth-child(9){width:190px!important}.product-request-operation-list th:nth-child(10){width:115px!important}.product-request-operation-list th:nth-child(11){width:230px!important}.product-request-operation-list thead th{position:sticky;top:0;z-index:4;padding:11px 10px!important;background:#eaf4fa!important;color:#345c75!important;border-right:1px solid #dce8ef!important;white-space:nowrap}.product-request-operation-list thead .product-request-sticky-left,.product-request-operation-list thead .product-request-sticky-right{background:#eaf4fa!important}.product-request-operation-list tbody td{padding:11px 10px!important;border-right:1px solid #e7eef3!important;border-bottom:1px solid #e7eef3!important;vertical-align:middle!important}.product-request-operation-list tbody tr:nth-child(odd)>td{background:#fff}.product-request-operation-list tbody tr:nth-child(even)>td{background:#f3f9fd}.product-request-operation-list tbody tr:hover>td{background:#eaf5fb!important}.product-request-operation-list .product-request-sticky-left,.product-request-operation-list .product-request-sticky-right{background:var(--flt-row-bg,#fff)!important}.product-request-operation-list tbody tr:nth-child(even)>.product-request-sticky-left,.product-request-operation-list tbody tr:nth-child(even)>.product-request-sticky-right{background:#f3f9fd!important}.product-request-operation-list tbody tr:hover>.product-request-sticky-left,.product-request-operation-list tbody tr:hover>.product-request-sticky-right{background:#eaf5fb!important}.product-request-operation-list .flt-status[data-status=rejected]{border-color:#f0c9c4;background:#fff0ee;color:#bd5c4d}.product-request-operation-list .flt-status[data-status=info]{border-color:#cbdfea;background:#f1f7fb;color:#50758c}.product-request-operation-actions{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:2px;width:100%}.product-request-operation-actions .flt-row-action{display:inline-flex!important;align-items:center!important;gap:4px!important;min-height:auto!important;margin:0!important;padding:4px 7px!important;border:0!important;border-radius:0!important;background:none!important;color:#2188be!important;font-size:12px!important;font-weight:400!important;line-height:1.5!important;white-space:nowrap}.product-request-operation-actions .flt-row-action[data-action=delete]{color:#c96959!important}.product-request-operation-pagination{border-top:1px solid #e3edf4}.product-request-operation-pagination .pagination-total{margin-right:auto}.product-request-operation-pagination form{display:flex;align-items:center;gap:8px;margin:0}.product-request-operation-pagination form input[type=hidden]{display:none}.product-request-operation-pagination .page-jump input{width:64px!important}.product-request-operation-pagination .is-disabled{pointer-events:none;opacity:.45}.product-request-delete-dialog{margin:auto}.product-request-delete-dialog .flt-dialog-content{display:flex;align-items:flex-start;gap:14px}.product-request-delete-icon{display:inline-flex;flex:0 0 38px;align-items:center;justify-content:center;width:38px;height:38px;border-radius:50%;background:#fff1ed;color:#c85e4c;font-size:18px}.product-request-delete-copy strong{display:block;margin-bottom:7px;color:#38566a}.product-request-delete-copy p{margin:0;color:#7c674f;line-height:1.8}@media(max-width:1100px){.product-request-operation-filter-grid{grid-template-columns:repeat(2,minmax(180px,1fr))}}@media(max-width:650px){.product-request-operation-template{padding:10px}.product-request-operation-filter-grid{grid-template-columns:1fr}.product-request-operation-pagination{justify-content:center}.product-request-operation-pagination .pagination-total,.product-request-operation-pagination .page-size{width:100%;margin:0;justify-content:center}.product-request-operation-picker .flt-picker-panel{position:relative;top:5px}}
        .product-request-operation-table-scroll{max-height:none!important;overflow:visible!important;scrollbar-width:none}.product-request-operation-table-scroll::-webkit-scrollbar{display:none}.product-request-operation-list{width:100%!important;min-width:0!important;table-layout:fixed!important}.product-request-operation-list th:nth-child(1){width:7.5%!important}.product-request-operation-list th:nth-child(2){width:5.5%!important}.product-request-operation-list th:nth-child(3){width:8%!important}.product-request-operation-list th:nth-child(4){width:13.5%!important}.product-request-operation-list th:nth-child(5){width:7.5%!important}.product-request-operation-list th:nth-child(6){width:7.5%!important}.product-request-operation-list th:nth-child(7){width:12%!important}.product-request-operation-list th:nth-child(8){width:6%!important;min-width:0!important}.product-request-operation-list th:nth-child(9){width:10%!important}.product-request-operation-list th:nth-child(10){width:6%!important}.product-request-operation-list th:nth-child(11){width:16.5%!important}.product-request-operation-list td{min-width:0!important}.product-request-operation-list .product-request-link-wrap>a{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.product-request-operation-list .product-request-remark-text{display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .product-request-operation-notices{position:fixed;top:64px;right:24px;z-index:1200;display:grid;width:min(410px,calc(100vw - 32px));gap:10px;pointer-events:none}.product-request-operation-notice{display:flex;align-items:flex-start;gap:11px;padding:13px 15px;border:1px solid #cfe1ec;border-left:4px solid #318fbe;border-radius:8px;background:#fff;color:#385a70;box-shadow:0 8px 28px rgba(31,67,91,.16);pointer-events:auto}.product-request-operation-notice>i{margin-top:2px;color:#318fbe;font-size:17px}.product-request-operation-notice.is-success{border-left-color:#36a477}.product-request-operation-notice.is-success>i{color:#36a477}.product-request-operation-notice.is-warning,.product-request-operation-notice.is-error{border-left-color:#d36b57}.product-request-operation-notice.is-warning>i,.product-request-operation-notice.is-error>i{color:#d36b57}.product-request-operation-notice-copy{flex:1;min-width:0;line-height:1.65}.product-request-operation-notice-copy strong{display:block;margin-bottom:2px;color:#294b61}.product-request-operation-notice-close{border:0;background:none;color:#91a2ae;font-size:19px;line-height:1;cursor:pointer}.product-request-operation-flash{margin-bottom:15px}.product-request-editor-modal .modal-content{border:1px solid #d4e3ef;border-radius:12px!important;box-shadow:0 22px 90px rgba(23,54,76,.25)!important}.product-request-editor-modal .modal-header{padding:18px 22px!important;background:#f6faff!important;border-bottom:1px solid #e5edf5!important}.product-request-editor-modal .modal-title{font-size:19px!important;font-weight:600!important}.product-request-editor-modal .modal-body{padding:22px!important;background:#fff}.product-request-editor-modal .modal-footer{display:flex;align-items:center;justify-content:flex-end;gap:9px;padding:15px 22px!important;background:#fbfdff;border-top:1px solid #e8eff5!important}.product-request-editor-modal .modal-footer .pull-left{margin-right:auto}.product-request-editor-modal .form-control{height:38px;border:1px solid #cfdeea;border-radius:5px;box-shadow:none}.product-request-editor-modal textarea.form-control{height:auto}.product-request-editor-modal .form-control:focus{border-color:#339dd3;box-shadow:0 0 0 3px rgba(36,153,209,.1)}.product-request-editor-modal .has-error .form-control{border-color:#ce695a;box-shadow:0 0 0 3px rgba(200,94,76,.07)}.product-request-editor-modal .help-block.text-red,.product-request-editor-modal .product-request-server-error{padding:6px 8px;border-radius:4px;background:#fff4f1;color:#bf5546!important}.product-request-editor-modal .modal-footer .btn{min-height:36px;padding:7px 14px;border-radius:5px}.product-request-editor-modal .modal-footer .btn-success{border-color:#2188be;background:#2188be}.product-request-editor-modal .modal-footer .btn-success:hover{border-color:#187aa9;background:#187aa9}.product-request-form-guidance{display:flex;align-items:flex-start;gap:10px;margin:0 0 17px;padding:12px 14px;border:1px solid #cfe4f1;border-left:4px solid #318fbe;border-radius:7px;background:#f2f9fd;color:#527084;line-height:1.7}.product-request-form-guidance i{margin-top:3px;color:#318fbe;font-size:16px}.product-request-form-guidance strong{color:#2a5874}@media(max-width:650px){.product-request-operation-notices{top:55px;right:16px}.product-request-editor-modal .modal-footer{flex-wrap:wrap}.product-request-editor-modal .modal-footer .pull-left{width:100%;margin:0}}
    </style>
@endpush
@push('scripts')
    <script>window.ErpProductRequestConfig = @json($clientConfig);</script>
    <script src="{{ $portalOrigin }}/assets/erp/product-requests.js?v=20261124-03"></script>
    <script>
    document.addEventListener('DOMContentLoaded',function(){
        var noticeHost=document.querySelector('[data-product-request-operation-notices]');
        function showNotice(type,message){
            if(!noticeHost||!message)return false;
            var notice=document.createElement('div');
            var safeType=type==='success'?'success':(type==='error'?'error':'warning');
            notice.className='product-request-operation-notice is-'+safeType;
            notice.innerHTML='<i class="fa '+(safeType==='success'?'fa-check-circle':'fa-exclamation-circle')+'"></i><div class="product-request-operation-notice-copy"><strong>'+(safeType==='success'?'操作成功':'操作提示')+'</strong><span></span></div><button type="button" class="product-request-operation-notice-close" aria-label="关闭">×</button>';
            notice.querySelector('span').textContent=message;
            notice.querySelector('button').addEventListener('click',function(){notice.remove();});
            noticeHost.appendChild(notice);
            window.setTimeout(function(){if(notice.parentNode)notice.remove();},5000);
            return true;
        }
        window.ErpProductRequestTemplateNotify=showNotice;
        try{var pendingNotice=window.sessionStorage.getItem('product-request-operation-notice');if(pendingNotice){window.sessionStorage.removeItem('product-request-operation-notice');var noticeData=JSON.parse(pendingNotice);showNotice(noticeData.type||'success',noticeData.message||'');}}catch(error){}
        var pickers=Array.prototype.slice.call(document.querySelectorAll('[data-product-request-operation-picker]'));
        pickers.forEach(function(picker){
            var summary=picker.querySelector('[data-picker-summary]');
            var search=picker.querySelector('[data-picker-search]');
            var options=Array.prototype.slice.call(picker.querySelectorAll('[data-picker-option]'));
            var empty=picker.querySelector('[data-picker-empty]');
            options.forEach(function(option){var input=option.querySelector('input[type="radio"]');input.addEventListener('change',function(){summary.textContent=option.querySelector('span').textContent.trim();picker.removeAttribute('open');});});
            if(search){search.addEventListener('input',function(){var keyword=search.value.trim().toLowerCase(),visible=0;options.forEach(function(option){var matched=option.textContent.toLowerCase().indexOf(keyword)!==-1;option.hidden=!matched;if(matched)visible++;});if(empty)empty.hidden=visible!==0;});picker.addEventListener('toggle',function(){if(picker.open){search.value='';options.forEach(function(option){option.hidden=false;});if(empty)empty.hidden=true;setTimeout(function(){search.focus();},0);}});}
        });
        document.addEventListener('click',function(event){pickers.forEach(function(picker){if(picker.open&&!picker.contains(event.target))picker.removeAttribute('open');});});

        var deleteDialog=document.querySelector('[data-product-request-delete-dialog]');
        var deleteMessage=deleteDialog?deleteDialog.querySelector('[data-product-request-delete-message]'):null;
        var deleteConfirm=deleteDialog?deleteDialog.querySelector('[data-product-request-delete-confirm]'):null;
        var pendingDeleteButton=null;
        function closeDeleteDialog(){if(deleteDialog&&deleteDialog.open)deleteDialog.close();pendingDeleteButton=null;}
        if(deleteDialog){
            document.addEventListener('click',function(event){
                var button=event.target.closest?event.target.closest('[data-product-request-delete]'):null;
                if(!button||button.dataset.templateDeleteConfirmed==='1')return;
                event.preventDefault();event.stopImmediatePropagation();
                pendingDeleteButton=button;
                var isDraft=!!button.closest('.product-request-draft-item');
                deleteDialog.querySelector('[data-product-request-delete-title]').textContent=isDraft?'确认删除草稿':'确认删除投放连接提报';
                deleteMessage.textContent=button.getAttribute('data-delete-confirm')||(isDraft?'删除后草稿内容无法恢复，请确认是否继续。':'删除后该投放连接提报记录无法恢复，请确认是否继续。');
                deleteDialog.showModal();
            },true);
            deleteDialog.querySelectorAll('[data-product-request-delete-close]').forEach(function(button){button.addEventListener('click',closeDeleteDialog);});
            deleteDialog.addEventListener('click',function(event){if(event.target===deleteDialog)closeDeleteDialog();});
            deleteConfirm.addEventListener('click',function(){
                if(!pendingDeleteButton)return;
                var button=pendingDeleteButton;
                var oldConfirm=window.ErpProductRequestConfig.deleteConfirm;
                var hadAttribute=button.hasAttribute('data-delete-confirm');
                var oldAttribute=button.getAttribute('data-delete-confirm');
                closeDeleteDialog();
                window.ErpProductRequestConfig.deleteConfirm='';
                button.removeAttribute('data-delete-confirm');
                button.dataset.templateDeleteConfirmed='1';
                button.click();
                window.setTimeout(function(){window.ErpProductRequestConfig.deleteConfirm=oldConfirm;delete button.dataset.templateDeleteConfirmed;if(hadAttribute)button.setAttribute('data-delete-confirm',oldAttribute||'');},0);
            });
        }
    });
    </script>
@endpush

@section('content')
<section class="content product-request-page product-request-operation-template flt">
    <div class="product-request-operation-notices" data-product-request-operation-notices aria-live="polite"></div>
    <header class="content-header"><h1>{{ $t('operation.title') }}</h1><p>{{ $t('operation.subtitle') }}</p></header>
    @if(session('success'))<div class="product-request-operation-notice product-request-operation-flash is-success"><i class="fa fa-check-circle"></i><div class="product-request-operation-notice-copy"><strong>操作成功</strong><span>{{ session('success') }}</span></div></div>@endif
    @if(session('error'))<div class="product-request-operation-notice product-request-operation-flash is-error"><i class="fa fa-exclamation-circle"></i><div class="product-request-operation-notice-copy"><strong>操作提示</strong><span>{{ session('error') }}</span></div></div>@endif
    <form class="flt-card flt-filters product-request-operation-filters" method="get" action="{{ route('product-requests.operation.index') }}" data-loading="region" data-loading-target=".product-request-table-box">
        <div class="product-request-operation-filter-grid">
            <label>{{ $t('filters.keyword') }}<input type="search" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ $t('filters.keyword_placeholder') }}" autocomplete="off"></label>
            <label>{{ $t('filters.reported_date') }}<input type="date" name="reported_date" value="{{ $filters['reported_date'] ?? '' }}"></label>
            @include('portals.product-requests.operation.partials.filter-single-picker', ['label'=>$t('table.staff_status'),'name'=>'status','selected'=>$filters['status'],'options'=>[''=>$t('common.all')] + $statusLabels])
            @include('portals.product-requests.operation.partials.filter-single-picker', ['label'=>$t('filters.review_status'),'name'=>'review_status','selected'=>$filters['review_status'],'options'=>[''=>$t('common.all')] + $reviewStatusLabels])
            <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
        </div>
        <div class="product-request-operation-filter-actions"><button class="flt-btn flt-primary" type="submit"><i class="fa fa-search"></i> {{ $t('common.search') }}</button><a class="flt-btn" href="{{ route('product-requests.operation.index') }}"><i class="fa fa-refresh"></i> {{ $t('common.reset') }}</a></div>
    </form>
    @if(isset($drafts) && $drafts->isNotEmpty())
        <div class="box box-solid product-request-draft-box">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-file-text-o"></i> {{ $t('operation.drafts_title') }} <span class="badge bg-aqua">{{ $drafts->count() }}</span></h3></div>
            <div class="box-body no-padding"><ul class="product-request-draft-list">
                @foreach($drafts as $draft)
                    <li class="product-request-draft-item">
                        <div class="product-request-draft-meta">
                            <strong>{{ $t('operation.draft_label') }} #{{ $draft->getKey() }}</strong>
                            <span class="text-muted">{{ $t('operation.draft_updated_at') }} {{ optional($draft->updated_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</span>
                            <span class="text-muted">{{ $t('table.skus') }}：{{ trim((string) $draft->skus) !== '' ? \Illuminate\Support\Str::limit((string) $draft->skus, 60) : $t('common.not_filled') }}</span>
                        </div>
                        <div class="product-request-draft-actions">
                            <button type="button" class="btn btn-primary btn-xs" data-product-request-modal-url="{{ route('product-requests.operation.drafts.edit', ['draft' => $draft, 'modal' => 1]) }}"><i class="fa fa-pencil"></i> {{ $t('operation.draft_continue') }}</button>
                            <button type="button" class="btn btn-danger btn-xs" data-product-request-delete data-delete-url="{{ route('product-requests.operation.drafts.destroy', $draft) }}" data-delete-confirm="{{ $t('operation.draft_delete_confirm') }}" title="{{ $t('operation.draft_delete_confirm') }}"><i class="fa fa-trash-o"></i> {{ $t('common.delete') }}</button>
                        </div>
                    </li>
                @endforeach
            </ul></div>
        </div>
    @endif
    <div class="flt-card flt-records product-request-table-box product-request-operation-records">
        <div class="flt-toolbar"><div class="flt-selection"><strong>{{ $t('operation.title') }}</strong><span class="flt-toolbar-divider"></span><span>{{ $t('operation.my_scope_hint') }}</span><span class="flt-toolbar-divider"></span><span>共 {{ $requests->total() }} 条</span></div><div class="flt-toolbar-actions"><button type="button" class="flt-btn flt-primary" data-product-request-modal-url="{{ route('product-requests.operation.create', ['modal' => 1]) }}"><i class="fa fa-plus"></i> {{ $t('common.new_request') }}</button></div></div>
        <div class="flt-table-scroll product-request-table-scroll product-request-operation-table-scroll"><table class="product-request-table product-request-operation-table product-request-operation-list"><thead><tr><th class="product-request-sticky-left">{{ $t('table.report_no') }}</th><th>{{ $t('table.reported_at') }}</th><th class="product-request-sku-cell">{{ $t('table.skus') }}</th><th>{{ $t('table.operation_supplier_url') }}</th><th>{{ $t('table.staff_status') }}</th><th class="product-request-link-review-cell">{{ $t('table.review_status') }}</th><th class="product-request-remark-cell">{{ $t('table.review_remark') }}</th><th class="product-request-image-cell">{{ $t('table.product') }}</th><th class="product-request-image-cell product-request-image-cell-multi">{{ $t('table.seller_images') }}</th><th>{{ $t('table.reporter') }}</th><th class="product-request-sticky-right">{{ $t('common.operation') }}</th></tr></thead><tbody>
            @forelse($requests as $productRequest)
                @php
                    $cover = $coverFor($productRequest);
                    $sellerImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_PROCUREMENT)->take(3);
                    $rejectedReview = $productRequest->placementReviews->first(fn ($review) => $review->status === \App\Models\ProductRequestPlacementReview::STATUS_REJECTED);
                    $reviewRemarkText = $rejectedReview ? $remarkText($rejectedReview->remark) : '';
                    $reviewRemarkImages = $rejectedReview
                        ? $productRequest->attachments->where('stage', \App\Models\ProductRequestAttachment::STAGE_REVIEW)->take(3)
                        : collect();
                    $operationSkuSet = collect(explode(',', (string) $productRequest->skus))->map(fn ($sku) => trim($sku))->filter()->values()->all();
                    $procurementSkuSet = collect(explode(',', (string) $productRequest->procurement_skus))->map(fn ($sku) => trim($sku))->filter()->values()->all();
                    $hasSkuChanged = $procurementSkuSet !== [] && $operationSkuSet !== $procurementSkuSet;
                    $staffStatusStyle = trim((string) $productRequest->inspection_reject_reason) !== ''
                        ? 'rejected'
                        : ($productRequest->status === \App\Models\ProductRequest::STATUS_PENDING ? 'pending' : 'enabled');
                    $reviewStatusStyle = match ($productRequest->placement_review_status) {
                        \App\Models\ProductRequest::REVIEW_PENDING => 'pending',
                        \App\Models\ProductRequest::REVIEW_REJECTED => 'rejected',
                        \App\Models\ProductRequest::REVIEW_APPROVED => 'enabled',
                        default => 'disabled',
                    };
                @endphp
                <tr><td class="product-request-sticky-left">{{ $productRequest->report_no }}</td><td class="product-request-nowrap-cell">{{ optional($productRequest->reported_at)->format('Y-m-d') }}</td><td class="product-request-sku-cell">@if($hasSkuChanged)<span class="flt-status" data-status="pending" title="{{ $t('operation.sku_changed_hint', ['operation' => $productRequest->skus ?: $t('common.not_available'), 'procurement' => $productRequest->procurement_skus]) }}">SKU 有变动</span>@else<span class="flt-status" data-status="enabled">{{ $t('operation.sku_normal') }}</span>@endif</td><td class="product-request-link-wrap">@if($productRequest->operation_supplier_url)<a href="{{ $productRequest->operation_supplier_url }}" target="_blank" rel="noopener" title="{{ $productRequest->operation_supplier_url }}">{{ $productRequest->operation_supplier_url }}</a>@else{{ $t('common.not_filled') }}@endif @if($productRequest->operation_supplier_url && $productRequest->supplier_url && $productRequest->supplier_url !== $productRequest->operation_supplier_url)<span class="product-request-badge status-inspection_rejected" title="运营原链接：{{ $productRequest->operation_supplier_url }}&#10;当前链接：{{ $productRequest->supplier_url }}"><i class="fa fa-link"></i> {{ $t('operation.link_changed') }}</span>@endif</td><td>@if(trim((string) $productRequest->inspection_reject_reason) !== '')<span class="flt-status" data-status="{{ $staffStatusStyle }}" title="{{ $productRequest->inspection_reject_reason }}">{{ $t('status.inspection_rejected') }}</span>@else<span class="flt-status" data-status="{{ $staffStatusStyle }}">{{ $statusLabels[$productRequest->status] ?? $t('common.not_available') }}</span>@endif</td><td class="product-request-link-review-cell"><span class="flt-status" data-status="{{ $reviewStatusStyle }}">{{ $reviewStatusLabels[$productRequest->placement_review_status] ?? $t('common.not_available') }}</span></td><td class="product-request-remark-cell">
                    @if($rejectedReview)
                        @if($reviewRemarkText !== '')<span class="product-request-remark-text" title="{{ $reviewRemarkText }}">{{ \Illuminate\Support\Str::limit($reviewRemarkText, 60) }}</span> @endif
                        @foreach($reviewRemarkImages as $reviewRemarkImage)<img class="product-request-thumbnail" loading="lazy" decoding="async" src="{{ route('product-requests.attachments.show', [$productRequest, $reviewRemarkImage]) }}?w=240" alt="" data-product-request-image-preview data-product-request-image-alt="{{ $reviewRemarkImage->original_name }}" onerror="this.classList.add('is-image-missing')"><span class="product-request-image-missing" title="图片暂不可用"><i class="fa fa-picture-o"></i></span> @endforeach
                    @else
                        {{ $t('common.not_available') }}
                    @endif
                </td><td class="product-request-image-cell">@if($cover)<img class="product-request-thumbnail" loading="lazy" decoding="async" src="{{ route('product-requests.attachments.show', [$productRequest, $cover]) }}?w=240" alt="" data-product-request-image-preview data-product-request-image-alt="{{ $cover->original_name }}" onerror="this.classList.add('is-image-missing')"><span class="product-request-image-missing" title="图片暂不可用"><i class="fa fa-picture-o"></i></span>@else{{ $t('common.not_available') }}@endif</td><td class="product-request-image-cell product-request-image-cell-multi">@if($sellerImages->isNotEmpty())@foreach($sellerImages as $sellerImage)<img class="product-request-thumbnail" loading="lazy" decoding="async" src="{{ route('product-requests.attachments.show', [$productRequest, $sellerImage]) }}?w=240" alt="" data-product-request-image-preview data-product-request-image-alt="{{ $sellerImage->original_name }}" onerror="this.classList.add('is-image-missing')"><span class="product-request-image-missing" title="图片暂不可用"><i class="fa fa-picture-o"></i></span>@endforeach @else{{ $t('common.not_available') }}@endif</td><td class="product-request-nowrap-cell">{{ $productRequest->operation_user_name ?: $t('common.not_available') }}</td><td class="product-request-sticky-right product-request-actions product-request-operation-actions"><a class="flt-row-action product-request-action-button product-request-action-view" href="{{ route('product-requests.show', $productRequest) }}" data-product-request-modal-url="{{ route('product-requests.show', ['productRequest' => $productRequest, 'modal' => 1]) }}"><i class="fa fa-eye"></i> {{ $t('common.view') }}</a>@if((int) $productRequest->operation_user_id === (int) $user->getKey() && trim((string) $productRequest->inspection_reject_reason) !== '')<button type="button" class="flt-row-action product-request-action-button" data-product-request-modal-url="{{ route('product-requests.operation.edit', ['productRequest' => $productRequest, 'modal' => 1]) }}"><i class="fa fa-pencil"></i> {{ $t('operation.edit') }}</button>@endif @if((int) $productRequest->operation_user_id === (int) $user->getKey() && $productRequest->status === \App\Models\ProductRequest::STATUS_INSPECTION_PASSED && $productRequest->placement_review_status !== \App\Models\ProductRequest::REVIEW_PENDING && $productRequest->placement_review_status !== \App\Models\ProductRequest::REVIEW_APPROVED)<button type="button" class="flt-row-action product-request-action-button product-request-action-primary" data-product-request-modal-url="{{ route('product-requests.placement.edit', ['productRequest' => $productRequest, 'modal' => 1]) }}"><i class="fa fa-external-link"></i> {{ $t('operation.placement_title') }}</button>@endif
                @if($productRequest->status === \App\Models\ProductRequest::STATUS_PENDING)<button type="button" class="flt-row-action product-request-action-button" data-action="delete" data-product-request-delete data-delete-url="{{ route('product-requests.operation.destroy', $productRequest) }}" title="{{ $t('common.delete_confirm') }}"><i class="fa fa-trash-o"></i> {{ $t('common.delete') }}</button>@endif
                </td></tr>
            @empty
                <tr><td colspan="11"><div class="flt-empty"><i class="fa fa-inbox"></i><h4>{{ $t('common.no_records') }}</h4><p>可调整筛选条件后重新查询</p></div></td></tr>
            @endforelse
        </tbody></table></div>
        @include('portals.product-requests.operation.partials.pagination', ['paginator' => $requests])
    </div>
    <dialog class="flt-dialog product-request-delete-dialog" data-product-request-delete-dialog aria-labelledby="product-request-delete-title">
        <header><h3 id="product-request-delete-title" data-product-request-delete-title>确认删除投放连接提报</h3><button type="button" class="flt-icon-close" data-product-request-delete-close aria-label="关闭">×</button></header>
        <div class="flt-dialog-content"><span class="product-request-delete-icon"><i class="fa fa-exclamation-triangle"></i></span><div class="product-request-delete-copy"><strong>此操作无法撤销</strong><p data-product-request-delete-message>删除后该投放连接提报记录无法恢复，请确认是否继续。</p></div></div>
        <footer><button type="button" class="flt-btn" data-product-request-delete-close>取消</button><button type="button" class="flt-btn flt-danger" data-product-request-delete-confirm><i class="fa fa-trash-o"></i> 确认删除</button></footer>
    </dialog>
    @include('portals.product-requests.partials.modal')
</section>
@endsection
