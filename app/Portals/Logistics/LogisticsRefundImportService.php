<?php

namespace App\Portals\Logistics;

use App\Models\AdminUser;
use App\Models\LogisticsOrder;
use App\Models\LogisticsRefundDetail;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class LogisticsRefundImportService
{
    private const HEADERS = [
        '退款单号' => 'refund_number',
        '退款金额' => 'refund_amount_usd',
        '退款类型' => 'refund_type',
        '退款原因' => 'refund_reason',
        '备注' => 'remark',
    ];

    /**
     * @return array{rows:int,added:int,duplicates:int,matched:int,unmatched:int,invalid:int,errors:list<string>}
     */
    public function import(UploadedFile $file, AdminUser $actor): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new RuntimeException('仅支持导入 xlsx、xls、csv 格式。');
        }

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            $spreadsheet->disconnectWorksheets();
        } catch (\Throwable $exception) {
            throw new RuntimeException('退款表格读取失败：'.$exception->getMessage(), previous: $exception);
        }

        $summary = ['rows' => 0, 'added' => 0, 'duplicates' => 0, 'matched' => 0, 'unmatched' => 0, 'invalid' => 0, 'errors' => []];
        [$headerRow, $columns] = $this->locateHeaders($rows);

        foreach ($rows as $rowNumber => $row) {
            if ((int) $rowNumber <= $headerRow || $this->isEmptyRow($row)) {
                continue;
            }
            $summary['rows']++;

            try {
                $data = $this->rowData($row, $columns);
                $order = LogisticsOrder::query()->where('bill_number', $data['refund_number'])->first();
                $fingerprint = $this->fingerprintFor($data);
                $existing = LogisticsRefundDetail::query()->where('fingerprint', $fingerprint)->first();

                if ($existing) {
                    if (!$existing->logistics_order_id && $order) {
                        $existing->forceFill(['logistics_order_id' => $order->getKey()])->save();
                    }
                    $summary['duplicates']++;
                    continue;
                }

                LogisticsRefundDetail::query()->create([
                    ...$data,
                    'logistics_order_id' => $order?->getKey(),
                    'fingerprint' => $fingerprint,
                    'is_void' => false,
                    'imported_by' => $actor->getKey(),
                    'imported_by_name' => $actor->name,
                ]);
                $summary['added']++;
                $summary[$order ? 'matched' : 'unmatched']++;
            } catch (\Throwable $exception) {
                $summary['invalid']++;
                if (count($summary['errors']) < 30) {
                    $summary['errors'][] = '第 '.$rowNumber.' 行：'.$exception->getMessage();
                }
            }
        }

        if ($summary['rows'] === 0) {
            throw new RuntimeException('表格没有可导入的退款明细。');
        }

        return $summary;
    }

    public function attachUnmatchedForOrder(LogisticsOrder $order): int
    {
        $billNumber = trim((string) $order->bill_number);
        if ($billNumber === '') {
            return 0;
        }

        return LogisticsRefundDetail::query()
            ->whereNull('logistics_order_id')
            ->where('refund_number', $billNumber)
            ->update(['logistics_order_id' => $order->getKey(), 'updated_at' => now()]);
    }

    /** @param array<int|string, array<string, mixed>> $rows
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

        throw new RuntimeException('表头必须包含：'.implode('、', array_keys(self::HEADERS)).'。');
    }

    /** @param array<string, mixed> $row
     * @param array<string, string> $columns
     * @return array{refund_number:string,refund_amount_usd:string,refund_type:?string,refund_reason:?string,remark:?string}
     */
    private function rowData(array $row, array $columns): array
    {
        $refundNumber = $this->text($row[$columns['refund_number']] ?? null);
        if ($refundNumber === '') {
            throw new RuntimeException('退款单号不能为空。');
        }

        $amountText = str_replace([',', '$', '￥', '¥', ' '], '', $this->text($row[$columns['refund_amount_usd']] ?? null));
        if ($amountText === '' || !is_numeric($amountText)) {
            throw new RuntimeException('退款金额必须是数字。');
        }
        $amount = abs((float) $amountText);
        if ($amount <= 0) {
            throw new RuntimeException('退款金额必须大于 0。');
        }

        return [
            'refund_number' => $refundNumber,
            'refund_amount_usd' => number_format($amount, 4, '.', ''),
            'refund_type' => $this->nullableText($row[$columns['refund_type']] ?? null),
            'refund_reason' => $this->nullableText($row[$columns['refund_reason']] ?? null),
            'remark' => $this->nullableText($row[$columns['remark']] ?? null),
        ];
    }

    /** @param array<string, mixed> $data */
    public function fingerprintFor(array $data): string
    {
        return hash('sha256', implode('|', [
            mb_strtolower(trim((string) $data['refund_number'])),
            number_format((float) $data['refund_amount_usd'], 4, '.', ''),
            mb_strtolower(trim((string) ($data['refund_type'] ?? ''))),
            mb_strtolower(trim((string) ($data['refund_reason'] ?? ''))),
            mb_strtolower(trim((string) ($data['remark'] ?? ''))),
        ]));
    }

    /** @param array<string, mixed> $row */
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

    private function text(mixed $value): string
    {
        return trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) ($value ?? '')) ?? '');
    }
}
