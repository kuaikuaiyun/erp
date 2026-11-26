<?php

namespace App\Admin\Controllers;

use App\Admin\Form\Field\ProfileAvatar;
use App\Portal\Services\PortalReturnUrlService;
use App\Services\LoginCaptchaService;
use App\Services\DingTalk\LoginService;
use App\Services\DingTalk\LoginEntryService;
use App\Services\Authentication\ModeService;
use App\Portal\Services\PortalDomainService;
use Encore\Admin\Controllers\AuthController as BaseAuthController;
use Encore\Admin\Form;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthController extends BaseAuthController
{
    protected $loginView = 'admin.auth.login';

    public function __construct(private readonly LoginCaptchaService $captcha) {}

    protected function settingForm()
    {
        $class = config('admin.database.users_model');
        $form = new Form(new $class());
        $form->display('username', trans('admin.username'));
        $form->text('name', trans('admin.name'))->rules('required');
        $form->pushField(new ProfileAvatar('avatar', [trans('admin.avatar')]));
        $form->password('password', trans('admin.password'))->rules('confirmed|required');
        $form->password('password_confirmation', trans('admin.password_confirmation'))->rules('required')->default(fn (Form $form) => $form->model()->password);
        $form->setAction(admin_url('auth/setting'));
        $form->ignore(['password_confirmation']);
        $form->saving(function (Form $form): void { if ($form->password && $form->model()->password !== $form->password) $form->password = Hash::make($form->password); });
        $form->saved(function () { admin_toastr(trans('admin.update_succeeded')); return redirect(admin_url('auth/setting')); });
        return $form;
    }

    public function getLogin(): \Symfony\Component\HttpFoundation\Response
    {
        $request = request();
        if ($this->guard()->check()) {
            return redirect()->to(app(PortalReturnUrlService::class)->pull($request) ?? app(PortalDomainService::class)->mainAdminUrl());
        }

        $modes = app(ModeService::class);
        $data = app(LoginEntryService::class)->viewData($request);
        try {
            $password = $modes->allows($request, 'password');
            $dingtalk = $modes->allows($request, 'dingtalk');

            return response()->view($this->loginView, $data + [
                'passwordEnabled' => $password,
                'dingtalkEnabled' => $dingtalk,
                'captcha' => $password ? $this->captcha->publicConfiguration() : null,
            ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        } catch (HttpException $exception) {
            return response()->view($this->loginView, $data + ['loginError' => $exception->getMessage()], $exception->getStatusCode())
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }
    }

    public function startDingTalk(Request $request, LoginService $login): JsonResponse
    {
        abort_if($this->guard()->check(), 403, __('dingtalk.errors.revoked'));

        return response()->json($login->start($request))->header('Cache-Control', 'no-store');
    }

    public function getLogout(Request $request)
    {
        $url = app(\App\Services\DingTalk\LoginEntryService::class)->url($request);
        $this->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->to($url);
    }

    public function postLogin(Request $request)
    {
        try {
            return redirect()->to(app(\App\Services\Authentication\PasswordLoginService::class)->login($request));
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            $username = $request->input('username');

            return redirect()->to(app(LoginEntryService::class)->url($request))
                ->withErrors($exception->errors())
                ->withInput(is_string($username) ? ['username' => $username] : []);
        }
    }

    public function tencentCaptchaOptions(): JsonResponse
    {
        app(ModeService::class)->requireMethod(request(), 'password');
        $options = $this->captcha->tencentCaptchaClientOptions(); abort_if($options === null, 404);
        return response()->json($options)->header('Cache-Control', 'no-store, private, max-age=0')->header('Pragma', 'no-cache');
    }

}
