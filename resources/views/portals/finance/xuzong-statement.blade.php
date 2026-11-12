@extends('portals.finance.layout')

@section('finance_content')
<div class="box">
    <div class="box-header with-border">
        <h3 class="box-title">结算变动明细</h3>
        <a class="btn btn-default btn-sm pull-right" href="{{ route('finance.reports.xuzong-credit', ['reference' => $order->reference]) }}">查看订单</a>
    </div>
    <div class="box-body">
        <p>平台流水号 {{ $order->reference }}　商户账户 {{ $order->account }}　累计应结算 {{ $order->payload['应结算'] ?? '—' }}</p>
        @if(isset($statement['legacy']) || isset($statement['sha256']))
            <p>该订单含历史汇总结算。重新导入原结算文件后可编辑对应的逐笔明细。</p>
        @endif
        @if($canManage && isset($statement['notice']))
            @include('portals.finance.partials.xuzong-statement-handled', ['noticeOrder' => $order])
        @endif
        @php($events = $statement['events'] ?? [])
        @forelse($events as $key => $event)
            <details class="well well-sm">
                <summary>{{ $event['time'] }}　应结算 {{ $event['amounts']['应结算'] }} USD</summary>
                @include('portals.finance.partials.xuzong-statement-event', ['editing' => true])
            </details>
        @empty
            <p>暂无逐笔结算明细。</p>
        @endforelse
        @if($canManage)
            <details class="well well-sm">
                <summary>新增结算明细</summary>
                @include('portals.finance.partials.xuzong-statement-event', ['editing' => false, 'event' => [], 'key' => ''])
            </details>
        @endif
    </div>
</div>
@endsection