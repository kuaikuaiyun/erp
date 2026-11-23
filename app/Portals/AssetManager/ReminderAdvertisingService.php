<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Models\FashionAdvertisingReport;
use App\Models\OperationReport;
use App\Portals\Fashion\OperationProjectCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReminderAdvertisingService
{
    private function db()
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'));
    }

    private function departmentIds(object $rule): array
    {
        $ids = array_map('intval', json_decode($rule->department_ids ?? 'null', true) ?: [$rule->department_id]);
        sort($ids);
        return $ids;
    }

    public function roster(object $rule, Carbon $date): array
    {
        if ($date->toDateString() < $rule->starts_on) return [];
        $departments = $this->departmentIds($rule);
        $roles = config('admin.database.roles_table');
        return AdminUser::query()->with('departments')->where('status', AdminUser::STATUS_ACTIVE)
            ->where('employment_status', 'employed')
            ->whereHas('departments', fn ($q) => $q->whereIn($roles.'.id', $departments))
            ->where(fn ($q) => $q->whereNull('hired_at')->orWhereDate('hired_at', '<=', $date->toDateString()))
            ->where(fn ($q) => $q->whereNull('resigned_at')->orWhereDate('resigned_at', '>', $date->toDateString()))
            ->orderBy('id')->get()->map(fn ($user) => [
                'id' => (int) $user->id, 'name' => $user->name ?: $user->username,
                'department_id' => min(array_intersect($departments, $user->departments->modelKeys())),
            ])->all();
    }

    /** Refresh today's observed roster; never invent or rewrite past rosters. */
    public function capture(object $rule, Carbon $date, ?array $roster = null): array
    {
        $date = $date->copy()->setTimezone('Asia/Shanghai');
        $roster ??= $this->roster($rule, $date);
        if (! $date->isSameDay(now('Asia/Shanghai')) || $date->toDateString() < $rule->starts_on) return $roster;
        $json = json_encode($roster, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $query = $this->db()->table('asset_advertising_reminder_days')->where('rule_id', $rule->id)->where('data_date', $date->toDateString());
        $stored = $query->value('snapshot');
        // MySQL normalizes JSON whitespace; compare decoded values to avoid a write
        // every minute when the roster has not changed.
        if ($stored === null || json_decode($stored, true, 512, JSON_THROW_ON_ERROR) !== $roster) {
            $this->db()->table('asset_advertising_reminder_days')->upsert([
                'rule_id' => $rule->id, 'data_date' => $date->toDateString(), 'snapshot' => $json, 'synced_at' => now('UTC'),
            ], ['rule_id', 'data_date'], ['snapshot', 'synced_at']);
        }
        return $roster;
    }

    public function summary(array $rules, Carbon $date, bool $persist = true): array
    {
        $date = $date->copy()->setTimezone('Asia/Shanghai')->startOfDay();
        $key = $date->toDateString();
        usort($rules, fn ($a, $b) => $a->department_id <=> $b->department_id);
        $rosters = []; $current = []; $observedDates = [$key]; $displayDepartments = [];
        // Only previously observed days contribute to the monthly total. Pure reminder
        // runs and recap snapshots are deliberately not used as historical evidence.
        $stored = $this->db()->table('asset_advertising_reminder_days')->whereIn('rule_id', array_column($rules, 'id'))
            ->whereBetween('data_date', [$date->copy()->startOfMonth()->toDateString(), $key])->orderBy('data_date')->get()->groupBy('rule_id');
        foreach ($rules as $rule) {
            $allowed = $this->departmentIds($rule);
            foreach ($stored->get($rule->id, collect()) as $day) {
                if ($day->data_date >= $key || $day->data_date < $rule->starts_on) continue;
                $observedDates[] = $day->data_date;
                foreach (json_decode($day->snapshot, true, 512, JSON_THROW_ON_ERROR) as $user) {
                    // Scope edits must not expose members from removed departments.
                    if (in_array((int) $user['department_id'], $allowed, true)) $rosters[$user['id'].'|'.$day->data_date] = $user;
                }
            }
            $today = $this->roster($rule, $date);
            if ($persist) $this->capture($rule, $date, $today);
            if ($key >= $rule->starts_on) $displayDepartments = array_merge($displayDepartments, $allowed);
            foreach ($today as $user) {
                $rosters[$user['id'].'|'.$key] ??= $user;
                $current[$user['id']] ??= $user;
            }
        }
        sort($observedDates); $from = $observedDates[0];
        $users = array_values(array_unique(array_column($rosters, 'id')));
        $submitted = [];
        // Batch by account IDs: no per-member/per-day report queries or order reads.
        foreach (array_chunk($users, 500) as $chunk) {
            $sources = [
                [OperationReport::query(), 'operator_user_id'],
                [FashionAdvertisingReport::query()->whereIn('project_code', array_keys(OperationProjectCatalog::all())), 'submitter_id'],
            ];
            foreach ($sources as [$query, $owner]) {
                $query->whereIn($owner, $chunk)->whereBetween('report_date', [$from, $key])
                    ->whereIn('status', ['pending', 'approved', 'rejected'])
                    ->select([$owner, 'report_date'])->distinct()->get()->each(function ($report) use (&$submitted, $owner) {
                        $submitted[(int) $report->{$owner}.'|'.$report->report_date->toDateString()] = true;
                    });
            }
        }
        $missing = array_diff_key($rosters, $submitted); $departments = []; $todayMissing = [];
        // Display every selected membership, while keeping the stored roster and
        // person/date counters unchanged. Load memberships in one batch, not per user.
        $roles = config('admin.database.roles_table');
        $memberships = $current === [] ? collect() : AdminUser::query()->select('id')->whereIn('id', array_keys($current))
            ->with(['departments' => fn ($q) => $q->whereIn($roles.'.id', array_values(array_unique($displayDepartments)))])
            ->get()->mapWithKeys(fn ($user) => [(int) $user->id => $user->departments->modelKeys()]);
        foreach ($current as $id => $user) {
            if (isset($submitted[$id.'|'.$key])) continue;
            $todayMissing[$id] = $user;
            foreach ($memberships->get($id, []) as $department) {
                $departments[$department][$id] = array_replace($user, ['department_id' => (int) $department]);
            }
        }
        ksort($departments);
        $departments = array_map('array_values', $departments);
        return ['date' => $key, 'from' => $from, 'count' => count($todayMissing),
            'monthly_count' => count($missing), 'monthly_people' => count(array_unique(array_column($missing, 'id'))),
            'departments' => $departments];
    }
}
