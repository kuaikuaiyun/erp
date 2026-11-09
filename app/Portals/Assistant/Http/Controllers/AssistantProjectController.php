<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssistantProject;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantAdvertisingScopeService;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AssistantProjectController extends Controller
{
    public function __construct(private readonly PortalEntryController $portalEntries, private readonly AssistantPortalAuthorizationService $authorization, private readonly AssistantAdvertisingScopeService $scope) {}

    public function index(Request $request): Response|View
    {
        $context = $this->context($request); if ($context instanceof Response) return $context;
        $department = $this->scope->selectedDepartment($context['user'], $request->input('assistant_department_id'));
        $projects = AssistantProject::query()
            ->where('assistant_department_id', $department->id)
            ->with(['websiteSystems' => fn ($query) => $query->orderBy('sort')->orderBy('id')])
            ->orderBy('sort')->orderBy('id')->get();
        $projects->each(function (AssistantProject $project): void {
            $names = $project->websiteSystems->map(fn ($system): string => $system->name.($system->is_enabled ? '' : '（已停用）'));
            $project->setAttribute('website_systems_count', $names->implode('、') ?: '未关联');
        });

        return view('portals.assistant.projects.index', ['portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'], 'projects' => $projects, 'assistantDepartments' => $this->scope->departmentsFor($context['user']), 'selectedAssistantDepartment' => $department]);
    }

    public function store(Request $request): RedirectResponse
    {
        $context = $this->authorizedContext($request); $department = $this->scope->selectedDepartment($context['user'], $request->input('assistant_department_id'));
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('assistant_projects')->where('assistant_department_id', $department->id)]], ['name.required' => '请输入项目名称。', 'name.unique' => '该项目已经存在。']);
        $sort = ((int) AssistantProject::query()->where('assistant_department_id', $department->id)->max('sort')) + 10;
        AssistantProject::query()->create([...$data, 'assistant_department_id' => $department->id, 'is_enabled' => true, 'sort' => $sort]);
        return back()->with('success', '项目添加成功。');
    }

    public function update(Request $request, AssistantProject $project): RedirectResponse
    {
        $this->assertAccess($request, $project); $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('assistant_projects')->where('assistant_department_id', $project->assistant_department_id)->ignore($project->id)]]);
        $project->update($data); return back()->with('success', '项目修改成功。');
    }

    public function toggle(Request $request, AssistantProject $project): RedirectResponse
    {
        $this->assertAccess($request, $project); $project->update(['is_enabled' => ! $project->is_enabled]); return back()->with('success', $project->is_enabled ? '项目已启用。' : '项目已停用。');
    }

    public function destroy(Request $request, AssistantProject $project): RedirectResponse
    {
        $this->assertAccess($request, $project); abort_if($project->websiteSystems()->exists(), 422, '该项目已关联建站系统，请先删除或调整关联数据。'); $project->delete(); return back()->with('success', '项目已删除。');
    }

    private function assertAccess(Request $request, AssistantProject $project): void { $context = $this->authorizedContext($request); abort_unless($this->scope->canAccessDepartment($context['user'], $project->assistant_department_id), 403); }
    private function authorizedContext(Request $request): array { $context = $this->context($request); if ($context instanceof Response) abort($context->getStatusCode()); return $context; }
    private function context(Request $request): array|Response { $context = $this->portalEntries->portalContext($request); if ($context instanceof Response) return $context; $this->authorization->assertAssistantPortal($context['portal']); return $context; }
}
