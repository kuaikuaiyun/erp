<?php

namespace App\Admin\Controllers;

use App\Admin\Form\Field\ProfileAvatar;
use App\Portal\Services\PortalReturnUrlService;
use App\Services\LoginCaptchaService;
use App\Services\DingTalk\LoginService;
use App\Services\SystemSettingService;
use Encore\Admin\Controllers\AuthController as BaseAuthController;
use Encore\Admin\Form;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthController extends BaseAuthController
{
    protected $loginView = 'admin.auth.login';

    public function __construct(private readonly LoginCaptchaService $captcha, private readonly SystemSettingService $settings) {}

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

    public function getLogin(?string $slug = null)
    {
        if ($this->guard()->check()) {
            if ($portalReturnUrl = app(PortalReturnUrlService::class)->pull(request())) return redirect()->to($portalReturnUrl);
            return redirect($this->redirectPath());
        }
        $entries = app(\App\Services\DingTalk\LoginEntryService::class);
        $data = $entries->viewData(request(), null, false);
        // The public endpoint never uses session hints to enumerate or select companies.
        $data = array_merge($data, ['brandName' => config('admin.name'), 'brandLogo' => null,
            'pageTitle' => __('dingtalk.login.title').' · '.config('admin.name'), 'welcome' => __('dingtalk.entry.public_hint'),
            'entryUrl' => null, 'flow' => null, 'loginError' => null, 'entryClosed' => true]);
        try {
            if (app(\App\Services\Authentication\ModeService::class)->current()->mode === 'password') {
                if ($slug !== null) return redirect()->to($entries->url(request()));
                return response()->view('admin.auth.password', ['captcha' => $this->captcha->publicConfiguration()])->header('Cache-Control', 'no-store');
            }
            if ($slug !== null) {
                $company = app(\App\Services\DingTalk\CompanyService::class)->forLoginSlug($slug);
                $entries->remember(request(), $company);
                $data = array_merge($data, $entries->viewData(request(), $company), ['entryClosed' => false]);
                $data['flow'] = app(LoginService::class)->start(request(), (int) $company->id);
            }
            return response()->view($this->loginView, $data)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        } catch (HttpException $exception) {
            $data['loginError'] = $exception->getMessage();
            return response()->view($this->loginView, $data, $exception->getStatusCode())
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        }
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
        return redirect()->to(app(\App\Services\Authentication\PasswordLoginService::class)->login($request));
    }

    public function tencentCaptchaOptions(): JsonResponse
    {
        $options = $this->captcha->tencentCaptchaClientOptions(); abort_if($options === null, 404);
        return response()->json($options)->header('Cache-Control', 'no-store, private, max-age=0')->header('Pragma', 'no-cache');
    }

}
