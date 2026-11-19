<?php

namespace App\Portals\AssetManager;

use App\Models\DingTalkCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
                    throw new ReminderCheckException('attendance_invalid');
                }
                foreach ($data['groups'] as $group) {
                    if (! is_array($group) || ! is_numeric($group['group_id'] ?? null) || ! is_string($group['group_name'] ?? null)) {
                        throw new ReminderCheckException('attendance_invalid');
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
            throw new ReminderCheckException('attendance_invalid');
        });
    }

    public function group(DingTalkCompany $company, int $id): array
    {
        $group = collect($this->groups($company))->first(fn ($g) => (int) $g['group_id'] === $id);
        if (! $group) {
            throw new ReminderCheckException('attendance_missing');
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
                throw new ReminderCheckException('attendance_invalid');
            }
            return (int) $days[$date->dayOfWeek] > 0 ? 'work' : 'rest';
        }
        if (($group['type'] ?? null) === 'NONE' && is_array($days = $group['freecheck_work_days'] ?? null)) {
            if (! array_is_list($days) || $days === [] || collect($days)->contains(fn ($day) => ! is_numeric($day) || (int) $day < 0 || (int) $day > 6)) {
                throw new ReminderCheckException('attendance_invalid');
            }
            return in_array($date->dayOfWeek, array_map('intval', $days), true) ? 'work' : 'rest';
        }
        throw new ReminderCheckException('attendance_unsupported');
    }

}
