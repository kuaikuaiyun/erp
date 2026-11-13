<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalDisputeOperation;
use App\Models\LogisticsPayPalTransaction;
use App\Models\LogisticsOrder;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\LogisticsPortalService;
use App\Portals\Logistics\PayPalDisputeAnalysisExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PayPalDisputeAnalysisController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly LogisticsPortalService $logistics,
        private readonly PayPalDisputeAnalysisExportService $exports,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;
        $filters = $this->filters($request);
        $disputes = $this->query($filters)->with([
            'account:id,account_name',
            'order:id,buyer_email,subscription_type,subscription_origin_order_id,is_add_on,subscription_status,logistics_status,tracking_number,order_created_at,paid_amount_usd,tongtu_order_number',
            'order.items:id,logistics_order_id,tongtu_sku,tongtu_product_name,product_title',
            'refundLinks.refund:id,refund_amount_usd,original_amount,currency_code',
            'operations' => fn ($query) => $query->latest('id'),
        ])->orderByDesc('dispute_created_at')->orderByDesc('id')->paginate($filters['per_page'])->withQueryString();
        $transactions = LogisticsPayPalTransaction::query()->whereIn('payment_transaction_id', $disputes->getCollection()->pluck('disputed_transaction_id')->filter()->unique())
            ->get(['logistics_paypal_account_id', 'payment_transaction_id', 'original_amount', 'currency_code'])
            ->keyBy(fn (LogisticsPayPalTransaction $item): string => $this->transactionKey($item->logistics_paypal_account_id, $item->payment_transaction_id));

        return view('portals.logistics.paypal-dispute-analysis.index', [
            ...$this->viewContext($context),
            'disputes' => $disputes,
            'filters' => $filters,
            'transactions' => $transactions,
            'accounts' => LogisticsPayPalAccount::query()->orderBy('account_name')->get(['id', 'account_name']),
            'currencies' => LogisticsPayPalDispute::query()->whereNotNull('currency_code')->where('currency_code', '!=', '')->distinct()->orderBy('currency_code')->pluck('currency_code'),
            'subscriptionTypes' => LogisticsOrder::query()->whereNotNull('subscription_type')->where('subscription_type', '!=', '')->distinct()->orderBy('subscription_type')->pluck('subscription_type'),
        ]);
    }

    public function handle(Request $request, LogisticsPayPalDispute $dispute): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return redirect()->route('logistics.paypal-dispute-analysis.index');
        $data = $request->validate(['subscription_cancelled' => ['nullable', 'in:yes,no'], 'add_on_cancelled' => ['nullable', 'in:yes,no'], 'note' => ['nullable', 'string', 'max:1000'], 'return_to' => ['nullable', 'string', 'max:2000']]);
        $changes = [];
        foreach (['subscription_cancelled', 'add_on_cancelled'] as $field) if (($data[$field] ?? null) !== null) $changes[$field] = $data[$field] === 'yes';
        if ($dispute->first_handled_at === null) $changes += ['first_handled_at' => now(), 'first_handled_by' => $context['user']->getKey(), 'first_handled_by_name' => $context['user']->name];
        if ($changes !== []) $dispute->forceFill($changes)->save();
        LogisticsPayPalDisputeOperation::query()->create(['logistics_paypal_dispute_id' => $dispute->getKey(), 'operator_id' => $context['user']->getKey(), 'operator_name' => $context['user']->name, 'action' => 'manual_handle', 'payload' => ['subscription_cancelled' => $data['subscription_cancelled'] ?? null, 'add_on_cancelled' => $data['add_on_cancelled'] ?? null, 'note' => trim((string) ($data['note'] ?? '')) ?: null]]);
        return $this->indexRedirect($request)->with('success', '争议人工处理信息已保存。');
    }

    public function export(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;

        $data = $request->validate([
            'scope' => ['required', 'in:selected,filtered'],
            'selected_ids' => ['required_if:scope,selected', 'array', 'min:1'],
            'selected_ids.*' => ['integer', 'distinct'],
        ]);
        $disputes = $this->query($this->filters($request));
        if ($data['scope'] === 'selected') $disputes->whereKey($data['selected_ids']);

        if (!(clone $disputes)->exists()) {
            throw ValidationException::withMessages(['scope' => '当前导出范围内没有可导出的 PayPal 争议记录。']);
        }

        return $this->exports->download($disputes);
    }

    private function query(array $filters): Builder
    {
        $query = LogisticsPayPalDispute::query();
        if ($filters['account_id'] !== '') $query->where('logistics_paypal_account_id', $filters['account_id']);
        if ($filters['status'] !== '') $query->where('status', $filters['status']);
        if ($filters['handled'] === 'yes') $query->whereNotNull('first_handled_at');
        if ($filters['handled'] === 'no') $query->whereNull('first_handled_at');
        if ($filters['matched'] === 'yes') $query->whereNotNull('logistics_order_id');
        if ($filters['matched'] === 'no') $query->whereNull('logistics_order_id');
        if ($filters['date_from'] !== '') $query->where('dispute_created_at', '>=', $filters['date_from'].' 00:00:00');
        if ($filters['date_to'] !== '') $query->where('dispute_created_at', '<=', $filters['date_to'].' 23:59:59');
        if ($filters['handled_from'] !== '') $query->where('first_handled_at', '>=', $filters['handled_from'].' 00:00:00');
        if ($filters['handled_to'] !== '') $query->where('first_handled_at', '<=', $filters['handled_to'].' 23:59:59');
        $this->amountRange($query, 'COALESCE(original_amount, dispute_amount_usd)', $filters['dispute_amount_min'], $filters['dispute_amount_max']);
        if ($filters['refund_amount_min'] !== '' || $filters['refund_amount_max'] !== '') {
            $query->whereHas('refundLinks.refund', function (Builder $refund) use ($filters): void {
                $this->amountRange($refund, 'COALESCE(original_amount, refund_amount_usd)', $filters['refund_amount_min'], $filters['refund_amount_max']);
            });
        }
        if ($filters['currency'] !== '') $query->where('currency_code', $filters['currency']);
        if ($filters['type'] === 'INQUIRY') $query->where('life_cycle_stage', 'INQUIRY');
        elseif ($filters['type'] !== '') $query->where('channel', $filters['type']);
        if ($filters['reason'] !== '') $query->where('reason', $filters['reason']);
        if ($filters['outcome'] !== '') $query->where('outcome_code', $filters['outcome']);
        if ($filters['subscription_cancelled'] !== '') $query->where('subscription_cancelled', $filters['subscription_cancelled'] === 'yes');
        if ($filters['add_on_cancelled'] !== '') $query->where('add_on_cancelled', $filters['add_on_cancelled'] === 'yes');

        $this->filterOrder($query, $filters);
        if ($filters['note'] !== '') {
            $query->whereHas('operations', function (Builder $operation) use ($filters): void {
                // Match the latest non-empty note, just as the list displays it.
                $latest = LogisticsPayPalDisputeOperation::query()
                    ->from('logistics_paypal_dispute_operations as latest_note_operation')
                    ->select('latest_note_operation.id')
                    ->whereNotNull('latest_note_operation.payload->note')
                    ->whereColumn('latest_note_operation.logistics_paypal_dispute_id', 'logistics_paypal_dispute_operations.logistics_paypal_dispute_id');
                $grammar = $operation->getQuery()->getGrammar();
                $latest->whereRaw("TRIM(COALESCE(".$grammar->wrap('latest_note_operation.payload->note').", '')) <> ''")
                    ->orderByDesc('latest_note_operation.id')->limit(1);
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['note']).'%';
                $operation->where('logistics_paypal_dispute_operations.id', '=', $latest)
                    ->whereRaw($grammar->wrap('logistics_paypal_dispute_operations.payload->note')." LIKE ? ESCAPE '!'", [$like]);
            });
        }
        if ($filters['keyword'] !== '') { $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['keyword']).'%'; $query->where(fn (Builder $item) => $item->where('dispute_id', 'like', $like)->orWhere('disputed_transaction_id', 'like', $like)->orWhereHas('account', fn (Builder $account) => $account->where('account_name', 'like', $like))); }
        return $query;
    }

    private function filterOrder(Builder $query, array $filters): void
    {
        $orderFilters = array_filter([
            'customer_email' => $filters['customer_email'], 'order_from' => $filters['order_from'], 'order_to' => $filters['order_to'],
            'order_amount_min' => $filters['order_amount_min'], 'order_amount_max' => $filters['order_amount_max'],
            'subscription_type' => $filters['subscription_type'], 'post_upsell' => $filters['post_upsell'], 'repurchase' => $filters['repurchase'],
            'shipped' => $filters['shipped'], 'logistics_status' => $filters['logistics_status'], 'tracking_number' => $filters['tracking_number'], 'tongtu_order_number' => $filters['tongtu_order_number'],
            'item_keyword' => $filters['item_keyword'],
        ], static fn ($value): bool => $value !== '');
        if ($orderFilters === []) return;

        $query->whereHas('order', function (Builder $order) use ($filters): void {
            if ($filters['customer_email'] !== '') $order->where('buyer_email', 'like', $this->like($filters['customer_email']));
            if ($filters['order_from'] !== '') $order->where('order_created_at', '>=', $filters['order_from'].' 00:00:00');
            if ($filters['order_to'] !== '') $order->where('order_created_at', '<=', $filters['order_to'].' 23:59:59');
            $this->amountRange($order, 'paid_amount_usd', $filters['order_amount_min'], $filters['order_amount_max']);
            if ($filters['subscription_type'] !== '') $order->where('subscription_type', $filters['subscription_type']);
            if ($filters['post_upsell'] === 'yes') $order->where('is_add_on', '已加购');
            if ($filters['post_upsell'] === 'no') $order->where(fn (Builder $item) => $item->whereNull('is_add_on')->orWhere('is_add_on', '!=', '已加购'));
            if ($filters['repurchase'] === 'yes') $order->whereNotNull('subscription_origin_order_id')->where('subscription_origin_order_id', '!=', '');
            if ($filters['repurchase'] === 'no') $order->where(fn (Builder $item) => $item->whereNull('subscription_origin_order_id')->orWhere('subscription_origin_order_id', ''));
            if ($filters['shipped'] === 'yes') $order->whereIn('logistics_status', [LogisticsOrder::LOGISTICS_SHIPPED, LogisticsOrder::LOGISTICS_IN_TRANSIT, LogisticsOrder::LOGISTICS_DELIVERED]);
            if ($filters['shipped'] === 'no') $order->where(fn (Builder $item) => $item->whereNull('logistics_status')->orWhereNotIn('logistics_status', [LogisticsOrder::LOGISTICS_SHIPPED, LogisticsOrder::LOGISTICS_IN_TRANSIT, LogisticsOrder::LOGISTICS_DELIVERED]));
            if ($filters['logistics_status'] !== '') $order->where('logistics_status', $filters['logistics_status']);
            if ($filters['tracking_number'] !== '') $order->where('tracking_number', 'like', $this->like($filters['tracking_number']));
            if ($filters['tongtu_order_number'] !== '') $order->where('tongtu_order_number', 'like', $this->like($filters['tongtu_order_number']));
            if ($filters['item_keyword'] !== '') $order->whereHas('items', fn (Builder $item) => $item->where('tongtu_sku', 'like', $this->like($filters['item_keyword']))->orWhere('tongtu_product_name', 'like', $this->like($filters['item_keyword']))->orWhere('product_title', 'like', $this->like($filters['item_keyword'])));
        });
    }

    private function amountRange(Builder $query, string $column, string $min, string $max): void
    {
        if ($min !== '') $query->whereRaw($column.' >= ?', [(float) $min]);
        if ($max !== '') $query->whereRaw($column.' <= ?', [(float) $max]);
    }

    private function like(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }

    private function filters(Request $request): array
    {
        $text = static fn (string $key): string => trim((string) $request->input($key));
        $choice = static fn (string $key, array $allowed): string => in_array($request->input($key), $allowed, true) ? (string) $request->input($key) : '';

        return [
            'account_id' => $text('account_id'), 'keyword' => $text('keyword'), 'customer_email' => $text('customer_email'), 'note' => $text('note'),
            'status' => $text('status'), 'matched' => $choice('matched', ['yes', 'no']), 'handled' => $choice('handled', ['yes', 'no']),
            'date_from' => $text('date_from'), 'date_to' => $text('date_to'), 'handled_from' => $text('handled_from'), 'handled_to' => $text('handled_to'),
            'order_from' => $text('order_from'), 'order_to' => $text('order_to'), 'dispute_amount_min' => $text('dispute_amount_min'), 'dispute_amount_max' => $text('dispute_amount_max'), 'refund_amount_min' => $text('refund_amount_min'), 'refund_amount_max' => $text('refund_amount_max'),
            'order_amount_min' => $text('order_amount_min'), 'order_amount_max' => $text('order_amount_max'), 'currency' => $text('currency'),
            'subscription_type' => $text('subscription_type'), 'post_upsell' => $choice('post_upsell', ['yes', 'no']), 'repurchase' => $choice('repurchase', ['yes', 'no']),
            'subscription_cancelled' => $choice('subscription_cancelled', ['yes', 'no']), 'add_on_cancelled' => $choice('add_on_cancelled', ['yes', 'no']),
            'type' => $choice('type', ['INTERNAL', 'EXTERNAL', 'ALERT', 'INQUIRY']), 'reason' => $text('reason'), 'outcome' => $text('outcome'),
            'shipped' => $choice('shipped', ['yes', 'no']), 'logistics_status' => $text('logistics_status'), 'tracking_number' => $text('tracking_number'), 'tongtu_order_number' => $text('tongtu_order_number'), 'item_keyword' => $text('item_keyword'),
            'per_page' => in_array((int) $request->input('per_page', 20), [20, 50, 100], true) ? (int) $request->input('per_page', 20) : 20,
        ];
    }
    private function transactionKey(mixed $accountId, mixed $transactionId): string { $transactionId = trim((string) $transactionId); return $transactionId === '' ? '' : (int) $accountId.'|'.$transactionId; }
    private function portalContext(Request $request): array|Response { $context = $this->portalEntries->portalContext($request); if ($context instanceof Response) return $context; $this->logistics->assertLogisticsPortal($context['portal']); return $context; }
    private function viewContext(array $context): array { $context['portal']->loadMissing('menus'); return $context; }
    private function indexRedirect(Request $request): RedirectResponse { $default = route('logistics.paypal-dispute-analysis.index'); $returnTo = trim((string) $request->input('return_to')); $candidate = parse_url($returnTo); $expected = parse_url($default); return $returnTo !== '' && is_array($candidate) && ($candidate['host'] ?? null) === ($expected['host'] ?? null) && ($candidate['path'] ?? null) === ($expected['path'] ?? null) ? redirect()->to($returnTo) : redirect()->to($default); }
}
