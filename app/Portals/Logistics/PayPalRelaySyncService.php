<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fetches a complete Schema v2 payload from the VPS before invoking the
 * existing PayPalImportService. It must not change matching or persistence
 * behaviour after the payload enters the ERP.
 */
final class PayPalRelaySyncService
{
    public function __construct(
        private readonly PayPalRelayClient $relay,
        private readonly PayPalImportService $imports,
    ) {
    }

    /** @return array{total:int,succeeded:int,failed:int,skipped:int,results:list<array<string,mixed>>} */
    public function syncAll(?int $onlyAccountId = null, ?CarbonImmutable $now = null, bool $dryRun = false): array
    {
        $query = LogisticsPayPalAccount::query()->where('is_enabled', true)->orderBy('id');
        if ($onlyAccountId !== null) {
            $query->whereKey($onlyAccountId);
        }

        $results = [];
        foreach ($query->get() as $account) {
            if (! $this->relay->isConfigured($account)) {
                $results[] = $this->result($account, false, 'skipped', 'VPS 中转配置不完整，保留旧推送方式。');
                continue;
            }
            $results[] = $this->sync($account, $now, $dryRun);
        }

        return [
            'total' => count($results),
            'succeeded' => count(array_filter($results, fn (array $result): bool => $result['status'] === 'success')),
            'failed' => count(array_filter($results, fn (array $result): bool => $result['status'] === 'failed')),
            'skipped' => count(array_filter($results, fn (array $result): bool => $result['status'] === 'skipped')),
            'results' => $results,
        ];
    }

    /** @return array<string,mixed> */
    public function sync(LogisticsPayPalAccount $account, ?CarbonImmutable $now = null, bool $dryRun = false): array
    {
        if (! $this->relay->isConfigured($account)) {
            return $this->result($account, false, 'skipped', 'VPS 中转配置不完整，保留旧推送方式。');
        }

        $lock = Cache::lock('logistics:paypal-relay-sync:'.$account->getKey(), 7200);
        if (! $lock->get()) {
            return $this->result($account, false, 'skipped', '该账户已有 PayPal VPS 同步正在运行。');
        }

        try {
            $to = ($now ?? CarbonImmutable::now('UTC'))->utc()->startOfSecond();
            [$from, $to] = $this->range($account, $to);

            // Every transport, pagination and dispute-detail check happens before
            // an import row, a storage file, or any business record is created.
            try {
                $payload = $this->collectPayload($account, $from, $to);
            } catch (PayPalRelayException $exception) {
                return $this->result($account, false, 'failed', $this->relayMessage($exception), $from, $to);
            } catch (\Throwable) {
                return $this->result($account, false, 'failed', 'PayPal VPS 数据采集异常，未写入任何 ERP 数据。', $from, $to);
            }

            if ($dryRun) {
                return $this->result(
                    $account,
                    true,
                    'success',
                    'PayPal VPS 数据完整性校验通过；预检模式未写入 ERP 数据。',
                    $from,
                    $to,
                    null,
                    [
                        'transactions' => $payload['collection_summary']['transactions'],
                        'disputes' => $payload['collection_summary']['disputes'],
                    ],
                );
            }

            return $this->importPayload($account, $payload, $from, $to);
        } finally {
            $lock->release();
        }
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function range(LogisticsPayPalAccount $account, CarbonImmutable $to): array
    {
        $overlapDays = $this->bounded('overlap_days', 1, 1, 30);
        // A relay-specific source file is the durable switch marker. Existing
        // push imports must not turn a first relay run into a long backfill.
        $lastRelayTo = LogisticsPayPalImport::query()
            ->where('logistics_paypal_account_id', $account->getKey())
            ->where('source_file', 'like', 'paypal-relay-%')
            ->where('status', 'success')
            ->max('period_to');
        $lastTo = $lastRelayTo
            ? CarbonImmutable::parse($lastRelayTo, 'UTC')
            : null;

        if ($lastTo !== null) {
            $from = $lastTo->subDays($overlapDays);
        } elseif ($account->last_imported_at !== null) {
            $from = $to->subDays($this->bounded('existing_account_initial_days', 1, 1, 30));
        } else {
            $from = $to->subDays($this->bounded('initial_days', 90, 1, 180));
        }

        return [$from->min($to)->startOfSecond(), $to];
    }

    /** @return array<string,mixed> */
    private function collectPayload(LogisticsPayPalAccount $account, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$segments, $transactionCount] = $this->collectTransactions($account, $from, $to);
        [$disputes, $disputeCount] = $this->collectDisputes($account, $from, $to);

        return [
            'schema_version' => 2,
            'upload_id' => 'relay-'.str_replace('-', '', (string) Str::uuid()),
            'merchant_id' => mb_strtoupper(trim((string) $account->merchant_id)),
            'environment' => in_array($account->environment, ['live', 'sandbox'], true) ? $account->environment : 'live',
            'generated_at' => CarbonImmutable::now('UTC')->format('Y-m-d\\TH:i:s\\Z'),
            'period' => [
                'from' => $from->format('Y-m-d\\TH:i:s\\Z'),
                'to' => $to->format('Y-m-d\\TH:i:s\\Z'),
            ],
            'source_responses' => [
                'transaction_search' => ['segments' => $segments],
                'dispute_search' => $disputes,
            ],
            'collection_summary' => [
                'transactions' => $transactionCount,
                'disputes' => $disputeCount,
                'errors' => 0,
            ],
            'errors' => [],
            'collector' => ['name' => 'ERP PayPal VPS relay pull', 'version' => '1.0'],
        ];
    }

