<?php

namespace App\Http\Controllers;

use App\Portal\Services\PortalDomainService;
use App\Services\DingTalk\AuditService;
use App\Services\DingTalk\LoginService;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DingTalkLoginController extends Controller
{
    public function callback(Request $request, LoginService $login)
    {
        if (Admin::guard()->check()) {
            return redirect(app(PortalDomainService::class)->mainAdminUrl())->header('Cache-Control', 'no-store');
        }

        try {
            $state = $request->query('state');
            $code = $request->query('authCode', $request->query('code'));
            if (!is_string($state) || strlen($state) !== 64 || !is_string($code) || $code === '' || strlen($code) > 1024) {
                throw new HttpException(401, __('dingtalk.errors.expired'));
            }
            $choices = $login->acceptCallback($request, $state, $code);
            return $choices->count() === 1
                ? redirect()->to($login->complete($request, (int) $choices->first()->account_id))->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store')
                : redirect()->route('dingtalk.accounts')->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
        } catch (HttpException $exception) {
            app(AuditService::class)->record('login.denied', null, null, ['reason' => 'callback_failed', 'status' => $exception->getStatusCode()]);
            return redirect(app(PortalDomainService::class)->mainLoginUrl())->withErrors(['dingtalk' => $exception->getMessage()])
                ->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
        }
    }

    public function accounts(Request $request, LoginService $login)
    {
        if (Admin::guard()->check()) {
            return redirect(app(PortalDomainService::class)->mainAdminUrl())->header('Cache-Control', 'no-store');
        }

        try {
            $choices = $login->pendingChoices($request);
            if ($choices->isEmpty()) {
                throw new HttpException(403, __('dingtalk.errors.no_account'));
            }
            return response()->view('admin.auth.dingtalk-accounts', compact('choices'))->header('Cache-Control', 'no-store');
        } catch (HttpException $exception) {
            return redirect(app(PortalDomainService::class)->mainLoginUrl())->withErrors(['dingtalk' => $exception->getMessage()]);
        }
    }

    public function select(Request $request, LoginService $login)
    {
        if (Admin::guard()->check()) {
            return redirect(app(PortalDomainService::class)->mainAdminUrl())->header('Cache-Control', 'no-store');
        }

        try {
            $account = $request->input('account_id');
            if (!is_scalar($account) || !ctype_digit((string) $account) || (int) $account <= 0) {
                throw new HttpException(403, __('dingtalk.errors.no_account'));
            }
            return redirect()->to($login->complete($request, (int) $account));
        } catch (HttpException $exception) {
            app(AuditService::class)->record('login.denied', null, null, ['reason' => 'selection_failed', 'status' => $exception->getStatusCode()]);
            return redirect(app(PortalDomainService::class)->mainLoginUrl())->withErrors(['dingtalk' => $exception->getMessage()]);
        }
    }
}
