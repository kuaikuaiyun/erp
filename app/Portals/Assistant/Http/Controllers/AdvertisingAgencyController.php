<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssistantAdvertisingAgency;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
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
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        return view('portals.assistant.advertising-agencies.index', [
            'portal' => $context['portal']->load('menus'),
            'user' => $context['user'],
            'agencies' => AssistantAdvertisingAgency::query()->orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('assistant_advertising_agencies', 'name')],
            'settlement_coefficient' => ['required', 'numeric', 'min:0', 'max:9999'],
        ], [
            'name.required' => '请输入广告代理名称。', 'name.unique' => '该广告代理已经存在。',
            'settlement_coefficient.required' => '请输入结算系数。',
        ]);
        $sort = ((int) AssistantAdvertisingAgency::query()->max('sort')) + 10;
        AssistantAdvertisingAgency::query()->create([...$validated, 'is_enabled' => true, 'sort' => $sort]);

        return back()->with('success', '广告代理添加成功。');
    }

    public function toggle(Request $request, AssistantAdvertisingAgency $advertisingAgency): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $advertisingAgency->update(['is_enabled' => ! $advertisingAgency->is_enabled]);

        return back()->with('success', $advertisingAgency->is_enabled ? '广告代理已启用。' : '广告代理已停用。');
    }

    public function update(Request $request, AssistantAdvertisingAgency $advertisingAgency): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('assistant_advertising_agencies', 'name')->ignore($advertisingAgency->getKey())],
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
