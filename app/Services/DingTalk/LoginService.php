<?php

namespace App\Services\DingTalk;

use App\Http\Middleware\EnforceAdminLoginExpiration;
use App\Models\AdminUser;
use App\Models\DingTalkBinding;
use App\Models\DingTalkIdentity;
use App\Portal\Services\PortalDomainService;
use App\Portal\Services\PortalReturnUrlService;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LoginService
{
    public const PROOF_KEY = 'dingtalk.login';
    private const PENDING_KEY = 'dingtalk.pending';

    public function __construct(private readonly Client $client, private readonly BindingService $bindings,
        private readonly MembershipService $memberships, private readonly AuditService $audit) {}

    public function start(Request $request, ?int $companyId = null): array
    {
        app(\App\Services\Authentication\ModeService::class)->requireDingTalk();
        $companies = app(CompanyService::class);
        $options = $companyId ? null : $companies->options();
        $company = $companyId ? $companies->enabled($companyId) : ($options->count() === 1 ? $companies->enabled((int)$options->first()->id) : throw new HttpException(503, __('dingtalk.company.choose')));
        $this->client->forCompany($company)->assertConfigured();
        // Reject a misconfigured callback pointing at another ERP host or endpoint.
        $callback = app(PortalDomainService::class)->mainUrl('/auth/dingtalk/callback');
        if (!hash_equals(rtrim($callback, '/'), rtrim((string) $company->redirect_uri, '/'))) {
            throw new HttpException(503, __('dingtalk.errors.not_configured'));
        }
        $request->session()->forget(self::PENDING_KEY);
        $state = $this->challenge($request, 'state', company: $company);
        $parameters = ['redirect_uri' => $company->redirect_uri, 'response_type' => 'code',
            'client_id' => $company->app_key, 'scope' => 'openid corpid', 'state' => $state,
            'prompt' => 'consent', 'corpId' => $company->corp_id];

        return ['frame_url' => 'https://login.dingtalk.com/oauth2/auth?'.http_build_query(['iframe' => 'true'] + $parameters, '', '&', PHP_QUERY_RFC3986),
            'authorize_url' => 'https://login.dingtalk.com/oauth2/auth?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
            'callback_url' => $company->redirect_uri, 'state' => $state,
            'expires_at' => now()->addSeconds(config('dingtalk.challenge_seconds'))->timestamp];
    }

    public function acceptCallback(Request $request, string $state, string $code): Collection
    {
        app(\App\Services\Authentication\ModeService::class)->requireDingTalk();
        // Consume before exchanging the code, including failed exchanges. Codes are never retried/reused.
        $stateProof = $this->consume($request, $state, 'state');
        $company = app(CompanyService::class)->enabled((int)$stateProof->company_id);
        app(CompanyService::class)->assertVersion($company, (int)$stateProof->company_version);
        app(LoginEntryService::class)->remember($request, $company);
        $client = $this->client->forCompany($company);
        $unionId = $client->loginUnionId($code);
        $member = $client->memberByUnionId($unionId);
        if (!$member) {
            $this->audit->record('login.denied', null, null, ['reason' => 'not_member']);
            throw new HttpException(403, __('dingtalk.errors.not_member'));
        }
        $identity = DingTalkIdentity::query()->where('corp_id', $company->corp_id)->where('user_id', $member['user_id'])->first();
        if (!$identity || !$identity->active || !hash_equals($identity->union_id, $unionId)) {
            $this->audit->record('login.denied', $identity, null, ['reason' => 'not_bound']);
            throw new HttpException(403, __('dingtalk.errors.not_bound'));
        }
        // Fetch again with the local generation snapshot so a concurrent leave cannot revive the identity.
        $identity = $this->memberships->refresh($identity);
        if (!$identity->active) {
            throw new HttpException(401, __('dingtalk.errors.revoked'));
        }
        $choices = $this->choices($identity);
        if ($choices->isEmpty()) {
            $this->audit->record('login.denied', $identity, null, ['reason' => 'no_account']);
            throw new HttpException(403, __('dingtalk.errors.no_account'));
        }
        $snapshot = $choices->mapWithKeys(fn (DingTalkBinding $binding): array => [(string) $binding->id => $binding->version])->all();
        $pending = $this->challenge($request, 'pending', $identity, $snapshot, $stateProof->expires_at, $company);
        $request->session()->put(self::PENDING_KEY, $pending);
        $this->audit->record('login.verified', $identity, null, ['accounts' => $choices->pluck('account_id')->all()]);
        return $choices;
    }

    public function pendingChoices(Request $request): Collection
    {
        app(\App\Services\Authentication\ModeService::class)->requireDingTalk();
        $pending = $this->pending($request);
        $company = app(CompanyService::class)->enabled((int)$pending->company_id);
        $identity = DingTalkIdentity::query()->find($pending->identity_id);
        if (!$identity || !hash_equals($company->corp_id,$identity->corp_id) || !$identity->active || $identity->version !== (int) $pending->identity_version
            || !$this->memberships->isFresh($identity)) {
            throw new HttpException(401, __('dingtalk.errors.expired'));
        }
        $snapshot = json_decode($pending->payload ?? '{}', true);
        return $this->choices($identity)->filter(fn (DingTalkBinding $binding): bool =>
            (int) ($snapshot[(string) $binding->id] ?? 0) === $binding->version)->values();
    }

    public function complete(Request $request, int $accountId): string
    {
        $modeService = app(\App\Services\Authentication\ModeService::class);
        $modeState = $modeService->requireDingTalk();
        $pending = $this->pending($request);
        $company = app(CompanyService::class)->enabled((int)$pending->company_id);
        $identity = DingTalkIdentity::query()->findOrFail($pending->identity_id);
        if (!hash_equals($company->corp_id,$identity->corp_id) || $identity->version !== (int) $pending->identity_version) {
            throw new HttpException(401, __('dingtalk.errors.expired'));
        }
        $identity = $this->memberships->refresh($identity);
        if (!$identity->active) {
            throw new HttpException(401, __('dingtalk.errors.revoked'));
        }
        $proof = DB::transaction(function () use ($request, $accountId, $pending, $identity, $company): array {
            app(CompanyService::class)->assertVersion($company, (int)$pending->company_version);
            $account = AdminUser::query()->lockForUpdate()->find($accountId);
            $current = DingTalkIdentity::query()->lockForUpdate()->findOrFail($identity->id);
            $binding = DingTalkBinding::query()->where('account_id', $accountId)->where('identity_id', $current->id)->lockForUpdate()->first();
            $snapshot = json_decode($pending->payload ?? '{}', true);
            if (!$account || !$current->active || $current->version !== (int) $pending->identity_version
                || !$this->memberships->isFresh($current) || !$binding || !$binding->active
                || (int) ($snapshot[(string) $binding->id] ?? 0) !== $binding->version
                || !$this->bindings->eligibleAccount($account)) {
                throw new HttpException(403, __('dingtalk.errors.no_account'));
            }
            $this->consume($request, (string) $request->session()->get(self::PENDING_KEY), 'pending');
            $proof = ['company_id' => $company->id, 'company_version' => (int)$company->version, 'identity_id' => $current->id, 'identity_version' => $current->version,
                'binding_id' => $binding->id, 'binding_version' => $binding->version,
                'account_id' => $accountId, 'issued_at' => now()->timestamp];
            return $proof;
        }, 3);

        // No authentication exists during callback validation or account selection.
        if (!Admin::guard()->loginUsingId($accountId, false)) {
            throw new HttpException(403, __('dingtalk.errors.no_account'));
        }
        $request->session()->regenerate(true);
        $request->session()->forget(self::PENDING_KEY);
        $request->session()->put(self::PROOF_KEY, $proof);
        $modeService->stamp($request, Admin::guard()->user(), $modeState);
        $request->session()->put(EnforceAdminLoginExpiration::LOGIN_ISSUED_AT_KEY, $proof['issued_at']);
        $this->validSession($request, Admin::guard()->user());
        $this->audit->record('login.succeeded', $identity, $accountId, ['binding_id' => $proof['binding_id']]);

        return app(PortalReturnUrlService::class)->pull($request) ?? app(PortalDomainService::class)->mainAdminUrl();
    }

    public function validSession(Request $request, AdminUser $account): DingTalkIdentity
    {
        $proof = $request->session()->get(self::PROOF_KEY);
        if (!is_array($proof) || (int) ($proof['account_id'] ?? 0) !== (int) $account->id
            || (int) ($proof['issued_at'] ?? 0) <= 0
            || now()->timestamp >= (int) $proof['issued_at'] + app(\App\Services\SystemSettingService::class)->loginExpirationSeconds()) {
            throw new HttpException(401, __('dingtalk.errors.expired'));
        }
        $identity = DingTalkIdentity::query()->find($proof['identity_id'] ?? 0);
        $binding = DingTalkBinding::query()->find($proof['binding_id'] ?? 0);
        $company = app(CompanyService::class)->enabled((int)($proof['company_id'] ?? 0));
        app(CompanyService::class)->assertVersion($company, (int)($proof['company_version'] ?? 0));
        if (!$identity || !$binding || !$identity->active || !$binding->active
            || !hash_equals((string) $company->corp_id, $identity->corp_id)
            || $identity->version !== (int) ($proof['identity_version'] ?? 0)
            || $binding->version !== (int) ($proof['binding_version'] ?? 0)
            || (int) $binding->identity_id !== (int) $identity->id || (int) $binding->account_id !== (int) $account->id
            || !$this->bindings->eligibleAccount($account)) {
            throw new HttpException(401, __('dingtalk.errors.revoked'));
        }
        if (!$this->memberships->isFresh($identity)) {
            $identity = $this->memberships->refresh($identity);
            if (!$this->memberships->isFresh($identity)) {
                throw new HttpException(401, __('dingtalk.errors.revoked'));
            }
        }
        return $identity;
    }

    public function choices(DingTalkIdentity $identity): Collection
    {
        return $identity->bindings()->where('active', true)->with('account')->orderBy('account_id')->get()
            ->filter(fn (DingTalkBinding $binding): bool => $binding->account !== null && $this->bindings->eligibleAccount($binding->account))->values();
    }

    private function browserHash(Request $request): string
    {
        if (!$request->session()->has('dingtalk.browser')) {
            $request->session()->put('dingtalk.browser', bin2hex(random_bytes(32)));
        }
        return hash('sha256', $request->session()->get('dingtalk.browser'));
    }

    private function challenge(Request $request, string $purpose, ?DingTalkIdentity $identity = null, ?array $payload = null, ?string $expiresAt = null, ?\App\Models\DingTalkCompany $company = null): string
    {
        $token = bin2hex(random_bytes(32));
        DB::table('dingtalk_challenges')->insert(['token_hash' => hash('sha256', $token), 'browser_hash' => $this->browserHash($request),
            'company_id' => $company?->id, 'company_version' => $company?->version, 'purpose' => $purpose, 'identity_id' => $identity?->id, 'identity_version' => $identity?->version,
            'payload' => $payload === null ? null : json_encode($payload), 'expires_at' => $expiresAt ?? now()->addSeconds(config('dingtalk.challenge_seconds')),
            'created_at' => now()]);
        return $token;
    }

    private function consume(Request $request, string $token, string $purpose): object
    {
        $query = DB::table('dingtalk_challenges')->where('token_hash', hash('sha256', $token))
            ->where('browser_hash', $this->browserHash($request))->where('purpose', $purpose)
            ->whereNull('consumed_at')->where('expires_at', '>', now());
        $challenge = strlen($token) === 64 ? (clone $query)->first() : null;
        if (!$challenge || !$query->update(['consumed_at' => now()])) {
            throw new HttpException(401, __('dingtalk.errors.expired'));
        }
        return $challenge;
    }

    private function pending(Request $request): object
    {
        $token = $request->session()->get(self::PENDING_KEY);
        $pending = is_string($token) ? DB::table('dingtalk_challenges')->where('token_hash', hash('sha256', $token))
            ->where('browser_hash', $this->browserHash($request))->where('purpose', 'pending')
            ->whereNull('consumed_at')->where('expires_at', '>', now())->first() : null;
        if (!$pending) {
            throw new HttpException(401, __('dingtalk.errors.expired'));
        }
        $company = app(CompanyService::class)->enabled((int)$pending->company_id);
        app(CompanyService::class)->assertVersion($company, (int)$pending->company_version);
        return $pending;
    }
}
