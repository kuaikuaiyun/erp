<?php

namespace App\Portals\Assistant;

use App\Models\AssistantShopifyStoreOrder;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssistantShopifyOrderCsvExportService
{
    /**
     * Stream the complete filtered order set so large exports do not need to
     * be held in PHP memory. Dashboard exclusions are intentionally not used:
     * cancelled, refunded and test orders remain visible in this audit file.
     *
     * @param Builder<AssistantShopifyStoreOrder> $orders
     * @param array<string, mixed> $filters
     */
    public function download(Builder $orders, array $filters): StreamedResponse
    {
        $filename = __('assistant.shopify_analytics.export.file_name', [
            'from' => $filters['from_date'],
            'to' => $filters['to_date'],
            'timestamp' => now()->format('Ymd-His'),
        ]);

        return response()->streamDownload(function () use ($orders, $filters): void {
            set_time_limit(0);
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $this->headers());

            $orders
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($output, $filters): void {
                    foreach ($rows as $order) {
                        $store = $order->store;
                        $owner = $store?->ownerUser;
                        $createdAt = $order->shopify_created_at?->copy()->setTimezone($filters['timezone']);

                        fputcsv($output, [
                            $order->id,
                            $this->text($store?->shop_name),
                            $this->text($store?->domain),
                            $this->text(collect($store?->team ?? [])->implode(', ')),
                            $this->text($owner?->name ?: $owner?->username),
                            $this->text($order->shopify_order_number),
                            $this->text($order->shopify_order_id),
                            $createdAt?->format('Y-m-d H:i:s'),
                            $filters['timezone'],
                            $order->shopify_processed_at?->format('Y-m-d H:i:s'),
                            $order->shopify_updated_at?->format('Y-m-d H:i:s'),
                            $order->shopify_cancelled_at?->format('Y-m-d H:i:s'),
                            $order->shopify_cancelled_at === null
                                ? __('assistant.shopify_analytics.export.no')
                                : __('assistant.shopify_analytics.export.yes'),
                            $order->is_test
                                ? __('assistant.shopify_analytics.export.yes')
                                : __('assistant.shopify_analytics.export.no'),
                            $this->text($order->financial_status),
                            $this->text($order->fulfillment_status),
                            $this->text($order->subscription_category),
                            $this->text(collect($order->shopify_tags ?? [])->implode(', ')),
                            $this->text($order->original_currency_code),
                            $order->original_total_price,
                            $this->text($order->refunded_currency_code),
                            $order->refunded_total_amount,
                            ((float) $order->refunded_total_amount) > 0
                                ? __('assistant.shopify_analytics.export.yes')
                                : __('assistant.shopify_analytics.export.no'),
                            $this->text($order->current_currency_code),
                            $order->current_total_price,
                            $order->synced_at?->format('Y-m-d H:i:s'),
                        ]);
                    }

                    fflush($output);
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /** @return list<string> */
    private function headers(): array
    {
        $keys = [
            'record_id',
            'store',
            'domain',
            'team',
            'owner',
            'order_number',
            'shopify_order_id',
            'created_at',
            'report_timezone',
            'processed_at',
            'shopify_updated_at',
            'cancelled_at',
            'is_cancelled',
            'is_test',
            'financial_status',
            'fulfillment_status',
            'subscription_category',
            'tags',
            'original_currency',
            'original_total',
            'refund_currency',
            'refund_amount',
            'is_refunded',
            'current_currency',
            'current_total',
            'synced_at',
        ];

        return array_map(
            static fn (string $key): string => __('assistant.shopify_analytics.export.columns.'.$key),
            $keys,
        );
    }

    private function text(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
