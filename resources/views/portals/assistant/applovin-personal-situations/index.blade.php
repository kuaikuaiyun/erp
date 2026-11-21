@extends('portal.layout')

@section('page_title', 'AppLovin个人情况')

@push('styles')
<style>
.aps-card{border:0;border-radius:12px;box-shadow:0 5px 18px rgba(31,67,98,.09);overflow:hidden}.aps-filter-box{overflow:visible}.aps-filter{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}.aps-filter .form-group{margin:0}.aps-filter label{display:block;margin-bottom:5px;font-size:12px;color:#60788c}.aps-kpis{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:12px;margin:16px 0}.aps-kpi{padding:16px 19px;border-radius:10px;background:linear-gradient(135deg,#fff,#eef8fd);border-left:4px solid #3498db;box-shadow:0 3px 12px rgba(31,67,98,.07)}.aps-kpi span{display:block;font-size:12px;color:#72889a}.aps-kpi strong{display:block;margin-top:5px;color:#173f5e;font-size:22px}.aps-table th{background:#e8f3fa!important;color:#275674;white-space:nowrap}.aps-table td,.aps-table th{vertical-align:middle!important}.aps-number{font-weight:700;color:#235d80}.aps-date{color:#2a759e;font-weight:600}.aps-muted{color:#96a5b0}.aps-roi{display:inline-block;min-width:54px;padding:4px 7px;border-radius:12px;text-align:center;font-weight:700;background:#eef7fb;color:#277da8}.aps-roi.is-empty{background:#f2f4f6;color:#9ba7af}.aps-note{margin:13px 15px;color:#72889a;font-size:12px}.aps-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;min-height:55px;margin-top:2px;padding:11px 16px;background:#f7fbfe;border-top:1px solid #d8e8f1}.aps-page-summary{display:flex;align-items:center;color:#547187;font-size:13px}.aps-page-summary strong{color:#1f5f8a}.aps-pagination-actions{display:flex;align-items:center;gap:8px;margin-left:auto;color:#607d92;font-size:12px}.aps-pagination-actions .form-control{width:62px;height:32px;padding:4px 8px;border-color:#cddfeb;border-radius:7px;color:#397493;background:#fff}.aps-pagination nav{margin:0}.aps-pagination .pagination{display:flex;align-items:center;gap:6px;margin:0}.aps-pagination .pagination>li{display:block}.aps-pagination .pagination>li>a,.aps-pagination .pagination>li>span{display:flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:5px 8px;border:1px solid #d2e2ec!important;border-radius:7px!important;background:#fff;color:#367eaa;line-height:1;text-align:center;box-shadow:none;transition:all .16s ease}.aps-pagination .pagination>li>a:hover{border-color:#57a8d3!important;background:#edf7fc;color:#176b97;box-shadow:none;transform:none}.aps-pagination .pagination>.active>span{border-color:#2d94c7!important;background:#2d94c7!important;color:#fff!important;box-shadow:0 3px 8px rgba(45,148,199,.24)}.aps-pagination .pagination>.disabled>span{border-color:#e6edf1!important;background:#f1f6f9!important;color:#b2bec6!important;box-shadow:none}.aps-roi.is-risk{background:#fde7e5;color:#c0392b}.aps-domain{display:block;max-width:210px;white-space:normal;color:#356c8b;line-height:1.5}.aps-performance{font-weight:700;color:#23845c}@media(max-width:800px){.aps-kpis{grid-template-columns:1fr}.aps-pagination{align-items:flex-start;flex-direction:column;gap:10px}.aps-pagination-actions{margin-left:0}.aps-pagination .pagination{gap:4px}.aps-pagination .pagination>li>a,.aps-pagination .pagination>li>span{min-width:32px;height:32px}}
.aps-pagination .pagination{padding:4px;border:1px solid #dbe8f0;border-radius:12px;background:#fff;box-shadow:0 4px 12px rgba(40,91,123,.08)}.aps-pagination .pagination>li>a,.aps-pagination .pagination>li>span{min-width:34px;height:34px;border:0!important;border-radius:8px!important;font-weight:600}.aps-pagination .pagination>li:first-child>a,.aps-pagination .pagination>li:first-child>span,.aps-pagination .pagination>li:last-child>a,.aps-pagination .pagination>li:last-child>span{min-width:36px;background:#f1f8fc;color:#27779f}.aps-pagination .pagination>li>a:hover{background:#e3f4fc!important;color:#1674a3;box-shadow:inset 0 0 0 1px #b9ddeb}.aps-pagination .pagination>.active>span{background:linear-gradient(135deg,#3eacd9,#2381b2)!important;color:#fff!important;box-shadow:0 3px 8px rgba(35,129,178,.32)}.aps-pagination .pagination>.disabled>span{background:transparent!important;color:#c0ccd4!important}.aps-pagination .pagination>li>span[aria-hidden="true"]{background:transparent!important;color:#9aabb7!important}.aps-pagination-actions .aps-page-jump{width:52px;text-align:center;padding-left:4px;padding-right:4px}
.aps-page-tools,.aps-pages{display:flex;align-items:center;gap:7px}.aps-page-size{display:flex;align-items:center;gap:6px;margin:0;color:#6c8292;font-size:12px;font-weight:400}.aps-page-size select{width:70px;height:34px;border:1px solid #d4e1e9;border-radius:7px;background:#fff;padding:4px 7px}.aps-page{display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:34px;padding:0 11px;border:1px solid #d4e1e9;border-radius:7px;background:#fff;color:#397d9f;font-weight:600;box-shadow:0 2px 5px rgba(40,88,116,.05);transition:.15s}.aps-page:hover{border-color:#3994c3;background:#edf7fc;color:#1d719d}.aps-page.active{border-color:#318ac0;background:linear-gradient(135deg,#3b9bc9,#247dab);color:#fff;box-shadow:0 4px 10px rgba(47,137,185,.25)}.aps-page.disabled{pointer-events:none;background:#f1f5f7;color:#a7b5be;box-shadow:none}.aps-gap{color:#90a3af}@media(max-width:800px){.aps-page-tools{width:100%;justify-content:space-between}}
.aps-stage-spend{display:block;margin-top:3px;color:#8095a4;font-size:12px;white-space:nowrap}
.aps-filter .assistant-department-menu{width:520px}.aps-filter .assistant-department-columns{grid-template-columns:190px minmax(0,1fr);height:310px;min-height:310px}.aps-filter .assistant-department-roots{height:310px;overflow-y:auto}.aps-filter .assistant-department-children{height:310px;overflow:hidden}.aps-filter .assistant-department-panel{height:310px;max-height:none;padding:7px;overflow-y:auto}.aps-filter .assistant-department-panel label{width:100%;margin:0;padding:8px 10px;border-radius:4px}.aps-filter .assistant-department-panel label:hover{background:#f1f8fc}@media(max-width:767px){.aps-filter .assistant-department-menu{width:min(520px,90vw)}.aps-filter .assistant-department-columns{grid-template-columns:150px minmax(0,1fr)}}
</style>
@endpush

@section('content')
<section class="content-header">
    <h1>AppLovin个人情况 <small>按个人广告账户统计启用与分阶段 ROI</small></h1>
</section>
<section class="content">
    <div class="box aps-card aps-filter-box">
        <div class="box-body">
            <form method="get" class="aps-filter" id="aps-filter-form">
                @include('portals.assistant.partials.operation-department-multi-filter', ['departments' => $departmentOptions, 'selectedIds' => $filters['department_ids'], 'inputName' => 'department_ids', 'label' => '运营部门'])
                <div class="form-group"><label>人员 / 账户</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] }}" placeholder="搜索人员、账户名称或编号"></div>
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
                    <th>运营部门 / 人员</th><th>AppLovin账户编号</th><th>绑定店铺域名</th><th>店铺业绩($)</th><th>启用日期（首次提报）</th><th>达到 $2,500 时间</th><th>达到 $2,500 业绩($)</th><th>达到 $2,500 ROI</th><th>达到 $5,000 时间</th><th>达到 $5,000 业绩($)</th><th>达到 $5,000 ROI</th><th>超过 $5,000 时间</th><th>超过 $5,000 业绩($)</th><th>超过 $5,000 ROI</th><th>累计广告费($)</th>
                </tr></thead>
                <tbody>
                    @php($previousOperator = null)
                    @forelse ($rows as $row)
                        @php($sameOperator = $previousOperator === $row['operator_user_id'])
                        <tr>
                            <td class="{{ $sameOperator ? 'aps-muted' : '' }}">@if ($sameOperator)〃@else{{ $row['department'] ?: '—' }}<br><b>{{ $row['operator_name'] }}</b>@endif</td>
                            <td class="aps-number">{{ $row['account_number'] ?: '—' }}</td>
                            <td><span class="aps-domain">{{ $row['store_domains'] ?: '未绑定' }}</span></td>
                            <td class="aps-performance">${{ number_format($row['store_performance'], 2) }}</td>
                            <td class="aps-date">{{ $row['date'] }}</td>
                            <td>@if ($row['reach_2500_days'] === null)<span class="aps-muted">未达到</span>@else{{ $row['reach_2500_days'] }} 天<br><span class="aps-date">{{ $row['reach_2500_date'] }}</span>@endif</td>
                            <td>@if ($row['reach_2500_performance'] === null)—@else${{ number_format($row['reach_2500_performance'], 2) }}<span class="aps-stage-spend">广告费 ${{ number_format($row['reach_2500_spend'], 2) }}</span>@endif</td>
                            <td>@if ($row['reach_2500_roi'] === null)<span class="aps-roi is-empty">—</span>@else<span class="aps-roi {{ $row['reach_2500_roi'] > 0 && $row['reach_2500_roi'] < 1.2 ? 'is-risk' : '' }}">{{ number_format($row['reach_2500_roi'], 2) }}</span>@endif</td>
                            <td>@if ($row['reach_5000_days'] === null)<span class="aps-muted">未达到</span>@else{{ $row['reach_5000_days'] }} 天<br><span class="aps-date">{{ $row['reach_5000_date'] }}</span>@endif</td>
                            <td>@if ($row['reach_5000_performance'] === null)—@else${{ number_format($row['reach_5000_performance'], 2) }}<span class="aps-stage-spend">广告费 ${{ number_format($row['reach_5000_spend'], 2) }}</span>@endif</td>
                            <td>@if ($row['roi_2500_to_5000'] === null)<span class="aps-roi is-empty">—</span>@else<span class="aps-roi {{ $row['roi_2500_to_5000'] > 0 && $row['roi_2500_to_5000'] < 1.3 ? 'is-risk' : '' }}">{{ number_format($row['roi_2500_to_5000'], 2) }}</span>@endif</td>
                            <td>@if ($row['over_5000_days'] === null)<span class="aps-muted">未达到</span>@else{{ $row['over_5000_days'] }} 天<br><span class="aps-date">{{ $row['over_5000_date'] }}</span>@endif</td>
                            <td>@if ($row['over_5000_performance'] === null)—@else${{ number_format($row['over_5000_performance'], 2) }}<span class="aps-stage-spend">广告费 ${{ number_format($row['over_5000_spend'], 2) }}</span>@endif</td>
                            <td>@if ($row['roi_over_5000'] === null)<span class="aps-roi is-empty">—</span>@else<span class="aps-roi {{ $row['roi_over_5000'] > 0 && $row['roi_over_5000'] < 1.4 ? 'is-risk' : '' }}">{{ number_format($row['roi_over_5000'], 2) }}</span>@endif</td>
                            <td class="aps-number">${{ number_format($row['total_spend'], 2) }}</td>
                        </tr>
                        @php($previousOperator = $row['operator_user_id'])
                    @empty
                        <tr><td colspan="15" class="text-center text-muted" style="padding:38px">暂无可查看的 AppLovin 账户数据</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="aps-note"><i class="fa fa-info-circle"></i> 启用日期取首次填写 AppLovin 广告账户的提报日期；达到 $2,500 的用时也以该日期起算。ROI 按已审核广告记录中的 AppLovin 营业额与实际广告费计算。跨越 $2,500 / $5,000 的当天，营业额按当日广告费比例拆分到对应阶段。</p>
        @if ($rows->total() > 0)
            @php($pageStart = max(1, $rows->currentPage() - 2))
            @php($pageEnd = min($rows->lastPage(), $rows->currentPage() + 2))
            <div class="aps-pagination">
                <div class="aps-page-summary">显示 <strong>{{ $rows->firstItem() }}-{{ $rows->lastItem() }}</strong> 条，共 <strong>{{ $rows->total() }}</strong> 个账户</div>
                <div class="aps-page-tools">
                    <label class="aps-page-size">每页 <select id="aps-per-page">
                        @foreach ([20, 50, 100] as $size)
                            <option value="{{ $size }}" @selected($rows->perPage() === $size)>{{ $size }}</option>
                        @endforeach
                    </select> 条</label>
                    @if ($rows->lastPage() > 1)
                        <nav class="aps-pages">
                            <a class="aps-page {{ $rows->onFirstPage() ? 'disabled' : '' }}" href="{{ $rows->previousPageUrl() ?: '#' }}"><i class="fa fa-angle-left"></i></a>
                            @if ($pageStart > 1)
                                <a class="aps-page" href="{{ $rows->url(1) }}">1</a>
                                @if ($pageStart > 2)<span class="aps-gap">…</span>@endif
                            @endif
                            @for ($page = $pageStart; $page <= $pageEnd; $page++)
                                <a class="aps-page {{ $page === $rows->currentPage() ? 'active' : '' }}" href="{{ $rows->url($page) }}">{{ $page }}</a>
                            @endfor
                            @if ($pageEnd < $rows->lastPage())
                                @if ($pageEnd < $rows->lastPage() - 1)<span class="aps-gap">…</span>@endif
                                <a class="aps-page" href="{{ $rows->url($rows->lastPage()) }}">{{ $rows->lastPage() }}</a>
                            @endif
                            <a class="aps-page {{ $rows->hasMorePages() ? '' : 'disabled' }}" href="{{ $rows->nextPageUrl() ?: '#' }}"><i class="fa fa-angle-right"></i></a>
                        </nav>
                    @endif
                </div>
            </div>
        @endif
    </div>
</section>
@endsection

@push('scripts')
<script>
(function () {
    var pageSize = document.getElementById('aps-per-page');
    if (!pageSize) return;
    pageSize.addEventListener('change', function () {
        var url = new URL(window.location.href);
        url.searchParams.set('per_page', pageSize.value);
        url.searchParams.set('page', '1');
        window.location.href = url.toString();
    });
})();
</script>
@endpush
