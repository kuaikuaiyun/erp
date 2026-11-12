<?php

namespace App\Portals\Finance;

use App\Models\FinancePaymentAccount;
use App\Models\FinanceRecord;
use App\Portals\Logistics\LogisticsCreditCardRefundReason;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;

class FinanceXuzongCreditMatches
{
    public const EXCHANGE_HEADERS = ['记账币种', '原币种净额', '折算后结算金额'];

    public const STATEMENT_HEADERS = [
        '交易本金' => '结算金额',
        '退款本金' => '退款金额',
        '拒付本金' => '拒付金额',
        '拒付处理费' => '拒付费用',
        '保证金扣除' => '保证金',
        '交易处理费' => '单笔费用',
        '退款处理费' => '退款费用',
        'Ethoca' => '拒付预警ethoca',
        'RDR' => 'RDR费用',
        'CDRN' => 'CDRN费用',
    ];

    private const LEGACY_SOURCES = ['xuzong1-details', 'xuzong-credit-source-1'];
    private const TXN_SOURCES = ['xuzong2-details', 'xuzong-credit-source-2'];
    private const LEGACY_ORDER_IDS = [
        '8048797126832324608003',
        '2348811528998121881679',
        '4948812369769436364844',
        '4048813397045498675233',
        '7048816948462100889658',
        '9648817145239124787204',
        '7848824164497444454449',
        '7848828390486887628863',
        '5048830876201327001634',
        '2048820963920554803209',
        '5048804872376374886469',
        '7248589285416693350454',
        '7048025759034184499251',
        '7348117715187872972859',
        '6048169062359919001699',
        '0648238076158914560009',
        '3748522540293620531277',
        '8848519931646675763275',
        '9348626277595027456006',
        '4648808966159670886445',
        '9848808642584630067241',
        '6748782805058359296005',
        '7648300410363557068812',
        '6248697028468383744016',
        '7248559835184971776020',
        '7548451106847599411210',
        '8948529656309956198429',
        '1948735039558109593629',
        '4148770876464512614464',
        '6348818415954664243235',
    ];

    private const AMOUNTS = [
        'settlement' => '结算金额', 'hold' => '保证金', 'fee' => '交易手续费', 'per_order_fee' => '单笔费用',
        'refund' => '退款金额', 'refund_fee' => '退款费用', 'chargeback' => '拒付金额', 'chargeback_fee' => '拒付费用',
        'ethoca_refund' => 'ethoca预警退款', 'ethoca_fee' => '拒付预警ethoca', 'rdr_refund' => '拒付RDR金额',
        'rdr_fee' => '拒付RDR+CDRN费用', 'rdr_fee_split' => 'RDR费用', 'cdrn_fee' => 'CDRN费用',
        'chargeback_return' => '拒付返还', 'dispute_processing_fee' => '争议处理费',
        'release' => '保证金返还', 'payable' => '应结算',
        'logistics' => '物流-元', 'purchase' => '采购成本-元',
    ];

    private ?Collection $accounts = null;

    public function __construct(
        private readonly FinanceWorkbookService $workbooks,
        private readonly FinanceSettlementService $settlements,
        private readonly FinanceConfluxSettlement $conflux,
    ) {}

    public static function reference(?object $order): string
    {
        return trim((string) (($order->payment_gateway_order_id ?? '') ?: ($order->transaction_id ?? '')));
    }

    public static function isAmount(string $header): bool
    {
        return in_array($header, self::AMOUNTS, true);
    }

    public static function isLegacyOrder(string $reference): bool
    {
        return in_array($reference, self::LEGACY_ORDER_IDS, true);
    }

