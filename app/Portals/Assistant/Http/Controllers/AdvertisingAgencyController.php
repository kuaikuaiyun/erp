<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssistantAdvertisingAgency;
use App\Models\AssistantAdvertisingMedia;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantAdvertisingScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AdvertisingAgencyController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantAdvertisingScopeService $advertisingScope,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $department = $this->advertisingScope->selectedDepartment($context['user'], $request->input('assistant_department_id'));

        return view('portals.assistant.advertising-agencies.index', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'agencies' => AssistantAdvertisingAgency::query()->where('assistant_department_id', $department->id)->with('advertisingMedia')->orderBy('sort')->orderBy('id')->get(),
            'media' => AssistantAdvertisingMedia::query()->where('assistant_department_id', $department->id)->where('is_enabled', true)->orderBy('sort')->orderBy('id')->get(),
            'assistantDepartments' => $this->advertisingScope->departmentsFor($context['user']),
            'selectedAssistantDepartment' => $department,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $department = $this->advertisingScope->selectedDepartment($context['user'], $request->input('assistant_department_id'));
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('assistant_advertising_agencies', 'name')
                ->where(fn ($query) => $query->where('assistant_department_id', $department->id)->where('advertising_media_id', $request->integer('advertising_media_id')))],
            'advertising_media_id' => ['required', 'integer', Rule::exists('assistant_advertising_media', 'id')->where(fn ($query) => $query->where('assistant_department_id', $department->id)->where('is_enabled', true))],
            'settlement_coefficient' => ['required', 'numeric', 'min:0', 'max:9999'],
        ], [
            'name.required' => '请输入广告代理名称。', 'name.unique' => '该广告代理已经存在。',
            'settlement_coefficient.required' => '请输入结算系数。',
        ]);
        $sort = ((int) AssistantAdvertisingAgency::query()->where('assistant_department_id', $department->id)->max('sort')) + 10;
        AssistantAdvertisingAgency::query()->create([...$validated, 'assistant_department_id' => $department->id, 'is_enabled' => true, 'sort' => $sort]);

        return back()->with('success', '广告代理添加成功。');
    }

    public function toggle(Request $request, AssistantAdvertisingAgency $advertisingAgency): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        abort_unless($this->advertisingScope->canAccessDepartment($context['user'], $advertisingAgency->assistant_department_id), 403);
        $advertisingAgency->update(['is_enabled' => ! $advertisingAgency->is_enabled]);

        return back()->with('success', $advertisingAgency->is_enabled ? '广告代理已启用。' : '广告代理已停用。');
    }

    public function update(Request $request, AssistantAdvertisingAgency $advertisingAgency): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        abort_unless($this->advertisingScope->canAccessDepartment($context['user'], $advertisingAgency->assistant_department_id), 403);
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('assistant_advertising_agencies', 'name')
                ->where(fn ($query) => $query->where('assistant_department_id', $advertisingAgency->assistant_department_id)->where('advertising_media_id', $request->integer('advertising_media_id')))
                ->ignore($advertisingAgency->getKey())],
            'advertising_media_id' => ['required', 'integer', Rule::exists('assistant_advertising_media', 'id')->where(fn ($query) => $query->where('assistant_department_id', $advertisingAgency->assistant_department_id)->where('is_enabled', true))],
            'settlement_coefficient' => ['required', 'numeric', 'min:0', 'max:9999'],
        ], [
            'name.required' => '请输入广告代理名称。', 'name.unique' => '该广告代理已经存在。',
            'settlement_coefficient.required' => '请输入结算系数。',
        ]);
        $advertisingAgency->update($validated);

        return back()->with('success', '广告代理修改成功。');
    }

    public function destroy(Request $request, AssistantAdvertisingAgency $advertisingAgency): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        abort_unless($this->advertisingScope->canAccessDepartment($context['user'], $advertisingAgency->assistant_department_id), 403);
        $advertisingAgency->delete();

        return back()->with('success', '广告代理已删除，历史报表数据不受影响。');
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->authorization->assertAssistantPortal($context['portal']);

        return $context;
    }
}
