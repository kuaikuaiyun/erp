<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable-source Shopify order fields used by Assistant analytics.
 *
 * The amounts in this table are persisted in Shopify's shop currency. Any
 * reference USD value is calculated only when the analytics page is read.
 */
class AssistantShopifyStoreOrder extends Model
{
    public const SUBSCRIPTION_CATEGORY_REGULAR = 'regular';

    public const SUBSCRIPTION_CATEGORY_SUBSCRIBED = 'subscribed';

    public const SUBSCRIPTION_CATEGORY_RECURRING = 'recurring';

    protected $fillable = [
        'assistant_domain_store_id',
        'shopify_order_id',
        'shopify_legacy_resource_id',
        'shopify_order_number',
        'shopify_created_at',
        'shopify_processed_at',
        'shopify_updated_at',
        'shopify_cancelled_at',
        'is_test',
        'shopify_tags',
        'subscription_category',
        'original_currency_code',
        'original_total_price',
        'current_currency_code',
        'current_total_price',
        'refunded_currency_code',
        'refunded_total_amount',
        'financial_status',
        'fulfillment_status',
        'synced_at',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'shopify_created_at' => 'datetime',
            'shopify_processed_at' => 'datetime',
            'shopify_updated_at' => 'datetime',
            'shopify_cancelled_at' => 'datetime',
            'is_test' => 'boolean',
            'shopify_tags' => 'array',
            'original_total_price' => 'decimal:4',
            'current_total_price' => 'decimal:4',
            'refunded_total_amount' => 'decimal:4',
            'synced_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public static function normalizeShopifyTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }

        $normalized = [];
        foreach ($tags as $tag) {
            if (!is_scalar($tag)) {
                continue;
            }

            $value = trim((string) $tag);
            if ($value === '') {
                continue;
            }

            $key = strtolower($value);
            $normalized[$key] ??= $value;
        }

        return array_values($normalized);
    }

    public static function subscriptionCategoryForTags(mixed $tags): string
    {
        $normalized = array_map(
            static fn (string $tag): string => strtolower(trim($tag)),
            self::normalizeShopifyTags($tags),
        );

        if (in_array('ouyicart subscription recurring order', $normalized, true)) {
            return self::SUBSCRIPTION_CATEGORY_RECURRING;
        }

        if (in_array('subscription first order', $normalized, true)
            || in_array('subscription', $normalized, true)) {
            return self::SUBSCRIPTION_CATEGORY_SUBSCRIBED;
        }

        return self::SUBSCRIPTION_CATEGORY_REGULAR;
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(AssistantDomainStore::class, 'assistant_domain_store_id');
    }
}
