<?php

namespace App\Admin\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Admin\Grid\Displayers\OrganizationActions;
use App\Models\AdminUser;
use App\Models\Department;
use App\Services\DepartmentAuthorizationService;
use App\Services\DepartmentOrganizationService;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Filter;
use Encore\Admin\Grid\Tools;
use Encore\Admin\Layout\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function __construct(
        private readonly DepartmentOrganizationService $organization,
        private readonly DepartmentAuthorizationService $authorization,
    )
    {
    }

    public function index(Content $content): Content
    {
        return $content
            ->title(__('organization.accounts.title'))
            ->description(__('organization.accounts.description'))
            ->body($this->grid());
    }

    public function create(Content $content): Content
    {
        $this->authorizeOrganizationManagement();

        return $content
            ->title(__('organization.accounts.create'))
            ->description(__('organization.accounts.title'))
            ->body($this->form());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeOrganizationManagement();
        $payload = $request->validate($this->accountRules(), $this->accountMessages());
        $memberships = $this->requestMemberships($request);
        $userModel = config('admin.database.users_model');

        DB::connection($this->connectionName())->transaction(function () use ($payload, $memberships, $userModel): void {
            /** @var AdminUser $account */
            $account = new $userModel();
            $account->fill([
                'username' => $payload['username'],
                'name' => $payload['name'],
                'password' => Hash::make($payload['password']),
                'status' => (int) ($payload['status'] ?? 1),
            ]);
            $account->save();
            $this->organization->syncAccountMemberships($account, $memberships, $this->administrator());
        });

        admin_success(__('organization.accounts.title'), __('organization.accounts.created'));

        return redirect(admin_url('organization/accounts'));
    }

    public function edit(AdminUser $account, Content $content): Content
    {
        $this->authorizeAccountManagement($account);

        return $content
            ->title(__('organization.accounts.edit'))
            ->description($account->name)
            ->body($this->form($account)->edit($account->getKey()));
    }

    public function update(Request $request, AdminUser $account): RedirectResponse
    {
        $this->authorizeAccountManagement($account);
        $payload = $request->validate($this->accountRules($account), $this->accountMessages());

        DB::connection($this->connectionName())->transaction(function () use ($request, $payload, $account): void {
            $account->name = $payload['name'];

            if (!$account->isAdministrator()) {
                $account->username = $payload['username'];
                $account->status = (int) ($payload['status'] ?? 1);
            }

            if (!empty($payload['password'])) {
                $account->password = Hash::make($payload['password']);
            }

            $account->save();

            if (!$account->isAdministrator()) {
                $this->organization->syncAccountMemberships(
                    $account,
                    $this->requestMemberships($request),
                    $this->administrator(),
                );
            }
        });

        admin_success(__('organization.accounts.title'), __('organization.accounts.updated'));

        return redirect(admin_url('organization/accounts'));
    }

    public function destroy(AdminUser $account): RedirectResponse|JsonResponse
    {
        $this->authorizeAccountManagement($account);

        try {
            $this->organization->deleteAccount($account, $this->administrator());
        } catch (ValidationException $exception) {
            if (request()->ajax()) {
                return response()->json([
                    'status' => false,
                    'message' => collect($exception->errors())->flatten()->first(),
                ], 422);
            }

            throw $exception;
        }

        if (request()->ajax()) {
            return response()->json([
                'status' => true,
                'message' => __('organization.accounts.deleted'),
            ]);
        }

        admin_success(__('organization.accounts.title'), __('organization.accounts.deleted'));

        return redirect(admin_url('organization/accounts'));
    }

    /**
     * The Grid remains a standard laravel-admin Grid. Department information
     * is rendered as a business column so routine account pages keep the
     * package's own toolbar, filter, pagination and row action behaviour.
     */
    private function grid(): Grid
    {
        $userModel = config('admin.database.users_model');
        $actor = $this->administrator();
        $grid = new Grid(new $userModel());

        $grid->setName('organization-accounts');
        $grid->setActionClass(OrganizationActions::class);
        $grid->model()->with('departments')->orderBy('id');
        if (!$actor->isAdministrator()) {
            $managedDepartmentIds = $this->authorization->managedDepartmentIds($actor);
            $departmentsTable = (new Department())->getTable();
            $grid->model()->whereHas('departments', function ($query) use ($managedDepartmentIds, $departmentsTable): void {
                $query->whereIn($departmentsTable.'.id', $managedDepartmentIds->all());
            });
        }
        $grid->disableExport();
        $grid->expandFilter();

        $grid->filter(function (Filter $filter): void {
            $filter->disableIdFilter();
            $filter->column(0.5, function (Filter $filter): void {
                $filter->like('username', __('organization.accounts.username'));
                $filter->like('name', __('organization.accounts.name'));
            });
        });

        $grid->column('id', __('organization.common.id'))->sortable();
        $grid->column('username', __('organization.accounts.username'));
        $grid->column('name', __('organization.accounts.name'));
        $grid->column('account_type', __('organization.accounts.account_type'))->display(function (): string {
            if ($this->isAdministrator()) {
                return '<span class="label label-warning">'.e(__('organization.common.system_account')).'</span>';
            }

            return '<span class="label label-default">'.e(__('organization.accounts.normal_account')).'</span>';
        });
        $grid->column('department_summary', __('organization.accounts.department_summary'))->display(function (): string {
            if ($this->isAdministrator()) {
                return '<span class="text-muted">'.e(__('organization.accounts.system_account_summary')).'</span>';
            }

            $memberships = $this->departments->sortBy('name')->values();

            if ($memberships->isEmpty()) {
                return '<span class="text-muted">'.e(__('organization.accounts.no_membership')).'</span>';
            }

            return $memberships->map(function (Department $department): string {
                $position = __('organization.positions.'.$department->pivot->position);

                return '<span class="label label-default">'.e($department->name).' · '.e($position).'</span>';
            })->implode(' ');
        });
        $grid->column('status', __('organization.common.status'))->display(function ($status): string {
            $enabled = (int) $status === 1;

            return '<span class="label '.($enabled ? 'label-success' : 'label-default').'">'
                .e($enabled ? __('organization.common.enabled') : __('organization.common.disabled'))
                .'</span>';
        });
        $grid->column('updated_at', __('organization.common.updated_at'))->display(function ($updatedAt): string {
            if (!$updatedAt) {
                return '';
            }

            return e($updatedAt instanceof \DateTimeInterface ? $updatedAt->format('Y-m-d H:i') : (string) $updatedAt);
        });

        $isSystemAdministrator = $actor->isAdministrator();
        $grid->actions(function (OrganizationActions $actions) use ($isSystemAdministrator): void {
            $actions->disableView();
            $actions->disableEdit();
            $actions->disableDelete();
            $account = $actions->row;
            $actions->append($actions->editButton());

            if (!$account->isAdministrator()) {
                if ($isSystemAdministrator) {
                    $url = admin_url('organization/accounts/'.$account->getKey().'/permissions');
                    $actions->append('<a href="'.e($url).'" class="btn btn-xs btn-info organization-grid-action"><i class="fa fa-key"></i> 页面权限</a>');
                }
                $actions->append($actions->deleteButton());
            }
        });

        $grid->tools(function (Tools $tools): void {
            $tools->batch(function (Tools\BatchActions $actions): void {
                $actions->disableDelete();
            });
        });

        return $grid;
    }

    private function form(?AdminUser $account = null): Form
    {
        $userModel = config('admin.database.users_model');
        $actor = $this->administrator();
        $isSystemAccount = $account?->isAdministrator() ?? false;
        $allMemberships = $account ? $this->organization->membershipsForAccount($account) : collect();
        $managedDepartmentIds = $this->authorization->managedDepartmentIds($actor);
        $memberships = $actor->isAdministrator()
            ? $allMemberships
            : $allMemberships->filter(fn ($membership) => $managedDepartmentIds->contains((int) $membership->department_id))->values();
        $departmentOptions = $this->organization->departmentOptionsForAccount($actor, $account);
        $positions = $this->organization->positionOptions();

        $form = new Form(new $userModel());
        $form->setTitle($account ? __('organization.accounts.edit') : __('organization.accounts.create'));
        $form->setWidth(8, 2);
        $form->disableViewCheck();
        $form->disableEditingCheck();
        $form->disableCreatingCheck();
        $form->tools(function (Form\Tools $tools): void {
            $tools->disableView();
            $tools->disableDelete();
        });
        $form->html('<span data-organization-account-form hidden></span>')->plain();

        $username = $form->text('username', __('organization.accounts.username'))
            ->rules($isSystemAccount ? 'nullable|max:190' : 'required|max:190')
            ->attribute('maxlength', 190);
        if ($isSystemAccount) {
            $username->readonly()->help(__('organization.accounts.system_account_intro'));
        }

        $form->text('name', __('organization.accounts.name'))
            ->rules('required|max:255')
            ->attribute('maxlength', 255);
        $form->password('password', __('organization.accounts.password'))
            ->rules($account ? 'nullable|min:8|confirmed' : 'required|min:8|confirmed')
            ->help($account ? __('organization.accounts.password_hint_update') : __('organization.accounts.password_hint_create'))
            ->customFormat(fn ($value) => '')
            ->attribute('autocomplete', 'new-password');
        $form->password('password_confirmation', __('organization.accounts.password_confirmation'))
            ->rules($account ? 'nullable' : 'required')
            ->attribute('autocomplete', 'new-password');
        $form->ignore(['password_confirmation']);

        if (!$isSystemAccount) {
            $form->radio('status', __('organization.common.status'))
                ->options([
                    1 => __('organization.common.enabled'),
                    0 => __('organization.common.disabled'),
                ])
                ->default(1);

            $form->html(function () use ($memberships, $departmentOptions, $positions): string {
                return view('admin.organization.accounts.partials.memberships', [
                    'memberships' => $memberships,
                    'departmentOptions' => $departmentOptions,
                    'positions' => $positions,
                ])->render();
            })->plain();
        } else {
            $form->html('<div class="alert alert-info organization-form-note"><i class="fa fa-shield"></i> '.e(__('organization.accounts.system_account_intro')).'</div>')->plain();
        }

        return $form;
    }

    private function requestMemberships(Request $request): array
    {
        return (array) $request->input('memberships', []);
    }

    private function accountRules(?AdminUser $account = null): array
    {
        $usersTable = config('admin.database.users_table');
        $connection = config('admin.database.connection');
        $uniqueTable = $connection ? $connection.'.'.$usersTable : $usersTable;
        $usernameRule = Rule::unique($uniqueTable, 'username');

        if ($account) {
            $usernameRule->ignore($account->getKey());
        }

        return [
            'username' => [$account?->isAdministrator() ? 'nullable' : 'required', 'string', 'max:190', $usernameRule],
            'name' => ['required', 'string', 'max:255'],
            'password' => [$account ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'status' => ['nullable', 'boolean'],
            'memberships' => $account?->isAdministrator()
                ? ['nullable', 'array']
                : ($account ? ['nullable', 'array'] : ['required', 'array', 'min:1']),
            'memberships.*.department_id' => ['nullable', 'integer'],
            'memberships.*.position' => ['nullable', 'string'],
        ];
    }

    private function accountMessages(): array
    {
        return [
            'username.required' => __('organization.validation.username_required'),
            'username.max' => __('organization.validation.username_max'),
            'username.unique' => __('organization.accounts.username_exists'),
            'name.required' => __('organization.validation.name_account_required'),
            'password.required' => __('organization.validation.password_min', ['min' => 8]),
            'password.min' => __('organization.validation.password_min'),
            'password.confirmed' => __('organization.validation.password_confirmed'),
            'memberships.required' => __('organization.accounts.memberships_required'),
            'memberships.min' => __('organization.accounts.memberships_required'),
        ];
    }

    private function authorizeOrganizationManagement(): void
    {
        abort_unless(
            $this->authorization->canManageOrganization($this->administrator()),
            403,
            __('organization.authorization.department_manager_only'),
        );
    }

    private function authorizeAccountManagement(AdminUser $account): void
    {
        abort_unless(
            $this->authorization->canManageAccount($this->administrator(), $account),
            403,
            __('organization.authorization.account_out_of_scope'),
        );
    }

    private function administrator(): AdminUser
    {
        /** @var AdminUser $administrator */
        $administrator = Admin::user();

        return $administrator;
    }

    private function connectionName(): ?string
    {
        return config('admin.database.connection') ?: config('database.default');
    }
}

