<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssistantProject;
use App\Models\AssistantWebsiteSystem;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantAdvertisingScopeService;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AssistantWebsiteSystemController extends Controller
{
    public function __construct(private readonly PortalEntryController $portalEntries, private readonly AssistantPortalAuthorizationService $authorization, private readonly AssistantAdvertisingScopeService $scope) {}

    public function index(Request $request): Response|View
    {
        $context = $this->context($request); if ($context instanceof Response) return $context;
        $department = $this->scope->selectedDepartment($context['user'], $request->input('assistant_department_id'));
        return view('portals.assistant.website-systems.index', ['portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'], 'systems' => AssistantWebsiteSystem::query()->where('assistant_department_id', $department->id)->with('project')->orderBy('sort')->orderBy('id')->get(), 'projects' => AssistantProject::query()->where('assistant_department_id', $department->id)->orderBy('sort')->orderBy('id')->get(), 'assistantDepartments' => $this->scope->departmentsFor($context['user']), 'selectedAssistantDepartment' => $department]);
    }

    public function store(Request $request): RedirectResponse
    {
        $context = $this->authorizedContext($request); $department = $this->scope->selectedDepartment($context['user'], $request->input('assistant_department_id')); $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $this->validateData($request, (int) $department->id);
        $sort = ((int) AssistantWebsiteSystem::query()->where('assistant_department_id', $department->id)->max('sort')) + 10;
        AssistantWebsiteSystem::query()->create([...$data, 'assistant_department_id' => $department->id, 'is_enabled' => true, 'sort' => $sort]); return back()->with('success', '建站系统添加成功。');
    }

    public function update(Request $request, AssistantWebsiteSystem $websiteSystem): RedirectResponse
    {
        $this->assertAccess($request, $websiteSystem); $request->merge(['name' => trim((string) $request->input('name'))]); $websiteSystem->update($this->validateData($request, $websiteSystem->assistant_department_id, $websiteSystem->id)); return back()->with('success', '建站系统修改成功。');
    }

    public function toggle(Request $request, AssistantWebsiteSystem $websiteSystem): RedirectResponse { $this->assertAccess($request, $websiteSystem); $websiteSystem->update(['is_enabled' => ! $websiteSystem->is_enabled]); return back()->with('success', $websiteSystem->is_enabled ? '建站系统已启用。' : '建站系统已停用。'); }
    public function destroy(Request $request, AssistantWebsiteSystem $websiteSystem): RedirectResponse { $this->assertAccess($request, $websiteSystem); $websiteSystem->delete(); return back()->with('success', '建站系统已删除。'); }

    private function validateData(Request $request, int $departmentId, ?int $ignoreId = null): array
    {
        return $request->validate(['project_id' => ['required', 'integer', Rule::exists('assistant_projects', 'id')->where(fn ($q) => $q->where('assistant_department_id', $departmentId)->when($ignoreId === null, fn ($query) => $query->where('is_enabled', true)))], 'name' => ['required', 'string', 'max:100', Rule::unique('assistant_website_systems')->where(fn ($q) => $q->where('assistant_department_id', $departmentId)->where('project_id', $request->integer('project_id')))->ignore($ignoreId)]], ['project_id.required' => '请先选择项目。', 'project_id.exists' => '所选项目不可用。', 'name.required' => '请输入建站系统名称。', 'name.unique' => '该项目下已存在同名建站系统。']);
    }
    private function assertAccess(Request $request, AssistantWebsiteSystem $system): void { $context = $this->authorizedContext($request); abort_unless($this->scope->canAccessDepartment($context['user'], $system->assistant_department_id), 403); }
    private function authorizedContext(Request $request): array { $context = $this->context($request); if ($context instanceof Response) abort($context->getStatusCode()); return $context; }
    private function context(Request $request): array|Response { $context = $this->portalEntries->portalContext($request); if ($context instanceof Response) return $context; $this->authorization->assertAssistantPortal($context['portal']); return $context; }
}
