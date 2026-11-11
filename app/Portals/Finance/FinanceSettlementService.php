<?php

namespace App\Portals\Finance;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantDomainStoreOwnerHistory;
use App\Models\FinancePaymentAccount;
use App\Models\FinanceRecord;
use App\Models\LogisticsOrderPaymentReference;
use App\Portals\Assistant\AssistantStoreOwnerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class FinanceSettlementService
{
    public const PAYPAL_COST_HEADERS = ['通途订单', '物流-元', '采购成本-元'];

    public const OPERATION_DEPARTMENT = '运营所属部门';

    public const CARD_TRANSACTION_STATUSES = ['success' => '成功', 'failed' => '失败', 'pending' => '处理中'];
    public const CARD_FAILURE_STATUSES = ['D', 'FAIL', 'FAILED', 'FAILURE', 'DECLINED', 'ERROR', '已拒绝', '失败'];

    private const TRANSACTION_STATUS_CODES = [
        'success' => ['S', 'SUCCESS', 'COMPLETED', 'PAID', '已付款', '已完成', '成功'],
        'failed' => self::CARD_FAILURE_STATUSES,
        'pending' => ['P', 'PENDING', 'PROCESSING', '待处理', '处理中'],
    ];

    public const CONDITION_FIELDS = ['account' => '支付账户', 'reference' => '交易单号', 'team' => '团队', 'owner' => '使用人',
        'domain' => '域名', 'subscription' => '订阅类型', 'gross' => '原币总额', 'settlement' => '结算金额',
        'fee' => '手续费', 'refund' => '退款金额', 'note' => '备注'];
    public const MONEY = [
        'converted_usd' => '转换美元',
        'usd_rate' => '折算美元汇率', 'fee' => '手续费', 'hold' => '冻结金额', 'dispute_hold' => '争议冻结金额',
        ...FinancePayPalSettlementOrders::REFUND_SPLIT_COLUMNS,
        'refund' => '退款金额', 'refund_fee' => '退款费用', 'dispute_refund' => '争议退款', 'dispute_fee' => '争议费',
        'fee_return' => '返还手续费', 'dispute_release' => '返还争议冻结', 'release' => '返还冻结',
        'per_order_fee' => '单笔费用（含失败交易）', 'chargeback' => '拒付金额', 'chargeback_fee' => '拒付费用',
        'ethoca_refund' => 'ETHOCA预警退款', 'ethoca_fee' => 'ETHOCA费用', 'rdr_refund' => 'RDR退款', 'rdr_fee' => 'RDR费用',
        'logistics' => '物流费用（人民币）', 'purchase' => '采购成本（人民币）',
    ];
    public const TEXT = ['domain' => '域名', 'subscription' => '订阅类型', 'upsell' => '是否加购', 'erp_order' => '通途订单', 'note' => '备注'];
    public const EXPORT = ['id' => '记录ID', 'account' => '支付账户', 'reference' => '交易单号', 'date' => '交易时间', 'timezone' => '时区', 'month' => '年月', 'status' => '支付状态', 'currency' => '原币币种', 'gross' => '原币总额', 'settlement' => '结算金额（美元）', 'domain' => '域名', 'owner' => '使用人', 'team' => '团队', 'subscription' => '订阅类型', 'upsell' => '是否加购', 'erp_order' => '通途订单', 'note' => '备注'];

    public function __construct(private readonly FinanceWorkbookService $workbooks) {}

    public function textFields(string $channel): array
    {
        return $channel === 'paypal'
            ? [...self::TEXT, 'owner' => '使用人', 'team' => '团队', 'paypal_12' => '偏好设定 Txn ID']
            : [...self::TEXT, 'owner' => '使用人', 'team' => '团队'];
    }

    public function importFields(string $channel): array
    {
        if ($channel === 'paypal') {
            return ['reference' => '交易 ID', 'erp_order' => '通途订单', 'converted_usd' => '转换美元', 'usd_rate' => '汇率', 'hold' => '冻结金额', 'dispute_hold' => '争议冻结金额',
                ...FinancePayPalSettlementOrders::REFUND_SPLIT_COLUMNS,
                'refund' => '退款金额', 'refund_fee' => '退款费用', 'dispute_refund' => '争议退款', 'dispute_fee' => '争议费',
                'fee_return' => '返还手续费', 'dispute_release' => '返还争议冻结', 'release' => '返还冻结',
                'logistics' => '物流-元', 'purchase' => '采购成本-元',
                ...$this->textFields($channel)];
        }

        return ['merchant_order' => '商户订单号', 'reference' => '平台订单号', 'erp_order' => '通途订单',
            'settlement' => '结算金额', 'hold' => '保证金', 'fee' => '交易手续费', 'per_order_fee' => '单笔费用',
            'refund' => '退款金额', 'refund_fee' => '退款费用', 'chargeback' => '拒付金额', 'chargeback_fee' => '拒付费用',
            'ethoca_refund' => 'ethoca预警退款', 'ethoca_fee' => '拒付预警ethoca', 'rdr_refund' => '拒付RDR金额',
            'rdr_fee' => '拒付RDR+CDRN费用', 'release' => '保证金返还', 'logistics' => '物流-元', 'purchase' => '采购成本-元',
            'usd_rate' => '汇率', ...$this->textFields($channel)];
    }

    public function columns(string $channel): array
    {
        if ($channel !== 'paypal') {
            return [...self::EXPORT, 'source_settlement_usd' => '结算-美元', ...$this->moneyFields($channel), 'currency_warning' => '币种核对'];
        }
        $source = [];
        foreach (array_slice(FinanceCatalog::dataset('paypal-orders')['headers'], 0, 15) as $index => $header) {
            $source[$index === 11 ? 'reference' : 'paypal_'.$index] = FinanceCatalog::simplified($header);
        }

        return ['id' => '记录ID', 'account' => '支付账户', ...$source,
            'month' => '年月', 'date' => '时间点', 'timezone' => '导出时区', 'subscription' => '订阅类型', 'upsell' => '是否加购',
            'converted_usd' => '转换美元', 'usd_rate' => '汇率', 'settlement' => '结算金额-美元',
            ...array_diff_key($this->moneyFields('paypal'), array_flip(['converted_usd', 'usd_rate'])),
            'domain' => '域名', 'owner' => '使用人', 'team' => self::OPERATION_DEPARTMENT, 'operation_region' => '地区',
            'erp_order' => '通途订单', 'note' => '备注'];
    }

    public function exportColumns(string $channel): array
    {
        $columns = $this->listColumns($channel);
        if ($channel !== 'paypal') {
            return $columns;
        }

        return $columns + FinancePayPalSettlementOrders::sourceExportColumns();
    }

    public const SHOP_DIFFERENCE_LABEL = '与店铺差额（美元）';

    public const SHOP_DIFFERENCE_COLUMN = ['shop_difference_usd' => self::SHOP_DIFFERENCE_LABEL];

    public function listColumns(string $channel): array
    {
        $columns = $this->columns($channel) + self::SHOP_DIFFERENCE_COLUMN;

        return $channel === 'paypal' ? FinancePayPalSettlementOrders::listColumns($columns) : $columns;
    }

    public function conditionFields(string $channel): array
    {
        if ($channel !== 'paypal') {
            return self::CONDITION_FIELDS;
        }

        return $this->listColumns($channel) + ['gross' => self::CONDITION_FIELDS['gross']];
    }

    public function numericConditionFields(string $channel): array
    {
        return $channel === 'paypal'
            ? ['id', 'gross', 'paypal_7', 'paypal_8', 'paypal_9', 'settlement', 'payable', 'open_hold',
                ...array_keys($this->moneyFields($channel))]
            : ['gross', 'settlement', 'fee', 'refund'];
    }

    public function query(string $channel, array $filters)
    {
        $query = FinanceRecord::query()->whereIn('dataset', $channel === 'paypal' ? ['paypal-orders'] : ['xuzong2-orders', 'xuzong2-details', 'xuzong1-details']);
        $query->whereNotExists(function ($deleted): void {
            $deleted->selectRaw('1')->from('finance_settlement_supplements as removed_settlement')
                ->whereColumn('removed_settlement.finance_record_id', 'finance_records.id')
                ->whereNotNull('removed_settlement.payload->_deleted_at');
        });
        $this->filterAccountHistory($query, $channel);
        if ($channel === 'paypal') {
            $types = $this->workbooks->database()->table('finance_transaction_types')->where('category', '结账付款')->pluck('raw_type');
            $query->whereIn('raw_type', $types)->where('raw_type', 'not like', 'T04%')
                ->where('raw_type', 'not like', 'T02%');
        } elseif (($filters['source'] ?? '') !== '') {
            $query->where('dataset', $filters['source']);
        } else {
            // Imported current-card details take precedence over an order with the same account/reference.
            $query->where(function ($query): void {
                $query->where('dataset', '!=', 'xuzong2-orders')->orWhereNotExists(function ($sub): void {
                    $sub->selectRaw('1')->from('finance_records as detail')->where('detail.dataset', 'xuzong2-details')
                        ->whereColumn('detail.account', 'finance_records.account')->whereColumn('detail.reference', 'finance_records.reference')
                        ->where(function ($match): void {
                            $match->whereColumn('detail.payload->商户订单号', 'finance_records.payload->Shopify订单号')
                                ->orWhereColumn('detail.payload->商户订单号', 'finance_records.payload->商户订单号');
                        })
                        ->whereColumn('detail.occurred_at', 'finance_records.occurred_at')->whereColumn('detail.currency', 'finance_records.currency')
                        ->whereColumn('detail.status', 'finance_records.status');
                });
            });
        }
        if ($channel === 'credit_card' && filled($filters['card_channel'] ?? null)) {
            FinanceCreditSettlementLayout::filter($query, $filters['card_channel']);
        }
        if ($channel === 'credit_card' && filled($filters['card_group'] ?? null)) {
            $query->whereIn('account', FinancePaymentAccount::query()->where('account_type', 'credit_card')
                ->where('card_group_id', $filters['card_group'])->select('activity_account'));
        }
        $statusCodes = self::TRANSACTION_STATUS_CODES[$filters['card_transaction_status'] ?? ''] ?? null;
        if ($channel === 'credit_card' && $statusCodes !== null) {
            $query->whereIn($query->getQuery()->raw('UPPER(TRIM(finance_records.status))'), $statusCodes);
        }
        foreach (['account', 'reference'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }
        $zone = $filters['timezone'] ?? 'Asia/Shanghai';
        if (filled($filters['from'] ?? null)) {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($filters['from'], $zone)->utc());
        }
        if (filled($filters['to'] ?? null)) {
            $query->where('occurred_at', '<', CarbonImmutable::parse($filters['to'], $zone)->addDay()->utc());
        }

        return $query;
    }

    public function filterAccountHistory($query, string $channel): void
    {
        // Materialize the small account list instead of correlated account subqueries.
        $accounts = FinancePaymentAccount::withTrashed()->where('account_type', $channel)
            ->get(['id', 'activity_account', 'deleted_at']);
        $deleted = $accounts->whereNotNull('deleted_at');
        $liveNames = $accounts->whereNull('deleted_at')->pluck('activity_account');
        $deletedNames = $deleted->pluck('activity_account')->diff($liveNames)->unique()->values();
        if ($deleted->isNotEmpty()) {
            $db = $this->workbooks->database();
            $query->whereNotIn('finance_records.id', $db->table('finance_payment_transactions')
                ->select('finance_record_id')->whereIn('payment_account_id', $deleted->pluck('id')));
            if ($deletedNames->isNotEmpty()) {
                $query->where(function ($scope) use ($deletedNames, $db): void {
                    $scope->whereNotIn('finance_records.account', $deletedNames)
                        ->orWhereIn('finance_records.id', $db->table('finance_payment_transactions')
                            ->select('finance_record_id'));
                });
            }
        }
    }

    private function rowContext(string $channel): array
    {
        $accountModels = FinancePaymentAccount::query()->where('account_type', $channel)->get();
        $accounts = $accountModels->pluck('account_name', 'activity_account');
        $accountsById = $accountModels->keyBy('id');
        $domainGroups = $this->workbooks->domainGroups(withAssignedOwner: $channel === 'paypal');
        $storesById = $channel === 'paypal' ? $domainGroups->keyBy('id') : collect();
        $domains = $channel === 'paypal'
            ? $domainGroups->groupBy('domain')->filter(fn ($groups) => $groups->count() === 1)->map(fn ($groups) => $groups->first())
            : $domainGroups->keyBy('domain');
        $brushStores = $channel === 'paypal' ? $domainGroups
            ->filter(fn ($group) => filled($group->shop_name ?? null))
            ->groupBy(fn ($group) => trim($group->shop_name))
            ->filter(fn ($groups) => $groups->count() === 1 && str_contains((string) $groups->first()->owner, '刷单'))
            ->map(fn ($groups) => $groups->first()) : collect();
        $locks = $this->workbooks->database()->table('finance_period_locks')->whereNotNull('report_snapshot')->pluck('month')->all();

        return compact('accountModels', 'accounts', 'accountsById', 'storesById', 'domains', 'brushStores', 'locks');
    }

    public function rowBatches(string $channel, array $filters): iterable
    {
        $context = $this->rowContext($channel);
        $query = $this->query($channel, $filters)->orderByDesc('occurred_at')->orderByDesc('id');
        foreach ($query->lazy(500)->chunk(500) as $records) {
            yield $this->rows($channel, $filters, new \Illuminate\Database\Eloquent\Collection($records->all()), $context);
        }
    }

    public function rows(string $channel, array $filters, ?Collection $records = null, ?array $context = null): Collection
    {
        $db = $this->workbooks->database();
        $records ??= $this->query($channel, $filters)->orderByDesc('occurred_at')->orderByDesc('id')->get();
        $supplements = $db->table('finance_settlement_supplements')->whereIntegerInRaw('finance_record_id', $records->modelKeys())->get()->keyBy('finance_record_id');
        $owners = $db->table('finance_payment_transactions')->whereIntegerInRaw('finance_record_id', $records->modelKeys())
            ->pluck('payment_account_id', 'finance_record_id');
        ['accountModels' => $accountModels, 'accounts' => $accounts, 'accountsById' => $accountsById,
            'storesById' => $storesById, 'domains' => $domains, 'brushStores' => $brushStores, 'locks' => $locks] =
            $context ?? $this->rowContext($channel);
        $metadata = $this->logisticsMetadata($records, $channel, withCustomReference: ! empty($filters['order_view']),
            summaryOnly: ! empty($filters['summary_only']));
        $candidateOrders = $channel === 'paypal' && ! empty($filters['order_view'])
            ? app(FinancePayPalOrderCandidates::class)->suggestions($records, $metadata) : [];
        $departmentMembers = $channel === 'paypal' && ! empty($filters['order_view']) && $records->isNotEmpty()
            ? $this->operationDepartmentMembers() : collect();
        $membersById = $departmentMembers->keyBy('id');
        $membersByName = $departmentMembers->groupBy(fn ($member) => array_unique(array_filter([$member->name, $member->username])));
        $conflux = $channel === 'credit_card' && ($filters['card_channel'] ?? null) === 'ye'
            ? app(FinanceConfluxSettlement::class) : null;
        $ledger = $conflux?->entriesFor($records, $accountModels->keyBy('activity_account'));
        $confluxFees = $conflux && $ledger->isNotEmpty() ? $conflux->feesFor($ledger) : [];
        $usdRates = app(FinanceCreditUsdRates::class);
        $confluxOrders = $conflux ? $records : ($channel === 'credit_card' && empty($filters['card_channel']) && empty($filters['summary_only'])
            ? $records->filter(fn ($record) => ($record->payload['_card_channel'] ?? null) === 'ye') : collect());
        $confluxOrderIds = $confluxOrders->pluck('id')->flip();
        $creditUsdRates = $usdRates->forOrders($confluxOrders);
        return $records->map(function ($record) use ($channel, $filters, $supplements, $accounts, $owners, $accountsById, $domains, $storesById, $brushStores, $locks, $metadata, $candidateOrders, $membersById, $membersByName, $conflux, $ledger, $confluxFees, $usdRates, $confluxOrderIds, $creditUsdRates): array {
            $owner = $accountsById->get($owners->get($record->id));
            $p = $record->payload;
            $definition = FinanceCatalog::dataset($record->dataset);
            $headers = $definition['headers'];
            $value = static fn ($key) => $p[$headers[$definition[$key]]] ?? '';
            $reportTimezone = $p['_report_timezone'] ?? ($filters['timezone'] ?? 'Asia/Shanghai');
            $occurredAt = $record->occurred_at;
            $date = $occurredAt->setTimezone($reportTimezone);
            $beijingDate = $reportTimezone === 'Asia/Shanghai' ? $date : $occurredAt->setTimezone('Asia/Shanghai');
            $row = ['id' => $record->id, 'channel' => $channel, 'source' => $record->dataset,
                'account_key' => $owner?->activity_account ?? $record->account,
                'account' => $owner?->account_name ?? $accounts->get($record->account, $record->account),
                'reference' => $record->reference, 'date' => isset($p['_manual_import_month']) ? '' : $date->format('Y-m-d H:i:s'), 'timezone' => $reportTimezone,
                'month' => $p['_manual_import_month'] ?? $date->format('Y-m'), 'status' => $record->status, 'currency' => $record->currency,
                'payment_method' => $p['支付方式'] ?? '',
                'gross' => $this->number($channel === 'paypal' ? ($p[$headers[7]] ?? null) : ($p['交易金额'] ?? null)),
                'locked' => in_array($beijingDate->format('Y-m'), $locks, true),
                'domain' => $record->domain ?? '', 'subscription' => $p['订阅类型'] ?? '', 'upsell' => $p['是否加购'] ?? '',
                'erp_order' => $p['通途订单'] ?? '', 'note' => $p['备注'] ?? '',
            ];
            foreach ($metadata[$record->id] ?? [] as $field => $value) {
                if (in_array($field, ['order', 'payment_reference_type'], true)) {
                    continue;
                }
                if ($field === 'logistics_order_id' && empty($filters['logistics_costs'])) {
                    continue;
                }
                if (blank($row[$field] ?? null)) {
                    $row[$field] = $value;
                }
            }
            $paymentType = null;
            if ($channel === 'paypal') {
                $business = $metadata[$record->id] ?? [];
                $paymentType = $business['payment_reference_type'] ?? null;
                $orderUpsell = $business['upsell'] ?? '';
                $row['upsell'] = self::isUpsell($orderUpsell) ? '已加购' : ($orderUpsell ?: $row['upsell']);
                if ($paymentType === LogisticsOrderPaymentReference::TYPE_PRIMARY) {
                    $row['subscription'] = $business['subscription'];
                } elseif ($paymentType === LogisticsOrderPaymentReference::TYPE_UPSELL) {
                    $row['upsell'] = '已加购';
                } elseif (self::isUpsell($business['upsell'] ?? '')) {
                    // An order-level tag does not identify the payment's subscription type.
                    $row['subscription'] = $p['订阅类型'] ?? '';
                }
            }
            $aliases = ['usd_rate' => '汇率', 'fee' => $channel === 'paypal' ? '手续费' : '交易手续费', 'logistics' => '物流-元', 'purchase' => '采购成本-元',
                'per_order_fee' => '单笔费用', 'hold' => $channel === 'paypal' ? '冻结金额' : '保证金',
                'release' => $channel === 'paypal' ? '返还冻结' : '保证金返还', 'ethoca_refund' => 'ethoca预警退款',
                'ethoca_fee' => '拒付预警ethoca', 'rdr_refund' => '拒付RDR金额', 'rdr_fee' => '拒付RDR+CDRN费用'];
            foreach (self::MONEY as $field => $label) {
                $row[$field] = $this->number(filled($p[$aliases[$field] ?? $label] ?? null) ? $p[$aliases[$field] ?? $label] : ($p[$label] ?? null));
            }
            if ($channel === 'paypal' && $row['fee'] === null) {
                $fee = $this->number($p[$headers[8]] ?? null);
                $row['fee'] = $fee === null ? null : bcmul($fee, '-1', 8);
            }
            $saved = isset($supplements[$record->id]) ? json_decode($supplements[$record->id]->payload, true, 512, JSON_THROW_ON_ERROR) : [];
            $row = [...$row, ...$saved];
            if ($channel !== 'paypal') {
                $row['subscription'] = self::isUpsell($row['upsell']) ? '加购' : self::subscriptionLabel($row['subscription']);
            }
            $row['usd_rate'] = $record->currency === 'USD' ? '1' : $row['usd_rate'];
            if (isset($saved['_edited_fields']['usd_rate'])) {
                $row['usd_rate'] = $saved['_edited_fields']['usd_rate'];
            }
            if ($channel === 'paypal') {
                if (empty($filters['summary_only'])) {
                    foreach (array_slice($headers, 0, 15) as $index => $header) {
                        $row['paypal_'.$index] = $index === 12 && filled($saved['paypal_12'] ?? null)
                            ? $saved['paypal_12']
                            : ($index === 4 ? FinancePayPalEventTypes::label((string) ($p[$header] ?? '')) : ($p[$header] ?? ''));
                    }
                }
                $net = $this->number($p[$headers[9]] ?? null);
                if ($row['converted_usd'] === null && $net !== null && $row['usd_rate'] !== null) {
                    $row['converted_usd'] = bcmul($net, $row['usd_rate'], 8);
                }
                if ($row['converted_usd'] !== null && $net !== null && bccomp($net, '0', 8) !== 0 && $record->currency !== 'USD') {
                    $row['usd_rate'] = bcdiv($row['converted_usd'], $net, 8);
                }
            }
            $success = self::isSuccessfulTransaction($record->status);
            $failed = in_array(strtoupper((string) $record->status), self::TRANSACTION_STATUS_CODES['failed'], true);
            $row['success'] = $success;
            $row['failed'] = $failed;
            // The source-dollar column is distinct from the manually reconciled settlement.
            $row['source_settlement_usd'] = $channel === 'credit_card' && strtoupper(trim((string) $record->status)) === 'SUCCESS'
                ? $row['gross'] : null;
            $row['status'] = match (strtoupper((string) $record->status)) {
                'S', 'SUCCESS', 'COMPLETED' => '已完成', 'P', 'PENDING' => '待处理',
                'D', 'FAIL', 'FAILED', 'FAILURE', 'DECLINED' => '已失败', 'V', 'VOIDED' => '已撤销',
                default => FinanceCatalog::simplified((string) $record->status),
            };
            $row['settlement'] = $success && $row['gross'] !== null && $row['usd_rate'] !== null ? bcmul($row['gross'], $row['usd_rate'], 8) : null;
            $importedSettlement = $this->number($p[$channel === 'paypal' ? '结算金额-美元' : '结算金额'] ?? null);
            if ($success && $importedSettlement !== null) {
                $row['settlement'] = $importedSettlement;
            }
            $row['currency_warning'] = $channel === 'credit_card' && $record->currency !== 'USD'
                ? '交易币种为 '.$record->currency.'，请核对美元结算金额。' : '';
            if ($channel === 'credit_card' && array_key_exists('settlement', $saved)) {
                $row['settlement'] = $saved['settlement'];
            }
            if ($channel === 'paypal' && ! array_key_exists('fee', $saved) && $row['fee'] !== null) {
                $row['fee'] = $row['usd_rate'] === null ? null : bcmul($row['fee'], $row['usd_rate'], 8);
            }
            $order = $metadata[$record->id]['order'] ?? null;
            $domain = strtolower(trim((string) ($saved['_edited_fields']['domain'] ?? $row['domain'])));
            $orderStore = $storesById->get($order->assistant_domain_store_id ?? null);
            $group = $orderStore?->domain === $domain ? $orderStore : $domains->get($domain);
            $ownership = $this->resolveOrderOwner($order, $orderStore, $group, $p);
            $ownerName = $ownership['ownerName'];
            $team = $ownership['team'];
            $row['owner'] = filled($saved['owner'] ?? null) ? $saved['owner'] : $ownerName;
            $row['team'] = filled($saved['team'] ?? null) ? $saved['team'] : $team;
            $row = [...$row, ...($saved['_edited_fields'] ?? [])];
            if ($channel === 'paypal') {
                $manualUpsell = $saved['_edited_fields']['upsell'] ?? $saved['upsell'] ?? null;
                $isUpsellPayment = $manualUpsell !== null ? self::isUpsell($manualUpsell)
                    : ($paymentType === LogisticsOrderPaymentReference::TYPE_UPSELL
                        || ($paymentType === null && self::isUpsell($p['是否加购'] ?? '')));
                $row['subscription'] = $isUpsellPayment ? '加购' : self::subscriptionLabel($row['subscription']);
                $domainGroup = $group;
                if (blank($row['domain'])) {
                    $domainGroup = $brushStores->get(trim((string) ($p['自定义编号'] ?? '')));
                    $row['domain'] = $domainGroup?->domain ?? $row['domain'];
                }
                if (($brushOwner = $this->brushOrderOwner($domainGroup)) !== null) {
                    $row['subscription'] = $row['team'] = '刷单';
                    $row['owner'] = $brushOwner;
                }
            }
            $row['locked'] = $row['locked'] || in_array($row['month'], $locks, true);
            if (! empty($filters['order_view'])) {
                $row['operation_department'] = $this->departmentForOrderOwner(
                    $order, $ownership, (string) $row['owner'], $membersById, $membersByName,
                );
                foreach (array_slice($headers, 15, null, true) as $index => $header) {
                    if (filled($p[$header] ?? null)) {
                        $row['paypal_'.$index] = $header === '物品标题'
                            ? FinanceProductNames::display((string) $p[$header]) : $p[$header];
                    }
                }
                $row['paypal_18'] ??= FinanceProductNames::display($metadata[$record->id]['order']->tongtu_product_name ?? '');
                $row['source_added_date'] = $p['新增日期'] ?? '';
                $row['business_order_id'] = $metadata[$record->id]['logistics_order_id'] ?? null;
                $row['candidate_order'] = $candidateOrders[$record->id] ?? null;
                $row['shopify_order_number'] = $metadata[$record->id]['order']->shopify_order_number ?? '';
                $row['source_account_key'] = $owner?->activity_account ?? $record->account;
                $row['source_date'] = $occurredAt;
                $keys = [...array_keys(self::MONEY), ...array_values($this->importFields('paypal')), '结算金额-美元', 'settlement'];
                $row['manual_values'] = [
                    ...array_intersect_key($p, array_flip($keys)), ...array_intersect_key($saved, array_flip($keys)),
                    ...array_intersect_key($saved['_edited_fields'] ?? [], array_flip($keys)),
                ];
            }

            if ($confluxOrderIds->has($record->id) && $row['gross'] !== null && filled($row['currency']) && ! isset($p['_manual_import_month'])) {
                $tradeDate = $beijingDate->toDateString();
                $row['source_settlement_usd'] = $saved['_edited_fields']['source_settlement_usd'] ?? $saved['source_settlement_usd']
                    ?? $usdRates->convert($creditUsdRates, $row['currency'], $tradeDate, $row['gross']);
            }
            if ($channel === 'credit_card' && filled($filters['card_channel'] ?? null)) {
                $manual = [...$saved, ...($saved['_edited_fields'] ?? [])];
                $row['card_layout'] = FinanceCreditSettlementLayout::values(
                    $filters['card_channel'], $row, $p, $manual,
                );
                if (isset($tradeDate)) {
                    $row['card_layout']['source_settlement_usd'] = $row['source_settlement_usd'];
                    $row['card_layout']['calendar_date'] = $tradeDate;
                }
                if ($ledger?->has($record->id)) {
                    $entries = $ledger->get($record->id);
                    $details = $conflux->details($entries, $confluxFees);
                    $existingFees = array_intersect_key(array_filter($row['card_layout'], fn ($value) => filled($value)),
                        array_flip(['hold', 'fee', 'per_order_fee', 'refund_fee', 'chargeback_fee', 'ethoca_fee', 'rdr_fee']));
                    $row['card_layout'] = array_replace($row['card_layout'], array_diff_key($details['values'], $manual, $existingFees), [
                        'ledger_dates' => $details['dates'], 'ledger_exceptions' => implode('；', $details['reasons']),
                    ]);
                    if ($details['reasons'] === [] && isset($details['values']['settlement_currency'])
                        && ! filled($row['card_layout']['payable'] ?? null)) {
                        $values = array_map($this->number(...), array_intersect_key($row['card_layout'], self::MONEY + ['settlement' => true]));
                        $values['settlement_currency'] = $details['values']['settlement_currency'];
                        $row['card_layout']['payable'] = $conflux->payable($entries, $values);
                    }
                }
                if ($conflux) {
                    $row['ledger_matched'] = $ledger->has($record->id);
                    $row['card_layout']['ledger_status'] = $row['ledger_matched'] ? '已匹配账单' : '待匹配账单';
                }
            }

            if (empty($filters['summary_only'])) {
                $shopPaid = $order->paid_amount_usd ?? null;
                if ($channel === 'paypal') {
                    $row['shop_paid_usd'] = $shopPaid;
                } else {
                    $layout = $row['card_layout'] ?? [];
                    $layoutCurrency = strtoupper(trim((string) ($layout['settlement_currency'] ?? '')));
                    $channelAmount = ($layoutCurrency === '' || $layoutCurrency === 'USD')
                        ? $this->number($layout['settlement'] ?? null) : null;
                    $row['shop_difference_usd'] = $this->shopDifference(
                        $channelAmount ?? $row['source_settlement_usd'] ?? $row['settlement'], $shopPaid,
                    );
                    if (isset($row['card_layout'])) {
                        $row['card_layout']['shop_difference_usd'] = $row['shop_difference_usd'];
                    }
                }
            }

            return $row;
        })->filter(fn ($row) => (! filled($filters['team'] ?? null) || $row['team'] === $filters['team'])
            && (! filled($filters['subscription'] ?? null) || $row['subscription'] === self::subscriptionLabel($filters['subscription']))
            && (! filled($filters['domain'] ?? null) || str_contains((string) $row['domain'], $filters['domain']))
            && (empty($filters['card_exceptions']) || filled($row['card_layout']['ledger_exceptions'] ?? null))
            && (empty($filters['card_match']) || ($row['ledger_matched'] ?? false) === ($filters['card_match'] === 'matched'))
            && $this->matches(array_replace($row, $row['card_layout'] ?? []), $filters['conditions'] ?? []))->values();
    }

    public function matches(array $row, array $conditions, ?array $numericFields = null): bool
    {
        foreach ($conditions as $condition) {
            $actual = $row[$condition['field']] ?? null;
            $expected = $condition['value'] ?? '';
            $operator = $condition['operator'];
            $empty = $actual === null || $actual === '';
            $canCompareNumbers = $numericFields === null || in_array($condition['field'], $numericFields, true);
            $actualNumber = $canCompareNumbers ? $this->number($actual) : null;
            $expectedNumber = $canCompareNumbers ? $this->number($expected) : null;
            $numeric = $actualNumber !== null && $expectedNumber !== null;
            $comparison = $numeric ? bccomp($actualNumber, $expectedNumber, 8) : strcmp((string) $actual, $expected);
            $match = match ($operator) {
                'empty' => $empty, 'not_empty' => ! $empty,
                'contains' => mb_stripos(FinanceCatalog::simplified((string) $actual), FinanceCatalog::simplified($expected)) !== false,
                'not_equals' => ! $empty && $comparison !== 0,
                'gt' => ! $empty && $numeric && $comparison > 0, 'gte' => ! $empty && $numeric && $comparison >= 0,
                'lt' => ! $empty && $numeric && $comparison < 0, 'lte' => ! $empty && $numeric && $comparison <= 0,
                default => ! $empty && $comparison === 0,
            };
            if (! $match) {
                return false;
            }
        }

        return true;
    }

    private function resolveOrderOwner(?object $order, ?object $orderStore, ?object $group, array $payload): array
    {
        $inactiveOrderStore = in_array($orderStore->shop_status ?? null, [
            AssistantDomainStore::SHOP_STATUS_ABANDONED, AssistantDomainStore::SHOP_STATUS_STOPPED,
            'closed', 'idle', 'banned',
        ], true);
        $ownerName = $group?->assigned_owner ?? $group?->owner ?? ($payload['使用人'] ?? '');
        $ownerId = $group?->assigned_owner_user_id ?? null;
        $team = $group?->team ?? ($payload['团队'] ?? '');
        $usesLegacyOperator = false;
        $emptyBaseline = false;
        if ($order !== null) {
            if (isset($order->owner_history_id)) {
                $ownerName = $order->historical_owner_name ?? '';
                $ownerId = $order->historical_owner_user_id;
            } elseif (filled($order->assigned_owner_name ?? null)) {
                $ownerName = $order->assigned_owner_name;
                $ownerId = $order->assigned_owner_user_id ?? null;
            } elseif (property_exists($order, 'assigned_owner_name')) {
                $ownerName = $orderStore?->assigned_owner ?? '';
                $ownerId = $orderStore?->assigned_owner_user_id ?? null;
            }
            if ($inactiveOrderStore && ! isset($order->owner_history_id) && blank($ownerName)) {
                $ownerName = trim((string) ($order->operator_name ?? ''));
            }
            $emptyBaseline = blank($ownerName) && $ownerId === null
                && ($order->historical_owner_event ?? null) === AssistantDomainStoreOwnerHistory::EVENT_BASELINE;
            if ($emptyBaseline && filled($order->assigned_owner_name ?? null)) {
                $ownerName = $order->assigned_owner_name;
                $ownerId = $order->assigned_owner_user_id ?? null;
            }
            $legacyOperator = trim((string) ($order->operator_name ?? ''));
            // An empty initial record can coexist with the older order and store operator fields.
            if (blank($ownerName) && $ownerId === null && $legacyOperator !== ''
                && $legacyOperator === trim((string) ($orderStore->owner ?? ''))
                && (! isset($order->owner_history_id)
                    || $order->historical_owner_event === AssistantDomainStoreOwnerHistory::EVENT_BASELINE)) {
                $ownerName = $legacyOperator;
                $usesLegacyOperator = true;
            }
            if ($emptyBaseline && blank($ownerName) && $ownerId === null) {
                // The initial history row can precede the first recorded owner.
                $ownerName = $order->initial_owner_name ?? '';
                $ownerId = $order->initial_owner_user_id ?? null;
            }
            if ($emptyBaseline && blank($ownerName) && $ownerId === null) {
                $ownerName = $orderStore?->owner ?: ($group?->owner ?? '');
            }
            if (property_exists($order, 'group_names') && (! $emptyBaseline || filled($order->group_names))) {
                $team = FinanceCatalog::teamLabel($order->group_names);
            }
        }

        return compact('ownerName', 'ownerId', 'team', 'inactiveOrderStore', 'usesLegacyOperator', 'emptyBaseline');
    }

    private function departmentForOrderOwner(?object $order, array $ownership, string $owner, Collection $membersById, Collection $membersByName): string
    {
        ['ownerName' => $ownerName, 'ownerId' => $ownerId, 'inactiveOrderStore' => $inactiveOrderStore, 'usesLegacyOperator' => $usesLegacyOperator] = $ownership;
        $displayedOwner = trim($owner);
        $displayedOwnerId = $displayedOwner !== '' && $displayedOwner === trim((string) $ownerName) ? $ownerId : null;
        $nameMatches = $membersByName->get($displayedOwner);
        $member = $displayedOwnerId !== null
            ? $membersById->get($displayedOwnerId)
            : ($nameMatches?->count() === 1 ? $nameMatches->first() : null);
        $department = $member?->operation_department ?? '';
        if ($member === null && ($inactiveOrderStore || $usesLegacyOperator) && $displayedOwner !== '') {
            // Keep the recorded team when it belongs to the operator shown on the settlement.
            $snapshotOwner = trim((string) (($order->assigned_owner_name ?? null) ?: ($order->operator_name ?? '')));
            if ($displayedOwner === $snapshotOwner
                || ($displayedOwnerId !== null && (int) $displayedOwnerId === (int) ($order->assigned_owner_user_id ?? 0))) {
                $department = FinanceCatalog::teamLabel($order->group_names ?? null);
            }
        }
        if ($department === '' && $ownership['emptyBaseline'] && $displayedOwner === trim((string) $ownerName)) {
            $department = $ownership['team'];
        }

        return $department;
    }

    public static function operationRegion(string $department): string
    {
        if ($department === '刷单') {
            return $department;
        }

        $region = '';
        foreach (explode('、', $department) as $name) {
            // Existing departments use city prefixes. New cities can use their full city name.
            if (! preg_match('/^(厦门|深圳|郑州|西安)|^(\p{Han}{2,8}?)市(?=$|[\s·—-]|运营|[一二三四五六七八九十0-9]+部)/u', trim($name), $matches)) {
                return '';
            }
            $matchedRegion = $matches[2] ?? $matches[1];
            if ($region !== '' && $region !== $matchedRegion) {
                return '';
            }
            $region = $matchedRegion;
        }

        return $region;
    }

    private function brushOrderOwner(?object $group): ?string
    {
        if (str_contains((string) $group?->owner, '刷单')) {
            return $group->owner;
        }

        return str_contains((string) $group?->team, '刷单') ? '刷单' : null;
    }

    public function liveOrderAttributionResolver(): \Closure
    {
        $groups = $this->workbooks->domainGroups(withAssignedOwner: true);
        $stores = $groups->keyBy('id');
        $domains = $groups->groupBy('domain')->filter(fn ($matches) => $matches->count() === 1)->map(fn ($matches) => $matches->first());
        $members = $this->operationDepartmentMembers();
        $membersById = $members->keyBy('id');
        $membersByName = $members->groupBy(fn ($member) => array_unique([$member->name, $member->username]));
        $departmentNames = $this->operationDepartments()->pluck('name')->unique();
        $departmentKey = static fn (string $name): string => preg_replace('/(?:运营部门|运营部|总部|部门|团队)$/u', '', trim($name));
        $departmentAliases = $departmentNames->groupBy($departmentKey)
            ->filter(fn ($names) => $names->count() === 1)->map(fn ($names) => $names->first());
        $resolveDepartment = static function (string $name) use ($departmentNames, $departmentKey, $departmentAliases): string {
            if ($name === '刷单' || $departmentNames->containsStrict($name)) {
                return $name;
            }

            return $departmentAliases->get($departmentKey($name), '');
        };

        return function (FinanceRecord $record, ?object $order) use ($stores, $domains, $membersById, $membersByName, $resolveDepartment): array {
            $domain = strtolower(trim((string) $record->domain));
            $orderStore = $stores->get($order->assistant_domain_store_id ?? null);
            $group = $orderStore?->domain === $domain ? $orderStore : $domains->get($domain);
            if (($brushOwner = $this->brushOrderOwner($group)) !== null) {
                return ['owner' => $brushOwner, 'department' => '刷单'];
            }
            $ownership = $this->resolveOrderOwner($order, $orderStore, $group, $record->payload);
            $owner = (string) $ownership['ownerName'];
            $department = str_contains($owner, '刷单') ? '刷单'
                : $this->departmentForOrderOwner($order, $ownership, $owner, $membersById, $membersByName);
            $department = $resolveDepartment($department)
                ?: $resolveDepartment($ownership['team'])
                ?: $resolveDepartment($group?->team ?? '');

            return [
                // The report's usage label can come from the store profile even when its formal owner was cleared.
                'owner' => filled($owner) ? $owner : ($group?->owner ?? ''),
                'department' => $department,
            ];
        };
    }

    /** Credit order IDs already refer to logistics orders, so no payment-reference matching is needed. */
    public function liveCreditReportOrders(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        $db = $this->workbooks->database();
        $columns = $db->getSchemaBuilder()->getColumnListing('logistics_orders');
        $selected = array_intersect(['id', 'transaction_id', 'payment_gateway_order_id', 'bill_number', 'buyer_email', 'order_created_at', 'tongtu_order_number', 'shopify_payload', 'paid_amount_usd',
            'assistant_domain_store_id', 'assigned_owner_name', 'assigned_owner_user_id', 'operator_name', 'group_names', 'subscription_type', 'is_add_on'], $columns);
        $query = $db->table('logistics_orders')->whereIntegerInRaw('logistics_orders.id', $ids->all())
            ->select(array_map(fn ($column) => 'logistics_orders.'.$column, $selected));
        $this->joinOrderOwnerHistory($query, $selected);

        return $query->get()->keyBy('id');
    }

    private function joinOrderOwnerHistory(\Illuminate\Database\Query\Builder $orderQuery, array $selected): void
    {
        $db = $orderQuery->getConnection();
        if (in_array('order_created_at', $selected, true)
            && in_array('assistant_domain_store_id', $selected, true)
            && $db->getSchemaBuilder()->hasTable('assistant_domain_store_owner_histories')
        ) {
            $historyId = $db->table('assistant_domain_store_owner_histories')
                ->select('id')
                ->whereColumn('assistant_domain_store_id', 'logistics_orders.assistant_domain_store_id')
                ->whereNotNull('logistics_orders.order_created_at')
                ->where(function ($query) use ($db): void {
                    // The first recorded owner also covers orders placed before the first boundary.
                    $firstEffectiveFrom = $db->table('assistant_domain_store_owner_histories')
                        ->selectRaw('MIN(effective_from)')
                        ->whereColumn('assistant_domain_store_id', 'logistics_orders.assistant_domain_store_id');
                    $query->whereColumn('effective_from', '<=', 'logistics_orders.order_created_at')
                        ->orWhere('effective_from', '=', $firstEffectiveFrom);
                })
                ->where(fn ($query) => $query->whereNull('effective_to')
                    ->orWhereColumn('effective_to', '>', 'logistics_orders.order_created_at'))
                ->orderByDesc('effective_from')->orderByDesc('id')->limit(1);
            $initialOwnerId = $db->table('assistant_domain_store_owner_histories as next_history')
                ->select('next_history.id')
                ->whereColumn('next_history.assistant_domain_store_id', 'owner_history.assistant_domain_store_id')
                ->whereColumn('next_history.effective_from', 'owner_history.effective_to')
                ->where('owner_history.event', AssistantDomainStoreOwnerHistory::EVENT_BASELINE)
                ->whereNull('owner_history.owner_user_id')
                ->where(fn ($query) => $query->whereNull('owner_history.owner_name_snapshot')
                    ->orWhere('owner_history.owner_name_snapshot', ''))
                ->where(fn ($query) => $query->whereNull('next_history.effective_to')
                    ->orWhereColumn('next_history.effective_to', '>', 'next_history.effective_from'))
                ->orderByDesc('next_history.id')->limit(1);
            $orderQuery->leftJoin('assistant_domain_store_owner_histories as owner_history', fn ($join) =>
                $join->where('owner_history.id', '=', $historyId))
                ->leftJoin('assistant_domain_store_owner_histories as initial_owner', fn ($join) =>
                    $join->where('initial_owner.id', '=', $initialOwnerId))
                ->addSelect('owner_history.id as owner_history_id', 'owner_history.owner_name_snapshot as historical_owner_name',
                    'owner_history.owner_user_id as historical_owner_user_id', 'owner_history.event as historical_owner_event',
                    'initial_owner.owner_name_snapshot as initial_owner_name', 'initial_owner.owner_user_id as initial_owner_user_id');
        }
    }

    public function operationDepartments(): Collection
    {
        if (! $this->workbooks->database()->getSchemaBuilder()->hasTable(config('admin.database.roles_table'))) {
            return collect();
        }

        return app(AssistantStoreOwnerService::class)->storeOwnershipSelectableOperationDepartments();
    }

    private function operationDepartmentMembers(): Collection
    {
        $schema = $this->workbooks->database()->getSchemaBuilder();
        $user = new AdminUser;
        if (! $schema->hasTable($user->getTable()) || ! $schema->hasTable(config('admin.database.role_users_table'))) {
            return collect();
        }

        $departmentIds = $this->operationDepartments()->pluck('id')->all();
        if ($departmentIds === []) {
            return collect();
        }

        $scope = fn ($query) => $query->whereIn(config('admin.database.roles_table').'.id', $departmentIds);

        return AdminUser::query()->withWhereHas('departments', $scope)->get(['id', 'name', 'username'])
            ->map(function (AdminUser $member) {
                $departments = $member->departments->sortBy('id');
                $department = $departments->first(fn ($department) => (bool) $department->pivot->is_primary)
                    ?? $departments->first();
                $member->operation_department = $department?->name ?? '';

                return $member;
            });
    }

    /** Enrich settlements from a linked logistics order or a unique payment reference. */
    public function logisticsMetadata(Collection $records, string $channel, bool $matchOnly = false, bool $withCustomReference = false, bool $summaryOnly = false): array
    {
        $db = $this->workbooks->database();
        if ($records->isEmpty() || ! $db->getSchemaBuilder()->hasTable('logistics_orders')) {
            return [];
        }
        $columns = $db->getSchemaBuilder()->getColumnListing('logistics_orders');
        $referenceFields = array_values(array_intersect(
            $channel === 'paypal' ? ['bill_number', 'transaction_id', 'payment_gateway_order_id'] : ['bill_number', 'transaction_id', 'payment_gateway_order_id', 'shopify_order_number'],
            $columns
        ));
        if ($referenceFields === [] && ! ($channel === 'paypal' && $db->getSchemaBuilder()->hasTable('logistics_order_payment_references'))) {
            return [];
        }
        $summaryFields = ['assistant_domain_store_id', 'source_shop_domain', 'subscription_type', 'is_add_on', 'tongtu_order_number',
            'order_created_at', 'payment_method', 'assigned_owner_name', 'assigned_owner_user_id', 'operator_name', 'group_names'];
        $detailFields = [
            'shopify_order_number', 'shopify_order_id', 'store_name', 'subscription_status', 'subscription_origin_order_id',
            'tongtu_sku', 'tongtu_product_name', 'sale_quantity', 'sale_product_weight', 'payment_gateway',
            ...array_keys(FinancePayPalOrderExport::COLUMNS), 'created_at', 'buyer_name', 'buyer_email', 'destination',
        ];
        $selected = $matchOnly ? ['id', ...$referenceFields] : array_values(array_unique(array_intersect(
            ['id', ...$referenceFields, ...$summaryFields, ...($summaryOnly ? [] : $detailFields)], $columns,
        )));
        $referenceValues = fn ($record) => $channel === 'paypal' ? [$record->reference, trim((string) ($record->payload['帳單號碼'] ?? ''))]
            : [$record->reference, $record->payload['商户订单号'] ?? '', $record->payload['Shopify订单号'] ?? ''];
        $references = $records->flatMap($referenceValues)->filter()->unique()->all();
        $customReferences = collect();
        if ($channel === 'paypal' && $withCustomReference && ! $matchOnly
            && in_array('transaction_id', $columns, true) && in_array('paid_amount_usd', $columns, true)) {
            $checkoutLabels = array_column(array_filter(FinancePayPalEventTypes::TYPES, fn ($type) => $type[1] === '结账付款'), 0);
            $customReferences = $records->filter(fn ($record) => $record->currency === 'USD'
                && in_array(FinancePayPalEventTypes::label((string) $record->raw_type), $checkoutLabels, true)
                && in_array(strtoupper((string) $record->status), ['S', 'SUCCESS', 'COMPLETED', 'PAID', '已付款', '已完成', '成功'], true))
                ->mapWithKeys(fn ($record) => [$record->id => trim((string) ($record->payload['自定义编号'] ?? ''))])
                ->filter(fn ($reference) => $reference !== '');
            if ($customReferences->isNotEmpty()) {
                $selected = array_values(array_unique([...$selected, 'paid_amount_usd']));
            }
        }
        $paymentReferences = collect();
        $paymentTypes = [];
        $paymentReferenceColumns = [];
        if ($channel === 'paypal' && $db->getSchemaBuilder()->hasTable('logistics_order_payment_references')) {
            $paymentReferenceColumns = ['reference_number', 'logistics_order_id'];
            if (! $matchOnly && $db->getSchemaBuilder()->hasColumn('logistics_order_payment_references', 'reference_type')) {
                $paymentReferenceColumns[] = 'reference_type';
            }
            $paymentReferences = $db->table('logistics_order_payment_references')->whereIn('reference_number', $references)->get($paymentReferenceColumns);
        }
        $linkedIds = $paymentReferences->pluck('logistics_order_id')->unique();
        // Resolve IDs through each reference index before loading the wider order rows.
        foreach (array_chunk($references, 500) as $batch) {
            $matches = null;
            foreach ($referenceFields as $field) {
                $lookup = $db->table('logistics_orders')->select('id')->whereIn($field, $batch);
                $matches = $matches ? $matches->union($lookup) : $lookup;
            }
            if ($matches !== null) {
                $linkedIds = $linkedIds->merge($matches->pluck('id'));
            }
        }
        foreach ($customReferences->unique()->chunk(500) as $batch) {
            $linkedIds = $linkedIds->merge($db->table('logistics_orders')->whereIn('transaction_id', $batch->values())->pluck('id'));
        }
        $linkedIds = $linkedIds->unique();
        $orderQuery = $db->table('logistics_orders')->whereIntegerInRaw('logistics_orders.id', $linkedIds->all())
            ->select(array_map(fn ($column) => 'logistics_orders.'.$column, $selected));
        if ($channel === 'paypal') {
            $this->joinOrderOwnerHistory($orderQuery, $selected);
        }
        $orders = $orderQuery->get()->keyBy('id');
        $stores = ! $matchOnly && $db->getSchemaBuilder()->hasTable('assistant_domain_stores')
            ? $db->table('assistant_domain_stores')->whereIntegerInRaw('id', $orders->pluck('assistant_domain_store_id')->filter()->unique()->all())
                ->pluck('domain', 'id') : collect();
        $index = [];
        $customIndex = [];
        $customValues = $customReferences->flip();
        foreach ($orders as $order) {
            if (isset($customValues[$order->transaction_id ?? ''])) {
                $customIndex[$order->transaction_id][$order->id] = $order;
            }
            foreach ($referenceFields as $field) {
                if (filled($order->$field)) {
                    $index[(string) $order->$field][$order->id] = $order;
                }
            }
        }
        foreach ($paymentReferences as $reference) {
            if ($order = $orders->get($reference->logistics_order_id)) {
                $index[$reference->reference_number][$order->id] = $order;
                if (isset($reference->reference_type)) {
                    $paymentTypes[$reference->reference_number][$order->id] = $reference->reference_type;
                }
            }
        }
        $customOrderIds = collect($customIndex)->filter(fn ($orders) => count($orders) === 1)
            ->flatMap(fn ($orders) => array_keys($orders))->all();
        $ordersWithPrimaryReference = $customOrderIds !== [] && in_array('reference_type', $paymentReferenceColumns, true)
            ? $db->table('logistics_order_payment_references')->whereIntegerInRaw('logistics_order_id', $customOrderIds)
                ->where('reference_type', LogisticsOrderPaymentReference::TYPE_PRIMARY)->pluck('logistics_order_id')->flip()
            : collect();
        $result = [];
        foreach ($records as $record) {
            $candidates = [];
            foreach (array_filter($referenceValues($record), fn ($value) => trim((string) $value) !== '') as $reference) {
                foreach ($index[(string) $reference] ?? [] as $id => $order) {
                    $candidates[$id] = $order;
                }
            }
            $matchedCustomReference = false;
            if ($candidates === [] && $customReferences->has($record->id)) {
                $customCandidates = $customIndex[$customReferences->get($record->id)] ?? [];
                if (count($customCandidates) === 1) {
                    $candidate = reset($customCandidates);
                    $gross = $this->number($record->payload[FinanceCatalog::dataset('paypal-orders')['headers'][7]] ?? null);
                    $paid = $this->number($candidate->paid_amount_usd);
                    if (blank($candidate->bill_number ?? null) && ! $ordersWithPrimaryReference->has($candidate->id)
                        && $gross !== null && $paid !== null
                        && bccomp($gross, '0', 4) > 0 && bccomp($gross, $paid, 4) === 0) {
                        $candidates = $customCandidates;
                        $matchedCustomReference = true;
                    }
                }
            }
            if (count($candidates) !== 1) {
                continue;
            }
            $order = reset($candidates);
            if ($matchOnly) {
                $result[$record->id] = ['logistics_order_id' => $order->id];
                continue;
            }
            $result[$record->id] = ['logistics_order_id' => $order->id, 'domain' => $stores->get($order->assistant_domain_store_id ?? null) ?: ($order->source_shop_domain ?? ''),
                'payment_method' => $order->payment_method ?? '',
                'subscription' => $order->subscription_type ?? '', 'upsell' => $order->is_add_on ?? '', 'erp_order' => $order->tongtu_order_number ?? ''];
            if ($channel === 'paypal') {
                $result[$record->id]['order'] = $order;
                $result[$record->id]['payment_reference_type'] = $paymentTypes[$record->reference][$order->id]
                    ?? ($matchedCustomReference || (string) ($order->bill_number ?? '') === $record->reference
                        ? LogisticsOrderPaymentReference::TYPE_PRIMARY : null);
            }
        }

        return $result;
    }

    public function logisticsPaymentReferences(object $order): array
    {
        $db = $this->workbooks->database();
        $references = collect([$order->bill_number ?? '', $order->transaction_id ?? '', $order->payment_gateway_order_id ?? '']);
        if ($db->getSchemaBuilder()->hasTable('logistics_order_payment_references')) {
            $references = $references->merge($db->table('logistics_order_payment_references')
                ->where('logistics_order_id', $order->id)->pluck('reference_number'));
        }

        return $references->filter(fn ($reference) => filled($reference))->flip()->all();
    }

    /** Resolve costs to a uniquely matched primary PayPal payment. */
    public function paypalCostPayments(array $orderNumbers, array $filters): Collection
    {
        $db = $this->workbooks->database();
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasColumns('logistics_orders', ['tongtu_order_number', 'bill_number'])) {
            return collect();
        }
        $orders = $db->table('logistics_orders')->whereIn('tongtu_order_number', $orderNumbers)
            ->get(['id', 'tongtu_order_number', 'bill_number']);
        $references = $orders->pluck('bill_number');
        if ($schema->hasColumns('logistics_order_payment_references', ['reference_type', 'reference_number', 'logistics_order_id'])) {
            $references = $references->merge($db->table('logistics_order_payment_references')
                ->whereIn('logistics_order_id', $orders->pluck('id'))
                ->where('reference_type', LogisticsOrderPaymentReference::TYPE_PRIMARY)->pluck('reference_number'));
        }
        $payments = $this->query('paypal', $filters)->whereIn('reference', $references->filter()->unique()->values())
            ->orderBy('id')->lockForUpdate()->get();
        $metadata = $this->logisticsMetadata($payments, 'paypal');

        return $payments->filter(fn ($payment) => in_array(strtoupper((string) $payment->status),
            ['S', 'SUCCESS', 'COMPLETED', 'PAID', '已付款', '已完成', '成功'], true)
            && ($metadata[$payment->id]['payment_reference_type'] ?? null) === LogisticsOrderPaymentReference::TYPE_PRIMARY)
            ->groupBy(fn ($payment) => $metadata[$payment->id]['erp_order']);
    }

    /** Match API transaction/invoice references without any legacy account binding. */
    public function paypalOrderMatchIndex(): \Illuminate\Database\Query\Builder
    {
        $db = $this->workbooks->database();
        $schema = $db->getSchemaBuilder();
        $references = $db->table('finance_records')->where('dataset', 'paypal-orders')
            ->whereNotNull('reference')->where('reference', '!=', '')
            ->selectRaw('id as record_id, reference as match_reference');
        $references->union($db->table('finance_records')->where('dataset', 'paypal-orders')
            ->whereNotNull('payload->帳單號碼')->where('payload->帳單號碼', '!=', '')
            ->select('id as record_id', 'payload->帳單號碼 as match_reference'));
        $matches = null;
        foreach (array_intersect(['bill_number', 'transaction_id', 'payment_gateway_order_id'], $schema->getColumnListing('logistics_orders')) as $field) {
            $branch = $db->query()->fromSub(clone $references, 'refs')
                ->join('logistics_orders as source_order', 'source_order.'.$field, '=', 'refs.match_reference')
                ->select('refs.record_id', 'source_order.id as order_id');
            $matches = $matches ? $matches->unionAll($branch) : $branch;
        }
        if ($schema->hasTable('logistics_order_payment_references')) {
            $branch = $db->query()->fromSub(clone $references, 'refs')
                ->join('logistics_order_payment_references as source_ref', 'source_ref.reference_number', '=', 'refs.match_reference')
                ->join('logistics_orders as source_order', 'source_order.id', '=', 'source_ref.logistics_order_id')
                ->select('refs.record_id', 'source_order.id as order_id');
            $matches = $matches ? $matches->unionAll($branch) : $branch;
        }
        $matches ??= $db->table('finance_records')->selectRaw('id as record_id, 0 as order_id')->whereRaw('1 = 0');

        return $db->query()->fromSub($matches, 'matches')->select('record_id')
            ->selectRaw('MIN(order_id) as order_id')->groupBy('record_id')->havingRaw('COUNT(DISTINCT order_id) = 1');
    }

    public function paypalOrderMatches(): \Illuminate\Database\Query\Builder
    {
        $db = $this->workbooks->database();
        $schema = $db->getSchemaBuilder();
        $fields = array_intersect(['bill_number', 'transaction_id', 'payment_gateway_order_id'], $schema->getColumnListing('logistics_orders'));
        $hasReferences = $schema->hasTable('logistics_order_payment_references');
        return $db->table('logistics_orders as logistics')->where(function ($match) use ($fields, $hasReferences): void {
            $match->whereRaw('1 = 0');
            foreach ($fields as $field) {
                $match->orWhere(function ($reference) use ($field): void {
                    $reference->where('logistics.'.$field, '!=', '')->where(function ($values) use ($field): void {
                        $values->whereColumn('logistics.'.$field, 'finance_records.reference')
                            ->orWhereColumn('logistics.'.$field, 'finance_records.payload->帳單號碼');
                    });
                });
            }
            if ($hasReferences) {
                $match->orWhereExists(function ($references): void {
                    $references->selectRaw('1')->from('logistics_order_payment_references as payment_reference')
                        ->whereColumn('payment_reference.logistics_order_id', 'logistics.id')
                        ->where('payment_reference.reference_number', '!=', '')
                        ->where(function ($values): void {
                            $values->whereColumn('payment_reference.reference_number', 'finance_records.reference')
                                ->orWhereColumn('payment_reference.reference_number', 'finance_records.payload->帳單號碼');
                        });
                });
            }
        });
    }

    public static function isSuccessfulTransaction(mixed $status): bool
    {
        return in_array(strtoupper((string) $status), self::TRANSACTION_STATUS_CODES['success'], true);
    }

    public static function transactionStatusCategory(mixed $status): string
    {
        $status = strtoupper(trim((string) $status));
        foreach (self::TRANSACTION_STATUS_CODES as $category => $codes) {
            if (in_array($status, $codes, true)) {
                return $category;
            }
        }

        return $status;
    }

    public static function subscriptionLabel(mixed $value): string
    {
        $label = str_replace('订购复购', '订阅复购', FinanceCatalog::simplified(trim((string) $value)));

        return $label === '订阅首次购买' ? '订阅' : preg_replace('/^订阅复购\s*(\d+)\s*次$/u', '订阅复购$1次', $label);
    }

    private static function isUpsell(mixed $value): bool
    {
        return in_array(strtolower(FinanceCatalog::simplified(trim((string) $value))), ['1', 'true', 'yes', '是', '加购', '已加购'], true);
    }

    public function subscriptionOptions(Collection $rows): Collection
    {
        $db = $this->workbooks->database();
        $key = 'finance:subscription-options:v1:'.hash('sha256', $db->getName().'|'.$db->getDatabaseName());
        $options = Cache::remember($key, 60, function () use ($db): array {
            $datasets = ['paypal-orders', 'xuzong2-orders', 'xuzong2-details', 'xuzong1-details'];
            $types = FinanceRecord::query()->whereIn('dataset', $datasets)->select('payload->订阅类型 as subscription')->distinct()->pluck('subscription');
            $supplements = $db->table('finance_settlement_supplements')->select('payload->subscription as subscription')->distinct()->pluck('subscription');
            $columns = array_intersect(['subscription_type', 'is_add_on'], $db->getSchemaBuilder()->getColumnListing('logistics_orders'));
            $logistics = $columns === [] ? collect() : $db->table('logistics_orders')->distinct()->get(array_values($columns));
            $upsells = FinanceRecord::query()->whereIn('dataset', $datasets)->select('payload->是否加购 as upsell')->distinct()->pluck('upsell')
                ->concat($db->table('finance_settlement_supplements')->select('payload->upsell as upsell')->distinct()->pluck('upsell'))
                ->concat($logistics->pluck('is_add_on'));

            return $types->concat($supplements)->concat($logistics->pluck('subscription_type'))
                ->concat($upsells->contains(self::isUpsell(...)) ? ['加购'] : [])->all();
        });

        return $rows->pluck('subscription')->concat($options)
            ->map(self::subscriptionLabel(...))->filter(fn ($value) => $value !== '')->unique()->sort()->values();
    }

    public function withLogisticsCosts(Collection $rows): Collection
    {
        $db = $this->workbooks->database();
        $orders = $rows->filter(fn ($row) => filled($row['logistics_order_id'] ?? null))->groupBy('logistics_order_id')
            ->filter(fn ($payments) => $payments->contains(fn ($row) => $row['success'])
                && (! $payments->contains(fn ($row) => $row['purchase'] !== null)
                    || ! $payments->contains(fn ($row) => $row['logistics'] !== null)));
        if ($orders->isEmpty() || ! $db->getSchemaBuilder()->hasTable('logistics_order_items')
            || ! $db->getSchemaBuilder()->hasTable('logistics_tongtu_sku_mappings')) {
            return $rows;
        }
        $itemColumns = array_intersect(['logistics_order_id', 'tongtu_sku_mapping_id', 'quantity', 'sale_weight_gram', 'tongtu_goods_weight_gram'],
            $db->getSchemaBuilder()->getColumnListing('logistics_order_items'));
        $fallbacks = [];
        foreach ($orders->chunk(500) as $batch) {
            $items = \App\Models\LogisticsOrderItem::query()->with('skuMapping:id,product_guide_cost_cny,goods_weight_gram')
                ->whereIntegerInRaw('logistics_order_id', $batch->keys()->all())->get($itemColumns)->groupBy('logistics_order_id');
            foreach ($batch as $orderId => $payments) {
                $payment = $payments->first(fn ($row) => $row['success']);
                $orderItems = $items->get($orderId);
                if ($payment === null || $orderItems === null) {
                    continue;
                }
                $purchase = $weight = '0';
                foreach ($orderItems as $item) {
                    $quantity = $this->number($item->quantity);
                    if ($quantity === null || bccomp($quantity, '0', 8) <= 0) {
                        $purchase = $weight = null;
                        break;
                    }
                    $cost = $this->number($item->skuMapping?->product_guide_cost_cny);
                    $grams = $this->number($item->sale_weight_gram ?: $item->tongtu_goods_weight_gram ?: $item->skuMapping?->goods_weight_gram);
                    $purchase = $purchase !== null && $cost !== null && bccomp($cost, '0', 8) > 0
                        ? bcadd($purchase, bcmul($cost, $quantity, 8), 8) : null;
                    $weight = $weight !== null && $grams !== null && bccomp($grams, '0', 8) > 0
                        ? bcadd($weight, bcmul($grams, $quantity, 8), 8) : null;
                }
                // Use the same weight-based estimate as the operations report, in CNY.
                $logistics = $weight === null ? null : bcadd(bcmul($weight, '0.08', 8), '20', 8);
                foreach (['purchase' => $purchase, 'logistics' => $logistics] as $field => $value) {
                    if ($value !== null && ! $payments->contains(fn ($row) => $row[$field] !== null)) {
                        $fallbacks[$payment['id']][$field] = $value;
                    }
                }
            }
        }

        return $rows->map(fn ($row) => isset($fallbacks[$row['id']]) ? array_replace($row, $fallbacks[$row['id']]) : $row);
    }

    public function shopDifference(mixed $channelAmount, mixed $shopPaid): ?string
    {
        $channelAmount = $this->number($channelAmount);
        $shopPaid = $this->number($shopPaid);

        return $channelAmount !== null && $shopPaid !== null ? bcsub($channelAmount, $shopPaid, 2) : null;
    }

    public function number(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = str_replace(',', '', trim((string) ($value ?? '')));

        return preg_match('/^-?\d{1,18}(?:\.\d{1,8})?$/', $text) ? $text : null;
    }

    public function moneyFields(string $channel): array
    {
        $keys = $channel === 'paypal' ? ['converted_usd', 'usd_rate', 'fee', 'hold', 'dispute_hold', 'frozen_refund', 'refund_top_up', 'refund', 'refund_fee', 'dispute_refund', 'dispute_fee', 'fee_return', 'dispute_release', 'release', 'logistics', 'purchase']
            : ['usd_rate', 'fee', 'hold', 'per_order_fee', 'refund', 'refund_fee', 'chargeback', 'chargeback_fee', 'ethoca_refund', 'ethoca_fee', 'rdr_refund', 'rdr_fee', 'release', 'logistics', 'purchase'];

        $fields = array_intersect_key(self::MONEY, array_flip($keys));
        if ($channel === 'paypal') {
            $fields = [...$fields, 'usd_rate' => '汇率', 'logistics' => '物流-元', 'purchase' => '采购成本-元'];
        }

        return $fields;
    }

    public function summaryRows(string $channel, array $filters, bool $transactions = false): Collection
    {
        $filters['summary_only'] = true;
        $fields = self::MONEY + array_fill_keys(['id', 'logistics_order_id', 'channel', 'account', 'account_key',
            'owner', 'team', 'subscription', 'payment_method', 'success', 'failed', 'settlement'], true);
        if ($transactions) {
            $fields += self::CONDITION_FIELDS + array_fill_keys(['currency', 'month'], true);
        }
        // Keep the original date order for grouping and for assigning costs to the first successful payment.
        $ids = $this->query($channel, $filters)->orderByDesc('occurred_at')->orderByDesc('id')->pluck('id');
        $rows = collect();
        $context = $ids->isEmpty() ? null : $this->rowContext($channel);
        foreach ($ids->chunk(1500) as $batch) {
            $records = FinanceRecord::query()->whereIntegerInRaw('id', $batch->all())
                ->orderByDesc('occurred_at')->orderByDesc('id')->get();
            foreach ($this->rows($channel, $filters, $records, $context) as $row) {
                $rows->push(array_intersect_key($row, $fields));
            }
        }

        return $rows;
    }

    public function totals(Collection $rows, string $layer): array
    {
        if ($layer === 'business') {
            $classifiedRows = $rows->map(function ($row) {
                $isFake = $row['channel'] === 'paypal' && str_contains(($row['team'] ?? '').' '.($row['owner'] ?? ''), '刷单');
                $subscription = $isFake ? '刷单' : ($row['subscription'] ?? '');

                return ($row['subscription'] ?? null) === $subscription ? $row : [...$row, 'subscription' => $subscription];
            });
            $groups = [];
            foreach ([['Shopify', 'project_classification'], ['通道', 'classification']] as [$scope, $classification]) {
                foreach ($this->totals($classifiedRows, $classification) as $group) {
                    [$channelName, $category] = explode(' / ', $group['name'], 2);
                    $isTotal = $group['is_total'] ?? false;
                    $group['scope'] = $scope === '通道' ? $channelName : $scope;
                    $group['category'] = $category;
                    $group['name'] = $channelName.($isTotal ? '' : ' / '.$category);
                    $group['is_total'] = $isTotal && $scope === 'Shopify';
                    $groups[] = $group;
                }
            }

            return $groups;
        }
        if (in_array($layer, ['classification', 'project_classification', 'payment_method'], true)) {
            $baseLayer = $layer === 'project_classification' ? 'project' : 'channel';
            $totals = $this->totals($rows, $baseLayer);
            foreach ($totals as &$total) {
                $total['name'] .= ' / 总计';
                $total['is_total'] = true;
            }
            unset($total);
            $classified = $rows->filter(fn ($row) => filled($layer === 'payment_method' ? ($row['payment_method'] ?? '') : $row['subscription']));
            $details = $classified->groupBy(fn ($row) => json_encode([
                $layer === 'project_classification' ? 'combined' : $row['channel'],
                $layer === 'payment_method' ? ($row['payment_method'] ?? '') : self::subscriptionLabel($row['subscription']),
            ]))->map(function ($items) use ($layer) {
                $first = $items->first();
                $group = $this->totals($items, $layer === 'project_classification' ? 'project' : 'channel')[0];
                $category = $layer === 'payment_method' ? ($first['payment_method'] ?: '未识别支付方式')
                    : (self::subscriptionLabel($first['subscription']) ?: '未分类');
                $group['name'] .= ' / '.$category;

                return $group;
            })->values()->all();

            return [...$totals, ...$details];
        }
        $groups = [];
        $amountFields = ['settlement', ...array_keys(self::MONEY)];
        $emptyAmounts = array_replace(array_fill_keys($amountFields, '0'), ['logistics' => null, 'purchase' => null]);
        foreach ($rows as $row) {
            $name = match ($layer) {
                'project' => 'Shopify',
                'channel' => $row['channel'] === 'paypal' ? 'PayPal' : '信用卡',
                'team' => $row['team'] ?: '未分组',
                'subscription' => $row['subscription'] ?: '未分类',
                'detail' => $row['account'].' / '.($row['team'] ?: '未分组').' / '.($row['subscription'] ?: '未分类'),
                default => $row['account'],
            };
            $channel = $layer === 'project' ? 'combined' : $row['channel'];
            $key = json_encode([$name, $channel]);
            $groups[$key] ??= ['name' => $name, 'channel' => $channel, 'count' => 0, 'success_count' => 0, 'failed_count' => 0] + $emptyAmounts;
            $groups[$key]['count']++;
            $groups[$key]['success_count'] += $row['success'] ? 1 : 0;
            $groups[$key]['failed_count'] += $row['failed'] ? 1 : 0;
            foreach ($amountFields as $field) {
                if ($field === 'settlement' && $row['failed']) {
                    continue;
                }
                // Workbook SUM ignores empty cells; retain blanks in detail, sum known values here.
                $amount = $field === 'refund' ? self::refundIncludingSplit($row) : $row[$field];
                if ($amount !== null) {
                    $groups[$key][$field] = bcadd($groups[$key][$field] ?? '0', $amount, 8);
                }
            }
        }

        return array_values($groups);
    }

    public static function refundIncludingSplit(array $row): ?string
    {
        $refund = $row['refund'] ?? null;
        if ($row['channel'] === 'paypal') {
            foreach (['frozen_refund' => 'refund', 'refund_top_up' => 'refund_fee'] as $field => $legacy) {
                // A legacy amount may already cover this component.
                if (isset($row[$field]) && bccomp($row[$legacy] ?? '0', '0', 8) === 0) {
                    $refund = bcadd($refund ?? '0', $row[$field], 8);
                }
            }
        }

        return $refund;
    }

    public function save(FinanceRecord $record, array $values, int $actor): void
    {
        $db = $this->workbooks->database();
        $db->transaction(function () use ($db, $record, $values, $actor): void {
            app(FinanceAccountingService::class)->guardUnlocked($record->occurred_at->setTimezone('Asia/Shanghai')->format('Y-m'));
            $existing = $db->table('finance_settlement_supplements')->where('finance_record_id', $record->id)->first();
            $data = $existing ? json_decode($existing->payload, true, 512, JSON_THROW_ON_ERROR) : [];
            if (filled($data['_edited_fields']['month'] ?? null)) {
                app(FinanceAccountingService::class)->guardUnlocked($data['_edited_fields']['month']);
            }
            foreach ($values as $key => $value) {
                if ($value === null && isset(self::MONEY[$key])) {
                    unset($data[$key]);
                    unset($values[$key]);
                }
            }
            $db->table('finance_settlement_supplements')->updateOrInsert(['finance_record_id' => $record->id], [
                'payload' => json_encode([...$data, ...$values], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_by' => $actor, 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
            ]);
        });
    }
}
