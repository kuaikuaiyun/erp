<?php

namespace App\Portals\ReviewCenter;

use App\Models\AdminUser;
use App\Models\Department;
use Illuminate\Support\Facades\DB;

class ReviewCenterAuthorizationService
{
    public function database()
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'));
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
