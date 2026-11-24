@extends('portal.layout')

@section('page_title', __('aikaipin.operation_library.title'))

@push('styles')
    <link rel="stylesheet" href="{{ rtrim(request()->getSchemeAndHttpHost(), '/') }}/assets/erp/aikaipin-product-library.css?v=20261023-1">
    <link rel="stylesheet" href="{{ rtrim(request()->getSchemeAndHttpHost(), '/') }}/css/frontend-list-template.css?v=20261124-20">
    <style>
        .operation-ai-product-page .aikaipin-operation-column { width:330px; text-align:center; }
        .operation-ai-product-page .aikaipin-operation-cell { text-align:center; vertical-align:middle; }
        @media (max-width:1199px) { .operation-ai-product-page .aikaipin-operation-column { width:300px; } }
        .operation-ai-push-toolbar { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px 12px; margin-bottom:12px; padding:10px 12px; border:1px solid #dce8ef; border-radius:6px; background:#f6fafc; }
        .operation-ai-push-search { position:relative; width:100%; }
        .operation-ai-push-search .fa { position:absolute; top:10px; left:11px; color:#94a2af; }
        .operation-ai-push-search .form-control { height:34px; padding-left:32px; border-radius:17px; }
        .operation-ai-push-members { max-height:420px; overflow:auto; border:1px solid #e1e8ee; border-radius:6px; }
        .operation-ai-push-member { display:flex; align-items:center; gap:10px; margin:0; padding:11px 13px; border-bottom:1px solid #edf1f4; cursor:pointer; font-weight:400; }
        .operation-ai-push-member:last-child { border-bottom:0; }
        .operation-ai-push-member:hover { background:#f7fbfd; }
        .operation-ai-push-member input { margin:0; }
        .operation-ai-push-member-main { min-width:0; flex:1; }
        .operation-ai-push-member-main strong,.operation-ai-push-member-main small { display:block; }
        .operation-ai-push-member-main small { margin-top:2px; color:#8795a3; }
        .operation-ai-push-member-status { flex:0 0 auto; }
        .operation-ai-push-empty { padding:32px 15px; color:#8c99a5; text-align:center; }
        .operation-ai-detail-dialog { width:880px; max-width:94vw; }
        .operation-ai-detail-modal .modal-header { padding:18px 22px; border-bottom:1px solid #e8edf3; }
        .operation-ai-detail-modal .modal-title { color:#263445; font-size:18px; font-weight:700; }
        .operation-ai-detail-modal .modal-title .fa { width:34px; height:34px; margin-right:9px; border-radius:9px; background:#eaf4fb; color:#3c8dbc; line-height:34px; text-align:center; }
        .operation-ai-detail-modal .modal-body { padding:20px 22px 24px; background:#f6f8fb; }
        .operation-ai-detail-hero { display:flex; align-items:center; gap:15px; grid-column:1 / -1; padding:17px 18px; border:1px solid #dfe7ef; border-radius:9px; background:#fff; box-shadow:0 2px 8px rgba(31,54,78,.04); }
        .operation-ai-detail-hero-thumb { width:74px; height:74px; flex:0 0 74px; overflow:hidden; border:1px solid #e4eaf0; border-radius:8px; background:#f3f6f9; }
        .operation-ai-detail-hero-thumb img { width:100%; height:100%; object-fit:cover; }
        .operation-ai-detail-hero-thumb .fa { display:block; color:#b6c2cf; font-size:26px; line-height:74px; text-align:center; }
        .operation-ai-detail-hero-main { min-width:0; }
        .operation-ai-detail-hero-main h4 { margin:0 0 7px; color:#243447; font-size:18px; font-weight:700; }
        .operation-ai-detail-code { display:inline-block; padding:3px 8px; border-radius:4px; background:#f0f4f8; color:#53677d; font-family:Consolas,monospace; font-size:12px; }
        .operation-ai-detail-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
        .operation-ai-detail-item { min-width:0; padding:14px 16px; border:1px solid #e2e8ef; border-radius:8px; background:#fff; }
        .operation-ai-detail-item.is-wide { grid-column:1 / -1; }
        .operation-ai-detail-label { display:flex; align-items:center; gap:6px; margin-bottom:8px; color:#8290a0; font-size:12px; font-weight:600; }
        .operation-ai-detail-label:before { content:''; width:3px; height:12px; border-radius:2px; background:#5fa8d3; }
        .operation-ai-detail-value { min-height:20px; color:#263445; line-height:1.65; word-break:break-word; }
        .operation-ai-detail-tag { display:inline-block; margin:0 6px 5px 0; padding:3px 9px; border-radius:12px; background:#edf5fb; color:#347ca8; font-size:12px; }
        .operation-ai-detail-images { display:grid; grid-template-columns:repeat(auto-fill,minmax(108px,1fr)); gap:10px; }
        .operation-ai-detail-image { padding:0; overflow:hidden; border:1px solid #e3e8ee; border-radius:7px; background:#f7f9fb; }
        .operation-ai-detail-image img { display:block; width:100%; height:112px; object-fit:cover; transition:transform .18s ease; }
        .operation-ai-detail-image:hover img { transform:scale(1.04); }
        .operation-ai-detail-link { display:inline-flex; align-items:center; gap:6px; color:#2786bd; }
        .operation-ai-detail-pages { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
        .operation-ai-detail-page { min-width:0; padding:13px 14px; border:1px solid #dce7ef; border-radius:8px; background:#f9fcfe; }
        .operation-ai-detail-page-head { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; margin-bottom:8px; }
        .operation-ai-detail-page-name { min-width:0; color:#285d7d; font-weight:700; word-break:break-word; }
        .operation-ai-detail-page-name .label { margin-left:5px; font-size:9px; }
        .operation-ai-detail-page-file { margin-bottom:8px; color:#8493a0; font-size:11px; word-break:break-all; }
        .operation-ai-detail-page-stats { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:9px; }
        .operation-ai-detail-page-stats span { padding:3px 7px; border-radius:9px; background:#edf3f7; color:#607487; font-size:11px; }
        .operation-ai-detail-page-url { display:flex; align-items:center; gap:5px; min-width:0; color:#2786bd; font-size:12px; }
        .operation-ai-detail-page-url span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .operation-ai-detail-page-empty { color:#9aa6b2; font-size:12px; }
        .operation-ai-detail-videos { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
        .operation-ai-detail-video { overflow:hidden; border:1px solid #dce6ed; border-radius:7px; background:#f9fbfc; }
        .operation-ai-detail-video video { display:block; width:100%; height:190px; background:#111; object-fit:contain; }
        .operation-ai-detail-video-trigger { display:flex; width:100%; height:190px; flex-direction:column; align-items:center; justify-content:center; gap:10px; border:0; color:#246c98; background:#edf4f7; cursor:pointer; }
        .operation-ai-detail-video-trigger:hover,.operation-ai-detail-video-trigger:focus { color:#175a82; background:#dfeef4; outline:2px solid #246c98; outline-offset:-2px; }
        .operation-ai-detail-video-trigger .fa { font-size:42px; }
        .operation-ai-detail-video-trigger span { font-size:13px; font-weight:600; }
        .operation-ai-video-preview-modal { z-index:1070; }
        .operation-ai-video-preview-modal .modal-dialog { width:min(1120px,calc(100% - 36px))!important; }
        .operation-ai-video-preview-modal .modal-body { padding:16px; background:#18232a; }
        .operation-ai-video-preview-stage { display:flex; min-height:420px; align-items:center; justify-content:center; overflow:hidden; border-radius:5px; background:#111a1f; }
        .operation-ai-video-preview-stage video { display:block; width:100%; max-height:70vh; background:#111; }
        .operation-ai-video-preview-error { margin:12px 0 0; }
        .operation-ai-detail-video-error { display:none; margin:8px 10px 0; padding:7px 9px; border:1px solid #f1c2bd; border-radius:4px; color:#b74235; background:#fff3f2; font-size:11px; line-height:1.5; }
        .operation-ai-detail-video.is-playback-error .operation-ai-detail-video-error { display:block; }
        .operation-ai-detail-video-info { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:3px 8px; padding:8px 10px; }
        .operation-ai-detail-video-code { overflow:hidden; color:#285d7d; font-family:Consolas,monospace; font-size:12px; font-weight:700; text-overflow:ellipsis; white-space:nowrap; }
        .operation-ai-detail-video-info small { color:#8998a6; font-size:10px; }
        .operation-ai-detail-video-groups { display:grid; gap:14px; }
        .operation-ai-detail-video-group { overflow:hidden; border:1px solid #dbe6ed; border-radius:8px; background:#f8fbfd; }
        .operation-ai-detail-video-group-head { display:flex; align-items:center; justify-content:space-between; padding:9px 12px; border-bottom:1px solid #dbe6ed; color:#285d7d; background:#eef6fa; font-weight:700; }
        .operation-ai-detail-video-group-head .badge { background:#3c8dbc; }
        .operation-ai-detail-video-group .operation-ai-detail-videos { padding:12px; }
        .operation-ai-detail-video-empty { padding:18px; color:#91a0ac; text-align:center; }
        .operation-ai-detail-video-actions { display:flex; align-items:center; justify-content:space-between; gap:8px; grid-column:1 / -1; margin-top:4px; }
        .operation-ai-detail-video-download { display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:4px; color:#fff; background:#3c8dbc; font-size:11px; text-decoration:none; }
        .operation-ai-detail-video-download:hover,.operation-ai-detail-video-download:focus { color:#fff; background:#2f7399; text-decoration:none; }
        .operation-ai-product-page .aikaipin-pagination-control { display:flex; align-items:center; justify-content:space-between; gap:16px; min-height:48px; }
        .operation-ai-product-page .aikaipin-pagination-summary { color:#7c8998; font-size:12px; }
        .operation-ai-product-page .pagination > li > a,
        .operation-ai-product-page .pagination > li > span { min-width:34px; height:32px; margin-left:6px; padding:6px 10px; border:1px solid #dce4ec; border-radius:5px !important; color:#53677d; line-height:18px; text-align:center; }
        .operation-ai-product-page .pagination > li > a:hover { border-color:#86bad9; background:#edf6fc; color:#287eaf; }
        .operation-ai-product-page .pagination > .active > a,
        .operation-ai-product-page .pagination > .active > a:hover { border-color:#3c8dbc; background:#3c8dbc; color:#fff; box-shadow:0 2px 6px rgba(60,141,188,.25); }
        .operation-ai-product-page .pagination > .disabled > span,
        .operation-ai-product-page .pagination > .disabled > a { background:#f7f9fb; color:#b3bec9; }
        .operation-ai-store-list { margin-bottom:18px; }
        .operation-ai-store-list h5 { margin:0 0 10px; color:#34495e; font-weight:700; }
        .operation-ai-store-card { display:flex; align-items:flex-start; gap:10px; margin:0 0 9px; padding:12px 14px; border:1px solid #dce6ef; border-radius:8px; background:#f8fbfd; cursor:pointer; }
        .operation-ai-store-card:hover { border-color:#70acd0; background:#f2f9fd; }
        .operation-ai-store-card input { margin-top:5px; }
        .operation-ai-store-card span { display:grid; grid-template-columns:minmax(140px,1fr) minmax(180px,1.3fr); gap:4px 18px; width:100%; }
        .operation-ai-store-card strong { color:#1f5270; }
        .operation-ai-store-card small { color:#708294; }
        .operation-ai-publish-dialog { width:980px; max-width:94vw; margin:3vh auto; }
        #operation-ai-product-publish-modal .modal-content { max-height:94vh; overflow:hidden; border:0; border-radius:10px; box-shadow:0 15px 45px rgba(17,38,58,.28); }
        #operation-ai-product-publish-modal .modal-header { padding:15px 20px; border-bottom:1px solid #dfe8ef; background:#f7fafc; }
        #operation-ai-product-publish-modal .modal-body { padding:0; overflow:hidden; }
        .operation-ai-publish-form { display:flex; flex-direction:column; max-height:calc(94vh - 64px); }
        .operation-ai-publish-scroll { min-height:0; padding:16px 20px 12px; overflow-y:auto; overscroll-behavior:contain; }
        .operation-ai-store-heading { display:flex; align-items:center; justify-content:space-between; gap:14px; margin-bottom:10px; }
        .operation-ai-store-heading h5 { margin:0; }
        .operation-ai-store-heading .badge { margin-left:5px; background:#3c8dbc; }
        .operation-ai-store-search { display:flex; width:390px; max-width:58%; }
        .operation-ai-store-search input { min-width:0; flex:1; height:34px; padding:6px 12px; border:1px solid #d6e1ea; border-right:0; border-radius:17px 0 0 17px; outline:none; }
        .operation-ai-store-search input:focus { border-color:#69a9cf; box-shadow:0 0 0 2px rgba(60,141,188,.12); }
        .operation-ai-store-search button { display:inline-flex; flex:0 0 auto; align-items:center; justify-content:center; gap:5px; min-width:76px; height:34px; padding:0 15px; border:1px solid #2385bd; border-radius:0 17px 17px 0; color:#fff; font-weight:600; background:linear-gradient(135deg,#43a7dc,#2385bd); box-shadow:0 2px 6px rgba(35,133,189,.24); cursor:pointer; transition:background .15s ease,box-shadow .15s ease,transform .15s ease; }
        .operation-ai-store-search button:hover,.operation-ai-store-search button:focus { background:linear-gradient(135deg,#3198cf,#1e76a7); border-color:#1e76a7; box-shadow:0 3px 9px rgba(35,133,189,.32); outline:none; }
        .operation-ai-store-search button:active { transform:translateY(1px); box-shadow:0 1px 3px rgba(35,133,189,.24); }
        .operation-ai-store-options { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; max-height:270px; padding:2px 4px 2px 2px; overflow-y:auto; }
        .operation-ai-store-card[hidden] { display:none !important; }
        .operation-ai-store-card { min-width:0; margin:0; padding:10px 12px; }
        .operation-ai-store-card:has(input:checked) { border-color:#3c8dbc; background:#edf7fd; box-shadow:0 0 0 1px rgba(60,141,188,.12); }
        .operation-ai-store-card span { grid-template-columns:minmax(115px,.8fr) minmax(145px,1.2fr); gap:3px 12px; }
        .operation-ai-store-card strong,.operation-ai-store-card small { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .operation-ai-store-empty { padding:22px; border:1px dashed #cfdce6; border-radius:7px; color:#8799a8; text-align:center; }
        .operation-ai-product-page-picker { margin-bottom:14px; padding:13px 15px; border:1px solid #cfe1ed; border-radius:8px; background:#f5fafd; }
        .operation-ai-product-page-picker > label { display:block; margin-bottom:7px; color:#285d7d; }
        .operation-ai-product-page-picker .help-block { margin:6px 0 0; }
        .operation-ai-product-page-summary { margin-bottom:10px; }
        .operation-ai-product-pages { display:flex; flex-wrap:wrap; gap:4px; margin-top:6px; max-width:330px; }
        .operation-ai-product-pages a,.operation-ai-product-pages span { display:inline-flex; align-items:center; gap:4px; max-width:150px; padding:3px 7px; border:1px solid #cfe2ef; border-radius:10px; background:#f3f9fc; color:#287eaf; font-size:11px; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .operation-ai-product-pages a:hover { border-color:#5aa7d3; background:#e8f5fb; text-decoration:none; }
        .operation-ai-product-pages span { border-color:#e1e5e9; background:#f7f8f9; color:#8794a1; }
        .operation-ai-product-pages em { align-self:center; color:#8898a8; font-size:11px; font-style:normal; }
        .operation-ai-product-page-link { display:inline-block; margin-top:7px; font-size:12px; }
        .operation-ai-variant-preview { max-height:230px; overflow:auto; border:1px solid #dce5ec; border-radius:6px; }
        .operation-ai-variant-preview .table { margin:0; }
        .operation-ai-variant-preview thead th { position:sticky; top:0; z-index:1; background:#edf5fa; }
        .operation-ai-publish-actions { display:flex; flex:0 0 auto; align-items:center; justify-content:space-between; gap:16px; padding:12px 20px; border-top:1px solid #dfe8ef; background:#fff; box-shadow:0 -5px 14px rgba(35,65,88,.07); }
        .operation-ai-publish-actions > span { color:#7a8d9d; font-size:12px; }
        .operation-ai-publish-actions .btn { min-width:92px; }
        @media (max-width:767px) { .operation-ai-store-options { grid-template-columns:1fr; max-height:220px; } .operation-ai-store-heading { align-items:flex-start; flex-direction:column; } .operation-ai-store-search { width:100%; max-width:none; } .operation-ai-publish-actions > span { display:none; } .operation-ai-publish-actions { justify-content:flex-end; } }
        .operation-ai-material-upload-progress { height:9px; margin:12px 0 5px; }
        @media (max-width:767px) { .operation-ai-detail-grid,.operation-ai-detail-pages,.operation-ai-detail-videos { grid-template-columns:1fr; } .operation-ai-detail-item.is-wide { grid-column:auto; } }
        .operation-ai-product-page.flt{padding:15px}.operation-ai-product-page .operation-ai-template-filters{margin-bottom:15px;padding:18px 20px 15px}.operation-ai-template-filter-grid{display:grid;grid-template-columns:minmax(280px,2fr) repeat(3,minmax(170px,1fr));align-items:start;gap:14px 18px}.operation-ai-template-filter-grid>label>input{margin-top:8px}.operation-ai-template-picker-field{min-width:0}.operation-ai-template-picker-field>.flt-field-label{margin-bottom:8px}.operation-ai-template-picker[open] summary:after{transform:translateY(-20%) rotate(225deg)}.operation-ai-template-picker .flt-picker-panel{z-index:108}.operation-ai-template-picker-search{min-height:34px!important;margin-bottom:5px;padding:7px 10px!important}.operation-ai-template-picker .flt-picker-options{margin-top:0}.operation-ai-template-picker .flt-picker-options label{font-weight:400}.operation-ai-template-filter-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:13px}.operation-ai-template-records{overflow:hidden}.operation-ai-template-records .flt-toolbar{padding:12px 18px}.operation-ai-template-table-scroll{max-height:calc(100vh - 285px);overflow:auto}.operation-ai-template-table{min-width:1120px!important;margin:0!important;border-collapse:separate!important;border-spacing:0!important}.operation-ai-template-table thead th{position:sticky;top:0;z-index:4;padding:11px 10px!important;background:#eaf4fa!important;color:#345c75!important;border-right:1px solid #dce8ef!important}.operation-ai-template-table tbody td{padding:11px 10px!important;border-right:1px solid #e7eef3!important;border-bottom:1px solid #e7eef3!important}.operation-ai-template-table tbody tr:nth-child(odd)>td{background:#fff}.operation-ai-template-table tbody tr:nth-child(even)>td{background:#f3f9fd}.operation-ai-template-table tbody tr:hover>td{background:#eaf5fb!important}.operation-ai-product-actions{display:grid;grid-template-columns:repeat(3,minmax(82px,1fr));gap:6px;width:100%;max-width:318px;margin:0 auto}.operation-ai-product-actions .flt-row-action{display:inline-flex;align-items:center;justify-content:center;gap:5px;width:100%;min-width:0;min-height:30px;white-space:nowrap}.operation-ai-product-actions button.flt-row-action{border-left:0}.operation-ai-product-actions .flt-row-action .fa{width:13px;margin:0;text-align:center}.operation-ai-template-pagination{border-top:1px solid #e3edf4}.operation-ai-template-pagination .operation-ai-pagination-total{margin-right:auto}.operation-ai-template-pagination .operation-ai-page-size{margin-right:auto}.operation-ai-template-pagination .is-disabled{pointer-events:none;opacity:.45}.operation-ai-template-pagination form{display:flex;align-items:center;gap:8px;margin:0}.operation-ai-template-pagination input[type=hidden]{display:none}.operation-ai-template-pagination .operation-ai-page-jump input{width:64px!important}@media(max-width:1100px){.operation-ai-template-filter-grid{grid-template-columns:repeat(2,minmax(180px,1fr))}}@media(max-width:650px){.operation-ai-template-filter-grid{grid-template-columns:1fr}.operation-ai-product-actions{grid-template-columns:repeat(2,minmax(82px,1fr))}.operation-ai-template-pagination{justify-content:center}.operation-ai-template-pagination .operation-ai-pagination-total,.operation-ai-template-pagination .operation-ai-page-size{width:100%;margin:0;justify-content:center}}
        .operation-ai-template-table .aikaipin-name-cell{min-width:260px;max-width:420px}.operation-ai-template-table .aikaipin-name-cell>strong{display:-webkit-box;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:2;white-space:normal;word-break:break-word}.operation-ai-template-table .aikaipin-sku-line{display:flex;width:100%;max-width:100%;align-items:center}.operation-ai-template-table .aikaipin-sku-line>span:first-child{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.operation-ai-template-table .aikaipin-sku-more{flex:0 0 auto}
    </style>
@endpush

@section('content')
<section class="content aikaipin-product-library-page operation-ai-product-page flt">
    @if(session('success'))<div class="alert alert-success"><i class="fa fa-check-circle"></i> {{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> {{ $errors->first() }}</div>@endif
    <form class="flt-card flt-filters operation-ai-template-filters" method="get" action="{{ route('operation.ai-product-library.index') }}">
        <div class="operation-ai-template-filter-grid">
            <label>{{ __('aikaipin.filters.keyword') }}<input type="search" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ __('aikaipin.filters.keyword_placeholder') }}" autocomplete="off"></label>
            @include('portals.operation.ai-product-library.partials.filter-single-picker',['label'=>__('aikaipin.filters.category'),'name'=>'category','selected'=>$filters['category'],'options'=>[''=>__('aikaipin.filters.all')] + collect($categories)->mapWithKeys(fn($category)=>[$category=>$category])->all(),'searchable'=>true])
            @include('portals.operation.ai-product-library.partials.filter-single-picker',['label'=>__('aikaipin.filters.source'),'name'=>'source','selected'=>$filters['source'],'options'=>[''=>__('aikaipin.filters.all')] + trans('aikaipin.upload.source_options')])
            @include('portals.operation.ai-product-library.partials.filter-single-picker',['label'=>__('aikaipin.filters.type'),'name'=>'type','selected'=>$filters['type'],'options'=>[''=>__('aikaipin.filters.all')] + trans('aikaipin.upload.type_options')])
            <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
        </div>
        <div class="operation-ai-template-filter-actions"><button class="flt-btn flt-primary" type="submit"><i class="fa fa-search"></i> {{ __('aikaipin.filters.search') }}</button><a class="flt-btn" href="{{ route('operation.ai-product-library.index') }}"><i class="fa fa-refresh"></i> {{ __('aikaipin.filters.reset') }}</a></div>
    </form>

    <div class="flt-card flt-records operation-ai-template-records">
        <div class="flt-toolbar"><div class="flt-selection"><i class="fa fa-cubes"></i><span>{{ trans_choice('aikaipin.product_library.record_count', $products->total(), ['count' => $products->total()]) }}</span></div></div>
        <div class="flt-table-scroll operation-ai-template-table-scroll">
                <table class="aikaipin-product-table operation-ai-template-table">
                    <thead><tr>
                        <th class="aikaipin-image-column">{{ __('aikaipin.table.image') }}</th>
                        <th class="aikaipin-product-code-column">{{ __('aikaipin.table.product_code') }}</th>
                        <th class="aikaipin-name-column">{{ __('aikaipin.table.product_name_sku') }}</th>
                        <th class="aikaipin-category-column">{{ __('aikaipin.table.category') }}</th>
                        <th class="aikaipin-source-column">{{ __('aikaipin.table.source_type') }}</th>
                        <th class="aikaipin-updated-column">{{ __('aikaipin.table.updated_at') }}</th>
                        <th class="aikaipin-operation-column">{{ __('aikaipin.table.operation') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($products as $product)
                        @php
                            $images = $product->imageFiles();
                            $skus = $product->skuValues();
                            $beijingUpdatedAt = $product->updated_at?->copy()->timezone('Asia/Shanghai');
                        @endphp
                        <tr>
                            <td class="aikaipin-image-cell">
                                <span class="aikaipin-product-image">
                                    @if ($images !== [])
                                        <img src="{{ route('operation.ai-product-library.images.show', ['product' => $product, 'imageIndex' => 0]) }}" alt="{{ $product->name }}" loading="lazy">
                                    @else
                                        <i class="fa fa-picture-o" aria-hidden="true"></i>
                                    @endif
                                </span>
                            </td>
                            <td class="aikaipin-product-code-cell"><span class="aikaipin-product-code">{{ $product->product_code }}</span></td>
                            <td class="aikaipin-name-cell">
                                <strong title="{{ $product->name }}">{{ $product->name }}</strong>
                                @if ($skus !== [])
                                    <span class="aikaipin-sku-line" title="{{ implode(', ', $skus) }}">
                                        <span>{{ $skus[0] }}</span>
                                        @if (count($skus) > 1)<span class="aikaipin-sku-more">+{{ count($skus) - 1 }}</span>@endif
                                    </span>
                                @endif
                                @if($product->hasShopifyData())
                                    <span class="label label-success" style="display:inline-block;margin-top:5px;">产品页资料 {{ max(1, (int) ($product->product_pages_count ?? 0)) }} 份</span>
                                @endif
                                @if($product->productPages->isNotEmpty())
                                    <div class="operation-ai-product-pages">
                                        @foreach($product->productPages->take(3) as $page)
                                            @if($page->landing_page_url)
                                                <a href="{{ $page->landing_page_url }}" target="_blank" rel="noopener noreferrer" title="{{ $page->name }}：{{ $page->landing_page_url }}"><i class="fa fa-external-link"></i>{{ \Illuminate\Support\Str::limit($page->name, 16) }}</a>
                                            @else
                                                <span title="{{ $page->name }}（未设置落地页）"><i class="fa fa-file-text-o"></i>{{ \Illuminate\Support\Str::limit($page->name, 16) }}</span>
                                            @endif
                                        @endforeach
                                        @if($product->productPages->count() > 3)<em>+{{ $product->productPages->count() - 3 }}</em>@endif
                                    </div>
                                @endif
                            </td>
                            <td class="aikaipin-category-cell">
                                @foreach ($product->categoryValues() as $category)<span class="label label-default">{{ $category }}</span>@endforeach
                            </td>
                            <td class="aikaipin-source-cell">
                                <span class="aikaipin-source">{{ trans('aikaipin.upload.source_options.'.$product->source_platform) }}</span>
                                <span class="aikaipin-type">{{ trans('aikaipin.upload.type_options.'.$product->product_type) }}</span>
                            </td>
                            <td class="aikaipin-updated-cell"><time datetime="{{ $beijingUpdatedAt?->toAtomString() }}" title="北京时间">{{ $beijingUpdatedAt?->format('Y-m-d H:i') }}</time></td>
                            <td class="aikaipin-operation-cell">
                                <div class="operation-ai-product-actions">
                                @if($product->referenceFilePayload())
                                    <a class="flt-row-action action-primary" href="{{ route('operation.ai-product-library.reference-preview', ['product' => $product]) }}" target="_blank" rel="noopener noreferrer">
                                        <i class="fa fa-external-link"></i> 参考页
                                    </a>
                                @endif
                                <button type="button" class="flt-row-action" data-operation-ai-detail
                                    data-detail-url="{{ route('operation.ai-product-library.detail', ['product' => $product]) }}">
                                    <i class="fa fa-eye"></i> {{ __('aikaipin.operation_library.detail') }}
                                </button>
                                <button type="button" class="flt-row-action action-primary" data-operation-ai-material-upload-trigger
                                    data-upload-url="{{ route('operation.ai-product-library.reference-videos.upload', absolute: false) }}"
                                    data-product-id="{{ $product->getKey() }}"
                                    data-product-label="{{ $product->product_code }} · {{ $product->name }}">
                                    <i class="fa fa-upload"></i> 上传素材
                                </button>
                                <button type="button" class="flt-row-action action-warning" data-operation-ai-placement
                                    data-placement-url="{{ route('operation.ai-product-library.placement-evaluations.form', $product) }}">
                                    <i class="fa fa-line-chart"></i> 投放测评
                                </button>
                                <button type="button" class="flt-row-action action-success" data-operation-ai-publish
                                    data-publish-url="{{ route('operation.ai-product-library.publish.form', $product) }}">
                                    <i class="fa fa-cloud-upload"></i> 上架
                                </button>
                                @if($canPushProducts)
                                    <button type="button" class="flt-row-action action-danger" data-operation-ai-push
                                        data-members-url="{{ route('operation.ai-product-library.push-members', $product) }}"
                                        data-push-url="{{ route('operation.ai-product-library.push-members.store', $product) }}">
                                        <i class="fa fa-paper-plane"></i> 推送
                                    </button>
                                @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr class="aikaipin-empty-row"><td colspan="7">
                            <div class="aikaipin-empty-state"><i class="fa fa-search"></i><p>{{ __('aikaipin.messages.no_records') }}</p></div>
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
        </div>
        @if ($products->total() > 0)
            @include('portals.operation.ai-product-library.partials.pagination', ['paginator' => $products])
        @endif
    </div>
</section>

@if($canPushProducts)
<div class="modal fade" id="operation-ai-product-push-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-paper-plane"></i> 定点推送上架任务</h4></div>
        <form data-operation-ai-push-form>
            @csrf
            <div class="modal-body">
                <div class="alert alert-info" data-operation-ai-push-product>正在加载产品和组员信息...</div>
                <div class="operation-ai-push-toolbar">
                    <div class="operation-ai-push-search"><i class="fa fa-search"></i><input type="search" class="form-control input-sm" placeholder="搜索姓名、账号或部门" autocomplete="off" data-operation-ai-push-search></div>
                    <label style="margin:0"><input type="checkbox" data-operation-ai-push-unpublished> 只看未上架成员</label>
                    <button type="button" class="btn btn-default btn-xs" data-operation-ai-push-select-all>选择当前可推送成员</button>
                </div>
                <div class="operation-ai-push-members" data-operation-ai-push-members><div class="operation-ai-push-empty"><i class="fa fa-spinner fa-spin"></i> 正在加载...</div></div>
                <div class="alert alert-danger" data-operation-ai-push-error hidden style="margin-top:12px;margin-bottom:0"></div>
                <div class="alert alert-success" data-operation-ai-push-success hidden style="margin-top:12px;margin-bottom:0"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button type="submit" class="btn btn-danger"><i class="fa fa-paper-plane"></i> 确认推送</button></div>
        </form>
    </div></div>
</div>
@endif

<div class="modal fade operation-ai-detail-modal" id="operation-ai-product-detail-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog operation-ai-detail-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('aikaipin.operation_library.close') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title"><i class="fa fa-cube"></i>{{ __('aikaipin.operation_library.detail_title') }}</h4>
            </div>
            <div class="modal-body">
                <div class="text-center text-muted" data-operation-ai-detail-loading><i class="fa fa-spinner fa-spin"></i> {{ __('aikaipin.operation_library.loading') }}</div>
                <div class="alert alert-danger" data-operation-ai-detail-error hidden></div>
                <div class="operation-ai-detail-grid" data-operation-ai-detail-content hidden></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('aikaipin.operation_library.close') }}</button></div>
        </div>
    </div>
</div>

<div class="modal fade operation-ai-video-preview-modal" id="operation-ai-video-preview-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title" data-operation-ai-video-preview-title><i class="fa fa-play-circle"></i> 素材视频</h4></div>
        <div class="modal-body">
            <div class="operation-ai-video-preview-stage"><video controls playsinline preload="none" data-operation-ai-video-preview-player></video></div>
            <div class="alert alert-danger operation-ai-video-preview-error" hidden data-operation-ai-video-preview-error><i class="fa fa-exclamation-triangle"></i> 当前视频无法在线解码播放，请先下载查看。</div>
        </div>
        <div class="modal-footer"><a class="btn btn-info" href="#" target="_blank" rel="noopener noreferrer" data-operation-ai-video-preview-open><i class="fa fa-external-link"></i> 新窗口打开</a><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div>
    </div></div>
</div>

<div class="modal fade" id="operation-ai-product-publish-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog operation-ai-publish-dialog" role="document"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-cloud-upload"></i> 上架到 Shopify</h4></div>
        <div class="modal-body" data-operation-ai-publish-content><div class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> 正在加载店铺信息...</div></div>
    </div></div>
</div>

<div class="modal fade" id="operation-ai-material-upload-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" enctype="multipart/form-data" data-operation-ai-material-upload-form>
            @csrf
            <input type="hidden" name="product_id" data-operation-ai-material-product-id>
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-upload"></i> 上传素材视频</h4></div>
            <div class="modal-body">
                <div class="alert alert-info" data-operation-ai-material-product></div>
                <div class="form-group"><label>素材类型</label><select class="form-control" name="type" required><option value="flow">Flow素材</option><option value="traffic">扒的素材</option><option value="remix">混剪素材</option></select></div>
                <div class="form-group"><label>选择视频</label><input class="form-control" type="file" name="video" accept="video/mp4,video/quicktime,video/x-msvideo,video/webm,video/x-matroska,.m4v" required><p class="help-block">单个视频最大 300MB；上传后仅本人和 AI 产品资料库可见。</p></div>
                <div class="alert alert-danger" data-operation-ai-material-error hidden></div>
                <div data-operation-ai-material-progress hidden><div class="progress operation-ai-material-upload-progress"><div class="progress-bar progress-bar-info progress-bar-striped active" style="width:0%" data-operation-ai-material-progress-bar></div></div><small class="text-muted" data-operation-ai-material-progress-text>准备上传...</small></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button type="submit" class="btn btn-primary"><i class="fa fa-upload"></i> 开始上传</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="operation-ai-placement-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title"><i class="fa fa-line-chart"></i> 投放测评</h4></div>
        <div class="modal-body" data-operation-ai-placement-content><div class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> 正在加载...</div></div>
    </div></div>
</div>

@endsection

@push('scripts')
<script>
(function () {
    var pickers=Array.prototype.slice.call(document.querySelectorAll('[data-operation-ai-template-picker]'));
    pickers.forEach(function(picker){
        var summary=picker.querySelector('[data-picker-summary]'),search=picker.querySelector('[data-picker-search]'),options=picker.querySelectorAll('[data-picker-option]'),empty=picker.querySelector('[data-picker-empty]');
        picker.querySelectorAll('input[type="radio"]').forEach(function(input){input.addEventListener('change',function(){summary.textContent=input.closest('label').querySelector('span').textContent;picker.removeAttribute('open');});});
        if(search)search.addEventListener('input',function(){var keyword=search.value.trim().toLowerCase(),visible=0;options.forEach(function(option){var show=!keyword||option.textContent.toLowerCase().indexOf(keyword)!==-1;option.hidden=!show;if(show)visible++;});empty.hidden=visible!==0;});
        picker.addEventListener('toggle',function(){if(picker.open&&search){search.value='';options.forEach(function(option){option.hidden=false;});empty.hidden=true;setTimeout(function(){search.focus();},0);}});
    });
    document.addEventListener('click',function(event){pickers.forEach(function(picker){if(!picker.contains(event.target))picker.removeAttribute('open');});});
})();
(function () {
    var modal = document.getElementById('operation-ai-product-push-modal');
    if (!modal) return;
    var form = modal.querySelector('[data-operation-ai-push-form]');
    var productBox = modal.querySelector('[data-operation-ai-push-product]');
    var membersBox = modal.querySelector('[data-operation-ai-push-members]');
    var searchInput = modal.querySelector('[data-operation-ai-push-search]');
    var unpublishedOnly = modal.querySelector('[data-operation-ai-push-unpublished]');
    var selectAll = modal.querySelector('[data-operation-ai-push-select-all]');
    var errorBox = modal.querySelector('[data-operation-ai-push-error]');
    var successBox = modal.querySelector('[data-operation-ai-push-success]');
    var submit = form.querySelector('[type="submit"]');
    var pushUrl = '';
    var members = [];
    var selectedIds = new Set();

    function showMessage(box, message) { box.textContent = message || ''; box.hidden = !message; }
    function renderMembers() {
        membersBox.innerHTML = '';
        var keyword = searchInput.value.trim().toLowerCase();
        var visible = members.filter(function (member) {
            if (unpublishedOnly.checked && member.is_published) return false;
            if (!keyword) return true;
            return [member.name, member.username, member.department_name].some(function (value) { return String(value || '').toLowerCase().indexOf(keyword) !== -1; });
        });
        if (!visible.length) {
            membersBox.innerHTML = '<div class="operation-ai-push-empty"><i class="fa fa-user-times"></i> 没有符合条件的组员</div>';
            return;
        }
        visible.forEach(function (member) {
            var label = document.createElement('label');
            label.className = 'operation-ai-push-member';
            label.dataset.published = member.is_published ? '1' : '0';
            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox'; checkbox.name = 'member_ids[]'; checkbox.value = member.id;
            checkbox.disabled = !!member.has_pending_task;
            checkbox.checked = selectedIds.has(member.id) && !checkbox.disabled;
            var main = document.createElement('span'); main.className = 'operation-ai-push-member-main';
            var name = document.createElement('strong'); name.textContent = member.name;
            var meta = document.createElement('small'); meta.textContent = member.department_name + ' · ' + member.username;
            main.appendChild(name); main.appendChild(meta);
            var status = document.createElement('span'); status.className = 'operation-ai-push-member-status label ' + (member.has_pending_task ? 'label-warning' : (member.is_published ? 'label-success' : 'label-default'));
            status.textContent = member.has_pending_task ? '待上架任务已推送' : (member.is_published ? '已上架' : '未上架');
            label.appendChild(checkbox); label.appendChild(main); label.appendChild(status); membersBox.appendChild(label);
        });
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-operation-ai-push]');
        if (!trigger) return;
        pushUrl = trigger.dataset.pushUrl || '';
        members = []; selectedIds.clear(); searchInput.value = ''; unpublishedOnly.checked = false;
        productBox.textContent = '正在加载产品和组员信息...';
        membersBox.innerHTML = '<div class="operation-ai-push-empty"><i class="fa fa-spinner fa-spin"></i> 正在加载...</div>';
        showMessage(errorBox, ''); showMessage(successBox, ''); submit.disabled = false;
        $(modal).modal('show');
        fetch(trigger.dataset.membersUrl, {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin'})
            .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || '加载组员失败'); return data; }); })
            .then(function (data) { members = data.members || []; productBox.textContent = data.product.code + ' · ' + data.product.name; renderMembers(); })
            .catch(function (error) { membersBox.innerHTML = ''; showMessage(errorBox, error.message); });
    });
    searchInput.addEventListener('input', renderMembers);
    unpublishedOnly.addEventListener('change', renderMembers);
    membersBox.addEventListener('change', function (event) {
        if (!event.target.matches('input[name="member_ids[]"]')) return;
        var id = Number(event.target.value);
        if (event.target.checked) selectedIds.add(id); else selectedIds.delete(id);
    });
    selectAll.addEventListener('click', function () {
        membersBox.querySelectorAll('input[name="member_ids[]"]:not(:disabled)').forEach(function (checkbox) { checkbox.checked = true; selectedIds.add(Number(checkbox.value)); });
    });
    form.addEventListener('submit', function (event) {
        event.preventDefault(); showMessage(errorBox, ''); showMessage(successBox, '');
        var ids = Array.from(selectedIds);
        if (!ids.length) { showMessage(errorBox, '请至少选择一名可推送成员。'); return; }
        submit.disabled = true;
        fetch(pushUrl, {method:'POST', headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':form.querySelector('[name="_token"]').value,'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin', body:JSON.stringify({member_ids:ids})})
            .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || '推送失败'); return data; }); })
            .then(function (data) { showMessage(successBox, data.message); members.forEach(function (member) { if (ids.indexOf(member.id) !== -1) member.has_pending_task = true; }); ids.forEach(function (id) { selectedIds.delete(id); }); renderMembers(); })
            .catch(function (error) { showMessage(errorBox, error.message); })
            .finally(function () { submit.disabled = false; });
    });
})();

(function () {
    var modal = document.getElementById('operation-ai-product-detail-modal');
    if (!modal) return;
    var loading = modal.querySelector('[data-operation-ai-detail-loading]');
    var error = modal.querySelector('[data-operation-ai-detail-error]');
    var content = modal.querySelector('[data-operation-ai-detail-content]');
    var labels = @json(__('aikaipin.operation_library'));
    var videoPreviewModal = document.getElementById('operation-ai-video-preview-modal');
    var videoPreviewTitle = document.querySelector('[data-operation-ai-video-preview-title]');
    var videoPreviewPlayer = document.querySelector('[data-operation-ai-video-preview-player]');
    var videoPreviewOpen = document.querySelector('[data-operation-ai-video-preview-open]');
    var videoPreviewError = document.querySelector('[data-operation-ai-video-preview-error]');

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character];
        });
    }
    function value(value) {
        if (Array.isArray(value)) return value.length ? value.map(function (entry) { return '<span class="operation-ai-detail-tag">' + escapeHtml(entry) + '</span>'; }).join('') : escapeHtml(labels.empty);
        return value ? escapeHtml(value) : escapeHtml(labels.empty);
    }
    function item(label, body, wide) {
        return '<div class="operation-ai-detail-item' + (wide ? ' is-wide' : '') + '"><span class="operation-ai-detail-label">' +
            escapeHtml(label) + '</span><div class="operation-ai-detail-value">' + body + '</div></div>';
    }
    function productPages(pages) {
        if (!Array.isArray(pages) || !pages.length) return escapeHtml(labels.empty);
        return '<div class="operation-ai-detail-pages">' + pages.map(function (page) {
            var landing = page.landing_page_url
                ? '<a class="operation-ai-detail-page-url" href="' + escapeHtml(page.landing_page_url) + '" target="_blank" rel="noopener noreferrer"><i class="fa fa-external-link"></i><span>' + escapeHtml(page.landing_page_url) + '</span></a>'
                : '<span class="operation-ai-detail-page-empty"><i class="fa fa-exclamation-circle"></i> 未设置落地页</span>';
            return '<div class="operation-ai-detail-page"><div class="operation-ai-detail-page-head"><div class="operation-ai-detail-page-name">' +
                escapeHtml(page.name || '未命名产品页') + (page.is_default ? '<span class="label label-primary">默认</span>' : '') +
                '</div></div><div class="operation-ai-detail-page-file"><i class="fa fa-file-excel-o"></i> ' + escapeHtml(page.source_file || '—') +
                '</div><div class="operation-ai-detail-page-stats"><span>' + escapeHtml(page.variant_count || 0) + ' 个规格</span><span>' +
                escapeHtml(page.image_count || 0) + ' 张图片</span>' + (page.vendor ? '<span>' + escapeHtml(page.vendor) + '</span>' : '') +
                '</div>' + landing + '</div>';
        }).join('') + '</div>';
    }
    function materialVideos(data) {
        var videos = Array.isArray(data.material_videos) ? data.material_videos : [];
        var groups = [
            {type:'flow', label:'Flow素材', icon:'fa-random'},
            {type:'traffic', label:'扒的素材', icon:'fa-cloud-download'},
            {type:'remix', label:'混剪素材', icon:'fa-film'}
        ];
        return '<div class="operation-ai-detail-video-groups">' + groups.map(function (group) {
            var groupVideos = videos.filter(function (video) { return video.type === group.type; });
            var cards = groupVideos.length ? '<div class="operation-ai-detail-videos">' + groupVideos.map(function (video) {
                return '<article class="operation-ai-detail-video"><button type="button" class="operation-ai-detail-video-trigger" data-operation-ai-video-preview data-video-src="' + escapeHtml(video.preview_url) + '" data-video-name="' + escapeHtml(video.video_code) + '"><i class="fa fa-play-circle"></i><span>点击播放素材视频</span></button><div class="operation-ai-detail-video-info"><strong class="operation-ai-detail-video-code" title="' + escapeHtml(video.video_code) + '">' + escapeHtml(video.video_code) + '</strong><span class="label label-info">' + escapeHtml(video.type_label) + '</span><small>' + escapeHtml(video.size) + '</small><small>' + escapeHtml(video.uploaded_by_name) + '</small><div class="operation-ai-detail-video-actions"><span class="text-muted"><i class="fa fa-file-video-o"></i> ' + escapeHtml(video.file_name) + '</span><a class="operation-ai-detail-video-download" href="' + escapeHtml(video.download_url) + '"><i class="fa fa-download"></i> 下载</a></div></div></article>';
            }).join('') + '</div>' : '<div class="operation-ai-detail-video-empty"><i class="fa fa-inbox"></i> 暂无' + group.label + '</div>';
            return '<section class="operation-ai-detail-video-group"><div class="operation-ai-detail-video-group-head"><span><i class="fa ' + group.icon + '"></i> ' + group.label + '</span><span class="badge">' + groupVideos.length + '</span></div>' + cards + '</section>';
        }).join('') + '</div>';
    }
    function render(data) {
        var landing = data.landing_page_url
            ? '<a class="operation-ai-detail-link" href="' + escapeHtml(data.landing_page_url) + '" target="_blank" rel="noopener noreferrer"><i class="fa fa-external-link"></i>' + escapeHtml(data.landing_page_url) + '</a>'
            : escapeHtml(labels.empty);
        var images = data.images && data.images.length
            ? '<div class="operation-ai-detail-images">' + data.images.map(function (url) { return '<button type="button" class="operation-ai-detail-image" onclick="window.erpZoomOpen && window.erpZoomOpen(this.querySelector(\'img\').src)"><img src="' + escapeHtml(url) + '" alt=""></button>'; }).join('') + '</div>'
            : escapeHtml(labels.empty);
        var heroImage = data.images && data.images.length
            ? '<img src="' + escapeHtml(data.images[0]) + '" alt="">'
            : '<i class="fa fa-picture-o"></i>';
        var hero = '<div class="operation-ai-detail-hero"><div class="operation-ai-detail-hero-thumb">' + heroImage +
            '</div><div class="operation-ai-detail-hero-main"><h4>' + escapeHtml(data.name) + '</h4><span class="operation-ai-detail-code">' + escapeHtml(data.product_code) + '</span></div></div>';
        var packageFile = data.package_download_url
            ? '<a class="btn btn-primary btn-sm" href="' + escapeHtml(data.package_download_url) + '"><i class="fa fa-download"></i> ' + escapeHtml(labels.download_package) + '</a>' +
                '<span class="text-muted" style="margin-left:8px;">' + escapeHtml(data.package_file_name) + '</span>'
            : escapeHtml(labels.empty);
        var shopifyData = data.shopify_data
            ? escapeHtml(data.shopify_data.title) + '<br><small>' +
                escapeHtml(data.shopify_data.vendor + ' / ' + data.shopify_data.product_type + ' / ' + data.shopify_data.variant_count + ' 个规格 / ' + data.shopify_data.image_count + ' 张图片') + '</small>'
            : escapeHtml(labels.empty);
        content.innerHTML =
            hero + item(labels.sku, value(data.skus), true) + item(labels.category, value(data.categories), true) +
            item(labels.source_type, value(data.source_label) + ' / ' + value(data.type_label)) + item(labels.landing_page, landing) +
            item('产品页 / 对应落地页', productPages(data.product_pages), true) +
            (!data.product_pages || !data.product_pages.length ? item('Shopify 完整资料', shopifyData, true) : '') +
            item(labels.images, images, true) + item(labels.reference_file, value(data.reference_file_name)) +
            item('素材视频 / 视频编号', materialVideos(data), true) +
            item(labels.package_file, packageFile) + item(labels.creator, value(data.created_by_name)) +
            item(labels.updater, value(data.updated_by_name)) + item(labels.created_at, value(data.created_at)) +
            item(labels.updated_at, value(data.updated_at));
        loading.hidden = true; error.hidden = true; content.hidden = false;
    }
    function loadDetail(url) {
        loading.hidden = false; error.hidden = true; content.hidden = true; content.innerHTML = '';
        return fetch(url, {headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}})
            .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || labels.load_failed); return data; }); })
            .then(render)
            .catch(function (exception) { loading.hidden = true; error.textContent = exception.message || labels.load_failed; error.hidden = false; });
    }
    document.addEventListener('click', function (event) {
        var videoTrigger = event.target.closest('[data-operation-ai-video-preview]');
        if (videoTrigger && videoPreviewModal && videoPreviewPlayer && window.jQuery) {
            event.preventDefault();
            videoPreviewPlayer.pause();
            videoPreviewPlayer.src = videoTrigger.getAttribute('data-video-src') || '';
            videoPreviewPlayer.load();
            if (videoPreviewTitle) videoPreviewTitle.textContent = videoTrigger.getAttribute('data-video-name') || '素材视频';
            if (videoPreviewOpen) videoPreviewOpen.href = videoPreviewPlayer.src;
            if (videoPreviewError) videoPreviewError.hidden = true;
            window.jQuery(videoPreviewModal).modal('show');
            return;
        }
        var button = event.target.closest('[data-operation-ai-detail]');
        if (!button) return;
        event.preventDefault();
        window.jQuery(modal).modal('show');
        loadDetail(button.getAttribute('data-detail-url'));
    });
    if (videoPreviewPlayer) {
        videoPreviewPlayer.addEventListener('error', function () {
            if (videoPreviewError) videoPreviewError.hidden = false;
        });
    }
    if (videoPreviewModal && window.jQuery) {
        window.jQuery(videoPreviewModal).on('hidden.bs.modal', function () {
            videoPreviewPlayer.pause();
            videoPreviewPlayer.removeAttribute('src');
            videoPreviewPlayer.load();
            if (document.querySelector('.modal.in')) document.body.classList.add('modal-open');
        });
    }
})();
(function () {
    var modal = document.getElementById('operation-ai-material-upload-modal');
    if (!modal) return;
    var form = modal.querySelector('[data-operation-ai-material-upload-form]');
    var product = modal.querySelector('[data-operation-ai-material-product]');
    var productId = modal.querySelector('[data-operation-ai-material-product-id]');
    var error = modal.querySelector('[data-operation-ai-material-error]');
    var progress = modal.querySelector('[data-operation-ai-material-progress]');
    var progressBar = modal.querySelector('[data-operation-ai-material-progress-bar]');
    var progressText = modal.querySelector('[data-operation-ai-material-progress-text]');
    var submit = form.querySelector('[type="submit"]');

    function setProgress(percent, text) {
        progress.hidden = false;
        progressBar.style.width = percent + '%';
        progressBar.textContent = percent + '%';
        progressText.textContent = text;
    }
    function reset() {
        form.reset();
        error.hidden = true;
        error.textContent = '';
        progress.hidden = true;
        progressBar.style.width = '0%';
        progressBar.textContent = '';
        progressText.textContent = '准备上传...';
        submit.disabled = false;
        submit.innerHTML = '<i class="fa fa-upload"></i> 开始上传';
    }
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-operation-ai-material-upload-trigger]');
        if (!trigger) return;
        reset();
        form.action = trigger.getAttribute('data-upload-url');
        productId.value = trigger.getAttribute('data-product-id') || '';
        product.textContent = '当前产品：' + (trigger.getAttribute('data-product-label') || '—');
        window.jQuery(modal).modal('show');
    });
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!form.reportValidity()) return;
        error.hidden = true;
        submit.disabled = true;
        submit.innerHTML = '<i class="fa fa-spinner fa-spin"></i> 上传中...';
        setProgress(0, '正在上传素材视频...');

        var request = new XMLHttpRequest();
        request.open('POST', form.action, true);
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.setRequestHeader('X-CSRF-TOKEN', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
        request.upload.onprogress = function (uploadEvent) {
            if (!uploadEvent.lengthComputable) return;
            var percent = Math.min(99, Math.round(uploadEvent.loaded / uploadEvent.total * 100));
            setProgress(percent, '正在上传素材视频...');
        };
        function uploadErrorMessage(payload, status) {
            if (payload && payload.message) return payload.message;
            if (payload && payload.errors) {
                var fields = Object.keys(payload.errors);
                if (fields.length && payload.errors[fields[0]] && payload.errors[fields[0]][0]) {
                    return payload.errors[fields[0]][0];
                }
            }
            if (status === 413) return '视频文件超过服务器允许的上传大小，请压缩后重试。';
            if (status === 419) return '登录状态或页面令牌已过期，请刷新页面后重新上传。';
            if (status === 422) return '视频文件校验未通过，请检查格式和文件大小。';
            return '视频上传失败（HTTP ' + status + '），请重试或联系管理员查看服务器日志。';
        }
        request.onload = function () {
            var payload = {};
            try { payload = JSON.parse(request.responseText || '{}'); } catch (ignore) {}
            if (request.status < 200 || request.status >= 300) {
                error.textContent = uploadErrorMessage(payload, request.status);
                error.hidden = false;
                submit.disabled = false;
                submit.innerHTML = '<i class="fa fa-upload"></i> 重新上传';
                setProgress(0, '上传失败');
                return;
            }
            setProgress(100, '上传完成：' + ((payload.video && payload.video.video_code) || '素材视频'));
            submit.innerHTML = '<i class="fa fa-check"></i> 上传成功';
            window.setTimeout(function () { window.jQuery(modal).modal('hide'); }, 700);
        };
        request.onerror = function () {
            error.textContent = '网络异常，视频上传失败，请重试。';
            error.hidden = false;
            submit.disabled = false;
            submit.innerHTML = '<i class="fa fa-upload"></i> 重新上传';
            setProgress(0, '上传失败');
        };
        request.send(new FormData(form));
    });
})();
(function () {
    var modal = document.getElementById('operation-ai-product-publish-modal');
    if (!modal) return;
    var content = modal.querySelector('[data-operation-ai-publish-content]');
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-operation-ai-publish]');
        if (!button) return;
        content.innerHTML = '<div class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> 正在加载店铺信息...</div>';
        window.jQuery(modal).modal('show');
        fetch(button.getAttribute('data-publish-url'), {headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function (response) { if (!response.ok) throw new Error('店铺信息加载失败'); return response.text(); })
            .then(function (html) {
                content.innerHTML = html;
                var search = content.querySelector('[data-publish-store-search]');
                var searchButton = content.querySelector('[data-publish-store-search-button]');
                var filterStores = function () {
                    var keyword = search.value.trim().toLocaleLowerCase(), visible = 0;
                    content.querySelectorAll('[data-publish-store-card]').forEach(function (card) {
                        var matched = !keyword || (card.getAttribute('data-search-text') || '').indexOf(keyword) !== -1;
                        card.hidden = !matched;
                        if (matched) visible++;
                    });
                    var empty = content.querySelector('[data-publish-store-empty]');
                    var resultCount = content.querySelector('[data-publish-store-result-count]');
                    var storeOptions = content.querySelector('[data-publish-store-options]');
                    if (empty) empty.hidden = visible > 0;
                    if (resultCount) resultCount.textContent = visible;
                    if (storeOptions) storeOptions.scrollTop = 0;
                };
                if (search && searchButton) {
                    searchButton.addEventListener('click', filterStores);
                    search.addEventListener('search', filterStores);
                    search.addEventListener('keydown', function (event) {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            filterStores();
                        }
                    });
                }
                var productPageSelect = content.querySelector('[data-product-page-select]');
                if (productPageSelect) {
                    var switchProductPage = function () {
                        var selected = productPageSelect.options[productPageSelect.selectedIndex];
                        var pageId = productPageSelect.value;
                        content.querySelectorAll('[data-product-page-panel]').forEach(function (panel) {
                            panel.hidden = panel.getAttribute('data-product-page-panel') !== pageId;
                        });
                        var titleInput = content.querySelector('input[name="title"]');
                        var statusInput = content.querySelector('select[name="status"]');
                        var skuInput = content.querySelector('[data-product-page-sku]');
                        var priceInput = content.querySelector('[data-product-page-price]');
                        var landingLink = content.querySelector('[data-product-page-landing-link]');
                        if (titleInput) titleInput.value = selected.getAttribute('data-title') || '';
                        if (statusInput) statusInput.value = selected.getAttribute('data-status') || 'DRAFT';
                        if (skuInput) skuInput.value = selected.getAttribute('data-sku') || '';
                        if (priceInput) priceInput.value = selected.getAttribute('data-price') || '0';
                        if (landingLink) {
                            var landingUrl = selected.getAttribute('data-landing-page-url') || '';
                            landingLink.hidden = !landingUrl;
                            landingLink.href = landingUrl || '#';
                        }
                    };
                    productPageSelect.addEventListener('change', switchProductPage);
                    switchProductPage();
                }
            })
            .catch(function (error) { content.innerHTML = '<div class="alert alert-danger">' + error.message + '</div>'; });
    });
})();
(function () {
    var modal = document.getElementById('operation-ai-placement-modal');
    if (!modal) return;
    var content = modal.querySelector('[data-operation-ai-placement-content]');
    function initializePlacementRecords() {
        var container=content.querySelector('[data-placement-records]'),template=content.querySelector('#placement-record-template'),add=content.querySelector('[data-add-placement-record]');
        if(!container||!template||!add)return;
        if (window.jQuery && window.jQuery.fn.popover) {
            window.jQuery(content).find('[data-toggle="popover"]').popover({container:'body'});
        }
        var picker=content.querySelector('[data-placement-material-picker]'),materialInput=content.querySelector('[data-placement-material-search]'),materialId=content.querySelector('[data-placement-material-video-id]'),materialResults=content.querySelector('[data-placement-material-results]'),materialSelected=content.querySelector('[data-placement-material-selected]'),searchTimer=null,searchController=null;
        function escapeMaterialHtml(value){return String(value==null?'':value).replace(/[&<>"']/g,function(character){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character];});}
        function hideMaterialResults(){if(materialResults){materialResults.style.display='none';materialResults.innerHTML='';}}
        function renderMaterialResults(items){
            if(!materialResults)return;
            if(!items.length){materialResults.innerHTML='<div class="list-group-item text-muted">未找到匹配的素材视频，请关闭弹窗并点击产品列表中的“上传素材”，上传后再返回搜索选择。</div>';materialResults.style.display='block';return;}
            materialResults.innerHTML=items.map(function(item){return '<div class="list-group-item" style="display:flex;align-items:center;gap:8px;padding:8px 10px"><button type="button" class="btn btn-link" style="min-width:0;padding:0;text-align:left;flex:1;overflow:hidden" data-select-material-video="'+Number(item.id)+'" data-material-name="'+escapeMaterialHtml(item.name)+'"><strong style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+escapeMaterialHtml(item.name)+'</strong><small class="text-muted">'+escapeMaterialHtml(item.type)+' · '+escapeMaterialHtml(item.size)+(item.original_name?' · 原文件：'+escapeMaterialHtml(item.original_name):'')+'</small></button><a class="btn btn-info btn-xs" href="'+escapeMaterialHtml(item.preview_url)+'" target="_blank" rel="noopener noreferrer"><i class="fa fa-play"></i> 预览</a></div>';}).join('');
            materialResults.style.display='block';
        }
        if(picker&&materialInput&&materialId&&materialResults){
            materialInput.addEventListener('input',function(){
                materialId.value='';
                materialSelected.className='help-block';
                materialSelected.textContent='输入关键词并选择素材，系统会自动关联对应视频，无需再次上传。';
                window.clearTimeout(searchTimer);
                var keyword=materialInput.value.trim();
                if(!keyword){hideMaterialResults();return;}
                searchTimer=window.setTimeout(function(){
                    if(searchController)searchController.abort();
                    searchController=new AbortController();
                    materialResults.innerHTML='<div class="list-group-item text-muted"><i class="fa fa-spinner fa-spin"></i> 正在搜索素材...</div>';materialResults.style.display='block';
                    fetch(picker.getAttribute('data-search-url')+'?keyword='+encodeURIComponent(keyword),{headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},signal:searchController.signal})
                        .then(function(response){if(!response.ok)throw new Error('素材搜索失败');return response.json();})
                        .then(function(data){renderMaterialResults(Array.isArray(data.items)?data.items:[]);})
                        .catch(function(error){if(error.name==='AbortError')return;materialResults.innerHTML='<div class="list-group-item text-danger">'+escapeMaterialHtml(error.message||'素材搜索失败，请重试。')+'</div>';materialResults.style.display='block';});
                },250);
            });
            materialResults.addEventListener('click',function(event){
                var option=event.target.closest('[data-select-material-video]');if(!option)return;
                materialId.value=option.getAttribute('data-select-material-video');materialInput.value=option.getAttribute('data-material-name');
                materialSelected.className='help-block text-success';materialSelected.innerHTML='<i class="fa fa-check-circle"></i> 已选择素材库视频：'+escapeMaterialHtml(materialInput.value)+'，提交时将自动关联该视频。';hideMaterialResults();
            });
            materialInput.addEventListener('focus',function(){if(materialResults.innerHTML)materialResults.style.display='block';});
            document.addEventListener('click',function(event){if(!picker.contains(event.target))hideMaterialResults();});
        }
        function renumber(){var rows=container.querySelectorAll('[data-placement-record]');rows.forEach(function(row,index){row.querySelector('[data-record-title]').textContent='投放平台 '+(index+1);row.querySelectorAll('[data-field]').forEach(function(field){field.name='records['+index+']['+field.getAttribute('data-field')+']';});row.querySelector('[data-remove-placement-record]').disabled=rows.length===1;});add.disabled=rows.length>=6;}
        function append(){if(container.querySelectorAll('[data-placement-record]').length>=6)return;container.appendChild(template.content.cloneNode(true));renumber();}
        add.addEventListener('click',append);container.addEventListener('click',function(event){var button=event.target.closest('[data-remove-placement-record]');if(!button||container.querySelectorAll('[data-placement-record]').length===1)return;button.closest('[data-placement-record]').remove();renumber();});append();
        var form=content.querySelector('form'),progress=content.querySelector('[data-placement-upload-progress]'),status=content.querySelector('[data-placement-upload-status]'),percent=content.querySelector('[data-placement-upload-percent]'),bar=content.querySelector('[data-placement-upload-bar]'),submit=form&&form.querySelector('[type="submit"]');
        function setProgress(value,message,error){value=Math.max(0,Math.min(100,Math.round(value)));progress.hidden=false;status.textContent=message;percent.textContent=value+'%';bar.style.width=value+'%';bar.classList.toggle('progress-bar-danger',!!error);bar.classList.toggle('progress-bar-info',!error);}
        if(form&&progress)form.addEventListener('submit',function(event){event.preventDefault();if(!form.reportValidity())return;submit.disabled=true;add.disabled=true;setProgress(0,'准备上传投放视频...',false);var xhr=new XMLHttpRequest();xhr.open('POST',form.action,true);xhr.setRequestHeader('Accept','application/json');xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');xhr.upload.addEventListener('progress',function(uploadEvent){if(uploadEvent.lengthComputable)setProgress(uploadEvent.loaded/uploadEvent.total*100,'正在上传投放视频...',false);});xhr.addEventListener('load',function(){var data={};try{data=JSON.parse(xhr.responseText||'{}');}catch(ignore){}if(xhr.status>=200&&xhr.status<300){setProgress(100,'上传完成，正在刷新列表...',false);window.location.assign(data.redirect_url||window.location.href);return;}var errors=data.errors||{},firstKey=Object.keys(errors)[0],message=(firstKey&&errors[firstKey]&&errors[firstKey][0])||data.message||'保存失败，请重试。';setProgress(0,message,true);submit.disabled=false;renumber();});xhr.addEventListener('error',function(){setProgress(0,'网络异常，上传失败，请重试。',true);submit.disabled=false;renumber();});xhr.send(new FormData(form));});
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-operation-ai-placement]');
        if (!button) return;
        content.innerHTML = '<div class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> 正在加载投放测评...</div>';
        window.jQuery(modal).modal('show');
        fetch(button.getAttribute('data-placement-url'), {headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function (response) { if (!response.ok) throw new Error('投放测评加载失败'); return response.text(); })
            .then(function (html) { content.innerHTML = html; initializePlacementRecords(); })
            .catch(function (error) { content.innerHTML = '<div class="alert alert-danger">' + error.message + '</div>'; });
    });
})();
</script>
@endpush