    public function completeStatementRows(array $rows, string $dataset, string $account, string $timezone): array
    {
        $legacy = in_array($dataset, self::LEGACY_SOURCES, true);
        $dateField = $legacy ? '交易时间' : '创建时间';
        $referenceField = $legacy ? '交易单号' : '平台订单号';
        $needsMetadata = fn ($row) => blank($row[$referenceField] ?? null)
            || (blank($row[$dateField] ?? null) && blank($row['时间点'] ?? null) && blank($row['（-8）时间点'] ?? null))
            || blank($row['支付状态'] ?? null)
            || (blank($row['交易币种'] ?? null) && FinanceSettlementService::transactionStatusCategory($row['支付状态'] ?? null) !== 'failed');
        $needsLookup = fn ($row) => $needsMetadata($row)
            || (! $legacy && blank($row['交易流水号'] ?? null) && ! str_starts_with(strtoupper($row[$referenceField]), 'TXN'));
        $lookup = array_filter($rows, $needsLookup);
        if ($lookup === []) {
            return $rows;
        }

        $keysFor = static fn (array $row) => array_values(array_unique(array_filter([
            $row['交易流水号'] ?? '', $row['平台订单号'] ?? '',
            ...($legacy ? [$row['交易单号'] ?? '', $row['商户订单号'] ?? ''] : []),
        ])));
        $keys = collect($lookup)->flatMap($keysFor)->unique()->values();
        $db = $this->workbooks->database();
        $orderKeys = $keys->isEmpty() || ! $db->getSchemaBuilder()->hasTable('logistics_orders') ? collect()
            : $db->table('logistics_orders')->where(fn ($query) => $query->whereIn('payment_gateway_order_id', $keys)
                ->orWhereIn('bill_number', $keys)->orWhereIn('transaction_id', $keys))
                ->get(['id', 'payment_gateway_order_id', 'bill_number', 'transaction_id'])->keyBy('id');
        $orders = $orderKeys->isEmpty() ? collect() : app(FinanceLogisticsCreditOrders::class)
            ->query(withPayload: false, xuzongReport: true, ids: $orderKeys->keys()->all(), includeSystemOrders: true)
            ->where('account', $account)->get()->groupBy(fn ($order) => array_values(array_unique(array_filter([
                $order->reference, $orderKeys[$order->id]->bill_number, $orderKeys[$order->id]->transaction_id,
            ]))));
        foreach ($lookup as $line => $row) {
            $matches = collect($keysFor($row))->flatMap(fn ($key) => $orders->get($key, []))->unique('id');
            if ($matches->count() !== 1) {
                if ($needsMetadata($row)) {
                    throw ValidationException::withMessages(['file' => '第'.$line.'行缺少订单信息，无法唯一匹配所选账户的订单。']);
                }

                continue;
            }
            $order = $matches->first();
            $transaction = $orderKeys[$order->id];
            if (blank($row[$referenceField] ?? null)) {
                $row[$referenceField] = $legacy && in_array($transaction->transaction_id, self::LEGACY_ORDER_IDS, true)
                    ? $transaction->transaction_id : $order->reference;
            }
            if (! $legacy && blank($row['交易流水号'] ?? null) && str_starts_with(strtoupper($transaction->bill_number ?? ''), 'TXN')) {
                $row['交易流水号'] = $transaction->bill_number;
            }
            if (blank($row[$dateField] ?? null) && blank($row['时间点'] ?? null) && blank($row['（-8）时间点'] ?? null)) {
                $row[$dateField] = $order->occurred_at?->setTimezone($timezone)->format('Y-m-d H:i:s');
            }
            if (blank($row['支付状态'] ?? null)) {
                $row['支付状态'] = FinanceSettlementService::isSuccessfulTransaction($order->status) ? 'SUCCESS' : $order->status;
            }
            if (blank($row['交易币种'] ?? null) && blank($row['交易金额'] ?? null)) {
                $row['交易币种'] = FinanceLogisticsCreditOrders::actualPayment($order->payment_transactions)['currency'];
            }
            $rows[$line] = $row;
        }

        return $rows;
    }

