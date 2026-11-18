<?php

namespace App\Portals\AssetManager\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DisciplineRecordLog;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\AssetManager\DisciplineExportService;
use App\Portals\AssetManager\DisciplineService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DisciplineController extends Controller
{
    public function __construct(private PortalEntryController $entries, private DisciplineService $service) {}

    private function context(Request $request): array
    {
        $context = $this->entries->portalContext($request);
        if ($context instanceof Response) {
            abort($context->getStatusCode() === 200 ? 403 : $context->getStatusCode());
        }
        abort_unless($request->routeIs('asset-manager.discipline.*') && $context['portal']->code === 'asset-manager', 404);
        $user = $context['user'];
        $menu = $context['portal']->menus->firstWhere('code', 'asset_manager_discipline');
        abort_unless($menu && $menu->is_enabled && ($user->isAdministrator() || $user->can('asset-manager.discipline')), 403);
        app()->setLocale('zh-CN');

        return $context;
    }

    public function index(Request $request)
    {
        $context = $this->context($request);
        $filters = $this->service->filters($request);
        $members = $this->service->membersQuery($context['user'], $filters)->paginate($filters['per_page'] ?? 30)->withQueryString();
        $options = $this->service->memberOptions($context['user'], collect($members->items()));
        $summaries = $this->service->summaries($context['user'], $filters, array_column($options, 'id'));
        $data = [
            'members' => $options, 'summaries' => $summaries, 'page' => $members->currentPage(),
            'last_page' => $members->lastPage(), 'total' => $members->total(),
        ];
        if ($request->expectsJson()) {
            return response()->json($data);
        }

        return view('portals.asset-manager.discipline', $context + compact('filters', 'members', 'data') + [
            'days' => $this->service->days($filters['month']), 'departments' => $this->service->departments($context['user']),
            'canManage' => $this->service->canManage($context['user']),
        ]);
    }

    public function members(Request $request)
    {
        $context = $this->context($request);
        abort_unless($this->service->canManage($context['user']), 403);
        $filters = $this->service->filters($request);
        $members = $this->service->membersQuery($context['user'], $filters, true)->paginate(30);

        return response()->json(['members' => $this->service->memberOptions($context['user'], collect($members->items())), 'page' => $members->currentPage(), 'last_page' => $members->lastPage()]);
    }

    public function records(Request $request)
    {
        $context = $this->context($request);
        $filters = $this->service->filters($request);
        $validated = $request->validate(['member_id' => ['required', 'integer', 'min:1'], 'date' => ['required', 'date_format:Y-m-d']], __('discipline.validation'), __('discipline.attributes'));
        $rows = $this->service->monthRecords($context['user'], $filters)->where('member_id', $validated['member_id'])
            ->whereDate('record_date', $validated['date'])->orderBy('id')->paginate(30);

        return response()->json(['records' => $rows->getCollection()->map(fn ($row) => $this->service->payload($row, $context['user'])), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]);
    }

    public function show(Request $request, int $record)
    {
        $context = $this->context($request);
        $row = $this->service->visibleRecords($context['user'])->findOrFail($record);
        $result = $this->service->payload($row, $context['user']);
        // Management-only audit trail, bounded independently of the calendar.
        if ($this->service->writable($context['user'], $row)) {
            $request->validate(['page' => ['nullable', 'integer', 'min:1']], __('discipline.validation'), __('discipline.attributes'));
            $logs = DisciplineRecordLog::where('record_id', $row->id)->orderByDesc('id')->paginate(20);
            $result['logs'] = $logs->getCollection()->map(fn ($log) => [
                'action' => __('discipline.actions.'.$log->action), 'actor' => $log->actor_name,
                'at' => $log->created_at->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'before' => $log->before_data, 'after' => $log->after_data,
            ]);
            $result['log_page'] = $logs->currentPage();
            $result['log_last_page'] = $logs->lastPage();
        }

        return response()->json($result);
    }

    private function values(Request $request, bool $create): array
    {
        $rules = [
            'record_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31'],
            'remarks' => ['required', 'string', 'max:2000', 'regex:/\S/u', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F]/u'],
        ];
        if ($create) {
            $rules += ['member_id' => ['required', 'integer', 'min:1'], 'department_id' => ['required', 'integer', 'min:1']];
        } else {
            $rules['version'] = ['required', 'string', 'max:40'];
        }

        return $request->validate($rules, __('discipline.validation'), __('discipline.attributes'));
    }

    public function store(Request $request)
    {
        $context = $this->context($request);
        $record = $this->service->create($context['user'], $this->values($request, true));

        return response()->json($this->service->payload($record, $context['user']));
    }

    public function update(Request $request, int $record)
    {
        $context = $this->context($request);
        $this->service->change($context['user'], $record, $this->values($request, false));

        return response()->json(['message' => __('discipline.saved')]);
    }

    public function destroy(Request $request, int $record)
    {
        $context = $this->context($request);
        $data = $request->validate(['version' => ['required', 'string', 'max:40']], __('discipline.validation'), __('discipline.attributes'));
        $this->service->change($context['user'], $record, $data, true);

        return response()->json(['message' => __('discipline.deleted')]);
    }

    public function export(Request $request, DisciplineExportService $export)
    {
        $context = $this->context($request);

        return $export->response($context['user'], $this->service->filters($request));
    }
}
