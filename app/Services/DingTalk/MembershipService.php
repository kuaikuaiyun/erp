<?php

namespace App\Services\DingTalk;

use App\Models\DingTalkBinding;
use App\Models\DingTalkIdentity;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MembershipService
{
    public function __construct(private readonly Client $client, private readonly AuditService $audit) {}

    public function isFresh(DingTalkIdentity $identity): bool
    {
        return $identity->active && $identity->verified_at !== null
            && $identity->verified_at->timestamp + (int) config('dingtalk.membership_seconds') > now()->timestamp;
    }

    public function refresh(DingTalkIdentity $identity): DingTalkIdentity
    {
        $version = $identity->version;
        $company = app(CompanyService::class)->forCorp($identity->corp_id);
        $companyVersion = (int)$company->version;
        if (!$identity->active) {
            throw new HttpException(401, __('dingtalk.errors.revoked'));
        }
        $startedAt = now();
        $member = $this->client->forCompany($company)->memberByUserId($identity->user_id);
        return DB::transaction(function () use ($identity, $version, $member, $startedAt, $company, $companyVersion): DingTalkIdentity {
            app(CompanyService::class)->assertVersion($company, $companyVersion);
            $current = DingTalkIdentity::query()->lockForUpdate()->findOrFail($identity->id);
            // A late response cannot undo a callback/admin revocation or certify a newer binding generation.
            if (!$current->active || $current->version !== $version) {
                throw new HttpException(401, __('dingtalk.errors.revoked'));
            }
            if (!$member || !hash_equals($current->union_id, $member['union_id'])) {
                $this->revokeLocked($current, 'not_member');
                return $current;
            }
            $current->forceFill(['name' => $member['name'], 'verified_at' => $startedAt,
                'next_check_at' => now()->addSeconds(config('dingtalk.reconcile_seconds'))])->save();
            return $current;
        }, 3);
    }

    public function revokeLocked(DingTalkIdentity $identity, string $reason, int $eventAt = 0): void
    {
        // Caller holds the identity row lock, in the same transaction as event deduplication.
        if ($identity->active) {
            $identity->forceFill(['active' => false, 'version' => $identity->version + 1, 'verified_at' => null])->save();
            DingTalkBinding::query()->where('identity_id', $identity->id)->where('active', true)
                ->update(['active' => false, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            DB::table('dingtalk_challenges')->where('identity_id', $identity->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $this->audit->record('identity.revoked', $identity, null, ['reason' => $reason, 'target_identity_id' => $identity->id]);
        }
        if ($eventAt > (int) $identity->last_event_at) {
            $identity->forceFill(['last_event_at' => $eventAt])->save();
        }
    }

    public function receiveEvent(array $event, ?\App\Models\DingTalkCompany $company = null): void
    {
        if (!is_string($event['CorpId'] ?? null) || $event['CorpId']==='') throw new HttpException(403, __('dingtalk.errors.bad_callback'));
        $company ??= \App\Models\DingTalkCompany::query()->where('corp_id',$event['CorpId'])->where('enabled',true)->first();
        if (!$company || !$company->enabled || $company->trashed() || !hash_equals($company->corp_id, $event['CorpId'])) {
            throw new HttpException(403, __('dingtalk.errors.bad_callback'));
        }
        $type = $event['EventType'] ?? '';
        if (!is_string($type) || strlen($type) > 64) {
            throw new HttpException(403, __('dingtalk.errors.bad_callback'));
        }
        $userIds = $event['UserId'] ?? $event['userId'] ?? [];
        if (!is_array($userIds) || count($userIds) > 1000) {
            throw new HttpException(403, __('dingtalk.errors.bad_callback'));
        }
        foreach ($userIds as $userId) {
            if (!is_string($userId) || $userId === '' || strlen($userId) > 128) {
                throw new HttpException(403, __('dingtalk.errors.bad_callback'));
            }
        }
        if ($type === 'user_leave_org' && $userIds === []) {
            throw new HttpException(403, __('dingtalk.errors.bad_callback'));
        }
        $rawTime = $event['EventTime'] ?? $event['TimeStamp'] ?? $event['timeStamp'] ?? 0;
        $externalId = $event['eventId'] ?? $event['BizId'] ?? '';
        if ((!is_int($rawTime) && (!is_string($rawTime) || !ctype_digit($rawTime)))
            || !is_scalar($externalId) || strlen((string) $externalId) > 256 || (int) $rawTime < 0) {
            throw new HttpException(403, __('dingtalk.errors.bad_callback'));
        }
        $eventAt = (int) $rawTime;
        if (in_array($type, ['user_leave_org', 'org_remove'], true) && (string) $externalId === '' && $eventAt <= 0) {
            throw new HttpException(403, __('dingtalk.errors.bad_callback'));
        }
        if ($eventAt > 0 && $eventAt < 100000000000) {
            $eventAt *= 1000;
        }
        sort($userIds, SORT_STRING);
        $eventKey = hash('sha256', $event['CorpId'].'|'.$type.'|'.$externalId.'|'.$eventAt.'|'.json_encode($userIds));
        DB::transaction(function () use ($eventKey, $type, $userIds, $eventAt, $event): void {
            // Duplicate callbacks may run concurrently. The unique key and transaction cover all effects.
            if (!DB::table('dingtalk_events')->insertOrIgnore(['event_key' => $eventKey, 'event_type' => $type,
                'corp_id' => $event['CorpId'], 'user_ids' => json_encode($userIds), 'event_at' => $eventAt, 'created_at' => now()])) {
                return;
            }
            if ($type === 'user_leave_org') {
                // Keep a tombstone even for an unbound member: a concurrent first bind must not miss this leave.
                foreach ($userIds as $userId) {
                    DingTalkIdentity::query()->firstOrCreate(['corp_id' => $event['CorpId'], 'user_id' => $userId],
                        ['union_id' => '', 'name' => $userId, 'active' => false, 'last_event_at' => $eventAt]);
                }
            }
            $query = DingTalkIdentity::query()->where('corp_id', $event['CorpId'])->orderBy('id')->lockForUpdate();
            if ($type !== 'org_remove') {
                $query->whereIn('user_id', $userIds);
            }
            foreach ($query->get() as $identity) {
                if (in_array($type, ['user_leave_org', 'org_remove'], true)) {
                    // Even delayed leave events invalidate access. Only an explicit admin rebind can restore it.
                    $this->revokeLocked($identity, $type, $eventAt);
                } elseif (in_array($type, ['user_modify_org', 'user_active_org'], true) && $identity->active) {
                    $identity->forceFill(['verified_at' => null, 'next_check_at' => now()])->save();
                }
                // Add/activation events never restore a revoked identity or binding.
            }
        }, 3);
    }
}
