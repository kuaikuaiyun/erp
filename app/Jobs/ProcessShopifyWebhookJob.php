<?php

namespace App\Jobs;

use App\Models\LogisticsShopifyConnection;
use App\Models\LogisticsWebhookDelivery;
use App\Portals\Logistics\ShopifyOrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class ProcessShopifyWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 180;
    public int $tries = 3;
    /** @var list<int> */
    public array $backoff = [30, 120];

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $deliveryId,
        public readonly int $connectionId,
        public readonly array $payload,
        public readonly string $topic,
    ) {
    }

    public function handle(ShopifyOrderSyncService $shopify): void
    {
        $delivery = LogisticsWebhookDelivery::query()->find($this->deliveryId);
        $connection = LogisticsShopifyConnection::query()->with('store')->find($this->connectionId);
        if (!$delivery || $delivery->status === 'success') {
            return;
        }
        if (!$connection) {
            throw new \RuntimeException('Webhook 对应的 Shopify 店铺连接不存在。');
        }

        $delivery->forceFill(['status' => 'processing', 'message' => null])->save();
        $order = $shopify->syncWebhookOrder($connection, $this->payload, $this->topic);
        $delivery->forceFill([
            'status' => 'success',
            'logistics_order_id' => $order?->getKey(),
            'message' => $order ? '订单已刷新。' : '订单尚未付款，已忽略本次写入。',
            'processed_at' => now(),
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        LogisticsWebhookDelivery::query()->whereKey($this->deliveryId)->update([
            'status' => 'failed',
            'message' => Str::limit($exception->getMessage(), 1000),
            'processed_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
