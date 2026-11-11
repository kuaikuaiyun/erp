<?php

namespace App\Portals\Operation;

use App\Models\AdminUser;
use App\Models\AssistantAdvertisingAgency;
use App\Models\OperationReport;
use App\Portals\Assistant\AssistantStoreOwnerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdvertisingReportBulkImportService
{
    public function __construct(
        private readonly AdvertisingReportSpreadsheetService $spreadsheets,
        private readonly OperationReportMetricsService $metrics,
        private readonly AssistantStoreOwnerService $assistantStoreOwners,
    ) {
    }

    /** @param Collection<int, AdminUser> $operators */
    public function templateResponse(Collection $operators): StreamedResponse
    {
        $groups = [];
        foreach ($operators as $operator) {
            $department = $this->assistantStoreOwners->operationDepartmentForUser($operator);
            $assistantIds = $department
                ? $this->assistantStoreOwners->assistantDepartmentIdsForOperationDepartment((int) $department->getKey())
                : [];
            $groups[$this->operatorLabel($operator)] = $this->agencyGroups($assistantIds);
        }

        return $this->spreadsheets->templateResponse($groups);
    }

    /**
     * @param Collection<int, AdminUser> $operators
     * @return array{imported_count:int,report_count:int,overwritten_count:int}
     */
    public function import(UploadedFile $file, Collection $operators, AdminUser $submittedBy): array
    {
        $configurations = [];
        $storedPaths = [];

        try {
            $rows = $this->spreadsheets->read($file);
            $groups = [];
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
                    $reason = $matches->isEmpty() ? '不存在、不在当前助理部门管理范围内或没有已分配店铺' : '存在同名人员，请使用模板参考页中的完整名称';
                    throw new \InvalidArgumentException("第 {$row['row']} 行的运营人员「{$row['operator_name']}」{$reason}。");
                }

                /** @var AdminUser $operator */
                $operator = $matches->first();
                $operatorId = (int) $operator->getKey();
                if (!isset($configurations[$operatorId])) {
                    $department = $this->assistantStoreOwners->operationDepartmentForUser($operator);
                    if (!$department) throw new \InvalidArgumentException("运营人员「{$row['operator_name']}」尚未归属运营部门。");
                    $assistantIds = $this->assistantStoreOwners->assistantDepartmentIdsForOperationDepartment((int) $department->getKey());
                    $configurations[$operatorId] = [
                        'operator' => $operator,
                        'department' => $department,
                        'agency_groups' => $this->agencyGroups($assistantIds),
                    ];
                }

                $matchedAgency = collect($configurations[$operatorId]['agency_groups'])
                    ->flatMap(function (array $items, string $agencyName) use ($row): array {
                        return $this->normalize($agencyName) === $this->normalize($row['agency_name']) ? $items : [];
                    })->first(fn (array $item): bool => $this->normalize($item['media']) === $this->normalize($row['media_source']));
                if (!$matchedAgency) {
                    throw new \InvalidArgumentException("第 {$row['row']} 行的广告代理与广告社媒组合不存在，或不属于该运营绑定的助理部门。");
                }

                $bookSpend = (float) $row['book_spend'];
                $coefficient = (float) $matchedAgency['coefficient'];
                $key = $operatorId.'|'.$row['report_date'];
                $groups[$key] ??= ['operator_id' => $operatorId, 'report_date' => $row['report_date'], 'ads' => []];
                $groups[$key]['ads'][] = [
                    'agency' => $row['agency_name'], 'media_source' => (string) $matchedAgency['media'],
                    'account_name' => $row['account_name'], 'account_number' => $row['account_number'],
                    'book_spend' => $bookSpend, 'settlement_coefficient' => $coefficient,
                    'handling_fee' => round($bookSpend * $coefficient, 4),
                    'actual_spend' => round($bookSpend * (1 + $coefficient), 4),
                    '_image_bytes' => $row['image_bytes'], '_image_extension' => $row['image_extension'],
                ];
            }

            foreach ($groups as &$group) foreach ($group['ads'] as &$ad) {
                $path = 'operation-reports/ads/'.Str::uuid().'.'.$ad['_image_extension'];
                if (!Storage::disk('public')->put($path, $ad['_image_bytes'])) throw new \InvalidArgumentException('广告消耗截图保存失败，请稍后重新导入。');
                $storedPaths[] = $path;
                $ad['screenshot'] = $path;
                unset($ad['_image_bytes'], $ad['_image_extension']);
            }
            unset($group, $ad);

            $oldScreenshotPaths = collect();
            $overwrittenCount = 0;
            (new OperationReport)->getConnection()->transaction(function () use ($groups, $configurations, $submittedBy, $oldScreenshotPaths, &$overwrittenCount): void {
                foreach ($groups as $group) {
                    $operator = $configurations[$group['operator_id']]['operator'];
                    $department = $configurations[$group['operator_id']]['department'];
                    $calculated = $this->metrics->build($group['report_date'], $group['operator_id']);
                    $totals = $calculated['totals'];
                    $adSpend = (float) collect($group['ads'])->sum('actual_spend');
                    $firstProfit = $totals['first_total_revenue'] - $totals['first_purchase_cost'] - $totals['first_shipping_cost'] - $totals['first_return_fee'] - $adSpend;
                    $renewalNet = $totals['renewal_revenue'] - $totals['renewal_cost'];
                    $report = OperationReport::query()
                        ->whereDate('report_date', $group['report_date'])
                        ->where('operator_user_id', $group['operator_id'])
                        ->lockForUpdate()
                        ->first();
                    if ($report) {
                        $overwrittenCount++;
                        $oldScreenshotPaths->push(...collect($report->advertising_rows ?: [])->pluck('screenshot')->filter()->all());
                    } else {
                        $report = new OperationReport();
                    }
                    $report->fill([
                        'report_date' => $group['report_date'], 'operator_user_id' => $group['operator_id'],
                        'operator_name' => (string) ($operator->name ?: $operator->username),
                        'operation_department_id' => (int) $department->getKey(), 'operation_department_name' => (string) $department->name,
                        'metric_rows' => $calculated['rows'], 'advertising_rows' => $group['ads'],
                        'first_purchase_cost' => $totals['first_purchase_cost'], 'first_shipping_cost' => $totals['first_shipping_cost'],
                        'first_return_fee' => $totals['first_return_fee'], 'actual_ad_spend' => $adSpend,
                        'first_profit' => $firstProfit, 'renewal_net_revenue' => $renewalNet, 'overall_profit' => $firstProfit + $renewalNet,
                        'status' => OperationReport::STATUS_APPROVED, 'submitted_by' => $submittedBy->getKey(),
                        'submitted_at' => now(), 'reviewed_by' => $submittedBy->getKey(), 'reviewed_at' => now(), 'review_reason' => null,
                    ])->save();
                }
            });
            $oldScreenshotPaths->unique()->each(static fn (string $path) => Storage::disk('public')->delete($path));
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) Storage::disk('public')->delete($path);
            throw $exception;
        }

        return ['imported_count' => count($rows), 'report_count' => count($groups), 'overwritten_count' => $overwrittenCount];
    }

    public function exportResponse(iterable $reports): StreamedResponse
    {
        return $this->spreadsheets->exportResponse($reports);
    }

    private function normalize(string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', trim($value)));
    }

    private function operatorLabel(AdminUser $operator): string
    {
        $name = trim((string) $operator->name);
        $username = trim((string) $operator->username);
        return $name !== '' && $username !== '' && $name !== $username ? $name.'（'.$username.'）' : ($name ?: $username);
    }

    /** @return array<string, list<array{key:string,media:string,coefficient:float}>> */
    private function agencyGroups(array $assistantDepartmentIds): array
    {
        $options = AssistantAdvertisingAgency::enabledOptions($assistantDepartmentIds);
        $media = AssistantAdvertisingAgency::enabledMediaNames($assistantDepartmentIds);
        $names = AssistantAdvertisingAgency::enabledNames($assistantDepartmentIds);
        $groups = [];
        foreach ($options as $key => $coefficient) {
            $agencyName = trim((string) ($names[$key] ?? ''));
            $mediaName = trim((string) ($media[$key] ?? ''));
            if ($agencyName !== '' && $mediaName !== '') $groups[$agencyName][] = ['key' => (string) $key, 'media' => $mediaName, 'coefficient' => (float) $coefficient];
        }
        return $groups;
    }
}
