<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\DingTalkCompany;
use App\Models\DingTalkCompanyGrant;
use App\Models\Portal;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReminderService
{
    public const DEFAULT_ENTRY_URL = 'https://www.kuaiyun8888.com/kky/';
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
        $query = $this->db()->table('asset_reminder_'.$kind)->whereIn('department_id', $this->ids($actor, $write));
        if ($kind === 'rules') {
            $allowed = $this->ids($actor, $write);
            foreach ($this->db()->table('asset_reminder_rules')->pluck('department_ids') as $json) {
                foreach (json_decode($json ?? '[]', true) as $department) {
                    if (! in_array((int) $department, $allowed, true)) { $query->whereJsonDoesntContain('department_ids', (int) $department); }
                }
            }
        }
        if ($kind === 'rules') $query->whereNull('deleted_at');
        return $query;
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

    public function publicGroup(object $group): object
    {
        $safe = clone $group;
        $safe->webhook_configured = ! empty($safe->webhook);
        $safe->webhook_secret_configured = ! empty($safe->webhook_secret);
        unset($safe->webhook, $safe->webhook_secret, $safe->webhook_hash, $safe->robot_code, $safe->conversation_id);
        return $safe;
    }

    public function ruleDepartments(object $rule): array
    {
        return array_map('intval', json_decode($rule->department_ids ?? 'null', true) ?: [$rule->department_id]);
    }

    public function save(AdminUser $actor, string $kind, array $input, ?int $id): int
    {
        $lock = Cache::lock('asset-reminder:configuration', 300);
        if (! $lock->get()) { throw new ReminderCheckException('busy'); }
        try { return $this->saveConfiguration($actor, $kind, $input, $id); }
        finally { $lock->release(); }
    }

    private function saveConfiguration(AdminUser $actor, string $kind, array $input, ?int $id): int
    {
        abort_unless(in_array($kind, ['groups', 'rules'], true), 404);
        if ($kind === 'rules' && isset($input['department_ids'])) {
            $input = Validator::make($input, ['department_ids' => ['required', 'array', 'min:1'], 'department_ids.*' => ['required', 'integer', 'distinct', Rule::in($this->ids($actor, true))]] + [], __('asset_reminders.validation'), __('asset_reminders.fields'))->validate() + $input;
            $input['department_ids'] = array_map('intval', $input['department_ids']);
            sort($input['department_ids']);
            $input['department_id'] = $input['department_ids'][0];
        }
        if ($kind === 'rules' && ! $id && ! isset($input['entry_url'])) { $input['entry_url'] = self::DEFAULT_ENTRY_URL; }
        $old = $id ? $this->record($actor, $kind, $id, true) : null;
        $common = [
            'department_id' => ['required', 'integer', Rule::in($this->ids($actor, true))],
            'enabled' => ['required', 'boolean'], 'version' => [$id ? 'required' : 'nullable', 'integer', 'min:1'],
        ];
        $rules = $kind === 'groups' ? [
            'name' => ['required', 'string', 'max:100'], 'company_id' => ['required', 'integer'],
            'webhook' => ['nullable', 'string', 'max:1000'],
            'webhook_secret' => ['nullable', 'string', 'max:256', 'regex:/\ASEC[A-Za-z0-9]+\z/D'],
            'clear_webhook_secret' => ['sometimes', 'boolean'],
            'remark' => ['nullable', 'string', 'max:500'],
        ] : [
            'group_id' => ['required', 'integer'], 'type' => ['required', Rule::in(['recap', 'advertising', 'submission'])],
            'attendance_group_id' => ['required', 'integer', 'min:1'],
            'work_times' => ['present', 'array', 'max:3'], 'work_times.*' => ['required', 'date_format:H:i', 'distinct'],
            'rest_times' => ['present', 'array', 'max:3'], 'rest_times.*' => ['required', 'date_format:H:i', 'distinct'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'entry_url' => ['required', 'url:http,https', 'max:1000'],
        ];
        if ($kind === 'rules' && ($input['type'] ?? '') === 'submission') {
            unset($rules['attendance_group_id'], $rules['work_times'], $rules['work_times.*'], $rules['rest_times'], $rules['rest_times.*'], $rules['starts_on']);
            $rules['submission_types'] = ['required', 'array', 'min:1', 'max:3'];
            $rules['submission_types.*'] = ['required', 'distinct', Rule::in(SubmissionNotificationService::TYPES)];
        }
        $data = Validator::make($input, $common + $rules, __('asset_reminders.validation'), __('asset_reminders.fields'))->validate();
        unset($data['version']);
        if ($kind === 'rules' && $data['type'] === 'recap' && (! $old || $data['enabled'])) throw ValidationException::withMessages(['type' => __('asset_reminders.recap_disabled')]);
        if ($kind === 'groups' && $old && (int) $old->department_id !== (int) $data['department_id']) {
            throw ValidationException::withMessages(['department_id' => __('asset_reminders.department_locked')]);
        }
        if ($kind === 'groups') {
            $this->company($actor, (int) $data['company_id']);
            // A shared group can only be edited by someone managing every affected department.
            if ($old) {
                foreach ($this->db()->table('asset_reminder_rules')->where('group_id', $id)->get() as $reference) {
                    abort_if(array_diff($this->ruleDepartments($reference), $this->ids($actor, true)) !== [], 403, __('asset_reminders.scope_revoked'));
                }
            }
            $webhook = trim($data['webhook'] ?? '');
            $secret = trim($data['webhook_secret'] ?? '');
            $clearSecret = (bool) ($data['clear_webhook_secret'] ?? false);
            unset($data['webhook'], $data['webhook_secret'], $data['clear_webhook_secret']);
            if ($webhook !== '') {
                try { $webhook = $this->client->normalizeWebhook($webhook); }
                catch (RuntimeException $e) { throw ValidationException::withMessages(['webhook' => $e->getMessage()]); }
                $data['webhook_hash'] = hash('sha256', $webhook);
                if ($this->db()->table('asset_reminder_groups')->where('webhook_hash', $data['webhook_hash'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                    throw ValidationException::withMessages(['webhook' => __('asset_reminders.group_duplicate')]);
                }
                $data['webhook'] = Crypt::encryptString($webhook);
            } elseif (empty($old?->webhook)) {
                throw ValidationException::withMessages(['webhook' => __('asset_reminders.webhook_missing')]);
            }
            if ($clearSecret && $secret !== '') {
                throw ValidationException::withMessages(['webhook_secret' => __('asset_reminders.secret_conflict')]);
            }
            if ($secret !== '') {
                $data['webhook_secret'] = Crypt::encryptString($secret);
            } elseif ($clearSecret) {
                $data['webhook_secret'] = null;
            }
        } else {
            $selected = $input['department_ids'] ?? ($old ? $this->ruleDepartments($old) : [(int) $data['department_id']]);
            $data['department_id'] = $selected[0];
            $data['department_ids'] = json_encode($selected);
            foreach ($this->db()->table('asset_reminder_rules')->whereNull('deleted_at')->where('type', $data['type'])->when($id, fn ($q) => $q->where('id', '!=', $id))->get() as $existing) {
                if (array_intersect($selected, $this->ruleDepartments($existing))) { throw ValidationException::withMessages(['department_ids' => __('asset_reminders.rule_duplicate')]); }
            }
            $group = $this->record($actor, 'groups', (int) $data['group_id'], true);
            $company = $this->company($actor, (int) $group->company_id);
            if ($data['type'] !== 'submission') {
            $attendance = $this->attendance->group($company, (int) $data['attendance_group_id']);
            $this->attendance->dayType($attendance, now('Asia/Shanghai'));
            $data['attendance_group_name'] = $attendance['group_name'];
            } else {
                $data['attendance_group_id'] = 0; $data['attendance_group_name'] = '';
                $data['work_times'] = []; $data['rest_times'] = []; $data['starts_on'] = $old?->starts_on ?? now('Asia/Shanghai')->toDateString();
                $data['submission_types'] = json_encode($data['submission_types']);
            }
            if (! $old) { $data['operator_userid'] = ''; } // Unused legacy NOT NULL column.
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
            if ($this->db()->table('asset_reminder_rules')->where('department_id', $data['department_id'])->whereNull('deleted_at')->where('type', $data['type'])
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
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$kind === 'groups' ? 'webhook' : 'type' => __('asset_reminders.'.($kind === 'groups' ? 'group_duplicate' : 'rule_duplicate'))]);
        } finally { foreach ($locks as $lock) { $lock->release(); } }
    }

    public function remove(AdminUser $actor, string $kind, int $id, bool $delete = false): void
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
            $this->query($actor, $kind, true)->where('id', $id)->update(($delete ? ['deleted_at' => now('UTC'), 'deletion_key' => $id] : []) + ['enabled' => false, 'updated_by' => $actor->id, 'version' => DB::raw('version + 1'), 'updated_at' => now('UTC')]);
        } else {
            $this->query($actor, $kind, true)->where('id', $id)->delete();
        }
        } finally { $lock->release(); }
    }

    public function runtime(object $rule, bool $allowDisabled = false): array
    {
        if ($rule->deleted_at ?? null) throw new ReminderCheckException('check_config_missing');
        if ($rule->type === 'recap') throw new ReminderCheckException('recap_disabled');
        $portal = Portal::where('code', 'asset-manager')->first();
        if (! $portal || ! $portal->isEnabled() || ! $portal->menus()->where('code', 'asset_manager_reminders')->where('is_enabled', true)->exists()) {
            throw new ReminderCheckException('check_portal_unavailable');
        }
        $actor = AdminUser::find($rule->updated_by);
        if (! $actor || (int) $actor->status !== 1 || ! $actor->can('asset-manager.computers') || array_diff($this->ruleDepartments($rule), $this->ids($actor, true)) !== []) {
            throw new ReminderCheckException('check_rule_authorization');
        }
        $group = $this->record($actor, 'groups', (int) $rule->group_id, true);
        $editor = AdminUser::find($group->updated_by);
        if (! $editor || (int) $editor->status !== 1 || ! $editor->can('asset-manager.computers') || ! in_array((int) $group->department_id, $this->ids($editor, true), true)) {
            throw new ReminderCheckException('check_group_authorization');
        }
        $company = $this->company($editor, (int) $group->company_id);
        if (empty($group->webhook)) {
            throw new ReminderCheckException('webhook_missing');
        }
        if (! $allowDisabled && (! $group->enabled || ! $rule->enabled)) {
            throw new ReminderCheckException('disabled');
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
                throw new ReminderCheckException('invalid_batch');
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
        // Every Webhook part repeats its title/link so keyword checks and the entry survive splitting.
        $parts = []; $part = ''; $heading = '### '.$title."\n\n";
        $links = array_filter($lines, fn ($line) => str_starts_with($line, '['));
        $suffix = "\n\n".implode("\n\n", $links);
        $body = implode("\n\n", array_filter($lines, fn ($line) => ! str_starts_with($line, '[')));
        $limit = 12000 - strlen($heading.$suffix) - strlen(__('asset_reminders.message.test', [], 'zh-CN')) - 2;
        if ($limit < 4) { throw new ReminderCheckException('message_too_long'); }
        foreach (preg_split('/(?<=、)|(?<=\n)/u', $body, -1, PREG_SPLIT_NO_EMPTY) as $segment) {
            if ($part !== '' && strlen($part.$segment) > $limit) {
                $parts[] = $heading.$part.$suffix; $part = '';
            }
            // Prefer complete names/lines; only an individually oversized segment needs a UTF-8 split.
            if (strlen($segment) > $limit) {
                foreach (preg_split('//u', $segment, -1, PREG_SPLIT_NO_EMPTY) as $character) {
                    if (strlen($part.$character) > $limit) { $parts[] = $heading.$part.$suffix; $part = ''; }
                    $part .= $character;
                }
            } else {
                $part .= $segment;
            }
        }
        if ($part !== '') { $parts[] = $heading.$part.$suffix; }
        return compact('title', 'text', 'parts', 'summary');
    }

    public function deliver(array $rules, Carbon $date, string $slot, int $round, bool $test = false, ?AdminUser $actor = null): array
    {
        $first = $rules[0]; $ids = array_map(fn ($r) => (int) $r->id, $rules);
        $departments = array_values(array_unique(array_merge(...array_map(fn ($r) => $this->ruleDepartments($r), $rules))));
        $key = hash('sha256', implode('|', [$first->group_id, $first->type, $date->toDateString(), $slot, $round, $test ? (string) \Illuminate\Support\Str::uuid() : 'scheduled']));
        $lock = Cache::lock('asset-reminder:delivery:'.$first->group_id, 300);
        if (! $lock->get()) {
            throw new ReminderCheckException('busy');
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
                            throw new ReminderCheckException('conflict');
                        }
                        [$group, $company] = $this->runtime($fresh, $test);
                    }
                    $this->client->send($group, $payload['title'], $part);
                }
                $status = 'accepted';
            }
            $this->db()->table('asset_reminder_runs')->where('id', $runId)->update(['status' => $status, 'updated_at' => now('UTC')]);
            return ['status' => $status, 'id' => $runId];
        } catch (\Throwable $e) {
            $diagnostic = ReminderCheckException::from($e, ['stage' => 'delivery', 'rule_ids' => $ids]);
            if ($runId) {
                $row = $this->db()->table('asset_reminder_runs')->where('id', $runId)->first();
                $this->db()->table('asset_reminder_runs')->where('id', $runId)->update([
                    'status' => $row->status === 'sending' ? 'uncertain' : 'failed',
                    'error' => mb_substr($diagnostic->getMessage(), 0, 1000), 'updated_at' => now('UTC'),
                ]);
            }
            throw $diagnostic;
        } finally {
            $lock->release();
        }
    }

    public function process(Carbon $now): int
    {
        $this->db()->table('asset_reminder_runs')->whereIn('status', ['preparing', 'sending'])->where('updated_at', '<', now('UTC')->subMinutes(30))
            ->update(['status' => 'uncertain', 'error' => __('asset_reminders.interrupted'), 'updated_at' => now('UTC')]);
        $batches = []; $errors = 0;
        foreach ($this->db()->table('asset_reminder_rules')->whereNull('deleted_at')->where('type', 'advertising')->where('enabled', true)->orderBy('department_id')->cursor() as $rule) {
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
            } catch (\Throwable $e) {
                $errors++;
                $diagnostic = ReminderCheckException::from($e, ['stage' => 'scheduled_check', 'rule_id' => (int) $rule->id]);
                $this->db()->table('asset_reminder_rules')->where('id', $rule->id)->update(['last_error' => mb_substr($diagnostic->getMessage(), 0, 1000), 'last_checked_at' => now('UTC')]);
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
