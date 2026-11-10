<?php

namespace App\Portals\Finance;

use App\Models\FinancePaymentAccount;
use App\Models\FinanceRecord;
use App\Portals\Logistics\LogisticsCreditCardRefundReason;
use Illuminate\Support\Collection;

class FinanceXuzongCreditMatches
{
    private const LEGACY_SOURCES = ['xuzong1-details', 'xuzong-credit-source-1'];

    private const AMOUNTS = [
        'settlement' => '结算金额', 'hold' => '保证金', 'fee' => '交易手续费', 'per_order_fee' => '单笔费用',
        'refund' => '退款金额', 'refund_fee' => '退款费用', 'chargeback' => '拒付金额', 'chargeback_fee' => '拒付费用',
        'ethoca_refund' => 'ethoca预警退款', 'ethoca_fee' => '拒付预警ethoca', 'rdr_refund' => '拒付RDR金额',
        'rdr_fee' => '拒付RDR+CDRN费用', 'release' => '保证金返还', 'payable' => '应结算',
        'logistics' => '物流-元', 'purchase' => '采购成本-元',
    ];

    private ?Collection $accounts = null;
    private ?Collection $accountsByMerchant = null;

    public function __construct(
        private readonly FinanceWorkbookService $workbooks,
        private readonly FinanceSettlementService $settlements,
        private readonly FinanceConfluxSettlement $conflux,
    ) {}

    public static function reference(?object $order): string
    {
        return trim((string) (($order->transaction_id ?? '') ?: ($order->payment_gateway_order_id ?? '')));
    }

    public static function isAmount(string $header): bool
    {
        return in_array($header, self::AMOUNTS, true);
    }

    public function forOrders(Collection $records, Collection $orders): Collection
    {
        $db = $this->workbooks->database();
        $this->accounts ??= FinancePaymentAccount::query()->where('account_type', 'credit_card')
            ->get(['id', 'activity_account', 'account_name', 'card_merchant_key'])->keyBy('activity_account');
        $this->accountsByMerchant ??= $this->accounts->groupBy(fn ($account) => array_unique([
            self::accountKey($account->activity_account), self::accountKey($account->account_name),
        ]));
        $references = $orders->map(self::reference(...))->filter();
        $byTransaction = $db->table('logistics_orders')->whereIn('transaction_id', $references->values())
            ->get(['id', 'transaction_id', 'collection_account', 'tongtu_order_number'])->groupBy('transaction_id');
        $gatewayOnly = $db->table('logistics_orders')->whereIn('payment_gateway_order_id', $references->values())
            ->where(fn ($query) => $query->whereNull('transaction_id')->orWhere('transaction_id', ''))
            ->groupBy('payment_gateway_order_id')->selectRaw('payment_gateway_order_id, COUNT(*) as total')
            ->pluck('total', 'payment_gateway_order_id');
        $erpOrders = $records->mapWithKeys(function ($record) use ($orders, $references, $byTransaction): array {
            $order = $orders->get($record->id);
            $matches = $byTransaction->get($references->get($record->id));
            $match = $matches?->count() === 1 ? $matches->first() : null;
            // The current order is already linked by ID. An alternate Shopify record must match D and the merchant.
            if ($match && filled($record->account) && self::accountKey($match->collection_account) === self::accountKey($record->account)
                && filled($match->tongtu_order_number) && blank($order?->tongtu_order_number)) {
                return [$record->id => $match];
            }

            return [$record->id => $order];
        });

        $details = $this->matchedDetails($records, $references, $byTransaction, $gatewayOnly, $erpOrders);
        $billing = $this->billingFields($details);
        $refunds = $this->refunds($erpOrders->filter()->pluck('id')->unique());
        $costRows = $records->map(function ($record) use ($erpOrders, $billing): array {
            $bill = $billing->get($record->id, []);

            return [
                'id' => $record->id, 'logistics_order_id' => $erpOrders->get($record->id)?->id,
                'success' => FinanceSettlementService::isSuccessfulTransaction($record->status),
                'logistics' => $bill['物流-元'] ?? null, 'purchase' => $bill['采购成本-元'] ?? null,
            ];
        });
        $costs = $this->settlements->withLogisticsCosts($costRows)->keyBy('id');

        return $records->mapWithKeys(function ($record) use ($erpOrders, $billing, $refunds, $costs): array {
            $order = $erpOrders->get($record->id);
            $bill = $billing->get($record->id, []);
            $cost = $costs->get($record->id);
            $currency = $bill['_settlement_currency'] ?? '';
            $calculatePayable = $bill['_calculate_payable'] ?? false;
            unset($bill['_settlement_currency'], $bill['_calculate_payable']);
            $refund = $currency === '' || $currency === 'USD' ? $refunds->get($order?->id, []) : [];
            $accounts = $this->accountsByMerchant->get(self::accountKey($record->account));
            $merchantNumber = $accounts?->count() === 1 ? $accounts->first()->card_merchant_key : null;
            $values = $bill + $refund + [
                '商户号' => $merchantNumber,
                '通途订单' => $order?->tongtu_order_number,
                '物流-元' => $cost['logistics'], '采购成本-元' => $cost['purchase'],
            ];
            if ($calculatePayable) {
                $values['应结算'] = $this->payable($values);
            }

            return [$record->id => $values];
        });
    }

