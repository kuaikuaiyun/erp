@php
    $watch = $watchSnapshot ?? false;
    $collapsible = $collapsible ?? true;
@endphp
@if($collapsible)
@once
@push('styles')
<style>
.finance-snapshot-disclosure{margin:0 0 10px;border:1px solid #e2eaf1;border-radius:6px;background:#f8fbfd;color:#31516b}.finance-snapshot-disclosure>summary{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 14px;cursor:pointer;list-style:none;font-size:12px;user-select:none}.finance-snapshot-disclosure>summary::-webkit-details-marker{display:none}.finance-snapshot-disclosure-title{display:flex;align-items:center;gap:7px;font-weight:600}.finance-snapshot-disclosure-title i{color:#3c8dbc}.finance-snapshot-disclosure-action{display:flex;align-items:center;gap:7px;color:#8092a4}.finance-snapshot-disclosure-action .fa{transition:transform .18s ease}.finance-snapshot-disclosure[open] .finance-snapshot-disclosure-action .fa{transform:rotate(180deg)}.finance-snapshot-disclosure-body{padding:4px 14px 10px;border-top:1px solid #e2eaf1;background:#fff}.finance-snapshot-disclosure-body p{margin:7px 0;font-size:12px;line-height:1.55}
</style>
@endpush
@endonce
@endif
@if($collapsible)
<details class="finance-snapshot-disclosure">
    <summary><span class="finance-snapshot-disclosure-title"><i class="fa fa-info-circle" aria-hidden="true"></i>报表生成通知</span><span class="finance-snapshot-disclosure-action">点击展开 <i class="fa fa-chevron-down" aria-hidden="true"></i></span></summary>
    <div class="finance-snapshot-disclosure-body">
@endif
<p role="status" @if($watch) data-finance-snapshot-status="{{ $status['state'] }}" data-finance-snapshot-time="{{ $status['updated_at'] ?? '' }}" data-finance-snapshot-version="{{ $status['version'] ?? '' }}" @endif>
    <span data-finance-snapshot-message>
    @if(isset($period)){{ $period }} · @endif
    @switch($status['state'])
        @case('pending') 报表已登记，等待后台生成。 @break
        @case('updating') 正在更新，当前显示上一版。 @break
        @case('failed') 上次更新失败，后台会重试。 @break
    @endswitch
    </span>
    @if(filled($status['updated_at'] ?? null))
        数据时间 {{ \Illuminate\Support\Carbon::parse($status['updated_at'])->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}（北京时间）
    @endif
</p>
@if($collapsible)
    </div>
</details>
@endif
@if($watch)
    <script src="{{ asset('assets/erp/finance-snapshot-status.js') }}?v=4" defer></script>
@endif
