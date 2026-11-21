<?php

namespace App\Portals\Finance;

use App\Models\FinanceRecord;
use Illuminate\Support\Collection;

/** Index order keys and settlement amounts; hydrate only the requested payments. */
class FinancePayPalListStorage
{
    public const LABELS = ['account', 'reference', 'team', 'subscription', 'upsell', 'order_status',
        'source_category', 'paypal_12', 'paypal_reference_type', 'review', 'refund_state', 'unlinked_account'];
    public const FLAGS = ['success', 'failed', 'had_hold', 'refund_pending'];

    public function __construct(private readonly FinanceWorkbookService $workbooks, private readonly FinanceSettlementService $settlements) {}

    public static function amounts(): array
    {
        return array_values(array_unique([...array_keys(FinanceSettlementService::MONEY), 'gross', 'settlement', 'payable', 'open_hold', 'shop_difference_usd']));
    }

    public function installed(): bool
    {
        return $this->workbooks->database()->getSchemaBuilder()->hasTable('finance_paypal_list_rows');
    }

    public function insert(string $generation, int &$position, array $rows): void
    {
        $headers = $amounts = [];
        foreach ($rows as $row) {
            $hasPayment = ! empty($row['payments']);
            $header = ['generation' => $generation, 'position' => ++$position, 'record_id' => $row['id'],
                'has_payment' => $hasPayment, 'date' => $row['date'] ?? '', 'month' => $row['month'] ?? ''];
            foreach (self::LABELS as $field) $header[$field] = $row[$field] ?? null;
            foreach (self::FLAGS as $field) $header[$field] = $row[$field] ?? null;
            $headers[] = $header;
            if ($hasPayment) {
                $money = ['generation' => $generation, 'position' => $position];
                foreach (self::amounts() as $field) {
                    $value = $row[$field] ?? null;
                    if ($value !== null && ! is_numeric($value)) throw new \RuntimeException('PayPal 汇总包含非数字金额，停止发布。');
                    $money[$field] = $value;
                }
                $amounts[] = $money;
            }
        }
        if ($headers === []) return;
        $this->workbooks->database()->transaction(function () use ($headers, $amounts): void {
            $this->workbooks->database()->table('finance_paypal_list_rows')->insert($headers);
            if ($amounts !== []) $this->workbooks->database()->table('finance_paypal_list_amounts')->insert($amounts);
        });
    }

    public function matchingQuery(string $generation, array $filters = []): \Illuminate\Database\Query\Builder
    {
        $query = $this->workbooks->database()->table('finance_paypal_list_rows')->where('generation', $generation);
        if (filled($filters['order_status'] ?? null)) {
            // A payment can have several status labels (for example paid + held).
            $query->where('order_status', 'like', '%'.$filters['order_status'].'%');
        }
        return $query;
    }

    public function page(string $generation, int $offset, int $limit, array $filters = []): ?Collection
    {
        $db = $this->workbooks->database();
        $headers = $this->matchingQuery($generation, $filters)
            ->orderBy('position')->offset($offset)->limit($limit)->get();
        if ($headers->isEmpty()) return collect();
        $paymentIds = $headers->where('has_payment', true)->pluck('record_id')->all();
        $records = FinanceRecord::query()->whereKey($paymentIds)->get();
        if ($records->count() !== count($paymentIds)) return null;
        $payments = $paymentIds === [] ? collect() : $this->settlements->rows('paypal',
            ['timezone' => 'Asia/Shanghai', 'order_view' => true, 'match_candidate_domains' => false], $records)->keyBy('id');
        $amounts = $db->table('finance_paypal_list_amounts')->where('generation', $generation)
            ->whereIntegerInRaw('position', $headers->pluck('position')->all())->get()->keyBy('position');
        $locks = $db->table('finance_period_locks')->whereNotNull('report_snapshot')->pluck('month')->flip();
        $rows = collect();
        foreach ($headers as $header) {
            $payment = $payments->get($header->record_id);
            if ($header->has_payment && ($payment === null || ! $amounts->has($header->position))) return null;
            if ($payment !== null) {
                unset($payment['manual_values'], $payment['source_date'], $payment['editor_values'], $payment['shop_paid_usd']);
            }
            $row = $header->has_payment ? $payment : ['id' => (int) $header->record_id];
            foreach (self::LABELS as $field) if ($header->$field !== null) $row[$field] = $header->$field;
            foreach (self::FLAGS as $field) if ($header->$field !== null) $row[$field] = (bool) $header->$field;
            $row['date'] = $header->date; $row['month'] = $header->month; $row['timezone'] = 'Asia/Shanghai';
            $row['locked'] = $locks->has($header->month);
            if ($header->has_payment) {
                foreach (self::amounts() as $field) $row[$field] = FinanceSourceStorage::money($amounts->get($header->position)->$field);
                $payment['locked'] = $row['locked'];
                $row['payments'] = [$payment];
            } else $row['payments'] = [];
            $row['operation_region'] = FinanceSettlementService::operationRegion((string) ($row['team'] ?? ''));
            $rows->push($row);
        }
        return $rows;
    }

    public function delete(string $generation): void
    {
        foreach (['finance_paypal_list_amounts', 'finance_paypal_list_rows'] as $table) {
            $this->workbooks->database()->table($table)->where('generation', $generation)->delete();
        }
    }
}
