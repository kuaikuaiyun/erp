<?php

namespace App\Portals\AssetManager;

use App\Jobs\SendAssetSubmissionNotification;
use App\Models\AdminUser;
use App\Models\DailyAdvertisingRecharge;
use App\Models\DailyAccountOpening;
use App\Models\DailyStoreLaunch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SubmissionNotificationService
{
    public const TYPES = ['advertising-recharge', 'account-opening', 'store-launch'];
    public function __construct(private ReminderService $reminders, private ReminderDingTalkClient $client) {}

    public function create(string $model, array $attributes): Model
    {
        $connection = (new $model)->getConnection();
        return $connection->transaction(function () use ($model, $attributes, $connection) {
            $record = $model::query()->create($attributes);
            // Notification persistence uses a savepoint, so a notification failure cannot undo the request.
            try { $connection->transaction(fn () => $this->capture($record)); }
            catch (\Throwable $e) { ReminderCheckException::from($e, ['stage' => 'submission_capture']); }
            return $record;
        });
    }

    public function capture(Model $record): void
    {
        $type = match (get_class($record)) {
            DailyAdvertisingRecharge::class => 'advertising-recharge',
            DailyAccountOpening::class => 'account-opening',
            DailyStoreLaunch::class => 'store-launch', default => null,
        };
        $db = $this->reminders->db();
        if (! $type || ! Schema::connection($db->getName())->hasColumn('asset_reminder_rules', 'submission_types')) return;
        $member = AdminUser::find($record->operator_user_id);
        $memberDepartments = $member ? $member->departments()->pluck(config('admin.database.roles_table').'.id')->map(fn ($id) => (int) $id)->all() : [];
        $matches = [];
        foreach ($db->table('asset_reminder_rules')->where('type', 'submission')->where('enabled', true)->whereNull('deleted_at')->get() as $rule) {
            if (! in_array($type, json_decode($rule->submission_types ?? '[]', true), true)
                || ! array_intersect($memberDepartments, $this->reminders->ruleDepartments($rule))) continue;
            try { $this->reminders->runtime($rule); } catch (\Throwable) { continue; }
            $matches[$rule->group_id][] = $rule;
        }
        foreach ($matches as $groupId => $rules) {
            $key = hash('sha256', 'submission|'.$type.'|'.$record->id.'|'.$groupId);
            $rule = $rules[0];
            $snapshot = [
                'type' => $type, 'id' => (int) $record->id, 'name' => $record->operator_name,
                'department' => $record->operation_department_name,
                'department_id' => (int) $record->operation_department_id,
                'matched_department_ids' => array_values(array_unique(array_merge(...array_map(fn ($r) => array_values(array_intersect($memberDepartments, $this->reminders->ruleDepartments($r))), $rules)))),
                'time' => $record->created_at->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'quantity' => $type === 'advertising-recharge' ? count($record->entries ?? []) : ($record->quantity ?? 1),
                'amount' => $record->total_amount ?? '', 'project' => $record->project_name ?? '',
                'system' => $record->website_system_name ?? '',
            ];
            $db->table('asset_reminder_runs')->insertOrIgnore([
                'delivery_key' => $key, 'group_id' => $groupId, 'type' => 'submission',
                'data_date' => now('Asia/Shanghai')->toDateString(), 'slot' => now('Asia/Shanghai')->format('H:i'), 'round' => 1,
                'rule_ids' => json_encode(array_column($rules, 'id')),
                'department_ids' => json_encode(array_values(array_unique(array_merge(...array_map(fn ($r) => $this->reminders->ruleDepartments($r), $rules))))),
                'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'status' => 'queued', 'is_test' => false,
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $runId = $db->table('asset_reminder_runs')->where('delivery_key', $key)->value('id');
            $db->afterCommit(function () use ($runId) {
                try { SendAssetSubmissionNotification::dispatch($runId); }
                catch (\Throwable $e) { ReminderCheckException::from($e, ['stage' => 'submission_queue']); }
            });
        }
    }

    public function send(int $id): void
    {
        $db = $this->reminders->db(); $run = $db->table('asset_reminder_runs')->find($id);
        if (! $run || $run->type !== 'submission' || $run->status !== 'queued') return;
        $lock = Cache::lock('asset-reminder:delivery:'.$run->group_id, 300);
        if (! $lock->get()) return; // The scheduled queue recovery will enqueue this pending record again.
        try {
            $run = $db->table('asset_reminder_runs')->find($id);
            if ($run->status !== 'queued') return;
            $payload = json_decode($run->snapshot, true);
            $rule = null; $group = null;
            foreach (json_decode($run->rule_ids, true) as $ruleId) {
                $candidate = $db->table('asset_reminder_rules')->find($ruleId);
                if (! $candidate || $candidate->deleted_at || ! $candidate->enabled || $candidate->type !== 'submission'
                    || (int) $candidate->group_id !== (int) $run->group_id
                    || ! in_array($payload['type'], json_decode($candidate->submission_types ?? '[]', true), true)
                    || ! array_intersect($payload['matched_department_ids'] ?? [$payload['department_id']], $this->reminders->ruleDepartments($candidate))) continue;
                try { [$group] = $this->reminders->runtime($candidate); $rule = $candidate; break; } catch (\Throwable) { continue; }
            }
            if (! $rule) { $db->table('asset_reminder_runs')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now('UTC')]); return; }
            $message = $this->message($payload, $rule->entry_url);
            $db->table('asset_reminder_runs')->where('id', $id)->update(['message' => $message, 'status' => 'sending', 'updated_at' => now('UTC')]);
            $this->client->send($group, __('asset_reminders.submission_title', [], 'zh-CN'), $message);
            $db->table('asset_reminder_runs')->where('id', $id)->update(['status' => 'accepted', 'updated_at' => now('UTC')]);
        } catch (\Throwable $e) {
            $safe = ReminderCheckException::from($e, ['stage' => 'submission_send']);
            $db->table('asset_reminder_runs')->where('id', $id)->update(['status' => 'uncertain', 'error' => mb_substr($safe->getMessage(), 0, 1000), 'updated_at' => now('UTC')]);
        } finally { $lock->release(); }
    }

    public function message(array $data, string $url): string
    {
        $safe = fn ($text) => str_replace(['\\', '`', '*', '_', '[', ']', '<', '>', '#', "\r", "\n"], ['\\\\', '\\`', '\\*', '\\_', '\\[', '\\]', '&lt;', '&gt;', '\\#', ' ', ' '], mb_substr((string) $text, 0, 200));
        $parameters = array_map($safe, array_filter($data, fn ($value) => is_scalar($value) || $value === null));
        $parameters['type'] = __('asset_reminders.submission_types.'.$data['type'], [], 'zh-CN');
        $text = __('asset_reminders.submission_body', $parameters, 'zh-CN');
        $text .= "\n\n".__('asset_reminders.submission_details.'.$data['type'], $parameters, 'zh-CN');
        return $text."\n\n[".__('asset_reminders.submission_link', [], 'zh-CN').']('.str_replace(['(', ')', '[', ']'], ['%28','%29','%5B','%5D'], $url).')';
    }

    public function recover(): int
    {
        $count = 0;
        foreach ($this->reminders->db()->table('asset_reminder_runs')->where('type', 'submission')->where('status', 'queued')->where('updated_at', '<=', now('UTC')->subMinutes(2))->orderBy('id')->limit(100)->pluck('id') as $id) {
            try {
                SendAssetSubmissionNotification::dispatch($id); $count++;
                $this->reminders->db()->table('asset_reminder_runs')->where('id', $id)->where('status', 'queued')->update(['updated_at' => now('UTC')]);
            } catch (\Throwable $e) { ReminderCheckException::from($e, ['stage' => 'submission_queue']); break; }
        }
        return $count;
    }
}
