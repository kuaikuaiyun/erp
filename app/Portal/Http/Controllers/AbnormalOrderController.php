<?php

namespace App\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AbnormalOrderRecord;
use App\Models\InternalMessage;
use App\Models\InternalMessageRecipient;
use App\Models\LogisticsOrder;
use App\Portals\Logistics\AbnormalOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AbnormalOrderController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AbnormalOrderService $abnormalOrders,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'processed'])],
            'keyword' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', Rule::in([20, 50, 100])],
        ]);
        $status = (string) ($validated['status'] ?? '');
        $keyword = trim((string) ($validated['keyword'] ?? ''));
        $query = $this->abnormalOrders->query()->with(['items', 'store', 'abnormalRecord']);
        if ($context['portal']->code === 'yunying' && ! $context['user']->isAdministrator()) {
            $query->where('assigned_owner_user_id', (int) $context['user']->getKey());
        }
        if ($status === 'processed') {
            $query->whereHas('abnormalRecord', fn ($record) => $record->where('status', AbnormalOrderRecord::STATUS_PROCESSED));
        } elseif ($status === 'pending') {
            $query->where(function ($pending): void {
                $pending->whereDoesntHave('abnormalRecord')
                    ->orWhereHas('abnormalRecord', fn ($record) => $record->where('status', AbnormalOrderRecord::STATUS_PENDING));
            });
        }
        if ($keyword !== '') {
            $query->where(function ($search) use ($keyword): void {
                $like = '%'.$keyword.'%';
                $search->where('shopify_order_number', 'like', $like)->orWhere('shopify_order_id', 'like', $like)
                    ->orWhere('tongtu_order_number', 'like', $like)->orWhere('store_name', 'like', $like)
                    ->orWhere('source_shop_domain', 'like', $like)->orWhere('assigned_owner_name', 'like', $like)
                    ->orWhereHas('items', fn ($items) => $items->where('shopify_sku', 'like', $like)
                        ->orWhere('tongtu_sku', 'like', $like)->orWhere('tongtu_product_code', 'like', $like));
            });
        }
        $orders = $query->orderByDesc('order_created_at')->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20))->withQueryString();
        $orders->getCollection()->each(function (LogisticsOrder $order): void {
            $order->setAttribute('abnormal_items', $this->abnormalOrders->details($order));
        });

        return view('portal.abnormal-orders.index', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'],
            'orders' => $orders, 'status' => $status, 'keyword' => $keyword,
            'isReviewPortal' => $context['portal']->code === 'caigou',
        ]);
    }

    public function updateStatus(Request $request, LogisticsOrder $order): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        abort_unless($context['portal']->code === 'yunying', 403);
        abort_unless($context['user']->isAdministrator() || (int) $order->assigned_owner_user_id === (int) $context['user']->getKey(), 403);
        abort_unless($this->abnormalOrders->query()->whereKey($order->getKey())->exists(), 404);
        $status = $request->validate(['status' => ['required', Rule::in(['pending', 'processed'])]])['status'];
        AbnormalOrderRecord::query()->updateOrCreate(['logistics_order_id' => $order->getKey()], [
            'status' => $status,
            'processed_by_user_id' => $status === 'processed' ? $context['user']->getKey() : null,
            'processed_by_name' => $status === 'processed' ? ($context['user']->name ?: $context['user']->username) : null,
            'processed_at' => $status === 'processed' ? now() : null,
        ]);
        return back()->with('success', $status === 'processed' ? '该异常订单已标记为已处理。' : '该异常订单已恢复为未处理。');
    }

    public function notify(Request $request, LogisticsOrder $order): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        abort_unless($context['portal']->code === 'caigou', 403);
        $order->load(['items', 'store', 'abnormalRecord']);
        abort_unless($this->abnormalOrders->query()->whereKey($order->getKey())->exists(), 404);
        abort_if($order->abnormalRecord?->status === AbnormalOrderRecord::STATUS_PROCESSED, 422, '已处理订单无需再次通知。');
        $recipientId = (int) $order->assigned_owner_user_id;
        abort_if($recipientId <= 0, 422, '该订单尚未匹配真实归属人，无法发送通知。');
        $details = $this->abnormalOrders->details($order);
        DB::connection(config('admin.database.connection') ?: config('database.default'))->transaction(function () use ($order, $details, $context, $recipientId): void {
            $message = InternalMessage::query()->create([
                'title' => '异常订单通知：'.($order->shopify_order_number ?: $order->id),
                'content' => $this->abnormalOrders->messageContent($order, $details),
                'sender_user_id' => $context['user']->getKey(),
                'sender_name' => (string) ($context['user']->name ?: $context['user']->username),
                'sender_portal_code' => $context['portal']->code,
                'sender_portal_name' => $context['portal']->name,
                'published_at' => now(),
            ]);
            InternalMessageRecipient::query()->create(['internal_message_id' => $message->getKey(), 'recipient_user_id' => $recipientId]);
            AbnormalOrderRecord::query()->updateOrCreate(['logistics_order_id' => $order->getKey()], [
                'status' => AbnormalOrderRecord::STATUS_PENDING,
                'last_notified_by_user_id' => $context['user']->getKey(),
                'last_notified_by_name' => $context['user']->name ?: $context['user']->username,
                'last_notified_at' => now(),
            ]);
        });
        return back()->with('success', '异常订单已通知运营：'.($order->assigned_owner_name ?: ('用户 #'.$recipientId)).'。');
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless(in_array($context['portal']->code, ['yunying', 'caigou'], true), 404);
        return $context;
    }
}
