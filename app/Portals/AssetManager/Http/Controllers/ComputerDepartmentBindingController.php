<?php

namespace App\Portals\AssetManager\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\AssetManager\AssetComputerDepartmentBindingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ComputerDepartmentBindingController extends Controller
{
    public function __construct(private PortalEntryController $entries, private AssetComputerDepartmentBindingService $bindings) {}

    private function context(Request $request): array
    {
        $context = $this->entries->portalContext($request);
        if ($context instanceof Response) {
            abort($context->getStatusCode() === 200 ? 403 : $context->getStatusCode());
        }
        abort_unless($request->routeIs('asset-manager.computer-department-bindings.*') && $context['portal']->code === 'asset-manager', 404);
        abort_unless($context['user']->isAdministrator(), 403);
        $menu = $context['portal']->menus->firstWhere('code', 'asset_computer_department_bindings');
        abort_unless($menu && $menu->is_enabled, 403);
        app()->setLocale('zh-CN');

        return $context;
    }

    public function index(Request $request)
    {
        $context = $this->context($request);
        $sources = $this->bindings->sourceDepartments();
        $data = $request->validate([
            'source_department_id' => ['nullable', 'integer', Rule::in($sources->pluck('id')->all())],
            'page' => ['nullable', 'integer', 'min:1'],
        ], __('asset_manager.bindings.validation'), __('asset_manager.bindings.attributes'));
        $sourceId = (int) ($data['source_department_id'] ?? $sources->first()?->id ?? 0);
        // Read the version first: a concurrent write can only cause a safe save conflict.
        $version = $this->bindings->version($sourceId);
        $selectedIds = $this->bindings->selectedIds($sourceId);
        $history = $this->bindings->database()->table('asset_computer_department_binding_logs')->where('source_department_id', $sourceId)->orderByDesc('id')->paginate(20)->withQueryString();

        return view('portals.asset-manager.computer-department-bindings', $context + [
            'sources' => $sources, 'sourceId' => $sourceId, 'selectedIds' => $selectedIds, 'version' => $version,
            'departments' => $this->bindings->targets(), 'history' => $history, 'bindings' => $this->bindings,
        ]);
    }

    public function update(Request $request)
    {
        $context = $this->context($request);
        $data = $request->validate([
            'source_department_id' => ['required', 'integer', Rule::in($this->bindings->sourceDepartments()->pluck('id')->all())],
            'department_ids' => ['present', 'array', 'max:500'],
            'department_ids.*' => ['required', 'integer', 'distinct'],
            'version' => ['required', 'integer', 'min:0'],
        ], __('asset_manager.bindings.validation'), __('asset_manager.bindings.attributes'));
        $this->bindings->save($context['user'], (int) $data['source_department_id'], $data['department_ids'], (int) $data['version']);
        session()->flash('success', __('asset_manager.bindings.saved'));

        return response()->json(['message' => __('asset_manager.bindings.saved')]);
    }
}
