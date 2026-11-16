<?php

namespace App\Services\DingTalk;

use App\Models\DingTalkAudit;
use App\Models\DingTalkIdentity;

class AuditService
{
    public function record(string $action, ?DingTalkIdentity $identity = null, ?int $accountId = null, array $metadata = []): DingTalkAudit
    {
        $request = app()->bound('request') ? request() : null;
        $actor = $request?->attributes->get('dingtalk.identity') ?? $identity;
        $actorAccount = $request?->attributes->get('dingtalk.account_id') ?? $accountId;
        $metadata['actor_type'] = $request?->attributes->has('dingtalk.identity') || (str_starts_with($action, 'login.') && $identity)
            ? 'dingtalk' : (app()->runningInConsole() ? 'console' : 'system');

        $password = $request?->attributes->get('erp.auth_mode') === 'password';
        if ($password) $metadata['actor_type'] = 'password';
        if ($action === 'authentication.mode_changed') $metadata['actor_type'] = 'system';
        return DingTalkAudit::query()->create([
            'action' => $action,
            'identity_id' => $actor?->id,
            'account_id' => $actorAccount,
            'corp_id' => $actor?->corp_id,
            'user_id' => $actor?->user_id,
            'actor_name' => $password ? \App\Models\AdminUser::query()->find($actorAccount)?->name : $actor?->name,
            'session_hash' => $request?->hasSession() ? hash('sha256', $request->session()->getId()) : null,
            'ip' => $request?->ip(),
            // Callers supply explicit safe fields. Never pass request input, credentials or tokens.
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
