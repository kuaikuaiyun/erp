<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\DailyAccountOpening;
use App\Models\DailyAccountStoreTransfer;
use App\Models\DailyAdvertisingAccountAuthorization;
use App\Models\DailyAdvertisingRecharge;
use App\Models\DailyStoreLaunch;
use Illuminate\Database\Eloquent\Builder;

class AssistantDailySubmissionPendingCountService
{
    public function __construct(private readonly DailyReportDepartmentScopeService $departmentScopes) {}

    /** @return array<string, int> */
    public function forUser(AdminUser $user): array
    {
        $visibleDepartmentIds = $this->departmentScopes->visibleIdsForReview($user);

        $counts = [
            'assistant_daily_advertising_recharges' => $this->count(DailyAdvertisingRecharge::query(), $visibleDepartmentIds),
            'assistant_daily_account_openings' => $this->count(DailyAccountOpening::query(), $visibleDepartmentIds),
            'assistant_daily_store_launches' => $this->count(DailyStoreLaunch::query(), $visibleDepartmentIds),
            'assistant_daily_ad_account_authorizations' => $this->count(DailyAdvertisingAccountAuthorization::query(), $visibleDepartmentIds),
            'assistant_daily_account_store_transfers' => $this->count(DailyAccountStoreTransfer::query(), $visibleDepartmentIds),
        ];

        return ['assistant_daily_submissions' => array_sum($counts), ...$counts];
    }

    /** @param list<int>|null $visibleDepartmentIds */
    private function count(Builder $query, ?array $visibleDepartmentIds): int
    {
        $query->where('status', 'pending');

        if ($visibleDepartmentIds !== null) {
            $visibleDepartmentIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('operation_department_id', $visibleDepartmentIds);
        }

        return $query->count();
    }
}
