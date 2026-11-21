<?php

namespace App\Portals\AssetManager\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\AssetManager\ReminderAttendanceService;
use App\Portals\AssetManager\ReminderCheckException;
use App\Portals\AssetManager\ReminderService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReminderController extends Controller
{
    public function __construct(private PortalEntryController $entries, private ReminderService $service, private ReminderAttendanceService $attendance) {}

    private function context(Request $request, bool $write = false): array
    {
        $context = $this->entries->portalContext($request);
        abort_if($context instanceof Response, 403);
        abort_unless($context['portal']->code === 'asset-manager' && $request->routeIs('asset-manager.reminders.*'), 404);
        $menu = $context['portal']->menus->firstWhere('code', 'asset_manager_reminders');
        abort_unless($menu && $menu->is_enabled && $context['user']->can('asset-manager.computers'), 403);
        abort_if($write && $this->service->ids($context['user'], true) === [] && $this->service->sourceIds($context['user'], true) === [] && ! $context['user']->isAdministrator(), 403);
        app()->setLocale('zh-CN');
        return $context;
    }

    public function index(Request $request)
    {
        $context = $this->context($request);
        $filters = $request->validate([
            'department_id' => ['nullable', 'integer'], 'type' => ['nullable', 'in:recap,advertising,submission'],
            'enabled' => ['nullable', 'in:0,1'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'in:20,50,100'],
            'group_page' => ['nullable', 'integer', 'min:1'],
        ], __('asset_reminders.validation'), __('asset_reminders.fields'));
        $actor = $context['user'];
        $query = $this->service->query($actor, 'rules');
        foreach (['department_id', 'type', 'enabled'] as $key) {
            if ($request->filled($key)) {
                if ($key === 'department_id') { $query->where(fn ($q) => $q->where('department_id', $filters[$key])->orWhereJsonContains('department_ids', (int) $filters[$key])); }
                else { $query->where($key, $filters[$key]); }
            }
        }
        $rows = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();
        $departments = Department::whereIn('id', $this->service->ids($actor))->with('parent:id,name')->orderBy('sort')->orderBy('name')->get()
            ->mapWithKeys(fn ($d) => [$d->id => ($d->parent ? $d->parent->name.' / ' : '').$d->name]);
        $groups = $this->service->query($actor, 'groups')->orderBy('name')->paginate(20, ['*'], 'group_page')->withQueryString();
        $groups->setCollection($groups->getCollection()->map(fn ($group) => $this->service->publicGroup($group, $actor)));
        $groupNames = $this->service->query($actor, 'groups')->whereIn('id', $rows->pluck('group_id'))->pluck('name', 'id');
        return view('portals.asset-manager.reminders', $context + compact('rows', 'filters', 'departments', 'groups') + [
            'canManageGroups' => $actor->isAdministrator() || $this->service->sourceIds($actor, true) !== [],
            'canManage' => $this->service->ids($actor, true) !== [],
            'writeIds' => $this->service->ids($actor, true), 'groupNames' => $groupNames,
            'calendarNames' => \App\Models\DingTalkCompany::whereIn('id', $rows->pluck('calendar_company_id'))->pluck('name', 'id'),
            'companyNames' => \App\Models\DingTalkCompany::whereIn('id', $groups->pluck('company_id'))->pluck('name', 'id'),
        ]);
    }

    public function options(Request $request)
    {
        $c = $this->context($request, true);
        $actor = $c['user'];
        return response()->json([
            'departments' => Department::whereIn('id', $this->service->ids($actor, true))->with('parent:id,name')->withExists('children')->get()->map(fn ($d) => ['id' => $d->id, 'name' => ($d->parent ? $d->parent->name.' / ' : '').$d->name, 'short_name' => $d->name, 'parent_id' => $d->parent_id, 'parent_name' => $d->parent?->name, 'has_children' => (bool) $d->children_exists]),
            'companies' => $this->service->companies($actor),
            'groups' => $this->service->query($actor, 'groups', true)->whereIn('company_id', $this->service->companies($actor)->pluck('id'))->get()->map(fn ($group) => $this->service->publicGroup($group, $actor)),
            'today' => now('Asia/Shanghai')->toDateString(), 'yesterday' => now('Asia/Shanghai')->subDay()->toDateString(),
        ])->header('Cache-Control', 'no-store');
    }

    public function calendar(Request $request)
    {
        $c = $this->context($request);
        $service = app(\App\Portals\AssetManager\ReminderCalendarService::class);
        $data = $request->validate(['company_id' => ['nullable', 'integer'], 'month' => ['required', 'date_format:Y-m']], __('asset_reminders.validation'), __('asset_reminders.fields'));
        return response()->json(['companies' => $service->companies($c['user']), 'month' => $data['month']] + ($request->filled('company_id') ? $service->month($c['user'], (int) $data['company_id'], $data['month']) : []))->header('Cache-Control', 'no-store');
    }

    public function saveCalendar(Request $request)
    {
        $c = $this->context($request, true);
        try {
            app(\App\Portals\AssetManager\ReminderCalendarService::class)->save($c['user'], $request->all());
            return response()->json(['message' => __('asset_reminders.saved')]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['message' => ReminderCheckException::from($e, ['stage' => 'calendar_save'])->getMessage()], 422);
        }
    }

    public function attendance(Request $request)
    {
        $c = $this->context($request, true);
        $data = $request->validate(['company_id' => ['required', 'integer'], 'refresh' => ['nullable', 'boolean']], __('asset_reminders.validation'), __('asset_reminders.fields'));
        $company = $this->service->company($c['user'], (int) $data['company_id']);
        try {
            return response()->json(['groups' => $this->attendance->groups($company, $request->boolean('refresh'))])->header('Cache-Control', 'no-store');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(Request $request, string $kind, int $id)
    {
        $c = $this->context($request);
        $record = $this->service->record($c['user'], $kind, $id);
        return response()->json(['record' => $kind === 'groups' ? $this->service->publicGroup($record, $c['user']) : $record])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, string $kind, ?int $id = null)
    {
        $c = $this->context($request, true);
        try {
            $id = $this->service->save($c['user'], $kind, $request->all(), $id);
            return response()->json(['id' => $id, 'message' => __('asset_reminders.saved')]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function remove(Request $request, string $kind, int $id)
    {
        $c = $this->context($request, true);
        try {
            $this->service->remove($c['user'], $kind, $id, $request->boolean('permanent'));
            return response()->json(['message' => __('asset_reminders.saved')]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function notificationPreview(Request $request, int $id)
    {
        $c = $this->context($request, true);
        $rule = $this->service->record($c['user'], 'rules', $id, true);
        abort_unless($rule->type === 'submission', 404);
        return response()->json(['message' => app(\App\Portals\AssetManager\SubmissionNotificationService::class)->message([
            'type' => 'account-opening', 'id' => 0, 'name' => __('asset_reminders.sample_person'), 'department' => __('asset_reminders.sample_department'),
            'time' => now('Asia/Shanghai')->format('Y-m-d H:i:s'), 'quantity' => 1, 'amount' => '', 'project' => '', 'system' => '',
        ], $rule->entry_url)])->header('Cache-Control', 'no-store');
    }

    public function preview(Request $request, int $id)
    {
        $c = $this->context($request, true);
        $rule = $this->service->record($c['user'], 'rules', $id, true);
        if ($rule->type === 'submission') return $this->notificationPreview($request, $id);
        try {
            $data = $this->service->build([$rule], now('Asia/Shanghai')->subDay()->startOfDay(), 1, false, true);
            return response()->json(['message' => $data['text']])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['message' => ReminderCheckException::from($e, ['stage' => 'preview', 'rule_id' => $id])->getMessage()], 422)->header('Cache-Control', 'no-store');
        }
    }

    public function test(Request $request, int $id)
    {
        $c = $this->context($request, true);
        $rule = $this->service->record($c['user'], 'rules', $id, true);
        if ($rule->type === 'submission') return response()->json(['message' => __('asset_reminders.notification_no_test')], 422);
        $request->validate(['confirmed' => ['required', 'accepted']], __('asset_reminders.validation'), __('asset_reminders.fields'));
        try {
            $data = $this->service->deliver([$rule], now('Asia/Shanghai')->subDay()->startOfDay(), now('Asia/Shanghai')->format('H:i'), 1, true, $c['user']);
            return response()->json($data + ['message' => __('asset_reminders.test_sent')]);
        } catch (\Throwable $e) {
            return response()->json(['message' => ReminderCheckException::from($e, ['stage' => 'test', 'rule_id' => $id])->getMessage()], 422)->header('Cache-Control', 'no-store');
        }
    }

    public function runs(Request $request)
    {
        $c = $this->context($request);
        $data = $request->validate(['group_id' => ['required', 'integer'], 'page' => ['nullable', 'integer', 'min:1']], __('asset_reminders.validation'), __('asset_reminders.fields'));
        $group = $this->service->record($c['user'], 'groups', (int) $data['group_id']);
        $query = $this->service->db()->table('asset_reminder_runs')->where('group_id', $group->id);
        $allowed = $this->service->ids($c['user']);
        // Logs may contain merged department names; visibility requires the complete batch scope.
        foreach ($this->service->db()->table('asset_reminder_rules')->get() as $rule) {
            foreach (array_diff($this->service->ruleDepartments($rule), $allowed) as $forbidden) { $query->whereJsonDoesntContain('department_ids', (int) $forbidden); }
        }
        $rows = $query->orderByDesc('id')->paginate(20);
        return response()->json($rows)->header('Cache-Control', 'no-store');
    }
}
