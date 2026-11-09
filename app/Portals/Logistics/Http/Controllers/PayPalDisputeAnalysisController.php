<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalDisputeOperation;
use App\Models\LogisticsPayPalTransaction;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\LogisticsPortalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PayPalDisputeAnalysisController extends Controller
{
    public function __construct(private readonly PortalEntryController $portalEntries, private readonly LogisticsPortalService $logistics) {}

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

        return view('portals.logistics.paypal-dispute-analysis.index', [...$this->viewContext($context), 'disputes' => $disputes, 'filters' => $filters, 'transactions' => $transactions, 'accounts' => LogisticsPayPalAccount::query()->orderBy('account_name')->get(['id', 'account_name'])]);
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
        if ($filters['keyword'] !== '') { $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['keyword']).'%'; $query->where(fn (Builder $item) => $item->where('dispute_id', 'like', $like)->orWhere('disputed_transaction_id', 'like', $like)->orWhereHas('account', fn (Builder $account) => $account->where('account_name', 'like', $like))); }
        return $query;
    }

    private function filters(Request $request): array { return ['account_id' => trim((string) $request->input('account_id')), 'status' => trim((string) $request->input('status')), 'handled' => in_array($request->input('handled'), ['yes', 'no'], true) ? $request->input('handled') : '', 'matched' => in_array($request->input('matched'), ['yes', 'no'], true) ? $request->input('matched') : '', 'date_from' => trim((string) $request->input('date_from')), 'date_to' => trim((string) $request->input('date_to')), 'keyword' => trim((string) $request->input('keyword')), 'per_page' => in_array((int) $request->input('per_page', 20), [20, 50, 100], true) ? (int) $request->input('per_page', 20) : 20]; }
    private function transactionKey(mixed $accountId, mixed $transactionId): string { $transactionId = trim((string) $transactionId); return $transactionId === '' ? '' : (int) $accountId.'|'.$transactionId; }
    private function portalContext(Request $request): array|Response { $context = $this->portalEntries->portalContext($request); if ($context instanceof Response) return $context; $this->logistics->assertLogisticsPortal($context['portal']); return $context; }
    private function viewContext(array $context): array { $context['portal']->loadMissing('menus'); return $context; }
    private function indexRedirect(Request $request): RedirectResponse { $default = route('logistics.paypal-dispute-analysis.index'); $returnTo = trim((string) $request->input('return_to')); $candidate = parse_url($returnTo); $expected = parse_url($default); return $returnTo !== '' && is_array($candidate) && ($candidate['host'] ?? null) === ($expected['host'] ?? null) && ($candidate['path'] ?? null) === ($expected['path'] ?? null) ? redirect()->to($returnTo) : redirect()->to($default); }
}
