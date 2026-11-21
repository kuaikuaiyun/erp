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
            ->body($this->grid())
            ->body($this->dingtalkModals())
            ->body($this->companyAssignmentModal())
            ->body(view('admin.organization.accounts.transfer')->render());
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
                'hired_at' => $payload['hired_at'] ?? null,
            ]);
            $account->company_id = $payload['company_id'] ?? null;
            $account->employment_status = $payload['employment_status'];
            $account->resigned_at = $payload['resigned_at'] ?? null;
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
            if (array_key_exists('company_id', $payload)) $account->company_id = $payload['company_id'];
            $account->hired_at = $payload['hired_at'] ?? null;
            $account->resigned_at = $payload['resigned_at'] ?? null;
            $account->employment_status = $payload['employment_status'];

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
        $grid->model()->with(['departments','company','dingTalkBindings.identity'])->orderBy('id');
        if (!$actor->isAdministrator()) {
            $managedDepartmentIds = $this->authorization->managedDepartmentIds($actor);
            $departmentsTable = (new Department())->getTable();
            $grid->model()->whereHas('departments', function ($query) use ($managedDepartmentIds, $departmentsTable): void {
                $query->whereIn($departmentsTable.'.id', $managedDepartmentIds->all());
            });
        }
        $grid->disableExport();

        $departmentTreeOptions = $this->organization->departmentOptionsFor($actor)->values();
        $availableDepartmentIds = $departmentTreeOptions->pluck('id')->map(static fn ($id): int => (int) $id);
        $departmentTreeOptions = $departmentTreeOptions->map(function (array $option) use ($availableDepartmentIds): array {
            if ($option['parent_id'] !== null && !$availableDepartmentIds->contains((int) $option['parent_id'])) {
                $option['parent_id'] = null;
            }

            return $option;
        });
        $departmentOptions = $departmentTreeOptions
            ->mapWithKeys(static fn (array $option): array => [
                (int) $option['id'] => (string) $option['label'],
            ])
            ->all();

        Admin::style(view('admin.organization.account-department-filter', [
            'mode' => 'style',
        ])->render());
        Admin::script(view('admin.organization.account-department-filter', [
            'mode' => 'script',
            'departments' => $departmentTreeOptions->all(),
            'texts' => [
                'all' => __('organization.accounts.department_filter_all'),
                'selected' => __('organization.accounts.department_filter_selected'),
                'search' => __('organization.accounts.department_filter_search'),
                'clear' => __('organization.accounts.department_filter_clear'),
                'empty' => __('organization.accounts.department_filter_empty'),
            ],
        ])->render());
        $grid->filter(function (Filter $filter) use ($departmentOptions): void {
            $filter->disableIdFilter();
            $filter->column(0.5, function (Filter $filter): void {
                $filter->like('username', __('organization.accounts.username'));
                $filter->like('name', __('organization.accounts.name'));
            });
            $filter->column(0.5, function (Filter $filter) use ($departmentOptions): void {
                $filter->where(function ($query): void {
                    $departmentIds = collect((array) $this->input)
                        ->filter(static fn ($departmentId): bool => is_numeric($departmentId))
                        ->map(static fn ($departmentId): int => (int) $departmentId)
                        ->unique()
                        ->values()
                        ->all();

                    if ($departmentIds === []) {
                        return;
                    }

                    $departmentsTable = (new Department())->getTable();
                    $query->whereHas('departments', function ($departmentQuery) use ($departmentIds, $departmentsTable): void {
                        $departmentQuery->whereIn($departmentsTable.'.id', $departmentIds);
                    });
                }, __('organization.accounts.department_filter'), 'department_ids')->multipleSelect($departmentOptions);
            });
        });

        $grid->column('id', __('organization.common.id'))->sortable();
        $grid->column('username', __('organization.accounts.username'));
        $grid->column('name', __('organization.accounts.name'));
        $grid->column('company_affiliation', __('organization.accounts.company'))->display(function (): string {
            $company = $this->company;
            $label = $company ? $company->name.($company->trashed() ? __('organization.accounts.company_archived')
                : (!$company->enabled ? __('organization.accounts.company_disabled') : '')) : __('organization.accounts.company_filter_unassigned');
            return '<span data-account-company="'.(int)$this->id.'">'.e($label).'</span>';
        });
        $grid->column('hired_at', __('organization.accounts.hired_at'))->display(function ($hiredAt): string {
            if (!$hiredAt) return '<span class="text-muted">—</span>';

            return e($hiredAt instanceof \DateTimeInterface ? $hiredAt->format('Y-m-d') : substr((string) $hiredAt, 0, 10));
        })->sortable();
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

            $labels=$memberships->map(fn (Department $department)=>$department->name.' · '.__('organization.positions.'.$department->pivot->position))->all();
            return AccountController::accountLabels($labels,__('organization.accounts.department_summary'));
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
        $bindingAccess = app(\App\Services\DingTalk\BindingAccessService::class);
        $canManageBindings = $bindingAccess->canAccess($actor);
        $bindingAccountIds = $canManageBindings && !$bindingAccess->isOwner($actor) ? $bindingAccess->accounts()->pluck('id')->all() : null;
        $companyNames=\App\Models\DingTalkCompany::withTrashed()->pluck('name','corp_id');
        $grid->column('dingtalk_binding',__('dingtalk.labels.binding'))->display(function () use ($companyNames) {
            $labels=$this->dingTalkBindings->filter(fn ($b)=>$b->active && $b->identity && $b->identity->active)
                ->map(fn ($b)=>($companyNames->get($b->identity->corp_id) ?? $b->identity->corp_id).' * '.$b->identity->name)->values()->all();
            return '<span data-binding-labels="'.(int)$this->id.'">'.AccountController::accountLabels($labels,__('dingtalk.labels.binding')).'</span>';
        });
        $grid->actions(function (OrganizationActions $actions) use ($isSystemAdministrator, $canManageBindings, $bindingAccountIds): void {
            $actions->disableView();
            $actions->disableEdit();
            $actions->disableDelete();
            $account = $actions->row;
            $actions->append($actions->editButton());
            if ($canManageBindings && ($bindingAccountIds === null || in_array((int)$account->id, $bindingAccountIds, true))) {
                $actions->append('<button type="button" class="btn btn-xs btn-default organization-grid-action" data-ding-account="'.(int)$account->id.'">'.e(__('dingtalk.mode.binding')).'</button>');
            }

            if (!$account->isAdministrator()) {
                if ($isSystemAdministrator) {
                    $url = admin_url('organization/accounts/'.$account->getKey().'/permissions');
                    $actions->append('<a href="'.e($url).'" class="btn btn-xs btn-info organization-grid-action"><i class="fa fa-key"></i> 页面权限</a>');
                }
                $actions->append($actions->deleteButton());
            }
        });

        $grid->tools(function (Tools $tools) use ($bindingAccess): void {
            if ($this->administrator()->isAdministrator()) {
                foreach (['import','export'] as $action) $tools->append('<button type="button" class="btn btn-sm btn-default" data-account-transfer="'.$action.'">'.e(__('account_transfer.'.$action)).'</button>');
            }
            if ($this->administrator()->isAdministrator()) $tools->append('<button type="button" class="btn btn-sm btn-default" data-account-company-open>'.e(__('organization.company_assignment.title')).'</button>');
            if ($bindingAccess->isOwner()) $tools->append('<button type="button" class="btn btn-sm btn-default" data-ding-grants>'.e(__('dingtalk.company.assign')).'</button>');
            $tools->batch(function (Tools\BatchActions $actions): void {
                $actions->disableDelete();
            });
        });

        return $grid;
    }

    public static function accountLabels(array $labels,string $title): string
    {
        if (!$labels) return '<span class="text-muted">'.e(__('dingtalk.labels.none')).'</span>';
        $html='<span class="erp-account-tags" data-account-tags="'.e(json_encode($labels,JSON_UNESCAPED_UNICODE)).'" data-tag-title="'.e($title).'">';
        foreach (array_slice($labels,0,2) as $label) $html.='<button type="button" class="erp-account-tag" title="'.e($label).'">'.e($label).'</button>';
        if (count($labels)>2) $html.='<button type="button" class="erp-account-more">＋'.(count($labels)-2).'</button>';
        return $html.'</span>';
    }
    private function dingtalkModals(): string
    {
        $owner=app(\App\Services\DingTalk\BindingAccessService::class)->isOwner();
        $options=['base'=>admin_url('organization/accounts'),'owner'=>$owner,'csrf'=>csrf_token()];
        $messages=array_merge(__('dingtalk.manage'),__('dingtalk.workspace'),__('dingtalk.company'),__('dingtalk.labels'));
        return view('admin.organization.dingtalk-modals',compact('owner','options','messages'))->render();
    }

    private function companyAssignmentModal(): string
    {
        return view('admin.organization.accounts.company-assignment', [
            'assignmentOptions' => ['url'=>admin_url('organization/accounts/company-assignment'), 'csrf'=>csrf_token()],
            'assignmentMessages' => __('organization.company_assignment'),
        ])->render();
    }

    private function assignmentInput(Request $request, bool $saving = false): array
    {
        abort_unless($this->administrator()->isAdministrator(), 403, __('organization.authorization.super_admin_only'));
        $rules = ['account_ids'=>['required','array','min:1','max:200'], 'account_ids.*'=>['required','integer','min:1','distinct']];
        if ($saving) $rules['company_id'] = ['required','integer','min:1'];
        return $request->validate($rules, [], ['account_ids'=>__('organization.company_assignment.accounts'), 'company_id'=>__('organization.accounts.company')]);
    }

    private function assignmentAccounts(array $ids, bool $lock = false)
    {
        $model = config('admin.database.users_model');
        $query = $model::query()->with('company')->whereIn('id', $ids)->orderBy('id');
        if ($lock) $query->lockForUpdate();
        $accounts = $query->get();
        if ($accounts->count() !== count($ids)) throw ValidationException::withMessages(['account_ids'=>__('organization.company_assignment.missing')]);
        foreach ($accounts as $account) $this->authorizeAccountManagement($account);
        return $accounts;
    }

    public function companyAssignmentOptions(Request $request): JsonResponse
    {
        $data = $this->assignmentInput($request);
        $accounts = $this->assignmentAccounts($data['account_ids']);
        $companies = \App\Models\DingTalkCompany::query()->where('enabled', true)->orderBy('name')->get(['id','name']);
        return response()->json(['companies'=>$companies, 'accounts'=>$accounts->map(fn ($account)=>[
            'id'=>$account->id, 'name'=>$account->name, 'username'=>$account->username,
            'company'=>$account->company?->name ?? __('organization.accounts.company_filter_unassigned'),
        ])->values()])->header('Cache-Control', 'no-store');
    }

    public function assignCompany(Request $request): JsonResponse
    {
        $data = $this->assignmentInput($request, true);
        $result = DB::connection($this->connectionName())->transaction(function () use ($data): array {
            $company = \App\Models\DingTalkCompany::query()->where('enabled', true)->whereKey($data['company_id'])->lockForUpdate()->first();
            if (!$company) throw ValidationException::withMessages(['company_id'=>__('organization.accounts.company_invalid')]);
            $accounts = $this->assignmentAccounts($data['account_ids'], true);
            $changed = 0;
            foreach ($accounts as $account) {
                if ((int) $account->company_id === (int) $company->id) continue;
                $account->company_id = $company->id;
                $account->save();
                $changed++;
            }
            return ['count'=>$accounts->count(), 'changed'=>$changed, 'company'=>$company->name,
                'account_ids'=>$accounts->pluck('id')->all()];
        }, 3);
        return response()->json($result + ['message'=>__('organization.company_assignment.saved', ['count'=>$result['count'], 'changed'=>$result['changed']])]);
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
        $form->select('company_id', __('organization.accounts.company'))
            ->options($this->companyOptions($account))
            ->placeholder(__('organization.accounts.company_placeholder'))
            ->help(__('organization.accounts.company_help'));
        $form->date('hired_at', __('organization.accounts.hired_at'))
            ->rules('nullable|date')
            ->help(__('organization.accounts.hired_at_help'));
        $form->date('resigned_at', __('organization.accounts.resigned_at'))
            ->rules('nullable|date')
            ->help(__('organization.accounts.resigned_at_help'));
        $form->radio('employment_status', __('organization.accounts.employment_status'))
            ->options([
                'employed' => __('organization.accounts.employment_employed'),
                'resigned' => __('organization.accounts.employment_resigned'),
            ])
            ->default('employed')
            ->rules('required|in:employed,resigned');
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

    private function companyOptions(?AdminUser $account = null): array
    {
        return \App\Models\DingTalkCompany::withTrashed()
            ->where(function ($query) use ($account): void {
                $query->where(function ($active): void {
                    $active->where('enabled', true)->whereNull('deleted_at');
                });
                if ($account?->company_id) $query->orWhere('id', $account->company_id);
            })->orderBy('name')->get()->mapWithKeys(function ($company): array {
                $suffix = $company->trashed() ? __('organization.accounts.company_archived')
                    : (!$company->enabled ? __('organization.accounts.company_disabled') : '');
                return [$company->id => $company->name.$suffix];
            })->all();
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
            'company_id' => ['bail', 'nullable', 'integer', function ($attribute, $value, $fail) use ($account): void {
                $company = \App\Models\DingTalkCompany::withTrashed()->find($value);
                $unchanged = $account && (int) $account->company_id === (int) $value;
                if (!$company || (!$unchanged && (!$company->enabled || $company->trashed()))) {
                    $fail(__('organization.accounts.company_invalid'));
                }
            }],
            'hired_at' => ['nullable', 'date'],
            'resigned_at' => ['nullable', 'date'],
            'employment_status' => ['required', Rule::in(['employed', 'resigned'])],
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
            'company_id.integer' => __('organization.accounts.company_invalid'),
            'username.required' => __('organization.validation.username_required'),
            'resigned_at.date' => __('organization.accounts.resigned_at_invalid'),
            'employment_status.required' => __('organization.accounts.employment_status_required'),
            'employment_status.in' => __('organization.accounts.employment_status_invalid'),
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
