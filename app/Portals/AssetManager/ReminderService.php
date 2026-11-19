<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\DingTalkCompany;
use App\Models\DingTalkCompanyGrant;
use App\Models\Portal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReminderService
{
    public function __construct(
        private AssetComputerDepartmentBindingService $bindings,
        private ReminderAttendanceService $attendance,
        private ReminderRecapService $recaps,
        private ReminderDingTalkClient $client,
    ) {}

    public function db()
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'));
    }

    public function ids(AdminUser $actor, bool $write = false): array
    {
        return $this->bindings->departmentIds($actor, $write);
    }

    public function companies(AdminUser $actor)
    {
        return DingTalkCompany::where('enabled', true)
            ->when(! $actor->isAdministrator(), fn ($q) => $q->whereIn('id', DingTalkCompanyGrant::where('user_id', $actor->id)->select('company_id')))
            ->orderBy('name')->get(['id', 'name']);
    }

    public function company(AdminUser $actor, int $id): DingTalkCompany
    {
        abort_unless($this->companies($actor)->contains('id', $id), 403, __('asset_reminders.company_unavailable'));
        return DingTalkCompany::findOrFail($id);
    }

    public function query(AdminUser $actor, string $kind, bool $write = false): Builder
    {
        abort_unless(in_array($kind, ['groups', 'rules'], true), 404);
        return $this->db()->table('asset_reminder_'.$kind)->whereIn('department_id', $this->ids($actor, $write));
    }

    public function record(AdminUser $actor, string $kind, int $id, bool $write = false): object
    {
        $row = $this->query($actor, $kind, $write)->where('id', $id)->first();
        abort_unless($row, 404);
        if ($kind === 'rules') {
            $this->record($actor, 'groups', (int) $row->group_id, $write);
        }
        if ($kind === 'groups') {
            $this->company($actor, (int) $row->company_id);
        }
        return $row;
    }

    public function save(AdminUser $actor, string $kind, array $input, ?int $id): int
    {
        abort_unless(in_array($kind, ['groups', 'rules'], true), 404);
        $old = $id ? $this->record($actor, $kind, $id, true) : null;
        $common = [
            'department_id' => ['required', 'integer', Rule::in($this->ids($actor, true))],
            'enabled' => ['required', 'boolean'], 'version' => [$id ? 'required' : 'nullable', 'integer', 'min:1'],
        ];
        $rules = $kind === 'groups' ? [
            'name' => ['required', 'string', 'max:100'], 'company_id' => ['required', 'integer'],
            'robot_code' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9_-]+$/'],
            'conversation_id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_+=\/-]+$/'],
            'remark' => ['nullable', 'string', 'max:500'],
        ] : [
            'group_id' => ['required', 'integer'], 'type' => ['required', Rule::in(['recap', 'advertising'])],
            'attendance_group_id' => ['required', 'integer', 'min:1'], 'operator_userid' => ['required', 'string', 'max:190'],
            'work_times' => ['present', 'array', 'max:10'], 'work_times.*' => ['required', 'date_format:H:i', 'distinct'],
            'rest_times' => ['present', 'array', 'max:10'], 'rest_times.*' => ['required', 'date_format:H:i', 'distinct'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'entry_url' => ['required', 'url:http,https', 'max:1000'],
        ];
        $data = Validator::make($input, $common + $rules, __('asset_reminders.validation'), __('asset_reminders.fields'))->validate();
        unset($data['version']);
        if ($old && (int) $old->department_id !== (int) $data['department_id']) {
            throw ValidationException::withMessages(['department_id' => __('asset_reminders.department_locked')]);
        }
        if ($kind === 'groups') {
            $this->company($actor, (int) $data['company_id']);
            // A shared group can only be edited by someone managing every affected department.
            if ($old && $this->db()->table('asset_reminder_rules')->where('group_id', $id)->whereNotIn('department_id', $this->ids($actor, true))->exists()) {
                abort(403, __('asset_reminders.scope_revoked'));
            }
            if ($this->db()->table('asset_reminder_groups')->where('company_id', $data['company_id'])->where('robot_code', $data['robot_code'])
                ->where('conversation_id', $data['conversation_id'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['conversation_id' => __('asset_reminders.group_duplicate')]);
            }
        } else {
            $group = $this->record($actor, 'groups', (int) $data['group_id'], true);
            $company = $this->company($actor, (int) $group->company_id);
            $attendance = $this->attendance->group($company, (int) $data['attendance_group_id']);
            $this->attendance->dayType($attendance, now('Asia/Shanghai'));
            $data['attendance_group_name'] = $attendance['group_name'];
            if ($old && ($old->starts_on !== $data['starts_on'] || $old->type !== $data['type'])) {
                throw ValidationException::withMessages(['starts_on' => __('asset_reminders.start_locked')]);
            }
            if (! $old && $data['starts_on'] < now('Asia/Shanghai')->subDay()->toDateString()) {
                throw ValidationException::withMessages(['starts_on' => __('asset_reminders.start_early')]);
            }
            // Previously captured dates retain their original roster; new dates use the new source.
            foreach (['work_times', 'rest_times'] as $key) {
                sort($data[$key]);
                $data[$key] = json_encode($data[$key], JSON_THROW_ON_ERROR);
            }
            if ($this->db()->table('asset_reminder_rules')->where('department_id', $data['department_id'])->where('type', $data['type'])
                ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['type' => __('asset_reminders.rule_duplicate')]);
            }
        }
        $groupIds = $kind === 'groups' ? array_filter([$id]) : array_unique(array_filter([$data['group_id'], $old?->group_id]));
        sort($groupIds); $locks = [];
        try {
            foreach ($groupIds as $groupId) {
                $lock = Cache::lock('asset-reminder:delivery:'.$groupId, 300);
                if (! $lock->get()) { throw new RuntimeException(__('asset_reminders.busy')); }
                $locks[] = $lock;
            }
            return $this->db()->transaction(function () use ($actor, $kind, $input, $id, $data) {
            $data['updated_by'] = $actor->id; $data['updated_at'] = now('UTC');
            if ($id) {
                $changed = $this->query($actor, $kind, true)->where('id', $id)->where('version', $input['version'])
                    ->update($data + ['version' => DB::raw('version + 1')]);
                abort_unless($changed, 409, __('asset_reminders.conflict'));
                return $id;
            }
            return $this->db()->table('asset_reminder_'.$kind)->insertGetId($data + ['created_at' => now('UTC'), 'version' => 1]);
            });
        } finally { foreach ($locks as $lock) { $lock->release(); } }
    }

    public function remove(AdminUser $actor, string $kind, int $id): void
    {
        $record = $this->record($actor, $kind, $id, true);
        $lock = Cache::lock('asset-reminder:delivery:'.($kind === 'groups' ? $id : $record->group_id), 300);
        if (! $lock->get()) { throw new RuntimeException(__('asset_reminders.busy')); }
        try {
        if ($kind === 'groups' && $this->db()->table('asset_reminder_rules')->where('group_id', $id)->exists()) {
            throw ValidationException::withMessages(['group_id' => __('asset_reminders.group_in_use')]);
        }
        if ($kind === 'rules') {
            // Preserve rule identity and historical snapshots. Deletion is an explicit stop.
            $this->query($actor, $kind, true)->where('id', $id)->update(['enabled' => false, 'updated_by' => $actor->id, 'version' => DB::raw('version + 1'), 'updated_at' => now('UTC')]);
        } else {
            $this->query($actor, $kind, true)->where('id', $id)->delete();
        }
        } finally { $lock->release(); }
    }

    public function runtime(object $rule, bool $allowDisabled = false): array
    {
        $portal = Portal::where('code', 'asset-manager')->first();
        if (! $portal || ! $portal->isEnabled() || ! $portal->menus()->where('code', 'asset_manager_reminders')->where('is_enabled', true)->exists()) {
            throw new RuntimeException(__('asset_reminders.scope_revoked'));
        }
        $actor = AdminUser::find($rule->updated_by);
        if (! $actor || (int) $actor->status !== 1 || ! $actor->can('asset-manager.computers') || ! in_array((int) $rule->department_id, $this->ids($actor, true), true)) {
            throw new RuntimeException(__('asset_reminders.scope_revoked'));
        }
        $group = $this->record($actor, 'groups', (int) $rule->group_id, true);
        $editor = AdminUser::find($group->updated_by);
        if (! $editor || (int) $editor->status !== 1 || ! $editor->can('asset-manager.computers') || ! in_array((int) $group->department_id, $this->ids($editor, true), true)) {
            throw new RuntimeException(__('asset_reminders.scope_revoked'));
        }
        $company = $this->company($editor, (int) $group->company_id);
        if (! $allowDisabled && (! $group->enabled || ! $rule->enabled)) {
            throw new RuntimeException(__('asset_reminders.disabled'));
        }
        return [$group, $company];
    }

    private function safe(string $value): string
    {
        return str_replace(['\\', '`', '*', '_', '[', ']', '<', '>', '#', "\r", "\n"], ['\\\\', '\\`', '\\*', '\\_', '\\[', '\\]', '&lt;', '&gt;', '\\#', ' ', ' '], $value);
    }

    public function build(array $rules, Carbon $date, int $round = 1, bool $persist = true, bool $draft = false): array
    {
        usort($rules, fn ($a, $b) => $a->department_id <=> $b->department_id);
        [$group, $company] = $this->runtime($rules[0], $draft);
        foreach ($rules as $rule) {
            [$candidate] = $this->runtime($rule, $draft);
            if ($rule->type !== $rules[0]->type || (int) $candidate->id !== (int) $group->id) {
                throw new RuntimeException(__('asset_reminders.invalid_batch'));
            }
        }
        $title = __('asset_reminders.message.'.$rules[0]->type.'_title', ['round' => __('asset_reminders.rounds.'.$round, [], 'zh-CN')], 'zh-CN');
        $summary = null;
        if ($rules[0]->type === 'recap') {
            $summary = $this->recaps->summary($rules, $company, $date, $persist);
            $stats = array_diff_key($summary, ['departments' => true]);
            foreach (['from', 'date'] as $key) { $stats[$key] = Carbon::parse($stats[$key])->format(__('asset_reminders.date_format', [], 'zh-CN')); }
            $lines = [__('asset_reminders.message.stats', $stats, 'zh-CN'), __('asset_reminders.message.people', [], 'zh-CN')];
            $names = Department::whereIn('id', array_keys($summary['departments']))->pluck('name', 'id');
            foreach ($summary['departments'] as $id => $users) {
                $lines[] = __('asset_reminders.message.department', ['department' => $this->safe($names[$id] ?? '#'.$id), 'count' => count($users), 'names' => implode('、', array_map(fn ($u) => $this->safe($u['name']), $users))], 'zh-CN');
            }
            $lines[] = __('asset_reminders.message.recap_footer', [], 'zh-CN');
        } else {
            $lines = [__('asset_reminders.message.advertising_body', [], 'zh-CN')];
        }
        foreach (array_unique(array_column($rules, 'entry_url')) as $url) {
            $lines[] = '['.__('asset_reminders.message.'.$rules[0]->type.'_link', [], 'zh-CN').']('.str_replace(['(', ')', '[', ']', "\n", "\r"], ['%28', '%29', '%5B', '%5D', '', ''], $url).')';
        }
        $text = '### '.$title."\n\n".implode("\n\n", $lines);
        // Split at Unicode boundaries; a partial batch is uncertain and never automatically replayed.
        $parts = []; $part = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $character) {
            if (strlen($part.$character) > 12000) { $parts[] = $part; $part = ''; }
            $part .= $character;
        }
        if ($part !== '') { $parts[] = $part; }
        return compact('title', 'text', 'parts', 'summary');
    }

    public function deliver(array $rules, Carbon $date, string $slot, int $round, bool $test = false, ?AdminUser $actor = null): array
    {
        $first = $rules[0]; $ids = array_map(fn ($r) => (int) $r->id, $rules);
        $departments = array_values(array_unique(array_map(fn ($r) => (int) $r->department_id, $rules)));
        $key = hash('sha256', implode('|', [$first->group_id, $first->type, $date->toDateString(), $slot, $round, $test ? (string) \Illuminate\Support\Str::uuid() : 'scheduled']));
        $lock = Cache::lock('asset-reminder:delivery:'.$first->group_id, 300);
        if (! $lock->get()) {
            throw new RuntimeException(__('asset_reminders.busy'));
        }
        $runId = null;
        try {
            if ($this->db()->table('asset_reminder_runs')->where('delivery_key', $key)->exists()) {
                return ['status' => 'duplicate'];
            }
            $runId = $this->db()->table('asset_reminder_runs')->insertGetId([
                'delivery_key' => $key, 'group_id' => $first->group_id, 'type' => $first->type,
                'data_date' => $date->toDateString(), 'slot' => $slot, 'round' => $round,
                'rule_ids' => json_encode($ids), 'department_ids' => json_encode($departments),
                'status' => 'preparing', 'is_test' => $test, 'actor_id' => $actor?->id,
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $payload = $this->build($rules, $date, $round, ! $test, $test);
            if ($test) {
                $payload['parts'] = array_map(fn ($part) => __('asset_reminders.message.test', [], 'zh-CN')."\n\n".$part, $payload['parts']);
            }
            $this->db()->table('asset_reminder_runs')->where('id', $runId)->update([
                'message' => implode("\n\n", $payload['parts']), 'snapshot' => json_encode($payload['summary'], JSON_UNESCAPED_UNICODE),
                'status' => 'sending', 'updated_at' => now('UTC'),
            ]);
            if ($first->type === 'recap' && $payload['summary']['count'] === 0 && ! $test) {
                $status = 'skipped';
            } else {
                foreach ($payload['parts'] as $part) {
                    // Re-read configuration immediately before every external side effect.
                    foreach ($rules as $rule) {
                        $fresh = $this->db()->table('asset_reminder_rules')->where('id', $rule->id)->first();
                        if (! $fresh || (int) $fresh->version !== (int) $rule->version) {
                            throw new RuntimeException(__('asset_reminders.conflict'));
                        }
                        [$group, $company] = $this->runtime($fresh, $test);
                    }
                    $process = $this->client->send($company, $group, $payload['title'], $part);
                    $this->db()->table('asset_reminder_runs')->where('id', $runId)->update(['process_key' => $process, 'updated_at' => now('UTC')]);
                }
                $status = 'accepted';
            }
            $this->db()->table('asset_reminder_runs')->where('id', $runId)->update(['status' => $status, 'updated_at' => now('UTC')]);
            return ['status' => $status, 'id' => $runId];
        } catch (\Throwable $e) {
            if ($runId) {
                $row = $this->db()->table('asset_reminder_runs')->where('id', $runId)->first();
                $message = $e instanceof RuntimeException || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $e->getMessage() : __('asset_reminders.failed');
                $this->db()->table('asset_reminder_runs')->where('id', $runId)->update([
                    'status' => $row->status === 'sending' ? 'uncertain' : 'failed',
                    'error' => mb_substr($message, 0, 1000), 'updated_at' => now('UTC'),
                ]);
            }
            throw new RuntimeException(__('asset_reminders.delivery_failed'));
        } finally {
            $lock->release();
        }
    }

    public function process(Carbon $now): int
    {
        $this->db()->table('asset_reminder_runs')->whereIn('status', ['preparing', 'sending'])->where('updated_at', '<', now('UTC')->subMinutes(30))
            ->update(['status' => 'uncertain', 'error' => __('asset_reminders.interrupted'), 'updated_at' => now('UTC')]);
        $batches = []; $errors = 0;
        foreach ($this->db()->table('asset_reminder_rules')->where('enabled', true)->orderBy('department_id')->cursor() as $rule) {
            if ($rule->starts_on > $now->copy()->subDay()->toDateString()) { continue; }
            try {
                [, $company] = $this->runtime($rule);
                $group = $this->attendance->group($company, (int) $rule->attendance_group_id);
                $dayType = $this->attendance->dayType($group, $now);
                // Snapshot the report date even when today has no configured sending slot.
                // Otherwise a day-off sending policy could lose yesterday's historical roster.
                if ($rule->type === 'recap') {
                    $this->recaps->day($rule, $company, $now->copy()->subDay()->startOfDay());
                }
                $times = json_decode($rule->{$dayType.'_times'}, true, 512, JSON_THROW_ON_ERROR);
                $index = array_search($now->format('H:i'), $times, true);
                if ($index !== false) {
                    $batches[$rule->group_id.'|'.$rule->type.'|'.$index][] = $rule;
                }
                $this->db()->table('asset_reminder_rules')->where('id', $rule->id)->update(['last_error' => null, 'last_checked_at' => now('UTC')]);
            } catch (\Throwable) {
                $errors++;
                $this->db()->table('asset_reminder_rules')->where('id', $rule->id)->update(['last_error' => __('asset_reminders.check_failed'), 'last_checked_at' => now('UTC')]);
            }
        }
        foreach ($batches as $rules) {
            try {
                [, $company] = $this->runtime($rules[0]);
                $dayType = $this->attendance->dayType($this->attendance->group($company, (int) $rules[0]->attendance_group_id), $now);
                $round = array_search($now->format('H:i'), json_decode($rules[0]->{$dayType.'_times'}, true), true) + 1;
                $this->deliver($rules, $now->copy()->subDay()->startOfDay(), $now->format('H:i'), $round);
            } catch (\Throwable) { $errors++; }
        }
        return $errors;
    }
}
