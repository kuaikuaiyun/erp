<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\FashionLogisticsOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssistantFashionStoreAnalyticsCsvExportService
{
    /** @param Builder<AssistantDomainStore> $stores */
    public function download(Builder $stores, array $filters, string $groupBy, string $projectName): StreamedResponse
    {
        $filename = sprintf('%s店铺分析_%s_%s至%s_%s.csv', $projectName, $groupBy === 'date' ? '按日期' : '按店铺',
            $filters['from_date'], $filters['to_date'], now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($stores, $filters, $groupBy): void {
            set_time_limit(0);
            $output = fopen('php://output', 'wb');
            if ($output === false) return;
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $groupBy === 'date'
                ? ['日期','店铺','域名','平台','团队','归属人','统计时区','订单数','营业额(USD)','客单价(USD)','最近付款时间','最近同步时间']
                : ['开始日期','结束日期','店铺','域名','平台','团队','归属人','统计时区','订单数','营业额(USD)','客单价(USD)','最近付款时间','最近同步时间']);
            $dates = $this->dates($filters);
            $this->exportStoresQuery($stores)->chunkById(100, function (Collection $chunk) use ($output, $filters, $groupBy, $dates): void {
                $metrics = $this->metrics($chunk->pluck('id')->map(fn ($id) => (int) $id)->all(), $filters, $groupBy === 'date' ? $dates : null);
                if ($groupBy === 'date') {
                    $metrics = $metrics->keyBy(fn ($row) => $row->assistant_domain_store_id.'|'.$row->report_date);
                    foreach ($chunk as $store) foreach (array_column($dates, 'date') as $date) {
                        fputcsv($output, $this->row($store, $metrics->get($store->id.'|'.$date), $filters, $date));
                    }
                } else {
                    $metrics = $metrics->keyBy('assistant_domain_store_id');
                    foreach ($chunk as $store) fputcsv($output, $this->row($store, $metrics->get($store->id), $filters));
                }
                fflush($output);
            }, 'assistant_domain_stores.id', 'id');
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, no-cache']);
    }

    private function exportStoresQuery(Builder $stores): Builder
    {
        $ownerTable = (new AdminUser())->getTable();
        return (clone $stores)->leftJoin($ownerTable.' as export_owner', 'assistant_domain_stores.owner_user_id', '=', 'export_owner.id')
            ->select(['assistant_domain_stores.id','assistant_domain_stores.shop_name','assistant_domain_stores.domain','assistant_domain_stores.site_builder','assistant_domain_stores.team'])
            ->selectRaw("COALESCE(NULLIF(export_owner.name,''),NULLIF(export_owner.username,''),'') export_owner_name")
            ->orderBy('assistant_domain_stores.id');
    }

    private function metrics(array $storeIds, array $filters, ?array $dates): Collection
    {
        if ($storeIds === []) return collect();
        $from = CarbonImmutable::parse($filters['from_date'].' 00:00:00', $filters['timezone'])->utc();
        $to = CarbonImmutable::parse($filters['to_date'].' 00:00:00', $filters['timezone'])->addDay()->utc();
        $query = FashionLogisticsOrder::query()->whereIn('assistant_domain_store_id', $storeIds)->whereNotNull('paid_at_utc')
            ->where('paid_at_utc', '>=', $from)->where('paid_at_utc', '<', $to)
            ->selectRaw('assistant_domain_store_id, COUNT(*) orders_count, COALESCE(SUM(total_amount),0) total_sales, MAX(paid_at_utc) last_paid_at, MAX(source_synced_at) last_synced_at');
        if ($dates !== null) {
            $cases = collect($dates)->map(fn ($bucket) => "WHEN paid_at_utc >= '{$bucket['from']}' AND paid_at_utc < '{$bucket['to']}' THEN '{$bucket['date']}'")->implode(' ');
            $expression = "CASE {$cases} ELSE NULL END";
            $query->selectRaw($expression.' report_date')->groupBy('assistant_domain_store_id')->groupByRaw($expression)->orderBy('report_date');
        } else $query->groupBy('assistant_domain_store_id');
        return $query->get();
    }

    private function dates(array $filters): array
    {
        $cursor = CarbonImmutable::parse($filters['from_date'], $filters['timezone'])->startOfDay();
        $last = CarbonImmutable::parse($filters['to_date'], $filters['timezone'])->startOfDay();
        $dates = [];
        while ($cursor->lte($last)) {
            $dates[] = ['date' => $cursor->toDateString(), 'from' => $cursor->utc()->format('Y-m-d H:i:s'), 'to' => $cursor->addDay()->utc()->format('Y-m-d H:i:s')];
            $cursor = $cursor->addDay();
        }
        return $dates;
    }

    private function row(AssistantDomainStore $store, ?object $metric, array $filters, ?string $date = null): array
    {
        $orders = (int) ($metric?->orders_count ?? 0); $sales = (float) ($metric?->total_sales ?? 0);
        $platform = mb_strtolower(trim((string) $store->site_builder));
        $prefix = $date === null ? [$filters['from_date'], $filters['to_date']] : [$date];
        return [...$prefix, $this->text($store->shop_name), $this->text($store->domain),
            in_array($platform, ['xshoppy','xshopy'], true) ? 'XShoppy' : ($platform === 'shopline' ? 'Shopline' : $this->text($store->site_builder)),
            $this->text(AssistantDomainStore::displayMultiValue($store->team)), $this->text($store->export_owner_name), $filters['timezone'],
            (string) $orders, number_format($sales, 2, '.', ''), number_format($orders > 0 ? $sales / $orders : 0, 2, '.', ''),
            $metric?->last_paid_at ? CarbonImmutable::parse($metric->last_paid_at, 'UTC')->timezone($filters['timezone'])->format('Y-m-d H:i:s') : '',
            $metric?->last_synced_at ? CarbonImmutable::parse($metric->last_synced_at, 'UTC')->timezone($filters['timezone'])->format('Y-m-d H:i:s') : ''];
    }

    private function text(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }
}
