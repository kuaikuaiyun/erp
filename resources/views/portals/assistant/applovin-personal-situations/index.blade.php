@extends('portal.layout')

@section('page_title', 'AppLovin个人情况')

@push('styles')
<style>
.aps-card{border:0;border-radius:12px;box-shadow:0 5px 18px rgba(31,67,98,.09);overflow:hidden}.aps-filter-box{overflow:visible}.aps-filter{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}.aps-filter .form-group{margin:0}.aps-filter label{display:block;margin-bottom:5px;font-size:12px;color:#60788c}.aps-kpis{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:12px;margin:16px 0}.aps-kpi{padding:16px 19px;border-radius:10px;background:linear-gradient(135deg,#fff,#eef8fd);border-left:4px solid #3498db;box-shadow:0 3px 12px rgba(31,67,98,.07)}.aps-kpi span{display:block;font-size:12px;color:#72889a}.aps-kpi strong{display:block;margin-top:5px;color:#173f5e;font-size:22px}.aps-table th{background:#e8f3fa!important;color:#275674;white-space:nowrap}.aps-table td,.aps-table th{vertical-align:middle!important}.aps-number{font-weight:700;color:#235d80}.aps-date{color:#2a759e;font-weight:600}.aps-muted{color:#96a5b0}.aps-roi{display:inline-block;min-width:54px;padding:4px 7px;border-radius:12px;text-align:center;font-weight:700;background:#eef7fb;color:#277da8}.aps-roi.is-empty{background:#f2f4f6;color:#9ba7af}.aps-note{margin:13px 15px;color:#72889a;font-size:12px}.aps-pagination{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:14px 18px;background:linear-gradient(135deg,#fbfdff,#f1f8fc);border-top:1px solid #dfeaf1}.aps-page-summary{font-size:13px;color:#72889a}.aps-page-summary strong{color:#286283}.aps-pagination .pagination{margin:0;display:flex;gap:6px}.aps-pagination .pagination>li>a,.aps-pagination .pagination>li>span{min-width:35px;height:34px;padding:7px 10px;border:1px solid #d5e3eb!important;border-radius:7px!important;background:#fff;color:#397e9f;line-height:18px;text-align:center;box-shadow:0 2px 5px rgba(40,88,116,.05)}.aps-pagination .pagination>.active>span{border-color:#318bc0!important;background:#318bc0!important;color:#fff!important}.aps-pagination .pagination>.disabled>span{background:#f2f5f7!important;color:#aab7c0!important}.aps-roi.is-risk{background:#fde7e5;color:#c0392b}.aps-domain{display:block;max-width:210px;white-space:normal;color:#356c8b;line-height:1.5}.aps-performance{font-weight:700;color:#23845c}@media(max-width:800px){.aps-kpis{grid-template-columns:1fr}.aps-pagination{align-items:flex-start;flex-direction:column}}
</style>
@endpush

@section('content')
<section class="content-header">
    <h1>AppLovin个人情况 <small>按个人广告账户统计启用与分阶段 ROI</small></h1>
</section>
<section class="content">
    <div class="box aps-card aps-filter-box">
        <div class="box-body">
            <form method="get" class="aps-filter">
                @include('portals.assistant.partials.operation-department-multi-filter', ['departments' => $departmentOptions, 'selectedIds' => $filters['department_ids'], 'inputName' => 'department_ids', 'label' => '运营部门'])
                <div class="form-group"><label>人员 / 账户</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] }}" placeholder="搜索人员、账户名称或编号"></div>
                <div class="form-group"><label>每页显示</label><select class="form-control" name="per_page">
                    @foreach ([20, 50, 100] as $size)
                        <option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }} 条</option>
                    @endforeach
                </select></div>
                <button class="btn btn-primary"><i class="fa fa-search"></i> 查询</button>
                <a class="btn btn-default" href="{{ route('operation-dashboard.applovin-personal-situations.index') }}">重置</a>
            </form>
        </div>
    </div>

    <div class="aps-kpis">
        <div class="aps-kpi"><span>账户数量</span><strong>{{ $totals['accounts'] }}</strong></div>
        <div class="aps-kpi"><span>已达到 $2,500 的账户</span><strong>{{ $totals['reached_2500'] }}</strong></div>
        <div class="aps-kpi"><span>累计实际广告费</span><strong>${{ number_format($totals['spend'], 2) }}</strong></div>
    </div>

    <div class="box aps-card">
        <div class="table-responsive">
            <table class="table table-hover table-bordered aps-table">
                <thead><tr>
                    <th>运营部门</th><th>运营人员</th><th>AppLovin账户编号</th><th>账户名称</th><th>绑定店铺域名</th><th>店铺业绩($)</th><th>启用日期（首次提报）</th><th>累计广告费($)</th><th>达到 $2,500 用时</th><th>达到 $2,500 日期</th><th>达到 $2,500 业绩($)</th><th>达到 $2,500 ROI</th><th>达到 $5,000 日期</th><th>达到 $5,000 业绩($)</th><th>超过 $5,000 日期</th><th>超过 $5,000 业绩($)</th><th>超过 $5,000 ROI</th>
                </tr></thead>
                <tbody>
                    @php($previousOperator = null)
                    @forelse ($rows as $row)
                        @php($sameOperator = $previousOperator === $row['operator_user_id'])
                        <tr>
                            <td class="{{ $sameOperator ? 'aps-muted' : '' }}">{{ $sameOperator ? '〃' : ($row['department'] ?: '—') }}</td>
                            <td class="{{ $sameOperator ? 'aps-muted' : '' }}">@if ($sameOperator)〃@else<b>{{ $row['operator_name'] }}</b>@endif</td>
                            <td class="aps-number">{{ $row['account_number'] ?: '—' }}</td>
                            <td>{{ $row['account_name'] ?: '—' }}</td>
                            <td><span class="aps-domain">{{ $row['store_domains'] ?: '未绑定' }}</span></td>
                            <td class="aps-performance">${{ number_format($row['store_performance'], 2) }}</td>
                            <td class="aps-date">{{ $row['date'] }}</td>
                            <td class="aps-number">${{ number_format($row['total_spend'], 2) }}</td>
                            <td>{{ $row['reach_2500_days'] === null ? '未达到' : $row['reach_2500_days'].' 天' }}</td>
                            <td>{{ $row['reach_2500_date'] ?: '—' }}</td>
                            <td>{{ $row['reach_2500_performance'] === null ? '—' : '$'.number_format($row['reach_2500_performance'], 2) }}</td>
                            <td>@if ($row['reach_2500_roi'] === null)<span class="aps-roi is-empty">—</span>@else<span class="aps-roi {{ $row['reach_2500_roi'] > 0 && $row['reach_2500_roi'] < 1.2 ? 'is-risk' : '' }}">{{ number_format($row['reach_2500_roi'], 2) }}</span>@endif</td>
                            <td>{{ $row['reach_5000_date'] ?: '—' }}</td>
                            <td>{{ $row['reach_5000_performance'] === null ? '—' : '$'.number_format($row['reach_5000_performance'], 2) }}</td>
                            <td>{{ $row['over_5000_date'] ?: '—' }}</td>
                            <td>{{ $row['over_5000_performance'] === null ? '—' : '$'.number_format($row['over_5000_performance'], 2) }}</td>
                            <td>@if ($row['roi_over_5000'] === null)<span class="aps-roi is-empty">—</span>@else<span class="aps-roi {{ $row['roi_over_5000'] > 0 && $row['roi_over_5000'] < 1.4 ? 'is-risk' : '' }}">{{ number_format($row['roi_over_5000'], 2) }}</span>@endif</td>
                        </tr>
                        @php($previousOperator = $row['operator_user_id'])
                    @empty
                        <tr><td colspan="17" class="text-center text-muted" style="padding:38px">暂无可查看的 AppLovin 账户数据</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="aps-note"><i class="fa fa-info-circle"></i> 启用日期取首次填写 AppLovin 广告账户的提报日期；达到 $2,500 的用时也以该日期起算。ROI 按已审核广告记录中的 AppLovin 营业额与实际广告费计算。跨越 $2,500 / $5,000 的当天，营业额按当日广告费比例拆分到对应阶段。</p>
        @if ($rows->total() > 0)
            <div class="aps-pagination">
                <div class="aps-page-summary">显示 <strong>{{ $rows->firstItem() }}-{{ $rows->lastItem() }}</strong> 条，共 <strong>{{ $rows->total() }}</strong> 个账户</div>
                @if ($rows->hasPages())
                    {{ $rows->onEachSide(2)->links() }}
                @endif
            </div>
        @endif
    </div>
</section>
@endsection
