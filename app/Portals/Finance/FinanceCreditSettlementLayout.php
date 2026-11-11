<?php

namespace App\Portals\Finance;

use App\Models\FinancePaymentAccount;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class FinanceCreditSettlementLayout
{
    public const REPORT_CHANNELS = ['ye-settlement' => 'ye', 'worldpay-settlement' => 'worldpay'];

    public const CHANNELS = ['ye' => 'ConfluxAPI', 'worldpay' => 'Worldpay'];

    public const SHEETS = ['ye' => '叶总订单明细', 'worldpay' => 'worldpay单包'];

    private const YE = [
        'website_name' => '网站名称', 'initiated_at' => '发起时间', 'completed_at' => '完成时间',
        'reference' => '流水订单号', 'merchant_order' => '商户订单号', 'three_ds' => '3DS', 'card_type' => '卡种',
        'payer_account' => '付款人账号', 'email' => '邮箱', 'cardholder_phone' => '持卡人电话',
        'recipient_phone' => '收货人电话', 'country' => '国家', 'currency' => '币种', 'gross' => '金额',
        'status' => '交易状态', 'failure_reason' => '失败原因', 'three_ds_status' => '3DS状态',
        'refund_status' => '退款状态', 'order_refund' => '退款金额', 'refund_count' => '退款次数',
        'source_page' => '来源页面', 'month' => '年月', 'calendar_date' => '日期', 'settlement_currency' => '结算币种',
        'source_settlement_usd' => '结算-美元', 'settlement' => '结算金额', 'hold' => '保证金', 'fee' => '交易手续费',
        'per_order_fee' => '单笔费用', 'refund' => '退款金额', 'refund_fee' => '退款费用', 'chargeback' => '拒付金额',
        'chargeback_fee' => '拒付费用', 'ethoca_refund' => 'ethoca-预警退款', 'ethoca_fee' => '拒付预警ethoca',
        'rdr_refund' => '拒付RDR金额', 'rdr_fee' => '拒付RDR费用', 'release' => '保证金返还', 'payable' => '应结算',
        'logistics' => '物流-元', 'nbc_fee' => 'NBC费用', 'store_fee' => 'Shopline+店匠费用',
        'domain' => '域名', 'owner' => '使用人', 'team' => '团队', 'store_platform' => '索引/NBC/shopline/超店/店匠',
        'project' => 'PH/DB/DB-BH/FB', 'note' => '备注',
    ];

    private const WORLDPAY = [
        'gateway' => '网关接入号', 'reference' => '流水订单号', 'merchant_order' => '商户订单号', 'currency' => '币种',
        'gross' => '交易金额', 'statement_currency' => '结单币种', 'statement_amount' => '结单金额',
        'statement_rate' => '结单汇率', 'date' => '交易时间', 'status' => '交易状态', 'payment_method' => '支付方式',
        'hpp_method' => 'HPP支付方式', 'card_type' => '卡种', 'transaction_url' => '交易网址', 'card_number' => '卡号',
        'email' => '邮箱', 'recipient_country' => '收货人国家', 'month' => '年月', 'calendar_date' => '日期',
        'settlement' => '结算金额', 'hold' => '保证金', 'fee' => '交易手续费', 'per_order_fee' => '单笔费用',
        'refund' => '退款金额', 'refund_fee' => '退款费用', 'chargeback' => '拒付金额', 'chargeback_fee' => '拒付费用',
        'rdr_refund' => '拒付预警RDR金额', 'rdr_fee' => '拒付预警RDR+CDRN服务费用',
        'ethoca_fee' => '拒付预警ETHOCA服务费用', 'pre_arbitration' => '预仲裁金额', 'pre_arbitration_fee' => '预仲裁费用',
        'release' => '保证金返还', 'rdr_appeal' => 'RDR申诉成功', 'appeal' => '申诉成功', 'payable' => '应结算',
        'logistics' => '物流-元', 'domain' => '域名', 'owner' => "使用人\n匹配", 'team' => '团队',
        'store_platform' => '索引/NBC', 'product_name' => '商品名称-简称',
    ];

    public static function columns(?string $channel): array
    {
        return match ($channel) {
            'ye' => self::YE,
            'worldpay' => self::WORLDPAY,
            default => [],
        };
    }

    public static function displayColumns(?string $channel): array
    {
        $columns = self::withDisplayLabels(self::columns($channel));
        if ($channel === 'ye') {
            $columns['website_name'] = '网站名称 / 账单描述符';
            $columns['order_refund'] = '订单退款金额';
            $columns['refund'] = '账单退款金额';
            $afterTeam = array_search('team', array_keys($columns), true) + 1;
            $columns = array_slice($columns, 0, $afterTeam, true)
                + ['operation_region' => '地区'] + array_slice($columns, $afterTeam, null, true);
        }

        return $columns;
    }

    public static function withDisplayLabels(array $columns): array
    {
        return array_replace($columns, array_intersect_key([
            'reference' => '交易 ID',
            'currency' => '货币',
            'gross' => '总额',
            'status' => '状态',
            'fee' => FinanceSettlementService::MONEY['fee'],
            'owner' => '使用人',
        ], $columns));
    }

    public static function filter(Builder $query, string $channel): void
    {
        $accounts = FinancePaymentAccount::query()->where('account_type', 'credit_card')
            ->get()
            ->filter(fn (FinancePaymentAccount $account) => self::accountPlatform($account) === $channel)
            ->pluck('activity_account');
        [$sourceField, $otherField] = $channel === 'ye' ? ['网站名称', '网关接入号'] : ['网关接入号', '网站名称'];

        $query->where(function (Builder $query) use ($channel, $accounts, $sourceField, $otherField): void {
            $query->where('payload->_card_channel', $channel)
                ->orWhere(fn (Builder $query) => $query->whereNull('payload->_card_channel')
                    ->where(fn (Builder $query) => $query->where('payload->'.$sourceField, '!=', '')
                        ->orWhere(fn (Builder $query) => $query->whereIn('account', $accounts)
                            ->where(fn (Builder $query) => $query->whereNull('payload->'.$otherField)->orWhere('payload->'.$otherField, '')))));
        });
    }

    public static function accountPlatform(FinancePaymentAccount $account): ?string
    {
        if ($account->card_platform !== null) {
            return $account->card_platform;
        }

        // Older accounts may predate explicit platform configuration.
        $name = mb_strtolower($account->account_name.' '.$account->activity_account);

        return match (true) {
            str_contains($name, 'worldpay') => 'worldpay',
            str_contains($name, 'confluxapi'), str_contains($name, '叶总') => 'ye',
            default => null,
        };
    }

    public static function importPayload(string $channel, array $values): array
    {
        $payload = ['_card_channel' => $channel];
        foreach (self::columns($channel) as $field => $label) {
            $value = $values[$field];
            if (in_array($field, ['completed_at', 'calendar_date'], true) && is_numeric($value)) {
                $value = Date::excelToDateTimeObject((float) $value)
                    ->format($field === 'calendar_date' ? 'Y-m-d' : 'Y-m-d H:i:s');
            }
            $payload[$field === 'order_refund' ? '订单退款金额' : $label] = $value;
        }
        $domain = $values['domain'];
        if ($channel === 'ye' && $domain === '' && filled($values['source_page'])) {
            $source = $values['source_page'];
            $domain = parse_url(str_contains($source, '://') ? $source : 'https://'.$source, PHP_URL_HOST) ?: '';
        }

        return [...$payload, '平台订单号' => $values['reference'], '创建时间' => $values['initiated_at'] ?? $values['date'],
            '交易币种' => $values['currency'], '交易金额' => $values['gross'], '支付状态' => $values['status'],
            '域名' => $domain, '使用人' => $values['owner'],
            '拒付RDR金额' => $values['rdr_refund'], '拒付RDR+CDRN费用' => $values['rdr_fee'],
            '拒付预警ethoca' => $values['ethoca_fee'], 'ethoca预警退款' => $values['ethoca_refund'] ?? ''];
    }

    public static function values(string $channel, array $row, array $payload, array $saved): array
    {
        $aliases = [
            'website_name' => $payload['商户名称'] ?? $payload['店铺名称'] ?? null,
            'initiated_at' => $row['date'], 'completed_at' => $payload['处理时间'] ?? null,
            'three_ds' => $payload['是否3D'] ?? null,
            'email' => $payload['消费者邮箱'] ?? null,
            'country' => $payload['发卡国家'] ?? $payload['发行国'] ?? null,
            'failure_reason' => $payload['拒绝原因'] ?? null,
            'source_page' => $payload['Source（域名）'] ?? null,
            'calendar_date' => substr($row['date'], 0, 10),
            'source_settlement_usd' => $row['currency'] === 'USD' ? $row['gross'] : null,
            'settlement' => null,
        ];
        $values = [];
        foreach (self::columns($channel) as $field => $label) {
            $fallback = array_key_exists($field, $aliases) ? $aliases[$field] : ($row[$field] ?? null);
            $raw = match ($field) {
                // The flat payload's refund amount is the settlement value.
                'order_refund' => $payload['订单退款金额'] ?? null,
                'owner' => $payload[$label] ?? $payload['使用人匹配'] ?? null,
                default => $payload[$label] ?? null,
            };
            if (isset($payload['_card_channel'])) {
                $raw = match ($field) {
                    'reference', 'currency', 'gross' => $row[$field],
                    'status' => $payload['支付状态'],
                    default => $raw,
                };
            }
            if ($field === 'date' || $field === 'initiated_at') {
                $raw = $row['date'];
            }
            if ($field === 'month' || in_array($field, ['domain', 'team'], true)) {
                $raw = $row[$field];
            }
            $values[$field] = array_key_exists($field, $saved)
                ? $saved[$field]
                : (filled($raw) ? $raw : $fallback);
        }

        return $values;
    }
}
