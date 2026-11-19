<?php

namespace App\Portals\AssetManager;

use App\Models\DingTalkCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ReminderAttendanceService
{
    public function __construct(private ReminderDingTalkClient $client) {}

    public function groups(DingTalkCompany $company, bool $refresh = false): array
    {
        $key = 'asset-reminder:groups:'.$company->id.':'.$company->version.':'.hash('sha256', $company->app_key.'|'.$company->app_secret);
        if ($refresh) {
            Cache::forget($key);
        }
        return Cache::remember($key, 300, function () use ($company) {
            $groups = [];
            for ($offset = 0; $offset < 10000; $offset += 10) {
                $data = $this->client->legacy($company, '/topapi/attendance/getsimplegroups', ['offset' => $offset, 'size' => 10]);
                if (! is_array($data['groups'] ?? null) || ! is_bool($data['has_more'] ?? null)) {
                    throw new RuntimeException(__('asset_reminders.attendance_invalid'));
                }
                foreach ($data['groups'] as $group) {
                    if (! is_array($group) || ! is_numeric($group['group_id'] ?? null) || ! is_string($group['group_name'] ?? null)) {
                        throw new RuntimeException(__('asset_reminders.attendance_invalid'));
                    }
                    // Keep only the fields this feature needs. Never retain Wi-Fi/location data.
                    $groups[] = array_intersect_key($group, array_flip(['group_id', 'group_name', 'type', 'work_day_list', 'freecheck_work_days', 'classes_list']));
                }
                if (! $data['has_more']) {
                    return $groups;
                }
                if ($data['groups'] === []) {
                    break;
                }
            }
            throw new RuntimeException(__('asset_reminders.attendance_invalid'));
        });
    }

    public function group(DingTalkCompany $company, int $id): array
    {
        $group = collect($this->groups($company))->first(fn ($g) => (int) $g['group_id'] === $id);
        if (! $group) {
            throw new RuntimeException(__('asset_reminders.attendance_missing'));
        }
        return $group;
    }

    public function dayType(array $group, Carbon $date): string
    {
        if (($group['type'] ?? null) === 'FIXED') {
            $days = $group['work_day_list'] ?? null;
            // DingTalk's seven-element shift vector starts on Sunday (0 = rest).
            if (! is_array($days) || ! array_is_list($days) || count($days) !== 7
                || collect($days)->contains(fn ($shift) => ! is_numeric($shift) || (int) $shift < 0)) {
                throw new RuntimeException(__('asset_reminders.attendance_invalid'));
            }
            return (int) $days[$date->dayOfWeek] > 0 ? 'work' : 'rest';
        }
        if (($group['type'] ?? null) === 'NONE' && is_array($days = $group['freecheck_work_days'] ?? null)) {
            if (! array_is_list($days) || $days === [] || collect($days)->contains(fn ($day) => ! is_numeric($day) || (int) $day < 0 || (int) $day > 6)) {
                throw new RuntimeException(__('asset_reminders.attendance_invalid'));
            }
            return in_array($date->dayOfWeek, array_map('intval', $days), true) ? 'work' : 'rest';
        }
        throw new RuntimeException(__('asset_reminders.attendance_unsupported'));
    }

    public function workingUsers(DingTalkCompany $company, string $operator, array $userids, Carbon $date): array
    {
        $states = [];
        foreach (array_chunk(array_values(array_unique($userids)), 50) as $batch) {
            $key = 'asset-reminder:schedule:'.hash('sha256', $company->id.'|'.$company->version.'|'.$operator.'|'.$date->toDateString().'|'.implode(',', $batch));
            $rows = Cache::remember($key, 300, fn () => $this->client->legacy($company, '/topapi/attendance/schedule/listbyusers', [
                'op_user_id' => $operator, 'userids' => implode(',', $batch),
                'from_date_time' => $date->copy()->startOfDay()->getTimestampMs(),
                'to_date_time' => $date->copy()->endOfDay()->getTimestampMs(),
            ]));
            if (! array_is_list($rows)) {
                throw new RuntimeException(__('asset_reminders.attendance_invalid'));
            }
            foreach ($rows as $row) {
                $id = $row['userid'] ?? $row['user_id'] ?? null;
                if (! is_string($id) || ! in_array($id, $batch, true) || ! in_array($row['is_rest'] ?? null, ['Y', 'N'], true)) {
                    throw new RuntimeException(__('asset_reminders.attendance_invalid'));
                }
                $rawDate = $row['work_date'] ?? null;
                if (! is_string($rawDate) && ! is_int($rawDate)) {
                    throw new RuntimeException(__('asset_reminders.attendance_invalid'));
                }
                $workDate = is_numeric($rawDate)
                    ? ((int) $rawDate > 100000000000 ? Carbon::createFromTimestampMs($rawDate, 'Asia/Shanghai') : Carbon::createFromTimestamp($rawDate, 'Asia/Shanghai'))
                    : Carbon::parse($rawDate, 'Asia/Shanghai');
                if ($workDate->toDateString() !== $date->toDateString()) {
                    throw new RuntimeException(__('asset_reminders.attendance_invalid'));
                }
                $states[$id] = ($states[$id] ?? false) || $row['is_rest'] === 'N';
            }
            // Empty/missing rows are unknown, never an authoritative rest day.
            foreach ($batch as $id) {
                if (! array_key_exists($id, $states)) {
                    throw new RuntimeException(__('asset_reminders.schedule_missing'));
                }
            }
        }
        return $states;
    }
}
