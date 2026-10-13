<?php

namespace App\Console\Commands;

use App\Portals\Logistics\PayPalImportPreviewService;
use Illuminate\Console\Command;

class PreviewPayPalImport extends Command
{
    protected $signature = 'logistics:paypal-preview
        {file : PayPal手工导出的JSON文件路径，可使用项目相对路径或绝对路径}
        {--limit=20 : 每类最多展示的明细数量}
        {--unmatched-only : 明细只展示未匹配或账号冲突记录}';

    protected $description = '只读预览PayPal JSON与物流订单的匹配结果，不修改数据库';

    public function handle(PayPalImportPreviewService $previews): int
    {
        try {
            $path = $this->resolvePath((string) $this->argument('file'));
            $payload = $this->readPayload($path);
            $preview = $previews->preview($payload);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $limit = max(1, min(200, (int) $this->option('limit')));
        $unmatchedOnly = (bool) $this->option('unmatched-only');
        $account = $preview['account'];
        $file = $preview['file'];
        $summary = $preview['summary'];

        $this->newLine();
        $this->info('PayPal JSON只读匹配预览');
        $this->line('文件：'.$path);
        $this->line('账号：'.$account['account_name'].' / Merchant ID '.$account['merchant_id']);
        $this->line('账号状态：'.($account['is_enabled'] ? '启用' : '停用'));
        $this->line('环境：'.($file['environment'] ?: '未提供'));
        $this->line('查询区间：'.($file['period_from'] ?: '—').' 至 '.($file['period_to'] ?: '—'));
        $this->line('Upload ID：'.($file['upload_id'] ?: '—'));

        if (!$account['is_enabled']) {
            $this->warn('该PayPal账号当前已停用；本命令仍可预览，但正式导入会被拒绝。');
        }
        foreach ($preview['source_errors'] as $error) {
            $this->warn('源文件异常：'.$error);
        }

        $this->newLine();
        $this->table(['统计项', '数量'], [
            ['全部交易记录', $summary['transactions']],
            ['T00xx付款类事件', $summary['payment_events']],
            ['付款匹配/未匹配', $summary['payment_matched'].' / '.$summary['payment_unmatched']],
            ['退款记录', $summary['refunds']],
            ['退款匹配/未匹配', $summary['refund_matched'].' / '.$summary['refund_unmatched']],
            ['退款已存在', $summary['refund_existing']],
            ['纠纷记录', $summary['disputes']],
            ['纠纷匹配/未匹配', $summary['dispute_matched'].' / '.$summary['dispute_unmatched']],
            ['纠纷已存在', $summary['dispute_existing']],
            ['源文件异常', $summary['source_errors']],
        ]);

        $this->newLine();
        $this->info('交易事件代码分布');
        $this->table(
            ['事件代码', '说明', '数量'],
            array_map(static fn (array $row): array => [
                $row['code'], $row['label'], $row['count'],
            ], $preview['event_distribution']),
        );

        $this->renderRows('付款匹配明细', $preview['payments'], $limit, $unmatchedOnly);
        $this->renderRows('退款匹配明细', $preview['refunds'], $limit, $unmatchedOnly);
        $this->renderRows('纠纷匹配明细', $preview['disputes'], $limit, $unmatchedOnly);

        $this->newLine();
        $this->info('预览完成：本命令没有新增、更新或删除任何数据库记录。');

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path, " \t\n\r\0\x0B\"'");
        if ($path === '') {
            throw new \RuntimeException('JSON文件路径不能为空。');
        }
        $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $candidate = $absolute ? $path : base_path($path);
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            throw new \RuntimeException('找不到JSON文件：'.$candidate);
        }

        return $resolved;
    }

    /** @return array<string,mixed> */
    private function readPayload(string $path): array
    {
        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new \RuntimeException('JSON文件为空或无法读取。');
        }
        if ($size > 200 * 1024 * 1024) {
            throw new \RuntimeException('JSON文件超过200MB，已停止预览。');
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('JSON文件读取失败。');
        }
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('JSON格式无效：'.$exception->getMessage(), 0, $exception);
        }
        if (!is_array($payload)) {
            throw new \RuntimeException('JSON顶层必须是对象。');
        }
        if ((int) ($payload['schema_version'] ?? 0) !== 2) {
            throw new \RuntimeException('仅支持schema_version为2的PayPal原始响应文件。');
        }
        if (!is_array(data_get($payload, 'source_responses.transaction_search.segments'))) {
            throw new \RuntimeException('JSON缺少交易查询原始响应。');
        }
        if (!is_array(data_get($payload, 'source_responses.dispute_search.list_responses'))
            || !is_array(data_get($payload, 'source_responses.dispute_search.detail_responses'))) {
            throw new \RuntimeException('JSON缺少纠纷查询原始响应。');
        }
        if (!isset($payload['errors']) || !is_array($payload['errors'])) {
            throw new \RuntimeException('JSON缺少errors数组。');
        }

        return $payload;
    }

    /** @param list<array<string,mixed>> $rows */
    private function renderRows(string $title, array $rows, int $limit, bool $unmatchedOnly): void
    {
        $allRows = collect($rows);
        if ($unmatchedOnly) {
            $allRows = $allRows->reject(
                static fn (array $row): bool => in_array($row['match_status'] ?? '', ['matched', 'would_associate'], true),
            );
        }
        $visible = $allRows->take($limit)->values();

        $this->newLine();
        $this->info($title.($unmatchedOnly ? '（仅未匹配）' : ''));
        if ($visible->isEmpty()) {
            $this->line('无记录。');

            return;
        }

        $this->table(
            ['事件', '本次交易号/纠纷号', '原交易号', '金额', '订单', '店铺', '结果', '已存在'],
            $visible->map(fn (array $row): array => [
                trim(($row['event_code'] ?? '').' '.($row['event_label'] ?? '')),
                $row['transaction_id'] ?: '—',
                $row['reference_id'] ?: '—',
                trim(($row['currency'] ?? '').' '.($row['amount'] ?? '')) ?: '—',
                $row['shopify_order_number'] ?: '—',
                $row['store_name'] ?: '—',
                $this->statusLabel((string) ($row['match_status'] ?? '')).'：'.($row['match_reason'] ?? ''),
                !empty($row['already_exists']) ? '是' : '否',
            ])->all(),
        );

        if ($allRows->count() > $visible->count()) {
            $this->line(sprintf(
                '当前展示 %d/%d 条；可通过 --limit=%d 调整，最大200条。',
                $visible->count(),
                $allRows->count(),
                min(200, max($limit + 20, 50)),
            ));
        }
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'matched' => '匹配成功',
            'would_associate' => '可匹配（待正式关联）',
            'not_found' => '未找到订单',
            'missing_reference' => '缺少交易号',
            'account_conflict' => '账号归属冲突',
            default => '未知状态',
        };
    }
}
