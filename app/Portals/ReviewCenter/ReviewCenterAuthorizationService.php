<?php

namespace App\Portals\ReviewCenter;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\Portal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReviewCenterAuthorizationService
{
    public function database()
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'));
    }

    /** Match the platform's active-account and root-department portal grants.
     * Portal maintenance/availability does not remove the underlying member authorization.
     */
    public function authorizedMembers(Portal $portal): Builder
    {
        $rootIds = $portal->departmentGrants()->pluck('department_id')->map(fn ($id) => (int) $id)->all();
        $rolesTable = config('admin.database.roles_table');

        return AdminUser::query()
            ->where(fn (Builder $query) => $query->whereNull('status')->orWhere('status', AdminUser::STATUS_ACTIVE))
            ->whereHas('roles', function (Builder $roles) use ($rootIds, $rolesTable): void {
                $roles->where('slug', Department::SYSTEM_SLUG)
                    ->orWhere(function (Builder $business) use ($rootIds, $rolesTable): void {
                        $business->where('slug', '!=', Department::SYSTEM_SLUG)
                            ->where(fn (Builder $department) => $department
                                ->where(fn (Builder $root) => $root->whereNull($rolesTable.'.parent_id')->whereIn($rolesTable.'.id', $rootIds))
                                ->orWhereIn($rolesTable.'.parent_id', $rootIds));
                    });
            });
    }

    /** The binding grants review scope only; portal and menu access are checked separately. */
    public function departmentIds(AdminUser $user): array
    {
        $departments = Department::business()->get(['id', 'parent_id']);
        if ($user->isAdministrator()) return $departments->pluck('id')->map(fn ($id) => (int) $id)->all();
        $assigned = $this->database()->table('review_center_department_bindings')->where('user_id', $user->getKey())->pluck('department_id')->map(fn ($id) => (int) $id)->all();
        $ids = [];
        foreach ($departments as $department) {
            // A root binding covers its direct children; a secondary binding covers itself only.
            if (in_array((int) $department->id, $assigned, true)
                || ($department->parent_id && in_array((int) $department->parent_id, $assigned, true))) $ids[] = (int) $department->id;
        }
        return array_values(array_unique($ids));
    }
}
