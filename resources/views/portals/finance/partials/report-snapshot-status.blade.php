<p role="status">
    @if(isset($period)){{ $period }} · @endif
    @switch($status['state'])
        @case('pending') 报表已登记，等待后台生成。 @break
        @case('updating') 正在更新，当前显示上一版。 @break
        @case('failed') 上次更新失败，后台会重试。 @break
    @endswitch
    @if(filled($status['updated_at'] ?? null))
        快照时间 {{ \Illuminate\Support\Carbon::parse($status['updated_at'])->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}（北京时间）
    @endif
</p>
