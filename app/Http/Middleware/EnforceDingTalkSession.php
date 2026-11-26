<?php

namespace App\Http\Middleware;

use App\Portal\Services\PortalDomainService;
use App\Portal\Services\PortalReturnUrlService;
use App\Services\DingTalk\AuditService;
use App\Services\DingTalk\LoginService;
use Closure;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnforceDingTalkSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Admin::guard();
        if ($guard->guest()) {
            return $next($request);
        }
        if ($request->routeIs(config('admin.route.prefix').'.logout')) {
            // Signing out must remain possible during an API outage or after revocation.
            if (\Illuminate\Support\Facades\Schema::hasTable('dingtalk_audits')) {
                $authentication = $request->session()->get(\App\Services\Authentication\ModeService::KEY, []);
                if ((int)($authentication['account_id'] ?? 0) === (int)$guard->id()) {
                    $request->attributes->set('erp.auth_mode', $authentication['mode'] ?? null);
                    $request->attributes->set('dingtalk.account_id', (int)$guard->id());
                }
                $identityId = (int)$request->session()->get(LoginService::PROOF_KEY.'.account_id') === (int)$guard->id()
                    ? $request->session()->get(LoginService::PROOF_KEY.'.identity_id') : null;
                $identity = $identityId ? \App\Models\DingTalkIdentity::query()->find($identityId) : null;
                app(AuditService::class)->record('login.signed_out', $identity, (int) $guard->id());
            }
            return $next($request);
        }
        try {
            $mode = app(\App\Services\Authentication\ModeService::class)->validate($request, $guard->user());
            $identity = $mode === 'dingtalk' ? app(LoginService::class)->validSession($request, $guard->user()) : null;
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 503) {
                // Retain the session, but stop access until authoritative revalidation succeeds.
                return $request->expectsJson()
                    ? response()->json(['message' => $exception->getMessage()], 503)->header('Cache-Control', 'no-store')
                    : response()->view('admin.auth.login', array_merge(app(\App\Services\DingTalk\LoginEntryService::class)->viewData($request), ['loginError' => $exception->getMessage(), 'paused' => true]), 503)
                        ->header('Cache-Control', 'no-store');
            }
            if (method_exists($guard, 'logoutCurrentDevice')) {
                $guard->logoutCurrentDevice();
            } else {
                $guard->logout();
            }
            $loginUrl = app(\App\Services\DingTalk\LoginEntryService::class)->url($request);
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $domains = app(PortalDomainService::class);
            if ($portal = $domains->portalForRequest($request)) {
                app(PortalReturnUrlService::class)->remember($request, $portal);
            }
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 401)->header('Cache-Control', 'no-store');
            }
            return redirect($loginUrl)->withErrors(['dingtalk' => $exception->getMessage()]);
        }
        if ($identity) $request->attributes->set('dingtalk.identity', $identity);
        $request->attributes->set('dingtalk.account_id', (int) $guard->id());
        $audit = !$request->isMethodSafe() ? app(AuditService::class)->record('request.write', $identity, (int) $guard->id(), [
            'method' => $request->method(), 'route' => $request->route()?->getName(), 'path' => $request->path(), 'status' => 'started',
        ]) : null;
        try {
            $response = $next($request);
            if ($audit) {
                $audit->update(['metadata' => array_merge($audit->metadata, ['status' => $response->getStatusCode()])]);
            }
            $response->headers->set('Cache-Control', 'no-store, private');
            return $response;
        } catch (\Throwable $exception) {
            if ($audit) {
                $audit->update(['metadata' => array_merge($audit->metadata, ['status' => 'exception'])]);
            }
            throw $exception;
        }
    }
}
