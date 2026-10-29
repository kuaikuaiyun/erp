<?php

namespace App\Admin\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\DepartmentAuthorizationService;
use App\Services\DepartmentOrganizationService;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountPermissionController extends Controller
{
    public function __construct(
        private readonly DepartmentOrganizationService $organization,
        private readonly DepartmentAuthorizationService $authorization,
    ) {
    }

    public function edit(AdminUser $account, Content $content): Content
    {
        abort_if($account->isAdministrator(), 404);

        return $content
            ->title('成员页面权限')
            ->description($account->name)
            ->body(view('admin.organization.permissions.user', [
                'account' => $account,
                'pageTree' => $this->organization->pagePermissionTree(),
                'overrides' => $this->organization->assignedUserPageOverrides($account),
                'departmentPermissionIds' => $this->authorization->departmentPermissionIdsFor($account)->all(),
            ])->render());
    }

    public function update(Request $request, AdminUser $account): RedirectResponse
    {
        abort_if($account->isAdministrator(), 404);
        /** @var AdminUser $actor */
        $actor = Admin::user();
        $this->organization->saveUserPageOverrides($account, (array) $request->input('permissions', []), $actor);
        admin_success('成员页面权限', '成员页面权限已保存。');

        return redirect(admin_url('organization/accounts'));
    }
}
