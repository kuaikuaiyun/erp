@extends('portal.layout')

@section('page_title', 'Shopline 订单')

@push('styles')
<style>
.shopline-orders-page{--sl-ink:#16324a;--sl-muted:#71869a;--sl-line:#dce7ef;--sl-blue:#2189bd;--sl-blue-soft:#eef8fc;--sl-green:#17865c;--sl-amber:#b87510}.shopline-orders-page .box{border:0;border-radius:14px;box-shadow:0 5px 22px rgba(28,69,96,.08);overflow:hidden}.sl-page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin:2px 0 18px}.sl-page-head h1{margin:0;color:var(--sl-ink);font-size:23px;font-weight:700}.sl-page-head p{margin:6px 0 0;color:var(--sl-muted);font-size:13px}.sl-filter{display:flex;align-items:flex-end;flex-wrap:wrap;gap:12px;padding:17px 19px;background:linear-gradient(110deg,#fff,#f4faff)}.sl-filter .form-group{margin:0}.sl-filter label{display:block;margin:0 0 6px;color:#637d91;font-size:12px;font-weight:600}.sl-filter .form-control{height:38px;min-width:135px;border-color:#d5e3ec;border-radius:7px;box-shadow:none}.sl-filter .sl-keyword{min-width:260px}.sl-filter .btn{height:38px;border-radius:7px;padding:0 14px;font-weight:600}.sl-filter .sl-query{background:#2189bd;border-color:#2189bd}.sl-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 19px;border-bottom:1px solid var(--sl-line);background:#fff}.sl-toolbar h3{margin:0;color:var(--sl-ink);font-size:15px;font-weight:700}.sl-toolbar small{margin-left:7px;color:var(--sl-muted);font-weight:400}.sl-sync-button{border-color:#b7dcca;color:#167650;background:#f2fbf7;border-radius:7px;font-weight:600}.sl-sync-button:hover{background:#e1f6ec;color:#116443}.sl-order-table{margin:0}.sl-order-table th{padding:11px 9px!important;background:#edf6fb!important;border-color:#dce8f0!important;color:#3b637d;font-size:12px;text-align:center;vertical-align:middle!important;white-space:nowrap}.sl-order-table td{padding:12px 9px!important;border-color:#e2ebf1!important;vertical-align:middle!important}.sl-order-table tbody tr:hover td{background:#f6fbfe!important}.sl-order-no{font-weight:700;color:#176f9f}.sl-sub{margin-top:3px;color:#8092a0;font-size:11px}.sl-store{font-weight:700;color:#254f6b}.sl-handle{margin-top:3px;color:#788c9d;font-size:11px}.sl-customer{max-width:145px}.sl-customer strong{display:block;color:#36576d;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.sl-products{min-width:245px}.sl-product{display:flex;gap:7px;align-items:center;padding:4px 0;border-bottom:1px dashed #e2ebf0}.sl-product:last-child{border-bottom:0}.sl-product img{width:31px;height:31px;flex:none;border:1px solid #dbe6ed;border-radius:5px;object-fit:cover}.sl-product-name{max-width:180px;overflow:hidden;color:#405e71;font-size:12px;text-overflow:ellipsis;white-space:nowrap}.sl-sku{margin-top:1px;color:#7892a4;font-size:11px}.sl-pill{display:inline-flex;align-items:center;padding:3px 8px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}.sl-pill.order{background:#eaf5fb;color:#1b759d}.sl-pill.payment{margin-top:4px;background:#f4eefc;color:#7953ac}.sl-pill.fulfillment{margin-top:4px;background:#fdf5e9;color:#a36912}.sl-money{color:#153e59;font-size:13px;font-weight:700;white-space:nowrap}.sl-detail-button{border-radius:6px;font-weight:600}.sl-empty{padding:65px 20px!important;text-align:center;color:#8a9ca9}.sl-empty i{margin-bottom:10px;color:#abd0e2}.sl-pagination{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:14px 19px;background:#fbfdff;border-top:1px solid var(--sl-line)}.sl-page-summary{color:#71869a;font-size:12px}.sl-page-summary strong{color:#217ba8}.sl-page-list{display:flex;align-items:center;gap:5px}.sl-page-link,.sl-page-active,.sl-page-gap{display:inline-flex;align-items:center;justify-content:center;height:31px;min-width:31px;padding:0 9px;border-radius:6px;font-size:12px}.sl-page-link{border:1px solid #d4e2ea;color:#35657e;background:#fff}.sl-page-link:hover{border-color:#2189bd;color:#fff;background:#2189bd}.sl-page-link.is-disabled{pointer-events:none;color:#a7b5bf;background:#f4f6f8}.sl-page-active{border:1px solid #177aaa;background:#2189bd;color:#fff;font-weight:700}.sl-page-gap{color:#8a9aa5}.sl-modal .modal-content{border:0;border-radius:12px;overflow:hidden;box-shadow:0 16px 55px rgba(20,58,82,.24)}.sl-modal .modal-header{padding:15px 18px;border:0;background:linear-gradient(120deg,#1b81b5,#286d99);color:#fff}.sl-modal .modal-title{font-size:16px;font-weight:700}.sl-modal .close{color:#fff;opacity:.85}.sl-modal .modal-body{padding:18px;background:#f8fbfd}.sl-sync-table{margin:0;background:#fff;border:1px solid #e0eaf0;border-radius:8px;overflow:hidden}.sl-sync-table th{padding:10px!important;background:#eff6fa;color:#547287;font-size:12px;white-space:nowrap}.sl-sync-table td{padding:10px!important;border-color:#e6eef3!important;font-size:12px;vertical-align:middle!important}.sl-ready{color:#16885a;font-weight:700}.sl-not-ready{color:#cc6a2d;font-weight:700}.sl-state{font-weight:700}.sl-state.success{color:#16885a}.sl-state.failed{color:#d55245}.sl-state.syncing{color:#bf7d10}.sl-detail-loading{padding:48px;text-align:center;color:#668092}.sl-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:16px}.sl-detail-card{min-height:77px;padding:11px 12px;border:1px solid #e1eaf0;border-radius:8px;background:#fff}.sl-detail-card span{display:block;color:#8093a2;font-size:11px}.sl-detail-card strong{display:block;margin-top:5px;color:#264c66;font-size:13px;word-break:break-word}.sl-detail-section{margin-top:16px}.sl-detail-section h4{margin:0 0 8px;color:#315e79;font-size:13px;font-weight:700}.sl-item-table{margin:0;background:#fff}.sl-item-table th{background:#edf5f9;color:#4c6e82;font-size:11px}.sl-item-table th,.sl-item-table td{padding:8px!important;border-color:#e3ebf1!important;font-size:12px}.sl-raw{max-height:350px;margin:0;padding:13px;overflow:auto;border:1px solid #1a354a;border-radius:8px;background:#152b3c;color:#cde6f4;font:12px/1.5 Consolas,"Courier New",monospace;white-space:pre-wrap;word-break:break-word}@media(max-width:1100px){.sl-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:760px){.sl-page-head,.sl-toolbar{align-items:flex-start;flex-direction:column}.sl-filter .form-control,.sl-filter .sl-keyword{min-width:100%;width:100%}.sl-filter .form-group{width:100%}.sl-detail-grid{grid-template-columns:1fr}.sl-pagination{justify-content:center}.sl-page-summary{width:100%;text-align:center}}
</style>
<style>
/* Keep the Shopline list visually aligned with the logistics order grid. */
.shopline-orders-page .sl-order-table{width:100%;margin:0;border:1px solid #cbdce8;border-collapse:separate;border-spacing:0;background:#fff}
.shopline-orders-page .sl-order-table th{height:43px;padding:10px 12px!important;border-top:0!important;border-right:1px solid #cbdce8!important;border-bottom:1px solid #cbdce8!important;background:#e9f2f8!important;color:#24506d;font-size:13px;font-weight:700;letter-spacing:.1px;text-align:center;vertical-align:middle!important}
.shopline-orders-page .sl-order-table th:last-child{border-right:0!important}
.shopline-orders-page .sl-order-table td{min-height:88px;padding:14px 12px!important;border-top:0!important;border-right:1px solid #d9e5ed!important;border-bottom:1px solid #d9e5ed!important;background:#fff;color:#36556a;vertical-align:middle!important}
.shopline-orders-page .sl-order-table td:last-child{border-right:0!important;text-align:center}
.shopline-orders-page .sl-order-table tbody tr:last-child td{border-bottom:0!important}
.shopline-orders-page .sl-order-table tbody tr:hover td{background:#f4f9fc!important}
.shopline-orders-page .sl-order-table .sl-order-no,.shopline-orders-page .sl-order-table .sl-store,.shopline-orders-page .sl-order-table .sl-customer strong,.shopline-orders-page .sl-order-table .sl-money{color:#175d89}
.shopline-orders-page .sl-order-table .sl-order-no{font-size:14px}
.shopline-orders-page .sl-order-table .sl-store{font-size:13px}
.shopline-orders-page .sl-order-table .sl-sub,.shopline-orders-page .sl-order-table .sl-handle{margin-top:5px;color:#7890a1;font-size:11px;line-height:1.45}
.shopline-orders-page .sl-order-table .sl-products{min-width:300px;padding-top:10px!important;padding-bottom:10px!important}
.shopline-orders-page .sl-order-table .sl-product{min-height:45px;gap:9px;padding:6px 0;border-bottom:1px dashed #d7e4ec}
.shopline-orders-page .sl-order-table .sl-product img{width:37px;height:37px;border-radius:4px}
.shopline-orders-page .sl-order-table .sl-product-name{max-width:235px;color:#35617d;font-size:12px;font-weight:600}
.shopline-orders-page .sl-order-table .sl-sku{margin-top:3px;color:#6d93ad;font-size:11px}
.shopline-orders-page .sl-order-table .sl-pill{min-width:58px;justify-content:center;margin-bottom:3px;padding:3px 8px;font-weight:700}
.shopline-orders-page .sl-order-table .sl-detail-button{min-width:56px;border-color:#a9cee4;background:#fff;color:#216c98;font-weight:600}
.shopline-orders-page .sl-order-table .sl-detail-button:hover{border-color:#2189bd;background:#2189bd;color:#fff}
.shopline-orders-page .sl-field-line{display:flex;align-items:baseline;gap:5px;max-width:100%;line-height:1.55}
.shopline-orders-page .sl-field-line+.sl-field-line{margin-top:3px}
.shopline-orders-page .sl-field-label{flex:none;color:#6f8ca2;font-size:11px;font-weight:600;white-space:nowrap}
.shopline-orders-page .sl-field-value{min-width:0;overflow:hidden;color:#265e84;font-size:12px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}
.shopline-orders-page .sl-field-value.muted{color:#7890a1;font-size:11px;font-weight:400}
.shopline-orders-page .sl-order-information{min-width:315px}.shopline-orders-page .sl-logistics-information{min-width:150px}.shopline-orders-page .sl-customer-information{min-width:205px}
.shopline-orders-page .sl-info-block+.sl-info-block{margin-top:8px;padding-top:8px;border-top:1px dashed #d9e5ed}
.shopline-orders-page .sl-status-stack{min-width:108px}.shopline-orders-page .sl-status-line{display:flex;align-items:baseline;gap:5px;line-height:1.7;white-space:nowrap}.shopline-orders-page .sl-status-value{font-size:12px;font-weight:700}.shopline-orders-page .sl-status-value.order{color:#24739a}.shopline-orders-page .sl-status-value.payment{color:#7351a4}.shopline-orders-page .sl-status-value.fulfillment{color:#a16a16}.shopline-orders-page .sl-logistics-body{display:block}
.shopline-orders-page .sl-toolbar{border:1px solid #cbdce8;border-bottom:0;border-radius:8px 8px 0 0;background:#fff}
.shopline-orders-page .sl-toolbar h3{font-size:16px}
@media(max-width:760px){.shopline-orders-page .sl-order-table th,.shopline-orders-page .sl-order-table td{padding:10px!important}.shopline-orders-page .sl-order-table .sl-products{min-width:250px}.shopline-orders-page .sl-order-table .sl-product-name{max-width:180px}}
</style>
<style>
.shopline-orders-page .sl-sync-modal .modal-dialog{width:92%;max-width:1320px;margin:24px auto}.shopline-orders-page .sl-sync-modal .modal-content{border:1px solid #d9e7ef;border-radius:9px;box-shadow:0 20px 60px rgba(23,58,82,.28)}.shopline-orders-page .sl-sync-modal .modal-header{padding:16px 20px;border-bottom:1px solid #d9e7ef;background:#fff;color:#16324a}.shopline-orders-page .sl-sync-modal .modal-header .close{margin-top:0;color:#486b80;opacity:.8}.shopline-orders-page .sl-sync-modal .modal-title{font-size:18px}.shopline-orders-page .sl-sync-modal .modal-header p{margin:5px 0 0;color:#7690a2;font-size:12px}.shopline-orders-page .sl-sync-modal .modal-body{padding:14px 18px 16px;background:#f7fafc}.sl-sync-filter{display:grid;grid-template-columns:minmax(250px,2fr) minmax(145px,1fr) minmax(145px,1fr) 115px auto;gap:10px;align-items:end;margin-bottom:12px;padding:12px;border:1px solid #d8e6ef;border-radius:7px;background:#fff}.sl-sync-filter .form-group{margin:0}.sl-sync-filter label{display:block;margin:0 0 5px;color:#648096;font-size:12px;font-weight:600}.sl-sync-filter .form-control{height:34px;border-color:#d1e0ea;border-radius:5px;box-shadow:none}.sl-sync-filter .btn{height:34px;border-radius:5px;font-weight:600}.sl-sync-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:12px;border:1px solid #d7e5ee;border-radius:7px;background:#fff;overflow:hidden}.sl-sync-metric{padding:10px 13px;border-right:1px solid #e3edf3}.sl-sync-metric:last-child{border-right:0}.sl-sync-metric span{display:block;color:#7892a4;font-size:11px}.sl-sync-metric strong{display:block;margin-top:4px;color:#244f6c;font-size:17px}.sl-sync-metric.good strong{color:#16885a}.sl-sync-metric.warning strong{color:#c76b22}.sl-sync-metric.danger strong{color:#d35348}.shopline-orders-page .sl-sync-table{border-radius:7px}.shopline-orders-page .sl-sync-table th{padding:10px!important;background:#eef5f9;color:#55748a;text-align:left}.shopline-orders-page .sl-sync-table td{padding:11px 10px!important;background:#fff}.sl-sync-status{display:inline-block;padding:3px 8px;border-radius:99px;font-size:11px;font-weight:700}.sl-sync-status.success{background:#e8f8f0;color:#16885a}.sl-sync-status.failed{background:#fff0ef;color:#cc4b42}.sl-sync-status.syncing{background:#fff6e8;color:#b97715}.sl-sync-status.pending{background:#eef3f6;color:#667f90}.sl-sync-error{max-width:220px;color:#c34d43;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.sl-sync-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px}.sl-sync-range{color:#71899b;font-size:12px}@media(max-width:900px){.sl-sync-filter{grid-template-columns:1fr 1fr}.sl-sync-filter .form-group:first-child{grid-column:1/-1}.sl-sync-summary{grid-template-columns:1fr 1fr}.sl-sync-metric:nth-child(2){border-right:0}.sl-sync-metric:nth-child(n+3){border-top:1px solid #e3edf3}.shopline-orders-page .sl-sync-modal .modal-dialog{width:96%}}@media(max-width:560px){.sl-sync-filter{grid-template-columns:1fr}.sl-sync-filter .form-group:first-child{grid-column:auto}.sl-sync-footer{flex-direction:column}.sl-sync-range{text-align:center}.sl-page-list{justify-content:center;flex-wrap:wrap}}
</style>
<style>
/* 与服装物流系统的数据弹窗保持一致：宽面板、浅色分区、表格优先。 */
.shopline-orders-page .sl-sync-modal .modal-dialog{width:calc(100% - 56px)!important;max-width:1480px!important;margin:26px auto!important}
.shopline-orders-page .sl-sync-modal .modal-content{border:1px solid #d8e4ed!important;border-radius:10px!important;background:#fff!important;box-shadow:0 18px 54px rgba(13,42,61,.28)!important}
.shopline-orders-page .sl-sync-modal .modal-header{min-height:70px;padding:17px 22px!important;border-bottom:1px solid #dce8f0!important;background:#fff!important;color:#183b55!important}
.shopline-orders-page .sl-sync-modal .modal-header .close{margin-top:-2px!important;color:#3d647d!important;font-size:25px!important;opacity:.82!important}
.shopline-orders-page .sl-sync-modal .modal-title{font-size:19px!important;line-height:1.25!important;font-weight:700!important}
.shopline-orders-page .sl-sync-modal .modal-header p{margin:5px 0 0!important;color:#7890a2!important;font-size:12px!important}
.shopline-orders-page .sl-sync-modal .modal-body{padding:18px 22px 20px!important;background:#f7fafc!important}
.shopline-orders-page .sl-sync-filter{grid-template-columns:minmax(300px,2.1fr) minmax(175px,1fr) minmax(175px,1fr) 118px 92px!important;gap:12px!important;margin-bottom:14px!important;padding:14px!important;border-color:#d7e4ed!important;border-radius:8px!important}
.shopline-orders-page .sl-sync-filter .form-control{height:38px!important;border-radius:6px!important}
.shopline-orders-page .sl-sync-filter .btn{height:38px!important;border-radius:6px!important}
.shopline-orders-page .sl-sync-summary{margin-bottom:14px!important;border-color:#d7e4ed!important;border-radius:8px!important}
.shopline-orders-page .sl-sync-metric{padding:12px 16px!important}
.shopline-orders-page .sl-sync-metric span{font-size:12px!important}
.shopline-orders-page .sl-sync-metric strong{font-size:20px!important}
.shopline-orders-page .sl-sync-table{min-width:1080px!important;border-color:#d8e5ed!important;border-radius:8px!important}
.shopline-orders-page .sl-sync-table th{padding:12px 13px!important;background:#eef5f9!important;color:#4d6d83!important;font-size:12px!important;font-weight:700!important}
.shopline-orders-page .sl-sync-table td{padding:13px!important;border-color:#e0ebf2!important;font-size:13px!important;line-height:1.45!important}
.shopline-orders-page .sl-sync-table th:nth-child(1){width:18%}.shopline-orders-page .sl-sync-table th:nth-child(2){width:20%}.shopline-orders-page .sl-sync-table th:nth-child(3){width:15%}.shopline-orders-page .sl-sync-table th:nth-child(4){width:11%}.shopline-orders-page .sl-sync-table th:nth-child(5),.shopline-orders-page .sl-sync-table th:nth-child(6){width:10%;text-align:center}.shopline-orders-page .sl-sync-table td:nth-child(5),.shopline-orders-page .sl-sync-table td:nth-child(6){text-align:center;font-variant-numeric:tabular-nums}
.shopline-orders-page .sl-sync-footer{padding:1px 2px 0!important}
@media(max-width:900px){.shopline-orders-page .sl-sync-modal .modal-dialog{width:calc(100% - 20px)!important;margin:10px auto!important}.shopline-orders-page .sl-sync-modal .modal-body{padding:12px!important}.shopline-orders-page .sl-sync-filter{grid-template-columns:1fr 1fr!important}.shopline-orders-page .sl-sync-filter .form-group:first-child{grid-column:1/-1}}
@media(max-width:560px){.shopline-orders-page .sl-sync-filter{grid-template-columns:1fr!important}.shopline-orders-page .sl-sync-filter .form-group:first-child{grid-column:auto}}
</style>
<style>
/* 顶部筛选条对齐“产品风险与利润”页：标签在上、控件在下、操作按钮同一行。 */
.shopline-orders-page .sl-filter{display:grid!important;grid-template-columns:repeat(3,minmax(160px,1fr)) minmax(210px,1.2fr);align-items:end;gap:13px 14px!important;padding:17px 19px!important;background:#fff!important}
.shopline-orders-page .sl-filter .form-group{min-width:0!important;margin:0!important}
.shopline-orders-page .sl-filter .form-control{width:100%!important;min-width:0!important;height:36px!important;border-radius:6px!important;color:#31536b!important}
.shopline-orders-page .sl-filter .sl-keyword{width:100%!important}
.shopline-orders-page .sl-filter label{height:16px;margin-bottom:5px!important;color:#597891!important;font-weight:700!important;line-height:16px!important;white-space:nowrap}
.shopline-orders-page .sl-filter-actions{display:flex;align-items:center;gap:8px;height:36px;grid-column:span 2;justify-content:flex-end}
.shopline-orders-page .sl-filter .btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;height:36px!important;margin:0!important;padding:0 13px!important;border-radius:5px!important;line-height:1!important;white-space:nowrap}
.shopline-orders-page .sl-filter .sl-query{background:#278bc0!important;border-color:#278bc0!important}
.shopline-orders-page .sl-filter .sl-reset{border-color:#d5e0e8!important;color:#49677c!important;background:#fff!important}
.shopline-orders-page .sl-filter .sl-reset:hover{border-color:#a9c6d8!important;background:#f5fafc!important;color:#2776a1!important}
@media(max-width:1050px){.shopline-orders-page .sl-filter{grid-template-columns:repeat(3,minmax(150px,1fr))!important}.shopline-orders-page .sl-filter .form-group:first-child{grid-column:span 3}.shopline-orders-page .sl-filter-actions{grid-column:span 3;justify-content:flex-start}}
@media(max-width:760px){.shopline-orders-page .sl-filter{grid-template-columns:1fr!important}.shopline-orders-page .sl-filter .form-group:first-child,.shopline-orders-page .sl-filter-actions{grid-column:auto}.shopline-orders-page .sl-filter-actions{justify-content:flex-start}}
</style>
@endpush

@section('content')
@php
    $orderLabels = ['open' => '处理中', 'cancelled' => '已取消'];
    $paymentLabels = ['unpaid' => '未支付', 'authorized' => '已授权', 'pending' => '支付中', 'partially_paid' => '部分支付', 'paid' => '已支付', 'partially_refunded' => '部分退款', 'refunded' => '已退款'];
    $fulfillmentLabels = ['unshipped' => '未发货', 'unfulfilled' => '未发货', 'partial' => '部分发货', 'partially_fulfilled' => '部分发货', 'shipped' => '已发货', 'fulfilled' => '已发货'];
    $fulfillmentFilterLabels = ['unshipped' => '未发货', 'partial' => '部分发货', 'shipped' => '已发货'];
    $marketingSourceLabels = ['google_ads' => 'Google Ads', 'tiktok' => 'TikTok', 'meta' => 'Meta', 'other_utm' => '其他 UTM', 'external_referral' => '外部引荐', 'direct_or_unknown' => '自然 / 未识别'];
    $syncLabels = ['pending' => '未同步', 'syncing' => '同步中', 'success' => '同步成功', 'failed' => '同步失败'];
@endphp
<section class="content shopline-orders-page">
    <div class="sl-page-head"><div><h1>Shopline 订单</h1><p>订单数据由 Shopline Admin API 增量同步；每 5 分钟自动更新一次。</p></div></div>

    <div class="box"><form method="get" action="{{ route('fashion-logistics.shopline-orders.index') }}" class="sl-filter">
        <div class="form-group"><label>关键词</label><input class="form-control sl-keyword" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="订单号、客户、邮箱、SKU、商品名称"></div>
        <div class="form-group"><label>店铺</label><select class="form-control" name="store_handle"><option value="">全部 Shopline 店铺</option>@foreach($storeHandles as $store)<option value="{{ $store->shop_name }}" @selected(strtolower(trim((string) ($filters['store_handle'] ?? ''))) === strtolower(trim((string) $store->shop_name)))>{{ $store->shop_name }}</option>@endforeach</select></div>
        <div class="form-group"><label>运营人员</label><select class="form-control" name="operator_id"><option value="">全部运营人员</option>@foreach($operators as $operator)<option value="{{ $operator->id }}" @selected((string)($filters['operator_id'] ?? '') === (string)$operator->id)>{{ $operator->name ?: $operator->username }}</option>@endforeach</select></div>
        <div class="form-group"><label>订单状态</label><select class="form-control" name="status"><option value="">全部订单状态</option><option value="open" @selected(($filters['status'] ?? '') === 'open')>处理中</option><option value="cancelled" @selected(($filters['status'] ?? '') === 'cancelled')>已取消</option></select></div>
        <div class="form-group"><label>支付状态</label><select class="form-control" name="payment_status"><option value="">全部支付状态</option>@foreach($paymentLabels as $key => $label)<option value="{{ $key }}" @selected(($filters['payment_status'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="form-group"><label>发货状态</label><select class="form-control" name="fulfillment_status"><option value="">全部发货状态</option>@foreach($fulfillmentFilterLabels as $key => $label)<option value="{{ $key }}" @selected(($filters['fulfillment_status'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="form-group"><label>订单来源</label><select class="form-control" name="marketing_source"><option value="">全部订单来源</option>@foreach($marketingSourceLabels as $key => $label)<option value="{{ $key }}" @selected(($filters['marketing_source'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="form-group"><label>下单开始</label><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
        <div class="form-group"><label>下单结束</label><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
        <div class="form-group"><label>每页</label><select class="form-control" name="per_page">@foreach([20, 50, 100] as $size)<option value="{{ $size }}" @selected((int)($filters['per_page'] ?? 20) === $size)>{{ $size }} 条</option>@endforeach</select></div>
        <div class="sl-filter-actions"><button class="btn btn-primary sl-query" type="submit"><i class="fa fa-search"></i> 查询</button><a class="btn btn-default sl-reset" href="{{ route('fashion-logistics.shopline-orders.index') }}"><i class="fa fa-refresh"></i> 重置</a></div>
    </form></div>

    <div class="box">
        <div class="sl-toolbar"><h3>订单列表 <small>共 {{ $orders->total() }} 笔 Shopline 订单</small></h3><button class="btn sl-sync-button" type="button" data-toggle="modal" data-target="#shopline-sync-modal" data-url="{{ route('fashion-logistics.shopline-orders.sync-status') }}"><i class="fa fa-refresh"></i> 店铺同步情况</button></div>
        <div class="table-responsive"><table class="table table-bordered table-hover sl-order-table"><thead><tr><th>订单信息</th><th>物流信息</th><th>客户信息</th><th>订单来源</th><th>商品摘要</th><th>订单金额</th><th>操作</th></tr></thead><tbody>
        @forelse($orders as $order)
            @php
                $fulfillmentStatus = strtolower(trim((string) $order->fulfillment_status));
                $storeOwner = trim((string) ($order->store?->ownerUser?->name ?: $order->store?->ownerUser?->username));
                $storeUser = trim((string) $order->store?->user_name);
                $storeTeams = collect($order->store?->team ?? [])->filter()->implode(' / ');
            @endphp
            <tr class="sl-order-row">
                <td class="sl-order-information"><div class="sl-info-block"><div class="sl-field-line"><span class="sl-field-label">日期：</span><span class="sl-field-value">{{ $order->order_created_at?->format('Y-m-d') ?: '—' }}</span></div><div class="sl-field-line"><span class="sl-field-label">时间：</span><span class="sl-field-value muted">{{ $order->order_created_at?->format('H:i:s') ?: '—' }}</span></div></div><div class="sl-info-block"><div class="sl-field-line"><span class="sl-field-label">店铺：</span><span class="sl-field-value">{{ $order->store_name ?: '未命名店铺' }}</span></div><div class="sl-field-line"><span class="sl-field-label">域名：</span><span class="sl-field-value muted">{{ $order->source_domain ?: '—' }}</span></div><div class="sl-field-line"><span class="sl-field-label">真实归属人：</span><span class="sl-field-value muted">{{ $storeOwner ?: '—' }}</span></div><div class="sl-field-line"><span class="sl-field-label">使用人：</span><span class="sl-field-value muted">{{ $storeUser ?: '—' }}</span></div><div class="sl-field-line"><span class="sl-field-label">所属团队：</span><span class="sl-field-value muted">{{ $storeTeams ?: '—' }}</span></div></div><div class="sl-info-block"><div class="sl-field-line"><span class="sl-field-label">订单号：</span><span class="sl-field-value sl-order-no">{{ $order->order_number ?: $order->external_order_id }}</span></div><div class="sl-field-line"><span class="sl-field-label">ID：</span><span class="sl-field-value muted">{{ $order->external_order_id }}</span></div></div></td>
                <td class="sl-logistics-information"><div class="sl-logistics-body"><div class="sl-status-stack"><div class="sl-status-line"><span class="sl-field-label">订单状态：</span><span class="sl-status-value order">{{ $orderLabels[$order->status] ?? ($order->status ?: '未知') }}</span></div><div class="sl-status-line"><span class="sl-field-label">支付状态：</span><span class="sl-status-value payment">{{ $paymentLabels[$order->payment_status] ?? ($order->payment_status ?: '支付状态未知') }}</span></div><div class="sl-status-line"><span class="sl-field-label">发货状态：</span><span class="sl-status-value fulfillment">{{ ($fulfillmentStatus === '' || $fulfillmentStatus === 'null') ? '未发货' : ($fulfillmentLabels[$fulfillmentStatus] ?? $order->fulfillment_status) }}</span></div></div></div></td>
                <td class="sl-customer-information"><div class="sl-field-line"><span class="sl-field-label">客户：</span><span class="sl-field-value" title="{{ $order->customer_name }}">{{ $order->customer_name ?: '—' }}</span></div><div class="sl-field-line"><span class="sl-field-label">联系方式：</span><span class="sl-field-value muted" title="{{ $order->customer_email ?: $order->customer_phone }}">{{ $order->customer_email ?: $order->customer_phone ?: '—' }}</span></div></td>
                <td><div class="sl-field-line"><span class="sl-field-label">来源：</span><span class="sl-field-value">{{ $marketingSourceLabels[$order->marketing_source] ?? '待识别' }}</span></div></td>
                <td class="sl-products">
                    @forelse($order->items->take(2) as $item)
                        <div class="sl-product">
                            @if($item->image_url)<img src="{{ $item->image_url }}" alt="">@endif
                            <div><div class="sl-product-name" title="{{ $item->product_name }}"><span class="sl-field-label">商品：</span>{{ $item->product_name ?: '未命名商品' }}</div><div class="sl-sku"><span class="sl-field-label">SKU：</span>{{ $item->sku ?: '无 SKU' }} · ×{{ rtrim(rtrim(number_format((float)$item->quantity, 4, '.', ''), '0'), '.') }}</div></div>
                        </div>
                    @empty
                        <span class="text-muted">无商品明细</span>
                    @endforelse
                    @if($order->items->count() > 2)<div class="sl-sub">另有 {{ $order->items->count() - 2 }} 件商品</div>@endif
                </td>
                <td><div class="sl-field-line"><span class="sl-field-label">金额：</span><span class="sl-field-value sl-money">{{ $order->currency ?: '—' }} {{ number_format((float)$order->total_amount, 2) }}</span></div>@if((float)$order->discount_amount > 0)<div class="sl-field-line"><span class="sl-field-label">优惠：</span><span class="sl-field-value muted">{{ number_format((float)$order->discount_amount, 2) }}</span></div>@endif</td>
                <td><button class="btn btn-info btn-xs sl-detail-button js-shopline-order-detail" type="button" data-url="{{ route('fashion-logistics.shopline-orders.show', $order) }}"><i class="fa fa-eye"></i> 详情</button></td>
            </tr>
        @empty
            <tr><td colspan="7" class="sl-empty"><i class="fa fa-inbox fa-2x"></i><br>当前筛选条件下没有 Shopline 订单。配置有效店铺 Token 后，定时任务会自动同步。</td></tr>
        @endforelse
        </tbody></table></div>
        @if($orders->total() > 0)
            <div class="sl-pagination">
                <div class="sl-page-summary">显示 <strong>{{ $orders->firstItem() }}-{{ $orders->lastItem() }}</strong> 条，共 <strong>{{ $orders->total() }}</strong> 条 · 第 {{ $orders->currentPage() }} / {{ $orders->lastPage() }} 页</div>
                @if($orders->hasPages())
                    @php
                        $pages = collect([1, $orders->currentPage() - 2, $orders->currentPage() - 1, $orders->currentPage(), $orders->currentPage() + 1, $orders->currentPage() + 2, $orders->lastPage()])
                            ->filter(fn ($page) => $page >= 1 && $page <= $orders->lastPage())
                            ->unique()->sort()->values();
                        $previous = null;
                    @endphp
                    <nav class="sl-page-list">
                        <a class="sl-page-link {{ $orders->onFirstPage() ? 'is-disabled' : '' }}" href="{{ $orders->previousPageUrl() ?: '#' }}"><i class="fa fa-angle-left"></i></a>
                        @foreach($pages as $page)
                            @if($previous !== null && $page > $previous + 1)<span class="sl-page-gap">…</span>@endif
                            @if($page === $orders->currentPage())<span class="sl-page-active">{{ $page }}</span>@else<a class="sl-page-link" href="{{ $orders->url($page) }}">{{ $page }}</a>@endif
                            @php($previous = $page)
                        @endforeach
                        <a class="sl-page-link {{ $orders->hasMorePages() ? '' : 'is-disabled' }}" href="{{ $orders->nextPageUrl() ?: '#' }}"><i class="fa fa-angle-right"></i></a>
                    </nav>
                @endif
            </div>
        @endif
    </div>
<div class="modal fade sl-modal sl-sync-modal" id="shopline-sync-modal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">×</button>
                <h4 class="modal-title">Shopline 店铺同步情况</h4>
                <p>查看店铺授权条件、同步状态和订单概况；仅加载当前页数据。</p>
            </div>
            <div class="modal-body">
                <form class="sl-sync-filter" id="shopline-sync-filter">
                    <div class="form-group"><label>店铺 / 运营</label><input class="form-control" id="shopline-sync-keyword" placeholder="输入 Handle 或运营人员"></div>
                    <div class="form-group"><label>同步条件</label><select class="form-control" id="shopline-sync-configuration"><option value="">全部</option><option value="ready">可同步</option><option value="missing">缺少配置</option></select></div>
                    <div class="form-group"><label>同步状态</label><select class="form-control" id="shopline-sync-status"><option value="">全部</option><option value="pending">未同步</option><option value="syncing">同步中</option><option value="success">同步成功</option><option value="failed">同步失败</option></select></div>
                    <div class="form-group"><label>每页</label><select class="form-control" id="shopline-sync-per-page"><option value="20">20 条</option><option value="50">50 条</option><option value="100">100 条</option></select></div>
                    <button class="btn btn-primary" id="shopline-sync-submit" type="submit"><i class="fa fa-search"></i> 查询</button>
                </form>
                <div class="sl-sync-summary" id="shopline-sync-summary"></div>
                <div class="table-responsive"><table class="table sl-sync-table"><thead><tr><th>店铺 Handle</th><th>同步条件</th><th>最近同步</th><th>状态</th><th>店铺订单数</th><th>今日订单数</th><th>最近错误</th></tr></thead><tbody id="shopline-sync-rows"><tr><td colspan="7" class="text-center text-muted">正在加载…</td></tr></tbody></table></div>
                <div class="sl-sync-footer"><div class="sl-sync-range" id="shopline-sync-range"></div><nav class="sl-page-list" id="shopline-sync-pagination"></nav></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade sl-modal" id="shopline-order-modal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal">×</button><h4 class="modal-title"><i class="fa fa-file-text-o"></i> Shopline 订单详情</h4></div><div class="modal-body" id="shopline-order-detail"><div class="sl-detail-loading"><i class="fa fa-spinner fa-spin"></i> 正在读取订单详情…</div></div></div></div></div>
</section>
@endsection

@push('scripts')
<script>
document.addEventListener('click', function (event) {
    var button = event.target.closest('.js-shopline-order-detail');
    if (!button) return;
    var panel = document.getElementById('shopline-order-detail');
    panel.innerHTML = '<div class="sl-detail-loading"><i class="fa fa-spinner fa-spin"></i> \u6b63\u5728\u8bfb\u53d6\u8ba2\u5355\u8be6\u60c5...</div>';
    $('#shopline-order-modal').modal('show');
    fetch(button.dataset.url, {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}})
        .then(function (response) { if (!response.ok) throw new Error('\u8bf7\u6c42\u5931\u8d25 (HTTP ' + response.status + ')'); return response.json(); })
        .then(function (data) { renderShoplineOrderDetail(panel, data); })
        .catch(function (error) { panel.innerHTML = '<div class="alert alert-danger" style="margin:0">\u65e0\u6cd5\u8bfb\u53d6\u8ba2\u5355\u8be6\u60c5\uff1a' + shoplineEscape(error.message) + '</div>'; });
});
function shoplineEscape(value) { var element = document.createElement('div'); element.textContent = value == null ? '' : String(value); return element.innerHTML; }
function shoplineValue(value) { return value == null || value === '' ? '-' : shoplineEscape(value); }
function shoplineMarketingSourceLabel(value) { return ({google_ads:'Google Ads',tiktok:'TikTok',meta:'Meta',other_utm:'其他 UTM',external_referral:'外部引荐',direct_or_unknown:'自然 / 未识别'})[value] || '待识别'; }
function renderShoplineOrderDetail(panel, data) {
    var order = data.order || {}, items = data.items || [], payload = order.source_payload || {};
    var cards = [
        ['\u8ba2\u5355\u53f7', order.order_number || order.external_order_id], ['\u5e97\u94fa / Handle', (order.store_name || '-') + ' / ' + (order.source_domain || '-')], ['\u8ba2\u5355\u91d1\u989d', (order.currency || '') + ' ' + (order.total_amount || '0')],
        ['\u8ba2\u5355\u72b6\u6001', order.status], ['\u652f\u4ed8\u72b6\u6001', order.payment_status], ['\u53d1\u8d27\u72b6\u6001', order.fulfillment_status], ['\u8ba2\u5355\u6765\u6e90', shoplineMarketingSourceLabel(order.marketing_source)],
        ['\u5ba2\u6237', order.customer_name], ['\u90ae\u7bb1 / \u7535\u8bdd', [order.customer_email, order.customer_phone].filter(Boolean).join(' / ')], ['\u6536\u8d27\u5730\u5740', order.shipping_address],
        ['\u652f\u4ed8\u65b9\u5f0f', order.payment_method_title || order.payment_method], ['\u4ea4\u6613\u53f7', order.transaction_id], ['\u4e0b\u5355\u65f6\u95f4', order.order_created_at],
        ['\u66f4\u65b0\u65f6\u95f4', order.order_modified_at], ['\u4ed8\u6b3e\u65f6\u95f4', order.paid_at], ['\u540c\u6b65\u65f6\u95f4', order.source_synced_at]
    ];
    var html = '<div class="sl-detail-grid">' + cards.map(function(card) { return '<div class="sl-detail-card"><span>' + card[0] + '</span><strong>' + shoplineValue(card[1]) + '</strong></div>'; }).join('') + '</div>';
    html += '<div class="sl-detail-section"><h4>\u5546\u54c1\u660e\u7ec6 (' + items.length + ')</h4><div class="table-responsive"><table class="table table-bordered sl-item-table"><thead><tr><th>\u5546\u54c1</th><th>SKU</th><th>\u6570\u91cf</th><th>\u5c0f\u8ba1</th><th>\u91d1\u989d</th></tr></thead><tbody>';
    html += items.length ? items.map(function(item) { return '<tr><td>' + shoplineValue(item.product_name) + '</td><td>' + shoplineValue(item.sku) + '</td><td>' + shoplineValue(item.quantity) + '</td><td>' + shoplineValue(item.subtotal) + '</td><td>' + shoplineValue(item.total) + '</td></tr>'; }).join('') : '<tr><td colspan="5" class="text-center text-muted">\u65e0\u5546\u54c1\u660e\u7ec6</td></tr>';
    html += '</tbody></table></div></div><div class="sl-detail-section"><h4>Shopline \u539f\u59cb JSON</h4><pre class="sl-raw" id="shopline-order-raw"></pre></div>';
    panel.innerHTML = html;
    document.getElementById('shopline-order-raw').textContent = JSON.stringify(payload, null, 2);
}
var shoplineSyncButton = document.querySelector('.sl-sync-button[data-url]');
var shoplineSyncEndpoint = shoplineSyncButton ? shoplineSyncButton.dataset.url : '';
var shoplineSyncFilter = document.getElementById('shopline-sync-filter');
var shoplineSyncRows = document.getElementById('shopline-sync-rows');
var shoplineSyncSummary = document.getElementById('shopline-sync-summary');
var shoplineSyncRange = document.getElementById('shopline-sync-range');
var shoplineSyncPagination = document.getElementById('shopline-sync-pagination');
var shoplineSyncSubmit = document.getElementById('shopline-sync-submit');
var shoplineSyncRequest = null;
var shoplineSyncRequestSequence = 0;

function setShoplineSyncSubmitting(isSubmitting) {
    if (!shoplineSyncSubmit) return;
    shoplineSyncSubmit.disabled = isSubmitting;
    shoplineSyncSubmit.innerHTML = isSubmitting
        ? '<i class="fa fa-spinner fa-spin"></i> 正在查询…'
        : '<i class="fa fa-search"></i> 查询';
}

$('#shopline-sync-modal').on('shown.bs.modal', function () { loadShoplineSyncStatus(1); });
shoplineSyncFilter.addEventListener('submit', function (event) { event.preventDefault(); loadShoplineSyncStatus(1); });
shoplineSyncPagination.addEventListener('click', function (event) {
    var button = event.target.closest('[data-shopline-page]');
    if (!button || button.disabled) return;
    loadShoplineSyncStatus(Number(button.dataset.shoplinePage));
});

function loadShoplineSyncStatus(page) {
    if (!shoplineSyncEndpoint) return;
    if (shoplineSyncRequest) return;
    shoplineSyncRows.innerHTML = '<tr><td colspan="7" class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> \u6b63\u5728\u52a0\u8f7d\u5f53\u524d\u9875\u6570\u636e...</td></tr>';
    shoplineSyncPagination.innerHTML = '';
    setShoplineSyncSubmitting(true);
    var params = new URLSearchParams({
        page: String(page || 1),
        keyword: document.getElementById('shopline-sync-keyword').value || '',
        configuration: document.getElementById('shopline-sync-configuration').value || '',
        sync_status: document.getElementById('shopline-sync-status').value || '',
        per_page: document.getElementById('shopline-sync-per-page').value || '20'
    });
    var abortController = window.AbortController ? new AbortController() : null;
    var requestSequence = ++shoplineSyncRequestSequence;
    var releaseRequest = function (timedOut) {
        if (requestSequence !== shoplineSyncRequestSequence) return;
        window.clearTimeout(requestTimer);
        shoplineSyncRequest = null;
        setShoplineSyncSubmitting(false);
        if (timedOut) {
            shoplineSyncRows.innerHTML = '<tr><td colspan="7" class="text-center text-danger">请求超时，请重试</td></tr>';
            shoplineSyncSummary.innerHTML = '';
            shoplineSyncRange.textContent = '';
        }
    };
    var requestTimer = window.setTimeout(function () {
        if (abortController) abortController.abort();
        releaseRequest(true);
    }, 20000);
    shoplineSyncRequest = fetch(shoplineSyncEndpoint + '?' + params.toString(), {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, signal: abortController ? abortController.signal : undefined})
        .then(function (response) { if (!response.ok) throw new Error('HTTP ' + response.status); return response.json(); })
        .then(renderShoplineSyncStatus)
        .catch(function (error) {
            var message = error && error.name === 'AbortError' ? '\u8bf7\u6c42\u8d85\u65f6\uff0c\u8bf7\u91cd\u8bd5' : ('\u65e0\u6cd5\u8bfb\u53d6\u540c\u6b65\u72b6\u6001\uff1a' + shoplineEscape(error.message));
            shoplineSyncRows.innerHTML = '<tr><td colspan="7" class="text-center text-danger">' + message + '</td></tr>';
            shoplineSyncSummary.innerHTML = '';
            shoplineSyncRange.textContent = '';
        })
        .then(function () { releaseRequest(false); });
}

function renderShoplineSyncStatus(data) {
    var summary = data.summary || {}, rows = data.rows || [], pagination = data.pagination || {};
    shoplineSyncSummary.innerHTML = syncMetric('\u5168\u90e8\u5e97\u94fa', summary.total, '') + syncMetric('\u53ef\u540c\u6b65', summary.ready, 'good') + syncMetric('\u7f3a\u5c11\u914d\u7f6e', summary.missing, 'warning') + syncMetric('\u540c\u6b65\u5931\u8d25', summary.failed, 'danger');
    shoplineSyncRows.innerHTML = rows.length ? rows.map(function (row) {
        var condition = row.ready ? '<span class="sl-ready"><i class="fa fa-check-circle"></i> \u53ef\u540c\u6b65</span>' : '<span class="sl-not-ready"><i class="fa fa-exclamation-circle"></i> \u7f3a\u5c11 Handle \u6216 Token</span>';
        var status = syncStatusLabel(row.status);
        var error = row.last_error || '-';
        return '<tr><td><strong>' + shoplineValue(row.handle) + '</strong><div class="sl-sub">' + shoplineValue(row.operator) + '</div></td><td>' + condition + '</td><td>' + shoplineValue(row.last_succeeded_at || '\u5c1a\u672a\u6210\u529f\u540c\u6b65') + '</td><td><span class="sl-sync-status ' + shoplineEscape(row.status || 'pending') + '">' + status + '</span></td><td>' + syncNumber(row.total_count) + '</td><td>' + syncNumber(row.today_count) + '</td><td><div class="sl-sync-error" title="' + shoplineEscape(error) + '">' + shoplineEscape(error) + '</div></td></tr>';
    }).join('') : '<tr><td colspan="7" class="text-center text-muted">\u6682\u65e0\u7b26\u5408\u6761\u4ef6\u7684 Shopline \u5e97\u94fa</td></tr>';
    shoplineSyncRange.textContent = pagination.total ? ('\u663e\u793a ' + pagination.from + '-' + pagination.to + ' \u6761\uff0c\u5171 ' + pagination.total + ' \u6761') : '\u5171 0 \u6761';
    renderShoplineSyncPagination(pagination);
}

function syncMetric(label, value, className) { return '<div class="sl-sync-metric ' + className + '"><span>' + label + '</span><strong>' + syncNumber(value) + '</strong></div>'; }
function syncNumber(value) { return new Intl.NumberFormat().format(Number(value || 0)); }
function syncStatusLabel(status) { return {pending: '\u672a\u540c\u6b65', syncing: '\u540c\u6b65\u4e2d', success: '\u540c\u6b65\u6210\u529f', failed: '\u540c\u6b65\u5931\u8d25'}[status] || '\u672a\u540c\u6b65'; }
function renderShoplineSyncPagination(pagination) {
    var current = Number(pagination.current_page || 1), last = Number(pagination.last_page || 1), pages = [1, current - 2, current - 1, current, current + 1, current + 2, last].filter(function (page, index, values) { return page >= 1 && page <= last && values.indexOf(page) === index; }).sort(function (a, b) { return a - b; });
    if (last <= 1) { shoplineSyncPagination.innerHTML = ''; return; }
    var html = '<button class="sl-page-link" type="button" data-shopline-page="' + (current - 1) + '"' + (current <= 1 ? ' disabled' : '') + '><i class="fa fa-angle-left"></i></button>', previous = null;
    pages.forEach(function (page) {
        if (previous !== null && page > previous + 1) html += '<span class="sl-page-gap">...</span>';
        html += page === current ? '<span class="sl-page-active">' + page + '</span>' : '<button class="sl-page-link" type="button" data-shopline-page="' + page + '">' + page + '</button>';
        previous = page;
    });
    html += '<button class="sl-page-link" type="button" data-shopline-page="' + (current + 1) + '"' + (current >= last ? ' disabled' : '') + '><i class="fa fa-angle-right"></i></button>';
    shoplineSyncPagination.innerHTML = html;
}
</script>
@endpush
