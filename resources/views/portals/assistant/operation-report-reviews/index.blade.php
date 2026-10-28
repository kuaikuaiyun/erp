@extends('portal.layout')
@section('page_title', '运营报表审核')
@push('styles')
<style>
.report-review-page .box{border:0;border-radius:9px;box-shadow:0 4px 16px rgba(35,67,92,.08);overflow:hidden}.review-filter{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}.review-filter .form-group{margin:0}.review-filter label{display:block;font-size:12px;color:#61788d;margin-bottom:5px}.review-filter .form-control{min-width:145px}.review-status{display:inline-block;padding:5px 11px;border-radius:14px;font-size:12px}.review-status.pending{background:#fff0bd;color:#8c6200}.review-status.approved{background:#dcf5e8;color:#17754b}.review-summary{margin-bottom:15px;padding:14px 18px;background:#fff;border-left:4px solid #e7a424;border-radius:8px;box-shadow:0 3px 13px rgba(35,67,92,.07)}.review-table th{background:#eef5fa}.review-table td,.review-table th{vertical-align:middle!important}
</style>
@endpush
@section('content')
<section class="content-header"><h1>运营报表审核 <small>审核运营系统提交的报表数据</small></h1></section>
<section class="content report-review-page">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <div class="review-summary"><i class="fa fa-clock-o text-orange"></i> 当前待审核报表：<strong>{{ $pendingCount }}</strong> 份</div>
    <div class="box"><div class="box-body"><form class="review-filter" method="get">
        <div class="form-group"><label>审核状态</label><select class="form-control" name="status"><option value="">全部</option><option value="pending" @selected($filters['status']==='pending')>待审核</option><option value="approved" @selected($filters['status']==='approved')>已审核</option></select></div>
        <div class="form-group"><label>报表开始日期</label><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}"></div>
        <div class="form-group"><label>报表结束日期</label><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}"></div>
        <div class="form-group"><label>运营人员</label><input class="form-control" name="operator" value="{{ $filters['operator'] }}" placeholder="输入运营姓名"></div>
        <button class="btn btn-primary"><i class="fa fa-search"></i> 查询</button><a class="btn btn-default" href="{{ route('assistant.operation-report-reviews.index') }}">重置</a>
    </form></div></div>
    <div class="box"><div class="box-header with-border"><h3 class="box-title">报表提交记录 <small>共 {{ $reports->total() }} 份</small></h3></div><div class="box-body no-padding table-responsive">
        <table class="table table-hover review-table"><thead><tr><th>报表日期</th><th>运营人员</th><th>实际广告费</th><th>首单 ROI</th><th>首单利润</th><th>整体利润</th><th>状态</th><th>提交时间</th><th>审核时间</th><th>操作</th></tr></thead><tbody>
        @forelse($reports as $report)@php($firstRevenue = collect($report->metric_rows ?? [])->sum('first_total_revenue'))<tr><td>{{ $report->report_date->format('Y-m-d') }}</td><td>{{ $report->operator_name }}</td><td>${{ number_format($report->actual_ad_spend,2) }}</td><td>{{ $report->actual_ad_spend > 0 ? number_format($firstRevenue/$report->actual_ad_spend,2) : '—' }}</td><td>${{ number_format($report->first_profit,2) }}</td><td>${{ number_format($report->overall_profit,2) }}</td><td><span class="review-status {{ $report->status }}">{{ $report->status==='approved'?'已审核':'待审核' }}</span></td><td>{{ optional($report->submitted_at)->format('Y-m-d H:i') }}</td><td>{{ optional($report->reviewed_at)->format('Y-m-d H:i') ?: '—' }}</td><td><a class="btn btn-info btn-xs" href="{{ route('assistant.operation-report-reviews.show',$report) }}"><i class="fa fa-eye"></i> 查看审核</a></td></tr>
        @empty<tr><td colspan="10" class="text-center text-muted" style="padding:35px">暂无报表数据</td></tr>@endforelse
        </tbody></table>
    </div><div class="box-footer">{{ $reports->links() }}</div></div>
</section>
@endsection