    public function forOrders(Collection $records, Collection $orders): Collection
    {
        $systemRecords = $records->where('dataset', '!=', FinanceLogisticsCreditOrders::IMPORTED_DATASET);
        $systemIds = $systemRecords->pluck('id');
        [$details, $erpOrders] = $systemRecords->isEmpty()
            ? [collect(), $orders]
            : $this->linkedDetails($records, $orders);
        $details = $details->replace($records->where('dataset', FinanceLogisticsCreditOrders::IMPORTED_DATASET)->keyBy('id'));
        $billing = $this->billingFields($details);
        $refunds = $this->refunds($erpOrders->only($systemIds->all())->filter()->pluck('id')->unique());

        return $records->mapWithKeys(function ($record) use ($erpOrders, $billing, $refunds): array {
            $order = $erpOrders->get($record->id);
            $bill = $billing->get($record->id, []);
            $currency = $bill['_settlement_currency'] ?? '';
            $calculatePayable = $bill['_calculate_payable'] ?? false;
            unset($bill['_settlement_currency'], $bill['_calculate_payable']);
            $refund = $record->dataset !== FinanceLogisticsCreditOrders::IMPORTED_DATASET && ($currency === '' || $currency === 'USD')
                ? $refunds->get($order?->id, []) : [];
            $values = $bill + $refund + ['结算币种' => $currency, '通途订单' => $order?->tongtu_order_number];
            if ($calculatePayable) {
                $values['应结算'] = $this->payable($values);
            }

            return [$record->id => $values];
        });
    }

    public function paymentCurrencies(Collection $records): Collection
    {
        $db = $this->workbooks->database();
        $orders = $db->getSchemaBuilder()->hasTable('logistics_orders')
            ? $db->table('logistics_orders')->whereIntegerInRaw('id', $records->pluck('id')->filter(fn ($id) => $id > 0)->all())
                ->get(['id', 'transaction_id', 'payment_gateway_order_id', 'bill_number', 'tongtu_order_number'])->keyBy('id')
            : collect();
        [$details] = $this->linkedDetails($records, $orders);

        return $records->mapWithKeys(fn (FinanceRecord $record) => [
            $record->id => $record->dataset === FinanceLogisticsCreditOrders::IMPORTED_DATASET ? $record->currency : ($this->statementPayment($details->get($record->id))
                ?? FinanceLogisticsCreditOrders::actualPayment($record->payment_transactions))['currency'],
        ]);
    }

    private function statementPayment(?FinanceRecord $detail): ?array
    {
        if ($detail === null || FinanceSettlementService::transactionStatusCategory($detail->status) !== 'success') {
            return null;
        }
        $currency = strtoupper(trim((string) ($detail->payload['交易币种'] ?? '')));
        $amount = $this->settlements->number($detail->payload['交易金额'] ?? null);

        return preg_match('/^[A-Z]{3}$/D', $currency) && $amount !== null ? compact('currency', 'amount') : null;
    }

    private function linkedDetails(Collection $records, Collection $orders): array
    {
        $db = $this->workbooks->database();
        $this->accounts ??= FinancePaymentAccount::query()->where('account_type', 'credit_card')
            ->get(['id', 'activity_account', 'account_name'])->keyBy('activity_account');
        $references = $orders->map(self::reference(...))->filter();
        $keys = $records->mapWithKeys(function (FinanceRecord $record) use ($orders): array {
            $order = $orders->get($record->id);
            $platform = trim((string) $record->reference);
            $internal = trim((string) ($order->transaction_id ?? $record->payload['_legacy_order_id'] ?? ''));
            $legacy = in_array($platform, self::LEGACY_ORDER_IDS, true) ? $platform : $internal;
            if (in_array($legacy, self::LEGACY_ORDER_IDS, true)) {
                return [$record->id => ['reference' => $legacy, 'legacy' => true]];
            }
            $txn = trim((string) ($order->bill_number ?? $record->payload['_bill_number'] ?? ''));

            return [$record->id => ['reference' => str_starts_with(strtoupper($txn), 'TXN') ? $txn : '', 'legacy' => false]];
        });
        $lookup = $references->concat($keys->where('legacy', true)->pluck('reference'))->unique()->values();
        $byTransaction = $lookup->isEmpty() ? collect() : $db->table('logistics_orders')->whereIn('transaction_id', $lookup)
            ->get(['id', 'transaction_id', 'collection_account', 'tongtu_order_number'])->groupBy('transaction_id');
        $gatewayOnly = $lookup->isEmpty() ? collect() : $db->table('logistics_orders')->whereIn('payment_gateway_order_id', $lookup)
            ->where(fn ($query) => $query->whereNull('transaction_id')->orWhereColumn('transaction_id', '<>', 'payment_gateway_order_id'))
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

        $details = $this->matchedDetails($records, $keys, $byTransaction, $gatewayOnly, $erpOrders);
        return [$details, $erpOrders];
    }

