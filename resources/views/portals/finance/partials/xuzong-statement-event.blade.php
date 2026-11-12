@if($canManage)
    <form method="post" action="{{ route('finance.reports.xuzong-credit.statement.update', $order->id) }}">
        @csrf
        <input type="hidden" name="action" value="{{ $editing ? 'update' : 'add' }}">
        @if($editing)<input type="hidden" name="event" value="{{ $key }}">@endif
        <div class="row">
            <label class="col-sm-3">结算时间（UTC +0）
                <input class="form-control" name="time" value="{{ $event['time'] ?? '' }}" placeholder="YYYY-MM-DD HH:mm:ss" required>
            </label>
            @foreach($amountFields as $field)
                <label class="col-sm-3">{{ $field }}（USD）
                    <input class="form-control" name="amounts[{{ $field }}]" value="{{ $event['amounts'][$field] ?? '' }}" inputmode="decimal" @required($field === '应结算')>
                </label>
            @endforeach
        </div>
        <button class="btn btn-primary btn-sm" type="submit">{{ $editing ? '保存修改' : '新增' }}</button>
    </form>
    @if($editing)
        <form method="post" action="{{ route('finance.reports.xuzong-credit.statement.update', $order->id) }}" onsubmit="return confirm('确定删除这条结算明细？')">
            @csrf
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="event" value="{{ $key }}">
            <button class="btn btn-danger btn-sm" type="submit">删除</button>
        </form>
    @endif
@else
    <dl class="dl-horizontal">
        @foreach($amountFields as $field)
            <dt>{{ $field }}</dt><dd>{{ $event['amounts'][$field] ?? '—' }}</dd>
        @endforeach
    </dl>
@endif