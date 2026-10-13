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
use Encore\Admin\Grid\Tools;
use Encore\Admin\Layout\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
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
            ->title(__('organization.departments.title'))
            ->description(__('organization.departments.description'))
            ->body($this->grid());
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

            return '<div class="organization-capacity-summary">'.implode('', $statistics).'</div>';
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

        $grid->tools(function (Tools $tools): void {
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
            'parent_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ];
    }

    private function departmentMessages(): array
    {
        return [
            'name.required' => __('organization.validation.name_required'),
            'name.max' => __('organization.validation.name_max'),
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


