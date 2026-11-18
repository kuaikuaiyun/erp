<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Department;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\OperationDashboardDepartmentScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class OperationDashboardDepartmentScopeController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly OperationDashboardDepartmentScopeService $scopes,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        abort_unless($this->scopes->canManage($context['user']), 403);

        $keyword = trim((string) $request->query('account_keyword', ''));
        $canManage = $this->scopes->canManage($context['user']);
        $users = $canManage ? $this->scopes->configurableUsers($keyword) : collect([$context['user']]);
        $selectedUserId = $canManage
            ? (int) ($request->query('user_id') ?: ($users->first()?->getKey() ?? 0))
            : (int) $context['user']->getKey();
        abort_if($selectedUserId > 0 && ! $users->contains(fn (AdminUser $user): bool => (int) $user->getKey() === $selectedUserId), 403);

        $departments = $this->orderDepartmentsByHierarchy(
            Department::business()->whereIn('id', $this->scopes->allDepartmentIds())
                ->get(['id', 'parent_id', 'name', 'sort'])
        );

        return view('portals.operation-dashboard.department-scopes.index', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'canManage' => $canManage,
            'users' => $users,
            'keyword' => $keyword,
            'selectedUserId' => $selectedUserId,
            'selectedDepartmentIds' => $selectedUserId > 0 ? $this->scopes->selectedDepartmentIds($selectedUserId) : [],
            'departments' => $departments,
        ]);
    }

    public function update(Request $request): RedirectResponse|Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        abort_unless($this->scopes->canManage($context['user']), 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'distinct'],
        ]);
        $target = AdminUser::query()->where('status', AdminUser::STATUS_ACTIVE)->findOrFail((int) $data['user_id']);
        abort_if($this->scopes->canManage($target), 422, '系统管理员账号始终拥有全部部门权限。');
        $this->scopes->save($target, $data['department_ids'] ?? [], $context['user']);

        return redirect()->route('operation-dashboard.department-scopes.index', ['user_id' => $target->getKey()])
            ->with('success', empty($data['department_ids']) ? '已设置为可查看全部运营部门。' : '账号部门查看权限已保存。');
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless((string) $context['portal']->code === 'yunying-kanban', 404);

        return $context;
    }

    private function orderDepartmentsByHierarchy(Collection $departments): Collection
    {
        $departments = $departments->sort(function (Department $left, Department $right): int {
            return [(int) $left->sort, (string) $left->name, (int) $left->getKey()]
                <=> [(int) $right->sort, (string) $right->name, (int) $right->getKey()];
        })->values();
        $departmentIds = $departments->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $departmentLookup = array_fill_keys($departmentIds, true);
        $children = $departments->groupBy(fn (Department $department): int => (int) ($department->parent_id ?? 0));
        $ordered = collect();
        $visited = [];

        $append = function (Department $department, int $depth, array $parentNames = [], ?int $rootId = null, ?string $rootName = null) use (&$append, &$visited, $children, $ordered): void {
            $id = (int) $department->getKey();
            if (isset($visited[$id])) return;

            $rootId ??= $id;
            $rootName ??= (string) $department->name;
            $visited[$id] = true;
            $department->setAttribute('hierarchy_depth', $depth);
            $department->setAttribute('hierarchy_parent_names', $parentNames);
            $department->setAttribute('hierarchy_root_id', $rootId);
            $department->setAttribute('hierarchy_root_name', $rootName);
            $ordered->push($department);

            $nextParentNames = [...$parentNames, (string) $department->name];
            foreach ($children->get($id, collect()) as $child) {
                $append($child, $depth + 1, $nextParentNames, $rootId, $rootName);
            }
        };

        foreach ($departments as $department) {
            $parentId = (int) ($department->parent_id ?? 0);
            if ($parentId === 0 || ! isset($departmentLookup[$parentId])) {
                $append($department, 0);
            }
        }

        // Preserve any malformed/cyclic legacy departments instead of hiding them.
        foreach ($departments as $department) {
            $append($department, 0);
        }

        return $ordered;
    }
}
