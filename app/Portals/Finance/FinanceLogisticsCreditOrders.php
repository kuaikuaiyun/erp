<?php

namespace App\Portals\Finance;

use App\Models\AssistantDomainStore;
use App\Models\FinanceRecord;
use App\Portals\Logistics\ShopifyOrderSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class FinanceLogisticsCreditOrders
{
    public const ACCOUNT_LABELS = ['SHANGYUN' => '徐总1', 'SHANGYUN-v2' => '徐总2'];
    public const IMPORTED_DATASET = 'xuzong-credit-imported-orders';
    private const XUZONG_MERCHANTS = ['fintrix' => 'shangyun', 'fintrixv2' => 'shangyun-v2'];
    private const FAILED_DATASET = 'xuzong-credit-failed-orders';

    public function query(
        bool $withPayload = true,
        bool $xuzongReport = false,
        bool $withPayments = true,
        ?array $ids = null,
        ?string $searchReference = null,
        bool $includeReplaced = false,
        bool $indexOnly = false,
    ): Builder
    {
        $db = app(FinanceWorkbookService::class)->database();
        if (! $db->getSchemaBuilder()->hasTable('logistics_orders')) {
            return $xuzongReport
                ? FinanceRecord::query()->fromSub($this->storedOrders($ids, $searchReference, $indexOnly), 'finance_records')
                : FinanceRecord::query()->whereRaw('1 = 0');
        }
        $grammar = $db->getQueryGrammar();
        $json = fn ($key) => $grammar->wrap('o.shopify_payload->'.str_replace('.', '->', $key));
        $account = "COALESCE(o.collection_account, '')";
        $reportAccount = $xuzongReport
            ? "CASE LOWER(TRIM(o.payment_gateway)) WHEN 'fintrix' THEN 'SHANGYUN' ELSE 'SHANGYUN-v2' END"
            : $account;
        $reference = $xuzongReport ? "NULLIF(TRIM(o.payment_gateway_order_id), '')"
            : "COALESCE(NULLIF(o.transaction_id, ''), NULLIF(o.payment_gateway_order_id, ''), NULLIF(o.bill_number, ''), o.shopify_order_number)";
        $hasStores = $db->getSchemaBuilder()->hasTable('assistant_domain_stores')
            && $db->getSchemaBuilder()->hasColumn('logistics_orders', 'assistant_domain_store_id');
        $domain = $hasStores ? "COALESCE(NULLIF(s.domain, ''), NULLIF(o.source_shop_domain, ''), '')" : "COALESCE(NULLIF(o.source_shop_domain, ''), '')";
        $indexed = $db->getSchemaBuilder()->hasColumn('logistics_orders', 'finance_is_credit_card');
        $source = $db->table('logistics_orders as o');
        if ($ids !== null) {
            $source->whereIntegerInRaw('o.id', array_filter($ids, fn ($id) => $id > 0));
        }
        if ($hasStores && ! $indexOnly) {
            $source->leftJoin('assistant_domain_stores as s', 's.id', '=', 'o.assistant_domain_store_id');
        }
        if ($xuzongReport) {
            $source->where(function ($orders): void {
                foreach (self::XUZONG_MERCHANTS as $gateway => $merchant) {
                    $orders->orWhere(function ($orders) use ($gateway, $merchant): void {
                        // Older gateway records can lack a merchant alias.
                        $orders->whereRaw('LOWER(TRIM(o.payment_gateway)) = ?', [$gateway])
                            ->whereRaw("LOWER(TRIM(COALESCE(o.collection_account, ''))) IN (?, ?)", ['', $merchant]);
                    });
                }
            });
            if (! $includeReplaced) {
                $source->whereNotIn('o.id', fn ($import) => $import
                    ->selectRaw('CAST('.$grammar->wrap('imported.payload->_replaced_logistics_id').' AS SIGNED)')
                    ->from('finance_records as imported')
                    ->where('imported.dataset', self::IMPORTED_DATASET)
                    ->whereNotNull('imported.payload->_replaced_logistics_id'));
            }
        } elseif ($db->getSchemaBuilder()->hasColumn('logistics_orders', 'finance_is_non_paypal')) {
            $source->where('o.finance_is_non_paypal', 1);
        } else {
            $source->whereRaw("LOWER(TRIM(COALESCE(o.payment_gateway, ''))) <> ?", ['paypal']);
        }
        $occurredAt = $indexed ? 'o.finance_credit_occurred_at' : 'COALESCE(o.order_created_at, o.paid_at)';
        $id = $xuzongReport ? 'CAST(o.id AS SIGNED) as id' : 'o.id';
        if ($indexOnly) {
            $source->selectRaw("{$id}, {$reportAccount} as account, {$occurredAt} as occurred_at");
            if ($xuzongReport) {
                $source->unionAll($this->storedOrders($ids, $searchReference, true));
            }

            return FinanceRecord::query()->fromSub($source, 'finance_records');
        }
        $source->selectRaw("{$id}, 'xuzong2-orders' as dataset, {$reportAccount} as account, {$reference} as reference,
            {$occurredAt} as occurred_at, 'USD' as currency, {$domain} as domain, NULL as raw_type");
        if ($xuzongReport) {
            $statuses = FinanceSettlementService::CARD_FAILURE_STATUSES;
            $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
            $source->selectRaw("CASE WHEN UPPER(TRIM(o.payment_status)) IN ({$placeholders}) THEN '失败' ELSE o.payment_status END as status", $statuses);
            $source->selectRaw(($withPayments ? $json('transactions') : 'NULL').' as payment_transactions');
            if ($searchReference !== null) {
                $source->where(fn ($order) => $order->where('o.transaction_id', $searchReference)
                    ->orWhereRaw($reference.' = ?', [$searchReference]));
            }
        } else {
            $source->addSelect('o.payment_status as status');
        }

        if ($withPayload) {
            $values = [
                'o.store_name', $account, 'o.shopify_order_number', $reference,
                'COALESCE('.$json('currentTotalPriceSet.shopMoney.currencyCode').', '.$json('currency').')',
                'COALESCE('.$json('currentTotalPriceSet.shopMoney.amount').', '.$json('total_price').')', "'USD'", 'o.paid_amount_usd',
                'o.payment_status', 'o.payment_method', $domain, 'o.tongtu_order_number',
                'o.subscription_type', 'o.is_add_on', 'o.order_created_at', 'o.paid_at',
                $hasStores ? 's.user_name' : 'NULL', $hasStores ? 's.team' : 'NULL',
            ];
            $pairs = [];
            $bindings = [];
            foreach (FinanceCatalog::dataset('xuzong2-orders')['headers'] as $index => $header) {
                $pairs[] = '?, '.$values[$index];
                $bindings[] = $header;
            }
            $source->selectRaw('JSON_OBJECT('.implode(', ', $pairs).') as payload', $bindings);
        } elseif ($xuzongReport) {
            // Finance-only failures still need their stored fields for the order filters.
            $source->selectRaw('NULL as payload');
        }

        if ($xuzongReport) {
            $refund = $this->refundExists($db, 'o.id');
            $source->selectRaw("CASE WHEN {$refund} OR UPPER(TRIM(o.payment_status)) IN ('退款', '已退款', 'REFUNDED', 'PARTIALLY_REFUNDED') THEN '退款' ELSE '成功' END as match_status");
            $source->unionAll($this->storedOrders($ids, $searchReference));
        }

        return FinanceRecord::query()->fromSub($source, 'finance_records');
    }

    private function storedOrders(?array $ids, ?string $searchReference, bool $indexOnly = false): QueryBuilder
    {
        $db = app(FinanceWorkbookService::class)->database();
        // Negative report keys avoid collisions with logistics IDs while supporting the same export selection.
        $orders = $db->table('finance_records as f')->whereIn('f.dataset', [self::FAILED_DATASET, self::IMPORTED_DATASET]);
        if ($ids !== null) {
            $orders->whereIntegerInRaw('f.id', array_map('abs', array_filter($ids, fn ($id) => $id < 0)));
        }
        if ($searchReference !== null) {
            $orders->where(fn ($order) => $order->where('f.reference', $searchReference)
                ->orWhere('f.payload->_legacy_order_id', $searchReference)
                ->orWhere('f.payload->_linked_platform_order_id', $searchReference)
                ->orWhere('f.payload->交易流水号', $searchReference)
                ->orWhere('f.payload->商户订单号', $searchReference)
                ->orWhere('f.payload->通途订单', $searchReference));
        }
        if ($db->getSchemaBuilder()->hasColumns('logistics_orders', ['source_shop_domain', 'shopify_order_id', 'paid_at', 'payment_gateway'])) {
            $orders->whereNotExists(fn ($paid) => $paid->selectRaw('1')->from('logistics_orders as paid')
                ->whereColumn('paid.source_shop_domain', 'f.payload->_source_shop_domain')
                ->whereColumn('paid.shopify_order_id', 'f.payload->_shopify_order_id')
                ->whereRaw('LOWER(TRIM(paid.payment_gateway)) IN (?, ?)', array_keys(self::XUZONG_MERCHANTS))
                ->whereNotNull('paid.paid_at'));
        }
        if ($indexOnly) {
            return $orders->selectRaw('-CAST(f.id AS SIGNED) as id, f.account, f.occurred_at');
        }
        $orders->selectRaw('-CAST(f.id AS SIGNED) as id, f.dataset, f.account, f.reference, f.occurred_at,
            f.currency, f.domain, f.raw_type, f.status, NULL as payment_transactions')->addSelect('f.payload');
        $link = $db->getQueryGrammar()->wrap('f.payload->_linked_logistics_id');
        $match = $db->getSchemaBuilder()->hasTable('logistics_orders')
            ? "EXISTS (SELECT 1 FROM logistics_orders AS linked WHERE linked.id = CAST({$link} AS SIGNED))"
            : '1 = 0';
        $refundFlag = $db->getQueryGrammar()->wrap('f.payload->退款');
        $refund = $this->refundExists($db, "CAST({$link} AS SIGNED)");
        $orders->selectRaw("CASE WHEN {$refundFlag} = '是'
            OR UPPER(TRIM(f.status)) IN ('退款', '已退款', 'REFUNDED', 'PARTIALLY_REFUNDED')
            OR (UPPER(TRIM(f.status)) IN ('SUCCESS', 'SUCCEEDED', 'PAID', '成功', '已付款') AND {$refund}) THEN '退款'
            WHEN {$match} THEN '成功' ELSE '失败' END as match_status");

        return $orders;
    }

    private function refundExists(\Illuminate\Database\Connection $db, string $orderId): string
    {
        return $db->getSchemaBuilder()->hasColumns('logistics_credit_card_refunds', ['logistics_order_id'])
            ? "EXISTS (SELECT 1 FROM logistics_credit_card_refunds AS refund WHERE refund.logistics_order_id = {$orderId})"
            : '1 = 0';
    }

    public function captureFailure(array $order, array $attributes, array $transactions, ?string $sourceDomain, ?AssistantDomainStore $store): void
    {
        if (blank($sourceDomain) || blank($order['createdAt'] ?? null) || ShopifyOrderSyncService::successfulPayment($transactions) !== []) {
            return;
        }
        $account = trim($attributes['yx_payment_alias'] ?? '');
        foreach ($transactions as $transaction) {
            if (! in_array(strtoupper($transaction['kind'] ?? ''), ['SALE', 'AUTHORIZATION', 'CAPTURE'], true)
                || ! in_array(strtoupper($transaction['status'] ?? ''), ['FAILURE', 'ERROR'], true)) {
                continue;
            }
            $gateway = trim(($attributes['yx_payment_gateway'] ?? '') ?: ($transaction['gateway'] ?? ''));
            $expectedMerchant = self::XUZONG_MERCHANTS[strtolower($gateway)] ?? null;
            if ($expectedMerchant === null || ($account !== '' && strtolower($account) !== $expectedMerchant)) {
                continue;
            }
            $db = app(FinanceWorkbookService::class)->database();
            if (! $db->getSchemaBuilder()->hasTable('finance_records')) {
                return;
            }
            $shopifyId = (string) ($order['legacyResourceId'] ?? $order['id']);
            $created = CarbonImmutable::parse($order['createdAt'])->utc();
            $reference = trim($attributes['yx_payment_gateway_order_id'] ?? '');
            $domain = $store?->domain ?: $sourceDomain;
            FinanceRecord::query()->updateOrCreate([
                'fingerprint' => hash('sha256', self::FAILED_DATASET.'|'.$sourceDomain.'|'.$shopifyId),
            ], [
                'dataset' => self::FAILED_DATASET, 'account' => strtolower($gateway) === 'fintrix' ? 'SHANGYUN' : 'SHANGYUN-v2', 'reference' => $reference,
                'occurred_at' => $created, 'source_timezone' => 'UTC', 'domain' => $domain, 'status' => '失败',
                'payload' => [
                    '店铺名称' => $store?->shop_name, '收款账户' => $account, 'Shopify订单号' => ltrim((string) ($order['name'] ?? ''), '#'),
                    '店铺币种' => data_get($order, 'currentTotalPriceSet.shopMoney.currencyCode'),
                    '店铺订单金额' => data_get($order, 'currentTotalPriceSet.shopMoney.amount'),
                    '支付方式' => $attributes['yx_payment_method'] ?? null, '创建时间' => $created->toDateTimeString(),
                    '_source_shop_domain' => $sourceDomain, '_shopify_order_id' => $shopifyId, '_gateway' => $gateway,
                    '_buyer_email' => $order['email'] ?? data_get($order, 'customer.email'),
                    '_bill_number' => $attributes['yx_payment_gateway_txn_id'] ?? null,
                    '_legacy_order_id' => $attributes['yx_order_id'] ?? null,
                ],
            ]);

            return;
        }
    }

    public static function actualPayment(?string $transactions): array
    {
        $transactions = json_decode($transactions ?? '', true);
        $payment = ShopifyOrderSyncService::successfulPayment(is_array($transactions) ? $transactions : []);
        $presentment = data_get($payment, 'amountSet.presentmentMoney');
        $shopMoney = data_get($payment, 'amountSet.shopMoney');
        $money = $presentment ?? $shopMoney
            ?? ['currencyCode' => $payment['currency'] ?? null, 'amount' => $payment['amount'] ?? null];

        $result = [
            'currency' => strtoupper(trim((string) ($money['currencyCode'] ?? ''))),
            'amount' => is_numeric($money['amount'] ?? null) ? (string) $money['amount'] : '',
        ];
        // The USD equivalent must come from the same successful payment as the original amount.
        if ($presentment !== null && $result['currency'] !== 'USD' && ($shopMoney['currencyCode'] ?? '') === 'USD') {
            $original = $result['amount'];
            $usd = (string) ($shopMoney['amount'] ?? '');
            if (preg_match('/^\d+(?:\.\d{1,8})?$/D', $original) && preg_match('/^\d+(?:\.\d{1,8})?$/D', $usd)
                && bccomp($original, '0', 8) > 0 && bccomp($usd, '0', 8) > 0) {
                $result['usd_exchange'] = ['currency' => $result['currency'], 'original' => $original, 'usd' => $usd];
            }
        }

        return $result;
    }
}
