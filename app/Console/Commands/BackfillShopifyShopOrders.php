<?php

namespace App\Console\Commands;

use App\Models\LogisticsOrder;
use App\Models\SharedLogisticsOrder;
use App\Models\SharedLogisticsOrderItem;
use App\Portals\Logistics\SharedLogisticsOrderProjection;
use App\Portals\Logistics\ShopifyOrderSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class BackfillShopifyShopOrders extends Command
{
    protected $signature = 'shop-orders:backfill-shopify {--dry-run} {--from-id=1} {--to-id=} {--pending} {--only=*} {--check}';

    private const ORDER_COLUMNS = [
        'assistant_domain_store_id', 'store_name', 'buyer_name', 'buyer_email', 'buyer_phone',
        'payment_status', 'logistics_status', 'subscription_type', 'subscription_status',
        'subscription_origin_order_id', 'is_add_on', 'payment_method', 'payment_gateway',
        'transaction_id', 'product_cost_usd', 'handling_fee_usd', 'total_shipping_usd',
        'proportional_profit_usd', 'proportional_profit_rate', 'financial_profit_usd',
        'financial_profit_rate', 'operation_profit_usd', 'operation_profit_rate', 'created_at', 'updated_at',
    ];

    private const ITEM_COLUMNS = [
        'quantity', 'tongtu_sku_mapping_id', 'tongtu_product_id', 'tongtu_goods_detail_id',
        'tongtu_sku', 'tongtu_product_code', 'tongtu_product_name', 'tongtu_product_type',
        'tongtu_goods_weight_gram', 'tongtu_package_weight_gram', 'sale_weight_gram',
        'unit_price_usd', 'line_revenue_usd', 'product_cost_usd', 'handling_fee_usd',
        'total_shipping_usd', 'proportional_refund_usd', 'profit_usd', 'profit_rate', 'created_at', 'updated_at',
    ];

    private const PAYLOAD_COLUMNS = [
        'platform_order' => 'shopify_payload',
        'tongtu_order' => 'tongtu_payload',
        'fulfillment' => 'fulfillment_payload',
        'tracking' => 'tracking_raw_payload',
        'page_source' => 'page_source',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('logistics.shop_order_backfill.description'));
    }

    public function handle(): int
    {
        $db = (new LogisticsOrder())->getConnection();
        $schema = $db->getSchemaBuilder();
        foreach (['shop_order_list', 'shop_order_items', 'shop_order_payloads', 'shop_order_source_map'] as $table) {
            if (! $schema->hasTable($table)) {
                $this->error(__('logistics.shop_order_backfill.missing_table', ['table' => $table]));

                return self::FAILURE;
            }
        }

        $projectionEnabled = $schema->hasTable('shop_order_logistics_pending');
        $pending = (bool) $this->option('pending');
        $check = (bool) $this->option('check');
        if (($pending || $check) && ! $projectionEnabled) {
            $this->error(__('logistics.shop_order_backfill.missing_table', ['table' => 'shop_order_logistics_pending']));

            return self::FAILURE;
        }
        if ($check) {
            return $this->checkProjection($db);
        }
        $projectionColumns = $projectionEnabled ? array_flip($schema->getColumnListing('shop_order_logistics')) : [];

        $from = filter_var($this->option('from-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $toOption = $this->option('to-id');
        $to = $toOption === null
            ? (int) ($pending ? $db->table('shop_order_logistics_pending')->max('source_id') : $db->table('logistics_orders')->max('id'))
            : filter_var($toOption, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($from === false || $to === false || ($toOption !== null && $to < $from)) {
            $this->error(__('logistics.shop_order_backfill.invalid_range'));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(__('logistics.shop_order_backfill.range', [
            'from' => $from, 'to' => $to, 'database' => $db->getDatabaseName(),
        ]));
        $counts = array_fill_keys(['written', 'mapped', 'invalid_identity', 'conflict', 'unknown_money', 'unknown_order_time', 'unknown_paid_time'], 0);
        $seen = [];
        $key = $pending ? 'source_id' : 'id';
        $query = $db->table($pending ? 'shop_order_logistics_pending' : 'logistics_orders')
            ->select($key)->whereBetween($key, [$from, $to]);
        $only = $this->option('only');
        if ($only !== []) {
            $query->whereIntegerInRaw($key, $only);
        }
        $query->chunkById(200, function ($rows) use ($db, $key, $dryRun, $pending, $projectionColumns, &$counts, &$seen): void {
            foreach ($rows as $row) {
                $id = (int) $row->{$key};
                $result = $db->transaction(function () use ($db, $id, $dryRun, $pending, $projectionColumns, &$seen): array {
                    return $this->backfillOrder($db, $id, $dryRun, $seen, $pending, $projectionColumns);
                }, 3);
                foreach ($result as $status) {
                    $counts[$status]++;
                    if ($counts[$status] <= 10 && ! in_array($status, ['written', 'mapped'], true)) {
                        $this->warn(__('logistics.shop_order_backfill.'.$status, ['id' => $id]));
                    }
                }
            }
        }, $key);

        $this->info(__('logistics.shop_order_backfill.summary', [
            'mode' => __('logistics.shop_order_backfill.'.($dryRun ? 'dry_run' : 'saved')), ...$counts,
        ]));

        if ($pending && ! $dryRun && $only === [] && $from === 1 && $toOption === null
            && ! $db->table('shop_order_logistics_pending')->where('source_id', '>', 0)->exists()) {
            $db->table('shop_order_logistics_pending')->where('source_id', 0)->delete();
        }

        return $counts['invalid_identity'] + $counts['conflict'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkProjection(Connection $db): int
    {
        $counts = $db->transaction(function () use ($db): array {
            $fields = ['shopify_order_number', 'payment_status', 'data_status', 'logistics_status', 'paid_amount_usd',
                'order_created_at', 'paid_at', 'tracking_number'];
            $orders = SharedLogisticsOrder::query()->select(['id', ...$fields])->toBase();
            $items = SharedLogisticsOrderItem::query()->select([
                'legacy_logistics_item_id', 'logistics_order_id', 'shopify_sku', 'quantity',
            ])->toBase();
            $orderComparison = $db->table('logistics_orders as old')->leftJoinSub($orders, 'current', 'current.id', '=', 'old.id');
            $itemComparison = $db->table('logistics_order_items as old')
                ->leftJoinSub($items, 'current', function ($join): void {
                    $join->on('current.legacy_logistics_item_id', '=', 'old.id')
                        ->on('current.logistics_order_id', '=', 'old.logistics_order_id');
                });

            return [
                'missing_orders' => (clone $orderComparison)->whereNull('current.id')->count(),
                'different_orders' => $orderComparison->whereNotNull('current.id')->where(function ($query) use ($fields): void {
                    foreach ($fields as $field) {
                        $query->orWhereRaw("NOT (current.`{$field}` <=> old.`{$field}`)");
                    }
                })->count(),
                'different_items' => $itemComparison->where(function ($query): void {
                    $query->whereNull('current.legacy_logistics_item_id')
                        ->orWhereRaw('NOT (current.shopify_sku <=> old.shopify_sku)')
                        ->orWhereRaw('NOT (current.quantity <=> old.quantity)');
                })->count(),
                'extra_orders' => $db->query()->fromSub($orders, 'current')
                    ->leftJoin('logistics_orders as old', 'old.id', '=', 'current.id')->whereNull('old.id')->count(),
                'extra_items' => $db->query()->fromSub($items, 'current')
                    ->joinSub($orders, 'projected_order', 'projected_order.id', '=', 'current.logistics_order_id')
                    ->leftJoin('logistics_order_items as old', function ($join): void {
                        $join->on('old.id', '=', 'current.legacy_logistics_item_id')
                            ->on('old.logistics_order_id', '=', 'current.logistics_order_id');
                    })
                    ->whereNull('old.id')->count(),
                'pending' => $db->table('shop_order_logistics_pending')->where('source_id', '>', 0)->count(),
            ];
        });

        $this->info(__('logistics.shop_order_backfill.projection_check', $counts));

        return array_sum($counts) === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function backfillOrder(Connection $db, int $sourceId, bool $dryRun, array &$seen, bool $pending, array $projectionColumns): array
    {
        $source = $db->table('logistics_orders')->where('id', $sourceId);
        $order = (array) ($dryRun ? $source->first() : $source->lockForUpdate()->first());
        $queue = $db->table('shop_order_logistics_pending')->where('source_id', $sourceId);
        if ($pending && ! $dryRun && $queue->lockForUpdate()->first() === null) {
            return [];
        }

        $sourceMap = $db->table('shop_order_source_map')->where('source_table', 'logistics_orders')->where('source_id', $sourceId);
        if ($order === []) {
            if (! $pending || $dryRun) {
                return ['invalid_identity'];
            }
            $db->table('shop_order_logistics')->where('id', $sourceId)->delete();
            // Keep the shared order and its stable mapping as historical identity.
            $queue->delete();

            return ['written'];
        }

        $orderId = $sourceMap->value('order_id');
        if ($orderId !== null && ! $pending) {
            return ['mapped'];
        }
        if ($orderId !== null && ! $db->table('shop_order_list')->where('id', $orderId)
            ->where('source_platform', 'shopify')->where('source_system', 'logistics')->exists()) {
            return ['conflict'];
        }

        $domain = strtolower(trim((string) $order['source_shop_domain']));
        $domain = rtrim(preg_split('~[/?#]~', preg_replace('~^https?://~', '', $domain), 2)[0], '.');
        $externalId = preg_replace('#^gid://shopify/Order/#', '', trim((string) $order['shopify_order_id']));
        if ($orderId === null && (! str_contains($domain, '.') || ! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || ! preg_match('/^[0-9]{1,100}$/D', $externalId))) {
            return ['invalid_identity'];
        }

        $identity = ['source_platform' => 'shopify', 'source_domain' => $domain, 'external_order_id' => $externalId];
        $key = $domain.'|'.$externalId;
        if ($orderId === null && (isset($seen[$key]) || $db->table('shop_order_list')->where($identity)->exists())) {
            return ['conflict'];
        }

        $payload = json_decode((string) $order['shopify_payload'], true, flags: JSON_BIGINT_AS_STRING);
        $payload = is_array($payload) ? $payload : [];
        // The webhook fallback fabricates USD and uses a fulfillments.nodes object.
        $fulfillments = $payload['fulfillments'] ?? null;
        $graphQlPayload = is_array($fulfillments) && array_is_list($fulfillments)
            && ! collect(Arr::get($payload, 'lineItems.nodes', []))->contains(fn ($line) => is_array($line) && array_key_exists('product', $line));
        $currency = Arr::get($payload, 'currentTotalPriceSet.shopMoney.currencyCode');
        $total = $graphQlPayload ? $this->amount($payload, 'currentTotalPriceSet', $currency) : null;
        $currency = $total !== null ? $currency : null;
        $transactions = $payload['transactions'] ?? [];
        $payment = ShopifyOrderSyncService::successfulPayment(is_array($transactions) ? array_filter($transactions, 'is_array') : []);
        $wasPaid = in_array(strtoupper((string) ($payload['displayFinancialStatus'] ?? $order['payment_status'])), [
            'PAID', 'PARTIALLY_PAID', 'PARTIALLY_REFUNDED', 'REFUNDED', '已付款', '部分付款', '部分退款', '已退款',
        ], true);
        $created = $this->beijingTime($payload['createdAt'] ?? null);
        $paid = $this->beijingTime($payment['processedAt'] ?? ($wasPaid ? ($payload['processedAt'] ?? null) : null));

        if ($order['shopify_payload'] === null && $db->table('logistics_sync_logs')
            ->where('logistics_order_id', $sourceId)
            ->where('source', 'manual')
            ->where('action', 'create')
            ->where('status', 'success')
            ->exists()) {
            $total = $this->decimalAmount($order['paid_amount_usd']);
            $currency = $total !== null ? 'USD' : null;
            // Manual entry stores the submitted Beijing times as UTC DATETIME values.
            $created = $this->beijingTime(str_replace(' ', 'T', (string) $order['order_created_at']).'Z');
            $paid = $this->beijingTime(str_replace(' ', 'T', (string) $order['paid_at']).'Z');
        }

        $result = ['written'];
        if ($total === null) {
            $result[] = 'unknown_money';
        }
        if ($created === null) {
            $result[] = 'unknown_order_time';
        }
        if ($paid === null && ($wasPaid || $payment !== [])) {
            $result[] = 'unknown_paid_time';
        }

        if ($dryRun) {
            $seen[$key] = true;

            return $result;
        }

        $attributes = [
            ...Arr::only($order, self::ORDER_COLUMNS),
            'order_number' => $order['shopify_order_number'],
            'buyer_external_id' => $order['shopify_customer_id'] ?? Arr::get($payload, 'customer.id'),
            'shipping_country' => Arr::get($payload, 'shippingAddress.country'),
            'fulfillment_status' => $payload['displayFulfillmentStatus'] ?? null,
            'currency' => $currency,
            'total_amount' => $total,
            'shipping_amount' => $this->amount($payload, 'currentShippingPriceSet', $currency),
            'discount_amount' => $this->amount($payload, 'currentTotalDiscountsSet', $currency),
            'tax_amount' => $this->amount($payload, 'currentTotalTaxSet', $currency),
            'order_created_at' => $created,
            'paid_at' => $paid,
            'order_modified_at' => $this->beijingTime($payload['updatedAt'] ?? null),
        ];
        if ($orderId === null) {
            $orderId = $db->table('shop_order_list')->insertGetId([...$attributes, ...$identity, 'source_system' => 'logistics']);
            $db->table('shop_order_source_map')->insert([
                'source_table' => 'logistics_orders',
                'source_id' => $sourceId,
                'order_id' => $orderId,
                'created_at' => now(),
            ]);
        } else {
            $db->table('shop_order_list')->where('id', $orderId)->update($attributes);
        }
        $this->insertItems($db, $sourceId, (int) $orderId, $projectionColumns !== []);
        $this->insertPayloads($db, $order, $payload, $orderId);
        if ($projectionColumns !== []) {
            SharedLogisticsOrderProjection::write($db, $order, $projectionColumns);
        }
        if ($pending) {
            $queue->delete();
        }

        return $result;
    }

    private function insertItems(Connection $db, int $sourceId, int $orderId, bool $projectionEnabled): void
    {
        $items = $db->table('logistics_order_items')->where('logistics_order_id', $sourceId)->orderBy('id')->get();
        $lineKeys = [];
        foreach ($items as $row) {
            $item = (array) $row;
            $lineKey = $item['source_key'] ?: 'legacy:logistics:'.$item['id'];
            $lineKeys[] = $lineKey;
            $db->table('shop_order_items')->updateOrInsert(['order_id' => $orderId, 'line_key' => $lineKey], [
                ...Arr::only($item, self::ITEM_COLUMNS),
                ...($projectionEnabled ? ['legacy_logistics_item_id' => $item['id']] : []),
                'external_item_id' => $item['shopify_line_item_id'],
                'external_product_id' => $item['shopify_product_id'],
                'external_variant_id' => $item['shopify_variant_id'],
                'product_name' => $item['product_title'],
                'variant_name' => $item['variant_title'],
                'sku' => $item['shopify_sku'],
                'image_url' => $item['product_image_url'],
            ]);
        }
        $db->table('shop_order_items')->where('order_id', $orderId)->whereNotIn('line_key', $lineKeys)->delete();
    }

    private function insertPayloads(Connection $db, array $order, array $payload, int $orderId): void
    {
        $raw = [];
        foreach (self::PAYLOAD_COLUMNS as $type => $column) {
            $raw[$type] = $order[$column] ?? null;
        }
        $detail = array_filter([
            'legacy_destination' => $order['destination'],
            'shipping_address' => $payload['shippingAddress'] ?? null,
            'note' => $payload['note'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
        if ($detail !== []) {
            $raw['order_detail'] = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        foreach ($raw as $type => $value) {
            if ($value !== null && $value !== '') {
                $db->table('shop_order_payloads')->updateOrInsert([
                    'order_id' => $orderId,
                    'payload_type' => $type,
                ], [
                    'payload' => $value,
                    'created_at' => $order['created_at'],
                    'updated_at' => $order['updated_at'],
                ]);
            } else {
                $db->table('shop_order_payloads')->where('order_id', $orderId)->where('payload_type', $type)->delete();
            }
        }
    }

    private function amount(array $payload, string $field, mixed $currency): ?string
    {
        $money = $payload[$field]['shopMoney'] ?? null;
        if (! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', $currency) || ($money['currencyCode'] ?? null) !== $currency) {
            return null;
        }

        return $this->decimalAmount($money['amount'] ?? null);
    }

    private function decimalAmount(mixed $amount): ?string
    {
        return (is_string($amount) || is_int($amount) || is_float($amount))
            && preg_match('/^-?\d{1,14}(\.\d{1,4})?$/D', (string) $amount) ? (string) $amount : null;
    }

    private function beijingTime(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)
            || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s');
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
