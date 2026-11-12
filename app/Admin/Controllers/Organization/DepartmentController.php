<?php

namespace App\Admin\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Admin\Grid\Displayers\OrganizationActions;
use App\Models\AdminUser;
use App\Models\Department;
use App\Services\DepartmentAuthorizationService;
use App\Services\DepartmentOrganizationService;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Tools;
use Encore\Admin\Layout\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    public function __construct(
        private readonly DepartmentOrganizationService $organization,
        private readonly DepartmentAuthorizationService $authorization,
        private readonly AssistantPortalAuthorizationService $assistantAuthorization,
        private readonly AssistantStoreOwnerService $assistantStoreOwners,
    )
    {
    }

    public function index(Content $content): Content
    {
        return $content
            ->title(__('organization.departments.title'))
            ->description(__('organization.departments.description'))
            ->body($this->grid());
    }

    public function bindings(Content $content): Content
    {
        abort_unless($this->administrator()->isAdministrator(), 403);

        return $content
            ->title('业务部门与助理部门绑定')
            ->description('设置每个助理部门可以关联的业务部门')
            ->body(view('admin.organization.departments.bindings', [
                'assistantDepartments' => $this->assistantAuthorization->assistantChildDepartments(),
                'businessDepartments' => $this->bindableBusinessDepartments(),
                'scopeMap' => $this->assistantStoreOwners->scopeMap(),
            ])->render());
    }

    public function updateBindings(Request $request): RedirectResponse
    {
        $actor = $this->administrator();
        abort_unless($actor->isAdministrator(), 403);

        $validated = $request->validate([
            'bindings' => ['nullable', 'array'],
            'bindings.*' => ['nullable', 'array'],
            'bindings.*.*' => ['integer', 'distinct'],
        ]);
        $assistantIds = $this->assistantAuthorization->assistantChildDepartments()
            ->pluck('id')->map(static fn ($id): int => (int) $id);
        $businessDepartmentIds = $this->bindableBusinessDepartments()
            ->pluck('id')->map(static fn ($id): int => (int) $id);
        $bindings = [];

        foreach ((array) ($validated['bindings'] ?? []) as $assistantDepartmentId => $selectedOperationIds) {
            $assistantDepartmentId = (int) $assistantDepartmentId;
            $selectedOperationIds = collect((array) $selectedOperationIds)
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values();

            if (! $assistantIds->contains($assistantDepartmentId)
                || $selectedOperationIds->diff($businessDepartmentIds)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'bindings' => ['提交的助理部门或业务部门不存在，请刷新页面后重试。'],
                ]);
            }

            $bindings[$assistantDepartmentId] = $selectedOperationIds->all();
        }

        $this->assistantStoreOwners->replaceScopes($bindings, $actor);
        admin_success('部门绑定', '业务部门与助理部门的绑定关系已保存。');

        return redirect(admin_url('organization/departments/bindings'));
    }

    public function members(Department $department, Request $request, Content $content): Content
    {
        $this->authorizeDepartmentManagement($department);
        $department->load(['children', 'departmentMembers.departments']);
        $actor = $this->administrator();
        $filterDepartmentIds = $actor->isAdministrator()
            ? Department::business()->pluck('id')->map(fn ($id): int => (int) $id)
            : $this->authorization->managedDepartmentIds($actor);
        $memberDepartmentOptions = Department::business()
            ->whereIn('id', $filterDepartmentIds)
            ->where('id', '!=', $department->getKey())
            ->orderByRaw('CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('sort')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name']);
        $memberDepartmentGroups = $memberDepartmentOptions
            ->filter(fn (Department $option): bool => ! $option->parent_id
                || ! $memberDepartmentOptions->contains('id', (int) $option->parent_id))
            ->map(fn (Department $option): array => [
                'department' => $option,
                'children' => $memberDepartmentOptions->where('parent_id', $option->getKey())->values(),
            ])->values();
        $memberFilters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'member_department_id' => ['nullable', 'integer', Rule::in($memberDepartmentOptions->pluck('id')->all())],
        ]);
        $search = trim((string) ($memberFilters['search'] ?? ''));
        $selectedMemberDepartmentId = (int) ($memberFilters['member_department_id'] ?? 0);
        $userModel = config('admin.database.users_model');
        $candidateUsers = $userModel::query()
            ->with('departments')
            ->where('status', AdminUser::STATUS_ACTIVE)
            ->whereDoesntHave('roles', fn ($query) => $query->whereKey($department->getKey()))
            ->whereDoesntHave('roles', fn ($query) => $query->where('slug', Department::SYSTEM_SLUG))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(fn ($nested) => $nested->where('name', 'like', '%'.$search.'%')
                    ->orWhere('username', 'like', '%'.$search.'%'));
            })
            ->when($selectedMemberDepartmentId > 0, function ($query) use ($selectedMemberDepartmentId): void {
                $query->whereHas('departments', fn ($departments) => $departments->whereKey($selectedMemberDepartmentId));
            })
            ->orderBy('name')->orderBy('username')->paginate(20)->withQueryString();

        return $content
            ->title('部门成员')
            ->description($department->name)
            ->body(view('admin.organization.departments.members', [
                'department' => $department,
                'members' => $department->departmentMembers->sortBy(fn (AdminUser $user) => $user->name ?: $user->username)->values(),
                'candidateUsers' => $candidateUsers,
                'search' => $search,
                'memberDepartmentOptions' => $memberDepartmentOptions,
                'memberDepartmentGroups' => $memberDepartmentGroups,
                'selectedMemberDepartmentId' => $selectedMemberDepartmentId,
                'positions' => $this->organization->positionOptions(),
                'canAddMembers' => $department->children->isEmpty(),
            ])->render());
    }

    public function addMember(Department $department, Request $request): RedirectResponse
    {
        $this->authorizeDepartmentManagement($department);
        abort_if($department->children()->exists(), 422, '一级部门存在下级部门，请将人员添加到对应的末级部门。');
        $userModel = config('admin.database.users_model');
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['required', 'integer', 'distinct', Rule::exists((new $userModel())->getTable(), 'id')],
            'return_to' => ['nullable', 'string', 'max:2000'],
        ]);
        $managedIds = $this->administrator()->isAdministrator()
            ? Department::business()->pluck('id')->map(fn ($id) => (int) $id)
            : $this->authorization->managedDepartmentIds($this->administrator());
        $accounts = $userModel::query()->whereKey($validated['user_ids'])->get();
        abort_if($accounts->contains(fn (AdminUser $account): bool => $account->isAdministrator()), 422, '系统管理员不能添加到业务部门。');

        DB::transaction(function () use ($accounts, $managedIds, $department): void {
            foreach ($accounts as $account) {
                $memberships = $this->organization->membershipsForAccount($account)
                    ->filter(fn ($membership) => $managedIds->contains((int) $membership->department_id))
                    ->map(fn ($membership): array => [
                        'department_id' => (int) $membership->department_id,
                        'position' => (string) $membership->position,
                    ])->values();
                if (! $memberships->contains(fn (array $membership) => $membership['department_id'] === (int) $department->getKey())) {
                    $memberships->push(['department_id' => (int) $department->getKey(), 'position' => Department::POSITION_MEMBER]);
                }
                $this->organization->syncAccountMemberships($account, $memberships->all(), $this->administrator());
            }
        });
        admin_success('部门成员', $accounts->count().' 人已添加到当前部门。');

        $defaultUrl = admin_url('organization/departments/'.$department->getKey().'/members');
        $returnTo = (string) ($validated['return_to'] ?? '');
        $returnParts = parse_url($returnTo);
        $defaultParts = parse_url($defaultUrl);
        if ($returnTo !== '' && is_array($returnParts) && is_array($defaultParts)
            && ($returnParts['host'] ?? null) === ($defaultParts['host'] ?? null)
            && ($returnParts['path'] ?? null) === ($defaultParts['path'] ?? null)) {
            return redirect()->to($returnTo);
        }

        return redirect($defaultUrl);
    }

    private function bindableBusinessDepartments()
    {
        $assistantRoot = Department::business()
            ->where('slug', AssistantPortalAuthorizationService::EDITOR_DEPARTMENT_CODE)
            ->first();
        $excludedIds = $assistantRoot
            ? $assistantRoot->children()->pluck('id')->push($assistantRoot->id)->all()
            : [];

        return Department::business()
            ->whereNotIn('id', $excludedIds)
            ->with('parent:id,name')
            ->orderByRaw('CASE WHEN parent_id = 0 OR parent_id IS NULL THEN id ELSE parent_id END')
            ->orderBy('parent_id')
            ->orderBy('sort')
            ->orderBy('name')
            ->get();
    }

    public function create(Request $request, Content $content): Content
    {
        $parent = $request->filled('parent_id')
            ? $this->organization->findBusinessDepartment((int) $request->integer('parent_id'))
            : null;

        if ($parent && $parent->parent_id) {
            abort(422, __('organization.departments.second_level_only'));
        }

        $this->authorizeDepartmentCreation($parent);

        return $content
            ->title($parent ? __('organization.departments.create_child') : __('organization.departments.create_root'))
            ->description(__('organization.departments.title'))
            ->body($this->form(null, $parent));
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate($this->departmentRules(), $this->departmentMessages());
        $parent = !empty($payload['parent_id'])
            ? $this->organization->findBusinessDepartment((int) $payload['parent_id'])
            : null;
        $this->authorizeDepartmentCreation($parent);
        $department = $this->organization->createDepartment(
            $payload,
            (array) $request->input('reassignments', []),
            $this->administrator(),
        );

        admin_success(__('organization.departments.title'), __('organization.departments.save_created'));

        return redirect(admin_url('organization/departments'));
    }

    public function edit(Department $department, Content $content): Content
    {
        $this->authorizeDepartmentManagement($department);

        return $content
            ->title(__('organization.departments.edit_department'))
            ->description($department->name)
            ->body($this->form($department, $department->parent)->edit($department->getKey()));
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $this->authorizeDepartmentManagement($department);
        $payload = $request->validate($this->departmentRules(), $this->departmentMessages());
        $this->organization->updateDepartment($department, $payload, $this->administrator());

        admin_success(__('organization.departments.title'), __('organization.departments.save_updated'));

        return redirect(admin_url('organization/departments'));
    }

    public function destroy(Department $department): RedirectResponse|JsonResponse
    {
        $this->authorizeDepartmentManagement($department);

        try {
            $this->organization->deleteDepartment($department, $this->administrator());
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
                'message' => __('organization.departments.deleted'),
            ]);
        }

        admin_success(__('organization.departments.title'), __('organization.departments.deleted'));

        return redirect(admin_url('organization/departments'));
    }

    /**
     * Shows the managed dissolution workflow for a populated leaf department.
     * This allows its people to be reassigned before the department is removed.
     */
    public function dissolution(Department $department, Content $content): Content
    {
        $this->authorizeDepartmentDissolution($department);
        $context = $this->organization->dissolutionContext($department);

        return $content
            ->title(__('organization.dissolution.title'))
            ->description($department->name)
            ->body(view('admin.organization.departments.dissolution', $context)->render());
    }

    public function dissolve(Request $request, Department $department): RedirectResponse
    {
        $this->authorizeDepartmentDissolution($department);
        $this->organization->dissolveDepartment(
            $department,
            (array) $request->input('reassignments', []),
            $this->administrator(),
        );

        admin_success(__('organization.dissolution.title'), __('organization.dissolution.completed'));

        return redirect(admin_url('organization/departments'));
    }

    private function grid(): Grid
    {
        $actor = $this->administrator();
        $isSystemAdministrator = $actor->isAdministrator();
        $managedDepartmentIds = $isSystemAdministrator
            ? collect()
            : $this->authorization->managedDepartmentIds($actor);
        $grid = new Grid(new Department());

        $grid->setName('organization-departments');
        $grid->setActionClass(OrganizationActions::class);
        $grid->model()
            ->where('slug', '!=', Department::SYSTEM_SLUG)
            ->with(['children', 'departmentMembers', 'permissions'])
            ->orderByRaw('COALESCE(parent_id, id)')
            ->orderByRaw('CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('sort')
            ->orderBy('name');
        if (!$isSystemAdministrator) {
            $grid->model()->whereIn('id', $managedDepartmentIds->all());
            $grid->disableCreateButton();
        }
        $grid->disableExport();
        $grid->disableFilter();

        $grid->column('name', __('organization.departments.department_name'))->display(function (string $name) use ($isSystemAdministrator, $managedDepartmentIds): string {
            $isChild = (bool) $this->parent_id;
            $hasVisibleParent = $isSystemAdministrator || $managedDepartmentIds->contains((int) $this->parent_id);
            $url = admin_url('organization/departments/'.$this->getKey().'/edit');
            $hasChildren = $this->children->isNotEmpty();
            $isVisibleChild = $isChild && $hasVisibleParent;
            $levelLabel = $isChild ? __('organization.departments.level_child') : __('organization.departments.level_root');
            $nodeClass = !$isChild ? 'organization-tree-root' : ($isVisibleChild ? 'organization-tree-child' : 'organization-tree-standalone');
            $connector = $isVisibleChild
                ? '<span class="organization-tree-branch" data-tree-child-of="'.e((string) $this->parent_id).'" aria-hidden="true"><i class="fa fa-level-down"></i></span>'
                : '';
            $toggle = !$isChild && $hasChildren
                ? '<button type="button" class="organization-tree-toggle" data-department-tree-toggle data-tree-department-id="'.e((string) $this->getKey()).'" aria-expanded="true" title="'.e(__('organization.departments.collapse_children')).'" data-collapse-label="'.e(__('organization.departments.collapse_children')).'" data-expand-label="'.e(__('organization.departments.expand_children')).'"><i class="fa fa-chevron-down"></i></button>'
                : '';

            return '<span class="organization-tree-node '.$nodeClass.'">'.$connector.$toggle
                .'<span class="organization-tree-name"><i class="fa '.($isChild ? 'fa-building-o' : 'fa-sitemap').' text-aqua"></i> <a href="'.e($url).'"><strong>'.e($name).'</strong></a> '
                .'<span class="label '.($isChild ? 'label-default' : 'label-primary').'">'.e($levelLabel).'</span>'
                .'<br><small class="text-muted organization-tree-code">'.e($this->slug).'</small></span></span>';
        });
        $grid->column('short_name', __('organization.departments.short_name'))->display(function ($shortName): string {
            $shortName = trim((string) $shortName);

            return $shortName === ''
                ? '<span class="text-muted">'.e(__('organization.common.not_set')).'</span>'
                : e($shortName);
        });
        $grid->column('people_structure', __('organization.departments.people_structure'))->display(function (): string {
            $counts = $this->departmentMembers->groupBy(fn (AdminUser $user) => $user->pivot->position)->map->count();
            $managerCount = (int) ($counts[Department::POSITION_MANAGER] ?? 0);
            $deputyCount = (int) ($counts[Department::POSITION_DEPUTY] ?? 0);
            $memberCount = (int) ($counts[Department::POSITION_MEMBER] ?? 0);
            $statistics = [
                '<span class="organization-capacity-item'.($managerCount >= 1 ? ' is-full' : '').'"><span>'.e(__('organization.departments.manager')).'</span><strong>'.e($managerCount.'/1').'</strong></span>',
            ];

            if ($this->children->isEmpty()) {
                $statistics[] = '<span class="organization-capacity-item'.($deputyCount >= 2 ? ' is-full' : '').'"><span>'.e(__('organization.departments.deputy')).'</span><strong>'.e($deputyCount.'/2').'</strong></span>';
                $statistics[] = '<span class="organization-capacity-item"><span>'.e(__('organization.departments.member')).'</span><strong>'.e((string) $memberCount).'</strong></span>';
            }

            $url = admin_url('organization/departments/'.$this->getKey().'/members');

            return '<a href="'.e($url).'" class="organization-capacity-link" style="display:inline-block;color:inherit;text-decoration:none" title="查看部门成员"><div class="organization-capacity-summary">'.implode('', $statistics).'</div></a>';
        });
        if ($isSystemAdministrator) {
            $grid->column('authorized_pages', __('organization.departments.authorized_pages'))->display(function (): string {
                $url = admin_url('organization/departments/'.$this->getKey().'/permissions');

                return '<a href="'.e($url).'">'.e(__('organization.common.items', ['count' => $this->permissions->count()])).'</a>';
            });
        }
        $grid->column('updated_at', __('organization.common.updated_at'))->display(function ($updatedAt): string {
            if (!$updatedAt) {
                return '';
            }

            return e($updatedAt instanceof \DateTimeInterface ? $updatedAt->format('Y-m-d H:i') : (string) $updatedAt);
        });

        $authorization = $this->authorization;
        $grid->actions(function (OrganizationActions $actions) use ($actor, $isSystemAdministrator, $authorization): void {
            $actions->disableView();
            $actions->disableEdit();
            $actions->disableDelete();
            $department = $actions->row;
            $departmentId = $department->getKey();
            $actions->append($actions->editButton());

            if ($isSystemAdministrator) {
                $permissionUrl = admin_url('organization/departments/'.$departmentId.'/permissions');
                $actions->append('<a href="'.e($permissionUrl).'" class="btn btn-xs btn-info organization-grid-action" title="'.e(__('organization.departments.view_permissions')).'"><i class="fa fa-key"></i> '.e(__('organization.departments.view_permissions')).'</a>');
            }

            if (!$department->parent_id && $authorization->canCreateChildDepartment($actor, $department)) {
                $childUrl = admin_url('organization/departments/create?parent_id='.$departmentId);
                $actions->append('<a href="'.e($childUrl).'" class="btn btn-xs btn-default organization-grid-action" title="'.e(__('organization.departments.create_child')).'"><i class="fa fa-plus-square-o"></i> '.e(__('organization.departments.create_child')).'</a>');
            }

            $hasMembers = $department->departmentMembers->isNotEmpty();

            if ($hasMembers && $department->children->isEmpty()) {
                if ($isSystemAdministrator) {
                    $dissolutionUrl = admin_url('organization/departments/'.$departmentId.'/dissolution');
                    $actions->append('<a href="'.e($dissolutionUrl).'" class="btn btn-xs btn-warning organization-grid-action" title="'.e(__('organization.dissolution.action')).'"><i class="fa fa-random"></i> '.e(__('organization.dissolution.action')).'</a>');
                }
            } else {
                $actions->append($actions->deleteButton());
            }
        });

        $grid->tools(function (Tools $tools) use ($isSystemAdministrator): void {
            if ($isSystemAdministrator) {
                $tools->append('<a href="'.e(admin_url('organization/departments/bindings')).'" class="btn btn-sm btn-primary"><i class="fa fa-link"></i> 运营/助理部门绑定</a>');
            }
            $tools->batch(function (Tools\BatchActions $actions): void {
                $actions->disableDelete();
            });
        });

        return $grid;
    }

    private function form(?Department $department, ?Department $parent): Form
    {
        $actor = $this->administrator();
        $isEditing = $department !== null;
        $affectedMembers = !$isEditing && $parent
            ? $this->organization->affectedMembersForSplit($parent)
            : collect();
        $form = new Form(new Department());

        $form->setTitle($isEditing ? __('organization.departments.edit_department') : ($parent ? __('organization.departments.create_child') : __('organization.departments.create_root')));
        $form->setWidth(8, 2);
        $form->disableViewCheck();
        $form->disableEditingCheck();
        $form->disableCreatingCheck();
        $form->tools(function (Form\Tools $tools): void {
            $tools->disableView();
            $tools->disableDelete();
        });
        $form->html('<span data-organization-department-form hidden></span>')->plain();

        $form->text('name', __('organization.departments.department_name'))
            ->rules('required|max:50')
            ->attribute('maxlength', 50);
        $form->text('short_name', __('organization.departments.short_name'))
            ->rules('nullable|max:50')
            ->attribute('maxlength', 50)
            ->help(__('organization.departments.short_name_help'));
        $form->text('slug', __('organization.departments.department_code'))
            ->default(__('organization.departments.code_pending'))
            ->readonly()
            ->help(__('organization.departments.code_auto_help'));
        $form->hidden('parent_id')->default($parent?->getKey());
        $form->html(function () use ($parent): string {
            $parentName = $parent?->name ?? __('organization.departments.root_department_hint');

            return view('admin.organization.departments.partials.parent-context', [
                'parentName' => $parentName,
            ])->render();
        })->plain();
        $form->number('sort', __('organization.departments.sort'))
            ->default(0)
            ->attribute('min', 0)
            ->attribute('max', 999999)
            ->help(__('organization.departments.sort_help'));

        if ($isEditing && !$department->parent_id) {
            $canCreateChild = $this->authorization->canCreateChildDepartment($actor, $department);
            $form->html(function () use ($department, $canCreateChild): string {
                return view('admin.organization.departments.partials.child-departments', [
                    'department' => $department->loadMissing('children'),
                    'canCreateChild' => $canCreateChild,
                ])->render();
            })->plain();
        }

        if (!$isEditing && $parent) {
            $departmentOptions = $this->organization->departmentOptionsFor($actor);
            $positions = $this->organization->positionOptions();

            $form->html(function () use ($affectedMembers, $parent, $departmentOptions, $positions): string {
                return view('admin.organization.departments.partials.split-reassignments', [
                    'parent' => $parent,
                    'affectedMembers' => $affectedMembers,
                    'departmentOptions' => $departmentOptions,
                    'positions' => $positions,
                ])->render();
            })->plain();
        }

        return $form;
    }

    private function departmentRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'short_name' => ['nullable', 'string', 'max:50'],
            'parent_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ];
    }

    private function departmentMessages(): array
    {
        return [
            'name.required' => __('organization.validation.name_required'),
            'name.max' => __('organization.validation.name_max'),
            'short_name.max' => __('organization.validation.short_name_max'),
            'parent_id.integer' => __('organization.departments.invalid_parent'),
            'sort.integer' => __('organization.validation.sort_integer'),
        ];
    }

    private function authorizeDepartmentManagement(Department $department): void
    {
        abort_if($department->isSystemDepartment(), 404);
        abort_unless(
            $this->authorization->canManageDepartment($this->administrator(), $department),
            403,
            __('organization.authorization.department_out_of_scope'),
        );
    }

    private function authorizeDepartmentCreation(?Department $parent): void
    {
        $actor = $this->administrator();

        if (!$parent) {
            abort_unless($actor->isAdministrator(), 403, __('organization.authorization.super_admin_only'));

            return;
        }

        abort_unless(
            $this->authorization->canCreateChildDepartment($actor, $parent),
            403,
            __('organization.authorization.department_out_of_scope'),
        );
    }

    private function authorizeDepartmentDissolution(Department $department): void
    {
        abort_if($department->isSystemDepartment(), 404);
        abort_unless($this->administrator()->isAdministrator(), 403, __('organization.authorization.super_admin_only'));
    }

    private function administrator(): AdminUser
    {
        /** @var AdminUser $administrator */
        $administrator = Admin::user();

        return $administrator;
    }
}
