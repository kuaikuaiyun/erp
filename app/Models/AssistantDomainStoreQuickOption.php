<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantDomainStoreQuickOption extends Model
{
    public const FIELD_SITE_BUILDER = 'site_builder';

    public const FIELD_CREDIT_CARD = 'credit_card';

    public const FIELD_WALLET = 'wallet';

    public const FIELD_PAYPAL = 'paypal';

    public const FIELD_OTHER_PAYMENT = 'other_payment';

    public const FIELD_TEAM = 'team';

    public const FIELD_PROJECT = 'project';

    protected $table = 'assistant_domain_store_quick_options';

    protected $fillable = [
        'field',
        'value',
    ];

    /** @return list<string> */
    public static function supportedFields(): array
    {
        return [
            self::FIELD_SITE_BUILDER,
            self::FIELD_CREDIT_CARD,
            self::FIELD_WALLET,
            self::FIELD_PAYPAL,
            self::FIELD_OTHER_PAYMENT,
            self::FIELD_TEAM,
            self::FIELD_PROJECT,
        ];
    }

    /** @return array<string, list<string>> */
    public static function valuesByField(): array
    {
        $values = array_fill_keys(self::supportedFields(), []);

        static::query()
            ->whereIn('field', self::supportedFields())
            ->orderBy('field')
            ->orderBy('value')
            ->get(['field', 'value'])
            ->each(function (self $option) use (&$values): void {
                $values[$option->field][] = $option->value;
            });

        return $values;
    }
}
