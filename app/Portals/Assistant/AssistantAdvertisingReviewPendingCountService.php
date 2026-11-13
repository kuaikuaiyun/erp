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

        $fashion = FashionAdvertisingReport::query()
            ->when($visibleDepartmentIds !== null, fn ($query) => $visibleDepartmentIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('submitter.departments', fn ($departments) => $departments
                    ->whereIn('admin_roles.id', $visibleDepartmentIds)))
            ->where('status', FashionAdvertisingReport::STATUS_PENDING)
            ->count();

        return [
            'assistant_operation_report_reviews' => $shopify,
            'assistant_fashion_advertising_reviews' => $fashion,
            'assistant_advertising_expenses' => $shopify + $fashion,
        ];
    }
}
