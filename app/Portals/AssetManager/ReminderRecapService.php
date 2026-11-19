<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Models\DingTalkCompany;
use App\Models\OperationTaskRecap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReminderRecapService
{

    private function db()
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'));
    }

    public function day(object $rule, DingTalkCompany $company, Carbon $date, bool $persist = true): array
    {
        $stored = $this->db()->table('asset_reminder_days')->where('rule_id', $rule->id)->where('data_date', $date->toDateString())->first();
        if ($stored) {
            return json_decode($stored->snapshot, true, 512, JSON_THROW_ON_ERROR);
        }
        if ($date->toDateString() < $rule->starts_on) {
            return [];
        }
        $departments = array_map('intval', json_decode($rule->department_ids ?? 'null', true) ?: [$rule->department_id]);
        sort($departments);
        $users = AdminUser::query()->where('status', 1)->where('employment_status', '!=', 'resigned')
            ->whereDoesntHave('roles', fn ($q) => $q->where('slug', 'administrator'))
            ->whereHas('departments', fn ($q) => $q->whereIn('admin_roles.id', $departments))
            ->where(fn ($q) => $q->whereNull('hired_at')->orWhereDate('hired_at', '<=', $date->toDateString()))
            ->where(fn ($q) => $q->whereNull('resigned_at')->orWhereDate('resigned_at', '>', $date->toDateString()))
            ->orderBy('id')->get();
        $snapshot = $users->map(fn ($user) => [
            'id' => (int) $user->id, 'name' => $user->name ?: $user->username,
            'department_id' => min(array_intersect($departments, $user->departments->modelKeys())),
        ])->values()->all();
        if ($persist) {
            $this->db()->table('asset_reminder_days')->insertOrIgnore([
                'rule_id' => $rule->id, 'data_date' => $date->toDateString(),
                'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'synced_at' => now('UTC'),
            ]);
            // Concurrent runs always use the winning snapshot.
            return $this->day($rule, $company, $date, false);
        }
        return $snapshot;
    }

    public function summary(array $rules, DingTalkCompany $company, Carbon $date, bool $persist = true): array
    {
        $start = $date->copy()->startOfMonth()->max(Carbon::parse(min(array_column($rules, 'starts_on')), 'Asia/Shanghai'));
        $submitted = OperationTaskRecap::withTrashed()->whereBetween('data_date', [$start->toDateString(), $date->toDateString()])
            ->whereNotNull('submitted_at')->get(['user_id', 'data_date'])->mapWithKeys(fn ($r) => [$r->user_id.'|'.$r->data_date->toDateString() => true])->all();
        $missing = []; $today = []; $departments = [];
        foreach ($rules as $rule) {
            // Effective date bounds are explicit. Do not back-count dates before activation.
            for ($day = $start->copy()->max(Carbon::parse($rule->starts_on, 'Asia/Shanghai')); $day->lte($date); $day->addDay()) {
                foreach ($this->day($rule, $company, $day, $persist) as $user) {
                    $key = $user['id'].'|'.$day->toDateString();
                    if (isset($submitted[$key])) {
                        continue;
                    }
                    $missing[$key] = $user['id'];
                    if ($day->isSameDay($date) && ! isset($today[$user['id']])) {
                        // Stable department ordering makes cross-department grouping deterministic.
                        $today[$user['id']] = $user;
                        $departments[$user['department_id'] ?? $rule->department_id][] = $user;
                    }
                }
            }
        }
        return [
            'date' => $date->toDateString(), 'from' => $start->toDateString(),
            'count' => count($today), 'monthly_count' => count($missing),
            'monthly_people' => count(array_unique(array_values($missing))), 'departments' => $departments,
        ];
    }
}
