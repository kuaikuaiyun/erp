@php
    $accountChoices = $reportAccounts->map(fn ($account) => [
        'value' => 'id:'.$account->id,
        'label' => \App\Portals\Finance\FinanceCatalog::simplified($account->account_name),
    ])->values()->all();
    foreach ($accountChannelOptions as $key => $label) $accountChoices[] = ['value' => 'channel:'.$key, 'label' => $label];
    $selectedAccounts = $filters['payment_accounts'] ?? (isset($filters['account_channel'])
        ? ['channel:'.$filters['account_channel']] : (isset($filters['payment_account_id']) ? ['id:'.$filters['payment_account_id']] : []));
    $selectedLabels = array_column(array_filter($accountChoices, fn ($option) => in_array($option['value'], $selectedAccounts, true)), 'label');
@endphp
<div class="form-group">
    <label>支付账户（可多选）</label>
    <details class="transaction-account-picker" data-account-picker>
        <summary class="form-control" data-account-caption>{{ $selectedLabels ? implode('、', $selectedLabels) : '全部账户' }}</summary>
        <div class="transaction-account-popup">
            <button type="button" class="btn btn-default btn-xs" data-clear-accounts>清除选择（全部账户）</button>
            @foreach($accountChoices as $option)
                <label><input type="checkbox" name="payment_accounts[]" value="{{ $option['value'] }}" data-account-label="{{ $option['label'] }}" @checked(in_array($option['value'], $selectedAccounts, true))> {{ $option['label'] }}</label>
            @endforeach
        </div>
    </details>
</div>
@push('styles')
<style>
.finance-report .box:has([data-account-picker]){overflow:visible}.transaction-account-picker{position:relative}.transaction-account-picker summary{cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:22px}.transaction-account-popup{position:absolute;left:0;top:40px;min-width:100%;max-height:320px;overflow:auto;background:#fff;z-index:30;border:1px solid #dce6ef;border-radius:5px;box-shadow:0 4px 12px #183e5d20;padding:10px}.finance-report .transaction-account-popup label{display:flex;align-items:center;gap:7px;white-space:nowrap;margin:0;padding:7px 4px;cursor:pointer}.transaction-account-popup .btn{margin-bottom:6px}
</style>
@endpush
<script>
(() => {
    const picker = document.querySelector('[data-account-picker]');
    const choices = Array.from(picker.querySelectorAll('input[type="checkbox"]'));
    const caption = picker.querySelector('[data-account-caption]');
    function update() {
        const labels = choices.filter(input => input.checked).map(input => input.dataset.accountLabel);
        caption.textContent = labels.length ? labels.join('、') : '全部账户';
        caption.title = caption.textContent;
    }
    picker.addEventListener('change', update);
    picker.querySelector('[data-clear-accounts]').addEventListener('click', () => { choices.forEach(input => { input.checked = false; }); update(); });
    document.addEventListener('click', event => { if (!picker.contains(event.target)) picker.open = false; });
})();
</script>
