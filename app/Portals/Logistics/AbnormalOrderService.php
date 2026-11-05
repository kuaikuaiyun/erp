<?php

namespace App\Portals\Logistics;

use App\Models\AdminUser;
use App\Models\AbnormalOrderRecord;
use App\Models\AssistantDomainStore;
use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderItem;
use App\Models\ProductRequestLinkSku;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class AbnormalOrderService
{
    /** 异常订单仅统计该日期（含）之后产生的订单。 */
    public const STATISTICS_START_AT = '2026-10-20 00:00:00';

    /** @var array{skus:list<string>,product_codes:list<string>}|null */
    private ?array $cachedKeys = null;

    /** @var array<string, AdminUser|null> */
    private array $userIdentityCache = [];

    /** @return array{skus:list<string>,product_codes:list<string>} */
    public function nonRunnableKeys(): array
    {
        if ($this->cachedKeys !== null) return $this->cachedKeys;
        $records = ProductRequestLinkSku::query()->where('is_runnable', false)
            ->get(['sku_values', 'skus', 'pcl_product_no']);
        $normalize = static fn (mixed $value): string => Str::lower(trim((string) $value));

        return $this->cachedKeys = [
            'skus' => $records->flatMap(static fn (ProductRequestLinkSku $record): array => array_merge(
                array_keys((array) $record->sku_values),
                preg_split('/[\r\n,，;；]+/u', (string) $record->skus, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            ))->map($normalize)->filter()->unique()->values()->all(),
            'product_codes' => $records->pluck('pcl_product_no')->map($normalize)->filter()->unique()->values()->all(),
        ];
    }

    public function query(): Builder
    {
        $keys = $this->nonRunnableKeys();
        return LogisticsOrder::query()
            ->where('order_created_at', '>=', self::STATISTICS_START_AT)
            ->whereIn('subscription_type', ['订阅首次购买', '无订阅'])
            ->where(function (Builder $abnormal) use ($keys): void {
                if ($keys['skus'] !== [] || $keys['product_codes'] !== []) {
                    $abnormal->whereHas('items', fn (Builder $items) => $this->scopeNonRunnableItems($items, $keys));
                } else {
                    $abnormal->whereRaw('1 = 0');
                }
                $abnormal->orWhereHas('items', fn (Builder $items) => $this->scopeMissingSkuItems($items));
            });
    }

    public function scopeType(Builder $query, string $type): Builder
    {
        if ($type === 'non_runnable') {
            $keys = $this->nonRunnableKeys();
            if ($keys['skus'] === [] && $keys['product_codes'] === []) return $query->whereRaw('1 = 0');
            return $query->whereHas('items', fn (Builder $items) => $this->scopeNonRunnableItems($items, $keys));
        }
        if ($type === 'missing_sku') {
            return $query->whereHas('items', fn (Builder $items) => $this->scopeMissingSkuItems($items));
        }
        return $query;
    }

    public function scopeForOwner(Builder $query, AdminUser $user): Builder
    {
        $identities = collect([$user->name, $user->username])
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $query->where(function (Builder $owner) use ($user, $identities): void {
            $owner->where('assigned_owner_user_id', (int) $user->getKey());
            if ($identities !== []) {
                $owner->orWhere(function (Builder $fallback) use ($identities): void {
                    $fallback->whereNull('assigned_owner_user_id')
                        ->whereHas('store', fn (Builder $store) => $store->whereIn('user_name', $identities));
                });
            }
        });
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where(function (Builder $pending): void {
            $pending->whereDoesntHave('abnormalRecord')
                ->orWhereHas('abnormalRecord', fn (Builder $record) => $record
                    ->where('status', AbnormalOrderRecord::STATUS_PENDING));
        });
    }

    /** @return array{user_id:?int,name:?string,source:string} */
    public function effectiveOwner(LogisticsOrder $order): array
    {
        $assignedOwnerId = (int) ($order->assigned_owner_user_id ?? 0);
        if ($assignedOwnerId > 0) {
            return [
                'user_id' => $assignedOwnerId,
                'name' => trim((string) $order->assigned_owner_name) ?: null,
                'source' => 'assigned_owner',
            ];
        }

        $usageName = trim((string) $order->store?->user_name);
        if ($usageName === '') {
            return ['user_id' => null, 'name' => null, 'source' => 'unmatched'];
        }

        $identity = $this->normalizeIdentity($usageName);
        if (!array_key_exists($identity, $this->userIdentityCache)) {
            $this->userIdentityCache[$identity] = AdminUser::query()
                ->where(function (Builder $user) use ($identity): void {
                    $user->whereRaw('LOWER(REPLACE(REPLACE(TRIM(name), ?, ?), ?, ?)) = ?', [' ', '', '　', '', $identity])
                        ->orWhereRaw('LOWER(REPLACE(REPLACE(TRIM(username), ?, ?), ?, ?)) = ?', [' ', '', '　', '', $identity]);
                })
                ->first();
        }
        $matchedUser = $this->userIdentityCache[$identity];

        return [
            'user_id' => $matchedUser?->getKey() === null ? null : (int) $matchedUser->getKey(),
            'name' => $matchedUser?->name ?: $usageName,
            'source' => 'store_user',
        ];
    }

    public function effectiveTeam(LogisticsOrder $order): string
    {
        return AssistantDomainStore::displayMultiValue($order->store?->team)
            ?: trim((string) $order->group_names)
            ?: trim((string) $order->department_name);
    }

    /** @return list<array{product_name:string,shopify_sku:string,tongtu_sku:string,product_code:string,types:list<string>}> */
    public function details(LogisticsOrder $order): array
    {
        $keys = $this->nonRunnableKeys();
        $skus = array_flip($keys['skus']);
        $codes = array_flip($keys['product_codes']);
        return $order->items->map(function (LogisticsOrderItem $item) use ($skus, $codes): ?array {
            $nonRunnable = isset($skus[Str::lower(trim((string) $item->shopify_sku))])
                || isset($skus[Str::lower(trim((string) $item->tongtu_sku))])
                || isset($codes[Str::lower(trim((string) $item->tongtu_product_code))]);
            $missingSku = $this->isMissingSkuItem($item);
            if (! $nonRunnable && ! $missingSku) return null;

            return [
                'product_name' => trim((string) ($item->tongtu_product_name ?: $item->product_title)) ?: '未填写',
                'shopify_sku' => trim((string) $item->shopify_sku) ?: '未填写',
                'tongtu_sku' => trim((string) $item->tongtu_sku) ?: '未匹配',
                'product_code' => trim((string) $item->tongtu_product_code) ?: '未匹配',
                'types' => array_values(array_filter([
                    $nonRunnable ? 'non_runnable' : null,
                    $missingSku ? 'missing_sku' : null,
                ])),
            ];
        })->filter()->values()->all();
    }

    public function messageContent(LogisticsOrder $order, array $details): string
    {
        $lines = [
            '订单中包含违规产品或原始 SKU 为空的商品，请及时处理。',
            '系统订单 ID：'.$order->id,
            'Shopify 订单号：'.($order->shopify_order_number ?: '未填写'),
            '店铺：'.($order->store_name ?: '未填写'),
            '店铺域名：'.($order->store?->domain ?: $order->source_shop_domain ?: '未填写'),
            '订单类型：'.$order->subscription_type,
            '', '不可跑商品：',
        ];
        foreach ($details as $index => $detail) {
            $typeLabels = collect($detail['types'] ?? [])->map(fn (string $type) => $type === 'missing_sku' ? 'SKU为空' : '违规产品')->implode('、');
            $lines[] = ($index + 1).'. '.$detail['product_name'].'（'.$typeLabels.'）';
            $lines[] = '   原始 SKU：'.$detail['shopify_sku'].'；通途 SKU：'.$detail['tongtu_sku'].'；PCL：'.$detail['product_code'];
        }
        return implode("\n", $lines);
    }

    /** @param array{skus:list<string>,product_codes:list<string>} $keys */
    private function scopeNonRunnableItems(Builder $items, array $keys): Builder
    {
        return $items->where(function (Builder $match) use ($keys): void {
            if ($keys['skus'] !== []) {
                $match->whereIn(\DB::raw('LOWER(TRIM(shopify_sku))'), $keys['skus'])
                    ->orWhereIn(\DB::raw('LOWER(TRIM(tongtu_sku))'), $keys['skus']);
            }
            if ($keys['product_codes'] !== []) {
                $method = $keys['skus'] === [] ? 'whereIn' : 'orWhereIn';
                $match->{$method}(\DB::raw('LOWER(TRIM(tongtu_product_code))'), $keys['product_codes']);
            }
        });
    }

    private function scopeMissingSkuItems(Builder $items): Builder
    {
        return $items->where(function (Builder $missing): void {
            $missing->whereNull('shopify_sku')->orWhereRaw("TRIM(shopify_sku) = ''");
        })->whereRaw("LOWER(TRIM(COALESCE(product_title, ''))) <> 'tipping'")
            ->whereRaw("LOWER(TRIM(COALESCE(product_title, ''))) NOT LIKE '%shipping protection%'")
            ->whereRaw("LOWER(TRIM(COALESCE(product_title, ''))) NOT LIKE '%shipping insurance%'")
            ->whereRaw("LOWER(TRIM(COALESCE(product_title, ''))) NOT LIKE '%freight insurance%'")
            ->whereRaw("TRIM(COALESCE(tongtu_product_name, '')) NOT LIKE '%运费险%'");
    }

    private function isMissingSkuItem(LogisticsOrderItem $item): bool
    {
        if (trim((string) $item->shopify_sku) !== '') return false;
        $productTitle = Str::lower(trim((string) $item->product_title));
        $tongtuName = trim((string) $item->tongtu_product_name);

        return $productTitle !== 'tipping'
            && ! Str::contains($productTitle, ['shipping protection', 'shipping insurance', 'freight insurance'])
            && ! Str::contains($tongtuName, '运费险');
    }

    private function normalizeIdentity(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[\s　]+/u', '', trim($value)));
    }
}