    private function matchedDetails(Collection $records, Collection $references, Collection $byTransaction, Collection $gatewayOnly, Collection $erpOrders): Collection
    {
        $matchReferences = fn ($query) => $query->whereIn('reference', $references->values())
            ->orWhere(fn ($legacy) => $legacy->whereIn('dataset', self::LEGACY_SOURCES)
                ->whereIn('payload->商户订单号', $references->values()));
        $uploads = FinanceRecord::query()->whereIn('dataset', array_values(FinanceCatalog::XUZONG_REPORT_IMPORTS))
            ->where($matchReferences)->get();
        $candidates = $uploads->concat($this->settlements->query('credit_card', [])
            ->whereIn('dataset', ['xuzong1-details', 'xuzong2-details'])->where($matchReferences)->get())
            ->groupBy(fn (FinanceRecord $detail) => in_array($detail->dataset, self::LEGACY_SOURCES, true)
                ? array_unique([$detail->reference, trim((string) ($detail->payload['商户订单号'] ?? ''))])
                : [$detail->reference]);

        return $records->mapWithKeys(function (FinanceRecord $record) use ($references, $byTransaction, $gatewayOnly, $candidates, $erpOrders): array {
            $reference = $references->get($record->id);
            if ($reference === null || ($byTransaction->get($reference)?->count() ?? 0) + $gatewayOnly->get($reference, 0) > 1) {
                return [];
            }
            $merchant = self::accountKey($record->account);
            $erpOrder = $erpOrders->get($record->id)?->tongtu_order_number;
            $matches = $candidates->get($reference, collect())->filter(function (FinanceRecord $detail) use ($merchant, $record, $reference, $erpOrder): bool {
                $account = $this->accounts->get($detail->account);
                $names = array_map(self::accountKey(...), [$detail->account, $detail->payload['商户名称'] ?? '', $account?->account_name]);
                $statementErpOrder = trim((string) ($detail->payload['通途订单'] ?? ''));
                $hasErpPair = filled($erpOrder) && $statementErpOrder !== '';
                $erpMatches = $hasErpPair && $statementErpOrder === $erpOrder;
                $merchantMatches = $merchant !== '' && in_array($merchant, $names, true);

                return FinanceSettlementService::transactionStatusCategory($detail->status) === FinanceSettlementService::transactionStatusCategory($record->status)
                    && ($detail->reference === $reference ? $merchantMatches
                        : ($merchantMatches || ($merchant === '' && $erpMatches)) && (! $hasErpPair || $erpMatches));
            });

            $uploads = $matches->whereIn('dataset', FinanceCatalog::XUZONG_REPORT_IMPORTS);
            $preferred = $uploads->isNotEmpty() ? $uploads : $matches;

            return $preferred->count() === 1 ? [$record->id => $preferred->first()] : [];
        });
    }

