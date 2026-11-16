<?php

namespace App\Services\DingTalk;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Client
{
    private ?\App\Models\DingTalkCompany $company = null;

    public function forCompany(\App\Models\DingTalkCompany $company): static
    {
        $copy = clone $this; $copy->company = $company; return $copy;
    }

    private function setting(string $key): mixed
    {
        if (!$this->company) throw new HttpException(503, __('dingtalk.errors.not_configured'));
        return $this->company->getAttribute($key);
    }

    private string $diagnosticEndpoint = '';
    private ?int $diagnosticStatus = null;
    public function assertConfigured(): void
    {
        foreach (['corp_id', 'app_key', 'app_secret', 'redirect_uri'] as $key) {
            if (!is_string($this->setting($key)) || trim($this->setting($key)) === '') {
                throw new HttpException(503, __('dingtalk.errors.not_configured'));
            }
        }
        $uri = parse_url($this->setting('redirect_uri'));
        if (!$uri || !in_array($uri['scheme'] ?? '', ['http', 'https'], true) || empty($uri['host'])
            || isset($uri['query']) || isset($uri['fragment']) || isset($uri['user']) || isset($uri['pass'])
            || (app()->environment('production') && $uri['scheme'] !== 'https')) {
            throw new HttpException(503, __('dingtalk.errors.not_configured'));
        }
    }