    /** @return array{0:list<array<string,mixed>>,1:int} */
    private function collectTransactions(LogisticsPayPalAccount $account, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $segmentDays = $this->bounded('transaction_segment_days', 30, 1, 30);
        $pageSize = $this->bounded('transaction_page_size', 500, 1, 500);
        $maxPages = $this->bounded('max_transaction_pages', 1000, 1, 1000);
        $segments = [];
        $count = 0;
        $start = $from;
        $number = 1;

        while ($start->lessThanOrEqualTo($to)) {
            $end = $start->addDays($segmentDays)->subSecond()->min($to);
            // The range is inclusive at second precision. When an initial
            // window is an exact multiple of the segment length, the three
            // normal segments otherwise leave only the final endpoint second.
            // Keep that endpoint in the current segment instead of issuing a
            // separate one-second upstream request.
            if ($end->addSecond()->equalTo($to)) {
                $end = $to;
            }
            $base = [
                'start_date' => $start->format('Y-m-d\\TH:i:s\\Z'),
                'end_date' => $end->format('Y-m-d\\TH:i:s\\Z'),
                'fields' => 'all',
                'balance_affecting_records_only' => 'N',
                'page_size' => (string) $pageSize,
            ];
            $responses = [];
            $totalPages = null;
            $expectedItems = null;
            $receivedItems = 0;

            for ($page = 1; $page <= $maxPages; $page++) {
                $payload = $this->relay->read($account, 'transaction_search', [...$base, 'page' => (string) $page])['payload'];
                $rows = $payload['transaction_details'] ?? null;
                $pages = filter_var($payload['total_pages'] ?? null, FILTER_VALIDATE_INT);
                $returnedPage = filter_var($payload['page'] ?? null, FILTER_VALIDATE_INT);
                $items = filter_var($payload['total_items'] ?? null, FILTER_VALIDATE_INT);
                if (! array_key_exists('transaction_details', $payload) && $pages === 0 && $items === 0) {
                    $rows = [];
                }
                if (! is_array($rows) || ! array_is_list($rows) || count($rows) > $pageSize
                    || $pages === false || $returnedPage !== $page || $pages < 0
                    || $items === false || $items < 0
                    || ($expectedItems !== null && $expectedItems !== $items)
                    || ($totalPages !== null && $totalPages !== max(1, $pages))
                    || ($pages === 0 && ($rows !== [] || $items !== 0))
                    || ($pages > 0 && $page > $pages) || ($page < $pages && $rows === [])) {
                    throw new PayPalRelayException('pagination');
                }
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        throw new PayPalRelayException('payload');
                    }
                }
                $totalPages = max(1, $pages);
                $expectedItems = $items;
                $receivedItems += count($rows);
                if ($receivedItems > $expectedItems || ($page === $totalPages && $receivedItems !== $expectedItems)) {
                    throw new PayPalRelayException('pagination');
                }
                $responses[] = $payload;
                if ($page === $totalPages) {
                    break;
                }
            }

