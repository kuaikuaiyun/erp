<?php

namespace App\Portals\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FinanceCreditAccountGroup;
use App\Models\FinancePaymentBalance;
use App\Models\FinancePaymentAccount;
use App\Models\FinanceRecord;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Finance\FinanceCatalog;
use App\Portals\Finance\FinanceConfluxSettlement;
use App\Portals\Finance\FinanceCreditSettlementLayout;
use App\Portals\Finance\FinanceCreditUsdRates;
use App\Portals\Finance\FinanceLogisticsCreditOrders;
use App\Portals\Finance\FinancePaymentBalanceService;
use App\Portals\Finance\FinancePayPalBalanceService;
use App\Portals\Finance\FinancePayPalSettlementOrders;
use App\Portals\Finance\FinanceProductNames;
use App\Portals\Finance\FinanceSettlementEditor;
use App\Portals\Finance\FinanceSettlementService;
use App\Portals\Finance\FinanceWorkbookService;
use App\Portals\Finance\FinanceXuzongCreditMatches;
use App\Portals\Finance\FinanceXuzongCreditReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class FinanceController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $entries,
        private readonly FinanceWorkbookService $workbooks,
        private readonly FinancePaymentBalanceService $paymentBalances,
        private readonly FinancePayPalBalanceService $paypalBalances,
    ) {}

    public function index(Request $request): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }

        $filters = $request->validate([
            'balance_account' => 'nullable|string|max:100', 'paypal_currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'balance_tab' => ['nullable', Rule::in(['paypal', 'credit-card', 'conflux'])],
            'add_credit_merchant' => ['nullable', Rule::in(array_keys(FinanceCreditSettlementLayout::CHANNELS))],
            'card_group' => 'nullable|integer|min:1',
        ]);
        $paypalBalance = $this->paypalBalances->dashboard($filters['paypal_currency'] ?? 'USD');
        $paymentBalance = $this->paymentBalances->dashboard($filters['balance_account'] ?? null);
        $hasPaymentAccount = $request->filled('balance_account');
        $paymentOverview = $hasPaymentAccount ? $this->paymentBalances->dashboard() : $paymentBalance;
        $activeBalanceTab = old('balance_tab', $filters['balance_tab'] ?? ($hasPaymentAccount ? 'credit-card' : 'paypal'));
        if (! in_array($activeBalanceTab, ['paypal', 'credit-card', 'conflux'], true)) {
            $activeBalanceTab = 'paypal';
        }

        $cardGroups = FinanceCreditAccountGroup::query()->orderBy('name')->pluck('name', 'id');

        [$confluxAccounts, $creditAccounts] = FinancePaymentAccount::query()->where('account_type', 'credit_card')->orderBy('id')->get()
            ->partition(fn (FinancePaymentAccount $account) => FinanceCreditSettlementLayout::accountPlatform($account) === 'ye');

        return response()->view('portals.finance.home', [
            ...$context, ...$paypalBalance, 'paypalBalance' => $paypalBalance,
            'paymentBalance' => $paymentBalance, 'paymentOverview' => $paymentOverview, 'activeBalanceTab' => $activeBalanceTab,
            'balanceFormatter' => $this->paymentBalances,
            'creditAccounts' => $creditAccounts, 'confluxAccounts' => $confluxAccounts,
            'paymentAccountEditors' => $context['canManage'] ? $this->paymentAccountEditors($paypalBalance['cards']) : [],
            'newCardPlatform' => $filters['add_credit_merchant'] ?? null,
            'cardGroups' => $cardGroups, 'newCardGroup' => $cardGroups->get($filters['card_group'] ?? 0),
            'paymentAccountActivities' => $this->paymentAccountActivities($paypalBalance['cards']),
            'activityCategories' => $this->workbooks->database()->table('finance_transaction_types')->pluck('category')
                ->map(fn ($category) => $category === null || $category === '' ? __('finance.records.unclassified') : $category)
                ->push(__('finance.records.unclassified'))->unique()->sort()->values(),
        ]);
    }

    public function syncLogs(Request $request): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }

        $filters = $request->validate(['page' => 'sometimes|required|integer|min:1|max:2147483647']);
        $logs = $this->workbooks->database()->table('finance_sync_logs as l')
            ->leftJoin('finance_payment_accounts as a', 'a.id', '=', 'l.payment_account_id')
            ->orderByDesc('l.id')
            ->paginate(10, ['l.operation', 'l.status', 'l.error_code', 'l.page_count', 'l.record_count', 'l.created_at', 'a.account_name'],
                page: (int) ($filters['page'] ?? 1));

        return response()->view('portals.finance.partials.sync-log-page', ['logs' => $logs])
            ->header('Cache-Control', 'private, no-store');
    }

    public function balances(Request $request): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }

        return redirect()->route('finance.home', $request->only('balance_account', 'paypal_currency', 'balance_tab'));
    }

    public function refreshPayPalBalance(Request $request, string $accountKey): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $filters = $request->validate([
            'paypal_currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'balance_account' => 'nullable|string|max:100',
        ]);
        $account = FinancePaymentAccount::query()->findOrFail(substr($accountKey, 8));
        $result = app(\App\Portals\Finance\FinancePayPalSyncService::class)->balances($account, (int) $context['user']->getKey());
        if ($result['ok']) {
            try {
                $progress = app(\App\Portals\Finance\FinanceOrderRefreshProgress::class);
                $task = $progress->enqueue($account, (int) $context['user']->getKey());
                $batch = $progress->attachBatch((int) $context['user']->getKey(), [$task], [], [FinanceCatalog::simplified($account->account_name)]);
                $result['orders_batch'] = $batch['batch'];
                $result['orders_queued'] = true;
            } catch (\Throwable $e) {
                report($e);
                $result['orders_queued'] = false;
                $result['orders_queue_error'] = '余额已更新，订单后台任务暂未排入，请稍后重试。';
            }
        }
        if ($request->expectsJson()) {
            $paypalBalance = $this->paypalBalances->dashboard($filters['paypal_currency'] ?? 'USD');
            $card = $paypalBalance['cards']->get('finance-'.$account->id);
            $viewData = [
                'paypalBalance' => $paypalBalance, 'card' => $card, 'balanceFormatter' => $this->paymentBalances,
                'canManage' => $context['canManage'], 'activeBalanceTab' => 'paypal',
            ];

            return response()->json([
                ...$result,
                'connection_status' => $card['connection_status'] ?? null,
                'refresh_allowed' => $card && $card['status'] === 'enabled' && $card['relay_configured'],
                'balance_html' => $card ? view('portals.finance.partials.paypal-card-balance', $viewData)->render() : null,
                'selected_currency' => $paypalBalance['summary']['currency'],
                'card_balances_html' => $paypalBalance['summary']['currency'] !== ($filters['paypal_currency'] ?? 'USD')
                    ? $paypalBalance['cards']->mapWithKeys(fn ($balanceCard) => [$balanceCard['key'] => view('portals.finance.partials.paypal-card-balance', [...$viewData, 'card' => $balanceCard])->render()])->all()
                    : null,
                'summary_html' => view('portals.finance.partials.paypal-summary', $viewData)->render(),
                'alerts_html' => view('portals.finance.partials.paypal-balance-alert', $viewData)->render(),
            ], $result['ok'] ? 200 : 422);
        }

        return redirect()->route('finance.home', $request->only('paypal_currency', 'balance_account'))->with('balanceRefreshResults', [$account->id => $result]);
    }

    private function refreshablePayPalAccounts(): \Illuminate\Database\Eloquent\Builder
    {
        return FinancePaymentAccount::query()->where('account_type', 'paypal')->where('status', 'enabled')
            ->whereNotNull('relay_url')->where('relay_url', '!=', '')
            ->whereNotNull('relay_certificate_sha1')->where('relay_certificate_sha1', '!=', '')
            ->whereNotNull('relay_hmac_key_id')->where('relay_hmac_key_id', '!=', '')
            ->whereNotNull('relay_hmac_secret')->where('relay_hmac_secret', '!=', '');
    }

    public function queuePayPalOrderRefresh(Request $request): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) return $context;
        $data = $request->validate([
            'account' => ['nullable', 'string', 'regex:/^(all|[1-9][0-9]*)$/'],
            'accounts' => 'nullable|array|min:1|max:100', 'accounts.*' => 'required|integer|min:1|distinct',
        ]);
        $ids = $data['accounts'] ?? (isset($data['account']) && $data['account'] !== 'all' ? [(int) $data['account']] : []);
        $all = ($data['account'] ?? '') === 'all';
        if (! $all && ! $ids) throw ValidationException::withMessages(['accounts' => '请至少选择一个 PayPal 账户。']);
        $query = $this->refreshablePayPalAccounts();
        if (! $all) $query->whereIn('id', $ids);
        $accounts = $query->orderBy('id')->get(['id', 'account_name']);
        if ($accounts->isEmpty() || (! $all && $accounts->count() !== count($ids))) {
            throw ValidationException::withMessages(['account' => '没有可刷新的账户，请选择已启用且已配置接口的 PayPal 账户。']);
        }
        $queued = [];
        $taskIds = [];
        $failed = [];
        foreach ($accounts as $account) {
            try {
                $taskIds[] = app(\App\Portals\Finance\FinanceOrderRefreshProgress::class)->enqueue($account, (int) $context['user']->getKey());
                $queued[] = FinanceCatalog::simplified($account->account_name);
            } catch (\Throwable $e) {
                report($e);
                $failed[] = FinanceCatalog::simplified($account->account_name);
            }
        }
        $state = app(\App\Portals\Finance\FinanceOrderRefreshProgress::class)->attachBatch((int) $context['user']->getKey(), $taskIds, $failed, $queued);
        $batch = $state['batch'];
        $taskIds = $state['tasks'];
        return response()->json(['batch' => $batch, 'results' => array_values(array_filter(array_map(fn ($id) => app(\App\Portals\Finance\FinanceOrderRefreshProgress::class)->get($id), $taskIds))), 'ok' => $failed === [], 'queued' => $queued, 'failed' => $failed,
            'message' => ($queued ? '已提交订单刷新：'.implode('、', $queued).'。后台按顺序补齐，完成后点击查询查看最新数据。' : '')
                .($failed ? '以下账户暂未提交，请稍后重试：'.implode('、', $failed).'。' : '')], $failed === [] ? 202 : 503);
    }

    public function latestPayPalOrderRefresh(Request $request): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) return $context;
        $batch = \Illuminate\Support\Facades\Cache::get('finance:orders:latest:'.$context['user']->getKey());
        if (! $batch || ! \Illuminate\Support\Facades\Cache::has('finance:orders:batch:'.$batch)) {
            return response()->json(['batch' => null, 'results' => [], 'done' => true])->header('Cache-Control', 'no-store');
        }
        return $this->payPalOrderRefreshStatus($request, $batch);
    }

    public function payPalOrderRefreshStatus(Request $request, string $batch): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) return $context;
        $state = \Illuminate\Support\Facades\Cache::get('finance:orders:batch:'.$batch);
        abort_unless($state && $state['actor'] === (int) $context['user']->getKey(), 404);
        $results = array_map(fn ($id) => app(\App\Portals\Finance\FinanceOrderRefreshProgress::class)->get($id)
            ?? ['account' => '账户状态已过期', 'status' => 'failed', 'count' => 0, 'error' => '刷新记录已过期，请重新提交。'], $state['tasks']);
        foreach ($state['failed'] ?? [] as $name) {
            $results[] = ['account' => $name, 'status' => 'failed', 'count' => 0, 'error' => '后台任务提交失败，请稍后重试。'];
        }
        return response()->json(['batch' => $batch, 'results' => $results, 'done' => !collect($results)->contains(fn ($row) => in_array($row['status'], ['queued', 'running'], true))])
            ->header('Cache-Control', 'no-store');
    }

    public function syncPayPalTransactions(Request $request, string $accountKey): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $account = FinancePaymentAccount::query()->findOrFail(substr($accountKey, 8));
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'timezone' => ['required', Rule::in(FinanceWorkbookService::TIMEZONES)],
        ]);
        if ($data['to'] > now($data['timezone'])->format('Y-m-d')) {
            throw ValidationException::withMessages(['to' => __('finance.sync.errors.range')]);
        }
        $from = CarbonImmutable::parse($data['from'], $data['timezone'])->startOfDay()->utc();
        $to = CarbonImmutable::parse($data['to'], $data['timezone'])->endOfDay()->utc()->min(CarbonImmutable::now('UTC'))->startOfSecond();
        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => __('finance.sync.errors.range')]);
        }
        $result = app(\App\Portals\Finance\FinancePayPalSyncService::class)->transactions($account, $from, $to, (int) $context['user']->getKey());
        $activity = $this->accountActivityStats($account->activity_account);
        if ($result['ok'] && $request->boolean('export_after_sync')) {
            $request->merge(['export_scope' => 'filtered', 'export_format' => 'xlsx', 'account' => $account->activity_account, 'transaction_scope' => 'balance_affecting']);

            $download = $this->exportRecords($request, 'paypal-orders');
            $download->headers->set('X-Finance-Record-Count', (string) $activity['record_count']);
            $download->headers->set('X-Finance-Last-Updated', $activity['last_updated_at'] ?? '');

            return $download;
        }
        if ($request->expectsJson()) {
            return response()->json([...$result, ...$activity], $result['ok'] ? 200 : 422);
        }

        return redirect()->route('finance.records', ['dataset' => 'paypal-orders', 'account' => $account->activity_account, ...$data])->with('syncResults', [$result]);
    }

    public function rawPayPalTransaction(Request $request, int $transaction): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }
        $row = $this->workbooks->database()->table('finance_payment_transactions')->find($transaction);
        abort_unless($row, 404);

        return response()->json(json_decode($row->raw_payload, true, 512, JSON_THROW_ON_ERROR))->header('Cache-Control', 'private, no-store');
    }

    public function disablePaymentAccount(Request $request, string $accountKey): Response|JsonResponse
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $this->workbooks->database()->transaction(function () use ($accountKey, $context): void {
            $account = FinancePaymentAccount::query()->lockForUpdate()->findOrFail(substr($accountKey, 8));
            $account->update(['status' => 'disabled', 'updated_by' => (int) $context['user']->getKey()]);
        });
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'redirect' => route('finance.home')]);
        }

        return redirect()->route('finance.home')->with('success', '账户已停用，历史数据已保留。');
    }

    public function deletePaymentAccount(Request $request, string $accountKey): Response|JsonResponse
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $account = FinancePaymentAccount::query()->findOrFail(substr($accountKey, 8));
        $db = $this->workbooks->database();
        $db->transaction(function () use ($account, $db): void {
            $account = FinancePaymentAccount::query()->lockForUpdate()->findOrFail($account->id);
            // Only finance-owned tables are cleared; logistics and payment sources are untouched.
            $records = $db->table('finance_records')->where(function ($query) use ($account, $db): void {
                $query->whereIn('id', $db->table('finance_payment_transactions')
                    ->where('payment_account_id', $account->id)->select('finance_record_id'))
                    ->orWhere(function ($query) use ($account): void {
                        $query->where('dataset', $account->account_type === 'paypal' ? 'paypal-orders' : 'xuzong2-details')
                            ->where('account', $account->activity_account);
                    });
            });
            $db->table('finance_settlement_supplements')->whereIn('finance_record_id', (clone $records)->select('id'))->delete();
            $records->delete();
            $db->table('finance_payment_transactions')->where('payment_account_id', $account->id)->delete();
            $db->table('finance_balance_snapshots')->where('payment_account_id', $account->id)->delete();
            $db->table('finance_sync_pages')->whereIn('sync_log_id', $db->table('finance_sync_logs')
                ->where('payment_account_id', $account->id)->select('id'))->delete();
            $db->table('finance_sync_logs')->where('payment_account_id', $account->id)->delete();
            $db->table('finance_payment_balances')->where('channel', $account->account_type === 'paypal' ? 'paypal' : 'xuzong2')
                ->where('account', $account->activity_account)->delete();
            foreach ($db->table('finance_period_locks')->whereNotNull('report_snapshot')->orderBy('month')->lockForUpdate()->get() as $period) {
                $rows = json_decode($period->report_snapshot, true, 512, JSON_THROW_ON_ERROR);
                $remaining = array_values(array_filter($rows, fn ($row) => (int) $row['account_id'] !== (int) $account->id));
                if (count($rows) !== count($remaining)) {
                    $db->table('finance_period_locks')->where('month', $period->month)->update([
                        'report_snapshot' => json_encode($remaining, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now(),
                    ]);
                }
            }
            $account->delete();
        });

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'redirect' => route('finance.home')]);
        }

        return redirect()->route('finance.home')->with('success', __('finance.payment_accounts.deleted'));
    }

    public function storePaymentAccount(Request $request): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $data = $this->validatedPaymentAccount($request);
        $displayOptions = array_intersect_key($data, array_flip(['paypal_currency', 'balance_account']));
        $fromSettlement = ! empty($data['from_credit_settlement']);
        unset($data['paypal_currency'], $data['balance_account'], $data['balance_tab'], $data['from_credit_settlement']);
        $account = $this->workbooks->database()->transaction(fn () => FinancePaymentAccount::query()->create([
            ...$this->accountGroupAttributes($data), 'account_code' => 'FA-'.Str::ulid(), 'activity_account' => $data['account_name'],
            'created_by' => (int) $context['user']->getKey(), 'updated_by' => (int) $context['user']->getKey(),
        ]));
        if ($fromSettlement && $account->account_type === 'credit_card') {
            return redirect()->route('finance.report', ['report' => array_search($account->card_platform, FinanceCreditSettlementLayout::REPORT_CHANNELS, true) ?: 'credit-settlement',
                'card_channel' => $account->card_platform, 'card_group' => $account->card_group_id, 'account' => $account->activity_account])
                ->with('success', __('finance.payment_accounts.created'));
        }

        return redirect()->route('finance.home', [
            ...$displayOptions, 'balance_tab' => match (true) {
                $data['account_type'] === 'paypal' => 'paypal',
                ($data['card_platform'] ?? null) === 'ye' => 'conflux',
                default => 'credit-card',
            },
        ])->with('success', __('finance.payment_accounts.created'))
            ->with('refreshConnectedAccount', $account->account_type === 'paypal' && $account->status === 'enabled' ? 'finance-'.$account->id : null);
    }

    public function updatePaymentAccount(Request $request, string $accountKey): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless(preg_match('/^finance-([1-9]\d*)$/', $accountKey, $matches), 404);
        $account = FinancePaymentAccount::query()->findOrFail($matches[1]);
        $data = $this->validatedPaymentAccount($request, $account);
        $displayOptions = array_intersect_key($data, array_flip(['paypal_currency', 'balance_account']));
        unset($data['paypal_currency'], $data['balance_account'], $data['balance_tab'], $data['from_credit_settlement']);
        $db = $this->workbooks->database();
        $db->transaction(function () use ($account, $data, $context, $db): void {
            $account = FinancePaymentAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($data['account_type'] !== $account->account_type
                && ($db->table('finance_balance_snapshots')->where('payment_account_id', $account->id)->exists()
                    || $db->table('finance_payment_transactions')->where('payment_account_id', $account->id)->exists())) {
                throw ValidationException::withMessages(['account_type' => __('finance.payment_accounts.linked_type_locked')])->errorBag('paymentAccount');
            }
            $platformChanged = array_key_exists('card_platform', $data) && $data['card_platform'] !== $account->card_platform;
            $merchantChanged = array_key_exists('card_merchant_key', $data)
                && strcasecmp((string) $data['card_merchant_key'], (string) $account->card_merchant_key) !== 0;
            if (($platformChanged || $merchantChanged)
                && $db->table(FinanceConfluxSettlement::TABLE)->where('payment_account_id', $account->id)->exists()) {
                throw ValidationException::withMessages(['card_merchant_key' => '该商户已有结算流水，不能更换支付平台或商户标识。'])->errorBag('paymentAccount');
            }
            $account->update([...$this->accountGroupAttributes($data), 'updated_by' => (int) $context['user']->getKey()]);
        });

        return redirect()->route('finance.home', [
            ...$displayOptions, 'balance_tab' => match (true) {
                $data['account_type'] === 'paypal' => 'paypal',
                ($data['card_platform'] ?? null) === 'ye' => 'conflux',
                default => 'credit-card',
            },
        ])->with('success', __('finance.paypal_profile.saved'))
            ->with('refreshConnectedAccount', $data['account_type'] === 'paypal' && $data['status'] === 'enabled' ? $accountKey : null);
    }

    private function validatedPaymentAccount(Request $request, ?FinancePaymentAccount $account = null): array
    {
        foreach (['account_name', 'main_currency', 'relay_certificate_sha1'] as $field) {
            if (is_string($request->input($field))) {
                $value = trim($request->input($field));
                $request->merge([$field => $field === 'account_name' ? $value : strtoupper($value)]);
            }
        }
        if (is_string($request->input('relay_certificate_sha1'))) {
            $request->merge(['relay_certificate_sha1' => str_replace([':', ' '], '', $request->input('relay_certificate_sha1'))]);
        }
        $db = $this->workbooks->database();
        $isPayPal = $request->input('account_type') === 'paypal';
        $relayRule = [Rule::excludeIf(! $isPayPal), 'bail', 'required', 'string'];
        $data = $request->validateWithBag('paymentAccount', [
            'account_name' => ['required', 'string', 'max:100', Rule::unique($db->getName().'.finance_payment_accounts', 'account_name')->where('account_type', $request->input('account_type'))->whereNull('deleted_at')->ignore($account?->id)],
            'account_type' => ['required', Rule::in(['paypal', 'credit_card'])],
            'card_platform' => [Rule::excludeIf($isPayPal), 'nullable', Rule::in(array_keys(FinanceCreditSettlementLayout::CHANNELS))],
            'card_group_name' => [Rule::excludeIf($isPayPal), 'nullable', 'string', 'max:100'],
            'card_merchant_key' => [Rule::excludeIf($isPayPal || $request->input('card_platform') !== 'ye'),
                Rule::requiredIf($request->boolean('from_credit_settlement') && $request->input('card_platform') === 'ye'), 'nullable', 'string',
                'max:100', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique($db->getName().'.finance_payment_accounts', 'card_merchant_key')
                    ->where('card_platform', 'ye')->whereNull('deleted_at')->ignore($account?->id)],
            'main_currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'status' => ['required', Rule::in(['enabled', 'disabled'])],
            'remark' => 'nullable|string|max:1000',
            'relay_url' => [...$relayRule, 'max:2048', 'url:https', function ($attribute, $value, $fail): void {
                $parts = parse_url($value);
                if (! is_array($parts) || ($parts['path'] ?? '') !== '/relay/v1/paypal/read' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                    $fail(__('finance.payment_accounts.invalid_relay_url'));
                }
            }],
            'relay_certificate_sha1' => [...$relayRule, 'regex:/^[A-F0-9]{40}$/'],
            'relay_hmac_key_id' => [...$relayRule, 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
            'relay_hmac_secret' => [Rule::excludeIf(! $isPayPal), $account?->getRawOriginal('relay_hmac_secret') ? 'nullable' : 'required', 'string', 'max:2048', function ($attribute, $value, $fail): void {
                $decoded = is_string($value) ? base64_decode($value, true) : false;
                if ($decoded === false || strlen($decoded) < 32 || base64_encode($decoded) !== $value) {
                    $fail(__('finance.payment_accounts.secret_base64'));
                }
            }],
            'relay_server_remark' => [Rule::excludeIf(! $isPayPal), 'nullable', 'string', 'max:255'],
            'balance_tab' => ['nullable', Rule::in(['paypal', 'credit-card', 'conflux'])],
            'paypal_currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'balance_account' => 'nullable|string|max:100',
            'from_credit_settlement' => 'nullable|boolean',
        ], [], trans('finance.payment_accounts.attributes'));
        $cards = $this->paypalBalances->dashboard()['cards'];
        $currentKey = $account ? 'finance-'.$account->id : null;
        if ($isPayPal && $cards->contains(fn ($card) => $card['key'] !== $currentKey && (mb_strtolower($card['name']) === mb_strtolower($data['account_name']) || mb_strtolower($card['activity_account']) === mb_strtolower($data['account_name'])))) {
            throw ValidationException::withMessages(['account_name' => __('finance.paypal_profile.duplicate_name')])->errorBag('paymentAccount');
        }
        if (! $isPayPal) {
            foreach (['relay_url', 'relay_certificate_sha1', 'relay_hmac_key_id', 'relay_hmac_secret', 'relay_server_remark'] as $field) {
                $data[$field] = null;
            }
        } else {
            if ($account?->card_platform !== null) {
                $data['card_platform'] = null;
            }
            if (! isset($data['relay_hmac_secret']) || $data['relay_hmac_secret'] === '') {
                unset($data['relay_hmac_secret']);
            }
        }
        if (($data['card_platform'] ?? null) !== 'ye' && $account?->card_merchant_key !== null
            && (array_key_exists('card_platform', $data) || $isPayPal)) {
            $data['card_merchant_key'] = null;
        }

        return $data;
    }

    private function accountGroupAttributes(array $data): array
    {
        if ($data['account_type'] === 'paypal') {
            $data['card_group_id'] = null;
        } elseif (array_key_exists('card_group_name', $data)) {
            $name = trim((string) $data['card_group_name']);
            $data['card_group_id'] = $name === '' ? null : FinanceCreditAccountGroup::query()->firstOrCreate(['name' => $name])->id;
            unset($data['card_group_name']);
        }

        return $data;
    }

    private function paymentAccountEditors(\Illuminate\Support\Collection $cards): array
    {
        $accounts = FinancePaymentAccount::query()->with('creditGroup')->get()->keyBy('id');
        $editors = [];
        foreach ($cards as $card) {
            $account = isset($card['finance_account_id']) ? $accounts->get($card['finance_account_id']) : null;
            $editors[$card['key']] = $this->accountEditorValues($card['key'], $account, $card);
        }
        foreach ($accounts->where('account_type', 'credit_card') as $account) {
            $key = 'finance-'.$account->id;
            $editors[$key] = $this->accountEditorValues($key, $account);
        }

        return $editors;
    }

    private function paymentAccountActivities(\Illuminate\Support\Collection $cards): array
    {
        $stats = FinanceRecord::query()->where('dataset', 'paypal-orders')->whereIn('account', $cards->pluck('activity_account'))
            ->select('account')->selectRaw('COUNT(*) AS record_count, MAX(updated_at) AS last_updated_at')->groupBy('account')->get()->keyBy('account');

        return $cards->mapWithKeys(fn ($card) => [$card['key'] => [
            'name' => $card['name'], 'account' => $card['activity_account'],
            'sync_url' => route('finance.payment-accounts.sync', $card['key']),
            'sync_allowed' => $card['status'] === 'enabled' && $card['relay_configured'],
            'record_count' => (int) ($stats->get($card['activity_account'])?->record_count ?? 0),
            'last_updated_at' => $stats->get($card['activity_account'])?->last_updated_at,
        ]])->all();
    }

    private function accountActivityStats(string $account): array
    {
        $stats = FinanceRecord::query()->where('dataset', 'paypal-orders')->where('account', $account)
            ->selectRaw('COUNT(*) AS record_count, MAX(updated_at) AS last_updated_at')->first();

        return ['record_count' => (int) $stats->record_count, 'last_updated_at' => $stats->last_updated_at];
    }

    private function accountEditorValues(string $key, ?FinancePaymentAccount $account, ?array $card = null): array
    {
        return [
            'url' => route('finance.payment-accounts.update', $key),
            'account_name' => $account?->account_name ?? $card['name'],
            'account_type' => $account?->account_type ?? 'paypal',
            'card_platform' => $account?->card_platform,
            'card_group_name' => $account?->creditGroup?->name,
            'card_merchant_key' => $account?->card_merchant_key,
            'main_currency' => $account?->main_currency ?? $card['main_currency'],
            'status' => $account?->status ?? $card['status'], 'remark' => $account?->remark ?? ($card['remark'] ?? ''),
            'relay_url' => $account?->relay_url ?? '', 'relay_certificate_sha1' => $account?->relay_certificate_sha1 ?? '',
            'relay_hmac_key_id' => $account?->relay_hmac_key_id ?? '', 'relay_server_remark' => $account?->relay_server_remark ?? '',
            'has_secret' => $account && $account->getRawOriginal('relay_hmac_secret') !== null,
            'relay_configured' => $account && $account->account_type === 'paypal'
                && filled($account->relay_url) && filled($account->relay_certificate_sha1)
                && filled($account->relay_hmac_key_id) && filled($account->getRawOriginal('relay_hmac_secret')),
            'type_locked' => $account && ($this->workbooks->database()->table('finance_balance_snapshots')->where('payment_account_id', $account->id)->exists()
                    || $this->workbooks->database()->table('finance_payment_transactions')->where('payment_account_id', $account->id)->exists()),
        ];
    }

    public function savePayPalAccount(Request $request, string $accountKey): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $cards = $this->paypalBalances->dashboard()['cards'];
        abort_unless($cards->has($accountKey), 404);
        $financeAccountId = $cards[$accountKey]['finance_account_id'] ?? null;
        $db = $this->workbooks->database();
        $data = $request->validateWithBag('paypalAccount', [
            'profile_key' => ['required', Rule::in([$accountKey])],
            'display_name' => ['required', 'string', 'max:100', Rule::unique($db->getName().'.finance_payment_accounts', 'account_name')->whereNull('deleted_at')->ignore($financeAccountId)],
            'remark' => 'nullable|string|max:1000',
            'paypal_currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'balance_account' => 'nullable|string|max:100',
        ], [], ['display_name' => __('finance.paypal_profile.name'), 'remark' => __('finance.paypal_profile.remark')]);
        if ($cards->contains(fn ($card) => $card['key'] !== $accountKey && mb_strtolower($card['name']) === mb_strtolower($data['display_name']))) {
            throw ValidationException::withMessages(['display_name' => __('finance.paypal_profile.duplicate_name')])->errorBag('paypalAccount');
        }
        $profileValues = [
            'display_name' => $data['display_name'], 'remark' => $data['remark'] ?? null,
            'updated_by' => (int) $context['user']->getKey(),
        ];
        FinancePaymentAccount::query()->findOrFail($financeAccountId)->update([
            'account_name' => $data['display_name'], 'remark' => $data['remark'] ?? null, 'updated_by' => $profileValues['updated_by'],
        ]);

        return redirect()->route('finance.home', [
            ...array_intersect_key($data, array_flip(['paypal_currency', 'balance_account'])), 'balance_tab' => 'paypal',
        ])->with('success', __('finance.paypal_profile.saved'));
    }

    public function saveExchangeRates(Request $request): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $data = $request->validateWithBag('exchangeRates', [
            'rates' => 'required|array|min:1|max:100',
            'rates.*' => 'required|array:currency,units_per_usd',
            'rates.*.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/', 'distinct'],
            'rates.*.units_per_usd' => ['nullable', 'numeric', 'regex:/^\d{1,12}(?:\.\d{1,10})?$/', 'gt:0'],
            'paypal_currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'balance_account' => 'nullable|string|max:100',
            'balance_tab' => ['nullable', Rule::in(['paypal', 'credit-card', 'conflux'])],
        ], [], ['rates.*.units_per_usd' => __('finance.exchange.rate'), 'rates.*.currency' => __('finance.fields.currency')]);
        foreach ($data['rates'] as $row) {
            if ($row['currency'] === 'USD' && (! isset($row['units_per_usd']) || bccomp($row['units_per_usd'], '1', 10) !== 0)) {
                throw ValidationException::withMessages(['rates' => __('finance.exchange.usd_fixed')])->errorBag('exchangeRates');
            }
        }
        $db = $this->workbooks->database();
        $db->transaction(function () use ($db, $data, $context): void {
            foreach ($data['rates'] as $row) {
                if ($row['currency'] === 'USD') {
                    continue;
                }
                if (! isset($row['units_per_usd'])) {
                    $db->table('finance_exchange_rates')->where('currency', $row['currency'])->delete();
                    continue;
                }
                $existing = $db->table('finance_exchange_rates')->where('currency', $row['currency'])->first();
                $db->table('finance_exchange_rates')->updateOrInsert(['currency' => $row['currency']], [
                    'units_per_usd' => $row['units_per_usd'], 'updated_by' => (int) $context['user']->getKey(),
                    'created_at' => $existing->created_at ?? now(), 'updated_at' => now(),
                ]);
            }
        });

        return redirect()->route('finance.home', array_intersect_key($data, array_flip(['paypal_currency', 'balance_account', 'balance_tab'])))
            ->with('success', __('finance.exchange.saved'));
    }

    public function savePaymentBalance(Request $request): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $request->merge(['currency' => strtoupper(trim((string) $request->input('currency')))]);
        $amountRule = ['required', 'numeric', 'regex:/^-?\d{1,14}(?:\.\d{1,4})?$/'];
        $data = $request->validate([
            'account' => 'required|string|max:100',
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'withdrawable' => $amountRule, 'pending' => $amountRule, 'reserve' => $amountRule,
            'usd_rate' => ['nullable', 'numeric', 'regex:/^\d{1,10}(?:\.\d{1,8})?$/', 'gt:0'],
            'as_of' => ['required', 'date_format:Y-m-d\TH:i'],
            'paypal_currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'balance_tab' => ['nullable', Rule::in(['paypal', 'credit-card', 'conflux'])],
        ], [], trans('finance.payment_balance.validation_attributes'));
        $asOf = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['as_of'], 'Asia/Shanghai')->utc();
        if ($asOf->isFuture()) {
            throw ValidationException::withMessages(['as_of' => __('finance.payment_balance.future_time')]);
        }
        $displayOptions = array_intersect_key($data, ['paypal_currency' => true]);
        unset($data['paypal_currency'], $data['balance_tab']);
        FinancePaymentBalance::query()->updateOrCreate(
            ['channel' => 'xuzong2', 'account' => $data['account'], 'currency' => $data['currency']],
            [
                ...$data, 'channel' => 'xuzong2',
                'usd_rate' => $data['currency'] === 'USD' ? '1' : ($data['usd_rate'] ?? null),
                'as_of' => $asOf,
                'updated_by' => (int) $context['user']->getKey(),
            ],
        );

        return redirect()->route('finance.home', ['balance_account' => $data['account'], ...$displayOptions, 'balance_tab' => 'credit-card'])
            ->with('success', __('finance.payment_balance.saved'));
    }

    public function records(Request $request, string $dataset): Response
    {
        $definition = FinanceCatalog::dataset($dataset);
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }
        $xuzongReport = $request->routeIs('finance.reports.xuzong-credit');
        if (in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true) && $request->has('order_detail')) {
            $detail = $request->validate([
                'order_detail' => $xuzongReport ? 'required|integer|not_in:0' : 'required|integer|min:1',
                'timezone' => ['nullable', Rule::in(FinanceWorkbookService::TIMEZONES)],
            ]);
            $record = $dataset === 'xuzong2-orders'
                ? app(FinanceLogisticsCreditOrders::class)->query(xuzongReport: $xuzongReport, ids: [(int) $detail['order_detail']])
                    ->where('id', (int) $detail['order_detail'])->firstOrFail()
                : FinanceRecord::query()->where('dataset', $dataset)->findOrFail($detail['order_detail']);
            $financeStoredOrder = $xuzongReport && $record->id < 0;
            $logisticsId = $financeStoredOrder ? ($record->payload['_linked_logistics_id'] ?? null) : $record->id;
            $metadata = $dataset === 'xuzong2-orders'
                ? ($logisticsId ? [$record->id => ['logistics_order_id' => $logisticsId]] : [])
                : app(\App\Portals\Finance\FinanceSettlementService::class)->logisticsMetadata(collect([$record]), 'paypal');
            $order = isset($metadata[$record->id]['logistics_order_id']) ? \App\Models\LogisticsOrder::query()->with([
                'items' => fn ($query) => $query->with('skuMapping')->orderBy('id'),
                'paypalAccount', 'paypalRefunds', 'paypalDisputes', 'paymentReferences', 'creditCardRefunds', 'logs',
            ])->find($metadata[$record->id]['logistics_order_id']) : null;
            if ($order && $record->dataset === FinanceLogisticsCreditOrders::IMPORTED_DATASET) {
                $order->payment_status = $record->status;
                $order->bill_number = ($record->payload['交易流水号'] ?? '') ?: $order->bill_number;
            }
            if (! $order) {
                $order = new \App\Models\LogisticsOrder($financeStoredOrder ? [
                    'payment_gateway_order_id' => ($record->payload['_platform_missing'] ?? false) ? null : $record->reference,
                    'bill_number' => $record->payload['交易流水号'] ?? $record->payload['_bill_number'] ?? null,
                    'shopify_order_number' => $record->payload['Shopify订单号'] ?? null,
                    'buyer_email' => $record->payload['消费者邮箱'] ?? $record->payload['_buyer_email'] ?? null,
                    'payment_gateway' => $record->payload['_gateway'] ?? null,
                    'payment_status' => $record->status,
                    'collection_account' => $record->account,
                    'order_created_at' => $record->occurred_at,
                ] : [
                    'transaction_id' => $record->reference,
                    'bill_number' => $record->payload['帳單號碼'] ?? null,
                    'buyer_name' => $record->payload['姓名'] ?? null,
                    'buyer_email' => $record->payload['寄件人電郵地址'] ?? null,
                    'payment_gateway' => 'PayPal',
                    'collection_account' => $record->account,
                    'paid_at' => $record->occurred_at,
                ]);
                foreach (['items', 'paypalRefunds', 'paypalDisputes', 'paymentReferences', 'creditCardRefunds', 'logs'] as $relation) {
                    $order->setRelation($relation, collect());
                }
                if ($dataset === 'paypal-orders') {
                    $order->setRelation('items', \App\Portals\Finance\FinancePayPalOrderExport::productItems(
                        [], (string) ($record->payload['物品标题'] ?? ''), (string) ($record->payload['数量'] ?? ''),
                    ));
                }
                $order->setRelation('paypalAccount', null);
            }
            $decode = static fn ($value) => is_array($value) ? $value : (json_decode((string) $value, true) ?: []);

            $html = view('portals.finance.partials.order-detail', [
                'order' => $order,
                'timezone' => $detail['timezone'] ?? 'Asia/Shanghai',
                'shopifyPayload' => $decode($order->shopify_payload),
                'tongtuPayload' => $decode($order->tongtu_payload),
                'fulfillmentPayload' => $decode($order->fulfillment_payload),
                'pageSourcePayload' => $decode($order->page_source),
            ])->render();

            return response(preg_replace('/\sdata-edit-url="[^"]*"/', '', $html, 1));
        }
        $filters = $this->filters($request, $dataset);
        if ($request->has('suggestion_field')) {
            $suggestion = $request->validate([
                'suggestion_field' => ['required', Rule::in(FinanceCatalog::filterFields($dataset))],
                'suggestion_q' => 'nullable|string|max:191',
            ]);
            if (in_array($suggestion['suggestion_field'], ['category', 'direction'], true)) {
                return response()->json([]);
            }
            $column = FinanceCatalog::filterColumn($dataset, $suggestion['suggestion_field']);
            $values = $this->recordsQuery($dataset, $filters, $xuzongReport)->whereNotNull($column)->where($column, '!=', '')
                ->where($column, 'like', '%'.($suggestion['suggestion_q'] ?? '').'%')
                ->select($column.' as choice')->distinct()->orderBy('choice')->limit(20)->pluck('choice');

            return response()->json($values->map(fn ($value) => ['value' => (string) $value, 'label' => FinanceCatalog::simplified((string) $value)]));
        }
        $indexOnly = $dataset === 'xuzong2-orders' && ! array_filter(\Illuminate\Support\Arr::except(
            $filters, ['timezone', 'per_page', 'export_format', 'transaction_scope']
        ), fn ($value) => filled($value)) && ($filters['transaction_scope'] ?? 'all') === 'all';
        $hydratePage = $xuzongReport || $indexOnly;
        $query = $indexOnly
            ? app(FinanceLogisticsCreditOrders::class)->query(xuzongReport: $xuzongReport, indexOnly: true)
            : $this->recordsQuery($dataset, $filters, $xuzongReport, lightweight: $xuzongReport);
        $perPage = (int) ($filters['per_page'] ?? 10);
        if ($indexOnly && $xuzongReport && in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $orderIndex = Cache::remember($this->filterCacheKey('xuzong-credit:imported-page-index'), 15, fn () => $query
                ->orderByDesc('occurred_at')->orderByDesc('id')->toBase()->get(['id', 'account']));
            $exportAccounts = $orderIndex->countBy('account')->sortKeys()
                ->map(fn ($total, $account) => (object) ['account' => $account, 'total' => $total])->values();
            $pageNumber = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage();
            $records = new \Illuminate\Pagination\LengthAwarePaginator(
                $orderIndex->forPage($pageNumber, $perPage)->values(), $orderIndex->count(), $perPage, $pageNumber,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        } else {
            $exportAccounts = (clone $query)->selectRaw('account, count(*) as total')->groupBy('account')->orderBy('account')->get();
            $records = $query->orderByDesc('occurred_at')->orderByDesc('id')
                ->paginate($perPage, $hydratePage ? ['id'] : ['*'], total: (int) $exportAccounts->sum('total'))->withQueryString();
        }
        if ($hydratePage && $records->isNotEmpty()) {
            $ids = $records->pluck('id');
            $page = app(FinanceLogisticsCreditOrders::class)->query(xuzongReport: $xuzongReport, ids: $ids->all())->get()->keyBy('id');
            $records->setCollection(new \Illuminate\Database\Eloquent\Collection(
                $ids->map(fn ($id) => $page->get($id))->filter()->values()->all(),
            ));
        }
        $types = $xuzongReport ? collect() : $this->workbooks->database()->table('finance_transaction_types')->pluck('category', 'raw_type');
        $groups = $xuzongReport ? collect() : $this->workbooks->domainGroups()->keyBy('domain');
        $paymentAccounts = $xuzongReport ? collect() : FinancePaymentAccount::query()
            ->where('account_type', $definition['channel'] === 'paypal' ? 'paypal' : 'credit_card')
            ->orderBy('id')->get(['activity_account', 'account_name']);
        $accounts = $paymentAccounts->pluck('activity_account')->unique()->values();
        $accountLabels = $paymentAccounts->pluck('account_name', 'activity_account');
        $conditionChoices = $this->conditionChoices($dataset, $xuzongReport);
        if ($dataset === 'xuzong2-orders') {
            $accounts = collect($conditionChoices['account'])->pluck('value');
        }
        if ($xuzongReport) {
            $accountLabels = collect(FinanceLogisticsCreditOrders::ACCOUNT_LABELS);
            $accounts = $accountLabels->keys();
        }

        $paypalOrderMetadata = $dataset === 'paypal-orders'
            ? app(\App\Portals\Finance\FinanceSettlementService::class)->logisticsMetadata($records->getCollection(), 'paypal') : [];
        if ($dataset === 'xuzong2-orders' && ! $xuzongReport && $records->isNotEmpty()) {
            $db = $this->workbooks->database();
            $orders = \App\Models\LogisticsOrder::query()->whereIn('id', $records->pluck('id'))->get()->keyBy('id');
            foreach ($records as $record) {
                $order = $orders->get($record->id);
                if ($order) {
                    $paypalOrderMetadata[$record->id] = [
                        'logistics_order_id' => $order->id, 'order' => $order, 'domain' => $record->domain,
                        'erp_order' => $order->tongtu_order_number ?? '', 'subscription' => $order->subscription_type ?? '', 'upsell' => $order->is_add_on ?? '',
                    ];
                }
            }
        }
        $orderIds = array_unique(array_column($paypalOrderMetadata, 'logistics_order_id'));
        if ($orderIds && $this->workbooks->database()->getSchemaBuilder()->hasTable('logistics_orders')) {
            $relations = [];
            foreach (['paypalRefunds' => 'logistics_paypal_refunds', 'creditCardRefunds' => 'logistics_credit_card_refunds'] as $relation => $table) {
                if ($this->workbooks->database()->getSchemaBuilder()->hasTable($table)) {
                    $relations[] = $relation;
                }
            }
            $linkedOrders = \App\Models\LogisticsOrder::query()->with($relations)->whereIn('id', $orderIds)->get()->keyBy('id');
            foreach ($paypalOrderMetadata as &$metadata) {
                $metadata['order'] = $linkedOrders->get($metadata['logistics_order_id']) ?? ($metadata['order'] ?? null);
            }
            unset($metadata);
        }
        $paypalOrderItems = $orderIds && $this->workbooks->database()->getSchemaBuilder()->hasTable('logistics_order_items')
            ? \App\Models\LogisticsOrderItem::query()->whereIn('logistics_order_id', $orderIds)->orderBy('id')->get()->groupBy('logistics_order_id')
            : collect();
        $fixedFilterOptions = [];
        if (in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true)) {
            $fixed = ['shopify_sku_status' => ['empty' => '为空', 'filled' => '不为空'], 'pcl_runnable' => ['yes' => '可跑', 'no' => '不可跑'],
                'store_assignment_status' => ['assigned' => '已归属', 'unmatched' => '未归属'], 'is_add_on' => ['已加购' => '已加购', '未加购' => '未加购']];
            foreach ($fixed as $field => $options) {
                $fixedFilterOptions[$field] = collect($options)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values();
            }
            $db = $this->workbooks->database();
            $logisticsChoices = Cache::remember($this->filterCacheKey('logistics'), 60, function () use ($db): array {
                $result = [];
                $fields = array_values(array_intersect(
                    ['store_name', 'assigned_owner_name', 'group_names', 'staff_status', 'source_shop_domain', 'subscription_type', 'payment_method', 'logistics_status', 'data_status'],
                    $db->getSchemaBuilder()->getColumnListing('logistics_orders')
                ));
                if ($fields === []) {
                    return $result;
                }
                // Fetch a narrow projection once instead of scanning logistics history for each dropdown.
                $options = $db->table('logistics_orders')->distinct()->get($fields);
                foreach ($fields as $field) {
                    $result[$field] = $options->pluck($field)->filter(fn ($value) => $value !== null && $value !== '')
                        ->uniqueStrict()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()
                        ->map(fn ($value) => ['value' => (string) $value, 'label' => FinanceCatalog::simplified((string) $value)]);
                }
                return $result;
            });
            $fixedFilterOptions = array_merge($fixedFilterOptions, $logisticsChoices);
        }

        return response()->view('portals.finance.records', [
            ...$context, ...compact('dataset', 'definition', 'filters', 'records', 'types', 'groups', 'xuzongReport'),
            'xuzongRows' => $xuzongReport ? app(FinanceXuzongCreditReport::class)->rows($records->getCollection(), $filters['timezone']) : collect(),
            'xuzongStatementNotices' => $xuzongReport ? FinanceRecord::query()
                ->where('dataset', FinanceLogisticsCreditOrders::IMPORTED_DATASET)
                ->whereNotNull('payload->_xuzong_statement_import->notice')
                ->latest('updated_at')->orderByDesc('id')->paginate(in_array($request->integer('statement_notice_per_page'), [10, 20, 50, 100], true) ? $request->integer('statement_notice_per_page') : 10,
                    ['id', 'account', 'reference', 'payload'], 'statement_notice_page')
                ->withQueryString() : null,
            'accounts' => $accounts,
            'accountLabels' => $accountLabels,
            'refreshablePayPalAccounts' => $dataset === 'paypal-orders' && $context['canManage']
                ? $this->refreshablePayPalAccounts()->orderBy('id')->get(['id', 'account_name']) : collect(),
            'paypalOrderMetadata' => $paypalOrderMetadata,
            'paypalOrderItems' => $paypalOrderItems,
            'fixedFilterOptions' => $fixedFilterOptions,
            'conditionChoices' => $conditionChoices,
            'exportAccounts' => $exportAccounts,
            'numericFilterFields' => FinanceCatalog::numericFilterFields($dataset),
            'canImport' => FinanceCatalog::supportsImport($dataset),
            'accountingTransactions' => $dataset === 'paypal-orders' ? $this->workbooks->database()->table('finance_payment_transactions')->whereIn('finance_record_id', $records->pluck('id'))->get(['finance_record_id', 'category', 'direction', 'monthly_rate', 'cny_amount'])->keyBy('finance_record_id') : collect(),
            'filterFields' => FinanceCatalog::filterFieldLabels($dataset),
            'filterOperators' => ['equals', 'contains', 'not_equals', 'empty', 'not_empty', 'gt', 'gte', 'lt', 'lte'],
            'categories' => $xuzongReport ? collect() : $types->merge($this->workbooks->database()->table('finance_payment_transactions')->whereNotNull('category')->distinct()->pluck('category'))->filter()->unique()->sort()->values(),
        ]);
    }

    public function importRecords(Request $request, string $dataset): Response
    {
        $definition = FinanceCatalog::dataset($dataset);
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless(FinanceCatalog::supportsImport($dataset), 404);
        $data = $request->validate([
            'file' => 'required|file|mimes:xlsx,csv,tsv,txt|max:10240',
            'account' => 'required|string|max:191',
            'source_timezone' => ['required', Rule::in(FinanceWorkbookService::TIMEZONES)],
        ]);
        $sheetName = str_ends_with($dataset, '-details') ? trans('finance.sheets.'.$dataset) : null;
        $rows = $this->workbooks->read($data['file'], $definition['headers'], $sheetName, FinanceCatalog::optionalImportHeaders($dataset));
        $count = $this->workbooks->importRecords($dataset, $rows, trim($data['account']), $data['source_timezone'], (int) $context['user']->getKey());

        return redirect('/finance/records/'.$dataset)->with('success', __('finance.imported', ['count' => $count]));
    }

    public function xuzongStatement(Request $request, int $record): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }
        $order = FinanceRecord::query()->where('dataset', FinanceLogisticsCreditOrders::IMPORTED_DATASET)->findOrFail($record);

        return response()->view($request->ajax() ? 'portals.finance.partials.xuzong-statement-body' : 'portals.finance.xuzong-statement', [
            ...$context, 'title' => '结算变动明细', 'order' => $order, 'statement' => $order->payload['_xuzong_statement_import'] ?? [],
            'reportValues' => $context['canManage'] ? app(FinanceXuzongCreditReport::class)->orderValues($order->id) : [],
            'amountFields' => \App\Portals\Finance\FinanceXuzongOrderImport::statementAmountFields(),
        ]);
    }

    public function updateXuzongStatement(Request $request, int $record): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $fields = \App\Portals\Finance\FinanceXuzongOrderImport::statementAmountFields();
        $data = $request->validate([
            'action' => ['required', Rule::in(['add', 'update', 'delete', 'handled', 'edit_order'])],
            'order_fields' => ['required_if:action,edit_order', 'array:'.implode(',', \App\Portals\Finance\FinanceXuzongOrderImport::EDITABLE_ORDER_FIELDS)],
            'order_fields.*' => 'nullable|string|max:191',
            'report_fields' => ['nullable', 'array:'.implode(',', FinanceXuzongCreditReport::additionalEditableHeaders())],
            'report_fields.*' => 'nullable|string|max:500',
            'order_fields.创建时间' => 'required_if:action,edit_order|date_format:Y-m-d H:i:s',
            'order_fields.完成时间' => 'nullable|date_format:Y-m-d H:i:s',
            'order_fields.交易币种' => 'required_if:action,edit_order|alpha|size:3',
            'order_fields.订单币种' => 'nullable|alpha|size:3',
            'order_fields.交易金额' => 'nullable|numeric',
            'order_fields.订单金额' => 'nullable|numeric',
            'order_fields.支付状态' => 'required_if:action,edit_order|string|max:50',
            'notice' => 'required_if:action,handled|nullable|uuid',
            'event' => ['required_if:action,update,delete', 'nullable', 'regex:/^[a-f0-9]{64}$/D'],
            'time' => 'required_if:action,add,update|nullable|date_format:Y-m-d H:i:s',
            'amounts' => ['nullable', 'array:'.implode(',', $fields)],
            'amounts.应结算' => ['required_if:action,add,update', 'nullable', 'regex:/^-?\d{1,18}(?:\.\d{1,2})?$/D'],
            'amounts.*' => ['nullable', 'regex:/^-?\d{1,18}(?:\.\d{1,8})?$/D'],
        ]);
        try {
            app(\App\Portals\Finance\FinanceXuzongOrderImport::class)->manageStatement($record, $data);
        } catch (\App\Portals\Finance\FinanceRelayException $exception) {
            throw ValidationException::withMessages(['statement' => $exception->getMessage()]);
        }

        if ($request->expectsJson()) {
            $handled = $data['action'] === 'handled';
            $page = 1;
            if ($handled) {
                $pageSize = $request->integer('notice_per_page', 10);
                $pageSize = in_array($pageSize, [10, 20, 50, 100], true) ? $pageSize : 10;
                $total = FinanceRecord::query()->where('dataset', FinanceLogisticsCreditOrders::IMPORTED_DATASET)
                    ->whereNotNull('payload->_xuzong_statement_import->notice')->count();
                $page = max(1, min($request->integer('notice_page', 1), (int) ceil($total / $pageSize)));
            }

            return response()->json(['handled' => $handled, 'noticePage' => $page]);
        }

        if ($data['action'] === 'handled') {
            return redirect()->route('finance.reports.xuzong-credit')
                ->with('success', '该结算变动已处理。');
        }

        return redirect()->route('finance.reports.xuzong-credit.statement', $record)->with('success', '结算明细已更新。');
    }

    public function importXuzongCreditDetails(Request $request): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $data = $request->validate([
            'file' => 'required|file|mimes:xlsx,csv,tsv,txt|max:10240',
            'source' => ['nullable', Rule::in(array_keys(FinanceCatalog::XUZONG_REPORT_IMPORTS))],
            'account' => 'nullable|string|max:191',
            'source_timezone' => ['nullable', Rule::in(FinanceWorkbookService::TIMEZONES)],
            'import_kind' => ['nullable', Rule::in(['orders', 'statement'])],
        ]);
        $source = $data['source'] ?? null;
        $timezone = $data['source_timezone'] ?? 'UTC';
        $account = $data['account'] ?? null;
        $reportAccount = $account;
        if ($account !== null && ! isset(FinanceLogisticsCreditOrders::ACCOUNT_LABELS[$account])) {
            $reportAccount = FinancePaymentAccount::query()->where('account_type', 'credit_card')
                ->where('activity_account', $account)->value('account_name');
            if (! isset(FinanceLogisticsCreditOrders::ACCOUNT_LABELS[$reportAccount])) {
                throw ValidationException::withMessages(['account' => '请选择徐总1或徐总2的信用卡账户。']);
            }
        }
        $orderImport = app(\App\Portals\Finance\FinanceXuzongOrderImport::class);
        $fileType = $orderImport->fileType($data['file']);
        if (in_array($fileType, ['official_statement', 'refunds'], true)) {
            $refunds = $fileType === 'refunds';
            try {
                $result = $refunds
                    ? $orderImport->importRefunds($data['file'], $reportAccount)
                    : $orderImport->importOfficialStatement($data['file'], $reportAccount);
            } catch (\App\Portals\Finance\FinanceRelayException $e) {
                if ($e->reason !== 'period_locked') {
                    throw $e;
                }

                throw ValidationException::withMessages(['file' => '文件涉及已关账月份，无法更新结算或退款记录。']);
            }
            if ($request->expectsJson()) {
                return response()->json($result);
            }

            return redirect()->route('finance.reports.xuzong-credit')->with('success',
                '已匹配 '.$result['matched'].' 笔订单，更新 '.$result['saved'].' 笔'.($refunds ? '退款' : '结算').'明细。');
        }
        if ($fileType === 'batches') {
            throw ValidationException::withMessages(['file' => '这份文件是结算批次汇总，请选择含逐笔订单号的交易文件。']);
        }
        if (in_array($data['import_kind'] ?? $fileType, ['orders', 'legacy_orders'], true)) {
            $legacySheet = $fileType === 'legacy_orders';
            $result = $this->workbooks->database()->transaction(fn () => $orderImport->import(
                $data['file'], $legacySheet ? 'SHANGYUN' : $reportAccount, $timezone, (int) $context['user']->getKey(), $legacySheet,
            ));
            Cache::forget($this->filterCacheKey('xuzong-credit:payment-currencies'));
            Cache::forget($this->filterCacheKey('xuzong-credit:order-options'));
            Cache::forget($this->filterCacheKey('xuzong-credit:imported-page-index'));

            if ($request->expectsJson()) {
                return response()->json($result);
            }

            return redirect()->route('finance.reports.xuzong-credit')->with('success',
                '已新增或补齐 '.$result['saved'].' 条订单，关联到系统订单 '.$result['matched'].' 条，跳过重复 '.($result['total'] - $result['saved']).' 条。');
        }
        // The workbook's payable column is a formula. The report calculates it from the matched amounts.
        $headers = array_values(array_diff($source ? FinanceCatalog::dataset($source)['headers'] : [
            ...FinanceCatalog::dataset('xuzong1-details')['headers'], ...FinanceCatalog::dataset('xuzong2-details')['headers'],
        ], ['应结算']));
        $headers = array_values(array_unique([...$headers, '商户号', '是否3D', '发卡国家', '卡号',
            '平台订单号', '交易流水号', '结算币种', '拒付返还', '争议处理费', 'RDR费用', 'CDRN费用',
            ...FinanceXuzongCreditMatches::EXCHANGE_HEADERS,
            ...array_values(FinanceXuzongCreditReport::DISPLAY_HEADERS),
            ...array_keys(FinanceXuzongCreditMatches::STATEMENT_HEADERS)]));
        $rows = $this->workbooks->read($data['file'], $headers, $source ? trans('finance.sheets.'.$source) : null, $headers);
        foreach ($rows as &$row) {
            foreach (FinanceXuzongCreditReport::DISPLAY_HEADERS as $field => $label) {
                if (blank($row[$field] ?? null) && filled($row[$label] ?? null)) {
                    $row[$field] = $row[$label];
                }
                unset($row[$label]);
            }
            foreach (FinanceXuzongCreditMatches::STATEMENT_HEADERS as $statementHeader => $detailHeader) {
                if (blank($row[$detailHeader] ?? null) && filled($row[$statementHeader])) {
                    $row[$detailHeader] = $row[$statementHeader];
                }
                unset($row[$statementHeader]);
            }
        }
        unset($row);
        $unclassified = $reportAccount === null ? array_filter($rows, fn ($row) => $orderImport::accountForRow($row) === null) : [];
        $linked = [];
        foreach (array_chunk($unclassified, 300, true) as $batch) {
            $linked += $orderImport->matches($batch);
        }
        $groups = [];
        foreach ($rows as $line => $row) {
            $rowAccount = $reportAccount ?? $orderImport::accountForRow($row) ?? ($linked[$line]->account ?? null);
            if ($rowAccount === null) {
                throw ValidationException::withMessages(['file' => '第'.$line.'行无法识别徐总信用卡账户，请核对商户名称或交易流水号。']);
            }
            $rowSource = $source ?? ($rowAccount === 'SHANGYUN' ? 'xuzong1-details' : 'xuzong2-details');
            if ($source === null && $rowSource === 'xuzong1-details') {
                $row['交易单号'] = $row['交易单号'] ?: $row['平台订单号'];
                $row['交易时间'] = $row['交易时间'] ?: $row['创建时间'];
            }
            if ($source === null && $rowSource === 'xuzong2-details') {
                $row['创建时间'] = $row['创建时间'] ?: $row['交易时间'];
            }
            if ($rowSource === 'xuzong2-details' && blank($row['平台订单号'] ?? null)
                && str_starts_with(strtoupper(trim($row['交易流水号'] ?? '')), 'TXN')) {
                $row['平台订单号'] = trim($row['交易流水号']);
            }
            if (filled($row['卡号'] ?? null)) {
                $row['卡号'] = preg_match('/(\d{4})$/D', trim((string) $row['卡号']), $lastFour) ? '****'.$lastFour[1] : '';
            }
            $groups[$rowSource][$rowAccount][$line] = $row;
        }
        $affected = $this->workbooks->database()->transaction(function () use ($account, $groups, $timezone, $context): int {
            $affected = 0;
            $matches = app(FinanceXuzongCreditMatches::class);
            foreach ($groups as $rowSource => $accounts) {
                $dataset = FinanceCatalog::XUZONG_REPORT_IMPORTS[$rowSource];
                foreach ($accounts as $rowAccount => $accountRows) {
                    $accountRows = $matches->completeStatementRows($accountRows, $dataset, $rowAccount, $timezone);
                    $affected += $this->workbooks->importRecords($dataset, $accountRows, $account ?? $rowAccount, $timezone,
                        (int) $context['user']->getKey(), createOnly: true, fillMissing: true);
                }
            }

            return $affected;
        });

        Cache::forget($this->filterCacheKey('xuzong-credit:payment-currencies'));
        Cache::forget($this->filterCacheKey('xuzong-credit:imported-page-index'));

        if ($request->expectsJson()) {
            return response()->json(['total' => count($rows), 'saved' => $affected]);
        }

        return redirect()->route('finance.reports.xuzong-credit')->with('success',
            '已新增或补齐 '.$affected.' 条账单记录，跳过 '.(count($rows) - $affected).' 条。报表会自动匹配订单。');
    }

    public function exportRecords(Request $request, string $dataset): Response
    {
        $definition = FinanceCatalog::dataset($dataset);
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }
        if ($request->boolean('template')) {
            abort_unless(FinanceCatalog::supportsImport($dataset), 404);
            return $this->workbooks->download($definition['headers'], [], $dataset.'-template.xlsx');
        }
        $filters = $this->filters($request, $dataset);
        $pageLayout = in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true) && $request->input('export_layout') === 'page';
        $xuzongReport = $request->routeIs('finance.reports.xuzong-credit.export');
        $productIndex = array_search('物品标题', array_map(FinanceCatalog::simplified(...), $definition['headers']), true);
        $pageHeaders = $pageLayout ? \App\Portals\Finance\FinancePayPalOrderExport::pageHeaders($dataset) : [];
        $xuzongHeaders = $xuzongReport ? FinanceXuzongCreditReport::headers() : [];
        $exportColumnCount = match (true) {
            $xuzongReport => count($xuzongHeaders),
            $pageLayout => count($pageHeaders),
            default => count($definition['headers']) + ($dataset === 'xuzong2-orders' ? 3 : 4)
                + ($dataset === 'paypal-orders' ? count(\App\Portals\Finance\FinancePayPalOrderExport::COLUMNS) : 0),
        };
        $selection = $request->validate([
            'export_layout' => 'nullable|in:page,source',
            'export_scope' => ['required', Rule::in(['accounts', 'records', 'filtered'])],
            'selected_accounts' => 'nullable|array|max:100',
            'selected_accounts.*' => 'required|string|max:191|distinct',
            'selected_ids' => 'nullable|array|max:100',
            'selected_ids.*' => $xuzongReport ? 'required|integer|not_in:0|distinct' : 'required|integer|min:1|distinct',
            'report_columns' => 'required_with:report_fields_customized|array|min:1|max:'.$exportColumnCount,
            'report_columns.*' => ['required', 'integer', 'distinct', Rule::in($xuzongReport
                ? array_keys($xuzongHeaders) : range(0, $exportColumnCount - 1))],
        ], ['export_scope.required' => __('finance.records.selection_required')]);
        if (! $xuzongReport && $request->boolean('export_template')) {
            abort_unless($pageLayout && $dataset === 'paypal-orders', 404);
            $headers = isset($selection['report_columns'])
                ? array_map(fn ($index) => $pageHeaders[$index], $selection['report_columns']) : $pageHeaders;

            return $this->downloadRecords($headers, [], $dataset.'-export-template', $filters['export_format'] ?? 'xlsx', $pageLayout);
        }
        if ($xuzongReport) {
            $headers = isset($selection['report_columns'])
                ? array_map(fn ($id) => $xuzongHeaders[$id], $selection['report_columns'])
                : array_values($xuzongHeaders);
            if ($request->boolean('export_template')) {
                $format = $filters['export_format'] ?? 'xlsx';

                return $this->workbooks->download($headers, [], 'xuzong-credit-template.'.$format, $format, simplifyText: false);
            }
        }
        $selected = $selection['export_scope'] === 'accounts' ? 'selected_accounts' : 'selected_ids';
        if ($selection['export_scope'] !== 'filtered' && empty($selection[$selected])) {
            throw ValidationException::withMessages([$selected => __('finance.records.selection_required')]);
        }
        $query = $this->recordsQuery($dataset, $filters, $xuzongReport, lightweight: $xuzongReport);
        if ($xuzongReport && $selected === 'selected_ids' && $selection['export_scope'] === 'records') {
            $query->whereIntegerInRaw('id', $selection[$selected]);
        } elseif ($selection['export_scope'] !== 'filtered') {
            $query->whereIn($selected === 'selected_accounts' ? 'account' : 'id', $selection[$selected]);
        }
        if ($dataset === 'paypal-orders') {
            // Resolve linked filters once; accounting lookup and export batches reuse the IDs.
            $exportIds = (clone $query)->pluck('finance_records.id')->all();
            $query = FinanceRecord::query()->whereIntegerInRaw('id', $exportIds);
        }
        if (! (clone $query)->exists()) {
            throw ValidationException::withMessages([$selected => __('finance.records.selection_empty')]);
        }
        if (($filters['export_format'] ?? 'xlsx') === 'xls' && (clone $query)->count() > 65535) {
            throw ValidationException::withMessages(['export_format' => __('finance.records.xls_row_limit')]);
        }
        if ($xuzongReport) {
            $report = app(FinanceXuzongCreditReport::class);
            $orders = app(FinanceLogisticsCreditOrders::class);
            $rows = (clone $query)->reorder('id')->select('id')->lazyById(300)->chunk(300)->flatMap(function ($batch) use ($orders, $report, $filters, $headers) {
                $records = $orders->query(xuzongReport: true, ids: $batch->pluck('id')->all())->orderBy('id')->get();

                return $report->rows($records, $filters['timezone'])->values()->map(fn ($values) =>
                    array_map(fn ($header) => FinanceXuzongCreditReport::formatCell($header, $values[$header] ?? ''), $headers));
            });

            $filename = 'xuzong-credit-'.now()->format('Ymd-His');
            $format = $filters['export_format'] ?? 'xlsx';

            return match ($format) {
                'xls' => $this->workbooks->download($headers, $rows, $filename.'.xls', 'xls', simplifyText: false),
                'csv', 'tsv' => $this->workbooks->downloadDelimited(
                    $headers, $rows, $filename.'.'.$format, $format === 'tsv' ? "\t" : ',', simplifyText: false,
                ),
                default => FinanceXuzongCreditReport::downloadXlsx($headers, $rows, $filename.'.xlsx',
                    fn () => FinanceXuzongCreditReport::statementExportRows(
                        (clone $query)->reorder('id')->where(fn ($records) => $records->whereNotNull('payload->_xuzong_statement_import')
                            ->orWhereNotNull('payload->_edited_report_fields'))->lazyById(500)
                    )),
            };
        }
        $types = $this->workbooks->database()->table('finance_transaction_types')->pluck('category', 'raw_type');
        $groups = $this->workbooks->domainGroups()->keyBy('domain');
        $accountingRows = $dataset === 'xuzong2-orders' ? collect() : $this->workbooks->database()->table('finance_payment_transactions')->whereIn('finance_record_id', (clone $query)->select('id'))->get(['finance_record_id', 'payment_account_id', 'category', 'direction', 'monthly_rate', 'cny_amount'])->keyBy('finance_record_id');
        $accountLabels = FinancePaymentAccount::query()
            ->where('account_type', $definition['channel'] === 'paypal' ? 'paypal' : 'credit_card')
            ->pluck('account_name', 'activity_account');
        $accountNamesById = FinancePaymentAccount::query()->pluck('account_name', 'id');
        $rows = $query->reorder()->lazyById(300)->chunk(300)->flatMap(function ($batch) use ($dataset, $definition, $filters, $types, $groups, $accountingRows, $accountLabels, $accountNamesById, $pageLayout, $productIndex) {
            $records = new \Illuminate\Database\Eloquent\Collection($batch->all());
            $metadata = $dataset === 'paypal-orders'
                ? app(\App\Portals\Finance\FinanceSettlementService::class)->logisticsMetadata($records, 'paypal') : [];
            if ($dataset === 'xuzong2-orders' && $pageLayout) {
                foreach ($this->workbooks->database()->table('logistics_orders')->whereIn('id', $batch->pluck('id')->all())->get() as $order) {
                    $metadata[$order->id] = ['order' => $order, 'logistics_order_id' => $order->id,
                        'domain' => $batch->firstWhere('id', $order->id)?->domain,
                        'erp_order' => $order->tongtu_order_number ?? '', 'subscription' => $order->subscription_type ?? '',
                        'upsell' => $order->is_add_on ?? ''];
                }
            }
            $items = ($dataset === 'paypal-orders' || $pageLayout) && $this->workbooks->database()->getSchemaBuilder()->hasTable('logistics_order_items')
                ? $this->workbooks->database()->table('logistics_order_items')->whereIn('logistics_order_id', array_column($metadata, 'logistics_order_id'))->orderBy('id')->get()->groupBy('logistics_order_id') : collect();
            $refunds = [];
            if ($pageLayout) {
                $db = $this->workbooks->database();
                foreach (['paypal' => 'logistics_paypal_refunds', 'credit_card' => 'logistics_credit_card_refunds'] as $channel => $table) {
                    if (! $db->getSchemaBuilder()->hasTable($table)) continue;
                    foreach ($db->table($table)->whereIn('logistics_order_id', array_column($metadata, 'logistics_order_id'))->orderBy('id')->get() as $refund) {
                        $refunds[$refund->logistics_order_id][$channel][] = trim(($refund->reason ?? $refund->refund_reason ?? '').' '.($refund->refund_amount_usd ?? ''));
                    }
                }
            }
            return $batch->map(function ($record) use ($dataset, $definition, $filters, $types, $groups, $accountingRows, $accountLabels, $accountNamesById, $metadata, $items, $pageLayout, $refunds, $productIndex) {
            $payload = $record->payload;
            $values = $pageLayout ? [] : array_map(fn ($index, $header) => in_array('column_'.$index, FinanceCatalog::numericFilterFields($dataset), true) || in_array(FinanceCatalog::simplified($header), ['数量', '物品标题', '主旨', '团队'], true) || ($definition['channel'] === 'paypal' && in_array($index, [14, $definition['status']], true))
                ? FinanceCatalog::displayValue($dataset, $index, $payload[$header] ?? '')
                : ($payload[$header] ?? ''), array_keys($definition['headers']), $definition['headers']);
            if (! $pageLayout && $productIndex !== false) {
                $values[$productIndex] = FinanceProductNames::categories((string) $values[$productIndex]);
            }
            if (! $pageLayout && $definition['channel'] === 'paypal' && isset($definition['type'])) {
                $values[$definition['type']] = \App\Portals\Finance\FinancePayPalEventTypes::label((string) $values[$definition['type']]);
            }
            $linked = $metadata[$record->id] ?? [];
            $group = $groups->get(($linked['domain'] ?? '') ?: $record->domain);
            if (! $pageLayout && $group && isset($definition['owner'])) {
                $values[$definition['owner']] = $group->owner;
                $values[$definition['team']] = $group->team;
            }

            $accounting = $accountingRows->get($record->id);
            $typeCategory = $types->get($record->raw_type) ?? '';
            $category = array_key_exists('type_category', $filters)
                ? ($typeCategory !== '' ? $typeCategory : __('finance.records.unclassified'))
                : ($accounting ? ($accounting->category ?: __('finance.records.unclassified')) : ($record->raw_type ? ($types->get($record->raw_type) ?: __('finance.records.unclassified')) : ''));

            if ($pageLayout) {
                return \App\Portals\Finance\FinancePayPalOrderExport::pageValues($record, $linked, $group,
                    $accountNamesById->get($accounting?->payment_account_id) ?? $accountLabels->get($record->account, $record->account),
                    $category, $filters['timezone'], $items->get($linked['logistics_order_id'] ?? 0, []),
                    array_map(fn ($lines) => implode("\n", $lines), $refunds[$linked['logistics_order_id'] ?? 0] ?? []), $dataset);
            }
            return [...$values, $accountNamesById->get($accounting?->payment_account_id) ?? $accountLabels->get($record->account, $record->account), ...($dataset === 'xuzong2-orders' ? [] : [$category]), $record->occurred_at->setTimezone($filters['timezone'])->format('Y-m-d H:i:s'), $filters['timezone'],
                ...($dataset === 'paypal-orders' ? \App\Portals\Finance\FinancePayPalOrderExport::values($linked['order'] ?? null, $filters['timezone'], $items->get($linked['logistics_order_id'] ?? 0, [])) : []),
                ...($accountingRows->isEmpty() ? [] : [$accounting?->direction ? __('finance.accounting.directions.'.$accounting->direction) : '', $accounting?->monthly_rate ?? '待配置', $accounting?->cny_amount ?? '待配置'])];
            });
        });

        $headers = [...$definition['headers'], __($definition['channel'] === 'paypal' ? 'finance.fields.paypal_account' : 'finance.fields.account'), ...($dataset === 'xuzong2-orders' ? [] : [__('finance.fields.category')]), __('finance.fields.display_time'), __('finance.fields.timezone')];
        if ($dataset === 'paypal-orders') {
            $headers = [...$headers, ...array_values(\App\Portals\Finance\FinancePayPalOrderExport::COLUMNS)];
        }
        if ($accountingRows->isNotEmpty()) {
            $headers = [...$headers, '收支方向', '月度人民币汇率', '折合人民币'];
        }
        if ($pageLayout) {
            $headers = $pageHeaders;
        } elseif ($productIndex !== false) {
            $headers[$productIndex] = '商品大类';
        }
        if (isset($selection['report_columns'])) {
            $columns = array_map('intval', $selection['report_columns']);
            $headers = array_map(fn ($index) => $headers[$index], $columns);
            $rows = $rows->map(fn ($values) => array_map(fn ($index) => $values[$index] ?? '', $columns));
        }
        $filename = $dataset.'-'.now()->format('Ymd-His');

        return $this->downloadRecords($headers, $rows, $filename, $filters['export_format'] ?? 'xlsx', $pageLayout);
    }

    private function downloadRecords(array $headers, iterable $rows, string $filename, string $format, bool $pageLayout): Response
    {
        return match ($format) {
            'xls' => $this->workbooks->download($headers, $rows, $filename.'.xls', 'xls'),
            'csv' => $this->workbooks->downloadDelimited($headers, $rows, $filename.'.csv'),
            'tsv' => $this->workbooks->downloadDelimited($headers, $rows, $filename.'.tsv', "\t"),
            default => $pageLayout
                ? \App\Portals\Finance\FinancePayPalOrderExport::download($headers, $rows, $filename.'.xlsx')
                : $this->workbooks->download($headers, $rows, $filename.'.xlsx'),
        };
    }

    public function settings(Request $request, string $kind): Response
    {
        abort_unless(in_array($kind, ['types', 'domains'], true), 404);
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }
        $data = $request->validate(['q' => 'nullable|string|max:191', 'category' => 'nullable|string|max:100', 'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])]]);
        if ($kind === 'types') {
            $types = $this->workbooks->typeSettings();
            $search = FinanceCatalog::simplified($data['q'] ?? '');
            $category = $data['category'] ?? '';
            $filtered = $types->filter(function ($row) use ($category, $search): bool {
                if ($category === '__unclassified' ? filled($row->category) : ($category !== '' && $row->category !== $category)) {
                    return false;
                }
                return $search === '' || mb_stripos(
                    \App\Portals\Finance\FinancePayPalEventTypes::label($row->raw_type).' '.implode(' ', $row->aliases).' '.FinanceCatalog::simplified($row->category ?? ''),
                    $search
                ) !== false;
            })->values();
            $perPage = (int) ($data['per_page'] ?? 10);
            $page = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage();

            return response()->view('portals.finance.settings', [
                ...$context, 'kind' => $kind,
                'categoryOptions' => $types->pluck('category')->filter()->unique()->sort()->values(),
                'rows' => new \Illuminate\Pagination\LengthAwarePaginator(
                    $filtered->forPage($page, $perPage)->values(), $filtered->count(), $perPage, $page,
                    ['path' => $request->url(), 'query' => $request->query()]
                ),
                'fields' => ['raw_type', 'category'],
            ]);
        }
        $search = FinanceCatalog::simplified($data['q'] ?? '');
        $perPage = (int) ($data['per_page'] ?? 10);
        if ($search === '') {
            $rows = $this->workbooks->domainGroups(perPage: $perPage);
        } else {
            $groups = $this->workbooks->domainGroups()->filter(fn ($row) => mb_stripos(
                FinanceCatalog::simplified($row->domain.' '.$row->owner.' '.$row->team), $search
            ) !== false)->values();
            $page = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage();
            $rows = new \Illuminate\Pagination\LengthAwarePaginator(
                $groups->forPage($page, $perPage)->values(), $groups->count(), $perPage, $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        }

        return response()->view('portals.finance.settings', [
            ...$context, 'kind' => $kind, 'canManage' => false,
            'categoryOptions' => collect(),
            'rows' => $rows,
            'fields' => ['domain', 'owner', 'team'],
        ]);
    }

    public function deleteTypeSetting(Request $request, int $type): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $rawType = $this->workbooks->database()->table('finance_transaction_types')->where('id', $type)->value('raw_type');
        $setting = $this->workbooks->typeSettings()->first(fn ($row) => in_array($rawType, $row->aliases, true));
        abort_unless($setting, 404);
        $deleted = $this->workbooks->database()->table('finance_transaction_types')->whereIn('raw_type', $setting->aliases)->delete();
        abort_unless($deleted, 404);

        return redirect()->route('finance.settings', ['kind' => 'types', ...$request->only('q', 'category')])
            ->with('success', '已删除类型分类设置。');
    }

    public function saveSetting(Request $request, string $kind): Response
    {
        abort_unless(in_array($kind, ['types', 'domains'], true), 404);
        abort_if($kind === 'domains', 403, '域名人员分组由域名与店铺信息管理统一维护。');
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $keys = $kind === 'types' ? ['raw_type', 'category'] : ['domain', 'owner', 'team'];
        $rules = array_fill_keys($keys, 'required|string|max:100');
        $rules[$keys[0]] = 'required|string|max:191';
        $data = $request->validate($rules);
        $this->workbooks->importSettings($kind, [2 => $data]);

        return redirect('/finance/settings/'.$kind)->with('success', __('finance.saved'));
    }

    public function importSettings(Request $request, string $kind): Response
    {
        abort_unless(in_array($kind, ['types', 'domains'], true), 404);
        abort_if($kind === 'domains', 403, '域名人员分组由域名与店铺信息管理统一维护。');
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $data = $request->validate(['file' => 'required|file|mimes:xlsx,csv,txt|max:10240']);
        $rows = $this->workbooks->read($data['file'], $this->settingsHeaders($kind), trans('finance.sheets.'.$kind));
        $count = $this->workbooks->importSettings($kind, $rows);

        return redirect('/finance/settings/'.$kind)->with('success', __('finance.imported', ['count' => $count]));
    }

    public function exportSettings(Request $request, string $kind): Response
    {
        abort_unless(in_array($kind, ['types', 'domains'], true), 404);
        abort_if($kind === 'domains', 403, '域名人员分组仅供查看。');
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }
        $keys = $kind === 'types' ? ['raw_type', 'category'] : ['domain', 'owner', 'team'];
        $rows = $request->boolean('template') ? [] : $this->workbooks->database()->table($this->settingsTable($kind))->select($keys)->orderBy($keys[0])->get()->map(fn ($row) => array_values((array) $row));

        if ($kind === 'types' && ! $request->boolean('template')) {
            $settingsFilters = $request->validate(['q' => 'nullable|string|max:191', 'category' => 'nullable|string|max:100']);
            $rows = $this->workbooks->typeSettings()->filter(function ($row) use ($settingsFilters) {
                $category = $settingsFilters['category'] ?? '';
                if ($category === '__unclassified' ? filled($row->category) : ($category !== '' && $row->category !== $category)) return false;
                $search = FinanceCatalog::simplified($settingsFilters['q'] ?? '');
                return $search === '' || mb_stripos(\App\Portals\Finance\FinancePayPalEventTypes::label($row->raw_type).' '.implode(' ', $row->aliases).' '.FinanceCatalog::simplified($row->category ?? ''), $search) !== false;
            })->map(fn ($row) => [
                $row->raw_type, $row->category,
            ]);
        }

        if (! $request->boolean('template')) {
            $rows = \App\Portals\Finance\FinanceExportSelection::filter($request, collect($rows)->map(fn ($row) => ['raw_type' => $row[0], 'category' => $row[1]]))->map(fn ($row) => array_values($row));
        }
        return $this->workbooks->download($this->settingsHeaders($kind), $rows, 'finance-'.$kind.'.xlsx');
    }

    public function accounting(Request $request): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $db = $this->workbooks->database();

        return response()->view('portals.finance.accounting', [
            ...$context, 'rates' => $db->table('finance_monthly_exchange_rates')->orderByDesc('month')->orderBy('currency')->get(),
            'creditUsdRates' => $db->table(FinanceCreditUsdRates::TABLE)->orderByDesc('starts_on')->orderBy('currency')->get(),
            'rateCurrencies' => FinanceRecord::query()->whereNotNull('currency')->distinct()->pluck('currency'),
            'rules' => $db->table('finance_transaction_type_mappings')->orderBy('priority')->orderBy('id')->get(),
            'locks' => $db->table('finance_period_locks')->whereNotNull('report_snapshot')->orderByDesc('month')->get(),
        ]);
    }

    public function saveAccounting(Request $request, string $action): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $service = app(\App\Portals\Finance\FinanceAccountingService::class);
        $db = $this->workbooks->database();
        try {
            if ($action === 'credit-usd-rate') {
                $data = $request->validate([
                    'usd_scope' => ['required', Rule::in(array_keys(FinanceCreditUsdRates::SCOPES))],
                    'usd_currency' => ['required', 'regex:/^[A-Z]{3}$/', 'not_in:USD'],
                    'usd_month' => ['exclude_unless:usd_scope,month', 'required', 'date_format:Y-m'],
                    'usd_start' => ['exclude_if:usd_scope,month', 'required', 'date_format:Y-m-d'],
                    'usd_end' => ['exclude_unless:usd_scope,range', 'required', 'date_format:Y-m-d', 'after_or_equal:usd_start'],
                    'usd_rate' => ['required', 'numeric', 'regex:/^\d{1,10}(?:\.\d{1,8})?$/', 'gt:0'],
                ], [], [
                    'usd_scope' => '汇率适用范围', 'usd_currency' => '原币币种', 'usd_month' => '适用月份',
                    'usd_start' => '开始日期', 'usd_end' => '结束日期', 'usd_rate' => '美元汇率',
                ]);
                app(FinanceCreditUsdRates::class)->save($data, (int) $context['user']->getKey());
            } elseif ($action === 'rule') {
                $data = $request->validate([
                    'id' => 'nullable|integer|min:1', 'name' => 'required|string|max:100',
                    'source_field' => ['required', Rule::in($service::SOURCES)], 'match_type' => ['required', Rule::in(['equals', 'contains', 'prefix'])],
                    'match_value' => 'required|string|max:191', 'category' => 'required|string|max:100',
                    'direction' => ['required', Rule::in(['income', 'expense', 'neutral'])],
                    'loss_subject' => ['nullable', Rule::in($service::SUBJECTS)], 'priority' => 'required|integer|min:0|max:100000', 'is_enabled' => 'required|boolean',
                ]);
                $id = $data['id'] ?? null;
                unset($data['id']);
                if ($id) {
                    abort_unless($db->table('finance_transaction_type_mappings')->where('id', $id)->exists(), 404);
                    $db->table('finance_transaction_type_mappings')->where('id', $id)->update([...$data, 'updated_by' => $context['user']->getKey(), 'updated_at' => now()]);
                } else {
                    $db->table('finance_transaction_type_mappings')->insert([...$data, 'updated_by' => $context['user']->getKey(), 'created_at' => now(), 'updated_at' => now()]);
                }
            } else {
                $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
                if ($action === 'rate') {
                    $data += $request->validate([
                        'currency' => ['required', 'regex:/^[A-Z]{3}$/'], 'cny_rate' => ['required', 'numeric', 'regex:/^\d{1,10}(?:\.\d{1,8})?$/', 'gt:0'],
                        'source' => 'nullable|string|max:255',
                    ]);
                    if ($data['currency'] === 'CNY' && bccomp($data['cny_rate'], '1', 8) !== 0) {
                        throw ValidationException::withMessages(['cny_rate' => __('finance.accounting.cny_fixed')]);
                    }
                    $service->saveRate($data, (int) $context['user']->getKey());
                } elseif ($action === 'reapply') {
                    $service->reapply($data['month']);
                } elseif ($action === 'lock') {
                    $service->lock($data['month'], (int) $context['user']->getKey());
                } else {
                    abort(404);
                }
            }
        } catch (\App\Portals\Finance\FinanceRelayException $e) {
            throw ValidationException::withMessages(['month' => $e->getMessage()]);
        }

        return redirect()->route('finance.accounting')->with('success', __('finance.accounting.saved'));
    }

    public function paypalSettlementDetail(Request $request, int $record): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }

        $timezone = $request->validate([
            'timezone' => ['nullable', Rule::in(FinanceWorkbookService::TIMEZONES)],
        ])['timezone'] ?? 'Asia/Shanghai';
        $row = app(\App\Portals\Finance\FinancePayPalSettlementOrders::class)->detailRow($record, $timezone);
        abort_if($row === null, 404);

        return response()->view('portals.finance.partials.paypal-settlement-events', ['row' => $row])
            ->header('Cache-Control', 'private, no-store');
    }

    public function report(Request $request, string $report): Response
    {
        if (in_array($report, ['paypal-settlement', 'credit-settlement', ...array_keys(FinanceCreditSettlementLayout::REPORT_CHANNELS)], true)) {
            return $this->settlement($request, $report);
        }
        if ($report === 'losses' || ($report === 'summary' && $request->query('view') !== 'transactions')) {
            return $this->settlementOverview($request, $report);
        }
        abort_unless(in_array($report, ['summary', 'losses'], true), 404);
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }

        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
            'page' => 'nullable|integer|min:1', 'payment_account_id' => 'nullable|integer|min:1',
            'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'], 'channel' => ['nullable', Rule::in(array_keys(\App\Portals\Finance\FinanceTransactionSummary::VIEWS))],
            'team' => 'nullable|string|max:100', 'subscription' => 'nullable|string|max:100', 'download' => 'nullable|boolean',
            'conditions' => 'nullable|array|max:10', 'conditions.*' => 'array:field,operator,value',
            'conditions.*.field' => ['required', Rule::in(array_keys(\App\Portals\Finance\FinanceSettlementService::CONDITION_FIELDS))],
            'conditions.*.operator' => 'required|in:equals', 'conditions.*.value' => 'required|string|max:191',
        ]);
        $tab = $filters['channel'] ?? 'paypal';
        $accountMatchesTab = static function (FinancePaymentAccount $account, string $tab): bool {
            return match ($tab) {
                'combined' => true,
                'paypal' => $account->account_type === 'paypal',
                'credit_card' => $account->account_type === 'credit_card'
                    && FinanceCreditSettlementLayout::accountPlatform($account) === null,
                default => $account->account_type === 'credit_card'
                    && FinanceCreditSettlementLayout::accountPlatform($account) === $tab,
            };
        };
        $creditMonths = $tab === 'credit_card' ? $this->creditImportMonths($filters['payment_account_id'] ?? null) : [];
        $filters['month'] ??= $creditMonths[0] ?? now('Asia/Shanghai')->format('Y-m');
        $accounts = FinancePaymentAccount::query()->orderBy('account_name')->get();
        $account = $accounts->firstWhere('id', $filters['payment_account_id'] ?? null);
        $month = CarbonImmutable::parse($filters['month'].'-01', 'Asia/Shanghai');
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $summary = app(\App\Portals\Finance\FinanceTransactionSummary::class);
        if ($request->has('suggestion_field')) {
            $rows = $this->transactionSummaryRows($service, $tab, $filters, $month, $account, reuse: true);
            $suggestion = $request->validate([
                'suggestion_field' => ['required', Rule::in(array_keys($service::CONDITION_FIELDS))],
                'suggestion_q' => 'nullable|string|max:191',
            ]);
            $search = FinanceCatalog::simplified($suggestion['suggestion_q'] ?? '');
            return response()->json($rows->pluck($suggestion['suggestion_field'])
                ->filter(fn ($value) => $value !== null && $value !== '' && mb_stripos(FinanceCatalog::simplified((string) $value), $search) !== false)
                ->unique()->sort()->take(20)->values()
                ->map(fn ($value) => ['value' => (string) $value, 'label' => FinanceCatalog::simplified((string) $value)]));
        }
        $columns = $summary->columns($tab);
        $scope = \Illuminate\Support\Arr::except($filters, ['page', 'per_page', 'download']);
        ksort($scope);
        $snapshots = app(\App\Portals\Finance\FinanceReportSnapshots::class);
        [[$items, $subscriptionOptions], $reportSnapshot, $reportStatus] = $snapshots->result('transactions', $filters['month'], [...$scope, 'channel' => $tab],
            fn () => $this->buildTransactionReport($tab, $scope, $month, $account),
            $request->boolean('download') ? $request->query('report_snapshot') : null);
        if ($request->boolean('download')) {
            abort_if($snapshots->runsInBackground() && $reportSnapshot === null, 409, '报表正在生成，请稍后重新查询。');
            $export = \App\Portals\Finance\FinanceExportSelection::filter($request, $items)->map(fn ($row) => array_map(fn ($field) => $row[$field] ?? null, array_keys($columns)));

            return $this->workbooks->download(array_values($columns), $export, 'finance-transactions-'.$tab.'-'.$filters['month'].'.xlsx');
        }
        $subscriptionOptions = collect($subscriptionOptions);
        $teamOptions = collect($items)->pluck('team')->filter()->unique()->sort()->values();
        $perPage = (int) ($filters['per_page'] ?? 10);
        $page = min((int) ($filters['page'] ?? 1), max(1, (int) ceil(count($items) / $perPage)));
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            array_slice($items, ($page - 1) * $perPage, $perPage), count($items), $perPage, $page,
            ['path' => $request->url(), 'query' => [...\Illuminate\Support\Arr::except($filters, ['page', 'download']), 'view' => 'transactions', 'channel' => $tab]]
        );
        $items = $paginator->items();
        $tabUrls = [];
        foreach (\App\Portals\Finance\FinanceTransactionSummary::VIEWS as $key => $label) {
            $query = \Illuminate\Support\Arr::except($filters, ['download', 'channel', 'page']);
            if ($key === 'credit_card' && ! $request->filled('month')) {
                unset($query['month']);
            }
            if ($account && ! $accountMatchesTab($account, $key)) {
                unset($query['payment_account_id']);
            }
            $tabUrls[$key] = route('finance.report', ['report' => $report, ...$query, 'view' => 'transactions', 'channel' => $key]);
        }

        return response()->view('portals.finance.report', [
            ...$context, 'report' => $report, 'filters' => $filters, 'columns' => $columns, 'items' => $items, 'paginator' => $paginator,
            'reportSnapshot' => $reportSnapshot, 'reportStatus' => $reportStatus, 'tab' => $tab, 'tabUrls' => $tabUrls, 'summaryTabs' => \App\Portals\Finance\FinanceTransactionSummary::VIEWS,
            'creditMonths' => $creditMonths,
            'currencyOptions' => FinanceRecord::query()->whereIn('dataset', ['paypal-orders', 'xuzong2-orders', 'xuzong2-details', 'xuzong1-details'])
                ->whereNotNull('currency')->distinct()->pluck('currency')
                ->map(fn ($currency) => strtoupper(trim($currency)))
                ->filter(fn ($currency) => preg_match('/^[A-Z]{3}$/D', $currency))->unique()->sort()->values(),
            'subscriptionOptions' => $subscriptionOptions,
            'teamOptions' => $teamOptions,
            'conditionFields' => $service::CONDITION_FIELDS,
            'reportAccounts' => $accounts->filter(fn ($account) => $accountMatchesTab($account, $tab) || $account->id === ($filters['payment_account_id'] ?? null)),
            'reportTabUrls' => $this->reportTabUrls([...$filters, 'channel' => $tab]),
            'isLocked' => $this->workbooks->database()->table('finance_period_locks')->where('month', $filters['month'])->whereNotNull('report_snapshot')->exists(),
        ]);
    }

    public function buildTransactionReport(string $tab, array $scope, CarbonImmutable $month, ?FinancePaymentAccount $account): array
    {
        $service = app(FinanceSettlementService::class);
        $summary = app(\App\Portals\Finance\FinanceTransactionSummary::class);
        $rows = $this->transactionSummaryRows($service, $tab, $scope, $month, $account, reuse: true)
            ->filter(fn ($row) => $service->matches($row, $scope['conditions'] ?? []));

        $subscriptions = [];
        $source = (function () use ($rows, &$subscriptions) {
            foreach ($rows as $row) {
                $subscriptions[$row['subscription']] = ['subscription' => $row['subscription']];
                yield $row;
            }
        })();
        return [$summary->summarize($service->logisticsCostStream($source), $tab), $service->subscriptionOptions(collect($subscriptions))->all()];
    }

    private function transactionSummaryRows(FinanceSettlementService $service, string $tab, array $filters, CarbonImmutable $month, ?FinancePaymentAccount $account, bool $reuse): \Illuminate\Support\LazyCollection
    {
        return \Illuminate\Support\LazyCollection::make(function () use ($service, $tab, $filters, $month, $account, $reuse) {
        $channels = $tab === 'combined' ? ['paypal', 'credit_card'] : [$tab === 'paypal' ? 'paypal' : 'credit_card'];
        foreach ($channels as $channel) {
            if (isset($filters['payment_account_id']) && (! $account || $account->account_type !== $channel)) {
                continue;
            }
            $scope = [
                'from' => $month->toDateString(), 'to' => $month->endOfMonth()->toDateString(),
                'timezone' => 'Asia/Shanghai', 'account' => $account?->activity_account,
                'team' => $filters['team'] ?? null, 'subscription' => $filters['subscription'] ?? null,
                'logistics_costs' => true, 'include_unmatched_fees' => true,
            ];
            $projection = $reuse ? app(\App\Portals\Finance\FinanceSummaryProjection::class)
                ->stream($channel, $scope, true) : null;
            $channelRows = $projection ?? $service->summaryRowStream($channel, $scope, transactions: true);
            foreach ($channelRows as $row) {
                $paymentChannel = $row['payment_channel'];
                if ($tab === 'combined' && ! FinanceSettlementService::isShopifySummaryChannel($paymentChannel)) {
                    continue;
                }
                if ($tab === 'credit_card' && ! in_array($paymentChannel, ['xuzong1', 'xuzong2'], true)
                    || in_array($tab, ['ye', 'worldpay'], true) && $paymentChannel !== $tab) {
                    continue;
                }
                if (empty($filters['currency']) || $row['currency'] === $filters['currency']) {
                    yield $row;
                }
            }
        }
        });
    }

    private function settlementFilters(Request $request, array $conditionFields): array
    {
        return [...$request->validate([
            'account' => 'nullable|string|max:191', 'reference' => 'nullable|string|max:191',
            'gateway' => 'nullable|string|max:191',
            'worldpay_exception' => ['nullable', Rule::in(['yes', 'no'])],
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'timezone' => ['nullable', Rule::in(FinanceWorkbookService::TIMEZONES)],
            'team' => 'nullable|string|max:100', 'subscription' => 'nullable|string|max:100', 'domain' => 'nullable|string|max:191',
            'order_status' => ['nullable', Rule::in(['已付款', '付款失败', '付款待确认', '全额退款', '部分退款', '退款待确认', '仍有冻结', '冻结已解除', '返还待核对', '待关联交易'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
            'source' => ['nullable', Rule::in(['xuzong2-orders', 'xuzong2-details', 'xuzong1-details'])],
            'card_channel' => ['nullable', Rule::in(array_keys(FinanceCreditSettlementLayout::CHANNELS))],
            'card_exceptions' => 'nullable|boolean',
            'card_wallet' => 'nullable|boolean',
            'card_match' => ['nullable', Rule::in(['matched', 'waiting'])],
            'ledger_settlement_type' => ['nullable', Rule::in(['waiting', '正常结算', '停结算'])],
            'card_transaction_status' => ['nullable', Rule::in(array_keys(FinanceSettlementService::CARD_TRANSACTION_STATUSES))],
            'card_group' => 'nullable|integer|min:1',
            'conditions' => 'nullable|array|max:10', 'conditions.*' => 'array:field,operator,value',
            'conditions.*.field' => ['required', Rule::in(array_keys($conditionFields))],
            'conditions.*.operator' => ['required', Rule::in(['equals','contains','not_equals','empty','not_empty','gt','gte','lt','lte'])],
            'conditions.*.value' => 'nullable|string|max:191',
            'format' => ['nullable', Rule::in(['xls', 'xlsx'])],
        ]), 'timezone' => $request->input('timezone', 'Asia/Shanghai') ?: 'Asia/Shanghai'];
    }

    public function settlement(Request $request, string $report): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }
        $channel = $report === 'paypal-settlement' ? 'paypal' : 'credit_card';
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $conditionFields = $service->conditionFields($channel);
        $numericConditionFields = $service->numericConditionFields($channel);
        $fixedCardChannel = FinanceCreditSettlementLayout::REPORT_CHANNELS[$report] ?? null;
        $filters = $this->settlementFilters($request, $conditionFields);
        if ($fixedCardChannel !== null) {
            $filters['card_channel'] = $fixedCardChannel;
        }
        if ($fixedCardChannel === 'worldpay') {
            unset($filters['account'], $filters['card_group'], $filters['source']);
        }
        if ($channel === 'credit_card' && isset($filters['conditions'])) {
            $filters['conditions'] = array_map(fn ($condition) => [...$condition, 'operator' => 'equals'], $filters['conditions']);
        }
        $cardLayout = $channel === 'credit_card' ? FinanceCreditSettlementLayout::columns($filters['card_channel'] ?? null) : [];
        if ($channel !== 'credit_card' || ($filters['card_channel'] ?? null) !== 'ye') {
            unset($filters['card_wallet'], $filters['card_exceptions'], $filters['card_match'], $filters['ledger_settlement_type']);
        }
        if ($channel !== 'credit_card') {
            unset($filters['card_group'], $filters['card_transaction_status']);
        }
        foreach ($filters['conditions'] ?? [] as $condition) {
            if (in_array($condition['operator'], ['gt','gte','lt','lte'], true)
                && (! in_array($condition['field'], $numericConditionFields, true) || $service->number($condition['value'] ?? null) === null)) {
                throw ValidationException::withMessages(['conditions' => '大小比较请选择数值字段并填写有效数字。']);
            }
        }
        $columns = $service->columns($channel);
        if ($request->boolean('template')) {
            if ($channel === 'paypal' && $request->input('import_kind') === 'tongtu_costs') {
                return $this->workbooks->download(FinanceSettlementService::PAYPAL_COST_HEADERS, [], 'paypal-tongtu-costs-template.xlsx');
            }
            $templateHeaders = array_values($cardLayout ?: ($channel === 'paypal'
                ? $service->columns($channel) : ['account' => '支付账户', ...array_combine(FinanceCatalog::dataset('xuzong2-details')['headers'], FinanceCatalog::dataset('xuzong2-details')['headers']), 'usd_rate' => '汇率']));
            return $this->workbooks->download($templateHeaders, [], $report.'-template.xlsx');
        }
        $paginator = null;
        $editorFields = array_diff_key($columns, $channel === 'paypal'
            ? ['id' => true, 'team' => true, 'operation_region' => true] : ['id' => true]);
        $orderView = $channel === 'paypal';
        $displayCardLayout = FinanceCreditSettlementLayout::displayColumns($channel === 'credit_card' ? ($filters['card_channel'] ?? null) : null);
        if ($displayCardLayout) {
            $displayCardLayout += FinanceSettlementService::SHOP_DIFFERENCE_COLUMN;
        }
        $columns = $displayCardLayout ?: $service->listColumns($channel);
        $exportColumns = $displayCardLayout ?: $service->exportColumns($channel);
        if ($channel === 'credit_card' && ! $displayCardLayout) {
            $columns = FinanceCreditSettlementLayout::withDisplayLabels($columns);
            $exportColumns = FinanceCreditSettlementLayout::withDisplayLabels($exportColumns);
        }
        if ($channel === 'credit_card' && ($filters['card_channel'] ?? null) === 'ye') {
            $columns = ['ledger_status' => '匹配状态', 'ledger_dates' => '账单日期', 'ledger_settlement_type' => '账单结算类型', ...$columns];
            $exportColumns += array_intersect_key($columns, array_flip(['ledger_status', 'ledger_dates', 'ledger_settlement_type']));
        }
        if (! empty($filters['card_exceptions'])) {
            $columns += FinanceConfluxSettlement::EXCEPTION_COLUMNS;
            $exportColumns += FinanceConfluxSettlement::EXCEPTION_COLUMNS;
        }
        if ($request->boolean('download')) {
            $requestedColumns = $request->validate([
                'export_columns' => 'sometimes|required|array|list|min:1|max:'.count($exportColumns),
                'export_columns.*' => ['required', 'string', 'distinct', Rule::in(array_keys($exportColumns))],
            ])['export_columns'] ?? array_keys(array_intersect_key($exportColumns, $columns));
            $downloadHeaders = array_map(fn ($field) => $exportColumns[$field], $requestedColumns);
            if ($request->boolean('export_template')) {
                $format = $filters['format'] ?? 'xlsx';
                return $this->workbooks->download($downloadHeaders, [], $report.'-export-template.'.$format, $format, plain: $orderView);
            }
            if ($orderView) {
                // Matching payment events can exceed the default PHP limit before the writer starts.
                $limit = ini_parse_quantity((string) ini_get('memory_limit'));
                if ($limit > 0 && $limit < 256 * 1024 ** 2) {
                    ini_set('memory_limit', '256M');
                }
            }
        }
        if ($request->boolean('download') && ($filters['card_channel'] ?? null) === 'ye' && $request->has('selected_rows')) {
            $selection = $request->validate(['export_record_ids' => 'sometimes|required|array|max:100', 'export_record_ids.*' => 'integer|min:1|distinct']);
            $filters += $selection;
        }
        $statementRows = fn () => empty($filters['export_record_ids']) ? app(FinanceConfluxSettlement::class)->unmatchedRows($filters) : [];
        $resultBatches = function () use ($service, $channel, $filters, $statementRows): iterable {
            yield from $service->rowBatches($channel, $filters);
            foreach (collect()->lazy()->concat($statementRows())->chunk(500) as $batch) {
                yield $batch;
            }
        };
        // Computed filters are evaluated in batches before counting and slicing the matches.
        $canPaginate = ! $request->boolean('download') && ! $request->has('suggestion_field')
            && empty($filters['card_wallet']) && empty($filters['card_exceptions']) && empty($filters['ledger_settlement_type'])
            && ! filled($filters['team'] ?? null) && ! filled($filters['subscription'] ?? null)
            && ! filled($filters['domain'] ?? null) && empty($filters['conditions']);
        if ($orderView) {
            $pageSize = (int) ($filters['per_page'] ?? 10);
            $paypalOrders = app(\App\Portals\Finance\FinancePayPalSettlementOrders::class);
            if ($request->boolean('download') || $request->has('suggestion_field')) {
                $rows = collect();
            } else {
                $page = max(1, (int) $request->query('page', 1));
                [$rows, $total, $subscriptions] = $paypalOrders->page($filters, ($page - 1) * $pageSize, $pageSize);
                $paginator = new \Illuminate\Pagination\LengthAwarePaginator($rows, $total, $pageSize, $page,
                    ['path' => $request->url(), 'query' => $request->query()]);
            }
        } elseif ($canPaginate) {
            $paginator = $service->query($channel, $filters)->orderByDesc('occurred_at')->orderByDesc('id')
                ->paginate((int) ($filters['per_page'] ?? 10))->withQueryString();
            $rows = $service->rows($channel, $filters, $paginator->getCollection());
            $paginator->setCollection($rows);
            if (($filters['card_channel'] ?? null) === 'ye') {
                $perPage = $paginator->perPage();
                $page = $paginator->currentPage();
                $orderTotal = $paginator->total();
                $skip = max(0, ($page - 1) * $perPage - $orderTotal);
                [$statementTotal, $statementPage] = app(FinanceConfluxSettlement::class)
                    ->unmatchedPage($filters, $skip, $perPage - $rows->count());
                $rows = $rows->concat($statementPage);
                $paginator = new \Illuminate\Pagination\LengthAwarePaginator($rows, $orderTotal + $statementTotal, $perPage, $page,
                    ['path' => $request->url(), 'query' => $request->query()]);
            }
        } elseif (! $request->boolean('download') && ! $request->has('suggestion_field')) {
            $perPage = (int) ($filters['per_page'] ?? 10);
            $page = max(1, (int) $request->query('page', 1));
            $offset = ($page - 1) * $perPage;
            $total = 0;
            $rows = collect();
            $subscriptions = [];
            foreach ($resultBatches() as $batch) {
                foreach ($batch as $row) {
                    $subscriptions[(string) ($row['subscription'] ?? '')] = ['subscription' => $row['subscription'] ?? null];
                    if ($total >= $offset && $rows->count() < $perPage) {
                        $rows->push($row);
                    }
                    $total++;
                }
            }
            $paginator = new \Illuminate\Pagination\LengthAwarePaginator($rows, $total, $perPage, $page,
                ['path' => $request->url(), 'query' => $request->query()]);
        } else {
            $rows = collect();
        }
        if ($request->has('suggestion_field')) {
            $suggestion = $request->validate([
                'suggestion_field' => ['required', Rule::in(array_keys($conditionFields))],
                'suggestion_q' => 'nullable|string|max:191',
            ]);
            $search = FinanceCatalog::simplified($suggestion['suggestion_q'] ?? '');
            $values = collect();
            $suggestionBatches = $orderView
                ? \Illuminate\Support\LazyCollection::make(fn () => $paypalOrders->exportRows($filters))->chunk(500)
                : $resultBatches();
            foreach ($suggestionBatches as $batch) {
                $values = $values->concat($batch->pluck($suggestion['suggestion_field'])->filter(fn ($value) => $value !== null && $value !== ''
                    && ($search === '' || mb_stripos(FinanceCatalog::simplified((string) $value), $search) !== false)))
                    ->unique()->sort()->take(20)->values();
            }

            return response()->json($values->map(fn ($value) => ['value' => (string) $value, 'label' => FinanceCatalog::simplified((string) $value)]));
        }
        if ($request->boolean('download')) {
            $format = $filters['format'] ?? 'xlsx';
            if ($orderView) {
                $exportRows = \Illuminate\Support\LazyCollection::make(
                    fn () => app(\App\Portals\Finance\FinancePayPalSettlementOrders::class)->exportRows($filters),
                );
                $selected = \App\Portals\Finance\FinanceExportSelection::keys($request);
                if ($selected !== null) {
                    $exportRows = \App\Portals\Finance\FinanceExportSelection::filter($request,
                        $exportRows->filter(fn ($row) => in_array(\App\Portals\Finance\FinanceExportSelection::key($row), $selected, true))->take(count($selected))->all())->lazy();
                }
            } else {
                $exportRows = \Illuminate\Support\LazyCollection::make(function () use ($resultBatches): iterable {
                    foreach ($resultBatches() as $batch) {
                        yield from $batch->values()->all();
                    }
                });
                $selected = \App\Portals\Finance\FinanceExportSelection::keys($request);
                if ($selected !== null) {
                    $exportRows = \App\Portals\Finance\FinanceExportSelection::filter($request,
                        $exportRows->filter(fn ($row) => in_array(\App\Portals\Finance\FinanceExportSelection::key($row), $selected, true))->all())->lazy();
                }
            }
            if ($format === 'xls' && $exportRows->take(65536)->count() > 65535) {
                throw ValidationException::withMessages(['format' => 'XLS最多65535条，请使用XLSX。']);
            }
            $additionalSheets = [];
            if ($fixedCardChannel === 'worldpay') {
                $allocation = new \App\Portals\Finance\FinanceWorldpayAllocation;
                $exportRows = $exportRows->map($allocation->add(...));
                $additionalSheets[] = ['失败单分摊', $allocation::HEADERS, $allocation->rows(...)];
            }
            return $this->workbooks->download($downloadHeaders, $exportRows->map(fn ($row) => array_map(
                fn ($field) => match (true) {
                    ! $orderView => ($row['card_layout'] ?? $row)[$field] ?? '',
                    $field === 'paypal_18' => FinanceProductNames::categories((string) ($row[$field] ?? '')),
                    $field === 'review' && ($row['review'] ?? null) === FinancePayPalSettlementOrders::ACCOUNT_RESERVE_NOTE => '',
                    default => FinancePayPalSettlementOrders::splitRefundNote($row, $field) ?? ($row[$field] ?? ''),
                },
                $requestedColumns,
            )), $report.'.'.$format, $format, plain: $orderView,
                sheetTitle: empty($filters['card_exceptions']) ? 'Sheet1' : '异常汇总', additionalSheets: $additionalSheets);
        }
        $perPage = (int) ($filters['per_page'] ?? 10);
        $page = max(1, (int) $request->query('page', 1));
        $paginator ??= new \Illuminate\Pagination\LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);
        $accounts = FinancePaymentAccount::query()->where('account_type', $channel)->orderBy('id')->get();
        $filterAccounts = $accounts;
        if ($cardLayout && $accounts->isNotEmpty()) {
            $platform = $filters['card_channel'];
            [$configured, $other] = $accounts->partition(
                fn ($account) => FinanceCreditSettlementLayout::accountPlatform($account) === $platform
            );
            if ($other->isNotEmpty()) {
                $history = $service->query($channel, ['card_channel' => $platform])
                    ->whereIn('account', $other->pluck('activity_account'))->distinct()->pluck('account')->flip();
                $filterAccounts = $accounts->filter(fn ($account) => $configured->contains($account)
                    || $history->has($account->activity_account));
            }
        }
        if (filled($filters['card_group'] ?? null)) {
            $filterAccounts = $filterAccounts->where('card_group_id', (int) $filters['card_group']);
        }

        return response()->view('portals.finance.settlement', [...$context, 'report' => $report, 'channel' => $channel, 'filters' => $filters,
            'fixedCardChannel' => $fixedCardChannel,
            'rows' => $paginator, 'columns' => $columns, 'exportColumns' => $exportColumns,
            'moneyFields' => $service->moneyFields($channel), 'textFields' => $service->textFields($channel),
            'editorFields' => $editorFields, 'orderView' => $orderView,
            'editorNumericFields' => app(\App\Portals\Finance\FinanceSettlementEditor::class)->numericFields($channel),
            'conditionFields' => $conditionFields, 'numericConditionFields' => $numericConditionFields,
            'subscriptionOptions' => $service->subscriptionOptions(isset($subscriptions) ? collect($subscriptions) : $rows),
            'accounts' => $accounts, 'filterAccounts' => $filterAccounts,
            'cardGroups' => $channel === 'credit_card' ? FinanceCreditAccountGroup::query()->orderBy('name')->pluck('name', 'id') : collect(),
            'teams' => ($orderView ? $service->operationDepartments()->pluck('name') : $this->workbooks->domainGroups()->pluck('team'))
                ->filter()->unique()->sort()->values(),
        ]);
    }

    public function previewExportTemplate(Request $request, string $report): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }
        $formats = in_array($report, ['paypal-orders', 'xuzong-credit'], true) ? 'XLSX、XLS、CSV 或 TSV' : 'XLSX、XLS 或 CSV';
        $extensions = in_array($report, ['paypal-orders', 'xuzong-credit'], true) ? 'xlsx,xls,csv,tsv' : 'xlsx,xls,csv';
        $data = $request->validate([
            'file' => 'required|file|extensions:'.$extensions.'|max:5120',
            'header_row' => 'required|integer|min:1|max:1000',
        ], [
            'file.required' => '请选择模板文件。',
            'file.extensions' => '请选择 '.$formats.' 文件。',
            'file.max' => '模板文件不能超过 5 MB。',
            'header_row.*' => '表头行号必须在 1 到 1000 之间。',
        ]);
        if ($report === 'xuzong-credit') {
            return response()->json($this->workbooks->readExportTemplate($data['file'], (int) $data['header_row'], FinanceXuzongCreditReport::headers()));
        }
        $channel = $report === 'paypal-settlement' ? 'paypal' : 'credit_card';
        $cardChannel = FinanceCreditSettlementLayout::REPORT_CHANNELS[$report] ?? ($channel === 'credit_card' && $report !== 'paypal-orders'
            ? ($request->validate([
                'card_channel' => ['nullable', Rule::in(array_keys(FinanceCreditSettlementLayout::CHANNELS))],
            ])['card_channel'] ?? null) : null);
        $cardLayout = FinanceCreditSettlementLayout::displayColumns($cardChannel);
        $columns = $report === 'paypal-orders'
            ? \App\Portals\Finance\FinancePayPalOrderExport::pageHeaders($report)
            : ($cardLayout ?: app(\App\Portals\Finance\FinanceSettlementService::class)->exportColumns($channel));
        if (isset(FinanceCreditSettlementLayout::REPORT_CHANNELS[$report])) {
            $columns += FinanceSettlementService::SHOP_DIFFERENCE_COLUMN;
        }
        if ($cardLayout && $cardChannel === 'ye' && $request->boolean('card_exceptions')) {
            $columns += FinanceConfluxSettlement::EXCEPTION_COLUMNS;
        }

        $legacyColumns = FinanceCreditSettlementLayout::columns($cardChannel);
        if ($report === 'credit-settlement' && ! $cardLayout) {
            $legacyColumns = $columns;
            $columns = FinanceCreditSettlementLayout::withDisplayLabels($columns);
        }

        return response()->json($this->workbooks->readExportTemplate($data['file'], (int) $data['header_row'], $columns, $legacyColumns));
    }

    private function settlementValues(array $input, string $channel): array
    {
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $rules = array_fill_keys(array_keys($service->moneyFields($channel)), ['nullable', 'numeric', 'regex:/^\d{1,18}(?:\.\d{1,8})?$/', 'gte:0']);
        $rules['usd_rate'] = ['nullable', 'numeric', 'regex:/^\d{1,10}(?:\.\d{1,8})?$/', 'gt:0'];
        if ($channel === 'credit_card') {
            $rules['settlement'] = ['nullable', 'numeric', 'regex:/^\d{1,18}(?:\.\d{1,8})?$/', 'gte:0'];
        }
        foreach ($service->textFields($channel) as $field => $label) {
            $rules[$field] = 'nullable|string|max:'.($field === 'note' ? 2000 : 191);
        }

        return validator($input, $rules)->validate();
    }

    public function saveSettlement(Request $request, string $report): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $channel = $report === 'paypal-settlement' ? 'paypal' : 'credit_card';
        $request->validate(['id' => 'required|integer|min:1']);
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $returnFilters = $this->settlementFilters(Request::create('/', 'GET', $request->query()), $service->conditionFields($channel));
        $page = filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]);
        if ($page !== false) {
            $returnFilters['page'] = $page;
        }
        $scope = ['card_channel' => FinanceCreditSettlementLayout::REPORT_CHANNELS[$report] ?? null];
        $record = $service->query($channel, $scope)->findOrFail($request->input('id'));
        try {
            app(\App\Portals\Finance\FinanceSettlementEditor::class)->save($record, $request->all(), $channel, (int) $context['user']->getKey());
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['reference' => '交易ID或对应支付记录已存在，不能重复。']);
        } catch (\App\Portals\Finance\FinanceRelayException $e) {
            throw ValidationException::withMessages(['month' => $e->getMessage()]);
        }

        if ($channel === 'paypal') {
            app(FinancePayPalSettlementOrders::class)->forgetOrderGraph();
        }

        return redirect()->route('finance.report', ['report' => $report, ...$returnFilters])->with('success', '结算明细已保存。');
    }

    public function deleteSettlement(Request $request, string $report): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $data = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|min:1|distinct']);
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $channel = $report === 'paypal-settlement' ? 'paypal' : 'credit_card';
        $scope = ['card_channel' => FinanceCreditSettlementLayout::REPORT_CHANNELS[$report] ?? null];
        try {
            $this->workbooks->database()->transaction(function () use ($data, $service, $channel, $scope, $context): void {
                $records = $service->query($channel, $scope)->whereIn('id', $data['ids'])->lockForUpdate()->get();
                $unlinkedIds = [];
                if ($channel === 'paypal' && $records->count() !== count($data['ids'])) {
                    $missing = array_diff($data['ids'], $records->modelKeys());
                    $orders = app(FinancePayPalSettlementOrders::class);
                    foreach (FinanceRecord::query()->whereIn('id', $missing)->lockForUpdate()->get() as $record) {
                        if ($orders->isUnlinkedSettlement($record->id)) {
                            $records->push($record);
                            $unlinkedIds[$record->id] = true;
                        }
                    }
                }
                if ($records->count() !== count($data['ids'])) {
                    throw ValidationException::withMessages(['ids' => '部分记录不存在、已删除或不属于当前结算明细，请刷新后重试。']);
                }
                foreach ($records as $record) {
                    $actor = (int) $context['user']->getKey();
                    $values = ['_deleted_at' => now()->toIso8601String(), '_deleted_by' => $actor];
                    if (isset($unlinkedIds[$record->id])) {
                        $values['_deleted_unlinked_event'] = true;
                    }
                    $service->save($record, $values, $actor);
                }
            });
        } catch (\App\Portals\Finance\FinanceRelayException $e) {
            throw ValidationException::withMessages(['ids' => $e->getMessage()]);
        }

        if ($channel === 'paypal') {
            app(FinancePayPalSettlementOrders::class)->forgetOrderGraph();
        }

        return redirect()->route('finance.report', $report)->with('success', '已删除 '.count($data['ids']).' 条结算明细，原始订单已保留。');
    }

    public function importSettlement(Request $request, string $report): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $channel = $report === 'paypal-settlement' ? 'paypal' : 'credit_card';
        if ($fixedCardChannel = FinanceCreditSettlementLayout::REPORT_CHANNELS[$report] ?? null) {
            $request->merge(['card_channel' => $fixedCardChannel]);
        }
        if ($report === 'worldpay-settlement' && ($request->has('files') || ! $request->hasFile('file'))) {
            return $this->importWorldpayFiles($request, $report, (int) $context['user']->getKey());
        }
        if ($channel === 'credit_card' && $request->input('card_channel') === 'ye' && $request->has('files')) {
            return $this->importConfluxFiles($request, $report, (int) $context['user']->getKey());
        }
        $request->validate(['file' => 'required|file|mimes:xlsx,csv,txt|max:10240']);
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        if ($channel === 'paypal') {
            if ($request->input('import_kind') === 'tongtu_costs') {
                return $this->importPayPalTongtuCosts($request, $service, (int) $context['user']->getKey());
            }
            return $this->importNewPayPalSettlements($request, $service, (int) $context['user']->getKey());
        }
        if ($request->input('import_kind') === 'conflux_report') {
            $data = $request->validate([
                'card_channel' => 'required|in:ye', 'account' => 'required|string|max:191',
                'card_group' => 'nullable|integer|min:1',
                'file' => 'required|file|mimes:xlsx',
            ]);
            $conflux = app(FinanceConfluxSettlement::class);
            try {
                $result = $conflux->import($conflux->read($request->file('file')), $data['account'],
                    (int) $context['user']->getKey(), isset($data['card_group']) ? (int) $data['card_group'] : null);
            } catch (\App\Portals\Finance\FinanceRelayException $e) {
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }

            return redirect()->route('finance.report', ['report' => $report, ...array_diff_key($data, ['file' => true])])
                ->with('success', '新增 '.$result['inserted'].' 条账单流水，'.$result['unmatched'].' 笔原交易待匹配。');
        }
        if ($request->filled('card_channel')) {
            return $this->importCreditChannelDetails($request, $report, (int) $context['user']->getKey());
        }
        if ($request->input('import_kind') === 'source') {
            abort_unless($channel === 'credit_card', 422);
            $data = $request->validate(['source' => ['required', Rule::in(['xuzong2-details', 'xuzong1-details'])],
                'account' => 'required|string|max:191', 'timezone' => ['required', Rule::in(FinanceWorkbookService::TIMEZONES)]]);
            abort_unless(FinancePaymentAccount::query()->where('account_type', 'credit_card')->where('activity_account', $data['account'])->exists(), 422);
            $definition = FinanceCatalog::dataset($data['source']);
            $rows = $this->workbooks->read($request->file('file'), $definition['headers'], null, FinanceCatalog::optionalImportHeaders($data['source']));
            try {
                $count = $this->workbooks->database()->transaction(function () use ($rows, $data, $context): int {
                    FinancePaymentAccount::query()->where('activity_account', $data['account'])->where('account_type', 'credit_card')->lockForUpdate()->firstOrFail();
                    $count = 0;
                    foreach ($rows as $index => $row) {
                        $added = $this->workbooks->importRecords($data['source'], [$index => $row], $data['account'], $data['timezone'], (int) $context['user']->getKey(), true);
                        if ($added) {
                            $record = FinanceRecord::query()->where('dataset', $data['source'])->where('account', $data['account'])->orderByDesc('id')->firstOrFail();
                            app(\App\Portals\Finance\FinanceAccountingService::class)->guardUnlocked($record->occurred_at->setTimezone('Asia/Shanghai')->format('Y-m'));
                            $count++;
                        }
                    }

                    return $count;
                });
            } catch (\App\Portals\Finance\FinanceRelayException $e) {
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }

            return redirect()->route('finance.report', $report)->with('success', '已新增 '.$count.' 条信用卡明细，跳过重复 '.(count($rows) - $count).' 条。');
        }
        $legacyFields = [...$service->textFields($channel), ...$service->moneyFields($channel)];
        $fields = $service->importFields($channel);
        $sourceHeaders = $channel === 'credit_card' ? ['交易单号', '交易时间', '处理时间', '商户名称', 'Source（域名）', '交易币种', '交易金额', '支付状态', '发行国', '拒绝原因', '邮箱', '年月', '（-8）时间点', '订阅类型', '结算-美元'] : [];
        $importHeaders = array_values(array_unique(['记录ID', '支付账户', ...array_values($fields), ...array_values($legacyFields), ...$sourceHeaders,
            ...($channel === 'credit_card' ? [...FinanceCatalog::dataset('xuzong2-details')['headers'], ...array_values($service->columns($channel))] : [])]));
        // The credit card template calculates this total from the imported amounts.
        $importHeaders = array_values(array_diff($importHeaders, ['应结算']));
        $rows = $this->workbooks->read($request->file('file'), $importHeaders, $channel === 'paypal' ? '贝宝导入模版' : '徐总支付导入模版', $importHeaders);
        $scope = $request->validate(['account' => 'nullable|string|max:191']);
        foreach ($rows as &$row) {
            foreach (['平台订单号' => '交易单号', '创建时间' => '交易时间', '交易币种' => '原币币种', '交易金额' => '原币总额', '导出时区' => '时区', '结算金额' => '结算金额（美元）',
                '域名' => 'Source（域名）', '完成时间' => '处理时间', '发卡国家' => '发行国', '消费者邮箱' => '邮箱'] as $target => $source) {
                if (($row[$target] ?? '') === '' && filled($row[$source] ?? null)) {
                    $row[$target] = $row[$source];
                }
            }
            foreach ($fields as $field => $label) {
                $alias = $service->columns($channel)[$field] ?? null;
                if (($row[$label] ?? '') === '' && $alias && filled($row[$alias] ?? null)) {
                    $row[$label] = $row[$alias];
                }
            }
        }
        unset($row);
        if ($channel === 'credit_card' && collect($rows)->contains(fn ($row) => filled($row['创建时间'] ?? null)
            || filled($row['时间点'] ?? null) || filled($row['（-8）时间点'] ?? null))) {
            $account = FinancePaymentAccount::query()->where('account_type', 'credit_card')
                ->where('activity_account', $scope['account'] ?? '')->first();
            if (! $account) {
                throw ValidationException::withMessages(['account' => '请选择信用卡支付账户。']);
            }
            try {
                $count = $this->workbooks->database()->transaction(function () use ($rows, $account, $context, $service, $fields): int {
                    FinancePaymentAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
                    $count = 0;
                    foreach ($rows as $index => $row) {
                        $added = $this->workbooks->importRecords('xuzong2-details', [$index => $row], $account->activity_account, 'Asia/Shanghai', (int) $context['user']->getKey(), true);
                        if (! $added) {
                            continue;
                        }
                        $record = FinanceRecord::query()->where('dataset', 'xuzong2-details')->where('account', $account->activity_account)->orderByDesc('id')->firstOrFail();
                        $values = [];
                        foreach ($fields as $field => $label) {
                            if (($row[$label] ?? '') !== '') {
                                $values[$field] = $row[$label];
                            }
                        }
                        $service->save($record, $this->settlementValues($values, 'credit_card'), (int) $context['user']->getKey());
                        $count++;
                    }

                    return $count;
                });
            } catch (\App\Portals\Finance\FinanceRelayException $e) {
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }

            return redirect()->route('finance.report', $report)->with('success', '已新增 '.$count.' 条信用卡明细，跳过重复 '.(count($rows) - $count).' 条。');
        }
        foreach ($rows as &$row) {
            if ($channel === 'credit_card' && $row['平台订单号'] === '') {
                $row['平台订单号'] = $row['交易单号'] ?? '';
            }
            if ($row['支付账户'] === '' && filled($scope['account'] ?? null)) {
                $row['支付账户'] = $scope['account'];
            }
            foreach ($legacyFields as $field => $label) {
                if (isset($fields[$field]) && $row[$fields[$field]] === '' && ($row[$label] ?? '') !== '') {
                    $row[$fields[$field]] = $row[$label];
                }
            }
        }
        unset($row);
        $db = $this->workbooks->database();
        $imported = [];
        $duplicates = 0;
        try {
            $db->transaction(function () use ($rows, $fields, $service, $channel, $context, &$imported, &$duplicates): void {
                $seen = [];
                foreach ($rows as $index => $row) {
                    // File record IDs never select an existing row for an import update.
                    $id = '';
                    if ($id === '') {
                        $reference = $row[$channel === 'paypal' ? '交易 ID' : '平台订单号'] ?? '';
                        $merchant = $channel === 'credit_card' ? ($row['商户订单号'] ?? '') : '';
                        $matches = FinanceRecord::query()->whereIn('dataset', ['xuzong2-orders', 'xuzong2-details', 'xuzong1-details']);
                        if ($reference !== '') {
                            $matches->where('reference', $reference);
                        }
                        if ($merchant !== '') {
                            $matches->where(fn ($query) => $query->where('payload->商户订单号', $merchant)->orWhere('payload->Shopify订单号', $merchant));
                        }
                        if (filled($row['支付账户'] ?? null)) {
                            $accounts = FinancePaymentAccount::query()->where('account_type', $channel)
                                ->where(fn ($query) => $query->where('account_name', $row['支付账户'])->orWhere('activity_account', $row['支付账户']))
                                ->pluck('activity_account');
                            $matches->whereIn('account', $accounts);
                        }
                        $candidates = $matches->limit(2)->get();
                        if ($candidates->isNotEmpty()) {
                            $duplicates++;
                            continue;
                        }
                        if ($channel === 'credit_card' && $reference !== '' && $merchant !== '') {
                            throw ValidationException::withMessages(['file' => '第'.$index.'行缺少订单时间，请填写交易时间或创建时间后再导入。']);
                        }
                        if (($reference === '' && $merchant === '') || $candidates->count() !== 1) {
                            throw ValidationException::withMessages(['file' => '第'.$index.'行交易或订单号无法唯一匹配，请核对单号及支付账户。']);
                        }
                        $id = (string) $candidates->first()->id;
                    }
                    if (! ctype_digit($id) || ($channel !== 'credit_card' && isset($seen[$id]))) {
                        throw ValidationException::withMessages(['file' => '第'.$index.'行记录ID无效或重复。']);
                    }
                    $record = $service->query($channel, [])->find($id);
                    if (! $record) {
                        $removed = $this->workbooks->database();
                        if ($removed->table('finance_settlement_supplements')->where('finance_record_id', $id)->whereNotNull('payload->_deleted_at')->exists()) {
                            throw ValidationException::withMessages(['file' => '第'.$index.'行记录 ID '.$id.' 已从结算明细删除，无法导入补充数据。请使用当前列表中的有效记录ID；补充导入不会新增或恢复已删除记录。']);
                        }
                        throw ValidationException::withMessages(['file' => '第'.$index.'行记录不属于当前结算明细。']);
                    }
                    $seen[$id] = true;
                    $values = [];
                    foreach ($fields as $field => $label) {
                        if (($row[$label] ?? '') !== '' && (! isset($service::MONEY[$field]) || $row[$label] !== '待补充')) {
                            $values[$field] = $row[$label];
                        }
                    }
                    $service->save($record, $this->settlementValues($values, $channel), (int) $context['user']->getKey());
                    $imported[$record->id] = $record;
                }
            });
        } catch (\App\Portals\Finance\FinanceRelayException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $route = ['report' => $report];
        $message = '已新增 '.count($imported).' 条结算明细，跳过重复 '.$duplicates.' 条。';
        if (count($imported) === 1) {
            $record = reset($imported);
            $route += ['reference' => $record->reference, 'account' => $record->account];
            $message .= '已定位记录 ID '.$record->id.'（原交易号：'.$record->reference.'）。';
        }

        return redirect()->route('finance.report', $route)->with('success', $message);
    }

    private function importPayPalTongtuCosts(Request $request, FinanceSettlementService $service, int $actor): Response
    {
        $filters = $request->validate(['account' => 'nullable|string|max:191']);
        $headers = [...FinanceSettlementService::PAYPAL_COST_HEADERS, '通途订单号', '通途单号',
            FinanceSettlementService::MONEY['logistics'], FinanceSettlementService::MONEY['purchase']];
        $rows = $this->workbooks->read($request->file('file'), $headers, optionalHeaders: $headers);
        $costs = [];
        foreach ($rows as $line => $row) {
            $order = $row['通途订单'] ?: ($row['通途订单号'] ?: $row['通途单号']);
            $values = array_filter([
                'logistics' => $row['物流-元'] !== '' ? $row['物流-元'] : $row[FinanceSettlementService::MONEY['logistics']],
                'purchase' => $row['采购成本-元'] !== '' ? $row['采购成本-元'] : $row[FinanceSettlementService::MONEY['purchase']],
            ], fn ($value) => $value !== '');
            if (blank($order) || $values === []) {
                throw ValidationException::withMessages(['file' => '第'.$line.'行请填写通途订单号和至少一项成本。']);
            }
            $values = $this->settlementValues($values, 'paypal');
            $prior = $costs[$order] ?? ['line' => $line, 'values' => []];
            foreach (array_intersect_key($values, $prior['values']) as $field => $value) {
                if (bccomp($prior['values'][$field], $value, 8) !== 0) {
                    throw ValidationException::withMessages([
                        'file' => '第'.$line.'行通途订单 '.$order.' 的'.FinanceSettlementService::MONEY[$field].'与文件内同单号的金额不一致。',
                    ]);
                }
            }
            $costs[$order] = ['line' => $prior['line'], 'values' => $prior['values'] + $values];
        }

        try {
            $this->workbooks->database()->transaction(function () use ($service, $filters, $costs, $actor): void {
                $payments = $service->paypalCostPayments(array_keys($costs), $filters);
                $editor = app(FinanceSettlementEditor::class);
                foreach ($costs as $order => $cost) {
                    $matches = $payments->get($order, collect());
                    if ($matches->count() !== 1) {
                        throw ValidationException::withMessages(['file' => '第'.$cost['line'].'行通途订单 '.$order.' 无法唯一匹配已付款的 PayPal 主单。']);
                    }
                    $editor->save($matches->sole(), $cost['values'], 'paypal', $actor);
                }
            });
        } catch (\App\Portals\Finance\FinanceRelayException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return redirect()->route('finance.report', ['report' => 'paypal-settlement', ...$filters])
            ->with('success', '已导入 '.count($costs).' 笔主单的物流或采购成本。');
    }

    private function importNewPayPalSettlements(Request $request, \App\Portals\Finance\FinanceSettlementService $service, int $actor): Response
    {
        $columns = $service->columns('paypal');
        $headers = [...array_values($columns), '团队'];
        $rows = $this->workbooks->read($request->file('file'), $headers, '贝宝导入模版', $headers);
        $created = [];
        $duplicates = 0;
        try {
            $this->workbooks->database()->transaction(function () use ($rows, $columns, $service, $actor, &$created, &$duplicates): void {
                foreach ($rows as $index => $row) {
                    $accountName = $row['支付账户'];
                    $accounts = FinancePaymentAccount::query()->where('account_type', 'paypal')
                        ->where(fn ($q) => $q->where('activity_account', $accountName)->orWhere('account_name', $accountName))->lockForUpdate()->limit(2)->get();
                    if ($accountName === '' || $accounts->count() !== 1) {
                        throw ValidationException::withMessages(['file' => '第'.$index.'行支付账户不存在或不唯一，请填写现有PayPal支付账户。']);
                    }
                    $account = $accounts->sole();
                    $reference = $row['交易 ID'];
                    if ($reference === '') {
                        throw ValidationException::withMessages(['file' => '第'.$index.'行交易ID不能为空。']);
                    }
                    if (FinanceRecord::query()->where('dataset', 'paypal-orders')->where('reference', $reference)->exists()) {
                        $duplicates++;
                        continue;
                    }
                    $payload = array_fill_keys(FinanceCatalog::dataset('paypal-orders')['headers'], '');
                    foreach (array_slice(array_keys($payload), 0, 15) as $offset => $header) {
                        $key = $offset === 11 ? 'reference' : 'paypal_'.$offset;
                        $payload[$header] = $row[$columns[$key]];
                    }
                    $type = \App\Portals\Finance\FinancePayPalEventTypes::label($payload['類型']);
                    $code = collect(\App\Portals\Finance\FinancePayPalEventTypes::TYPES)->search(fn ($definition) => $definition[0] === $type && $definition[1] === '结账付款');
                    if ($code === false) {
                        throw ValidationException::withMessages(['file' => '第'.$index.'行类型必须为PayPal结账付款类型。']);
                    }
                    $payload['類型'] = $code;
                    $this->workbooks->database()->table('finance_transaction_types')->insertOrIgnore([
                        'raw_type' => $code, 'category' => '结账付款', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if ($this->workbooks->database()->table('finance_transaction_types')->where('raw_type', $code)->value('category') !== '结账付款') {
                        throw ValidationException::withMessages(['file' => '第'.$index.'行交易类型当前未归类为结账付款，请先核对类型分类。']);
                    }
                    foreach (['總額', '費用', '淨額'] as $field) {
                        if ($service->number($payload[$field]) === null) {
                            throw ValidationException::withMessages(['file' => '第'.$index.'行'.FinanceCatalog::simplified($field).'必须填写有效金额。']);
                        }
                    }
                    $zone = match (strtoupper(trim($payload['時區']))) {
                        '' => 'Asia/Shanghai',
                        'PDT' => '-07:00',
                        'PST' => '-08:00',
                        default => trim($payload['時區']),
                    };
                    $count = $this->workbooks->importRecords('paypal-orders', [$index => $payload], $account->activity_account, $zone, $actor, true);
                    if ($count === 0) {
                        $duplicates++;
                        continue;
                    }
                    $record = FinanceRecord::query()->where('dataset', 'paypal-orders')->where('account', $account->activity_account)->where('reference', $reference)->sole();
                    $values = [];
                    foreach ([...$service->importFields('paypal'), 'fee' => '手续费'] as $field => $label) {
                        if (($row[$label] ?? '') !== '') {
                            $values[$field] = $row[$label];
                        }
                    }
                    $service->save($record, $this->settlementValues($values, 'paypal'), $actor);
                    $created[] = $record;
                }
            });
        } catch (\App\Portals\Finance\FinanceRelayException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        $route = ['report' => 'paypal-settlement'];
        if (count($created) === 1) {
            $route += ['reference' => $created[0]->reference, 'account' => $created[0]->account];
        }

        return redirect()->route('finance.report', $route)->with('success', '已新增 '.count($created).' 条结算明细，跳过重复 '.$duplicates.' 条。新记录ID由系统生成。');
    }

    private function importConfluxFiles(Request $request, string $report, int $actor): Response
    {
        set_time_limit(180);
        $memoryLimit = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($memoryLimit > 0 && $memoryLimit < 384 * 1024 ** 2) {
            ini_set('memory_limit', '384M');
        }
        $data = $request->validate([
            'timezone' => ['required', Rule::in(FinanceWorkbookService::TIMEZONES)],
            'settlement_type' => ['nullable', Rule::in(['正常结算', '停结算'])],
            'files' => 'required|array|list|min:1|max:5',
            'files.*' => 'required|file|extensions:xlsx,csv,txt|max:20480',
        ], [
            'files.*.uploaded' => '文件上传失败，当前服务器单文件上传限制为 '.ini_get('upload_max_filesize').'。',
            'files.*.max' => '单个 ConfluxAPI 文件不得超过 20 MB。',
        ]);
        $files = $data['files'];
        unset($data['files']);
        $data['card_channel'] = 'ye';
        $conflux = app(FinanceConfluxSettlement::class);
        $batch = [];
        foreach ($files as $index => $file) {
            try {
                $statement = null;
                if ($conflux->isStatement($file)) {
                    if (empty($data['settlement_type'])) {
                        throw ValidationException::withMessages(['file' => '请选择账单的结算类型：正常结算或停结算。']);
                    }
                    $statement = $conflux->read($file, $data['settlement_type']);
                }
                $batch[] = ['index' => $index, 'file' => $file, 'statement' => $statement];
            } catch (ValidationException|\PhpOffice\PhpSpreadsheet\Exception $e) {
                $message = $e instanceof ValidationException ? $e->getMessage() : '无法识别文件。';
                throw ValidationException::withMessages(['files.'.$index => $file->getClientOriginalName().'：'.$message]);
            }
        }
        // All orders are available before looking up statement references, regardless of selection order.
        usort($batch, fn ($left, $right) => ($left['statement'] !== null) <=> ($right['statement'] !== null));
        $counts = $this->workbooks->database()->transaction(function () use ($batch, $data, $actor, $conflux): array {
            $accounts = FinancePaymentAccount::query()->where('account_type', 'credit_card')->where('card_platform', 'ye')
                ->whereNotNull('card_merchant_key')->orderBy('id')->lockForUpdate()->get();
            $statementAccounts = [];
            foreach ($batch as ['index' => $index, 'file' => $file, 'statement' => $statement]) {
                if ($statement === null) {
                    continue;
                }
                try {
                    $account = $conflux->findOrCreateStatementAccount($accounts, $statement, $actor);
                    $statementAccounts[$index] = $account;
                    if (! $accounts->contains('id', $account->id)) {
                        $accounts->push($account);
                    }
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['files.'.$index => $file->getClientOriginalName().'：'.$e->getMessage()]);
                }
            }

            $counts = ['orders' => 0, 'duplicates' => 0, 'statements' => 0, 'entries' => 0,
                'repeated_statements' => 0, 'statements_with_unmatched' => 0];
            foreach ($batch as ['index' => $index, 'file' => $file, 'statement' => $statement]) {
                try {
                    if ($statement) {
                        $account = $statementAccounts[$index];
                        $result = $conflux->import($statement, $account->activity_account, $actor);
                        $counts['statements']++;
                        $counts['entries'] += $result['inserted'];
                        $counts['repeated_statements'] += (int) ($result['inserted'] === 0);
                        $counts['statements_with_unmatched'] += (int) ($result['unmatched'] > 0);
                    } else {
                        $readerType = strtolower($file->getClientOriginalExtension()) === 'xlsx'
                            ? \PhpOffice\PhpSpreadsheet\IOFactory::READER_XLSX
                            : \PhpOffice\PhpSpreadsheet\IOFactory::READER_CSV;
                        $account = null;
                        $websites = [];
                        foreach ($this->creditChannelOrderBatches($file, 'ye', [$readerType]) as $rows) {
                            foreach (array_unique(array_column($rows, '网站名称')) as $website) {
                                $website = trim((string) $website);
                                $matched = $websites[$website] ??= $conflux->resolveAccount($accounts, $website, website: true, actor: $actor);
                                if ($account !== null && $account->id !== $matched->id) {
                                    throw ValidationException::withMessages(['file' => '此订单文件包含多个 Conflux 商户，请按商户分别导出。']);
                                }
                                $account = $matched;
                            }
                            $added = $this->workbooks->importRecords('xuzong2-details', $rows, $account->activity_account,
                                $data['timezone'], $actor, createOnly: true, guardSettlementMonth: true);
                            $counts['orders'] += $added;
                            $counts['duplicates'] += count($rows) - $added;
                        }
                        unset($rows);
                    }
                } catch (ValidationException|\App\Portals\Finance\FinanceRelayException|\PhpOffice\PhpSpreadsheet\Exception $e) {
                    throw ValidationException::withMessages(['files.'.$index => $file->getClientOriginalName().'：'.$e->getMessage()]);
                }
            }

            return $counts;
        });

        $message = '新增订单 '.$counts['orders'].' 笔，跳过重复订单 '.$counts['duplicates'].' 笔。';
        if ($counts['statements']) {
            $message .= '识别结算报表 '.$counts['statements'].' 份，新增账单流水 '.$counts['entries'].' 条，跳过重复报表 '.$counts['repeated_statements'].' 份。';
        }
        if ($counts['statements_with_unmatched']) {
            $message .= '其中 '.$counts['statements_with_unmatched'].' 份报表仍有待匹配交易，后续可补导订单。';
        }

        unset($data['settlement_type']);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'redirect' => route('finance.report', ['report' => $report, ...$data])]);
        }

        return redirect()->route('finance.report', ['report' => $report, ...$data])->with('success', $message);
    }

    private function lockCreditImportAccount(array $data): FinancePaymentAccount
    {
        $account = FinancePaymentAccount::query()->where('account_type', 'credit_card')
            ->where('activity_account', $data['account'])->lockForUpdate()->first();
        if (! $account) {
            throw ValidationException::withMessages(['account' => '请选择有效的信用卡支付账户。']);
        }
        if ($account->card_platform !== $data['card_channel']) {
            throw ValidationException::withMessages(['account' => '所选支付账户不属于当前支付平台。']);
        }
        if (isset($data['card_group']) && (int) $account->card_group_id !== (int) $data['card_group']) {
            throw ValidationException::withMessages(['account' => '所选账户不属于当前归属主体。']);
        }

        return $account;
    }

    private function creditChannelOrderBatches(\Illuminate\Http\UploadedFile $file, string $channel, ?array $readerTypes = null): \Generator
    {
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($limit > 0 && $limit < 384 * 1024 ** 2) {
            ini_set('memory_limit', '384M');
        }
        $columns = FinanceCreditSettlementLayout::columns($channel);
        $optional = $channel === 'ye' ? array_keys(array_slice($columns, 21, preserve_keys: true)) : [];
        $sheetName = FinanceCreditSettlementLayout::SHEETS[$channel];
        $batches = $channel === 'ye' && strtolower($file->getClientOriginalExtension()) === 'xlsx'
            ? $this->workbooks->readConfluxOrderBatches($file, $columns, $sheetName, $optional)
            : [$this->workbooks->read($file, $columns, $sheetName, $optional, keyedHeaders: true, maxRows: 10000, readerTypes: $readerTypes)];
        foreach ($batches as $rows) {
            yield array_map(fn (array $row) => FinanceCreditSettlementLayout::importPayload($channel, $row), $rows);
        }
    }

    private function importCreditChannelDetails(Request $request, string $report, int $actor): Response
    {
        $directWorldpay = $report === 'worldpay-settlement';
        $data = $request->validate([
            'card_channel' => ['required', Rule::in(array_keys(FinanceCreditSettlementLayout::CHANNELS))],
            'card_group' => 'nullable|integer|min:1',
            'account' => $directWorldpay ? 'exclude' : 'required|string|max:191',
            'timezone' => ['required', Rule::in(FinanceWorkbookService::TIMEZONES)],
        ]);
        $file = $request->file('file');
        $worldpay = $data['card_channel'] === 'worldpay' ? app(\App\Portals\Finance\FinanceWorldpayImport::class) : null;
        $official = $worldpay?->read($file);
        try {
            [$count, $total] = $this->workbooks->database()->transaction(function () use ($data, $file, $actor, $worldpay, $official, $directWorldpay): array {
                if ($directWorldpay) {
                    // Serialize direct uploads before resolving existing gateway/reference pairs.
                    $this->workbooks->database()->table('portals')->where('code', 'caiwu')->lockForUpdate()->first();
                }
                $account = $directWorldpay ? \App\Portals\Finance\FinanceWorldpayImport::SOURCE_ACCOUNT
                    : $this->lockCreditImportAccount($data)->activity_account;
                if ($official !== null) {
                    [$kind, $rows] = $official;
                    return [$worldpay->import($kind, $rows, $account, $data['timezone'], $actor), count($rows)];
                }
                $count = $total = 0;
                foreach ($this->creditChannelOrderBatches($file, $data['card_channel']) as $rows) {
                    $count += $this->workbooks->importRecords('xuzong2-details', $rows, $account,
                        $data['timezone'], $actor, createOnly: true, guardSettlementMonth: true);
                    $total += count($rows);
                }

                return [$count, $total];
            });
        } catch (\App\Portals\Finance\FinanceRelayException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return redirect()->route('finance.report', ['report' => $report, ...$data])
            ->with('success', $official === null
                ? '已新增 '.$count.' 条信用卡明细，跳过重复 '.($total - $count).' 条。'
                : '已新增或更新 '.$count.' 条 Worldpay 明细，跳过无变化 '.($total - $count).' 条。');
    }

    private function importWorldpayFiles(Request $request, string $report, int $actor): Response
    {
        $data = $request->validate([
            'files' => 'required|array|min:1|max:'.\App\Portals\Finance\FinanceWorldpayImport::MAX_UPLOAD_FILES,
            'files.*' => 'required|file|max:10240',
        ], [
            'files.required' => '没有收到 Worldpay 文件，请重新选择文件上传。',
            'files.*.uploaded' => 'Worldpay 文件上传失败，请检查文件大小是否超过服务器的上传限制。',
            'files.*.max' => 'Worldpay 单个文件不得超过 10 MB。',
        ]);
        $worldpay = app(\App\Portals\Finance\FinanceWorldpayImport::class);
        $sources = [];
        $skipped = 0;
        foreach ($data['files'] as $index => $file) {
            if (in_array($file->getClientOriginalName(), ['扣返款明细.csv', '商户结算费用汇总.xlsx'], true)) {
                $skipped++;
                continue;
            }
            $sources[$index] = $file;
        }
        try {
            [$count, $total] = $this->workbooks->database()->transaction(function () use ($sources, $worldpay, $actor): array {
                $this->workbooks->database()->table('portals')->where('code', 'caiwu')->lockForUpdate()->first();
                $count = $total = 0;
                foreach ($sources as $index => $file) {
                    try {
                        $batches = $worldpay->readBatches($file);
                        if (! $batches->valid()) {
                            throw ValidationException::withMessages(['file' => '无法识别 Worldpay 交易表头。']);
                        }
                        foreach ($batches as [$kind, $rows]) {
                            $count += $worldpay->import($kind, $rows, \App\Portals\Finance\FinanceWorldpayImport::SOURCE_ACCOUNT, 'Asia/Shanghai', $actor);
                            $total += count($rows);
                        }
                    } catch (ValidationException|\App\Portals\Finance\FinanceRelayException $e) {
                        throw ValidationException::withMessages(['files.'.$index => $file->getClientOriginalName().'：'.$e->getMessage()]);
                    }
                }
                return [$count, $total];
            });
        } catch (\App\Portals\Finance\FinanceRelayException $e) {
            throw ValidationException::withMessages(['files' => $e->getMessage()]);
        }
        return redirect()->route('finance.report', $report)
            ->with('success', '已处理 '.$total.' 条 Worldpay 记录，其中 '.$count.' 条新增或更新。'
                .($skipped ? '已跳过 '.$skipped.' 份批次对账文件。' : ''));
    }

    private function creditImportMonths(?int $accountId = null, ?string $account = null): array
    {
        return FinanceRecord::query()->whereIn('dataset', ['xuzong2-details', 'xuzong1-details'])
            ->when($accountId !== null, fn ($query) => $query->whereIn('account',
                FinancePaymentAccount::query()->whereKey($accountId)->select('activity_account')))
            ->when(filled($account), fn ($query) => $query->where('account', $account))
            ->select('occurred_at')->orderByDesc('occurred_at')->cursor()
            ->map(fn ($record) => $record->occurred_at->setTimezone('Asia/Shanghai')->format('Y-m'))
            ->unique()->values()->all();
    }

    private function settlementOverview(Request $request, string $report): Response
    {
        if ($request->query('channel') === 'xuzong') {
            $source = $request->validate(['xuzong_source' => 'nullable|in:xuzong1,xuzong2']);
            $request->query->set('channel', $source['xuzong_source'] ?? 'xuzong');
        }
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }
        $data = $request->validate(['month' => 'nullable|date_format:Y-m', 'month_from' => 'nullable|date_format:Y-m', 'month_to' => 'nullable|date_format:Y-m|after_or_equal:month_from', 'channel' => ['nullable', Rule::in(array_keys(FinanceSettlementService::REPORT_CHANNELS))],
            'layer' => ['nullable', Rule::in(['project', 'account', 'channel', 'team', 'subscription', 'detail', 'business', 'classification', 'project_classification', 'payment_method'])],
            'account' => 'nullable|string|max:191', 'owner' => 'nullable|string|max:191',
            'team' => 'nullable|string|max:100', 'subscription' => 'nullable|string|max:100', 'payment_method' => 'nullable|string|max:100']);
        // Older links used combined for all payment channels.
        if ($report === 'summary' && ($data['channel'] ?? '') === 'combined') {
            $data['channel'] = '';
            $data['layer'] ??= $report === 'losses' ? 'project_classification' : 'project';
        }
        $creditMonths = ($data['channel'] ?? null) === 'credit_card' ? $this->creditImportMonths(account: $data['account'] ?? null) : [];
        $month = $data['month_to'] ?? $data['month'] ?? $creditMonths[0] ?? now('Asia/Shanghai')->format('Y-m');
        if ($report === 'losses') {
            $data['layer'] ??= 'business';
            if ($data['layer'] === 'classification') {
                $data['layer'] = 'business';
            }
        }
        $comparisonMonths = [$month];
        if ($report === 'losses') {
            $monthFrom = $data['month_from'] ?? CarbonImmutable::parse($month.'-01', 'Asia/Shanghai')->subMonths(2)->format('Y-m');
            if ($monthFrom > $month) {
                throw \Illuminate\Validation\ValidationException::withMessages(['month_from' => '开始月份不能晚于结束月份。']);
            }
            $data['month_from'] = $monthFrom;
            $data['month_to'] = $month;
            $comparisonMonths = [];
            for ($period = CarbonImmutable::parse($monthFrom.'-01', 'Asia/Shanghai'); $period->format('Y-m') <= $month; $period = $period->addMonth()) {
                $comparisonMonths[] = $period->format('Y-m');
            }
        }
        if ($report === 'losses' && ($data['channel'] ?? null) === 'combined') {
            $data['layer'] = in_array($data['layer'] ?? '', ['business', 'classification', 'project_classification'], true)
                ? 'project_classification' : 'project';
        } elseif ($report === 'losses' && in_array($data['channel'] ?? null, ['paypal', 'credit_card'], true) && ($data['layer'] ?? null) === 'project') {
            $data['layer'] = 'channel';
        }

        $service = app(FinanceSettlementService::class);
        $metrics = [
            'logistics' => '物流占比', 'purchase' => '采购占比', 'fee' => '手续费占比',
            'refund' => '退款金额占比', 'refund_fee' => '退款费用占比',
            'dispute_refund' => '争议冻结退款占比', 'dispute_fee' => '争议费用占比',
        ];
        $monthlyLossGroups = [];
        $scope = \Illuminate\Support\Arr::except($data, ['month', 'month_from', 'month_to']);
        ksort($scope);
        $snapshots = app(\App\Portals\Finance\FinanceReportSnapshots::class);
        $reportSnapshots = [];
        $reportStatuses = [];
        $versions = $request->boolean('download')
            ? $request->validate(['report_versions' => 'sometimes|array', 'report_versions.*' => 'required|uuid'])['report_versions'] ?? []
            : [];
        foreach ($comparisonMonths as $comparisonMonth) {
            $includeOptions = $comparisonMonth === $month;
            if ($versions !== [] && ! isset($versions[$comparisonMonth])) {
                throw ValidationException::withMessages(['report_versions' => '报表版本不完整，请重新查询后导出。']);
            }
            [[$groups, $options, $rate], $reportSnapshots[$comparisonMonth], $reportStatuses[$comparisonMonth]] = $snapshots
                ->result($report, $comparisonMonth, [...$scope, '_include_options' => $includeOptions],
                    fn () => $this->buildSettlementOverviewMonth($comparisonMonth, $report, $scope, $includeOptions, reuse: false),
                    $versions[$comparisonMonth] ?? null);
            $monthlyLossGroups[$comparisonMonth] = $groups;
            if ($includeOptions) {
                $filterOptions = $options;
            }
        }
        abort_if($request->boolean('download') && $snapshots->runsInBackground() && in_array(null, $reportSnapshots, true),
            409, '报表正在生成，请稍后重新查询。');
        if ($request->boolean('download') && $report === 'losses') {
            $export = [];
            $selectionKeys = \App\Portals\Finance\FinanceExportSelection::keys($request);
            $panels = collect($monthlyLossGroups)->flatten(1)->unique(fn ($group) => $group['name'].'|'.$group['channel']);
            if ($request->filled('loss_scope')) {
                $panels = $panels->filter(fn ($group) => ($group['scope'] ?? ($group['channel'] === 'combined'
                    ? 'Shopify' : ($group['channel'] === 'xuzong' ? '徐总信用卡' : FinanceSettlementService::REPORT_CHANNELS[$group['channel']]))) === $request->query('loss_scope')
                    && ($group['category'] ?? (explode(' / ', $group['name'], 2)[1] ?? '总计')) === $request->query('loss_category', '总计'));
            }
            foreach ($panels as $panel) {
                $periods = collect($monthlyLossGroups)->map(fn ($items) => collect($items)->first(fn ($item) => $item['name'] === $panel['name'] && $item['channel'] === $panel['channel']));
                $panelExport = [];
                $includeMetric = fn ($metric) => $selectionKeys === null || in_array(hash('sha256', \App\Portals\Finance\FinanceExportSelection::key($panel).'|'.$metric), $selectionKeys, true);
                if ($includeMetric('success_count'))
                $panelExport[] = ['成功订单数', ...$periods->map(fn ($item) => $item['success_count'] ?? 0)->values()->all()];
                foreach ($periods->filter()->flatMap(fn ($item) => array_keys($item['ratios']))->unique() as $key) {
                    if ($includeMetric($key)) $panelExport[] = [($metrics[$key] ?? $service::MONEY[$key]).'（%）', ...$periods->map(fn ($item) => $item['ratios'][$key] ?? '')->values()->all()];
                }
                if ($includeMetric('total_ratio')) $panelExport[] = ['合计（%）', ...$periods->map(fn ($item) => $item['total_ratio'] ?? '')->values()->all()];
                if ($panelExport) {
                    $export[] = [$panel['name'], ...array_keys($monthlyLossGroups)];
                    array_push($export, ...$panelExport);
                    $export[] = [''];
                }
            }
            if ($selectionKeys !== null && ! $export) {
                throw ValidationException::withMessages(['selected_rows' => '所选数据已不在当前筛选结果中，请重新选择。']);
            }
            return $this->workbooks->download(['项目 / 指标', ...array_keys($monthlyLossGroups)], $export, 'finance-losses-'.$month.'.xlsx');
        }
        if ($request->boolean('download')) {
            $metricKeys = array_values(array_unique(array_merge(...array_map(fn ($group) => array_keys($group['ratios']), $groups))));
            $groups = \App\Portals\Finance\FinanceExportSelection::filter($request, $groups)->all();
            $headers = $report === 'losses' ? ['项目', '通道', '成功订单数', '失败交易数', '结算金额（美元）', ...array_map(fn ($key) => ($metrics[$key] ?? $service::MONEY[$key]).'（%）', $metricKeys), '合计损耗占比（%）'] : ['项目', '通道', '成功订单数', '失败交易数', '结算金额（美元）'];
            $export = array_map(fn ($group) => [$group['name'], FinanceSettlementService::REPORT_CHANNELS[$group['channel']], $group['success_count'], $group['failed_count'], $group['settlement']], $groups);

            return $this->workbooks->download($headers, $export, 'finance-'.$report.'-'.$month.'.xlsx');
        }

        return response()->view('portals.finance.settlement-overview', [...$context, 'report' => $report, 'filters' => [...$data, 'month' => $month], 'groups' => $groups, 'monthlyLossGroups' => $monthlyLossGroups, 'rate' => $rate, 'creditMonths' => $creditMonths, 'filterOptions' => $filterOptions, 'metrics' => [...$service::MONEY, ...$metrics], 'reportSnapshots' => $reportSnapshots, 'reportStatuses' => $reportStatuses, 'reportTabUrls' => $this->reportTabUrls([...$data, 'month' => $month])]);
    }

    public function buildSettlementOverviewMonth(string $comparisonMonth, string $report, array $data, bool $includeOptions, bool $reuse): array
    {
        $start = CarbonImmutable::parse($comparisonMonth.'-01', 'Asia/Shanghai');
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $filters = ['from' => $start->toDateString(), 'to' => $start->endOfMonth()->toDateString(), 'timezone' => 'Asia/Shanghai',
            'logistics_costs' => $report === 'losses', 'include_unmatched_fees' => true];
        $filterOptions = [];
        $optionValues = [];
        $accountKeys = $includeOptions ? FinancePaymentAccount::query()->pluck('activity_account')
            ->push(\App\Portals\Finance\FinanceWorldpayImport::SOURCE_ACCOUNT)->flip() : collect();
        $layer = $data['layer'] ?? 'account';
        $rows = (function () use ($service, $filters, $data, $report, $reuse, $layer, $includeOptions, $accountKeys, &$optionValues) {
            foreach (['paypal', 'credit_card'] as $channel) {
                if (($data['channel'] ?? '') === 'paypal' && $channel !== 'paypal'
                    || filled($data['channel'] ?? null) && ! in_array($data['channel'], ['paypal', 'combined'], true) && $channel === 'paypal') {
                    continue;
                }
                $projection = $reuse && $report === 'summary'
                    ? app(\App\Portals\Finance\FinanceSummaryProjection::class)->stream($channel, $filters, false) : null;
                foreach ($projection ?? $service->summaryRowStream($channel, $filters) as $row) {
                    if (filled($data['channel'] ?? null) && ! in_array($data['channel'], ['paypal', 'credit_card', 'combined'], true)
                        && ($data['channel'] === 'xuzong'
                            ? ! in_array($row['payment_channel'], ['xuzong1', 'xuzong2'], true)
                            : $row['payment_channel'] !== $data['channel'])) {
                        continue;
                    }
                    if ($includeOptions) {
                        if ($accountKeys->has($row['account_key'])) {
                            $optionValues['account'][$row['account_key']] ??= ['value' => $row['account_key'], 'label' => $row['account']];
                        }
                        foreach (['owner', 'team', 'subscription', 'payment_method'] as $field) {
                            if (filled($row[$field])) {
                                $optionValues[$field][$row[$field]] = $row[$field];
                            }
                        }
                    }
                    foreach (['account' => 'account_key', 'owner' => 'owner', 'team' => 'team', 'subscription' => 'subscription', 'payment_method' => 'payment_method'] as $filter => $field) {
                        if (filled($data[$filter] ?? null) && $row[$field] !== ($filter === 'subscription' ? $service::subscriptionLabel($data[$filter]) : $data[$filter])) {
                            continue 2;
                        }
                    }
                    if ($report === 'summary' && in_array($layer, ['account', 'channel', 'team', 'subscription', 'detail'], true)) {
                        $row['channel'] = $row['payment_channel'];
                    }
                    if ($report === 'losses') {
                        $row['channel'] = match ($row['payment_channel']) {
                            'xuzong1', 'xuzong2' => 'xuzong',
                            default => $row['payment_channel'],
                        };
                    }
                    yield $row;
                }
            }
        })();
        $groups = $service->totals($report === 'losses' ? $service->logisticsCostStream($rows) : $rows, $layer);
        if ($includeOptions) {
            $filterOptions['account'] = collect($optionValues['account'] ?? [])->values();
            foreach (['owner', 'team', 'subscription', 'payment_method'] as $field) {
                $values = collect(array_values($optionValues[$field] ?? []));
                $values = $field === 'subscription' ? $service->subscriptionOptions($values->map(fn ($value) => ['subscription' => $value])) : $values->sort();
                $filterOptions[$field] = $values->map(fn ($value) => ['value' => $value, 'label' => $value])->values();
            }
        }
        if ($report === 'summary' && ($data['layer'] ?? '') === 'project') {
            foreach ($groups as &$group) {
                $group['channel'] = filled($data['channel'] ?? null) ? $data['channel'] : 'combined';
            }
            unset($group);
        }
        $rate = $this->workbooks->database()->table('finance_monthly_exchange_rates')->where('month', $comparisonMonth)->where('currency', 'USD')->value('cny_rate');
        $metrics = ['logistics' => '物流占比', 'purchase' => '采购占比', 'fee' => '手续费占比', 'refund' => '退款金额占比', 'refund_fee' => '退款费用占比', 'dispute_refund' => '争议冻结退款占比', 'dispute_fee' => '争议费用占比'];
        foreach ($groups as &$group) {
            $group['ratios'] = [];
            $group['total_ratio'] = null;
            $keys = $group['channel'] === 'paypal' ? array_keys($metrics) : ['logistics', 'purchase', 'fee', 'per_order_fee', 'refund', 'refund_fee', 'chargeback', 'chargeback_fee', 'ethoca_refund', 'ethoca_fee', 'rdr_refund', 'rdr_fee'];
            foreach ($keys as $key) {
                $amount = $group[$key];
                if ($key === 'fee' && in_array($group['channel'], ['paypal', 'combined'], true)) {
                    $amount = $amount === null || $group['fee_return'] === null ? null : bcsub($amount, $group['fee_return'], 8);
                }
                if ($key === 'dispute_refund' && $group['channel'] === 'paypal') {
                    $amount = $amount === null || $group['dispute_hold'] === null || $group['dispute_release'] === null ? null : bcsub(bcadd($amount, $group['dispute_hold'], 8), $group['dispute_release'], 8);
                }
                if ($group['channel'] === 'combined' && $key === 'ethoca_refund') {
                    $amount = bcsub(bcadd(bcadd($amount, $group['dispute_refund'], 8), $group['dispute_hold'], 8), $group['dispute_release'], 8);
                }
                if ($group['channel'] === 'combined' && $key === 'ethoca_fee') {
                    $amount = bcadd($amount, \App\Portals\Finance\FinanceTransactionSummary::shopifyPayPalDisputeFee(
                        $group['dispute_fee'], $comparisonMonth
                    ), 8);
                }
                if (in_array($key, ['logistics', 'purchase'], true)) {
                    $amount = $amount === null || $rate === null ? null : bcdiv($amount, $rate, 8);
                }
                $group['ratios'][$key] = $amount === null || $group['settlement'] === null || bccomp($group['settlement'], '0', 8) === 0 ? null : bcmul(bcdiv($amount, $group['settlement'], 10), '100', 8);
                if ($group['ratios'][$key] !== null) {
                    $group['total_ratio'] = bcadd($group['total_ratio'] ?? '0', $group['ratios'][$key], 8);
                }
            }
        }
        unset($group);

        return [$groups, $filterOptions, $rate];
    }

    private function reportTabUrls(array $filters): array
    {
        $common = \Illuminate\Support\Arr::only($filters, ['month', 'channel']);
        $transactionFilters = $common;
        if (in_array($common['channel'] ?? null, ['xuzong', 'xuzong1', 'xuzong2'], true)) {
            $transactionFilters['channel'] = 'credit_card';
        }
        $account = filled($filters['payment_account_id'] ?? null)
            ? FinancePaymentAccount::find($filters['payment_account_id'])
            : (filled($filters['account'] ?? null) ? FinancePaymentAccount::where('activity_account', $filters['account'])->first() : null);

        return [
            'settlement' => route('finance.report', ['report' => 'summary', ...$common, 'view' => 'settlement', ...($account ? ['account' => $account->activity_account] : [])]),
            'transactions' => route('finance.report', ['report' => 'summary', ...$transactionFilters, 'view' => 'transactions', ...($account ? ['payment_account_id' => $account->id] : [])]),
        ];
    }

    private function context(Request $request, bool $write = false): array|Response
    {
        $context = $this->entries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($context['portal']->code === FinanceCatalog::PORTAL_CODE, 404);
        abort_unless($context['user']->can('finance.view') && (! $write || $context['user']->can('finance.manage')), 403);
        if ($request->is('finance', 'finance/*') || $request->getPathInfo() === '/') {
            $currentPath = $request->getPathInfo() === '/' || $request->routeIs('finance.balances', 'finance.sync-logs', 'finance.balances.xuzong2.save', 'finance.exchange-rates.save', 'finance.paypal-accounts.update', 'finance.payment-accounts.*')
                ? '/finance' : rtrim($request->getPathInfo(), '/');
            if ($request->routeIs('finance.transactions.raw', 'finance.payment-accounts.sync', 'finance.orders.paypal.refresh', 'finance.orders.paypal.status', 'finance.orders.paypal.latest')) {
                $currentPath = '/finance/records/paypal-orders';
            }
            $domains = app(\App\Portal\Services\PortalDomainService::class);
            $homePath = $domains->portalPath($context['portal']);
            $authorization = app(\App\Portal\Services\PortalMenuAuthorizationService::class);
            $legacyPath = $domains->portalPath($context['portal'], '/finance/reports/credit-settlement');
            if ($currentPath === $legacyPath || str_starts_with($currentPath, $legacyPath.'/')) {
                $legacyMenu = $context['portal']->menus->firstWhere('code', 'finance_credit-settlement');
                if ($legacyMenu && ! $legacyMenu->is_enabled) {
                    foreach (array_keys(FinanceCreditSettlementLayout::REPORT_CHANNELS) as $report) {
                        $menu = $context['portal']->menus->firstWhere('code', 'finance_'.$report);
                        abort_unless($menu?->is_enabled && $authorization->canAccess($context['user'], $menu), 403);
                    }
                    $currentPath = $domains->portalPath($context['portal'], '/finance/reports/ye-settlement');
                }
            }
            $menu = $context['portal']->menus->first(function ($menu) use ($currentPath, $domains, $context, $homePath): bool {
                $path = trim((string) $menu->path);
                if (! $menu->is_enabled || $path === '' || str_starts_with($path, '#') || preg_match('#^https?://#', $path)) {
                    return false;
                }
                $path = $domains->portalPath($context['portal'], $path);

                return $currentPath === $path || ($path !== $homePath && str_starts_with($currentPath, $path.'/'));
            });
            abort_unless($menu, 403);
            abort_unless($authorization->canAccess($context['user'], $menu), 403);
        }
        app()->setLocale('zh-CN');

        return [...$context, 'canManage' => $context['user']->can('finance.manage')];
    }

    private function settingsTable(string $kind): string
    {
        return $kind === 'types' ? 'finance_transaction_types' : 'finance_domain_groups';
    }

    private function settingsHeaders(string $kind): array
    {
        return array_values(trans('finance.import_headers.'.$kind));
    }

    private function filterCacheKey(string $scope): string
    {
        $db = $this->workbooks->database();

        return 'finance:filter-options:v1:'.hash('sha256', $db->getName().'|'.$db->getDatabaseName()).':'.$scope;
    }

    private function conditionChoices(string $dataset, bool $xuzongReport): array
    {
        $definition = FinanceCatalog::dataset($dataset);
        $choices = [];
        $creditOptions = $dataset === 'xuzong2-orders'
            ? Cache::remember($this->filterCacheKey(($xuzongReport ? 'xuzong-credit-imported' : $dataset).':order-options'), 60, fn () =>
                app(FinanceLogisticsCreditOrders::class)->query(withPayload: false, xuzongReport: $xuzongReport, withPayments: false)
                    ->distinct()->toBase()->get(['account', 'currency', 'status']))
            : null;
        $choices['account'] = FinancePaymentAccount::query()
            ->where('account_type', $definition['channel'] === 'paypal' ? 'paypal' : 'credit_card')->orderBy('id')
            ->get(['activity_account', 'account_name'])->map(fn ($account) => [
                'value' => $account->activity_account, 'label' => FinanceCatalog::simplified($account->account_name),
            ])->all();
        if ($creditOptions !== null) {
            $choices['account'] = $creditOptions->pluck('account')->filter()->uniqueStrict()->sort()->values()
                ->map(fn ($value) => ['value' => $value, 'label' => FinanceCatalog::simplified($value)])->all();
        }
        $fields = ['currency', 'status'];
        $reportCurrencies = $xuzongReport
            ? Cache::remember($this->filterCacheKey('xuzong-credit:payment-currencies'), 60, function (): array {
                $orders = app(FinanceLogisticsCreditOrders::class)->query(withPayload: false, xuzongReport: true);

                $matches = app(FinanceXuzongCreditMatches::class);

                return $orders->select(['id', 'dataset', 'account', 'reference', 'status', 'currency', 'payment_transactions'])->cursor()->chunk(500)
                    ->flatMap(fn ($batch) => $matches->paymentCurrencies(collect($batch->all()))->values())
                    ->filter()->unique()->sort()->values()->all();
            })
            : null;
        if ($definition['channel'] === 'paypal') {
            $fields = [...$fields, 'raw_type', 'balance_impact'];
            $choices['category'] = $this->workbooks->database()->table('finance_transaction_types')
                ->whereNotNull('category')->where('category', '!=', '')->pluck('category')
                ->merge($this->workbooks->database()->table('finance_payment_transactions')->whereNotNull('category')->distinct()->pluck('category'))
                ->unique()->sort()->values()->map(fn ($value) => ['value' => $value, 'label' => FinanceCatalog::simplified($value)])->all();
            $choices['direction'] = collect(__('finance.accounting.directions'))
                ->map(fn ($label, $value) => compact('value', 'label'))->values()->all();
        }
        foreach ($fields as $field) {
            $column = FinanceCatalog::filterColumn($dataset, $field);
            $values = $field === 'currency' && $reportCurrencies !== null ? collect($reportCurrencies) : ($creditOptions !== null
                ? $creditOptions->pluck($field)->filter(fn ($value) => $value !== null && $value !== '')->uniqueStrict()->sort()
                : collect(Cache::remember($this->filterCacheKey($dataset.':'.$field), 60, function () use ($dataset, $column): array {
                    return $this->recordsQuery($dataset, [])->whereNotNull($column)
                        ->select($column.' as choice')->distinct()->orderBy('choice')->pluck('choice')->filter(fn ($value) => $value !== '')->all();
                })));
            if ($field === 'raw_type') {
                $values = $values->merge($this->workbooks->database()->table('finance_transaction_types')->pluck('raw_type'))->unique();
            }
            if ($xuzongReport && $field === 'status') {
                $values = collect(['成功', '失败', '待确认', '退款']);
            }
            $choices[$field] = $values->map(fn ($value) => [
                'value' => (string) $value,
                'label' => $field === 'raw_type' ? \App\Portals\Finance\FinancePayPalEventTypes::label((string) $value)
                    : FinanceCatalog::simplified(match ((string) $value) {
                        'S' => '成功', 'D' => '拒绝', 'P' => '处理中', 'V' => '已撤销',
                        'Credit', 'CR' => '贷记（增加余额）', 'Debit', 'DR' => '借记（减少余额）',
                        'Memo' => '不影响余额', default => (string) $value,
                    }),
            ])->values()->all();
        }
        foreach (['currency', 'status', 'type' => 'raw_type'] as $index => $field) {
            $key = is_int($index) ? $field : $index;
            if (isset($definition[$key], $choices[$field])) {
                $choices['column_'.$definition[$key]] = $choices[$field];
            }
        }
        if ($definition['channel'] === 'paypal') {
            $choices['column_14'] = $choices['balance_impact'];
        }

        return $choices;
    }

    private function filters(Request $request, string $dataset): array
    {
        $data = $request->validate([
            ...(in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true) ? [
                'order_fields' => 'nullable|array:'.implode(',', array_keys(FinanceCatalog::PAYPAL_ORDER_FILTERS)),
                'order_fields.*' => 'nullable|string|max:191',
            'report_fields' => ['nullable', 'array:'.implode(',', FinanceXuzongCreditReport::additionalEditableHeaders())],
            'report_fields.*' => 'nullable|string|max:500',
            ] : []),
            'account' => 'nullable|string|max:191', 'reference' => 'nullable|string|max:191',
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', Rule::in(FinanceWorkbookService::TIMEZONES)],
            'export_format' => ['nullable', Rule::in(['xls', 'xlsx', 'csv', 'tsv'])],
            'type_category' => 'nullable|string|max:100',
            'transaction_scope' => ['nullable', Rule::in(FinanceCatalog::dataset($dataset)['channel'] === 'paypal' ? ['all', 'balance_affecting', 'non_balance_affecting'] : ['all'])],
            'conditions' => 'nullable|array|max:10',
            'conditions.*' => 'array:field,operator,value',
            'conditions.*.field' => ['required', 'string', Rule::in(FinanceCatalog::filterFields($dataset))],
            'conditions.*.operator' => ['nullable', 'string', Rule::in(['equals', 'contains', 'not_equals', 'empty', 'not_empty', 'gt', 'gte', 'lt', 'lte'])],
            'conditions.*.value' => 'nullable|string|max:191',
        ]);

        foreach ($data['conditions'] ?? [] as $index => $condition) {
            if (in_array($condition['operator'] ?? '', ['gt', 'gte', 'lt', 'lte'], true)
                && (! in_array($condition['field'], FinanceCatalog::numericFilterFields($dataset), true)
                    || ! preg_match('/^-?\d+(?:\.\d{1,8})?$/', $condition['value'] ?? ''))) {
                throw ValidationException::withMessages(['conditions.'.$index.'.value' => '大小比较仅适用于金额等数字字段，请输入有效数字（最多8位小数）。']);
            }
        }
        if (in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true)) {
            $dateRules = [];
            foreach (['refunded', 'ordered', 'paid'] as $prefix) {
                foreach (['from', 'to'] as $suffix) {
                    $dateRules['order_fields.'.$prefix.'_'.$suffix] = 'nullable|date_format:Y-m-d';
                }
            }
            $request->validate($dateRules);
        }

        return [...$data, 'timezone' => $data['timezone'] ?? 'Asia/Shanghai'];
    }

    private function recordsQuery(string $dataset, array $filters, bool $xuzongReport = false, bool $lightweight = false): Builder
    {
        $needsPayload = ! $lightweight || ! empty($filters['conditions']);
        $query = $dataset === 'xuzong2-orders'
            ? app(FinanceLogisticsCreditOrders::class)->query(
                withPayload: $needsPayload,
                xuzongReport: $xuzongReport,
                withPayments: ! $lightweight || filled($filters['order_fields']['currency'] ?? null),
                searchReference: $xuzongReport && filled($filters['reference'] ?? null) ? $filters['reference'] : null,
            )
            : FinanceRecord::query()->where('dataset', $dataset);
        if ($dataset === 'paypal-orders') {
            app(\App\Portals\Finance\FinanceSettlementService::class)->filterAccountHistory($query, 'paypal');
        }
        if (isset($filters['type_category']) && $filters['type_category'] !== '') {
            $query->where(function ($query) use ($filters): void {
                $query->whereExists(fn ($types) => $types->selectRaw('1')->from('finance_transaction_types')
                    ->whereColumn('finance_transaction_types.raw_type', 'finance_records.raw_type')
                    ->where('category', $filters['type_category']));
                if ($filters['type_category'] === __('finance.records.unclassified')) {
                    $query->orWhereNotExists(fn ($types) => $types->selectRaw('1')->from('finance_transaction_types')
                        ->whereColumn('finance_transaction_types.raw_type', 'finance_records.raw_type')
                        ->whereNotNull('category')->where('category', '!=', ''));
                }
            });
        }
        if (($filters['transaction_scope'] ?? 'all') !== 'all') {
            $query->whereIn(FinanceCatalog::filterColumn($dataset, 'balance_impact'), $filters['transaction_scope'] === 'balance_affecting'
                ? ['Credit', 'credit', 'CREDIT', 'Debit', 'debit', 'DEBIT', 'CR', 'DR', '贷记', '借记', '貸記', '借記']
                : ['Memo', 'memo', 'MEMO', '备忘录', '備忘錄', '不影响余额', '不影響餘額']);
        }
        foreach (['account', 'reference'] as $field) {
            if (($filters[$field] ?? '') && ! ($xuzongReport && $field === 'reference')) {
                $query->where($field, $filters[$field]);
            }
        }
        if ($filters['from'] ?? '') {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($filters['from'], $filters['timezone'])->utc());
        }
        if ($filters['to'] ?? '') {
            $query->where('occurred_at', '<', CarbonImmutable::parse($filters['to'], $filters['timezone'])->addDay()->utc());
        }
        if (in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true)) {
            $linked = [];
            foreach ($filters['order_fields'] ?? [] as $field => $value) {
                if ($xuzongReport && $field === 'currency') {
                    continue;
                }
                if (filled($value) && isset(FinanceCatalog::PAYPAL_ORDER_FILTERS[$field])) {
                    if ($xuzongReport && in_array($field, ['subscription_type', 'is_add_on', 'domain', 'tongtu_order_number', 'status', 'column_13'], true)) {
                        [$label, $fallback, $logisticsField] = match ($field) {
                            'subscription_type' => ['订阅类型', 'payload->订阅类型', 'subscription_type'],
                            'is_add_on' => ['是否加购', 'payload->是否加购', 'is_add_on'],
                            'domain' => ['域名', 'domain', 'source_shop_domain'],
                            'tongtu_order_number' => ['通途订单', 'payload->通途订单', 'tongtu_order_number'],
                            'status' => ['状态', 'match_status', null],
                            'column_13' => ['平台订单号', 'reference', null],
                        };
                        $grammar = $query->getQuery()->getGrammar();
                        $saved = $grammar->wrap($field === 'column_13' ? 'payload->_edited_platform_order_id' : 'payload->_edited_report_fields->'.$label);
                        $base = $grammar->wrap($fallback);
                        $linkedValue = 'NULL';
                        $db = $query->getConnection();
                        if ($logisticsField && $db->getSchemaBuilder()->hasColumn('logistics_orders', $logisticsField)) {
                            $linkedId = $grammar->wrap('finance_records.payload->_linked_logistics_id');
                            $linkedValue = '(SELECT logistics.'.$logisticsField.' FROM logistics_orders AS logistics WHERE logistics.id = '.$linkedId.')';
                        }
                        $effective = "COALESCE({$saved}, NULLIF({$base}, ''), {$linkedValue})";
                        if ($field === 'subscription_type') {
                            $effective = "REPLACE(REPLACE(REPLACE(TRIM({$effective}), '订购复购', '订阅复购'), '订阅首次购买', '订阅'), ' ', '')";
                            $value = str_replace(' ', '', FinanceSettlementService::subscriptionLabel($value));
                        } elseif ($field === 'is_add_on') {
                            $effective = "CASE WHEN {$effective} IS NULL THEN NULL WHEN LOWER({$effective}) IN ('1','true','yes','是','加购','已加购') THEN '已加购' ELSE '未加购' END";
                        }
                        $query->whereRaw($effective.(in_array($field, ['status', 'subscription_type', 'is_add_on'], true) ? ' = ?' : ' LIKE ?'),
                            [in_array($field, ['status', 'subscription_type', 'is_add_on'], true) ? $value : '%'.$value.'%']);
                        continue;
                    }
                    if ($dataset === 'paypal-orders' && $field === 'domain') {
                        $this->filterPayPalOrderDomain($query, $value);
                        continue;
                    }
                    if (in_array($field, FinanceCatalog::PAYPAL_LOGISTICS_FILTERS, true) || ($dataset === 'xuzong2-orders' && (str_starts_with($field, 'column_') || $field === 'sender_email'))) {
                        $linked[$field] = $value;
                        continue;
                    }
                    $column = $xuzongReport && $field === 'status' ? 'match_status'
                        : ($field === 'domain' ? 'domain' : FinanceCatalog::filterColumn($dataset, $field));
                    $this->applyCondition($query, $column, in_array($field, ['currency', 'status'], true) ? 'equals' : 'contains', $value);
                }
            }
            if ($linked !== []) {
                $schema = $this->workbooks->database()->getSchemaBuilder();
                if (! $schema->hasTable('logistics_orders')) {
                    $query->whereRaw('1 = 0');
                } else {
                    $method = $dataset === 'paypal-orders' ? 'whereIn' : 'whereExists';
                    $arguments = $dataset === 'paypal-orders' ? ['finance_records.id'] : [];
                    $arguments[] = function ($orders) use ($linked, $dataset): void {
                        if ($dataset === 'xuzong2-orders') {
                            $orders->selectRaw('1')->from('logistics_orders as logistics')->whereColumn('logistics.id', 'finance_records.id');
                        } else {
                            $matches = app(\App\Portals\Finance\FinanceSettlementService::class)->paypalOrderMatchIndex();
                            $orders->select('matched_order.record_id')->fromSub($matches, 'matched_order')
                                ->join('logistics_orders as logistics', 'logistics.id', '=', 'matched_order.order_id');
                        }
                        foreach ($linked as $field => $value) {
                            if ($dataset === 'xuzong2-orders' && $field === 'sender_email') { $field = 'buyer_email'; }
                            if ($dataset === 'xuzong2-orders' && str_starts_with($field, 'column_')) {
                                $field = ['column_13' => 'bill_number', 'column_19' => 'shopify_order_id', 'column_18' => 'tongtu_product_name',
                                    'column_3' => 'buyer_name', 'column_37' => 'buyer_phone', 'column_15' => 'collection_account_email'][$field] ?? $field;
                            }
                            if (preg_match('/^(refunded|ordered|paid)_(from|to)$/', $field, $parts)) {
                                $column = ['refunded' => 'refunded_at', 'ordered' => 'order_created_at', 'paid' => 'paid_at'][$parts[1]];
                                $date = CarbonImmutable::parse($value, 'Asia/Shanghai');
                                $orders->where('logistics.'.$column, $parts[2] === 'from' ? '>=' : '<', $parts[2] === 'from' ? $date->utc() : $date->addDay()->utc());
                                continue;
                            }
                            if (in_array($field, ['shopify_sku', 'shopify_sku_status', 'pcl_runnable'], true)) {
                                $exists = $field !== 'shopify_sku_status' || $value !== 'empty' ? 'whereExists' : 'whereNotExists';
                                $orders->$exists(function ($items) use ($field, $value): void {
                                    $items->selectRaw('1')->from('logistics_order_items as item')->whereColumn('item.logistics_order_id', 'logistics.id');
                                    if ($field === 'pcl_runnable') {
                                        $items->whereExists(fn ($pcl) => $pcl->selectRaw('1')->from('product_request_link_skus as pcl')
                                            ->whereColumn('pcl.pcl_product_no', 'item.tongtu_product_code')->where('pcl.is_runnable', $value === 'yes'));
                                    } elseif ($field === 'shopify_sku_status') {
                                        $items->whereNotNull('item.shopify_sku')->where('item.shopify_sku', '!=', '');
                                    } else {
                                        $this->applyCondition($items, 'item.shopify_sku', 'contains', $value);
                                    }
                                });
                                continue;
                            }
                            if ($field === 'store_assignment_status') {
                                $orders->{$value === 'assigned' ? 'whereNotNull' : 'whereNull'}('logistics.assistant_domain_store_id');
                                continue;
                            }
                            if ($field === 'refund_number') {
                                $credit = $dataset === 'xuzong2-orders';
                                $table = $credit ? 'logistics_credit_card_refunds' : 'logistics_paypal_refunds';
                                $column = $credit ? 'refund_reference' : 'refund_transaction_id';
                                $orders->whereExists(fn ($refund) => $refund->selectRaw('1')->from($table.' as refund')
                                    ->whereColumn('refund.logistics_order_id', 'logistics.id')->where('refund.'.$column, 'like', '%'.$value.'%'));
                                continue;
                            }
                            $this->applyCondition($orders, 'logistics.'.$field, 'contains', $value);
                        }
                    };
                    if ($xuzongReport) {
                        $query->where(function ($query) use ($method, $arguments, $linked): void {
                            $query->$method(...$arguments);
                            $query->orWhere(function ($failed) use ($linked): void {
                                $failed->where('id', '<', 0);
                                $columns = [
                                    'id' => 'id', 'shopify_order_number' => 'payload->Shopify订单号',
                                    'shopify_order_id' => 'payload->_shopify_order_id', 'store_name' => 'payload->店铺名称',
                                    'source_shop_domain' => 'payload->_source_shop_domain', 'payment_method' => 'payload->支付方式',
                                    'sender_email' => 'payload->_buyer_email', 'column_13' => 'payload->_bill_number',
                                ];
                                foreach ($linked as $field => $value) {
                                    if (isset($columns[$field])) {
                                        $this->applyCondition($failed, $columns[$field], 'contains', $value);
                                    } elseif (in_array($field, ['ordered_from', 'ordered_to'], true)) {
                                        $date = CarbonImmutable::parse($value, 'Asia/Shanghai');
                                        $failed->where('occurred_at', $field === 'ordered_from' ? '>=' : '<',
                                            ($field === 'ordered_from' ? $date : $date->addDay())->utc());
                                    } else {
                                        $failed->whereRaw('1 = 0');
                                    }
                                }
                            });
                        });
                    } else {
                        $query->$method(...$arguments);
                    }
                }
            }
        }
        foreach ($filters['conditions'] ?? [] as $condition) {
            $operator = $condition['operator'] ?? 'equals';
            $value = $condition['value'] ?? '';
            if ($value === '' && ! in_array($operator, ['empty', 'not_empty'], true)) {
                continue;
            }
            if ($condition['field'] === 'category') {
                $query->where(function ($query) use ($operator, $value): void {
                    $query->whereExists(function ($query) use ($operator, $value): void {
                        $query->selectRaw('1')->from('finance_payment_transactions')->whereColumn('finance_payment_transactions.finance_record_id', 'finance_records.id');
                        $this->applyCondition($query, 'finance_payment_transactions.category', $operator, $value);
                    })->orWhere(function ($query) use ($operator, $value): void {
                        $query->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('finance_payment_transactions')->whereColumn('finance_payment_transactions.finance_record_id', 'finance_records.id'));
                        $method = $operator === 'empty' ? 'whereNotExists' : 'whereExists';
                        $query->$method(function ($query) use ($operator, $value): void {
                            $query->selectRaw('1')->from('finance_transaction_types')->whereColumn('finance_transaction_types.raw_type', 'finance_records.raw_type')->whereNotNull('category')->where('category', '!=', '');
                            if (! in_array($operator, ['empty', 'not_empty'], true)) {
                                $this->applyCondition($query, 'category', $operator, $value);
                            }
                        });
                    });
                });
            } elseif ($condition['field'] === 'direction') {
                $query->whereExists(function ($query) use ($operator, $value): void {
                    $query->selectRaw('1')->from('finance_payment_transactions')->whereColumn('finance_payment_transactions.finance_record_id', 'finance_records.id');
                    $this->applyCondition($query, 'direction', $operator, $value);
                });
            } else {
                $column = $xuzongReport && $condition['field'] === 'status' ? 'match_status' : FinanceCatalog::filterColumn($dataset, $condition['field']);
                $this->applyCondition($query, $column, $operator, $value);
            }
        }

        if ($xuzongReport && filled($filters['order_fields']['currency'] ?? null)) {
            $query->where('currency', strtoupper($filters['order_fields']['currency']));
        }

        return $query;
    }

    private function filterPayPalOrderDomain(Builder $query, string $value): void
    {
        $db = $this->workbooks->database();
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasTable('logistics_orders')) {
            $this->applyCondition($query, 'domain', 'contains', $value);

            return;
        }

        $domains = [];
        $orders = $db->table('logistics_orders as domain_order');
        if ($schema->hasColumn('logistics_orders', 'assistant_domain_store_id')
            && $schema->hasTable('assistant_domain_stores')) {
            $orders->leftJoin('assistant_domain_stores as domain_store', 'domain_store.id', '=', 'domain_order.assistant_domain_store_id');
            $domains[] = "NULLIF(domain_store.domain, '')";
        }
        if ($schema->hasColumn('logistics_orders', 'source_shop_domain')) {
            $domains[] = "NULLIF(domain_order.source_shop_domain, '')";
        }
        if ($domains === []) {
            $this->applyCondition($query, 'domain', 'contains', $value);

            return;
        }

        $matches = app(\App\Portals\Finance\FinanceSettlementService::class)->paypalOrderMatchIndex();
        $linkedDomain = $orders->joinSub($matches, 'domain_match', 'domain_match.order_id', '=', 'domain_order.id')
            ->whereColumn('domain_match.record_id', 'finance_records.id')
            ->selectRaw('COALESCE('.implode(', ', [...$domains, 'NULL']).')');

        $query->whereRaw('COALESCE(('.$linkedDomain->toSql().'), finance_records.domain) LIKE ?',
            [...$linkedDomain->getBindings(), '%'.$value.'%']);
    }

    private function applyCondition($query, string $column, string $operator, string $value): void
    {
        if (in_array($operator, ['gt', 'gte', 'lt', 'lte'], true)) {
            $comparison = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$operator];
            $wrapped = $query->getQuery()->getGrammar()->wrap($column);
            $query->whereNotNull($column)->where($column, '!=', '')
                ->whereRaw('CAST('.$wrapped.' AS DECIMAL(30,8)) '.$comparison.' CAST(? AS DECIMAL(30,8))', [$value]);

            return;
        }
        if ($operator === 'empty') {
            $query->where(fn ($query) => $query->whereNull($column)->orWhere($column, ''));
        } elseif ($operator === 'not_empty') {
            $query->whereNotNull($column)->where($column, '!=', '');
        } else {
            $query->where($column, match ($operator) {
                'contains' => 'like', 'not_equals' => '!=', default => '='
            }, $operator === 'contains' ? '%'.$value.'%' : $value);
        }
    }
}
