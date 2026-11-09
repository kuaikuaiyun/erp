<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillFashionLogisticsOrderUtc extends Command
{
    protected $signature = 'fashion-logistics:backfill-order-utc
        {--all : Recalculate rows that already have a UTC timestamp}
        {--platform= : Only backfill the specified source platform, for example xshoppy}
        {--chunk=500 : Number of rows read per batch}';

    protected $description = 'Backfill order timestamps and China-normalized payment times for fashion orders';

    public function handle(): int
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $table = 'fashion_logistics_orders';
        $chunk = max(1, (int) $this->option('chunk'));
        $overwrite = (bool) $this->option('all');
        $platform = strtolower(trim((string) $this->option('platform')));
        $updated = 0;
        $skipped = 0;
        $invalid = 0;

        DB::connection($connection)->table($table)
            ->select(['id', 'source_platform', 'source_order_created_at', 'source_order_timezone', 'order_created_at', 'order_created_at_utc', 'paid_at', 'paid_at_utc', 'source_payload'])
            ->whereNotNull('order_created_at')
            ->when($platform !== '', fn ($query) => $query->where('source_platform', $platform))
            ->when(! $overwrite, fn ($query) => $query->where(fn ($missing) => $missing->whereNull('order_created_at_utc')->orWhereNull('paid_at_utc')))
            ->orderBy('id')
            ->chunkById($chunk, function ($orders) use ($connection, $table, &$updated, &$skipped, &$invalid): void {
                foreach ($orders as $order) {
                    try {
                        if ($order->source_platform === 'xshoppy') {
                            if (blank($order->source_order_created_at) || blank($order->source_order_timezone)) {
                                $skipped++;
                                continue;
                            }

                            $utc = CarbonImmutable::parse((string) $order->source_order_created_at, new DateTimeZone((string) $order->source_order_timezone))->utc();
                        } else {
                            // Shopline timestamps were historically stored in the application's UTC timezone.
                            $utc = CarbonImmutable::parse((string) $order->order_created_at, 'UTC')->utc();
                        }

                        $values = [
                            'order_created_at_utc' => $utc->format('Y-m-d H:i:s'),
                            'order_created_at' => $utc->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                            'updated_at' => now(),
                        ];

                        $payload = json_decode((string) $order->source_payload, true);
                        $sourcePaidAt = $order->source_platform === 'xshoppy'
                            ? data_get($payload, 'date_paid')
                            : (data_get($payload, 'paid_at') ?? data_get($payload, 'processed_at'));
                        if (is_string($sourcePaidAt) && trim($sourcePaidAt) !== '') {
                            $paidAt = $order->source_platform === 'xshoppy'
                                ? CarbonImmutable::parse($sourcePaidAt, new DateTimeZone((string) $order->source_order_timezone))
                                : CarbonImmutable::parse($sourcePaidAt);
                            $values['paid_at'] = $paidAt->format('Y-m-d H:i:s');
                            $values['paid_at_utc'] = ($order->source_platform === 'xshoppy'
                                ? $paidAt->setTimezone('Asia/Shanghai')
                                : $paidAt->utc())->format('Y-m-d H:i:s');
                        }

                        DB::connection($connection)->table($table)->where('id', $order->id)->update($values);
                        $updated++;
                    } catch (\Throwable) {
                        $invalid++;
                    }
                }
            });

        $this->info("UTC time backfill completed: updated {$updated}, skipped {$skipped}, invalid {$invalid}.");

        return self::SUCCESS;
    }
}
