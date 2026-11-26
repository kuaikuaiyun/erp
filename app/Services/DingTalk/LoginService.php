<?php

namespace App\Services\DingTalk;

use App\Http\Middleware\EnforceAdminLoginExpiration;
use App\Models\AdminUser;
use App\Models\DingTalkBinding;
use App\Models\DingTalkIdentity;
use App\Services\Authentication\LoginSiteService;
use App\Services\Authentication\ModeService;
use Illuminate\Support\Facades\Cache;
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
        private readonly AuditService $audit) {}

    public function start(Request $request, ?int $companyId = null): array
    {
        $policy = app(ModeService::class)->requireMethod($request, 'dingtalk');
        if (isset($policy->site_id)) {
            $companyId = (int) $policy->company_id;
            abort_unless($companyId, 503, __('dingtalk.errors.not_configured'));
        }
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
        $this->pruneExpiredChallenges();
        $state = $this->challenge($request, 'state', company: $company, policy: $policy);
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
        $policy = app(ModeService::class)->requireMethod($request, 'dingtalk');
        // Consume before exchanging the code, including failed exchanges. Codes are never retried/reused.
        $stateProof = $this->consume($request, $state, 'state');
        $company = app(CompanyService::class)->enabled((int)$stateProof->company_id);
        app(CompanyService::class)->assertVersion($company, (int)$stateProof->company_version);
        $this->assertChallengePolicy($stateProof, $policy);
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
        $choices = $this->choices($identity);
        if ($choices->isEmpty()) {
            $this->audit->record('login.denied', $identity, null, ['reason' => 'no_account']);
            throw new HttpException(403, __('dingtalk.errors.no_account'));
        }
        $snapshot = $choices->mapWithKeys(fn (DingTalkBinding $binding): array => [(string) $binding->id => $binding->version])->all();
        $pending = $this->challenge($request, 'pending', $identity, $snapshot, $stateProof->expires_at, $company, $policy);
        $request->session()->put(self::PENDING_KEY, $pending);
        $this->audit->record('login.verified', $identity, null, ['accounts' => $choices->pluck('account_id')->all()]);
        return $choices;
    }

    public function pendingChoices(Request $request): Collection
    {
        $pending = $this->pending($request, app(ModeService::class)->requireMethod($request, 'dingtalk'));
        $company = app(CompanyService::class)->enabled((int)$pending->company_id);
        $identity = DingTalkIdentity::query()->find($pending->identity_id);
        if (!$identity || !hash_equals($company->corp_id,$identity->corp_id) || !$identity->active || $identity->version !== (int) $pending->identity_version) {
            throw new HttpException(401, __('dingtalk.errors.expired'));
        }
        $snapshot = json_decode($pending->payload ?? '{}', true);
        return $this->choices($identity)->filter(fn (DingTalkBinding $binding): bool =>
            (int) ($snapshot[(string) $binding->id] ?? 0) === $binding->version)->values();
    }

    public function complete(Request $request, int $accountId): string
    {
        $modeService = app(\App\Services\Authentication\ModeService::class);
        $modeState = $modeService->requireMethod($request, 'dingtalk');
        $pending = $this->pending($request, $modeState);
        $company = app(CompanyService::class)->enabled((int)$pending->company_id);
        $identity = DingTalkIdentity::query()->find($pending->identity_id);
        if (!$identity || !$identity->active || !hash_equals($company->corp_id,$identity->corp_id) || $identity->version !== (int) $pending->identity_version) {
            throw new HttpException(401, __('dingtalk.errors.expired'));
        }
        $member = $this->client->forCompany($company)->memberByUserId($identity->user_id);
        if (!$member || !hash_equals($identity->union_id, $member['union_id'])) {
            throw new HttpException(403, __('dingtalk.errors.not_member'));
        }
        $proof = DB::transaction(function () use ($request, $accountId, $pending, $identity, $company, $member): array {
            app(CompanyService::class)->assertVersion($company, (int)$pending->company_version);
            $account = AdminUser::query()->lockForUpdate()->find($accountId);
            $current = DingTalkIdentity::query()->lockForUpdate()->findOrFail($identity->id);
            $binding = DingTalkBinding::query()->where('account_id', $accountId)->where('identity_id', $current->id)->lockForUpdate()->first();
            $snapshot = json_decode($pending->payload ?? '{}', true);
            if (!$account || !$current->active || $current->version !== (int) $pending->identity_version
                || !hash_equals($current->union_id, $member['union_id']) || !$binding || !$binding->active
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
        try {
            $modeService->stamp($request, Admin::guard()->user(), $modeState);
            $request->session()->put(EnforceAdminLoginExpiration::LOGIN_ISSUED_AT_KEY, $proof['issued_at']);
            $this->validSession($request, Admin::guard()->user());
        } catch (\Throwable $exception) {
            Admin::guard()->logoutCurrentDevice();
            $request->session()->forget([self::PROOF_KEY, ModeService::KEY]);
            throw $exception;
        }
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
        $site = app(LoginSiteService::class)->forRequest($request);
        abort_if($site && (int) $site->dingtalk_company_id !== (int) $company->id, 401, __('dingtalk.errors.revoked'));
        return $identity;
    }

    public function choices(DingTalkIdentity $identity): Collection
    {
        return $identity->bindings()->where('active', true)->with('account')->orderBy('account_id')->get()
            ->filter(fn (DingTalkBinding $binding): bool => $binding->account !== null && $this->bindings->eligibleAccount($binding->account))->values();
    }

    private function pruneExpiredChallenges(): void
    {
        if (!Cache::add('dingtalk:challenges:prune', true, 60)) {
            return;
        }

        $expired = DB::table('dingtalk_challenges')->where('expires_at', '<', now()->subDay());
        $ids = (clone $expired)->orderBy('expires_at')->limit(500)->pluck('id');
        if ($ids->isNotEmpty()) {
            $expired->whereIn('id', $ids)->delete();
        }
    }

    private function assertChallengePolicy(object $challenge, object $policy): void
    {
        abort_unless((int) ($challenge->login_site_id ?? 0) === (int) ($policy->site_id ?? 0)
            && (!isset($policy->site_id) || ((int) $challenge->login_method_version === (int) $policy->version
                && (int) $challenge->company_id === (int) $policy->company_id)), 401, __('dingtalk.errors.expired'));
    }

    private function browserHash(Request $request): string
    {
        if (!$request->session()->has('dingtalk.browser')) {
            $request->session()->put('dingtalk.browser', bin2hex(random_bytes(32)));
        }
        return hash('sha256', $request->session()->get('dingtalk.browser'));
    }

    private function challenge(Request $request, string $purpose, ?DingTalkIdentity $identity = null, ?array $payload = null, ?string $expiresAt = null, ?\App\Models\DingTalkCompany $company = null, ?object $policy = null): string
    {
        $token = bin2hex(random_bytes(32));
        DB::table('dingtalk_challenges')->insert(['token_hash' => hash('sha256', $token), 'browser_hash' => $this->browserHash($request),
            'company_id' => $company?->id, 'company_version' => $company?->version, 'purpose' => $purpose, 'identity_id' => $identity?->id, 'identity_version' => $identity?->version,
            'payload' => $payload === null ? null : json_encode($payload), 'expires_at' => $expiresAt ?? now()->addSeconds(config('dingtalk.challenge_seconds')),
            'created_at' => now(),
            ...(isset($policy->site_id) ? ['login_site_id' => $policy->site_id, 'login_method_version' => $policy->version] : []),
        ]);
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

    private function pending(Request $request, object $policy): object
    {
        $token = $request->session()->get(self::PENDING_KEY);
        $pending = is_string($token) ? DB::table('dingtalk_challenges')->where('token_hash', hash('sha256', $token))
            ->where('browser_hash', $this->browserHash($request))->where('purpose', 'pending')
            ->whereNull('consumed_at')->where('expires_at', '>', now())->first() : null;
        if (!$pending) {
            throw new HttpException(401, __('dingtalk.errors.expired'));
        }
        $this->assertChallengePolicy($pending, $policy);
        $company = app(CompanyService::class)->enabled((int)$pending->company_id);
        app(CompanyService::class)->assertVersion($company, (int)$pending->company_version);
        return $pending;
    }
}
