<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Models\DingTalkCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class ReminderCalendarService
{
    private function reminders(): ReminderService { return app(ReminderService::class); }

    public function canWrite(AdminUser $actor): bool
    {
        return $actor->isAdministrator() || $this->reminders()->sourceIds($actor, true) !== [];
    }

    public function companies(AdminUser $actor)
    {
        if ($actor->isAdministrator()) return $this->reminders()->companies($actor);
        $ids = $this->reminders()->query($actor, 'groups')->pluck('company_id');
        if ($this->canWrite($actor)) $ids = $ids->merge($this->reminders()->companies($actor)->pluck('id'));
        return DingTalkCompany::where('enabled', true)->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }

    public function authorize(AdminUser $actor, int $company, bool $write = false): void
    {
        if ($write) {
            abort_unless($this->canWrite($actor), 403);
            $this->reminders()->company($actor, $company);
        } else {
            abort_unless($this->companies($actor)->contains('id', $company), 403);
        }
    }

    public function month(AdminUser $actor, int $company, string $month): array
    {
        $this->authorize($actor, $company);
        $row = $this->reminders()->db()->table('asset_reminder_calendars')->where('company_id', $company)->where('month', $month)->first();
        return ['days' => $row ? json_decode($row->days, true) : null, 'version' => $row?->version ?? 0,
            'updated_at' => $row?->updated_at, 'updated_by' => $row ? AdminUser::find($row->updated_by)?->name : null,
            'can_write' => $this->canWrite($actor) && $this->reminders()->companies($actor)->contains('id', $company)];
    }

    public function save(AdminUser $actor, array $input): void
    {
        $data = Validator::make($input, [
            'company_id' => ['required', 'integer'], 'month' => ['required', 'date_format:Y-m'],
            'version' => ['required', 'integer', 'min:0'], 'days' => ['required', 'array'],
            'days.*' => ['required', 'in:work,rest'],
        ], __('asset_reminders.validation'), __('asset_reminders.fields'))->validate();
        $this->authorize($actor, (int) $data['company_id'], true);
        $date = Carbon::createFromFormat('!Y-m', $data['month'], 'Asia/Shanghai');
        $keys = [];
        for ($i = 1; $i <= $date->daysInMonth; $i++) $keys[] = $date->copy()->day($i)->toDateString();
        $days = $data['days']; ksort($days);
        abort_unless(array_keys($days) === $keys, 422, __('asset_reminders.calendar.incomplete'));
        $db = $this->reminders()->db(); $locks = [];
        try {
            // Freeze group/rule changes before enumerating their delivery locks.
            $configuration = Cache::lock('asset-reminder:configuration', 300);
            if (! $configuration->get()) throw new ReminderCheckException('busy');
            $locks[] = $configuration;
            $keys = [];
            foreach ($db->table('asset_reminder_groups')->where('company_id', $data['company_id'])->orderBy('id')->pluck('id') as $id) $keys[] = 'asset-reminder:delivery:'.$id;
            foreach ($keys as $key) {
                $lock = Cache::lock($key, 300);
                if (! $lock->get()) throw new ReminderCheckException('busy');
                $locks[] = $lock;
            }
            $db->transaction(function () use ($db, $actor, $data, $days) {
                $query = $db->table('asset_reminder_calendars')->where('company_id', $data['company_id'])->where('month', $data['month']);
                $old = $query->first();
                abort_unless((int) ($old?->version ?? 0) === (int) $data['version'], 409, __('asset_reminders.conflict'));
                $values = ['days' => json_encode($days), 'updated_by' => $actor->id, 'updated_at' => now('UTC'), 'version' => $data['version'] + 1];
                if ($old) { $query->update($values); $id = $old->id; }
                else { $id = $db->table('asset_reminder_calendars')->insertGetId($values + ['company_id' => $data['company_id'], 'month' => $data['month'], 'created_at' => now('UTC')]); }
                $db->table('asset_reminder_calendar_logs')->insert(['calendar_id' => $id, 'actor_id' => $actor->id,
                    'before_days' => $old?->days, 'after_days' => json_encode($days), 'version' => $values['version'], 'created_at' => now('UTC')]);
            });
        } finally { foreach (array_reverse($locks) as $lock) $lock->release(); }
    }

    public function dayType(object $rule, int $company, Carbon $date): string
    {
        if ((int) ($rule->calendar_company_id ?? 0) !== $company) throw new ReminderCheckException('calendar.unlinked');
        $date = $date->copy()->setTimezone('Asia/Shanghai');
        $row = $this->reminders()->db()->table('asset_reminder_calendars')->where('company_id', $company)->where('month', $date->format('Y-m'))->first();
        $type = $row ? (json_decode($row->days, true)[$date->toDateString()] ?? null) : null;
        if (! in_array($type, ['work', 'rest'], true)) throw new ReminderCheckException('calendar.missing', ['month' => $date->format('Y-m')]);
        return $type;
    }
}
