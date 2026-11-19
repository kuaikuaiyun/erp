<?php

namespace App\Portals\AssetManager;

use App\Models\DingTalkCompany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ReminderDingTalkClient
{
    private function token(DingTalkCompany $company): string
    {
        if (! $company->enabled || $company->trashed() || ! $company->app_key || ! $company->app_secret) {
            throw new RuntimeException(__('asset_reminders.company_unavailable'));
        }
        $key = 'asset-reminder:token:'.hash('sha256', $company->corp_id.'|'.$company->app_key.'|'.$company->app_secret);
        return Cache::remember($key, 600, function () use ($company) {
            $data = $this->request('https://api.dingtalk.com/v1.0/oauth2/accessToken', [
                'appKey' => $company->app_key, 'appSecret' => $company->app_secret,
            ]);
            if (! is_string($data['accessToken'] ?? null) || $data['accessToken'] === '' || (int) ($data['expireIn'] ?? 0) < 660) {
                throw new RuntimeException(__('asset_reminders.api_failed'));
            }
            return $data['accessToken'];
        });
    }

    private function request(string $url, array $payload, ?string $token = null): array
    {
        try {
            $request = Http::acceptJson()->connectTimeout(3)->timeout(15)->withOptions(['allow_redirects' => false]);
            if ($token !== null) {
                $request = $request->withHeaders(['x-acs-dingtalk-access-token' => $token]);
            }
            $response = $request->post($url, $payload);
        } catch (\Throwable) {
            // Never propagate request exceptions containing access tokens or credentials.
            throw new RuntimeException(__('asset_reminders.api_uncertain'));
        }
        if (! $response->successful() || ! is_array($data = $response->json())) {
            throw new RuntimeException(__('asset_reminders.api_failed'));
        }
        return $data;
    }

    public function legacy(DingTalkCompany $company, string $path, array $payload): array
    {
        if (! in_array($path, ['/topapi/attendance/getsimplegroups', '/topapi/attendance/schedule/listbyusers'], true)) {
            throw new RuntimeException(__('asset_reminders.api_failed'));
        }
        $data = $this->request('https://oapi.dingtalk.com'.$path.'?access_token='.rawurlencode($this->token($company)), $payload);
        if (! array_key_exists('errcode', $data) || (int) $data['errcode'] !== 0 || ! is_array($data['result'] ?? null)) {
            throw new RuntimeException(__('asset_reminders.api_failed'));
        }
        return $data['result'];
    }

    public function send(DingTalkCompany $company, object $group, string $title, string $text): string
    {
        $data = $this->request('https://api.dingtalk.com/v1.0/robot/groupMessages/send', [
            'robotCode' => $group->robot_code, 'openConversationId' => $group->conversation_id,
            'msgKey' => 'sampleMarkdown', 'msgParam' => json_encode(compact('title', 'text'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ], $this->token($company));
        if (! is_string($data['processQueryKey'] ?? null) || $data['processQueryKey'] === '') {
            throw new RuntimeException(__('asset_reminders.api_failed'));
        }
        return $data['processQueryKey'];
    }
}
