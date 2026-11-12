<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\DailyReportDepartmentScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class DailyReportDepartmentSettingController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly DailyReportDepartmentScopeService $departmentScopes,
    ) {}

    public function edit(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->departmentScopes->canAccessSettings($context['user']), 403);

        $canConfigure = $this->departmentScopes->canConfigure($context['user']);

        return view('portals.assistant.daily-submission-department-settings', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'],
            'canConfigure' => $canConfigure,
            'departments' => $canConfigure ? $this->departmentScopes->allowedDepartments($context['user']) : collect(),
            'selectedDepartmentIds' => $canConfigure ? $this->departmentScopes->selectedIds($context['user']) : [],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->departmentScopes->canConfigure($context['user']), 403);
        $allowedIds = $this->departmentScopes->allowedIds($context['user']);
        $validated = $request->validate([
            'department_ids' => ['required', 'array', 'min:1'],
            'department_ids.*' => ['integer', 'distinct', Rule::in($allowedIds)],
        ], ['department_ids.required' => '请至少选择一个需要显示的运营部门。', 'department_ids.min' => '请至少选择一个需要显示的运营部门。']);
        $ids = collect($validated['department_ids'])->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $this->departmentScopes->replace($context['user'], $ids);

        return redirect()->route('assistant.daily-submission-departments.edit')
            ->with('success', '部门显示范围已保存，日常提报系统五个菜单将统一使用此设置。');
    }
}
