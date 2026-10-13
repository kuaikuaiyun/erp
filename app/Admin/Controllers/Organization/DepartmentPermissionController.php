<?php

namespace App\Admin\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Department;
use App\Services\DepartmentOrganizationService;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Layout\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DepartmentPermissionController extends Controller
{
    public function __construct(private readonly DepartmentOrganizationService $organization)
    {
    }

    public function edit(Department $department, Content $content): Content
    {
        abort_if($department->isSystemDepartment(), 404);

        return $content
            ->title(__('organization.page_permissions.title'))
            ->description($department->name)
            ->body($this->form($department)->edit($department->getKey()));
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        abort_if($department->isSystemDepartment(), 404);

        $permissions = collect((array) $request->input('permissions', []))
            ->filter(fn ($permission) => is_array($permission) && !empty($permission['enabled']))
            ->map(fn (array $permission, $permissionId) => [
                'permission_id' => (int) $permissionId,
                'scope' => (string) ($permission['scope'] ?? 'self'),
            ])
            ->values()
            ->all();

        $this->organization->savePagePermissions($department, $permissions, $this->administrator());

        admin_success(__('organization.page_permissions.title'), __('organization.page_permissions.saved'));

        return redirect(admin_url('organization/departments'));
    }

    private function form(Department $department): Form
    {
        $pageTree = $this->organization->pagePermissionTree();
        $directPermissions = $this->organization->assignedPagePermissions($department);
        $inheritedPermissionIds = $this->organization->inheritedPagePermissionIds($department)->all();
        $form = new Form(new Department());
        $form->setTitle(__('organization.page_permissions.title'));
        $form->setAction(admin_url('organization/departments/'.$department->getKey().'/permissions'));
        $form->disableViewCheck();
        $form->disableEditingCheck();
        $form->disableCreatingCheck();
        $form->tools(function (Form\Tools $tools): void {
            $tools->disableList();
            $tools->disableView();
            $tools->disableDelete();
            $tools->append('<div class="btn-group pull-right" style="margin-right: 5px"><a href="'.e(admin_url('organization/departments')).'" class="btn btn-sm btn-default"><i class="fa fa-list"></i><span class="hidden-xs">&nbsp;'.e(__('organization.common.back')).'</span></a></div>');
        });
        $form->html(function () use ($department, $pageTree, $directPermissions, $inheritedPermissionIds): string {
            return view('admin.organization.permissions.partials.form-content', [
                'department' => $department,
                'pageTree' => $pageTree,
                'directPermissions' => $directPermissions,
                'inheritedPermissionIds' => $inheritedPermissionIds,
            ])->render();
        })->plain();

        return $form;
    }

    private function administrator(): AdminUser
    {
        /** @var AdminUser $administrator */
        $administrator = Admin::user();

        return $administrator;
    }
}


