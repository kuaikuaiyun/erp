<?php

namespace App\Portals\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FinancePaymentBalance;
use App\Models\FinancePaymentAccount;
use App\Models\FinanceRecord;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Finance\FinanceCatalog;
use App\Portals\Finance\FinancePaymentBalanceService;
use App\Portals\Finance\FinancePayPalBalanceService;
use App\Portals\Finance\FinanceWorkbookService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
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
            'balance_tab' => ['nullable', Rule::in(['paypal', 'credit-card'])],
        ]);
        $paypalBalance = $this->paypalBalances->dashboard($filters['paypal_currency'] ?? 'USD');
        $paymentBalance = $this->paymentBalances->dashboard($filters['balance_account'] ?? null);
        $hasPaymentAccount = $request->filled('balance_account');
        $paymentOverview = $hasPaymentAccount ? $this->paymentBalances->dashboard() : $paymentBalance;
        $activeBalanceTab = old('balance_tab', $filters['balance_tab'] ?? ($hasPaymentAccount ? 'credit-card' : 'paypal'));
        if (! in_array($activeBalanceTab, ['paypal', 'credit-card'], true)) {
            $activeBalanceTab = 'paypal';
        }

        return response()->view('portals.finance.home', [
            ...$context, ...$paypalBalance, 'paypalBalance' => $paypalBalance,
            'paymentBalance' => $paymentBalance, 'paymentOverview' => $paymentOverview, 'activeBalanceTab' => $activeBalanceTab,
            'balanceFormatter' => $this->paymentBalances,
            'creditAccounts' => FinancePaymentAccount::query()->where('account_type', 'credit_card')->orderBy('id')->get(),
            'paymentAccountEditors' => $context['canManage'] ? $this->paymentAccountEditors($paypalBalance['cards']) : [],
            'paymentAccountActivities' => $this->paymentAccountActivities($paypalBalance['cards']),
            'activityCategories' => $this->workbooks->database()->table('finance_transaction_types')->pluck('category')
                ->map(fn ($category) => $category === null || $category === '' ? __('finance.records.unclassified') : $category)
                ->push(__('finance.records.unclassified'))->unique()->sort()->values(),
            'recentSyncLogs' => $context['canManage'] ? $this->workbooks->database()->table('finance_sync_logs as l')->leftJoin('finance_payment_accounts as a', 'a.id', '=', 'l.payment_account_id')->orderByDesc('l.id')->limit(20)->get(['l.operation', 'l.status', 'l.error_code', 'l.page_count', 'l.record_count', 'l.created_at', 'a.account_name']) : collect(),
        ]);
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
            $result['orders'] = app(\App\Portals\Finance\FinancePayPalSyncService::class)->refreshOrders($account, (int) $context['user']->getKey());
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

    public function deletePaymentAccount(Request $request, string $accountKey): Response|JsonResponse
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $account = FinancePaymentAccount::query()->findOrFail(substr($accountKey, 8));
        $db = $this->workbooks->database();
        $db->transaction(function () use ($account): void {
            $account = FinancePaymentAccount::query()->lockForUpdate()->findOrFail($account->id);
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
        unset($data['paypal_currency'], $data['balance_account'], $data['balance_tab']);
        $account = FinancePaymentAccount::query()->create([
            ...$data, 'account_code' => 'FA-'.Str::ulid(), 'activity_account' => $data['account_name'],
            'created_by' => (int) $context['user']->getKey(), 'updated_by' => (int) $context['user']->getKey(),
        ]);

        return redirect()->route('finance.home', [
            ...$displayOptions, 'balance_tab' => $data['account_type'] === 'paypal' ? 'paypal' : 'credit-card',
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
        unset($data['paypal_currency'], $data['balance_account'], $data['balance_tab']);
        $db = $this->workbooks->database();
        $db->transaction(function () use ($account, $data, $context, $db): void {
            $account = FinancePaymentAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($data['account_type'] !== $account->account_type
                && ($db->table('finance_balance_snapshots')->where('payment_account_id', $account->id)->exists()
                    || $db->table('finance_payment_transactions')->where('payment_account_id', $account->id)->exists())) {
                throw ValidationException::withMessages(['account_type' => __('finance.payment_accounts.linked_type_locked')])->errorBag('paymentAccount');
            }
            $account->update([...$data, 'updated_by' => (int) $context['user']->getKey()]);
        });

        return redirect()->route('finance.home', [
            ...$displayOptions, 'balance_tab' => $data['account_type'] === 'paypal' ? 'paypal' : 'credit-card',
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
            'account_name' => ['required', 'string', 'max:100', Rule::unique($db->getName().'.finance_payment_accounts', 'account_name')->whereNull('deleted_at')->ignore($account?->id)],
            'account_type' => ['required', Rule::in(['paypal', 'credit_card'])],
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
            'balance_tab' => ['nullable', Rule::in(['paypal', 'credit-card'])],
            'paypal_currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'balance_account' => 'nullable|string|max:100',
        ], [], trans('finance.payment_accounts.attributes'));
        $cards = $this->paypalBalances->dashboard()['cards'];
        $currentKey = $account ? 'finance-'.$account->id : null;
        if ($cards->contains(fn ($card) => $card['key'] !== $currentKey && (mb_strtolower($card['name']) === mb_strtolower($data['account_name']) || mb_strtolower($card['activity_account']) === mb_strtolower($data['account_name'])))) {
            throw ValidationException::withMessages(['account_name' => __('finance.paypal_profile.duplicate_name')])->errorBag('paymentAccount');
        }
        if (! $isPayPal) {
            foreach (['relay_url', 'relay_certificate_sha1', 'relay_hmac_key_id', 'relay_hmac_secret', 'relay_server_remark'] as $field) {
                $data[$field] = null;
            }
        } elseif (! isset($data['relay_hmac_secret']) || $data['relay_hmac_secret'] === '') {
            unset($data['relay_hmac_secret']);
        }

        return $data;
    }

    private function paymentAccountEditors(\Illuminate\Support\Collection $cards): array
    {
        $accounts = FinancePaymentAccount::query()->get()->keyBy('id');
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
            'balance_tab' => ['nullable', Rule::in(['paypal', 'credit-card'])],
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
            'balance_tab' => ['nullable', Rule::in(['paypal', 'credit-card'])],
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
        if (in_array($dataset, ['paypal-orders', 'xuzong2-orders'], true) && $request->has('order_detail')) {
            $detail = $request->validate(['order_detail' => 'required|integer|min:1']);
            $record = $dataset === 'xuzong2-orders'
                ? app(\App\Portals\Finance\FinanceLogisticsCreditOrders::class)->query()->where('id', $detail['order_detail'])->firstOrFail()
                : FinanceRecord::query()->where('dataset', $dataset)->findOrFail($detail['order_detail']);
            $metadata = $dataset === 'xuzong2-orders' ? [$record->id => ['logistics_order_id' => $record->id]] : app(\App\Portals\Finance\FinanceSettlementService::class)->logisticsMetadata(collect([$record]), 'paypal');
            $order = isset($metadata[$record->id]['logistics_order_id']) ? \App\Models\LogisticsOrder::query()->with([
                'items' => fn ($query) => $query->with('skuMapping')->orderBy('id'),
                'paypalAccount', 'paypalRefunds', 'paypalDisputes', 'paymentReferences', 'creditCardRefunds', 'logs',
            ])->findOrFail($metadata[$record->id]['logistics_order_id']) : null;
            if (! $order) {
                $order = new \App\Models\LogisticsOrder([
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
                $order->setRelation('paypalAccount', null);
            }
            $decode = static fn ($value) => is_array($value) ? $value : (json_decode((string) $value, true) ?: []);

            $html = view('portals.finance.partials.order-detail', [
                'order' => $order,
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
            $values = $this->recordsQuery($dataset, $filters)->whereNotNull($column)->where($column, '!=', '')
                ->where($column, 'like', '%'.($suggestion['suggestion_q'] ?? '').'%')
                ->select($column.' as choice')->distinct()->orderBy('choice')->limit(20)->pluck('choice');

            return response()->json($values->map(fn ($value) => ['value' => (string) $value, 'label' => FinanceCatalog::simplified((string) $value)]));
        }
        $query = $this->recordsQuery($dataset, $filters);
        $exportAccounts = (clone $query)->selectRaw('account, count(*) as total')->groupBy('account')->orderBy('account')->get();
        $records = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10))->withQueryString();
        $types = $this->workbooks->database()->table('finance_transaction_types')->pluck('category', 'raw_type');
        $groups = $this->workbooks->domainGroups()->keyBy('domain');
        $paymentAccounts = FinancePaymentAccount::query()
            ->where('account_type', $definition['channel'] === 'paypal' ? 'paypal' : 'credit_card')
            ->orderBy('id')->get(['activity_account', 'account_name']);
        $accounts = $paymentAccounts->pluck('activity_account')->unique()->values();
        $accountLabels = $paymentAccounts->pluck('account_name', 'activity_account');
        if ($dataset === 'xuzong2-orders') {
            $accounts = app(\App\Portals\Finance\FinanceLogisticsCreditOrders::class)->query()
                ->select('account')->distinct()->orderBy('account')->pluck('account')->filter()->values();
        }

        $paypalOrderMetadata = $dataset === 'paypal-orders'
            ? app(\App\Portals\Finance\FinanceSettlementService::class)->logisticsMetadata($records->getCollection(), 'paypal') : [];
        if ($dataset === 'xuzong2-orders' && $records->isNotEmpty()) {
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
            if ($db->getSchemaBuilder()->hasTable('logistics_orders')) {
                $available = $db->getSchemaBuilder()->getColumnListing('logistics_orders');
                foreach (['store_name', 'assigned_owner_name', 'group_names', 'staff_status', 'source_shop_domain', 'subscription_type', 'payment_method', 'logistics_status', 'data_status'] as $field) {
                    if (in_array($field, $available, true)) {
                        $fixedFilterOptions[$field] = $db->table('logistics_orders')->whereNotNull($field)->where($field, '!=', '')
                            ->distinct()->orderBy($field)->pluck($field)->map(fn ($value) => ['value' => (string) $value, 'label' => FinanceCatalog::simplified((string) $value)]);
                    }
                }
            }
        }

        return response()->view('portals.finance.records', [
            ...$context, ...compact('dataset', 'definition', 'filters', 'records', 'types', 'groups'),
            'accounts' => $accounts,
            'accountLabels' => $accountLabels,
            'paypalOrderMetadata' => $paypalOrderMetadata,
            'paypalOrderItems' => $paypalOrderItems,
            'fixedFilterOptions' => $fixedFilterOptions,
            'conditionChoices' => $this->conditionChoices($dataset),
            'numericFilterFields' => FinanceCatalog::numericFilterFields($dataset),
            'exportAccounts' => $exportAccounts,
            'canImport' => FinanceCatalog::supportsImport($dataset),
            'accountingTransactions' => $dataset === 'paypal-orders' ? $this->workbooks->database()->table('finance_payment_transactions')->whereIn('finance_record_id', $records->pluck('id'))->get(['finance_record_id', 'category', 'direction', 'monthly_rate', 'cny_amount'])->keyBy('finance_record_id') : collect(),
            'timezones' => FinanceWorkbookService::TIMEZONES,
            'filterFields' => FinanceCatalog::filterFieldLabels($dataset),
            'filterOperators' => ['equals', 'contains', 'not_equals', 'empty', 'not_empty', 'gt', 'gte', 'lt', 'lte'],
            'categories' => $types->merge($this->workbooks->database()->table('finance_payment_transactions')->whereNotNull('category')->pluck('category'))->filter()->unique()->sort()->values(),
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
        $rows = $this->workbooks->read($data['file'], $definition['headers'], $sheetName);
        $count = $this->workbooks->importRecords($dataset, $rows, trim($data['account']), $data['source_timezone'], (int) $context['user']->getKey());

        return redirect('/finance/records/'.$dataset)->with('success', __('finance.imported', ['count' => $count]));
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
        $selection = $request->validate([
            'export_scope' => ['required', Rule::in(['accounts', 'records', 'filtered'])],
            'selected_accounts' => 'nullable|array|max:100',
            'selected_accounts.*' => 'required|string|max:191|distinct',
            'selected_ids' => 'nullable|array|max:100',
            'selected_ids.*' => 'required|integer|min:1|distinct',
            'report_columns' => 'required_with:report_fields_customized|array|min:1|max:'.(count($definition['headers']) + ($dataset === 'xuzong2-orders' ? 3 : 4)),
            'report_columns.*' => ['required', 'integer', 'distinct', Rule::in(range(0, count($definition['headers']) + ($dataset === 'xuzong2-orders' ? 2 : 3)))],
        ], ['export_scope.required' => __('finance.records.selection_required')]);
        $selected = $selection['export_scope'] === 'accounts' ? 'selected_accounts' : 'selected_ids';
        if ($selection['export_scope'] !== 'filtered' && empty($selection[$selected])) {
            throw ValidationException::withMessages([$selected => __('finance.records.selection_required')]);
        }
        $query = $this->recordsQuery($dataset, $filters);
        if ($selection['export_scope'] !== 'filtered') {
            $query->whereIn($selected === 'selected_accounts' ? 'account' : 'id', $selection[$selected]);
        }
        if (! (clone $query)->exists()) {
            throw ValidationException::withMessages([$selected => __('finance.records.selection_empty')]);
        }
        if (($filters['export_format'] ?? 'xlsx') === 'xls' && (clone $query)->count() > 65535) {
            throw ValidationException::withMessages(['export_format' => __('finance.records.xls_row_limit')]);
        }
        $types = $this->workbooks->database()->table('finance_transaction_types')->pluck('category', 'raw_type');
        $groups = $this->workbooks->domainGroups()->keyBy('domain');
        $accountingRows = $dataset === 'xuzong2-orders' ? collect() : $this->workbooks->database()->table('finance_payment_transactions')->whereIn('finance_record_id', (clone $query)->select('id'))->get(['finance_record_id', 'category', 'direction', 'monthly_rate', 'cny_amount'])->keyBy('finance_record_id');
        $accountLabels = FinancePaymentAccount::query()
            ->where('account_type', $definition['channel'] === 'paypal' ? 'paypal' : 'credit_card')
            ->pluck('account_name', 'activity_account');
        $rows = $query->orderBy('id')->cursor()->map(function ($record) use ($dataset, $definition, $filters, $types, $groups, $accountingRows, $accountLabels) {
            $values = array_map(fn ($index, $header) => in_array('column_'.$index, FinanceCatalog::numericFilterFields($dataset), true) || in_array(FinanceCatalog::simplified($header), ['数量', '物品标题', '主旨', '团队'], true) || ($definition['channel'] === 'paypal' && in_array($index, [14, $definition['status']], true))
                ? FinanceCatalog::displayValue($dataset, $index, $record->payload[$header] ?? '')
                : ($record->payload[$header] ?? ''), array_keys($definition['headers']), $definition['headers']);
            if ($definition['channel'] === 'paypal' && isset($definition['type'])) {
                $values[$definition['type']] = \App\Portals\Finance\FinancePayPalEventTypes::label((string) $values[$definition['type']]);
            }
            $group = $groups->get($record->domain);
            if ($group && isset($definition['owner'])) {
                $values[$definition['owner']] = $group->owner;
                $values[$definition['team']] = $group->team;
            }

            $accounting = $accountingRows->get($record->id);
            $typeCategory = $types->get($record->raw_type) ?? '';
            $category = array_key_exists('type_category', $filters)
                ? ($typeCategory !== '' ? $typeCategory : __('finance.records.unclassified'))
                : ($accounting ? ($accounting->category ?: __('finance.records.unclassified')) : ($record->raw_type ? ($types->get($record->raw_type) ?: __('finance.records.unclassified')) : ''));

            return [...$values, $accountLabels->get($record->account, $record->account), ...($dataset === 'xuzong2-orders' ? [] : [$category]), $record->occurred_at->setTimezone($filters['timezone'])->format('Y-m-d H:i:s'), $filters['timezone'],
                ...($accountingRows->isEmpty() ? [] : [$accounting?->direction ? __('finance.accounting.directions.'.$accounting->direction) : '', $accounting?->monthly_rate ?? '待配置', $accounting?->cny_amount ?? '待配置'])];
        });

        $headers = [...$definition['headers'], __($definition['channel'] === 'paypal' ? 'finance.fields.paypal_account' : 'finance.fields.account'), ...($dataset === 'xuzong2-orders' ? [] : [__('finance.fields.category')]), __('finance.fields.display_time'), __('finance.fields.timezone')];
        if ($accountingRows->isNotEmpty()) {
            $headers = [...$headers, '收支方向', '月度人民币汇率', '折合人民币'];
        }
        if (isset($selection['report_columns'])) {
            $columns = array_map('intval', $selection['report_columns']);
            $headers = array_map(fn ($index) => $headers[$index], $columns);
            $rows = $rows->map(fn ($values) => array_map(fn ($index) => $values[$index], $columns));
        }
        $filename = $dataset.'-'.now()->format('Ymd-His');

        return match ($filters['export_format'] ?? 'xlsx') {
            'xls' => $this->workbooks->download($headers, $rows, $filename.'.xls', 'xls'),
            'csv' => $this->workbooks->downloadDelimited($headers, $rows, $filename.'.csv'),
            'tsv' => $this->workbooks->downloadDelimited($headers, $rows, $filename.'.tsv', "\t"),
            default => $this->workbooks->download($headers, $rows, $filename.'.xlsx'),
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
            $filtered = $types->filter(function ($row) use ($data): bool {
                $category = $data['category'] ?? '';
                if ($category === '__unclassified' ? filled($row->category) : ($category !== '' && $row->category !== $category)) {
                    return false;
                }
                $search = FinanceCatalog::simplified($data['q'] ?? '');

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
        $groups = $this->workbooks->domainGroups()->filter(fn ($row) => $search === '' || mb_stripos(
            FinanceCatalog::simplified($row->domain.' '.$row->owner.' '.$row->team), $search
        ) !== false)->values();
        $perPage = (int) ($data['per_page'] ?? 10);
        $page = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage();

        return response()->view('portals.finance.settings', [
            ...$context, 'kind' => $kind, 'canManage' => false,
            'categoryOptions' => collect(),
            'rows' => new \Illuminate\Pagination\LengthAwarePaginator(
                $groups->forPage($page, $perPage)->values(), $groups->count(), $perPage, $page,
                ['path' => $request->url(), 'query' => $request->query()]
            ),
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
            if ($action === 'rule') {
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

    public function report(Request $request, string $report): Response
    {
        if (in_array($report, ['paypal-settlement', 'credit-settlement'], true)) {
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
            'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'], 'channel' => ['nullable', Rule::in(['paypal', 'credit_card', 'combined'])],
            'team' => 'nullable|string|max:100', 'subscription' => 'nullable|string|max:100', 'download' => 'nullable|boolean',
            'conditions' => 'nullable|array|max:10', 'conditions.*' => 'array:field,operator,value',
            'conditions.*.field' => ['required', Rule::in(array_keys(\App\Portals\Finance\FinanceSettlementService::CONDITION_FIELDS))],
            'conditions.*.operator' => 'required|in:equals', 'conditions.*.value' => 'required|string|max:191',
        ]);
        $tab = $filters['channel'] ?? 'paypal';
        $creditMonths = $tab === 'credit_card' ? $this->creditImportMonths($filters['payment_account_id'] ?? null) : [];
        $filters['month'] ??= $creditMonths[0] ?? now('Asia/Shanghai')->format('Y-m');
        $accounts = FinancePaymentAccount::query()->orderBy('account_name')->get();
        $account = $accounts->firstWhere('id', $filters['payment_account_id'] ?? null);
        $month = CarbonImmutable::parse($filters['month'].'-01', 'Asia/Shanghai');
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $summary = app(\App\Portals\Finance\FinanceTransactionSummary::class);
        $rows = collect();
        $channels = $tab === 'combined' ? ['paypal', 'credit_card'] : [$tab];
        foreach ($channels as $channel) {
            if (isset($filters['payment_account_id']) && (! $account || $account->account_type !== $channel)) {
                continue;
            }
            $rows = $rows->concat($service->rows($channel, [
                'from' => $month->toDateString(), 'to' => $month->endOfMonth()->toDateString(),
                'timezone' => 'Asia/Shanghai', 'account' => $account?->activity_account,
                'team' => $filters['team'] ?? null, 'subscription' => $filters['subscription'] ?? null,
                'logistics_costs' => true,
            ]));
        }
        $rows = $rows->filter(fn ($row) => empty($filters['currency']) || $row['currency'] === $filters['currency']);
        if ($request->has('suggestion_field')) {
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
        $rows = $rows->filter(fn ($row) => $service->matches($row, $filters['conditions'] ?? []));
        $columns = $summary->columns($tab);
        $items = $summary->summarize($service->withLogisticsCosts($rows), $tab);
        if ($request->boolean('download')) {
            $export = \App\Portals\Finance\FinanceExportSelection::filter($request, $items)->map(fn ($row) => array_map(fn ($field) => $row[$field] ?? null, array_keys($columns)));

            return $this->workbooks->download(array_values($columns), $export, 'finance-transactions-'.$tab.'-'.$filters['month'].'.xlsx');
        }
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
            if ($account && $key !== 'combined' && $account->account_type !== $key) {
                unset($query['payment_account_id']);
            }
            $tabUrls[$key] = route('finance.report', ['report' => $report, ...$query, 'view' => 'transactions', 'channel' => $key]);
        }

        return response()->view('portals.finance.report', [
            ...$context, 'report' => $report, 'filters' => $filters, 'columns' => $columns, 'items' => $items, 'paginator' => $paginator,
            'tab' => $tab, 'tabUrls' => $tabUrls, 'summaryTabs' => \App\Portals\Finance\FinanceTransactionSummary::VIEWS,
            'creditMonths' => $creditMonths,
            'currencyOptions' => FinanceRecord::query()->whereIn('dataset', ['paypal-orders', 'xuzong2-orders', 'xuzong2-details', 'xuzong1-details'])
                ->whereNotNull('currency')->distinct()->pluck('currency')
                ->map(fn ($currency) => strtoupper(trim($currency)))
                ->filter(fn ($currency) => preg_match('/^[A-Z]{3}$/D', $currency))->unique()->sort()->values(),
            'subscriptionOptions' => $service->subscriptionOptions($rows),
            'conditionFields' => $service::CONDITION_FIELDS,
            'reportAccounts' => $accounts->filter(fn ($account) => $tab === 'combined' || $account->account_type === $tab),
            'reportTabUrls' => $this->reportTabUrls([...$filters, 'channel' => $tab]),
            'isLocked' => $this->workbooks->database()->table('finance_period_locks')->where('month', $filters['month'])->whereNotNull('report_snapshot')->exists(),
        ]);
    }

    private function settlementFilters(Request $request): array
    {
        return [...$request->validate([
            'account' => 'nullable|string|max:191', 'reference' => 'nullable|string|max:191',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'timezone' => ['nullable', Rule::in(FinanceWorkbookService::TIMEZONES)],
            'team' => 'nullable|string|max:100', 'subscription' => 'nullable|string|max:100', 'domain' => 'nullable|string|max:191',
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
            'source' => ['nullable', Rule::in(['xuzong2-orders', 'xuzong2-details', 'xuzong1-details'])],
            'conditions' => 'nullable|array|max:10', 'conditions.*' => 'array:field,operator,value',
            'conditions.*.field' => ['required', Rule::in(['account','reference','team','owner','domain','subscription','gross','settlement','fee','refund','note'])],
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
        $filters = $this->settlementFilters($request);
        foreach ($filters['conditions'] ?? [] as $condition) {
            if (in_array($condition['operator'], ['gt','gte','lt','lte'], true)
                && (! in_array($condition['field'], ['gross','settlement','fee','refund'], true) || $service->number($condition['value'] ?? null) === null)) {
                throw ValidationException::withMessages(['conditions' => '大小比较请选择金额字段并填写有效数字。']);
            }
        }
        $columns = $service->columns($channel);
        if ($request->boolean('template')) {
            $templateHeaders = array_values($service->importFields($channel));
            return $this->workbooks->download($templateHeaders, [], $report.'-template.xlsx');
        }
        $rows = $service->rows($channel, $filters);
        if ($request->has('suggestion_field')) {
            $suggestion = $request->validate([
                'suggestion_field' => ['required', Rule::in(['account','reference','team','owner','domain','subscription','gross','settlement','fee','refund','note'])],
                'suggestion_q' => 'nullable|string|max:191',
            ]);
            $search = FinanceCatalog::simplified($suggestion['suggestion_q'] ?? '');
            $values = $rows->pluck($suggestion['suggestion_field'])->filter(fn ($value) => $value !== null && $value !== ''
                && ($search === '' || mb_stripos(FinanceCatalog::simplified((string) $value), $search) !== false))
                ->unique()->sort()->take(20)->values();

            return response()->json($values->map(fn ($value) => ['value' => (string) $value, 'label' => FinanceCatalog::simplified((string) $value)]));
        }
        if ($request->boolean('download')) {
            $rows = \App\Portals\Finance\FinanceExportSelection::filter($request, $rows);
            $format = $filters['format'] ?? 'xlsx';
            if ($format === 'xls' && $rows->count() > 65535) {
                throw ValidationException::withMessages(['format' => 'XLS最多65535条，请使用XLSX。']);
            }
            return $this->workbooks->download(array_values($columns), $rows->map(fn ($row) => array_map(fn ($field) => $row[$field] ?? '', array_keys($columns))), $report.'.'.$format, $format);
        }
        $perPage = (int) ($filters['per_page'] ?? 10);
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return response()->view('portals.finance.settlement', [...$context, 'report' => $report, 'channel' => $channel, 'filters' => $filters,
            'rows' => $paginator, 'columns' => $columns, 'moneyFields' => $service->moneyFields($channel), 'textFields' => $service::TEXT,
            'conditionFields' => $service::CONDITION_FIELDS,
            'subscriptionOptions' => $service->subscriptionOptions($rows),
            'accounts' => FinancePaymentAccount::query()->where('account_type', $channel)->orderBy('id')->get(),
            'teams' => $this->workbooks->domainGroups()->pluck('team')->filter()->unique()->sort()->values(),
        ]);
    }

    private function settlementValues(array $input, string $channel): array
    {
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $rules = array_fill_keys(array_keys($service->moneyFields($channel)), ['nullable', 'numeric', 'regex:/^\d{1,18}(?:\.\d{1,8})?$/', 'gte:0']);
        $rules['usd_rate'] = ['nullable', 'numeric', 'regex:/^\d{1,10}(?:\.\d{1,8})?$/', 'gt:0'];
        if ($channel === 'credit_card') {
            $rules['settlement'] = ['nullable', 'numeric', 'regex:/^\d{1,18}(?:\.\d{1,8})?$/', 'gte:0'];
        }
        foreach ($service::TEXT as $field => $label) {
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
        $record = $service->query($channel, [])->findOrFail($request->input('id'));
        try {
            $service->save($record, $this->settlementValues($request->all(), $channel), (int) $context['user']->getKey());
        } catch (\App\Portals\Finance\FinanceRelayException $e) {
            throw ValidationException::withMessages(['month' => $e->getMessage()]);
        }

        $returnFilters = $this->settlementFilters(Request::create('/', 'GET', $request->query()));

        return redirect()->route('finance.report', ['report' => $report, ...$returnFilters])->with('success', '结算明细已保存。');
    }

    public function importSettlement(Request $request, string $report): Response
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }
        $request->validate(['file' => 'required|file|mimes:xlsx,csv,txt|max:10240']);
        $channel = $report === 'paypal-settlement' ? 'paypal' : 'credit_card';
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        if ($request->input('import_kind') === 'source') {
            abort_unless($channel === 'credit_card', 422);
            $data = $request->validate(['source' => ['required', Rule::in(['xuzong2-details', 'xuzong1-details'])],
                'account' => 'required|string|max:191', 'timezone' => ['required', Rule::in(FinanceWorkbookService::TIMEZONES)]]);
            abort_unless(FinancePaymentAccount::query()->where('account_type', 'credit_card')->where('activity_account', $data['account'])->exists(), 422);
            $definition = FinanceCatalog::dataset($data['source']);
            $rows = $this->workbooks->read($request->file('file'), $definition['headers']);
            try {
                $count = $this->workbooks->database()->transaction(function () use ($rows, $data, $context): int {
                    $count = $this->workbooks->importRecords($data['source'], $rows, $data['account'], $data['timezone'], (int) $context['user']->getKey());
                    $months = FinanceRecord::query()->where('dataset', $data['source'])->where('account', $data['account'])
                        ->whereIn('reference', array_column($rows, FinanceCatalog::dataset($data['source'])['headers'][FinanceCatalog::dataset($data['source'])['reference']]))
                        ->get()->map(fn ($record) => $record->occurred_at->setTimezone('Asia/Shanghai')->format('Y-m'))->unique();
                    foreach ($months as $month) {
                        app(\App\Portals\Finance\FinanceAccountingService::class)->guardUnlocked($month);
                    }

                    return $count;
                });
            } catch (\App\Portals\Finance\FinanceRelayException $e) {
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }

            return redirect()->route('finance.report', $report)->with('success', '已处理 '.$count.' 条信用卡明细。');
        }
        $legacyFields = [...$service::TEXT, ...$service->moneyFields($channel)];
        $fields = $service->importFields($channel);
        $sourceHeaders = $channel === 'credit_card' ? ['交易单号', '交易时间', '处理时间', '商户名称', 'Source（域名）', '交易币种', '交易金额', '支付状态', '发行国', '拒绝原因', '邮箱', '年月', '（-8）时间点', '订阅类型', '结算-美元'] : [];
        $importHeaders = array_values(array_unique(['记录ID', '支付账户', ...array_values($fields), ...array_values($legacyFields), ...$sourceHeaders]));
        $rows = $this->workbooks->read($request->file('file'), $importHeaders, $channel === 'paypal' ? '贝宝导入模版' : '徐总支付导入模版', $importHeaders);
        $scope = $request->validate(['account' => 'nullable|string|max:191', 'import_month' => 'nullable|date_format:Y-m']);
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
        try {
            $db->transaction(function () use ($rows, $fields, $service, $channel, $context, $scope): void {
                $seen = [];
                foreach ($rows as $index => $row) {
                    $id = $row['记录ID'];
                    if ($id === '') {
                        $reference = $row[$channel === 'paypal' ? '交易 ID' : '平台订单号'] ?? '';
                        $merchant = $channel === 'credit_card' ? ($row['商户订单号'] ?? '') : '';
                        $matches = $service->query($channel, []);
                        if ($reference !== '') {
                            $matches->where('reference', $reference);
                        }
                        if ($merchant !== '') {
                            $matches->where(fn ($query) => $query->where('payload->商户订单号', $merchant)->orWhere('payload->Shopify订单号', $merchant));
                        }
                        if ($channel === 'credit_card' && filled($row['交易时间'] ?? null)) {
                            try {
                                $sourceDate = is_numeric($row['交易时间'])
                                    ? CarbonImmutable::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $row['交易时间'], new \DateTimeZone('Asia/Shanghai')))
                                    : CarbonImmutable::parse($row['交易时间'], 'Asia/Shanghai');
                            } catch (\Throwable) {
                                throw ValidationException::withMessages(['file' => '第'.$index.'行交易时间无效。']);
                            }
                            $matches->where('occurred_at', $sourceDate->utc());
                            if (filled($row['支付状态'] ?? null)) {
                                $matches->where('status', $row['支付状态']);
                            }
                        }
                        if (filled($row['支付账户'] ?? null)) {
                            $accounts = FinancePaymentAccount::query()->where('account_type', $channel)
                                ->where(fn ($query) => $query->where('account_name', $row['支付账户'])->orWhere('activity_account', $row['支付账户']))
                                ->pluck('activity_account');
                            $matches->whereIn('account', $accounts);
                        }
                        $candidates = $matches->limit(2)->get();
                        if ($candidates->isEmpty() && $channel === 'credit_card' && $reference !== '' && $merchant !== '') {
                            $account = FinancePaymentAccount::query()->where('account_type', 'credit_card')
                                ->where('activity_account', $scope['account'] ?? '')->first();
                            $sourceMonth = $row['年月'] ?? '';
                            $month = preg_match('/^\d{4}-\d{2}$/', $sourceMonth) ? $sourceMonth : ($scope['import_month'] ?? null);
                            if (! $account || blank($month)) {
                                throw ValidationException::withMessages(['file' => '新增信用卡记录时请选择支付账户和所属月份。']);
                            }
                            $date = $row['交易时间'] ?? '';
                            try {
                                $occurred = $date === '' ? CarbonImmutable::parse($month.'-01', 'Asia/Shanghai')
                                    : (is_numeric($date) ? CarbonImmutable::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $date, new \DateTimeZone('Asia/Shanghai')))
                                        : CarbonImmutable::parse($date, 'Asia/Shanghai'));
                            } catch (\Throwable) {
                                throw ValidationException::withMessages(['file' => '第'.$index.'行交易时间无效。']);
                            }
                            app(\App\Portals\Finance\FinanceAccountingService::class)->guardUnlocked($occurred->format('Y-m'));
                            $payload = array_fill_keys(FinanceCatalog::dataset('xuzong2-details')['headers'], '');
                            $payload = [...$payload, '商户订单号' => $merchant, '平台订单号' => $reference,
                                '交易币种' => $row['交易币种'] ?: 'USD', '商户名称' => $row['商户名称'] ?? '',
                                '交易金额' => $row['交易金额'] ?? '', '支付状态' => $row['支付状态'] ?? '',
                                '创建时间' => $date === '' ? '' : $occurred->format('Y-m-d H:i:s')];
                            if ($date === '') {
                                $payload['_manual_import_month'] = $month;
                            }
                            $created = FinanceRecord::create([
                                'dataset' => 'xuzong2-details', 'account' => $account->activity_account, 'reference' => $reference,
                                'occurred_at' => $occurred->utc(),
                                'source_timezone' => 'Asia/Shanghai', 'currency' => $payload['交易币种'], 'status' => $payload['支付状态'] ?: null,
                                'domain' => strtolower($row['域名'] ?: ($row['Source（域名）'] ?? '')) ?: null,
                                'fingerprint' => hash('sha256', json_encode(['manual-credit', $account->activity_account, $merchant, $reference,
                                    $date === '' ? null : $occurred->utc()->toIso8601String(), $payload['支付状态']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                                'payload' => $payload, 'imported_by' => (int) $context['user']->getKey(),
                            ]);
                            $candidates = collect([$created]);
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
                }
            });
        } catch (\App\Portals\Finance\FinanceRelayException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return redirect()->route('finance.report', $report)->with('success', '已处理 '.count($rows).' 条结算明细。');
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
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }
        $data = $request->validate(['month' => 'nullable|date_format:Y-m', 'month_from' => 'nullable|date_format:Y-m', 'month_to' => 'nullable|date_format:Y-m|after_or_equal:month_from', 'channel' => ['nullable', Rule::in(['paypal', 'credit_card', 'combined'])],
            'layer' => ['nullable', Rule::in(['project', 'account', 'channel', 'team', 'subscription', 'detail', 'business', 'classification', 'project_classification', 'payment_method'])],
            'account' => 'nullable|string|max:191', 'owner' => 'nullable|string|max:191',
            'team' => 'nullable|string|max:100', 'subscription' => 'nullable|string|max:100', 'payment_method' => 'nullable|string|max:100']);
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
        $monthlyLossGroups = [];
        foreach ($comparisonMonths as $comparisonMonth) {
        $start = CarbonImmutable::parse($comparisonMonth.'-01', 'Asia/Shanghai');
        $service = app(\App\Portals\Finance\FinanceSettlementService::class);
        $filters = [...$data, 'from' => $start->toDateString(), 'to' => $start->endOfMonth()->toDateString(), 'timezone' => 'Asia/Shanghai',
            'logistics_costs' => $report === 'losses'];
        $rows = collect();
        foreach (['paypal', 'credit_card'] as $channel) {
            if (empty($data['channel']) || $data['channel'] === 'combined' || $data['channel'] === $channel) {
                $rows = $rows->concat($service->rows($channel, \Illuminate\Support\Arr::except($filters, ['account', 'owner', 'team', 'subscription'])));
            }
        }
        if ($comparisonMonth === $month) {
        $filterOptions = [
            'account' => $rows->whereIn('account_key', FinancePaymentAccount::query()->pluck('activity_account'))
                ->unique('account_key')->map(fn ($row) => ['value' => $row['account_key'], 'label' => $row['account']])->values(),
        ];
        foreach (['owner', 'team', 'subscription', 'payment_method'] as $field) {
            $values = $field === 'subscription' ? $service->subscriptionOptions($rows) : $rows->pluck($field)->filter()->unique()->sort();
            $filterOptions[$field] = $values->map(fn ($value) => ['value' => $value, 'label' => $value])->values();
        }
        }
        $rows = $rows->filter(function ($row) use ($data, $service): bool {
            foreach (['account' => 'account_key', 'owner' => 'owner', 'team' => 'team', 'subscription' => 'subscription', 'payment_method' => 'payment_method'] as $filter => $field) {
                if (filled($data[$filter] ?? null) && $row[$field] !== ($filter === 'subscription' ? $service::subscriptionLabel($data[$filter]) : $data[$filter])) {
                    return false;
                }
            }

            return true;
        });
        if (($data['channel'] ?? null) === 'combined') {
            $data['layer'] = $report === 'losses' && in_array($data['layer'] ?? '', ['business', 'classification', 'project_classification'], true)
                ? 'project_classification' : 'project';
        } elseif (in_array($data['channel'] ?? null, ['paypal', 'credit_card'], true) && ($data['layer'] ?? null) === 'project') {
            $data['layer'] = 'channel';
        }
        $groups = $service->totals($report === 'losses' ? $service->withLogisticsCosts($rows) : $rows, $data['layer'] ?? 'account');
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
                    $amount = bcadd($amount, $group['dispute_fee'], 8);
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
        $monthlyLossGroups[$comparisonMonth] = $groups;
        }
        if ($request->boolean('download') && $report === 'losses') {
            $export = [];
            $selectionKeys = \App\Portals\Finance\FinanceExportSelection::keys($request);
            $panels = collect($monthlyLossGroups)->flatten(1)->unique(fn ($group) => $group['name'].'|'.$group['channel']);
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
            $export = array_map(fn ($group) => $report === 'losses' ? [$group['name'], match ($group['channel']) { 'paypal' => 'PayPal', 'combined' => '全部通道', default => '信用卡' }, $group['success_count'], $group['failed_count'], $group['settlement'], ...array_map(fn ($key) => array_key_exists($key, $group['ratios']) ? ($group['ratios'][$key] ?? '') : '不适用', $metricKeys), $group['total_ratio'] ?? '']
                : [$group['name'], match ($group['channel']) { 'paypal' => 'PayPal', 'combined' => '全部通道', default => '信用卡' }, $group['success_count'], $group['failed_count'], $group['settlement']], $groups);

            return $this->workbooks->download($headers, $export, 'finance-'.$report.'-'.$month.'.xlsx');
        }

        return response()->view('portals.finance.settlement-overview', [...$context, 'report' => $report, 'filters' => [...$data, 'month' => $month], 'groups' => $groups, 'monthlyLossGroups' => $monthlyLossGroups, 'rate' => $rate, 'creditMonths' => $creditMonths, 'filterOptions' => $filterOptions, 'metrics' => [...$service::MONEY, ...$metrics], 'reportTabUrls' => $this->reportTabUrls([...$data, 'month' => $month])]);
    }

    private function reportTabUrls(array $filters): array
    {
        $common = \Illuminate\Support\Arr::only($filters, ['month', 'channel']);
        $account = filled($filters['payment_account_id'] ?? null)
            ? FinancePaymentAccount::find($filters['payment_account_id'])
            : (filled($filters['account'] ?? null) ? FinancePaymentAccount::where('activity_account', $filters['account'])->first() : null);

        return [
            'settlement' => route('finance.report', ['report' => 'summary', ...$common, 'view' => 'settlement', ...($account ? ['account' => $account->activity_account] : [])]),
            'transactions' => route('finance.report', ['report' => 'summary', ...$common, 'view' => 'transactions', ...($account ? ['payment_account_id' => $account->id] : [])]),
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
            $currentPath = $request->getPathInfo() === '/' || $request->routeIs('finance.balances', 'finance.balances.xuzong2.save', 'finance.exchange-rates.save', 'finance.paypal-accounts.update', 'finance.payment-accounts.*')
                ? '/finance' : rtrim($request->getPathInfo(), '/');
            if ($request->routeIs('finance.transactions.raw', 'finance.payment-accounts.sync')) {
                $currentPath = '/finance/records/paypal-orders';
            }
            $domains = app(\App\Portal\Services\PortalDomainService::class);
            $homePath = $domains->portalPath($context['portal']);
            $menu = $context['portal']->menus->first(function ($menu) use ($currentPath, $domains, $context, $homePath): bool {
                $path = trim((string) $menu->path);
                if (! $menu->is_enabled || $path === '' || str_starts_with($path, '#') || preg_match('#^https?://#', $path)) {
                    return false;
                }
                $path = $domains->portalPath($context['portal'], $path);

                return $currentPath === $path || ($path !== $homePath && str_starts_with($currentPath, $path.'/'));
            });
            abort_unless($menu, 403);
            abort_unless(app(\App\Portal\Services\PortalMenuAuthorizationService::class)->canAccess($context['user'], $menu), 403);
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

    private function conditionChoices(string $dataset): array
    {
        $definition = FinanceCatalog::dataset($dataset);
        $choices = [];
        $choices['account'] = FinancePaymentAccount::query()
            ->where('account_type', $definition['channel'] === 'paypal' ? 'paypal' : 'credit_card')->orderBy('id')
            ->get(['activity_account', 'account_name'])->map(fn ($account) => [
                'value' => $account->activity_account, 'label' => FinanceCatalog::simplified($account->account_name),
            ])->all();
        if ($dataset === 'xuzong2-orders') {
            $choices['account'] = $this->recordsQuery($dataset, [])->select('account')->distinct()->orderBy('account')
                ->pluck('account')->filter()->map(fn ($value) => ['value' => $value, 'label' => FinanceCatalog::simplified($value)])->values()->all();
        }
        $fields = ['currency', 'status'];
        if ($definition['channel'] === 'paypal') {
            $fields = [...$fields, 'raw_type', 'balance_impact'];
            $choices['category'] = $this->workbooks->database()->table('finance_transaction_types')
                ->whereNotNull('category')->where('category', '!=', '')->pluck('category')
                ->merge($this->workbooks->database()->table('finance_payment_transactions')->whereNotNull('category')->pluck('category'))
                ->unique()->sort()->values()->map(fn ($value) => ['value' => $value, 'label' => FinanceCatalog::simplified($value)])->all();
            $choices['direction'] = collect(__('finance.accounting.directions'))
                ->map(fn ($label, $value) => compact('value', 'label'))->values()->all();
        }
        foreach ($fields as $field) {
            $column = FinanceCatalog::filterColumn($dataset, $field);
            $values = $this->recordsQuery($dataset, [])->whereNotNull($column)
                ->select($column.' as choice')->distinct()->orderBy('choice')->pluck('choice')->filter(fn ($value) => $value !== '');
            if ($field === 'raw_type') {
                $values = $values->merge($this->workbooks->database()->table('finance_transaction_types')->pluck('raw_type'))->unique();
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
            ] : []),
            'account' => 'nullable|string|max:191', 'reference' => 'nullable|string|max:191',
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
            'from' => [$request->boolean('report_fields_customized') ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'to' => [$request->boolean('report_fields_customized') ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', Rule::in(FinanceWorkbookService::TIMEZONES)],
            'export_format' => ['nullable', Rule::in($request->boolean('report_fields_customized') ? ['xls', 'xlsx'] : ['xls', 'xlsx', 'csv', 'tsv'])],
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

    private function recordsQuery(string $dataset, array $filters): Builder
    {
        $query = $dataset === 'xuzong2-orders'
            ? app(\App\Portals\Finance\FinanceLogisticsCreditOrders::class)->query()
            : FinanceRecord::query()->where('dataset', $dataset);
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
            if ($filters[$field] ?? '') {
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
                if (filled($value) && isset(FinanceCatalog::PAYPAL_ORDER_FILTERS[$field])) {
                    if (in_array($field, FinanceCatalog::PAYPAL_LOGISTICS_FILTERS, true) || ($dataset === 'xuzong2-orders' && (str_starts_with($field, 'column_') || $field === 'sender_email'))) {
                        $linked[$field] = $value;
                        continue;
                    }
                    $column = $field === 'domain' ? 'domain' : FinanceCatalog::filterColumn($dataset, $field);
                    $this->applyCondition($query, $column, in_array($field, ['currency', 'status'], true) ? 'equals' : 'contains', $value);
                }
            }
            if ($linked !== []) {
                $schema = $this->workbooks->database()->getSchemaBuilder();
                if (! $schema->hasTable('logistics_orders')) {
                    $query->whereRaw('1 = 0');
                } else {
                    if ($dataset === 'paypal-orders') {
                        $uniqueMatch = app(\App\Portals\Finance\FinanceSettlementService::class)->paypalOrderMatches()->selectRaw('COUNT(*)');
                        $query->whereRaw('('.$uniqueMatch->toSql().') = 1', $uniqueMatch->getBindings());
                    }
                    $query->whereExists(function ($orders) use ($linked, $dataset): void {
                        if ($dataset === 'xuzong2-orders') {
                            $orders->selectRaw('1')->from('logistics_orders as logistics')->whereColumn('logistics.id', 'finance_records.id');
                        } else {
                            $matches = app(\App\Portals\Finance\FinanceSettlementService::class)->paypalOrderMatches();
                            $orders->selectRaw('1')->from('logistics_orders as logistics')->mergeWheres($matches->wheres, $matches->getBindings());
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
                                $orders->whereExists(fn ($refund) => $refund->selectRaw('1')->from('logistics_paypal_refunds as refund')
                                    ->whereColumn('refund.logistics_order_id', 'logistics.id')->where('refund.refund_transaction_id', 'like', '%'.$value.'%'));
                                continue;
                            }
                            $this->applyCondition($orders, 'logistics.'.$field, 'contains', $value);
                        }
                    });
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
                $this->applyCondition($query, FinanceCatalog::filterColumn($dataset, $condition['field']), $operator, $value);
            }
        }

        return $query;
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
