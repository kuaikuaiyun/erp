<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\FashionAdvertisingReport;
use App\Models\OperationReport;

class AssistantAdvertisingReviewPendingCountService
{
    public function __construct(private readonly AssistantStoreOwnerService $storeOwners) {}

    /** @return array<string, int> */
    public function forUser(AdminUser $user): array
    {
        $visibleDepartmentIds = $user->isAdministrator()
            ? null
            : $this->storeOwners->visibleOperationDepartmentIds($user);

        $shopify = OperationReport::query()
            ->when($visibleDepartmentIds !== null, fn ($query) => $visibleDepartmentIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('operation_department_id', $visibleDepartmentIds))
            ->where('status', OperationReport::STATUS_PENDING)
            ->count();

        $fashionCounts = FashionAdvertisingReport::query()
            ->when($visibleDepartmentIds !== null, fn ($query) => $visibleDepartmentIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('submitter.departments', fn ($departments) => $departments
                    ->whereIn('admin_roles.id', $visibleDepartmentIds)))
            ->where('status', FashionAdvertisingReport::STATUS_PENDING)
            ->selectRaw('project_code, COUNT(*) total')
            ->groupBy('project_code')
            ->pluck('total', 'project_code');
        $fashion = (int) $fashionCounts->sum();

        return [
            'assistant_operation_report_reviews' => $shopify,
            'assistant_fashion_advertising_reviews' => (int) ($fashionCounts['fuzhuang'] ?? 0),
            'assistant_baokuan_fashion_advertising_reviews' => (int) ($fashionCounts['baokuan-fashion'] ?? 0),
            'assistant_baokuan_general_advertising_reviews' => (int) ($fashionCounts['baokuan-general'] ?? 0),
            'assistant_danbao_general_advertising_reviews' => (int) ($fashionCounts['danbao-general'] ?? 0),
            'assistant_advertising_expenses' => $shopify + $fashion,
        ];
    }
}
