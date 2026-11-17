@extends('portal.layout')

@section('page_title', '缺失通途订单核对')

@section('content')
    <link rel="stylesheet" href="{{ asset('assets/erp/logistics.css') }}?v=20261104-1">
    <style>
        .tongtu-check .box { border: 1px solid #dce5ec; border-radius: 8px; }
        .tongtu-check .box-header, .tongtu-check .box-body, .tongtu-check .box-footer { padding: 14px 18px; }
        .tongtu-check .box-title { font-size: 15px; font-weight: 600; }
        .tongtu-check .box-title .fa { color: #367fa9; margin-right: 6px; }
        .tongtu-check .filters, .tongtu-check .result-footer { display: flex; align-items: flex-end; flex-wrap: wrap; gap: 12px; }
        .tongtu-check .date-field { flex: 0 1 180px; min-width: 145px; }
        .tongtu-check .date-field label { display: block; color: #465b6d; }
        .tongtu-check .date-field input { border-radius: 4px; border-color: #c8d6e1; }
        .tongtu-check .domain-field { flex-basis: 240px; }
        .tongtu-check .actions { display: flex; gap: 8px; }
        .tongtu-check .results-table { margin-bottom: 0; }
        .tongtu-check .results-table th { background: #edf3f7; color: #344e63; }
        .tongtu-check .results-table th, .tongtu-check .results-table td { padding: 12px 18px; overflow-wrap: anywhere; }
        .tongtu-check .results-table th:first-child { width: 45%; }
        .tongtu-check .result-footer .logistics-pagination-control { flex-wrap: wrap; }
        .tongtu-check .empty { text-align: center; color: #526a7b; padding: 28px 18px; }
    </style>
    <section class="content-header">
        <h1>缺失通途订单核对</h1>
    </section>
    <section class="content tongtu-check">
        <div class="box">
            <div class="box-header with-border">
                <h2 class="box-title"><i class="fa fa-filter" aria-hidden="true"></i> 筛选条件</h2>
            </div>
            <div class="box-body">
                <form method="GET" action="{{ $checkUrl }}" class="filters">
                    <input type="hidden" name="per_page" value="{{ $orders->perPage() }}">
                    <div class="date-field">
                        <label for="paid-from">付款开始日期</label>
                        <input id="paid-from" class="form-control" type="date" name="paid_from" value="{{ $paidFrom }}">
                    </div>
                    <div class="date-field">
                        <label for="paid-to">付款结束日期</label>
                        <input id="paid-to" class="form-control" type="date" name="paid_to" value="{{ $paidTo }}">
                    </div>
                    <div class="date-field domain-field">
                        <label for="tongtu-store-domain">店铺域名</label>
                        <input id="tongtu-store-domain" class="form-control" type="search" name="store_domain"
                               value="{{ $storeDomain }}" placeholder="输入完整或部分域名" maxlength="255" autocomplete="off">
                    </div>
                    <div class="date-field domain-field">
                        <label for="tongtu-order-number">通途订单号</label>
                        <input id="tongtu-order-number" class="form-control" type="search" name="tongtu_order_number"
                               value="{{ $tongtuOrderNumber }}" placeholder="输入完整或部分单号" maxlength="191" autocomplete="off">
                    </div>
                    <div class="actions">
                        <button class="btn btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> 查询</button>
                        <a class="btn btn-default" href="{{ $checkUrl }}">重置</a>
                    </div>
                </form>
            </div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif
        @if ($syncedDays < $totalDays)
            <div class="alert alert-info" role="status">
                {{ $coverageFrom }} 至 {{ $coverageTo }} 已同步 {{ $syncedDays }} / {{ $totalDays }} 天，列表显示已同步的结果。
            </div>
        @endif
        <div class="box tongtu-result-box">
            <div class="box-header with-border">
                <h2 class="box-title">核对结果</h2>
                @if ($lastSync)
                    <span class="text-muted pull-right">最近同步 {{ $lastSync }}</span>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover results-table">
                    <thead>
                        <tr><th scope="col">通途订单号</th><th scope="col">店铺域名</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($orders as $order)
                            <tr><td>{{ $order->number }}</td><td>{{ $order->domain ?? '未匹配域名' }}</td></tr>
                        @empty
                            <tr><td colspan="2" class="empty">{{ $lastSync ? '已同步的数据中没有符合筛选条件的缺失订单。' : '所选日期暂无已同步数据。' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="box-footer result-footer">
                @include('portals.logistics.partials.pagination', [
                    'paginator' => $orders,
                    'filters' => $paginationFilters,
                    'routeName' => 'logistics.orders.missing-tongtu',
                    'loadingTarget' => '.tongtu-result-box',
                ])
            </div>
        </div>
    </section>
@endsection