    private function billingFields(Collection $details): Collection
    {
        if ($details->isEmpty()) {
            return collect();
        }
        $db = $this->workbooks->database();
        $saved = $db->table('finance_settlement_supplements')->whereIn('finance_record_id', $details->pluck('id'))
            ->pluck('payload', 'finance_record_id');
        $entries = $this->conflux->entriesFor($details->unique('id'), $this->accounts);
        $fees = $this->conflux->feesFor($entries);

        return $details->map(function (FinanceRecord $detail) use ($saved, $entries, $fees): array {
            $payload = $detail->payload;
            $manual = json_decode($saved->get($detail->id, '{}'), true) ?: [];
            $manual = [...$manual, ...($manual['_edited_fields'] ?? [])];
            $ledger = $entries->get($detail->id);
            $ledgerValues = $ledger ? $this->conflux->details($ledger, $fees) : ['values' => [], 'reasons' => []];
            $platform = $payload['_card_channel'] ?? null;
            $platformHeaders = FinanceCreditSettlementLayout::columns($platform);
            $amounts = [];
            foreach (self::AMOUNTS as $field => $header) {
                $amount = $this->settlements->number($manual[$field] ?? null)
                    ?? $this->settlements->number($payload[$header] ?? null)
                    ?? $this->settlements->number($payload[$platformHeaders[$field] ?? ''] ?? null)
                    ?? $this->settlements->number($ledgerValues['values'][$field] ?? null);
                if ($amount !== null) {
                    $amounts[$field] = $amount;
                }
            }
            if ($ledger && ! isset($amounts['payable']) && $ledgerValues['reasons'] === []) {
                $payable = $this->conflux->payable($ledger, $amounts + $ledgerValues['values']);
                if ($payable !== null) {
                    $amounts['payable'] = $payable;
                }
            }
            $result = [];
            foreach ($amounts as $field => $value) {
                $result[self::AMOUNTS[$field]] = $value;
            }
            $card = trim((string) ($payload['卡号'] ?? ''));
            $merchantNumber = filled($payload['商户号'] ?? null) ? $payload['商户号'] : $this->accounts->get($detail->account)?->card_merchant_key;
            $result['_settlement_currency'] = strtoupper(trim((string) (
                ($ledgerValues['values']['settlement_currency'] ?? '') ?: (($payload['结算币种'] ?? '') ?: ($payload['结单币种'] ?? ''))
            )));
            $result['_calculate_payable'] = ! $ledger && isset($amounts['settlement']) && ! isset($amounts['payable']);

            return $result + array_filter([
                '商户号' => $merchantNumber,
                '是否3D' => filled($payload['是否3D'] ?? null) ? $payload['是否3D'] : ($payload['3DS'] ?? null),
                '发卡国家' => filled($payload['发卡国家'] ?? null) ? $payload['发卡国家'] : ($payload['发行国'] ?? null),
                '卡号' => preg_match('/(\d{4})$/D', $card, $parts) ? '****'.$parts[1] : null,
            ], fn ($value) => filled($value));
        });
    }

    private function payable(array $values): string
    {
        $net = bcadd($values['结算金额'], $values['保证金返还'] ?? '0', 8);
        foreach (['hold', 'fee', 'per_order_fee', 'refund', 'refund_fee', 'chargeback', 'chargeback_fee',
            'ethoca_refund', 'ethoca_fee', 'rdr_refund', 'rdr_fee'] as $deduction) {
            $net = bcsub($net, $values[self::AMOUNTS[$deduction]] ?? '0', 8);
        }

        return $net;
    }

    private function refunds(Collection $ids): Collection
    {
        $db = $this->workbooks->database();
        if ($ids->isEmpty() || ! $db->getSchemaBuilder()->hasTable('logistics_credit_card_refunds')) {
            return collect();
        }

        return $db->table('logistics_credit_card_refunds')->whereIntegerInRaw('logistics_order_id', $ids->all())
            ->get(['logistics_order_id', 'refund_amount_usd', 'refund_reason'])->groupBy('logistics_order_id')
            ->map(function (Collection $rows): array {
                $result = [];
                foreach ($rows as $row) {
                    $reason = LogisticsCreditCardRefundReason::canonical($row->refund_reason);
                    $field = match (true) {
                        $reason === 'RDR' => '拒付RDR金额',
                        $reason === 'Ethoca' => 'ethoca预警退款',
                        in_array($reason, LogisticsCreditCardRefundReason::DISPUTE, true) => '拒付金额',
                        in_array($reason, LogisticsCreditCardRefundReason::CUSTOMER_SERVICE, true) => '退款金额',
                        default => null,
                    };
                    $amount = $this->settlements->number($row->refund_amount_usd);
                    if ($field !== null && $amount !== null) {
                        $result[$field] = bcadd($result[$field] ?? '0', $amount, 8);
                    }
                }

                return $result;
            });
    }

    private static function accountKey(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
