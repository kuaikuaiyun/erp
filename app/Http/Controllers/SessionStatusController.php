<?php

namespace App\Http\Controllers;

use App\Services\Authentication\ModeService;
use App\Services\DingTalk\LoginEntryService;
use App\Services\DingTalk\LoginService;
use App\Services\SystemSettingService;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SessionStatusController extends Controller
{
    public function __invoke(Request $request, SessionManager $sessions, ModeService $modes): JsonResponse
    {
        $session = clone $sessions->driver();
        $sessionId = $request->cookies->get($session->getName());
        if (!$session->isValidId($sessionId)) {
            return $this->expired($request);
        }

        // Read the stored session without StartSession, which would refresh its idle lifetime.
        $session->flush();
        $session->setId($sessionId);
        $session->setRequestOnHandler($request);
        $session->start();
        $request->setLaravelSession($session);

        $guard = Admin::guard();
        $id = $session->get($guard->getName());
        $account = $id === null ? null : $guard->getProvider()->retrieveById($id);
        if (!$account) {
            return $this->expired($request);
        }

        try {
            if ($modes->validate($request, $account) === 'dingtalk') {
                app(LoginService::class)->validSession($request, $account);
            }
        } catch (HttpException $exception) {
            if (!in_array($exception->getStatusCode(), [401, 403], true)) {
                throw $exception;
            }

            return $this->expired($request);
        }

        $issuedAt = (int) $session->get(ModeService::KEY.'.issued_at');
        $seconds = app(SystemSettingService::class)->loginExpirationSeconds();

        return response()->json(['state' => 'active', 'remaining_seconds' => max(0, $issuedAt + $seconds - now()->timestamp)])
            ->header('Cache-Control', 'no-store');
    }

    private function expired(Request $request): JsonResponse
    {
        return response()->json([
            'state' => 'expired',
            'title' => __('enterprise_login.session_expired_title'),
            'message' => __('enterprise_login.session_expired'),
            'action' => __('enterprise_login.editor.sign_in_again'),
            'login_url' => app(LoginEntryService::class)->url($request),
        ], 401)->header('Cache-Control', 'no-store');
    }
}
