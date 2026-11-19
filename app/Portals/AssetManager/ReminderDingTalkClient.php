<?php

namespace App\Portals\AssetManager;

use App\Models\DingTalkCompany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class ReminderDingTalkClient
{
    private function token(DingTalkCompany $company): string
    {
        if (! $company->enabled || $company->trashed() || ! $company->app_key || ! $company->app_secret) {
            throw new ReminderCheckException('company_unavailable');
        }
        $key = 'asset-reminder:token:'.hash('sha256', $company->corp_id.'|'.$company->app_key.'|'.$company->app_secret);
        return Cache::remember($key, 600, function () use ($company) {
            $data = $this->request('https://api.dingtalk.com/v1.0/oauth2/accessToken', [
                'appKey' => $company->app_key, 'appSecret' => $company->app_secret,
            ], 'token');
            if (! is_string($data['accessToken'] ?? null) || $data['accessToken'] === '' || (int) ($data['expireIn'] ?? 0) < 660) {
                throw $this->apiError('token', 'format', $data);
            }
            return $data['accessToken'];
        });
    }

    private function apiError(string $operation, string $reason, array $data = [], ?int $status = null): ReminderCheckException
    {
        $code = $data['errcode'] ?? null;
        $code = is_scalar($code) && preg_match('/\A-?[0-9]{1,12}\z/D', (string) $code) ? (string) $code : __('asset_reminders.api_not_returned', [], 'zh-CN');
        return new ReminderCheckException('api_check_failed', [
            'operation' => __('asset_reminders.api_operations.'.$operation, [], 'zh-CN'),
            'reason' => __('asset_reminders.api_reasons.'.$reason, [], 'zh-CN'),
            'code' => $code, 'status' => $status ?? __('asset_reminders.api_not_returned', [], 'zh-CN'),
            'hint' => __('asset_reminders.api_hints.'.$operation, [], 'zh-CN'),
        ]);
    }

    private function request(string $url, array $payload, string $operation): array
    {
        try {
            $request = Http::acceptJson()->connectTimeout(3)->timeout(15)->withOptions(['allow_redirects' => false]);
            $response = $request->post($url, $payload);
        } catch (\Throwable) {
            // Never propagate request exceptions containing access tokens or credentials.
            throw $operation === 'webhook' ? new ReminderCheckException('api_uncertain') : $this->apiError($operation, 'connection');
        }
        if (! $response->successful() || ! is_array($data = $response->json())) {
            $data = $response->json();
            throw $this->apiError($operation, $response->successful() ? 'format' : 'http', is_array($data) ? $data : [], $response->status());
        }
        if ($operation !== 'webhook' && array_key_exists('errcode', $data) && ! in_array($data['errcode'], [0, '0'], true)) {
            throw $this->apiError($operation, 'rejected', $data, $response->status());
        }
        return $data;
    }

    public function legacy(DingTalkCompany $company, string $path, array $payload): array
    {
        if ($path !== '/topapi/attendance/getsimplegroups') {
            throw new ReminderCheckException('api_failed');
        }
        $operation = 'attendance_groups';
        $data = $this->request('https://oapi.dingtalk.com'.$path.'?access_token='.rawurlencode($this->token($company)), $payload, $operation);
        if (! array_key_exists('errcode', $data) || (int) $data['errcode'] !== 0 || ! is_array($data['result'] ?? null)) {
            throw $this->apiError($operation, 'format', $data);
        }
        return $data['result'];
    }

    public function normalizeWebhook(string $url): string
    {
        // Accept only the official endpoint and an unsigned access token; never follow redirects.
        if (! preg_match('~\Ahttps://oapi\.dingtalk\.com/robot/send\?access_token=([A-Za-z0-9_-]{1,256})\z~D', $url)) {
            throw new ReminderCheckException('webhook_invalid');
        }
        return $url;
    }

    public function send(object $group, string $title, string $text): void
    {
        if (empty($group->webhook)) {
            throw new ReminderCheckException('webhook_missing');
        }
        try {
            $url = $this->normalizeWebhook(Crypt::decryptString($group->webhook));
            $secret = $group->webhook_secret ? Crypt::decryptString($group->webhook_secret) : '';
        } catch (\Throwable) {
            throw new ReminderCheckException('webhook_unavailable');
        }
        if ($secret !== '') {
            $timestamp = (string) now()->getTimestampMs();
            $sign = base64_encode(hash_hmac('sha256', $timestamp."\n".$secret, $secret, true));
            $url .= '&'.http_build_query(['timestamp' => $timestamp, 'sign' => $sign], '', '&', PHP_QUERY_RFC3986);
        }
        $data = $this->request($url, ['msgtype' => 'markdown', 'markdown' => compact('title', 'text')], 'webhook');
        if (! array_key_exists('errcode', $data) || ! in_array($data['errcode'], [0, '0'], true)) {
            // Do not persist DingTalk's response text, which can echo credentials or message content.
            throw new ReminderCheckException('webhook_failed');
        }
    }
}
