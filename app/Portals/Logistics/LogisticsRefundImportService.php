<?php

namespace App\Portals\Logistics;

use App\Models\AdminUser;
use App\Models\LogisticsCreditCardRefund;
use App\Models\LogisticsPayPalRefund;
use App\Models\LogisticsPayPalTransaction;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class LogisticsRefundImportService
{
    public function __construct(private readonly LogisticsOrderPaymentReferenceService $paymentReferences)
    {
    }

    private const HEADERS = [
        '支付方式' => 'payment_method',
        '对应编号' => 'reference_number',
        '退款流水号' => 'refund_reference',
        '原因' => 'refund_reason',
        '金额' => 'refund_amount_usd',
    ];

    /**
     * @return array{rows:int,paypal_updated:int,credit_card_added:int,credit_card_updated:int,invalid:int,errors:list<array<string,mixed>>}
     */
    public function import(UploadedFile $file, AdminUser $actor): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new RuntimeException(__('logistics.refund_import.errors.file_type'));
        }

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $spreadsheet->disconnectWorksheets();
        } catch (\Throwable $exception) {
            throw new RuntimeException(__('logistics.refund_import.errors.read_failed', ['message' => $exception->getMessage()]), previous: $exception);
        }

        [$headerRow, $columns] = $this->locateHeaders($rows);
        $summary = [
            'rows' => 0,
            'paypal_updated' => 0,
            'credit_card_added' => 0,
            'credit_card_updated' => 0,
            'invalid' => 0,
            'errors' => [],
        ];

        foreach ($rows as $rowNumber => $row) {
            if ((int) $rowNumber <= $headerRow || $this->isEmptyRow($row)) {
                continue;
            }
            $summary['rows']++;

            try {
                $data = $this->rowData($row, $columns);
                if ($data['payment_method'] === 'paypal') {
                    $this->updatePayPalRefund($data, $actor);
                    $summary['paypal_updated']++;
                    continue;
                }

                if ($this->upsertCreditCardRefund($data, $actor)) {
                    $summary['credit_card_added']++;
                } else {
                    $summary['credit_card_updated']++;
                }
            } catch (RefundImportRowException $exception) {
                $summary['invalid']++;
                $summary['errors'][] = $this->errorRow((int) $rowNumber, $exception, $row, $columns);
            } catch (\Throwable $exception) {
                $summary['invalid']++;
                $summary['errors'][] = [
                    ...$this->sourceData($row, $columns),
                    'row' => (int) $rowNumber,
                    'field' => __('logistics.refund_import.error_fields.system'),
                    'reason' => __('logistics.refund_import.errors.unexpected'),
                    'hint' => $exception->getMessage(),
                ];
            }
        }

        if ($summary['rows'] === 0) {
            throw new RuntimeException(__('logistics.refund_import.errors.no_rows'));
        }

        return $summary;
    }

    /** @param array<string,mixed> $data */
    private function updatePayPalRefund(array $data, AdminUser $actor): void
    {
        $matches = LogisticsPayPalRefund::query()
            ->where('refund_transaction_id', $data['reference_number'])
            ->limit(2)
            ->get();
        if ($matches->count() === 0) {
            throw new RefundImportRowException('reference_number', 'paypal_refund_not_found');
        }
        if ($matches->count() > 1) {
            throw new RefundImportRowException('reference_number', 'paypal_refund_ambiguous');
        }

        $matches->first()->forceFill([
            'manual_reason' => $data['refund_reason'],
            'manual_reason_updated_by' => $actor->getKey(),
            'manual_reason_updated_by_name' => $actor->name,
            'manual_reason_updated_at' => now(),
        ])->save();
    }

    /** @param array<string,mixed> $data */
    private function upsertCreditCardRefund(array $data, AdminUser $actor): bool
    {
        $order = $this->paymentReferences->uniqueOrderForReference($data['reference_number']);
        if (!$order) {
            if ($this->paymentReferences->referenceIsAmbiguous($data['reference_number'])) {
                throw new RefundImportRowException('reference_number', 'credit_card_bill_ambiguous');
            }

            throw new RefundImportRowException('reference_number', 'credit_card_bill_not_found');
        }
        if (LogisticsPayPalTransaction::query()
            ->where('payment_transaction_id', $data['reference_number'])
            ->exists()) {
            // An order can carry multiple payment references. Only block the
            // exact reference proven to be a real PayPal transaction; other
            // references may legitimately belong to an independent card flow.
            throw new RefundImportRowException('reference_number', 'credit_card_bill_is_paypal_order');
        }

        $existing = LogisticsCreditCardRefund::query()
            ->where('logistics_order_id', $order->getKey())
            ->where('refund_reference', $data['refund_reference'])
            ->first();
        $values = [
            'bill_number' => $data['reference_number'],
            'refund_amount_usd' => $data['refund_amount_usd'],
            'refund_reason' => $data['refund_reason'],
            'last_updated_by' => $actor->getKey(),
            'last_updated_by_name' => $actor->name,
            'last_imported_at' => now(),
        ];
        if ($existing) {
            $existing->fill($values)->save();

            return false;
        }

        LogisticsCreditCardRefund::query()->create([
            ...$values,
            'logistics_order_id' => $order->getKey(),
            'refund_reference' => $data['refund_reference'],
            'imported_by' => $actor->getKey(),
            'imported_by_name' => $actor->name,
        ]);

        return true;
    }

    /** @param array<int|string,array<string,mixed>> $rows
     * @return array{0:int,1:array<string,string>}
     */
    private function locateHeaders(array $rows): array
    {
        foreach ($rows as $rowNumber => $row) {
            $columns = [];
            foreach ($row as $column => $value) {
                $header = $this->text($value);
                if (isset(self::HEADERS[$header])) {
                    $columns[self::HEADERS[$header]] = (string) $column;
                }
            }
            if (count($columns) === count(self::HEADERS)) {
                return [(int) $rowNumber, $columns];
            }
        }

        throw new RuntimeException(__('logistics.refund_import.errors.headers_required', [
            'headers' => implode('、', array_keys(self::HEADERS)),
        ]));
    }

    /** @param array<string,mixed> $row
     * @param array<string,string> $columns
     * @return array{payment_method:string,reference_number:string,refund_reference:?string,refund_reason:?string,refund_amount_usd:?string}
     */
    private function rowData(array $row, array $columns): array
    {
        $paymentMethod = $this->paymentMethod($this->text($row[$columns['payment_method']] ?? null));
        if ($paymentMethod === null) {
            throw new RefundImportRowException('payment_method', 'payment_method_invalid');
        }

        $referenceNumber = $this->text($row[$columns['reference_number']] ?? null);
        if ($referenceNumber === '') {
            throw new RefundImportRowException('reference_number', 'reference_number_required');
        }

        $refundReference = $this->nullableText($row[$columns['refund_reference']] ?? null);
        $amount = $this->nullableAmount($row[$columns['refund_amount_usd']] ?? null);
        if ($paymentMethod === 'credit_card') {
            if ($refundReference === null) {
                throw new RefundImportRowException('refund_reference', 'credit_card_reference_required');
            }
            if ($amount === null || (float) $amount <= 0) {
                throw new RefundImportRowException('refund_amount_usd', 'credit_card_amount_required');
            }
        }

        return [
            'payment_method' => $paymentMethod,
            'reference_number' => $referenceNumber,
            'refund_reference' => $refundReference,
            'refund_reason' => $this->nullableText($row[$columns['refund_reason']] ?? null),
            'refund_amount_usd' => $amount,
        ];
    }

    private function paymentMethod(string $value): ?string
    {
        $value = mb_strtolower(trim($value));

        return match ($value) {
            'paypal' => 'paypal',
            'credit_card', 'credit card', '信用卡' => 'credit_card',
            default => null,
        };
    }

    /** @param array<string,mixed> $row
     * @param array<string,string> $columns
     * @return array<string,mixed>
     */
    private function errorRow(int $rowNumber, RefundImportRowException $exception, array $row, array $columns): array
    {
        return [
            ...$this->sourceData($row, $columns),
            'row' => $rowNumber,
            'field' => __('logistics.refund_import.error_fields.'.$exception->field),
            'reason' => __('logistics.refund_import.errors.'.$exception->reason),
            'hint' => __('logistics.refund_import.hints.'.$exception->reason),
        ];
    }

    /** @param array<string,mixed> $row
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    private function sourceData(array $row, array $columns): array
    {
        $data = [];
        foreach (self::HEADERS as $header => $field) {
            $data[$field] = $this->text($row[$columns[$field]] ?? null);
        }

        return $data;
    }

    public function fingerprintFor(array $data): string
    {
        return hash('sha256', implode('|', array_map(static fn (mixed $value): string => mb_strtolower(trim((string) ($value ?? ''))), $data)));
    }

    /** @param array<string,mixed> $row */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->text($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = $this->text($value);

        return $value === '' ? null : $value;
    }

    private function nullableAmount(mixed $value): ?string
    {
        $value = str_replace([',', '$', '¥', '￥', ' '], '', $this->text($value));
        if ($value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new RefundImportRowException('refund_amount_usd', 'amount_invalid');
        }

        return number_format((float) $value, 4, '.', '');
    }

    private function text(mixed $value): string
    {
        return trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) ($value ?? '')) ?? '');
    }
}

class RefundImportRowException extends RuntimeException
{
    public function __construct(public readonly string $field, public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
