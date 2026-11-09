@push('styles')
<style>
.paypal-balances{margin-bottom:26px;color:#17324d}.paypal-balance-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 16px}.paypal-balance-heading h2{font-size:20px;font-weight:600;margin:0}.paypal-balance-heading span{font-size:12px;color:#7e8d9f}.paypal-summary{background:#fff;border:1px solid #dfe7f1;border-radius:12px;overflow:hidden;margin-bottom:16px;box-shadow:0 2px 3px rgba(22,49,76,.04)}.paypal-summary-heading{display:flex;justify-content:space-between;align-items:center;padding:15px 20px 0;font-size:13px}.paypal-currency{font-size:11px;border-radius:12px;padding:3px 9px;background:#e9f1ff;color:#3a6bc1;white-space:nowrap}.paypal-summary-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;padding:16px 20px 20px}.paypal-summary-metric span{display:block;font-size:12px;color:#77899c;margin-bottom:8px}.paypal-summary-metric strong{font-size:26px;font-weight:600;line-height:1.4;letter-spacing:.1px;font-variant-numeric:tabular-nums;color:#12324e}.paypal-summary-metric:last-child strong{color:#2261b8}.paypal-summary-message{font-size:12px;color:#7c8b9e;margin:0;padding:0 20px 17px}.paypal-account-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.paypal-account-card{background:#fff;border:1px solid #dfe7f1;border-radius:10px;overflow:hidden;box-shadow:0 2px 3px rgba(22,49,76,.03)}.paypal-account-header{padding:15px 17px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #edf2f7}.paypal-account-logo{height:30px;width:30px;display:flex;align-items:center;justify-content:center;background:#edf4ff;color:#1f69b4;border-radius:8px;font-size:17px}.paypal-account-header h3{font-size:15px;font-weight:600;margin:0;line-height:1.4}.paypal-account-header .paypal-account-tag{margin-left:auto;border-radius:12px;font-size:10px;padding:3px 7px;background:#fff5e6;color:#a57933;white-space:nowrap}.paypal-account-values{padding:15px 17px 13px}.paypal-account-values+.paypal-account-values{border-top:1px solid #e7edf5}.paypal-account-currency{display:flex;justify-content:space-between;align-items:center;font-size:11px;color:#92a0b1;margin-bottom:12px}.paypal-account-metrics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.paypal-account-metrics span{display:block;color:#798a9e;font-size:12px;margin-bottom:6px}.paypal-account-metrics strong{display:block;font-variant-numeric:tabular-nums;font-size:21px;line-height:1.4;overflow-wrap:anywhere;letter-spacing:.05px;font-weight:600;color:#173b5c}.paypal-account-metrics .paypal-hold-value{color:#9b783a}.paypal-account-metrics .paypal-value-empty{color:#afbbc9;font-weight:400;font-size:20px}.paypal-account-total{display:flex;align-items:center;justify-content:space-between;margin-top:15px;padding-top:11px;border-top:1px solid #edf1f6;font-size:12px;color:#7c8a9c;gap:10px}.paypal-account-total strong{font-variant-numeric:tabular-nums;font-weight:600;color:#365774;font-size:14px}.paypal-account-footer{margin:0;padding:9px 17px;background:#fafcff;border-top:1px solid #edf2f7;color:#96a2b2;font-size:10px;line-height:1.6}.paypal-balance-note{font-size:12px;color:#798b9e;line-height:1.8;margin:12px 2px 0}.paypal-balance-alert{padding:10px 14px;margin-top:12px;background:#fff9ed;border:1px solid #f3e9d4;border-radius:7px;color:#9a7a44;font-size:12px;line-height:1.7}.paypal-balance-note .fa{margin-right:5px}@media(max-width:1100px){.paypal-account-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.paypal-summary-metric strong{font-size:23px}}@media(max-width:600px){.paypal-account-grid{grid-template-columns:1fr}.paypal-summary-metrics{grid-template-columns:1fr;gap:14px}.paypal-summary-metric{display:flex;align-items:center;justify-content:space-between;gap:10px}.paypal-summary-metric span{margin:0}.paypal-balance-heading{align-items:flex-start;flex-direction:column}.paypal-account-metrics strong{font-size:23px}}
</style>
<style>
.paypal-summary-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.paypal-summary-controls form{margin:0}.paypal-summary-controls select{border:1px solid #d8e4f5;cursor:pointer;font-size:12px;padding:5px 26px 5px 10px;border-radius:16px;background:#e9f1ff;color:#3a6bc1}.paypal-rate-edit{background:none;border:0;color:#3a6bc1;font-size:12px;padding:5px 0}.paypal-rate-note{padding:0 20px 15px;margin:0;color:#7c8b9e;font-size:12px;line-height:1.7}.paypal-rate-warning{color:#9a7a44}.paypal-rate-table{width:100%;font-size:13px}.paypal-rate-table th,.paypal-rate-table td{padding:7px 8px}.paypal-rate-table .form-control{border-radius:5px}.paypal-rate-table th:first-child{width:30%}.paypal-rate-form-note{font-size:13px;line-height:1.8;color:#6d8195}.paypal-rate-add{margin:10px 8px}.paypal-summary-heading{gap:12px;flex-wrap:wrap}.paypal-rate-errors{margin-top:10px}@media(max-width:600px){.paypal-summary-controls{width:100%;justify-content:space-between}}
</style>
@endpush

<section class="paypal-balances" id="paypal-balances" aria-labelledby="paypal-balance-title">
    <div class="paypal-balance-heading">
        <h2 id="paypal-balance-title">{{ __('finance.paypal.title') }}</h2>
        @if($canManage)<div class="paypal-balance-actions">
            @include('portals.finance.partials.sync-log-button')
            <span class="text-muted" style="font-size:12px">后台每小时自动更新</span>
            <button type="button" class="btn btn-default btn-sm" id="finance-refresh-all">{{ __('finance.sync.refresh_all') }}</button>
            <button type="button" class="btn btn-primary btn-sm" data-add-payment-account="paypal"><i class="fa fa-plus" aria-hidden="true"></i> {{ __('finance.payment_accounts.add') }}</button>
        </div>@endif
    </div>
    <div class="paypal-account-grid">
    @forelse($paypalBalance['cards'] as $card)
        <article class="paypal-account-card" data-paypal-account="{{ $card['key'] }}" data-account-order="{{ $card['finance_account_id'] }}">
            <div class="paypal-account-header">
                <span class="paypal-account-logo" aria-hidden="true"><i class="fa fa-paypal"></i></span>
                <h3 title="{{ $card['name'] }}">{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($card['name'])) }}</h3>
                <span class="paypal-connection-status" data-connection-status data-state="{{ $card['connection_status'] ?? '' }}" title="{{ $card['connection_status'] ? __('finance.paypal.connection_hints.'.$card['connection_status']) : '' }}" @if(!$card['connection_status']) hidden @endif><span class="paypal-connection-dot" aria-hidden="true"></span><span data-connection-label>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($card['connection_status'] ? __('finance.paypal.connection_states.'.$card['connection_status']) : '')) }}</span></span>
                @if($canManage)
                <form method="post" action="{{ route('finance.payment-accounts.refresh', $card['key']) }}" class="paypal-card-refresh" data-loading="off" data-refresh-account="{{ $card['name'] }}">
                    @csrf
                    <input type="hidden" name="paypal_currency" value="{{ $paypalBalance['summary']['currency'] }}">
                    @if(request('balance_account'))<input type="hidden" name="balance_account" value="{{ request('balance_account') }}">@endif
                    <button type="submit" class="paypal-card-refresh-button" title="{{ __($card['relay_configured'] ? 'finance.sync.refresh' : 'finance.paypal.configure_before_refresh') }}" aria-label="{{ __('finance.paypal.refresh_account', ['account' => $card['name']]) }}" @disabled($card['status'] !== 'enabled' || !$card['relay_configured'])><i class="fa fa-refresh" aria-hidden="true"></i></button>
                </form>
                @endif
            </div>
            @if(isset($card['account_code']))<p class="paypal-account-remark" title="{{ $card['account_code'] }}">{{ __('finance.payment_accounts.code') }} {{ $card['account_code'] }}</p>@endif
            @if($card['remark'] !== '')<p class="paypal-account-remark" title="{{ $card['remark'] }}">{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($card['remark'])) }}</p>@endif
            <div data-card-balance>@include('portals.finance.partials.paypal-card-balance')</div>
            @php($refreshResult = session('balanceRefreshResults.'.$card['finance_account_id']))
            <p class="paypal-card-refresh-message" data-refresh-message data-result="{{ $refreshResult ? ($refreshResult['ok'] ? 'success' : 'error') : '' }}" role="status" aria-live="polite" @if(!$refreshResult) hidden @endif>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($refreshResult ? ($refreshResult['ok'] ? __('finance.sync.refreshed') : $refreshResult['error']) : '')) }}</p>
            <div class="paypal-account-actions">
                @if($canManage)<button type="button" class="paypal-account-action" data-edit-payment-account="{{ $card['key'] }}" aria-label="{{ __('finance.paypal_profile.edit_account', ['account' => $card['name']]) }}"><i class="fa fa-pencil-square-o" aria-hidden="true"></i> {{ __('finance.settings.edit') }}</button>@endif
                <a class="paypal-account-action" href="{{ route('finance.records', ['dataset' => 'paypal-orders', 'account' => $card['activity_account']]) }}" aria-label="{{ __('finance.paypal_profile.account_activity', ['account' => $card['name']]) }}"><i class="fa fa-history" aria-hidden="true"></i> {{ __('finance.paypal_profile.activity') }}</a>
                @if($canManage)<form method="post" action="{{ route('finance.payment-accounts.delete', $card['key']) }}" class="paypal-account-delete" data-loading="off" data-delete-account>@csrf @method('DELETE')<button type="submit" class="paypal-account-action"><i class="fa fa-trash-o" aria-hidden="true"></i> {{ __('finance.payment_accounts.delete') }}</button></form>@endif
            </div>
        </article>
    @empty
        <p class="finance-note">{{ __('finance.paypal.no_accounts') }}</p>
    @endforelse
    </div>
    <p class="paypal-balance-note"><i class="fa fa-info-circle" aria-hidden="true"></i>{{ __('finance.paypal.note') }}</p>
    <div data-paypal-balance-alerts>@include('portals.finance.partials.paypal-balance-alert')</div>
