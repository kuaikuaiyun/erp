<?php

namespace App\Console\Commands;

use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalImport;
use App\Portals\Logistics\PayPalImportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportPayPalJson extends Command
{
    protected $signature = 'logistics:paypal-import
        {file : PayPal 手工导出的 JSON 文件路径，可使用项目相对路径或绝对路径}
        {--force : 相同 upload_id 与相同文件内容已导入时重新处理}';

    protected $description = '把 Windows VPS 导出的 PayPal JSON 正式匹配并写入物流系统';

    public function handle(PayPalImportService $imports): int
    {
        $account = null;
        $import = null;

        try {
            $this->info('正在读取并校验 PayPal JSON 文件……');
            $path = $this->resolvePath((string) $this->argument('file'));
            [$payload, $json] = $this->readPayload($path);
            $account = $this->account($payload);
            $this->line('已识别账号：'.$account->account_name.' / Merchant ID '.$account->merchant_id);
            $uploadId = trim((string) $payload['upload_id']);
            $checksum = hash('sha256', $json);
            $existing = LogisticsPayPalImport::query()->where('upload_id', $uploadId)->first();

            if ($existing && !hash_equals((string) $existing->payload_sha256, $checksum)) {
                throw new \RuntimeException('相同 upload_id 对应了不同文件内容，已拒绝导入。');
            }
            if ($existing && !$this->option('force')) {
                $this->warn('该文件已经导入过，没有重复写入。若确需重新处理，请增加 --force。');
                $this->renderImport($existing);

                return self::SUCCESS;
            }

            $this->line('正在归档原始 JSON 并建立导入记录……');
            $storagePath = $existing?->storage_path ?: $this->archive($payload, $json);
            $period = is_array($payload['period'] ?? null) ? $payload['period'] : [];
            $values = [
                'logistics_paypal_account_id' => $account->getKey(),
                'merchant_id' => mb_strtoupper(trim((string) $payload['merchant_id'])),
                'environment' => $payload['environment'] ?? null,
                'source_file' => Str::limit(basename($path), 255, ''),
                'payload_sha256' => $checksum,
                'storage_path' => $storagePath,
                'period_from' => $this->dateTime($period['from'] ?? null),
                'period_to' => $this->dateTime($period['to'] ?? null),
                'generated_at' => $this->dateTime($payload['generated_at'] ?? null),
                'status' => 'processing',
                'transaction_count' => 0,
                'refund_count' => 0,
                'dispute_count' => 0,
                'matched_count' => 0,
                'unmatched_count' => 0,
                'error_count' => 0,
                'message' => '正在处理手工上传的 PayPal JSON 文件。',
            ];

            if ($existing) {
                $existing->forceFill($values)->save();
                $import = $existing;
            } else {
                $import = LogisticsPayPalImport::query()->create([
                    'upload_id' => $uploadId,
                    ...$values,
                ]);
            }

            $account->forceFill([
                'last_import_status' => 'processing',
                'last_import_message' => '正在处理手工上传的 PayPal JSON 文件。',
            ])->save();

            $summary = $imports->import(
                $account,
                $import,
                $payload,
                function (string $message, int $current, int $total): void {
                    $suffix = $total > 0 ? sprintf('（%d/%d）', $current, $total) : '';
                    $this->line($message.$suffix.'……');
                },
            );

            $this->newLine();
            $this->info('PayPal JSON 已正式导入。');
            $this->line('文件：'.$path);
            $this->line('账号：'.$account->account_name.' / Merchant ID '.$account->merchant_id);
            $this->table(['统计项', '数量'], [
                ['全部交易记录', $summary['transactions']],
                ['付款事件', $summary['payments']],
                ['付款已匹配', $summary['payment_matched']],
                ['付款未匹配', $summary['payment_unmatched']],
                ['退款记录', $summary['refunds']],
                ['纠纷记录', $summary['disputes']],
                ['全部业务记录已匹配', $summary['matched']],
                ['全部业务记录未匹配', $summary['unmatched']],
                ['异常', $summary['errors']],
            ]);
            $this->line($summary['message']);

            return $summary['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $message = Str::limit('手工文件导入失败：'.$exception->getMessage(), 1800);
            try {
                if ($import instanceof LogisticsPayPalImport) {
                    $import->forceFill([
                        'status' => 'failed',
                        'error_count' => max(1, (int) $import->error_count),
                        'message' => $message,
                    ])->save();
                }
                if ($account instanceof LogisticsPayPalAccount) {
                    $account->forceFill([
                        'last_import_status' => 'failed',
                        'last_import_message' => $message,
                    ])->save();
                }
            } catch (\Throwable) {
                // 保留并输出最初的导入异常，避免状态写回失败掩盖根因。
            }
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path, " \t\n\r\0\x0B\"'");
        if ($path === '') {
            throw new \RuntimeException('JSON 文件路径不能为空。');
        }

        $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $candidate = $absolute ? $path : base_path($path);
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            throw new \RuntimeException('找不到 JSON 文件：'.$candidate);
        }

        return $resolved;
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function readPayload(string $path): array
    {
        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new \RuntimeException('JSON 文件为空或无法读取。');
        }
        if ($size > 200 * 1024 * 1024) {
            throw new \RuntimeException('JSON 文件超过 200MB，已停止导入。');
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('JSON 文件读取失败。');
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('JSON 格式无效：'.$exception->getMessage(), 0, $exception);
        }
        if (!is_array($payload)) {
            throw new \RuntimeException('JSON 顶层必须是对象。');
        }

        $uploadId = trim((string) ($payload['upload_id'] ?? ''));
        if ((int) ($payload['schema_version'] ?? 0) !== 2) {
            throw new \RuntimeException('仅支持 schema_version 为 2 的 PayPal 原始响应文件。');
        }
        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $uploadId)) {
            throw new \RuntimeException('JSON 的 upload_id 无效。');
        }
        if (trim((string) ($payload['merchant_id'] ?? '')) === '') {
            throw new \RuntimeException('JSON 缺少 merchant_id。');
        }
        if (!is_array(data_get($payload, 'source_responses.transaction_search.segments'))) {
            throw new \RuntimeException('JSON 缺少交易查询原始响应。');
        }
        if (!is_array(data_get($payload, 'source_responses.dispute_search.list_responses'))
            || !is_array(data_get($payload, 'source_responses.dispute_search.detail_responses'))) {
            throw new \RuntimeException('JSON 缺少纠纷查询原始响应。');
        }
        if (!isset($payload['errors']) || !is_array($payload['errors'])) {
            throw new \RuntimeException('JSON 缺少 errors 数组。');
        }

        return [$payload, $json];
    }

    private function account(array $payload): LogisticsPayPalAccount
    {
        $merchantId = mb_strtoupper(trim((string) $payload['merchant_id']));
        $account = LogisticsPayPalAccount::query()
            ->whereRaw('UPPER(merchant_id) = ?', [$merchantId])
            ->first();
        if (!$account) {
            throw new \RuntimeException('ERP 中不存在 Merchant ID '.$merchantId.' 对应的 PayPal 账号。');
        }
        if (!$account->is_enabled) {
            throw new \RuntimeException('Merchant ID '.$merchantId.' 对应的 PayPal 账号已停用。');
        }

        return $account;
    }

    private function archive(array $payload, string $json): string
    {
        $merchantId = preg_replace(
            '/[^A-Za-z0-9_-]/',
            '_',
            mb_strtoupper(trim((string) $payload['merchant_id'])),
        );
        $uploadId = preg_replace('/[^A-Za-z0-9_-]/', '_', trim((string) $payload['upload_id']));
        $storagePath = sprintf(
            'paypal-imports/manual/%s/%s/%s.json',
            now()->format('Y/m'),
            $merchantId,
            $uploadId,
        );

        if (!Storage::disk('local')->put($storagePath, $json)) {
            throw new \RuntimeException('ERP 无法归档 PayPal JSON 文件。');
        }

        return $storagePath;
    }

    private function dateTime(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function renderImport(LogisticsPayPalImport $import): void
    {
        $this->table(['字段', '值'], [
            ['状态', $import->status],
            ['交易', $import->transaction_count],
            ['退款', $import->refund_count],
            ['纠纷', $import->dispute_count],
            ['已匹配', $import->matched_count],
            ['未匹配', $import->unmatched_count],
            ['异常', $import->error_count],
            ['结果', $import->message ?: '—'],
        ]);
    }
}
