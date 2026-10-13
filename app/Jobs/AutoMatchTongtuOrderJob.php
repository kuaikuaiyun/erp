<?php

namespace App\Jobs;

use App\Models\LogisticsOrder;
use App\Models\LogisticsSyncLog;
use App\Portals\Logistics\TongtuOrderMatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class AutoMatchTongtuOrderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const STAGE_PAID_24H = 'paid_24h';
    public const STAGE_FULFILLMENT = 'fulfillment';

    public int $timeout = 360;
    public int $tries = 1;

    public function __construct(public readonly int $orderId, public readonly string $stage)
    {
    }

    public function handle(TongtuOrderMatchService $tongtu): void
    {
        $order = LogisticsOrder::query()->find($this->orderId);
        if (!$order || !$order->assistant_domain_store_id || !$order->paid_at) {
            return;
        }

        if ($this->stage === self::STAGE_PAID_24H) {
            $this->handlePaidStage($order, $tongtu);

            return;
        }

        if ($this->stage === self::STAGE_FULFILLMENT) {
            $this->handleFulfillmentStage($order, $tongtu);
        }
    }

    private function handlePaidStage(LogisticsOrder $order, TongtuOrderMatchService $tongtu): void
    {
        if (!$this->claimStage($order, 'auto_match_paid_completed_at')) {
            return;
        }
        $order->refresh();
        $dueAt = $order->paid_match_due_at ?: $order->paid_at->copy()->addHours(24);
        if ($order->first_fulfilled_at && $order->first_fulfilled_at->lessThanOrEqualTo($dueAt)) {
            $this->log($order, 'skipped', '付款后 24 小时内已发货，已取消 24 小时自动匹配。');

            return;
        }

        $summary = $tongtu->match([$order], null);
        $this->logMatchResult($order, '付款满 24 小时自动匹配已执行。', $summary);
    }

    private function handleFulfillmentStage(LogisticsOrder $order, TongtuOrderMatchService $tongtu): void
    {
        if (!$order->first_fulfilled_at
            || !$this->claimStage($order, 'auto_match_fulfillment_completed_at', 'first_fulfilled_at')) {
            return;
        }
        $order->refresh();

        $dueAt = $order->paid_match_due_at ?: $order->paid_at->copy()->addHours(24);
        if ($order->first_fulfilled_at->greaterThan($dueAt)
            && $this->claimStage($order, 'auto_match_paid_completed_at')) {
            $paidSummary = $tongtu->match([$order], null);
            $this->logMatchResult($order, '订单在付款满 24 小时后发货，补执行 24 小时自动匹配。', $paidSummary);
            $order->refresh();
        } elseif ($order->first_fulfilled_at->lessThanOrEqualTo($dueAt)
            && $this->claimStage($order, 'auto_match_paid_completed_at')) {
            $this->log($order, 'skipped', '订单已在付款后 24 小时内发货，跳过 24 小时自动匹配。');
        }

        $summary = $tongtu->match([$order], null);
        $this->logMatchResult($order, '首次发货自动匹配已执行。', $summary);
    }

    public function failed(Throwable $exception): void
    {
        $order = LogisticsOrder::query()->find($this->orderId);
        if (!$order) {
            return;
        }
        $field = $this->stage === self::STAGE_FULFILLMENT
            ? 'auto_match_fulfillment_completed_at'
            : 'auto_match_paid_completed_at';
        $order->forceFill([
            $field => now(),
            'data_status' => LogisticsOrder::STATUS_TONGTU_FAILED,
        ])->save();
        $this->log($order, 'failed', Str::limit($exception->getMessage(), 1000));
    }

    private function claimStage(LogisticsOrder $order, string $completedField, ?string $requiredField = null): bool
    {
        $query = LogisticsOrder::query()
            ->whereKey($order->getKey())
            ->whereNull($completedField);
        if ($requiredField !== null) {
            $query->whereNotNull($requiredField);
        }

        return $query->update([
            $completedField => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    /** @param array<string, mixed> $summary */
    private function logMatchResult(LogisticsOrder $order, string $message, array $summary): void
    {
        $failed = (int) ($summary['failed'] ?? 0) > 0;
        if ($failed) {
            $reason = collect($summary['errors'] ?? [])->filter()->implode('；');
            $message .= $reason !== '' ? ' 失败原因：'.$reason : ' 匹配失败。';
        }

        $this->log($order, $failed ? 'failed' : 'success', $message, $summary);
    }

    /** @param array<string, mixed>|null $context */
    private function log(LogisticsOrder $order, string $status, string $message, ?array $context = null): void
    {
        LogisticsSyncLog::query()->create([
            'logistics_order_id' => $order->getKey(),
            'source' => 'tongtu',
            'action' => 'auto_match_'.$this->stage,
            'status' => $status,
            'message' => $message,
            'context' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }
}
