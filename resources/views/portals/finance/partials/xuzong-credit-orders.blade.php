<div class="box-body table-responsive no-padding logistics-orders-scroll" tabindex="0" aria-label="徐总信用卡订单列表">
    <table class="table table-hover logistics-table xuzong-credit-table">
        <thead><tr>
            <th scope="col" class="paypal-order-selection"><input type="checkbox" id="paypal-order-check-all" aria-label="全选当前页"></th>
            @foreach($exportHeaders as $header)<th scope="col">{{ $header }}</th>@endforeach
            <th scope="col">操作</th>
        </tr></thead>
        <tbody>
        @forelse($records as $record)
            @php($values = $xuzongRows->get($record->id))
            <tr>
                <td class="paypal-order-selection"><input type="checkbox" class="paypal-order-check" value="{{ $record->id }}" aria-label="选择记录 {{ $record->id }}"></td>
                @foreach($exportHeaders as $header)<td>{{ $values[$header] ?? '' }}</td>@endforeach
                <td><button type="button" class="btn btn-info btn-xs js-logistics-order-detail" data-order-detail-url="{{ route($recordsRoute, ['order_detail' => $record->id, 'timezone' => $filters['timezone']]) }}" aria-haspopup="dialog"><i class="fa fa-eye" aria-hidden="true"></i> 查看</button></td>
            </tr>
        @empty
            <tr><td colspan="{{ count($exportHeaders) + 2 }}">{{ __('finance.records.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
