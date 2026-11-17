<?php

namespace App\Portals\ReviewCenter;

use App\Models\AdminUser;
use App\Models\OperationTaskRecap;
use App\Models\OperationTestTask;
use App\Models\Portal;
use App\Portal\Services\PortalAccessService;
use App\Portal\Services\PortalMenuAuthorizationService;

class ReviewCenterPendingCountService
{
    public function __construct(
        private readonly ReviewCenterAuthorizationService $authorization,
        private readonly PortalAccessService $access,
        private readonly PortalMenuAuthorizationService $menus,
    ) {}

    /** Pending work across the member's complete review scope, independent of list filters. */
    public function forUser(Portal $portal, AdminUser $user): array
    {
        $empty = ['recaps' => 0, 'tests' => 0];
        if ($portal->code !== 'review-center' || ! $this->access->canEnter($portal, $user)) return $empty;
        $menu = $portal->menus()->where('code', 'review_center_operation_tasks')->where('is_enabled', true)->first();
        if (! $menu || ! $this->menus->canAccess($user, $menu)) return $empty;

        $ids = $this->authorization->departmentIds($user);
        $administrator = $user->isAdministrator();
        if (! $administrator && $ids === []) return $empty;

        $recaps = OperationTaskRecap::query()
            ->when(! $administrator, fn ($query) => $query->whereIn('department_id', $ids))
            ->where('department_review_status', 'pending')->count();
        $tests = OperationTestTask::query()
            ->when(! $administrator, fn ($query) => $query->whereIn('department_id', $ids))
            ->whereDoesntHave('reviews', fn ($query) => $query
                ->where('target_type', 'task')
                ->whereColumn('target_version', 'operation_test_tasks.version')
                ->where('channel', 'department'))
            ->count();

        return ['recaps' => $recaps, 'tests' => $tests];
    }
}