    public function loginUnionId(string $code): string
    {
        $this->assertConfigured();
        $token = $this->post('https://api.dingtalk.com/v1.0/oauth2/userAccessToken', [
            'clientId' => $this->setting('app_key'), 'clientSecret' => $this->setting('app_secret'),
            'code' => $code, 'grantType' => 'authorization_code',
        ]);
        if (!is_string($token['accessToken'] ?? null) || $token['accessToken'] === '') {
            $this->fail($body ?? $token ?? $result ?? []);
        }
        // The corpid scope is mandatory; do not silently drop the organization check.
        if (!is_string($token['corpId'] ?? null) || $token['corpId'] === '') {
            $this->fail($body ?? $token ?? $result ?? []);
        }
        if (!hash_equals($this->setting('corp_id'), $token['corpId'])) {
            throw new HttpException(403, __('dingtalk.errors.not_member'));
        }
        $this->diagnosticEndpoint = '/v1.0/contact/users/me';
        $this->diagnosticStatus = null;
        try {
            $response = Http::acceptJson()->connectTimeout(2)->timeout(config('dingtalk.http_timeout'))
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['x-acs-dingtalk-access-token' => $token['accessToken']])
                ->get('https://api.dingtalk.com/v1.0/contact/users/me');
        } catch (ConnectionException) {
            $this->fail($body ?? $token ?? $result ?? []);
        }
        $this->diagnosticStatus = $response->status();
        $body = $response->json();
        if (!$response->successful() || !is_array($body) || !is_string($body['unionId'] ?? null) || $body['unionId'] === '') {
            $this->fail($body ?? $token ?? $result ?? []);
        }
        return $body['unionId'];
    }

    public function memberByUnionId(string $unionId): ?array
    {
        $resolved = $this->resolveUnionId($unionId);
        if ($resolved === null) {
            return null;
        }
        $member = $this->profile($resolved);
        return $member && hash_equals($unionId, $member['union_id']) ? $member : null;
    }

    public function memberByUserId(string $userId): ?array
    {
        $member = $this->profile($userId);
        if (!$member) {
            return null;
        }
        $resolved = $this->resolveUnionId($member['union_id']);
        return $resolved !== null && hash_equals($userId, $resolved) ? $member : null;
    }

    private function profile(string $userId): ?array
    {
        $result = $this->legacy('/topapi/v2/user/get', ['userid' => $userId]);
        if ($result === null) {
            return null;
        }
        if (!is_bool($result['active'] ?? null) || !is_string($result['unionid'] ?? null)
            || $result['unionid'] === '' || !is_string($result['userid'] ?? null)
            || !hash_equals($userId, $result['userid'])) {
            $this->fail($body ?? $token ?? $result ?? []);
        }
        if (!$result['active']) {
            return null;
        }
        return ['corp_id' => $this->setting('corp_id'), 'user_id' => $userId,
            'union_id' => $result['unionid'], 'name' => mb_substr((string) ($result['name'] ?? $userId), 0, 190)];
    }

    private function resolveUnionId(string $unionId): ?string
    {
        $result = $this->legacy('/topapi/user/getbyunionid', ['unionid' => $unionId]);
        if ($result === null) {
            return null;
        }
        if (!isset($result['contact_type']) || !is_string($result['userid'] ?? null) || $result['userid'] === '') {
            $this->fail($body ?? $token ?? $result ?? []);
        }
        return (int) $result['contact_type'] === 0 ? $result['userid'] : null;
    }

    private function legacy(string $path, array $data, bool $retry = true): ?array
    {
        $body = $this->post('https://oapi.dingtalk.com'.$path.'?access_token='.rawurlencode($this->appToken()), $data);
        if ($retry && in_array((int) ($body['errcode'] ?? -1), [88, 40014, 42001], true)) {
            Cache::forget($this->tokenCacheKey());
            return $this->legacy($path, $data, false);
        }
        // Only explicit authoritative "employee does not exist" results revoke access.
        if (in_array((int) ($body['errcode'] ?? -1), [60111, 60121], true)) {
            return null;
        }
        if (!array_key_exists('errcode', $body) || (int) $body['errcode'] !== 0 || !is_array($body['result'] ?? null)) {
            $this->fail($body ?? $token ?? $result ?? []);
        }
        return $body['result'];
    }

    public function departmentChildren(int $parent): array
    {
        $result = $this->legacy('/topapi/v2/department/listsub', ['dept_id' => $parent, 'language' => 'zh_CN']);
        if ($result === null || !array_is_list($result)) throw new HttpException(503, __('dingtalk.manage.directory_invalid'));
        $rows = [];
        foreach ($result as $department) {
            if (!is_array($department) || filter_var($department['dept_id'] ?? null, FILTER_VALIDATE_INT) === false
                || (int) $department['dept_id'] < 1 || !is_string($department['name'] ?? null)) {
                throw new HttpException(503, __('dingtalk.manage.directory_invalid'));
            }
            $rows[] = ['id' => (int) $department['dept_id'], 'name' => $department['name']];
        }
        return $rows;
    }

    public function departmentMembers(int $department, int $cursor): array
    {
        $result = $this->legacy('/topapi/v2/user/list', ['dept_id' => $department, 'cursor' => $cursor, 'size' => 100, 'language' => 'zh_CN']);
        if (!$result || !is_array($result['list'] ?? null) || !array_is_list($result['list']) || !isset($result['has_more'])) {
            throw new HttpException(503, __('dingtalk.manage.directory_invalid'));
        }
        $more = filter_var($result['has_more'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($more === null || ($more && (filter_var($result['next_cursor'] ?? null, FILTER_VALIDATE_INT) === false
            || (int) $result['next_cursor'] < 0))) throw new HttpException(503, __('dingtalk.manage.directory_invalid'));
        $rows = [];
        foreach ($result['list'] as $member) {
            if (!is_array($member) || !is_string($member['userid'] ?? null) || $member['userid'] === '' || !is_string($member['name'] ?? null)) {
                throw new HttpException(503, __('dingtalk.manage.directory_invalid'));
            }
            $rows[] = ['user_id' => $member['userid'], 'name' => $member['name']];
        }
        return ['members' => $rows, 'has_more' => $more, 'next_cursor' => $more ? (int) $result['next_cursor'] : null];
    }

    private function appToken(): string
    {
        $this->assertConfigured();
        $key = $this->tokenCacheKey();
        if (is_string($cached = Cache::get($key)) && $cached !== '') {
            return $cached;
        }
        $body = $this->post('https://api.dingtalk.com/v1.0/oauth2/accessToken', [
            'appKey' => $this->setting('app_key'), 'appSecret' => $this->setting('app_secret'),
        ]);
        if (!is_string($body['accessToken'] ?? null) || $body['accessToken'] === '' || (int) ($body['expireIn'] ?? 0) <= 60) {
            $this->fail($body ?? $token ?? $result ?? []);
        }
        Cache::put($key, $body['accessToken'], min(6900, (int) $body['expireIn'] - 60));
        return $body['accessToken'];
    }

    private function tokenCacheKey(): string
    {
        return 'dingtalk:app-token:'.hash('sha256', $this->setting('corp_id').'|'.$this->setting('app_key').'|'.$this->setting('app_secret'));
    }

    private function post(string $url, array $data): array
    {
        $this->diagnosticEndpoint = (string) parse_url($url, PHP_URL_PATH);
        $this->diagnosticStatus = null;
        try {
            $response = Http::acceptJson()->connectTimeout(2)->timeout(config('dingtalk.http_timeout'))
                ->withOptions(['allow_redirects' => false])->post($url, $data);
        } catch (ConnectionException) {
            // Discard the original HTTP exception: it may contain credentials in the URL/body.
            $this->fail($body ?? $token ?? $result ?? []);
        }
        $this->diagnosticStatus = $response->status();
        $body = $response->json();
        if (!$response->successful() || !is_array($body)) {
            $this->fail($body ?? $token ?? $result ?? []);
        }
        return $body;
    }

    private function fail(mixed $body = []): never
    {
        $body = is_array($body) ? $body : [];
        $id = bin2hex(random_bytes(8));
        $code = $body['errcode'] ?? $body['code'] ?? null;
        $code = (is_string($code) || is_int($code)) && preg_match('/\A[A-Za-z0-9_.:-]{1,80}\z/D', (string) $code) ? (string) $code : null;
        $fields = match ($this->diagnosticEndpoint) {
            '/v1.0/oauth2/accessToken' => ['accessToken' => 'string', 'expireIn' => 'positive'],
            '/v1.0/oauth2/userAccessToken' => ['accessToken' => 'string', 'corpId' => 'string'],
            '/v1.0/contact/users/me' => ['unionId' => 'string'],
            '/topapi/v2/user/get' => ['active' => 'boolean', 'unionid' => 'string', 'userid' => 'string'],
            '/topapi/user/getbyunionid' => ['contact_type' => 'present', 'userid' => 'string'],
            default => [],
        };
        $values = is_array($body['result'] ?? null) ? $body['result'] : $body;
        $invalid = [];
        foreach ($fields as $field => $type) {
            $value = $values[$field] ?? null;
            $valid = match ($type) {
                'string' => is_string($value) && $value !== '',
                'boolean' => is_bool($value),
                'positive' => is_numeric($value) && (float) $value > 60,
                default => $value !== null,
            };
            if (!$valid) $invalid[] = $field;
        }
        // Never log full URLs, request data, response bodies, field values or exception objects.
        Log::warning(__('dingtalk.diagnostics.log_message'), [
            'diagnostic_id' => $id, 'company_id' => $this->company?->id, 'endpoint' => $this->diagnosticEndpoint,
            'http_status' => $this->diagnosticStatus, 'api_code' => $code,
            'invalid_fields' => $invalid,
            'reason' => $this->diagnosticStatus === null ? 'connection_failed' : 'http_api_or_field_validation_failed',
        ]);
        throw new HttpException(503, __('dingtalk.errors.unavailable_diagnostic', ['id' => $id]));
    }
}
