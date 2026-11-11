<?php

namespace App\Portals\Fashion;

use App\Models\AdminUser;
use App\Models\AppLovinAccountStoreBinding;
use App\Models\AppLovinSpendScreenshot;
use App\Models\AssistantAdvertisingAgency;
use App\Models\AssistantDomainStore;
use App\Models\FashionAdvertisingReport;
use App\Models\FashionLogisticsOrder;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Operation\AdvertisingReportSpreadsheetService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FashionAdvertisingReportBulkImportService
{
    public function __construct(
        private readonly AdvertisingReportSpreadsheetService $spreadsheets,
        private readonly AssistantStoreOwnerService $storeOwners,
    ) {}

    /** @param Collection<int, AdminUser> $operators */
    public function templateResponse(Collection $operators): StreamedResponse
    {
        $groups = [];
        foreach ($operators as $operator) {
            $department = $this->storeOwners->operationDepartmentForUser($operator);
            $assistantIds = $department ? $this->storeOwners->assistantDepartmentIdsForOperationDepartment((int) $department->getKey()) : [];
            $groups[$this->operatorLabel($operator)] = $this->agencyGroups($assistantIds);
        }

        return $this->spreadsheets->templateResponse($groups);
    }

    /** @param Collection<int, AdminUser> $operators
     *  @return array{imported_count:int,report_count:int}
     */
    public function import(UploadedFile $file, Collection $operators): array
    {
        $rows = $this->spreadsheets->read($file);
        $configurations = [];
        $groups = [];
        $storedPaths = [];

        try {
            foreach ($rows as $row) {
                $matches = $operators->filter(function (AdminUser $operator) use ($row): bool {
                    $needle = $this->normalize($row['operator_name']);
                    return in_array($needle, [
                        $this->normalize($this->operatorLabel($operator)),
                        $this->normalize((string) $operator->name),
                        $this->normalize((string) $operator->username),
                    ], true);
                })->values();
                if ($matches->count() !== 1) {
                    $reason = $matches->isEmpty() ? '不存在、不在当前管理范围内或没有绑定服装店铺' : '存在同名人员，请填写模板参考页中的完整名称';
                    throw new \InvalidArgumentException("第 {$row['row']} 行的运营人员「{$row['operator_name']}」{$reason}。");
                }

                /** @var AdminUser $operator */
                $operator = $matches->first();
                $operatorId = (int) $operator->getKey();
                if (! isset($configurations[$operatorId])) {
                    $department = $this->storeOwners->operationDepartmentForUser($operator);
                    if (! $department) throw new \InvalidArgumentException("运营人员「{$row['operator_name']}」尚未归属运营部门。");
                    $assistantIds = $this->storeOwners->assistantDepartmentIdsForOperationDepartment((int) $department->getKey());
                    $configurations[$operatorId] = ['operator' => $operator, 'agency_groups' => $this->agencyGroups($assistantIds)];
                }

                $matchedAgency = collect($configurations[$operatorId]['agency_groups'])
                    ->flatMap(fn (array $items, string $agencyName): array => $this->normalize($agencyName) === $this->normalize($row['agency_name']) ? $items : [])
                    ->first(fn (array $item): bool => $this->normalize($item['media']) === $this->normalize($row['media_source']));
                if (! $matchedAgency) throw new \InvalidArgumentException("第 {$row['row']} 行的广告代理与广告社媒组合不存在，或不属于该运营绑定的助理部门。");

                $storeId = null;
                $storeDomain = null;
                if ($this->normalize((string) $matchedAgency['media']) === 'applovin') {
                    $accountKey = AppLovinSpendScreenshot::accountKey($operatorId, $row['agency_name'], (string) $matchedAgency['media'], $row['account_name'], $row['account_number']);
                    $storeId = (int) (AppLovinAccountStoreBinding::query()->where('account_key', $accountKey)->value('assistant_domain_store_id') ?? 0);
                    $store = AssistantDomainStore::query()->whereKey($storeId)->where('owner_user_id', $operatorId)->first();
                    if (! $store) throw new \InvalidArgumentException("第 {$row['row']} 行的 AppLovin 账号尚未绑定该运营的店铺域名，请先完成绑定后再导入。");
                    $storeDomain = (string) $store->domain;
                }

                $bookSpend = round((float) $row['book_spend'], 2);
                $coefficient = round((float) $matchedAgency['coefficient'], 4);
                $key = $operatorId.'|'.$row['report_date'];
                $groups[$key] ??= ['operator_id' => $operatorId, 'report_date' => $row['report_date'], 'ads' => []];
                $groups[$key]['ads'][] = [
                    'agency' => $row['agency_name'], 'media' => (string) $matchedAgency['media'],
                    'account_name' => $row['account_name'], 'account_number' => $row['account_number'],
                    'assistant_domain_store_id' => $storeId ?: null, 'store_domain' => $storeDomain,
                    'book_spend_usd' => $bookSpend, 'settlement_coefficient' => $coefficient,
                    'actual_spend_usd' => round($bookSpend * (1 + $coefficient), 2),
                    '_image_bytes' => $row['image_bytes'], '_image_extension' => $row['image_extension'],
                ];
            }

            foreach ($groups as $group) {
                if (FashionAdvertisingReport::query()->whereDate('report_date', $group['report_date'])->where('submitter_id', $group['operator_id'])->exists()) {
                    throw new \InvalidArgumentException($this->operatorLabel($configurations[$group['operator_id']]['operator']).' 在 '.$group['report_date'].' 已有提报记录，请先删除或编辑原记录。');
                }
            }
            foreach ($groups as &$group) foreach ($group['ads'] as &$ad) {
                $path = 'fashion-advertising/'.now()->format('Ym').'/'.Str::uuid().'.'.$ad['_image_extension'];
                if (! Storage::disk('local')->put($path, $ad['_image_bytes'])) throw new \InvalidArgumentException('广告消耗截图保存失败，请稍后重试。');
                $storedPaths[] = $path;
                $ad['screenshot_path'] = $path;
                unset($ad['_image_bytes'], $ad['_image_extension']);
            }
            unset($group, $ad);

            (new FashionAdvertisingReport)->getConnection()->transaction(function () use ($groups, $configurations): void {
                foreach ($groups as $group) {
                    $operator = $configurations[$group['operator_id']]['operator'];
                    [$orders, $revenue] = $this->performance((int) $group['operator_id'], $group['report_date']);
                    $spend = round((float) collect($group['ads'])->sum('actual_spend_usd'), 2);
                    $report = FashionAdvertisingReport::query()->create([
                        'report_date' => $group['report_date'], 'submitter_id' => $group['operator_id'],
                        'submitter_name' => (string) ($operator->name ?: $operator->username),
                        'total_revenue_usd' => $revenue, 'total_order_count' => $orders,
                        'total_ad_spend_usd' => $spend, 'roi' => $spend > 0 ? round($revenue / $spend, 4) : 0,
                        'status' => FashionAdvertisingReport::STATUS_PENDING,
                        'reviewed_by' => null, 'reviewer_name' => null, 'reviewed_at' => null, 'review_reason' => null,
                    ]);
                    $report->adSpendEntries()->createMany($group['ads']);
                }
            });
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) Storage::disk('local')->delete($path);
            throw $exception;
        }

        return ['imported_count' => count($rows), 'report_count' => count($groups)];
    }

    /** @return array{0:int,1:float} */
    private function performance(int $operatorId, string $date): array
    {
        $storeIds = AssistantDomainStore::query()->where('owner_user_id', $operatorId)
            ->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy','shopline')")->pluck('id');
        $from = CarbonImmutable::parse($date.' 00:00:00', '-08:00')->utc();
        $to = CarbonImmutable::parse($date.' 23:59:59', '-08:00')->utc();
        $metrics = FashionLogisticsOrder::query()->whereIn('assistant_domain_store_id', $storeIds)
            ->whereIn('source_platform', ['xshoppy', 'shopline'])->whereBetween('order_created_at', [$from, $to])
            ->selectRaw('COUNT(*) order_count, COALESCE(SUM(total_amount),0) revenue')->first();

        return [(int) ($metrics?->order_count ?? 0), round((float) ($metrics?->revenue ?? 0), 2)];
    }

    private function normalize(string $value): string { return mb_strtolower((string) preg_replace('/\s+/u', '', trim($value))); }
    private function operatorLabel(AdminUser $operator): string
    {
        $name = trim((string) $operator->name); $username = trim((string) $operator->username);
        return $name !== '' && $username !== '' && $name !== $username ? $name.'（'.$username.'）' : ($name ?: $username);
    }

    private function agencyGroups(array $assistantDepartmentIds): array
    {
        $options = AssistantAdvertisingAgency::enabledOptions($assistantDepartmentIds);
        $media = AssistantAdvertisingAgency::enabledMediaNames($assistantDepartmentIds);
        $names = AssistantAdvertisingAgency::enabledNames($assistantDepartmentIds);
        $groups = [];
        foreach ($options as $key => $coefficient) {
            $agency = trim((string) ($names[$key] ?? '')); $medium = trim((string) ($media[$key] ?? ''));
            if ($agency !== '' && $medium !== '') $groups[$agency][] = ['media' => $medium, 'coefficient' => (float) $coefficient];
        }
        return $groups;
    }
}