    private function matchedDetails(Collection $records, Collection $keys, Collection $byTransaction, Collection $gatewayOnly, Collection $erpOrders): Collection
    {
        $legacyKeys = $keys->where('legacy', true)->pluck('reference')->unique();
        $txnKeys = $keys->where('legacy', false)->pluck('reference')->filter()->unique();
        if ($legacyKeys->isEmpty() && $txnKeys->isEmpty()) {
            return collect();
        }
        $matchReferences = fn ($query) => $query
            ->where(fn ($legacy) => $legacy->whereIn('dataset', self::LEGACY_SOURCES)
                ->where(fn ($row) => $row->whereIn('reference', $legacyKeys)->orWhereIn('payload->商户订单号', $legacyKeys)))
            ->orWhere(fn ($txn) => $txn->whereIn('dataset', self::TXN_SOURCES)
                ->where(fn ($row) => $row->whereIn('reference', $txnKeys)->orWhereIn('payload->交易流水号', $txnKeys)));
        $uploads = FinanceRecord::query()->whereIn('dataset', array_values(FinanceCatalog::XUZONG_REPORT_IMPORTS))
            ->where($matchReferences)->get();
        $candidates = $uploads->concat($this->settlements->query('credit_card', [])
            ->whereIn('dataset', ['xuzong1-details', 'xuzong2-details'])->where($matchReferences)->get())
            ->groupBy(fn (FinanceRecord $detail) => array_unique([
                $detail->reference,
                trim((string) ($detail->payload[in_array($detail->dataset, self::LEGACY_SOURCES, true) ? '商户订单号' : '交易流水号'] ?? '')),
            ]));

        return $records->mapWithKeys(function (FinanceRecord $record) use ($keys, $byTransaction, $gatewayOnly, $candidates, $erpOrders): array {
            ['reference' => $reference, 'legacy' => $legacy] = $keys->get($record->id);
            if ($reference === '' || ($legacy && ($byTransaction->get($reference)?->count() ?? 0) + $gatewayOnly->get($reference, 0) > 1)) {
                return [];
            }
            $merchant = self::accountKey(($record->payload['收款账户'] ?? '') ?: $record->account);
            $erpOrder = $erpOrders->get($record->id)?->tongtu_order_number;
            $matches = $candidates->get($reference, collect())->filter(function (FinanceRecord $detail) use ($merchant, $record, $reference, $erpOrder, $legacy): bool {
                if (! in_array($detail->dataset, $legacy ? self::LEGACY_SOURCES : self::TXN_SOURCES, true)) {
                    return false;
                }
                $account = $this->accounts->get($detail->account);
                $names = array_map(self::accountKey(...), [$detail->account, $detail->payload['商户名称'] ?? '', $account?->account_name]);
                $statementErpOrder = trim((string) ($detail->payload['通途订单'] ?? ''));
                $hasErpPair = filled($erpOrder) && $statementErpOrder !== '';
                $erpMatches = $hasErpPair && $statementErpOrder === $erpOrder;
                $merchantMatches = $merchant !== '' && in_array($merchant, $names, true);

                return FinanceSettlementService::transactionStatusCategory($detail->status) === FinanceSettlementService::transactionStatusCategory($record->status)
                    && (! $legacy || $detail->reference === $reference ? $merchantMatches
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
        if ($this->accounts === null && $details->contains(fn ($detail) => ($detail->payload['_card_channel'] ?? null) === 'ye')) {
            $this->accounts = FinancePaymentAccount::query()->where('account_type', 'credit_card')
                ->get(['id', 'activity_account', 'account_name'])->keyBy('activity_account');
        }
        $entries = $this->conflux->entriesFor($details->unique('id'), $this->accounts ?? collect());
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
            if (isset($amounts['rdr_fee_split'], $amounts['cdrn_fee'])) {
                unset($amounts['rdr_fee']);
            }
            $result = [];
            if (($payment = $this->statementPayment($detail)) !== null) {
                $result['_statement_payment'] = $payment;
            }
            foreach ($amounts as $field => $value) {
                $result[self::AMOUNTS[$field]] = $value;
            }
            $card = trim((string) ($payload['卡号'] ?? ''));
            $result['_settlement_currency'] = strtoupper(trim((string) (
                ($ledgerValues['values']['settlement_currency'] ?? '') ?: (($payload['结算币种'] ?? '') ?: ($payload['结单币种'] ?? ''))
            )));
            $result['_calculate_payable'] = ! $ledger && isset($amounts['settlement']) && ! isset($amounts['payable']);
            if (strtoupper(trim((string) ($payload['结算币种'] ?? ''))) === 'USD') {
                $original = $this->settlements->number($payload['原币种净额'] ?? null);
                $usd = $this->settlements->number($payload['折算后结算金额'] ?? null);
                if (! $ledger && ! isset($amounts['payable']) && $usd !== null) {
                    $result['应结算'] = $usd;
                    $result['_calculate_payable'] = false;
                }
                if ($original !== null && $usd !== null && bccomp($original, '0', 8) > 0 && bccomp($usd, '0', 8) > 0) {
                    $result['_usd_exchange'] = [
                        'currency' => strtoupper(trim((string) (($payload['记账币种'] ?? '') ?: ($payload['交易币种'] ?? '')))),
                        'original' => $original,
                        'usd' => $usd,
                    ];
                }
            }

            return $result + array_filter([
                '商户订单号' => $payload['商户订单号'] ?? null,
                '备注' => $payload['备注'] ?? null,
                '是否3D' => filled($payload['是否3D'] ?? null) ? $payload['是否3D'] : ($payload['3DS'] ?? null),
                '发卡国家' => filled($payload['发卡国家'] ?? null) ? $payload['发卡国家'] : ($payload['发行国'] ?? null),
                '卡号' => preg_match('/(\d{4})$/D', $card, $parts) ? '****'.$parts[1] : null,
            ], fn ($value) => filled($value));
        });
    }

    private function payable(array $values): string
    {
        $net = bcadd($values['结算金额'], $values['保证金返还'] ?? '0', 8);
        $net = bcadd($net, $values['拒付返还'] ?? '0', 8);
        foreach (['hold', 'fee', 'per_order_fee', 'refund', 'refund_fee', 'chargeback', 'chargeback_fee',
            'ethoca_refund', 'ethoca_fee', 'rdr_refund', 'dispute_processing_fee'] as $deduction) {
            $net = bcsub($net, $values[self::AMOUNTS[$deduction]] ?? '0', 8);
        }
        // A legacy combined fee may already include either split amount.
        $splitFees = bcadd($values['RDR费用'] ?? '0', $values['CDRN费用'] ?? '0', 8);
        $fees = isset($values['RDR费用'], $values['CDRN费用']) ? $splitFees : ($values['拒付RDR+CDRN费用'] ?? $splitFees);

        return bcsub($net, $fees, 8);
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