            if ($totalPages === null || count($responses) !== $totalPages || $receivedItems !== $expectedItems) {
                throw new PayPalRelayException('pagination');
            }
            $segments[] = ['segment' => $number++, 'request' => $base, 'responses' => $responses];
            $count += $receivedItems;
            $start = $end->addSecond();
        }

        return [$segments, $count];
    }

    /** @return array{0:array<string,mixed>,1:int} */
    private function collectDisputes(LogisticsPayPalAccount $account, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $segmentDays = $this->bounded('dispute_segment_days', 7, 1, 30);
        $pageSize = $this->bounded('dispute_page_size', 50, 1, 100);
        $maxPages = $this->bounded('max_dispute_pages', 100, 1, 1000);
        $requests = [];
        $listResponses = [];
        $disputeIds = [];
        $start = $from;

        while ($start->lessThanOrEqualTo($to)) {
            $end = $start->addDays($segmentDays)->subSecond()->min($to);
            $base = [
                'update_time_after' => $start->format('Y-m-d\\TH:i:s\\Z'),
                'update_time_before' => $end->format('Y-m-d\\TH:i:s\\Z'),
                'page_size' => (string) $pageSize,
            ];
            $requests[] = $base;
            $finished = false;

            for ($page = 1; $page <= $maxPages; $page++) {
                $payload = $this->relay->read($account, 'dispute_search', [...$base, 'page' => (string) $page])['payload'];
                $items = $payload['items'] ?? null;
                if (! is_array($items) || ! array_is_list($items)) {
                    throw new PayPalRelayException('payload');
                }
                foreach ($items as $item) {
                    if (! is_array($item) || trim((string) ($item['dispute_id'] ?? '')) === '') {
                        throw new PayPalRelayException('payload');
                    }
                    $disputeIds[trim((string) $item['dispute_id'])] = true;
                }
                $listResponses[] = $payload;
                $hasNext = $this->hasNextPage($payload['links'] ?? null);
                if (! $hasNext) {
                    $finished = true;
                    break;
                }
                if ($items === []) {
                    throw new PayPalRelayException('pagination');
                }
            }
            if (! $finished) {
                throw new PayPalRelayException('pagination');
            }
            $start = $end->addSecond();
        }

        $detailResponses = [];
        foreach (array_keys($disputeIds) as $disputeId) {
            $payload = $this->relay->read($account, 'dispute_detail', ['dispute_id' => $disputeId])['payload'];
            if (trim((string) ($payload['dispute_id'] ?? '')) !== $disputeId || ! is_array($payload['disputed_transactions'] ?? null)) {
                throw new PayPalRelayException('payload');
            }
            $detailResponses[] = ['dispute_id' => $disputeId, 'response' => $payload];
        }

        return [[
            'requests' => $requests,
            'list_responses' => $listResponses,
            'detail_responses' => $detailResponses,
        ], count($disputeIds)];
    }

    /** @return array<string,mixed> */
    private function importPayload(LogisticsPayPalAccount $account, array $payload, CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $uploadId = (string) $payload['upload_id'];
            $merchantId = mb_strtoupper(trim((string) $payload['merchant_id']));
            $storagePath = sprintf(
                'paypal-imports/%s/%s/%s.json',
                now()->format('Y/m'),
                preg_replace('/[^A-Za-z0-9_-]/', '_', $merchantId),
                preg_replace('/[^A-Za-z0-9_-]/', '_', $uploadId),
            );
            if (! Storage::disk('local')->put($storagePath, $json)) {
                throw new \RuntimeException('storage');
            }
        } catch (\Throwable) {
            return $this->result($account, false, 'failed', 'PayPal VPS 数据已校验，但 ERP 无法归档本轮数据，未写入业务数据。', $from, $to);
        }

        $import = LogisticsPayPalImport::query()->create([
            'logistics_paypal_account_id' => $account->getKey(),
            'upload_id' => $uploadId,
            'merchant_id' => $merchantId,
            'environment' => $payload['environment'],
            'source_file' => 'paypal-relay-'.$uploadId.'.json',
            'payload_sha256' => hash('sha256', $json),
            'storage_path' => $storagePath,
            'period_from' => $from,
            'period_to' => $to,
            'generated_at' => CarbonImmutable::parse((string) $payload['generated_at'], 'UTC'),
            'status' => 'processing',
            'transaction_count' => 0,
            'refund_count' => 0,
            'dispute_count' => 0,
            'matched_count' => 0,
            'unmatched_count' => 0,
            'error_count' => 0,
            'message' => '正在处理 ERP 从 VPS 拉取的 PayPal 数据。',
        ]);
        $account->forceFill([
            'last_import_status' => 'processing',
            'last_import_message' => '正在处理 ERP 从 VPS 拉取的 PayPal 数据。',
        ])->save();

        try {
            // This is intentionally the unchanged legacy import path. New pull
            // code must never own order association or downstream persistence.
            $summary = $this->imports->import($account, $import, $payload);
        } catch (\Throwable $exception) {
            $message = Str::limit('VPS 拉取结果导入失败：'.$exception->getMessage(), 1800);
            $import->forceFill(['status' => 'failed', 'error_count' => 1, 'message' => $message])->save();
            $account->forceFill(['last_import_status' => 'failed', 'last_import_message' => $message])->save();

            return $this->result($account, false, 'failed', $message, $from, $to, $import->getKey());
        }

        $status = (string) ($summary['status'] ?? 'failed');
        return $this->result(
            $account,
            $status === 'success',
            $status === 'success' ? 'success' : 'failed',
            (string) ($summary['message'] ?? 'PayPal VPS 拉取已完成。'),
            $from,
            $to,
            $import->getKey(),
            $summary,
        );
    }

    private function hasNextPage(mixed $links): bool
    {
        if (! is_array($links)) {
            return false;
        }
        foreach ($links as $link) {
            if (is_array($link) && strtolower(trim((string) ($link['rel'] ?? ''))) === 'next') {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function result(
        LogisticsPayPalAccount $account,
        bool $ok,
        string $status,
        string $message,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?int $importId = null,
        array $summary = [],
    ): array {
        return [
            'ok' => $ok,
            'status' => $status,
            'account_id' => (int) $account->getKey(),
            'account_name' => (string) $account->account_name,
            'from' => $from?->format('Y-m-d\\TH:i:s\\Z'),
            'to' => $to?->format('Y-m-d\\TH:i:s\\Z'),
            'import_id' => $importId,
            'message' => $message,
            'transactions' => (int) ($summary['transactions'] ?? 0),
            'refunds' => (int) ($summary['refunds'] ?? 0),
            'disputes' => (int) ($summary['disputes'] ?? 0),
            'errors' => (int) ($summary['errors'] ?? 0),
        ];
    }

    private function relayMessage(PayPalRelayException $exception): string
    {
        return match ($exception->reason) {
            'configuration', 'secret_encoding' => 'PayPal VPS 中转配置无效，未写入任何 ERP 数据。',
            'relay_unauthorized', 'relay_forbidden' => 'PayPal VPS 中转服务拒绝访问，未写入任何 ERP 数据。',
            'signature' => 'PayPal VPS 响应验签失败，未写入任何 ERP 数据。',
            'pagination' => 'PayPal VPS 分页数据不完整，未写入任何 ERP 数据。',
            'payload' => 'PayPal VPS 返回数据格式不完整，未写入任何 ERP 数据。',
            'size' => 'PayPal VPS 返回数据超过安全限制，未写入任何 ERP 数据。',
            default => 'PayPal VPS 请求失败，未写入任何 ERP 数据。',
        };
    }

    private function bounded(string $key, int $default, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, (int) config('logistics.paypal.relay.'.$key, $default)));
    }
}