</section>
@if($canManage)
@push('scripts')
<script>
(function () {
    'use strict';
    document.querySelectorAll('[data-delete-account]').forEach(function (form) {
        var pending = false;
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (pending) return;
            pending = true;
            var deleting = false;
            var dialog = document.createElement('dialog');
            dialog.className = 'finance-delete-dialog';
            dialog.setAttribute('aria-labelledby', 'finance-delete-title');
            dialog.setAttribute('aria-describedby', 'finance-delete-description');
            var heading = document.createElement('div');
            heading.className = 'finance-delete-heading';
            heading.innerHTML = '<span class="finance-delete-icon" aria-hidden="true"><i class="fa fa-trash-o"></i></span><div><h3 id="finance-delete-title">确认删除账户？</h3><p>删除后无法恢复，请确认后继续</p></div>';
            var text = document.createElement('p');
            text.id = 'finance-delete-description';
            text.className = 'finance-delete-description';
            text.textContent = '将永久删除此账户，以及该账户在本财务系统内的以下数据：';
            var scope = document.createElement('div');
            scope.className = 'finance-delete-scope';
            scope.innerHTML = '<span>历史交易与结算补充数据</span><span>余额与余额快照</span><span>报表关联数据与同步日志</span>';
            var hint = document.createElement('p');
            hint.className = 'finance-delete-hint';
            hint.textContent = '其他系统的原始数据不受影响。';
            var advice = document.createElement('p');
            advice.className = 'finance-delete-description';
            advice.textContent = '建议先停用账户：停止自动刷新并保留历史数据，之后可在编辑中重新启用。';
            var actions = document.createElement('div');
            actions.className = 'finance-delete-actions';
            var cancel = document.createElement('button');
            cancel.type = 'button'; cancel.className = 'btn btn-default'; cancel.textContent = '取消';
            var proceed = document.createElement('button');
            proceed.type = 'button'; proceed.className = 'btn btn-danger'; proceed.textContent = '确认删除';
            var disable = document.createElement('button');
            disable.type = 'button'; disable.className = 'btn btn-primary'; disable.textContent = '快捷停用';
            actions.append(cancel, disable, proceed);
            dialog.append(heading, advice, text, scope, hint, actions);
            document.body.appendChild(dialog);
            dialog.addEventListener('close', function () { dialog.remove(); if (!deleting) pending = false; });
            cancel.addEventListener('click', function () { dialog.close(); });
            async function submitAction(event) {
                var stopping = event.currentTarget === disable;
                if (deleting) return;
                deleting = true;
                dialog.close();
                pending = true;
                var submit = form.querySelector('button:not([type]), [type="submit"]');
                submit.disabled = true;
                var card = form.closest('[data-paypal-account]');
                var notice = card ? card.querySelector('[data-refresh-message]') : form.querySelector('[data-delete-message]');
                if (!notice) {
                    notice = document.createElement('span');
                    notice.setAttribute('data-delete-message', '');
                    notice.setAttribute('role', 'alert');
                    form.appendChild(notice);
                }
                notice.hidden = true;
                try {
                    var body = new FormData(form);
                    if (stopping) body.delete('_method');
                    var response = await fetch(form.action + (stopping ? '/disable' : ''), {
                        method: 'POST', body: body, credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        signal: AbortSignal.timeout(30000)
                    });
                    var result = await response.json();
                    if (!response.ok || !result.ok) throw new Error(result.message || (stopping ? '停用失败，请重试。' : '删除失败，请重试。'));
                    window.location.assign(result.redirect);
                } catch (error) {
                    notice.dataset.result = 'failed';
                    notice.textContent = error.name === 'TimeoutError' ? '请求超时，请刷新核对账户状态。' : (error.message || '操作失败，请重试。');
                    notice.hidden = false;
                } finally {
                    pending = false;
                    submit.disabled = false;
                }
            }
            proceed.addEventListener('click', submitAction);
            disable.addEventListener('click', submitAction);
            dialog.showModal();
            cancel.focus();
        });
    });
    var button = document.getElementById('finance-refresh-all');
    var forms = Array.from(document.querySelectorAll('[data-refresh-account]'));
    var states = @json(__('finance.paypal.connection_states'));
    var hints = @json(__('finance.paypal.connection_hints'));
    var busy = false;
    forms.forEach(function (form) { form.dataset.refreshAllowed = String(!form.querySelector('button').disabled); });
    function message(form, result, text) {
        var node = form.closest('[data-paypal-account]').querySelector('[data-refresh-message]');
        node.dataset.result = result;
        node.textContent = text;
        node.hidden = false;
    }
    function connectionResult(form, state) {
        var status = form.closest('[data-paypal-account]').querySelector('[data-connection-status]');
        status.dataset.state = state || '';
        status.title = hints[state] || '';
        status.querySelector('[data-connection-label]').textContent = states[state] || '';
        status.hidden = !states[state];
    }
    function failedConnectionState(form) {
        var state = form.closest('[data-paypal-account]').querySelector('[data-connection-status]').dataset.state;
        return state === 'closed' ? 'closed' : 'error';
    }
    function sortCards() {
        var grid = document.querySelector('#paypal-balances .paypal-account-grid');
        var rank = {connected: 0, closed: 1, error: 2};
        function priority(card) {
            var state = card.querySelector('[data-connection-status]').dataset.state;
            return Object.prototype.hasOwnProperty.call(rank, state) ? rank[state] : 3;
        }
        Array.from(grid.querySelectorAll('[data-paypal-account]')).sort(function (a, b) {
            return priority(a) - priority(b) || Number(a.dataset.accountOrder) - Number(b.dataset.accountOrder);
        }).forEach(function (card) { grid.appendChild(card); });
    }
    async function refresh(form) {
        var card = form.closest('[data-paypal-account]');
        form.setAttribute('aria-busy', 'true');
        form.querySelector('.fa-refresh').classList.add('fa-spin');
        message(form, 'loading', @json(__('finance.sync.refreshing')));
        try {
            var response = await fetch(form.action, {
                method: 'POST', headers: {'Accept': 'application/json'}, body: new FormData(form), credentials: 'same-origin'
            });
            var data = await response.json();
            var ok = response.ok && data.ok === true;
            if (typeof data.balance_html === 'string') card.querySelector('[data-card-balance]').innerHTML = data.balance_html;
            if (data.card_balances_html && typeof data.card_balances_html === 'object') {
                document.querySelectorAll('[data-paypal-account]').forEach(function (balanceCard) {
                    var html = data.card_balances_html[balanceCard.dataset.paypalAccount];
                    if (typeof html === 'string') balanceCard.querySelector('[data-card-balance]').innerHTML = html;
                });
            }
            if (typeof data.summary_html === 'string') document.querySelector('[data-paypal-summary]').outerHTML = data.summary_html;
            if (typeof data.selected_currency === 'string') document.querySelectorAll('input[name="paypal_currency"]').forEach(function (input) { input.value = data.selected_currency; });
            if (typeof data.alerts_html === 'string') document.querySelector('[data-paypal-balance-alerts]').innerHTML = data.alerts_html;
            if (typeof data.refresh_allowed === 'boolean') form.dataset.refreshAllowed = String(data.refresh_allowed);
            connectionResult(form, Object.prototype.hasOwnProperty.call(data, 'connection_status') ? data.connection_status : (ok ? 'connected' : failedConnectionState(form)));
            message(form, ok ? 'success' : 'error', ok ? @json(__('finance.sync.refreshed')) : (typeof data.error === 'string' ? data.error : @json(__('finance.sync.errors.transport'))));
            if (ok && data.orders_queued === true) {
                message(form, 'success', '余额已更新，订单已排队，将在后台补齐。');
            } else if (ok && typeof data.orders_queue_error === 'string') {
                message(form, 'error', data.orders_queue_error);
            }
            if (ok) document.dispatchEvent(new CustomEvent('finance:balance-refreshed', {detail: {key: card.dataset.paypalAccount}}));
        } catch (error) {
            connectionResult(form, failedConnectionState(form));
            message(form, 'error', @json(__('finance.sync.errors.transport')));
        } finally {
            form.setAttribute('aria-busy', 'false');
            form.querySelector('.fa-refresh').classList.remove('fa-spin');
        }
    }
    function unlock() {
        forms.forEach(function (form) { form.querySelector('button').disabled = form.dataset.refreshAllowed !== 'true'; });
        button.disabled = !forms.some(function (form) { return form.dataset.refreshAllowed === 'true'; });
    }
    async function run(targets) {
        if (busy || !targets.length) return;
        busy = true;
        button.disabled = true;
        forms.forEach(function (form) { form.querySelector('button').disabled = true; });
        targets.forEach(function (form) { message(form, 'loading', @json(__('finance.sync.refresh_queued'))); });
        try {
            for (var form of targets) await refresh(form);
        } finally {
            busy = false;
            unlock();
            sortCards();
        }
    }
    forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (form.dataset.refreshAllowed === 'true') run([form]);
        });
    });
    button.addEventListener('click', function () {
        run(forms.filter(function (form) { return form.dataset.refreshAllowed === 'true'; }));
    });
    unlock();
    var connectedKey = @json(session('refreshConnectedAccount'));
    if (connectedKey) {
        var connectedForm = forms.find(function (form) { return form.closest('[data-paypal-account]').dataset.paypalAccount === connectedKey; });
        if (connectedForm && connectedForm.dataset.refreshAllowed === 'true') run([connectedForm]);
    }
})();
</script>
@endpush
@endif
