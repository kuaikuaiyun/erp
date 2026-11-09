@php
    $editorGroups = [
        '交易信息' => ['account', 'reference', 'paypal_4', 'paypal_5', 'paypal_0', 'paypal_1', 'paypal_2', 'month', 'paypal_3', 'paypal_10', 'paypal_12', 'paypal_13', 'paypal_14', 'date', 'timezone'],
        '金额与结算' => ['paypal_6', 'paypal_7', 'paypal_8', 'paypal_9', 'converted_usd', 'usd_rate', 'settlement'],
        '费用与退款' => ['fee', 'hold', 'dispute_hold', 'frozen_refund', 'refund_top_up', 'refund', 'refund_fee', 'dispute_refund', 'dispute_fee', 'fee_return', 'dispute_release', 'release', 'logistics', 'purchase'],
        '业务归属' => ['domain', 'owner', 'subscription', 'upsell', 'erp_order', 'note'],
    ];
    $editorSuggestions = [
        'paypal_4' => collect(\App\Portals\Finance\FinancePayPalEventTypes::TYPES)->filter(fn ($type) => $type[1] === '结账付款')->pluck(0)->all(),
        'paypal_5' => ['S', 'D', 'PENDING', 'VOIDED'],
        'paypal_6' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'HKD', 'CNY', 'SGD'],
        'paypal_14' => ['Credit', 'Debit', 'Memo'],
        'owner' => [],
        'domain' => [],
        'upsell' => ['是', '否'],
    ];
    foreach ($editorSuggestions as $suggestionField => $suggestions) {
        $editorSuggestions[$suggestionField] = collect($suggestions)
            ->merge($rows->getCollection()->pluck($suggestionField))
            ->filter(fn ($value) => $value !== null && $value !== '')->unique()->values()->all();
    }
    $remainingFields = array_diff(array_keys($editorFields), array_merge(...array_values($editorGroups)));
    if ($remainingFields) {
        $editorGroups['其他信息'] = $remainingFields;
    }
@endphp
<div class="paypal-editor-body">
    <p class="settlement-note">冻结退款、补差价退款按已核实的美元金额补录。留空使用流水自动计算。同一笔支出只填对应的一列。</p>
@foreach($editorGroups as $title => $fields)
<section class="paypal-editor-section" aria-labelledby="paypal-editor-group-{{ $loop->iteration }}">
    <h4 id="paypal-editor-group-{{ $loop->iteration }}">{{ $title }}</h4>
    <div class="paypal-editor-fields">
    @foreach($fields as $field)
        @if(array_key_exists($field, $editorFields))
        <div @class(['paypal-editor-field', 'paypal-editor-field-wide' => in_array($field, ['reference', 'paypal_10', 'paypal_13', 'date'], true), 'paypal-editor-field-note' => $field === 'note'])>
            <label for="edit-{{ $field }}">{{ $editorFields[$field] }}</label>
            @if($field === 'note')
            <textarea id="edit-note" name="note" class="form-control" maxlength="2000" rows="3" placeholder="填写需要补充说明的信息"></textarea>
            @elseif(in_array($field, ['account', 'paypal_2', 'timezone'], true))
            <select id="edit-{{ $field }}" name="{{ $field }}" class="form-control">
                <option value="">请选择{{ $editorFields[$field] }}</option>
                @if($field === 'account')
                    @foreach($accounts as $account)<option value="{{ $account->account_name }}">{{ $account->account_name }}</option>@endforeach
                @else
                    @include('portals.finance.partials.timezone-options', ['selectedTimezone' => null])
                @endif
            </select>
            @elseif(array_key_exists($field, $editorSuggestions))
            <input type="text" id="edit-{{ $field }}" name="{{ $field }}" class="form-control" maxlength="191" list="paypal-editor-options-{{ $field }}" placeholder="选择或自行填写" autocomplete="off">
            <datalist id="paypal-editor-options-{{ $field }}">@foreach($editorSuggestions[$field] as $suggestion)<option value="{{ $suggestion }}">@endforeach</datalist>
            @else
            <input id="edit-{{ $field }}" name="{{ $field }}" class="form-control" @if(in_array($field, $editorNumericFields, true)) type="number" step="0.00000001" @if(isset($moneyFields[$field]) && $field !== 'usd_rate') data-settlement-cost @endif @elseif($field === 'paypal_0') type="date" @elseif($field === 'paypal_1') type="time" step="1" @elseif($field === 'month') type="month" @else type="text" maxlength="191" @endif @if($field === 'subscription') list="settlement-subscriptions" placeholder="选择或自行填写" @elseif($field === 'account') list="settlement-accounts" @elseif($field === 'date') placeholder="YYYY-MM-DD HH:mm:ss" @elseif($field === 'month') placeholder="YYYY-MM" @elseif($field === 'paypal_0') placeholder="YYYY-MM-DD" @elseif($field === 'paypal_1') placeholder="HH:mm:ss" @endif>
            @endif
        </div>
        @endif
    @endforeach
    </div>
    @if($title === '费用与退款')<p class="paypal-editor-help">费用和返还金额以美元填写；物流费用、采购成本以人民币填写。</p>@endif
</section>
@endforeach
<p class="paypal-editor-help">记录ID由系统生成。交易ID及支付记录须保持唯一；时间点格式为 YYYY-MM-DD HH:mm:ss，年月格式为 YYYY-MM。已锁定月份不可修改。</p>
</div>
