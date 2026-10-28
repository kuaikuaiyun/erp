<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssistantAdvertisingMedia;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AdvertisingMediaController extends Controller
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

        return view('portals.assistant.advertising-media.index', [
            'portal' => $context['portal']->load('menus'),
            'user' => $context['user'],
            'media' => AssistantAdvertisingMedia::query()->orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('assistant_advertising_media', 'name')],
        ], ['name.required' => '请输入广告社媒名称。', 'name.unique' => '该广告社媒已经存在。']);

        $sort = ((int) AssistantAdvertisingMedia::query()->max('sort')) + 10;
        AssistantAdvertisingMedia::query()->create(['name' => $validated['name'], 'is_enabled' => true, 'sort' => $sort]);

        return back()->with('success', '广告社媒添加成功。');
    }

    public function toggle(Request $request, AssistantAdvertisingMedia $advertisingMedium): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        abort_if($advertisingMedium->name === '其他', 422, '“其他”是系统兜底媒介，不能停用。');
        $advertisingMedium->update(['is_enabled' => ! $advertisingMedium->is_enabled]);

        return back()->with('success', $advertisingMedium->is_enabled ? '广告社媒已启用。' : '广告社媒已停用。');
    }

    public function destroy(Request $request, AssistantAdvertisingMedia $advertisingMedium): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        abort_if($advertisingMedium->name === '其他', 422, '“其他”是系统兜底媒介，不能删除。');
        $advertisingMedium->delete();

        return back()->with('success', '广告社媒已删除，历史报表数据不受影响。');
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->authorization->assertAssistantPortal($context['portal']);

        return $context;
    }
}
