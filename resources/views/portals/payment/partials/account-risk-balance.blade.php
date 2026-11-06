@php
    $balanceItems = $balance['items'] ?? [];
    $visibleCount = (int) ($balance['visible_count'] ?? count($balanceItems));
@endphp
@if($balance && $balanceItems !== [])
    <div class="payment-account-balance-summary">
        <div class="payment-account-balance-list">
            @foreach($balanceItems as $index => $item)
                <div class="payment-account-balance-row {{ $item['primary'] ? 'is-primary' : '' }}" @if($index >= $visibleCount) data-payment-account-zero-balance hidden @endif>
                    <div class="payment-account-balance-currency"><strong>{{ $item['currency'] }}</strong>@if($item['primary'])<small>主币种</small>@endif</div>
                    <div><span>可用</span><b>{{ $item['available'] }}</b></div>
                    <div><span>暂不可用</span><b>{{ $item['withheld'] }}</b></div>
                    <div><span>总余额</span><b>{{ $item['total'] }}</b></div>
                </div>
            @endforeach
        </div>
        <div class="payment-account-balance-meta"><span><i class="fa fa-clock-o" aria-hidden="true"></i> 上次手动刷新：{{ $balance['refreshed_at'] }}</span>@if($balance['hidden_zero_count'] > 0)<button type="button" data-payment-account-balance-toggle data-hidden-count="{{ $balance['hidden_zero_count'] }}"><i class="fa fa-angle-down" aria-hidden="true"></i> 展开 {{ $balance['hidden_zero_count'] }} 个零余额币种</button>@endif</div>
    </div>
@else
    <p class="payment-account-balance-pending"><i class="fa fa-info-circle" aria-hidden="true"></i> 尚未获取实时余额</p>
@endif
