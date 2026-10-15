<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalDisputeRefund;
use App\Models\LogisticsPayPalRefund;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PayPalSyncService
{
    private const REFUND_EVENT_CODES = ['T1107', 'T1115', 'T1120'];

    public function __construct(private readonly PayPalOrderAssociationService $associations)
    {
    }

    /**
     * @return array{
     *     refunds:int,
     *     disputes:int,
     *     matched_refunds:int,
     *     matched_disputes:int,
     *     unmatched:int,
     *     from:string,
     *     to:string,
     *     dispute_from:string,
     *     status:string,
     *     refund_error:?string,
     *     dispute_error:?string,
     *     message:string
     * }
     */
    public function sync(LogisticsPayPalAccount $account, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        if (!$account->is_enabled) {
            throw new RuntimeException('该 PayPal 账号已停用，不能执行同步。');
        }

        [$from, $to] = $this->syncRange($account, $dateFrom, $dateTo);
        $account->forceFill([
            'last_sync_started_at' => now(),
            'last_sync_status' => 'processing',
            'last_sync_message' => '正在同步 PayPal 退款与纠纷数据。',
            'last_sync_from' => $from,
            'last_sync_to' => $to,
        ])->save();

        try {
            $token = $this->accessToken($account);
            $disputeFrom = $from->greaterThan(now()->subDays(179)->startOfDay())
                ? $from->copy()
                : now()->subDays(179)->startOfDay();
            $refunds = ['total' => 0, 'matched' => 0, 'unmatched' => 0];
            $disputes = ['total' => 0, 'matched' => 0, 'unmatched' => 0];
            $refundError = null;
            $disputeError = null;

            try {
                $refunds = $this->syncRefunds($account, $token, $from, $to);
            } catch (\Throwable $exception) {
                $refundError = Str::limit($exception->getMessage(), 800);
            }

            if ($disputeFrom->lessThanOrEqualTo($to)) {
                try {
                    $disputes = $this->syncDisputes($account, $token, $disputeFrom, $to);
                } catch (\Throwable $exception) {
                    $disputeError = Str::limit($exception->getMessage(), 800);
                }
            }

            $failedParts = array_filter([
                '退款' => $refundError,
                '纠纷' => $disputeError,
            ]);
            $status = $failedParts === [] ? 'success' : (count($failedParts) === 2 ? 'failed' : 'partial');

            $summary = [
                'refunds' => $refunds['total'],
                'disputes' => $disputes['total'],
                'matched_refunds' => $refunds['matched'],
                'matched_disputes' => $disputes['matched'],
                'unmatched' => $refunds['unmatched'] + $disputes['unmatched'],
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
                'dispute_from' => $disputeFrom->format('Y-m-d H:i:s'),
                'status' => $status,
                'refund_error' => $refundError,
                'dispute_error' => $disputeError,
            ];
            $message = sprintf(
                '%s：退款 %d 条（匹配 %d 条），纠纷 %d 条（匹配 %d 条），未匹配 %d 条。',
                $status === 'success' ? '同步完成' : ($status === 'partial' ? '同步部分完成' : '同步失败'),
                $summary['refunds'],
                $summary['matched_refunds'],
                $summary['disputes'],
                $summary['matched_disputes'],
                $summary['unmatched'],
            );
            if ($failedParts !== []) {
                $message .= ' '.collect($failedParts)
                    ->map(static fn (string $error, string $part): string => $part.'同步失败：'.$error)
                    ->implode('；');
            }
            $summary['message'] = $message;

            $account->forceFill([
                // 只有两个数据源均成功时才推进增量游标，防止权限恢复后漏同步历史数据。
                'last_synced_at' => $status === 'success' ? now() : $account->last_synced_at,
                'last_sync_status' => $status,
                'last_sync_message' => Str::limit($message, 1800),
            ])->save();

            if ($status === 'failed') {
                throw new RuntimeException($message);
            }

            return $summary;
        } catch (\Throwable $exception) {
            $message = Str::limit($exception->getMessage(), 1800);
            $account->forceFill([
                'last_sync_status' => 'failed',
                'last_sync_message' => $message,
            ])->save();

            throw new RuntimeException($message, previous: $exception);
        }
    }

    private function accessToken(LogisticsPayPalAccount $account): string
    {
        if ($account->hasReusableToken()) {
            return (string) $account->access_token;
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->withBasicAuth((string) $account->client_id, (string) $account->client_secret)
                ->timeout($this->timeout())
                ->post($account->apiBaseUrl().'/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('无法连接 PayPal 获取访问令牌：'.$exception->getMessage(), previous: $exception);
        }

        $this->assertSuccessful($response, 'PayPal 获取访问令牌失败');
        $token = trim((string) $response->json('access_token'));
        if ($token === '') {
            throw new RuntimeException('PayPal 未返回访问令牌，请检查 Client ID 和 Client Secret。');
        }

        $account->forceFill([
            'access_token' => $token,
            'token_expires_at' => now()->addSeconds(max(60, (int) $response->json('expires_in', 300)) - 30),
            'granted_scopes' => trim((string) $response->json('scope')),
        ])->save();

        return $token;
    }

    /** @return array{total:int,matched:int,unmatched:int} */
    private function syncRefunds(
        LogisticsPayPalAccount $account,
        string $token,
        Carbon $from,
        Carbon $to,
    ): array {
        $summary = ['total' => 0, 'matched' => 0, 'unmatched' => 0];
        foreach ($this->dateSegments($from, $to) as [$segmentFrom, $segmentTo]) {
            $page = 1;
            do {
                $response = $this->get($account, $token, '/v1/reporting/transactions', [
                    'start_date' => $segmentFrom->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'end_date' => $segmentTo->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'fields' => 'all',
                    'page_size' => max(1, min(500, (int) config('logistics.paypal.transaction_page_size', 500))),
                    'page' => $page,
                ], 'PayPal 交易记录查询失败');

                $details = $response->json('transaction_details', []);
                if (!is_array($details)) {
                    $details = [];
                }
                foreach ($details as $detail) {
                    if (!is_array($detail) || !in_array((string) Arr::get($detail, 'transaction_info.transaction_event_code'), self::REFUND_EVENT_CODES, true)) {
                        continue;
                    }
                    $matched = $this->persistRefund($account, $detail);
                    $summary['total']++;
                    $summary[$matched ? 'matched' : 'unmatched']++;
                }

                $totalPages = max(1, (int) $response->json('total_pages', 1));
                $page++;
            } while ($page <= $totalPages && $page <= 100);
        }

        return $summary;
    }

    private function persistRefund(LogisticsPayPalAccount $account, array $detail): bool
    {
        $info = is_array($detail['transaction_info'] ?? null) ? $detail['transaction_info'] : [];
        $refundId = trim((string) ($info['transaction_id'] ?? ''));
        if ($refundId === '') {
            return false;
        }

        $originalTransactionId = trim((string) ($info['paypal_reference_id'] ?? ''));
        $order = $this->associations->orderForTransaction($account, $originalTransactionId);
        $amount = abs((float) Arr::get($info, 'transaction_amount.value', 0));
        $currency = strtoupper(trim((string) Arr::get($info, 'transaction_amount.currency_code')));

        $refund = LogisticsPayPalRefund::query()->updateOrCreate([
            'logistics_paypal_account_id' => $account->getKey(),
            'refund_transaction_id' => $refundId,
        ], [
            'logistics_order_id' => $order?->getKey(),
            'original_transaction_id' => $originalTransactionId ?: null,
            'event_code' => $info['transaction_event_code'] ?? null,
            'status' => $info['transaction_status'] ?? null,
            'refund_amount_usd' => $currency === 'USD' ? $amount : null,
            'original_amount' => $amount,
            'currency_code' => $currency ?: null,
            'invoice_id' => $info['invoice_id'] ?? null,
            'refund_reason' => $info['transaction_subject'] ?? $info['transaction_note'] ?? null,
            'refunded_at' => $this->dateTime($info['transaction_initiation_date'] ?? null),
            'raw_payload' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'synced_at' => now(),
        ]);
        $this->linkRefundToDisputes($refund);

        return (bool) $order;
    }

    /** @return array{total:int,matched:int,unmatched:int} */
    private function syncDisputes(
        LogisticsPayPalAccount $account,
        string $token,
        Carbon $from,
        Carbon $to,
    ): array {
        $summary = ['total' => 0, 'matched' => 0, 'unmatched' => 0];
        $page = 1;
        do {
            $response = $this->get($account, $token, '/v1/customer/disputes', [
                'update_time_after' => $from->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'update_time_before' => $to->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'page_size' => max(1, min(50, (int) config('logistics.paypal.dispute_page_size', 50))),
                'page' => $page,
            ], 'PayPal 纠纷列表查询失败');
            $items = $response->json('items', []);
            if (!is_array($items)) {
                $items = [];
            }

            foreach ($items as $item) {
                if (!is_array($item) || blank($item['dispute_id'] ?? null)) {
                    continue;
                }
                $detailResponse = $this->get(
                    $account,
                    $token,
                    '/v1/customer/disputes/'.rawurlencode((string) $item['dispute_id']),
                    [],
                    'PayPal 纠纷详情查询失败',
                );
                $detail = $detailResponse->json();
                if (!is_array($detail)) {
                    $detail = $item;
                }
                $matched = $this->persistDispute($account, [...$item, ...$detail]);
                $summary['total']++;
                $summary[$matched ? 'matched' : 'unmatched']++;
            }

            $hasNext = collect($response->json('links', []))->contains(
                static fn ($link): bool => is_array($link) && ($link['rel'] ?? null) === 'next',
            );
            $page++;
        } while ($hasNext && $page <= 50);

        return $summary;
    }

    private function persistDispute(LogisticsPayPalAccount $account, array $detail): bool
    {
        $disputeId = trim((string) ($detail['dispute_id'] ?? ''));
        if ($disputeId === '') {
            return false;
        }

        $transactions = is_array($detail['disputed_transactions'] ?? null) ? $detail['disputed_transactions'] : [];
        $transaction = is_array($transactions[0] ?? null) ? $transactions[0] : [];
        $transactionId = $this->firstFilled([
            $transaction['seller_transaction_id'] ?? null,
            $transaction['transaction_id'] ?? null,
            $transaction['buyer_transaction_id'] ?? null,
        ]);
        $order = $this->associations->orderForTransaction($account, $transactionId);
        $amount = abs((float) Arr::get($detail, 'dispute_amount.value', 0));
        $currency = strtoupper(trim((string) Arr::get($detail, 'dispute_amount.currency_code')));

        $dispute = LogisticsPayPalDispute::query()->updateOrCreate([
            'logistics_paypal_account_id' => $account->getKey(),
            'dispute_id' => $disputeId,
        ], [
            'logistics_order_id' => $order?->getKey(),
            'disputed_transaction_id' => $transactionId ?: null,
            'reason' => $detail['reason'] ?? null,
            'status' => $detail['status'] ?? null,
            'dispute_state' => $detail['dispute_state'] ?? null,
            'life_cycle_stage' => $detail['dispute_life_cycle_stage'] ?? null,
            'channel' => $detail['dispute_channel'] ?? null,
            'dispute_amount_usd' => $currency === 'USD' ? $amount : null,
            'original_amount' => $amount,
            'currency_code' => $currency ?: null,
            'dispute_created_at' => $this->dateTime($detail['create_time'] ?? null),
            'dispute_updated_at' => $this->dateTime($detail['update_time'] ?? null),
            'seller_response_due_at' => $this->dateTime($detail['seller_response_due_date'] ?? null),
            'outcome_code' => Arr::get($detail, 'dispute_outcome.outcome_code'),
            'raw_payload' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'synced_at' => now(),
        ]);
        $this->linkDisputeToRefunds($dispute);

        return (bool) $order;
    }

    private function linkRefundToDisputes(LogisticsPayPalRefund $refund): void
    {
        $originalTransactionId = trim((string) $refund->original_transaction_id);
        if ($originalTransactionId === '') {
            return;
        }

        LogisticsPayPalDispute::query()
            ->where('logistics_paypal_account_id', $refund->logistics_paypal_account_id)
            ->where('disputed_transaction_id', $originalTransactionId)
            ->get(['id'])
            ->each(function (LogisticsPayPalDispute $dispute) use ($refund, $originalTransactionId): void {
                LogisticsPayPalDisputeRefund::query()->updateOrCreate([
                    'logistics_paypal_dispute_id' => $dispute->getKey(),
                    'logistics_paypal_refund_id' => $refund->getKey(),
                ], [
                    'match_source' => 'original_transaction',
                    'raw_reference' => $originalTransactionId,
                    'matched_at' => now(),
                ]);
            });
    }

    private function linkDisputeToRefunds(LogisticsPayPalDispute $dispute): void
    {
        $transactionId = trim((string) $dispute->disputed_transaction_id);
        if ($transactionId === '') {
            return;
        }

        LogisticsPayPalRefund::query()
            ->where('logistics_paypal_account_id', $dispute->logistics_paypal_account_id)
            ->where('original_transaction_id', $transactionId)
            ->get(['id'])
            ->each(function (LogisticsPayPalRefund $refund) use ($dispute, $transactionId): void {
                LogisticsPayPalDisputeRefund::query()->updateOrCreate([
                    'logistics_paypal_dispute_id' => $dispute->getKey(),
                    'logistics_paypal_refund_id' => $refund->getKey(),
                ], [
                    'match_source' => 'original_transaction',
                    'raw_reference' => $transactionId,
                    'matched_at' => now(),
                ]);
            });
    }

    private function get(
        LogisticsPayPalAccount $account,
        string $token,
        string $path,
        array $query,
        string $label,
    ): Response {
        try {
            $request = Http::acceptJson()
                ->withToken($token)
                ->timeout($this->timeout());
            $response = $request->get($account->apiBaseUrl().$path, $query);
            if ($response->status() === 401) {
                $account->forceFill([
                    'access_token' => null,
                    'token_expires_at' => null,
                ])->save();
                $response = Http::acceptJson()
                    ->withToken($this->accessToken($account))
                    ->timeout($this->timeout())
                    ->get($account->apiBaseUrl().$path, $query);
            }
        } catch (ConnectionException $exception) {
            throw new RuntimeException($label.'：'.$exception->getMessage(), previous: $exception);
        }

        $this->assertSuccessful($response, $label);

        return $response;
    }

    private function assertSuccessful(Response $response, string $label): void
    {
        if ($response->successful()) {
            return;
        }

        $name = trim((string) $response->json('name'));
        $message = trim((string) $response->json('message'));
        $details = collect($response->json('details', []))
            ->filter(fn ($detail): bool => is_array($detail))
            ->map(fn (array $detail): string => trim((string) ($detail['description'] ?? $detail['issue'] ?? '')))
            ->filter()
            ->implode('；');
        $body = trim(implode('：', array_filter([$name, $message])));
        if ($details !== '') {
            $body .= ($body !== '' ? '；' : '').$details;
        }
        if ($body === '') {
            $body = Str::limit($response->body(), 1000);
        }

        throw new RuntimeException($label.'（HTTP '.$response->status().'）：'.$body);
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function syncRange(LogisticsPayPalAccount $account, ?string $dateFrom, ?string $dateTo): array
    {
        $timezone = 'Asia/Shanghai';
        if ($dateFrom && $dateTo) {
            $from = Carbon::parse($dateFrom, $timezone)->startOfDay();
            $to = Carbon::parse($dateTo, $timezone)->endOfDay();
            if ($to->isFuture()) {
                $to = now($timezone);
            }
        } elseif ($account->last_synced_at) {
            // PayPal 交易报表最长可能延迟约三小时；保留四小时重叠窗口，并依靠唯一键安全去重。
            $from = $account->last_synced_at->copy()->timezone($timezone)->subHours(4);
            $to = now($timezone);
        } else {
            $days = max(1, (int) config('logistics.paypal.initial_sync_days', 30));
            $from = now($timezone)->subDays($days - 1)->startOfDay();
            $to = now($timezone);
        }

        if ($from->greaterThan($to)) {
            throw new RuntimeException('PayPal 同步开始日期不能晚于结束日期。');
        }

        return [$from, $to];
    }

    /** @return list<array{0:Carbon,1:Carbon}> */
    private function dateSegments(Carbon $from, Carbon $to): array
    {
        $segments = [];
        $cursor = $from->copy();
        while ($cursor->lessThanOrEqualTo($to)) {
            $end = $cursor->copy()->addDays(30)->subSecond();
            if ($end->greaterThan($to)) {
                $end = $to->copy();
            }
            $segments[] = [$cursor->copy(), $end];
            $cursor = $end->copy()->addSecond();
        }

        return $segments;
    }

    private function dateTime(mixed $value): ?CarbonInterface
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

    /** @param list<mixed> $values */
    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            $value = trim((string) ($value ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function timeout(): int
    {
        return max(10, (int) config('logistics.paypal.request_timeout_seconds', 45));
    }
}
