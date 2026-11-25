<?php

namespace App\Portals\Aikaipin;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AiProductOrderStatisticsService
{
    /**
     * @param  list<int>  $publicationIds
     * @return Collection<int, array{order_count:int,item_quantity:float,revenue:float,first_order_at:?string,last_order_at:?string}>
     */
    public function forPublications(array $publicationIds, ?CarbonInterface $from = null, ?CarbonInterface $to = null): Collection
    {
        $publicationIds = collect($publicationIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($publicationIds->isEmpty()) {
            return collect();
        }

        $statistics = collect();
        foreach ($publicationIds->chunk(500) as $ids) {
            $rows = $this->query($ids->all(), $from, $to);
            foreach ($rows as $row) {
                $statistics->put((int) $row->publication_id, [
                    'order_count' => (int) $row->order_count,
                    'item_quantity' => (float) $row->item_quantity,
                    'revenue' => (float) $row->revenue,
                    'first_order_at' => $row->first_order_at ?: null,
                    'last_order_at' => $row->last_order_at ?: null,
                ]);
            }
        }

        return $statistics;
    }

    /**
     * @return Collection<int, array{
     *     id:int,order_number:string,shopify_order_id:string,store_name:string,store_domain:string,
     *     ordered_at:?string,item_quantity:float,revenue:float,items:list<array{product_title:string,variant_title:string,sku:string,quantity:float,revenue:float}>
     * }>
     */
    public function ordersForPublication(int $publicationId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): Collection
    {
        if ($publicationId <= 0) {
            return collect();
        }

        $currentMatch = $this->identifierMatchSql('publications', 'items');
        $candidateMatch = $this->identifierMatchSql('candidate', 'items');
        $bindings = [$publicationId];
        $dateSql = '';

        if ($from !== null) {
            $dateSql .= ' AND orders.order_created_at >= ?';
            $bindings[] = $from->toDateTimeString();
        }
        if ($to !== null) {
            $dateSql .= ' AND orders.order_created_at <= ?';
            $bindings[] = $to->toDateTimeString();
        }

        $sql = <<<SQL
            SELECT
                orders.id AS order_id,
                orders.shopify_order_number,
                orders.shopify_order_id,
                orders.store_name,
                orders.source_shop_domain,
                orders.order_created_at,
                items.id AS item_id,
                items.product_title,
                items.variant_title,
                items.shopify_sku,
                items.quantity,
                items.line_revenue_usd
            FROM ai_product_shopify_publications AS publications
            INNER JOIN logistics_orders AS orders
                ON orders.assistant_domain_store_id = publications.assistant_domain_store_id
                AND orders.order_created_at >= publications.published_at
            INNER JOIN logistics_order_items AS items
                ON items.logistics_order_id = orders.id
                AND {$currentMatch}
            LEFT JOIN assistant_shopify_store_orders AS source_orders
                ON source_orders.assistant_domain_store_id = orders.assistant_domain_store_id
                AND source_orders.shopify_order_id = orders.shopify_order_id
            WHERE publications.id = ?
                AND publications.status = 'success'
                AND publications.published_at IS NOT NULL
                AND (source_orders.id IS NULL OR (source_orders.is_test = 0 AND source_orders.shopify_cancelled_at IS NULL))
                {$dateSql}
                AND publications.id = (
                    SELECT candidate.id
                    FROM ai_product_shopify_publications AS candidate
                    WHERE candidate.status = 'success'
                        AND candidate.published_at IS NOT NULL
                        AND candidate.assistant_domain_store_id = orders.assistant_domain_store_id
                        AND candidate.published_at <= orders.order_created_at
                        AND {$candidateMatch}
                    ORDER BY candidate.published_at DESC, candidate.id DESC
                    LIMIT 1
                )
            ORDER BY orders.order_created_at DESC, orders.id DESC, items.id ASC
        SQL;

        $rows = DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->select($sql, $bindings);

        return collect($rows)
            ->groupBy(static fn (object $row): int => (int) $row->order_id)
            ->map(static function (Collection $items): array {
                $order = $items->first();

                return [
                    'id' => (int) $order->order_id,
                    'order_number' => trim((string) ($order->shopify_order_number ?: $order->shopify_order_id ?: $order->order_id)),
                    'shopify_order_id' => trim((string) $order->shopify_order_id),
                    'store_name' => trim((string) $order->store_name),
                    'store_domain' => trim((string) $order->source_shop_domain),
                    'ordered_at' => $order->order_created_at ?: null,
                    'item_quantity' => (float) $items->sum(static fn (object $item): float => (float) $item->quantity),
                    'revenue' => (float) $items->sum(static fn (object $item): float => (float) $item->line_revenue_usd),
                    'items' => $items->map(static fn (object $item): array => [
                        'product_title' => trim((string) $item->product_title),
                        'variant_title' => trim((string) $item->variant_title),
                        'sku' => trim((string) $item->shopify_sku),
                        'quantity' => (float) $item->quantity,
                        'revenue' => (float) $item->line_revenue_usd,
                    ])->values()->all(),
                ];
            })
            ->values();
    }

    /** @param list<int> $publicationIds */
    private function query(array $publicationIds, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $placeholders = implode(',', array_fill(0, count($publicationIds), '?'));
        $currentMatch = $this->identifierMatchSql('publications', 'items');
        $candidateMatch = $this->identifierMatchSql('candidate', 'items');
        $bindings = $publicationIds;
        $dateSql = '';

        if ($from !== null) {
            $dateSql .= ' AND orders.order_created_at >= ?';
            $bindings[] = $from->toDateTimeString();
        }
        if ($to !== null) {
            $dateSql .= ' AND orders.order_created_at <= ?';
            $bindings[] = $to->toDateTimeString();
        }

        $sql = <<<SQL
            SELECT
                publications.id AS publication_id,
                COUNT(DISTINCT orders.id) AS order_count,
                COALESCE(SUM(items.quantity), 0) AS item_quantity,
                COALESCE(SUM(items.line_revenue_usd), 0) AS revenue,
                MIN(orders.order_created_at) AS first_order_at,
                MAX(orders.order_created_at) AS last_order_at
            FROM ai_product_shopify_publications AS publications
            INNER JOIN logistics_orders AS orders
                ON orders.assistant_domain_store_id = publications.assistant_domain_store_id
                AND orders.order_created_at >= publications.published_at
            INNER JOIN logistics_order_items AS items
                ON items.logistics_order_id = orders.id
                AND {$currentMatch}
            LEFT JOIN assistant_shopify_store_orders AS source_orders
                ON source_orders.assistant_domain_store_id = orders.assistant_domain_store_id
                AND source_orders.shopify_order_id = orders.shopify_order_id
            WHERE publications.id IN ({$placeholders})
                AND publications.status = 'success'
                AND publications.published_at IS NOT NULL
                AND (source_orders.id IS NULL OR (source_orders.is_test = 0 AND source_orders.shopify_cancelled_at IS NULL))
                {$dateSql}
                AND publications.id = (
                    SELECT candidate.id
                    FROM ai_product_shopify_publications AS candidate
                    WHERE candidate.status = 'success'
                        AND candidate.published_at IS NOT NULL
                        AND candidate.assistant_domain_store_id = orders.assistant_domain_store_id
                        AND candidate.published_at <= orders.order_created_at
                        AND {$candidateMatch}
                    ORDER BY candidate.published_at DESC, candidate.id DESC
                    LIMIT 1
                )
            GROUP BY publications.id
        SQL;

        return DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->select($sql, $bindings);
    }

    private function identifierMatchSql(string $publicationAlias, string $itemAlias): string
    {
        $publicationProduct = "NULLIF(TRIM({$publicationAlias}.shopify_product_id), '')";
        $itemProduct = "NULLIF(TRIM({$itemAlias}.shopify_product_id), '')";
        $publicationVariant = "NULLIF(TRIM({$publicationAlias}.shopify_variant_id), '')";
        $itemVariant = "NULLIF(TRIM({$itemAlias}.shopify_variant_id), '')";
        $publicationSku = "NULLIF(TRIM({$publicationAlias}.sku), '')";
        $itemSku = "NULLIF(TRIM({$itemAlias}.shopify_sku), '')";

        return <<<SQL
            (
                (
                    {$publicationProduct} IS NOT NULL
                    AND {$itemProduct} IS NOT NULL
                    AND SUBSTRING_INDEX({$publicationAlias}.shopify_product_id, '/', -1) = SUBSTRING_INDEX({$itemAlias}.shopify_product_id, '/', -1)
                )
                OR (
                    ({$publicationProduct} IS NULL OR {$itemProduct} IS NULL)
                    AND {$publicationVariant} IS NOT NULL
                    AND {$itemVariant} IS NOT NULL
                    AND SUBSTRING_INDEX({$publicationAlias}.shopify_variant_id, '/', -1) = SUBSTRING_INDEX({$itemAlias}.shopify_variant_id, '/', -1)
                )
                OR (
                    ({$publicationProduct} IS NULL OR {$itemProduct} IS NULL)
                    AND ({$publicationVariant} IS NULL OR {$itemVariant} IS NULL)
                    AND {$publicationSku} IS NOT NULL
                    AND {$itemSku} IS NOT NULL
                    AND LOWER({$publicationAlias}.sku) = LOWER({$itemAlias}.shopify_sku)
                )
            )
        SQL;
    }
}
