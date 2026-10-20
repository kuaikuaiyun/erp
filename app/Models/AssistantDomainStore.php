<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AssistantDomainStore extends Model
{
    public const DOMAIN_CHECK_NOT_CHECKED = 'not_checked';

    public const DOMAIN_CHECK_NORMAL = 'normal';

    public const DOMAIN_CHECK_ABNORMAL = 'abnormal';

    public const DOMAIN_CHECK_REVIEW = 'review';

    public const DOMAIN_CHECK_FAILED = 'failed';

    public const SHOP_STATUS_IN_USE = 'in_use';

    public const SHOP_STATUS_ABANDONED = 'abandoned';

    public const SHOP_STATUS_STOPPED = 'stopped';

    private const SITE_BUILDER_OPTIONS = [
        'xshopy' => 'xshopy',
        'nbc' => 'nbc',
        'shopyy' => 'shopyy',
        'shopline' => 'shopline',
        '超店' => 'super_store',
        '店匠' => 'shoplazza',
        'shopify' => 'shopify',
    ];

    private const ERP_OPTIONS = [
        '马帮' => 'mabang',
        '通途' => 'tongtu',
        '独立站ERP' => 'independent',
    ];

    private const CREDIT_CARD_OPTIONS = [
        'F支付' => 'f_payment',
        '叶总厦门单包' => 'ye_xiamen_single',
        '叶总深圳单包' => 'ye_shenzhen_single',
        '叶总郑州单包' => 'ye_zhengzhou_single',
        '叶总西安单包' => 'ye_xian_single',
        '叶总pin单包' => 'ye_pin_single',
        '叶总郑州普货' => 'ye_zhengzhou_general',
        '叶总深圳极客' => 'ye_shenzhen_geek',
        '徐州信用卡' => 'xuzhou_card',
        '酷迈普货' => 'kumai_general',
        '酷迈百货' => 'kumai_department',
        '酷迈英国服装' => 'kumai_uk_clothing',
    ];

    private const WALLET_OPTIONS = [
        'F支付' => 'f_payment',
        '叶总厦门单包' => 'ye_xiamen_single',
        '叶总深圳单包' => 'ye_shenzhen_single',
        '叶总郑州单包' => 'ye_zhengzhou_single',
        '叶总西安单包' => 'ye_xian_single',
        '叶总郑州普货' => 'ye_zhengzhou_general',
        '叶总深圳极客' => 'ye_shenzhen_geek',
    ];

    private const PAYPAL_OPTIONS = [
        '1-尚雲科技' => 'shangyun',
        '2-立鉑' => 'libo',
        '3-嘉匯環球貿易' => 'jiahui',
        '4-啟盛亞洲商貿' => 'qisheng',
        '5-晟達恆通商貿' => 'shengda',
    ];

    private const OTHER_PAYMENT_OPTIONS = [
        'payments' => 'payments',
    ];

    protected $fillable = [
        'owner_department_id',
        'owner_user_id',
        'site_builder',
        'shop_name',
        'domain',
        'domain_expires_at',
        'domain_check_status',
        'domain_check_root',
        'domain_checked_at',
        'domain_check_http_status',
        'domain_check_message',
        'email',
        'email_bound',
        'email_purchased',
        'team',
        'project',
        'user_name',
        'erp',
        'credit_card',
        'wallet',
        'paypal',
        'other_payment',
        'shop_login_url',
        'store_prefix',
        'tongtu_prefix',
        'shopify_client_id',
        'shopify_client_secret',
        'shop_status',
        'plan_expires_at',
        'payment_card',
        'remark',
        'last_operator_id',
        'last_operator_name',
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
            'domain_expires_at' => 'date',
            'plan_expires_at' => 'date',
            'domain_checked_at' => 'datetime',
            'domain_check_http_status' => 'integer',
            'email_bound' => 'boolean',
            'email_purchased' => 'boolean',
            'team' => 'array',
            'erp' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function changeLogs(): HasMany
    {
        return $this->hasMany(AssistantDomainStoreChangeLog::class, 'domain_store_id');
    }

    public function authorizedDepartments(): BelongsToMany
    {
        return $this->belongsToMany(
            Department::class,
            'assistant_domain_store_department_accesses',
            'domain_store_id',
            'department_id',
        )->withTimestamps();
    }

    public function ownerDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'owner_department_id');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'owner_user_id');
    }

    public function shopifyConnection(): HasOne
    {
        return $this->hasOne(LogisticsShopifyConnection::class, 'assistant_domain_store_id');
    }

    public function shopifyAnalyticsMetric(): HasOne
    {
        return $this->hasOne(AssistantShopifyStoreMetric::class, 'assistant_domain_store_id');
    }

    public function shopifyAnalyticsOrders(): HasMany
    {
        return $this->hasMany(AssistantShopifyStoreOrder::class, 'assistant_domain_store_id');
    }

    public function shopifyAnalyticsHistory(): HasMany
    {
        return $this->hasMany(AssistantShopifyStoreMetricHistory::class, 'assistant_domain_store_id');
    }

    public function shopifyAnalyticsHours(): HasMany
    {
        return $this->hasMany(AssistantShopifyStoreMetricHour::class, 'assistant_domain_store_id');
    }

    public function shopifyAnalyticsPeriods(): HasMany
    {
        return $this->hasMany(AssistantShopifyStoreMetricPeriod::class, 'assistant_domain_store_id');
    }

    /** @return array<string, string> */
    public static function siteBuilderOptions(): array
    {
        return self::translatedOptions('site_builder', self::SITE_BUILDER_OPTIONS);
    }

    /** @return array<string, string> */
    public static function erpOptions(): array
    {
        return self::translatedOptions('erp', self::ERP_OPTIONS);
    }

    /**
     * Normalize a tag-style field to a unique, ordered list of non-empty values.
     *
     * @return list<string>
     */
    public static function normalizeMultiValue(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $values = is_array($decoded)
                ? $decoded
                : (preg_split('/[、,，;；\r\n]+/u', $value) ?: []);
        } else {
            $values = is_array($value) ? $value : [$value];
        }

        $normalized = [];

        foreach ($values as $item) {
            if (!is_scalar($item) && $item !== null) {
                continue;
            }

            $item = trim((string) $item);

            if ($item !== '' && !in_array($item, $normalized, true)) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    public static function displayMultiValue(mixed $value): string
    {
        return implode(',', self::normalizeMultiValue($value));
    }

    /** @return array<string, string> */
    public static function creditCardOptions(): array
    {
        return self::translatedOptions('credit_card', self::CREDIT_CARD_OPTIONS);
    }

    /** @return array<string, string> */
    public static function walletOptions(): array
    {
        return self::translatedOptions('wallet', self::WALLET_OPTIONS);
    }

    /** @return array<string, string> */
    public static function paypalOptions(): array
    {
        return self::translatedOptions('paypal', self::PAYPAL_OPTIONS);
    }

    /** @return array<string, string> */
    public static function otherPaymentOptions(): array
    {
        return self::translatedOptions('other_payment', self::OTHER_PAYMENT_OPTIONS);
    }

    /** @return array<string, string> */
    public static function domainCheckLabels(): array
    {
        return [
            self::DOMAIN_CHECK_NOT_CHECKED => __('assistant.domain_stores.options.domain_check.not_checked'),
            self::DOMAIN_CHECK_NORMAL => __('assistant.domain_stores.options.domain_check.normal'),
            self::DOMAIN_CHECK_ABNORMAL => __('assistant.domain_stores.options.domain_check.abnormal'),
            self::DOMAIN_CHECK_REVIEW => __('assistant.domain_stores.options.domain_check.review'),
            self::DOMAIN_CHECK_FAILED => __('assistant.domain_stores.options.domain_check.failed'),
        ];
    }

    public static function domainCheckStatusClass(?string $status): string
    {
        return match ($status) {
            self::DOMAIN_CHECK_NORMAL => 'label-success',
            self::DOMAIN_CHECK_REVIEW => 'label-warning',
            self::DOMAIN_CHECK_ABNORMAL, self::DOMAIN_CHECK_FAILED => 'label-danger',
            default => 'label-default',
        };
    }

    public static function domainCheckMessageLabel(?string $message): string
    {
        if ($message === null || $message === '') {
            return '';
        }

        $key = 'assistant.domain_stores.domain_check.messages.'.$message;
        $label = __($key);

        return $label === $key ? $message : $label;
    }

    /** @return array<string, string> */
    public static function shopStatusLabels(): array
    {
        return [
            self::SHOP_STATUS_IN_USE => __('assistant.domain_stores.options.shop_status.in_use'),
            self::SHOP_STATUS_ABANDONED => __('assistant.domain_stores.options.shop_status.abandoned'),
            self::SHOP_STATUS_STOPPED => __('assistant.domain_stores.options.shop_status.stopped'),
        ];
    }

    /** @return array<string, string> */
    public static function historyFieldLabels(): array
    {
        return [
            'site_builder' => self::fieldLabel('site_builder'),
            'shop_name' => self::fieldLabel('shop_name'),
            'domain' => self::fieldLabel('domain'),
            'domain_expires_at' => self::fieldLabel('domain_expires_at'),
            'domain_check_status' => self::fieldLabel('domain_check_status'),
            'domain_check_root' => self::fieldLabel('domain_check_root'),
            'domain_checked_at' => self::fieldLabel('domain_checked_at'),
            'domain_check_http_status' => self::fieldLabel('domain_check_http_status'),
            'domain_check_message' => self::fieldLabel('domain_check_message'),
            'email' => self::fieldLabel('email'),
            'email_bound' => self::fieldLabel('email_bound'),
            'email_purchased' => self::fieldLabel('email_purchased'),
            'team' => self::fieldLabel('team'),
            'project' => self::fieldLabel('project'),
            'user_name' => self::fieldLabel('user_name'),
            'erp' => self::fieldLabel('erp'),
            'credit_card' => self::fieldLabel('credit_card'),
            'wallet' => self::fieldLabel('wallet'),
            'paypal' => self::fieldLabel('paypal'),
            'other_payment' => self::fieldLabel('other_payment'),
            'shop_login_url' => self::fieldLabel('shop_login_url'),
            'store_prefix' => self::fieldLabel('store_prefix'),
            'tongtu_prefix' => self::fieldLabel('tongtu_prefix'),
            'shopify_client_id' => self::fieldLabel('shopify_client_id'),
            'shopify_client_secret' => self::fieldLabel('shopify_client_secret'),
            'shop_status' => self::fieldLabel('shop_status'),
            'plan_expires_at' => self::fieldLabel('plan_expires_at'),
            'payment_card' => self::fieldLabel('payment_card'),
            'remark' => self::fieldLabel('remark'),
            'transferred_at' => self::fieldLabel('transferred_at'),
            'transfer_note' => self::fieldLabel('transfer_note'),
            'payment_type' => self::fieldLabel('payment_type'),
            'payment_note' => self::fieldLabel('payment_note'),
            'authorized_departments' => self::fieldLabel('authorized_departments'),
            'owner_department' => self::fieldLabel('owner_department'),
            'owner_user' => self::fieldLabel('owner_user'),
        ];
    }

    public static function fieldLabel(string $field): string
    {
        $key = 'assistant.domain_stores.fields.'.$field;
        $label = __($key);

        return $label === $key ? $field : $label;
    }

    public function makeTongtuOrderNumber(?string $shopifyOrderNumber): ?string
    {
        $prefix = rtrim(trim((string) $this->tongtu_prefix), '-');
        $orderNumber = ltrim(trim((string) $shopifyOrderNumber), '#');

        if ($prefix === '' || $orderNumber === '') {
            return null;
        }

        return $prefix.'-'.$orderNumber;
    }

    /** @return array<string, mixed> */
    public function historySnapshot(): array
    {
        return [
            'site_builder' => $this->site_builder,
            'shop_name' => $this->shop_name,
            'domain' => $this->domain,
            'domain_expires_at' => $this->dateValue('domain_expires_at'),
            'domain_check_status' => $this->domain_check_status,
            'domain_check_root' => $this->domain_check_root,
            'domain_checked_at' => $this->dateTimeValue('domain_checked_at'),
            'domain_check_http_status' => $this->domain_check_http_status,
            'domain_check_message' => $this->domain_check_message,
            'email' => $this->email,
            'email_bound' => $this->email_bound ? '1' : '0',
            'email_purchased' => $this->email_purchased ? '1' : '0',
            'team' => $this->team,
            'project' => $this->project,
            'user_name' => $this->user_name,
            'erp' => $this->erp,
            'credit_card' => $this->credit_card,
            'wallet' => $this->wallet,
            'paypal' => $this->paypal,
            'other_payment' => $this->other_payment,
            'shop_login_url' => $this->shop_login_url,
            'store_prefix' => $this->store_prefix,
            'tongtu_prefix' => $this->tongtu_prefix,
            'shopify_client_id' => $this->shopify_client_id,
            'shopify_client_secret' => $this->shopify_client_secret,
            'shop_status' => $this->shop_status,
            'plan_expires_at' => $this->dateValue('plan_expires_at'),
            'payment_card' => $this->payment_card,
            'remark' => $this->remark,
            'authorized_departments' => $this->authorizedDepartments
                ->sortBy('name')
                ->pluck('name')
                ->values()
                ->all(),
            'owner_department' => $this->ownerDepartment?->name,
            'owner_user' => $this->ownerUser?->name ?: $this->ownerUser?->username,
        ];
    }

    public static function displayHistoryValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '' || (is_array($value) && $value === [])) {
            return __('assistant.domain_stores.history.not_filled');
        }

        return match ($field) {
            'team', 'erp', 'authorized_departments' => self::displayMultiValue($value),
            'site_builder' => self::siteBuilderOptions()[(string) $value] ?? (string) $value,
            'credit_card' => self::creditCardOptions()[(string) $value] ?? (string) $value,
            'wallet' => self::walletOptions()[(string) $value] ?? (string) $value,
            'paypal' => self::paypalOptions()[(string) $value] ?? (string) $value,
            'other_payment' => self::otherPaymentOptions()[(string) $value] ?? (string) $value,
            'domain_check_status' => match ((string) $value) {
                'yes', 'blocked', self::DOMAIN_CHECK_ABNORMAL => self::domainCheckLabels()[self::DOMAIN_CHECK_ABNORMAL],
                'no', self::DOMAIN_CHECK_NORMAL => self::domainCheckLabels()[self::DOMAIN_CHECK_NORMAL],
                self::DOMAIN_CHECK_NOT_CHECKED => self::domainCheckLabels()[self::DOMAIN_CHECK_NOT_CHECKED],
                self::DOMAIN_CHECK_REVIEW => self::domainCheckLabels()[self::DOMAIN_CHECK_REVIEW],
                self::DOMAIN_CHECK_FAILED => self::domainCheckLabels()[self::DOMAIN_CHECK_FAILED],
                default => (string) $value,
            },
            'domain_check_message' => self::domainCheckMessageLabel((string) $value),
            'domain_check_http_status' => __('assistant.domain_stores.domain_check.http_status', ['status' => $value]),
            'shop_status' => match ((string) $value) {
                self::SHOP_STATUS_IN_USE, 'enabled' => self::shopStatusLabels()[self::SHOP_STATUS_IN_USE],
                self::SHOP_STATUS_ABANDONED, 'closed', 'idle' => self::shopStatusLabels()[self::SHOP_STATUS_ABANDONED],
                self::SHOP_STATUS_STOPPED, 'banned' => self::shopStatusLabels()[self::SHOP_STATUS_STOPPED],
                default => (string) $value,
            },
            'payment_type' => [
                'credit_card' => self::fieldLabel('credit_card'),
                'wallet' => self::fieldLabel('wallet'),
                'paypal' => self::fieldLabel('paypal'),
                'other' => self::fieldLabel('other_payment'),
            ][(string) $value] ?? (string) $value,
            'email_bound', 'email_purchased' => (string) $value === '1'
                ? __('assistant.domain_stores.common.yes')
                : __('assistant.domain_stores.common.no'),
            default => (string) $value,
        };
    }

    /** @param array<string, string> $options */
    private static function translatedOptions(string $group, array $options): array
    {
        $translated = [];

        foreach ($options as $value => $translationKey) {
            $translated[$value] = __('assistant.domain_stores.options.'.$group.'.'.$translationKey);
        }

        return $translated;
    }

    private function dateValue(string $field): ?string
    {
        $value = $this->getAttribute($field);

        return $value ? $value->format('Y-m-d') : null;
    }

    private function dateTimeValue(string $field): ?string
    {
        $value = $this->getAttribute($field);

        return $value ? $value->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') : null;
    }
}
