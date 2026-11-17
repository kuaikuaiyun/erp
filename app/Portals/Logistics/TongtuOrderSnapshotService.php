<?php

namespace App\Portals\Logistics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TongtuOrderSnapshotService
{
    public const HISTORY_DAYS = 90;
    private const RECENT_BACKFILL_DAYS = 3;
    private const PAGE_SIZE = 100;
    private const RETRY_DELAY_MINUTES = 15;
    private const PROGRESS_KEY = 'logistics:tongtu:order-snapshot-progress';

    public function __construct(private readonly TongtuSkuMappingService $tongtu)
    {
    }

    public function connection(): Connection
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'));
    }

    public function syncNextDay(?string $requestedDay = null): ?string
    {
        return Cache::lock('logistics:tongtu:order-snapshot-sync', 7200)->get(function () use ($requestedDay): ?string {
            $day = $requestedDay ?? $this->nextDay();
            if ($day !== null) {
                $retryKey = $this->retryKey($day);
                try {
                    $this->syncDay($day);
                } catch (RuntimeException $exception) {
                    Cache::put($retryKey, true, now()->addMinutes(self::RETRY_DELAY_MINUTES));
                    Log::warning('Tongtu order snapshot sync failed', [
                        'paid_date' => $day,
                        'reason' => $this->failureReason($exception),
                        'retry_after_minutes' => self::RETRY_DELAY_MINUTES,
                    ]);

                    throw $exception;
                } finally {
                    Cache::forget(self::PROGRESS_KEY);
                }
                Cache::forget($retryKey);
            }

            return $day;
        }) ?: null;
    }

    private function nextDay(): ?string
    {
        $now = CarbonImmutable::now('Asia/Shanghai');
        $today = $now->startOfDay();
        $oldest = $today->subDays(self::HISTORY_DAYS - 1);
        $refreshCutoff = $now->subHour()->toDateTimeString();
        $days = $this->connection()->table('logistics_tongtu_sync_days')
            ->whereBetween('paid_date', [$oldest->toDateString(), $today->toDateString()])
            ->pluck('synced_at', 'paid_date');

        if ($days->count() < self::HISTORY_DAYS) {
            for ($day = $today; $day->gte($oldest); $day = $day->subDay()) {
                $date = $day->toDateString();
                if (!$days->has($date) && !Cache::has($this->retryKey($date))) {
                    return $date;
                }
            }

            return null;
        }

        for ($offset = 0; $offset <= self::RECENT_BACKFILL_DAYS; $offset++) {
            $day = $today->subDays($offset);
            $date = $day->toDateString();
            if ($days->get($date) <= $refreshCutoff && !Cache::has($this->retryKey($date))) {
                return $date;
            }
        }

        return null;
    }

    private function retryKey(string $day): string
    {
        return 'logistics:tongtu:order-snapshot-retry:'.$day;
    }

    private function failureReason(RuntimeException $exception): string
    {
        $message = $exception->getMessage();
        // HTTP errors can include signed URLs or response bodies. Record only known error summaries.
        if (preg_match('/\A(Tongtu (?:connection failed: cURL error \d+|(?:GET|POST) request failed \(HTTP \d{3}\)|(?:app token|partner lookup|Shopify order query) failed))/', $message, $match)) {
            return $match[1];
        }

        return match ($message) {
            '通途重复返回同一页，本次同步未完成。',
            '等待通途接口限流队列超时，请稍后重试。',
            'Invalid Tongtu Shopify order response.',
            'Tongtu GET request returned invalid JSON.',
            'Tongtu POST request returned invalid JSON.',
            'Tongtu configuration is incomplete.',
            'Tongtu did not return an app token.',
            'Tongtu merchant configuration is ambiguous.' => $message,
            default => $exception::class,
        };
    }

    private function syncDay(string $day): void
    {
        $startedAt = now('Asia/Shanghai')->toDateTimeString();
        $orders = [];
        $seen = [];
        for ($page = 1; ; $page++) {
            Cache::put(self::PROGRESS_KEY, [
                'date' => $day,
                'orders' => count($orders),
            ], now()->addMinutes(3));
            $rows = $this->tongtu->shopifyErpOrderPage($day.' 00:00:00', $day.' 23:59:59', $page, self::PAGE_SIZE);
            $signature = hash('sha256', serialize(array_column($rows, 'orderIdCode')));
            if (isset($seen[$signature])) {
                throw new RuntimeException('通途重复返回同一页，本次同步未完成。');
            }
            $seen[$signature] = true;

            foreach ($rows as $row) {
                $number = trim((string) ($row['orderIdCode'] ?? ''));
                if ($number !== '') {
                    $orders[mb_strtolower($number)] = [
                        'paid_date' => $day,
                        'tongtu_order_number' => $number,
                        'domain' => self::domain($row['saleAccount'] ?? null),
                    ];
                }
            }

            if (count($rows) < self::PAGE_SIZE) {
                break;
            }
        }

        $db = $this->connection();
        $db->transaction(function () use ($db, $day, $orders, $startedAt): void {
            $db->table('logistics_tongtu_order_snapshots')->where('paid_date', $day)->delete();
            foreach (array_chunk($orders, 500) as $chunk) {
                $db->table('logistics_tongtu_order_snapshots')->upsert($chunk, ['paid_date', 'tongtu_order_number'], ['domain']);
            }
            $db->table('logistics_tongtu_sync_days')->updateOrInsert(['paid_date' => $day], ['synced_at' => $startedAt]);
        });
    }

    public function progress(): ?array
    {
        return Cache::get(self::PROGRESS_KEY);
    }

    public static function domain(mixed $account): ?string
    {
        if (!is_string($account) || trim($account) === '' || str_contains($account, '@')) {
            return null;
        }

        $account = trim($account);
        $host = parse_url(str_contains($account, '://') ? $account : '//'.$account, PHP_URL_HOST);

        return is_string($host) && str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            ? strtolower($host)
            : null;
    }
}
