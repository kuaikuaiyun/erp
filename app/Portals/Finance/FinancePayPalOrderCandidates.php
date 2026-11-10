<?php

namespace App\Portals\Finance;

use App\Models\LogisticsOrderPaymentReference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FinancePayPalOrderCandidates
{
    private const WINDOW_MINUTES = 15;

    public function __construct(private readonly FinanceWorkbookService $workbooks) {}

    /** @return array<int, string> */
    public function suggestions(Collection $records, array $matched): array
    {
        $payments = $records->filter(fn ($record) => ! isset($matched[$record->id]) && $record->currency === 'USD'
            && (FinancePayPalEventTypes::TYPES[$record->raw_type][1] ?? null) === '结账付款'
            && in_array(strtoupper((string) $record->status), ['S', 'SUCCESS', 'COMPLETED', 'PAID', '已付款', '已完成', '成功'], true));
        if ($payments->isEmpty()) {
            return [];
        }

        $db = $this->workbooks->database();
        $schema = $db->getSchemaBuilder();
        $orderFields = ['id', 'buyer_email', 'paid_at', 'paid_amount_usd', 'tongtu_order_number', 'bill_number', 'payment_gateway'];
        $itemFields = ['logistics_order_id', 'product_title', 'variant_title', 'quantity'];
        if (! $schema->hasColumns('logistics_orders', $orderFields) || ! $schema->hasColumns('logistics_order_items', $itemFields)) {
            return [];
        }

        $details = $db->table('finance_payment_transactions')->whereIntegerInRaw('finance_record_id', $payments->modelKeys())
            ->get(['finance_record_id', 'raw_payload->payer_info->email_address as email', 'raw_payload->cart_info->item_details as items'])
            ->filter(fn ($detail) => filled($detail->email) && filled($detail->items))->keyBy('finance_record_id');
        if ($details->isEmpty()) {
            return [];
        }

        $dates = $payments->whereIn('id', $details->keys())->pluck('occurred_at');
        $orders = $db->table('logistics_orders')
            ->whereIn('buyer_email', $details->pluck('email')->unique())
            ->whereBetween('paid_at', [$dates->min()->subMinutes(self::WINDOW_MINUTES), $dates->max()->addMinutes(self::WINDOW_MINUTES)])
            ->where('payment_gateway', 'PayPal')
            ->whereNotNull('tongtu_order_number')->where('tongtu_order_number', '!=', '')
            ->where(fn ($query) => $query->whereNull('bill_number')->orWhere('bill_number', ''));
        if ($schema->hasColumns('logistics_order_payment_references', ['logistics_order_id', 'reference_type'])) {
            $orders->whereNotExists(fn ($query) => $query->selectRaw('1')->from('logistics_order_payment_references')
                ->whereColumn('logistics_order_id', 'logistics_orders.id')
                ->where('reference_type', LogisticsOrderPaymentReference::TYPE_PRIMARY));
        }
        $orders = $orders->get($orderFields);
        $items = $db->table('logistics_order_items')->whereIntegerInRaw('logistics_order_id', $orders->pluck('id')->all())
            ->get($itemFields)->groupBy('logistics_order_id');
        $ordersByEmail = $orders->groupBy(fn ($order) => strtolower(trim($order->buyer_email)));
        $grossHeader = FinanceCatalog::dataset('paypal-orders')['headers'][7];
        $suggestions = [];
        foreach ($payments as $payment) {
            $detail = $details->get($payment->id);
            $amount = trim((string) ($payment->payload[$grossHeader] ?? ''));
            $cart = json_decode($detail->items ?? '[]', true);
            if ($detail === null || ! is_array($cart) || $cart === [] || ! preg_match('/^\d{1,18}(?:\.\d{1,8})?$/D', $amount)
                || bccomp($amount, '0', 4) <= 0) {
                continue;
            }
            $candidates = $ordersByEmail->get(strtolower(trim($detail->email)), collect())
                ->filter(function ($order) use ($payment, $amount, $cart, $items): bool {
                    $paid = (string) $order->paid_amount_usd;
                    $difference = abs(CarbonImmutable::parse($order->paid_at, 'UTC')->getTimestamp() - $payment->occurred_at->getTimestamp());

                    return $difference <= self::WINDOW_MINUTES * 60 && is_numeric($paid)
                        && bccomp($paid, bcsub($amount, '0.01', 4), 4) >= 0
                        && bccomp($paid, bcadd($amount, '0.01', 4), 4) <= 0
                        && $this->sameItems($cart, $items->get($order->id, collect()));
                });
            if ($candidates->count() === 1) {
                $suggestions[$payment->id] = $candidates->first()->tongtu_order_number;
            }
        }

        return $suggestions;
    }

    private function sameItems(array $cart, Collection $items): bool
    {
        if (count($cart) !== $items->count()) {
            return false;
        }

        foreach ($cart as $line) {
            $name = trim((string) ($line['item_name'] ?? ''));
            $quantity = (string) ($line['item_quantity'] ?? '');
            $matching = $items->filter(function ($item) use ($name, $quantity): bool {
                $title = trim((string) $item->product_title);
                $variant = trim((string) $item->variant_title);
                $orderQuantity = (string) $item->quantity;

                return $title !== '' && ctype_digit($quantity) && is_numeric($orderQuantity)
                    && bccomp($quantity, $orderQuantity, 4) === 0
                    && ($name === $title || ($variant !== '' ? $name === $title.'-'.$variant : str_starts_with($name, $title.'-')));
            });
            if ($matching->count() !== 1) {
                return false;
            }
            $items = $items->except($matching->keys());
        }

        return true;
    }
}
