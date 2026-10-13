<?php

namespace App\Admin\Controllers;

use App\Admin\Form\Field\ProfileAvatar;
use App\Http\Middleware\EnforceAdminLoginExpiration;
use App\Models\AdminUser;
use App\Portal\Services\PortalReturnUrlService;
use App\Services\LoginCaptchaService;
use App\Services\SystemSettingService;
use Encore\Admin\Controllers\AuthController as BaseAuthController;
use Encore\Admin\Form;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    public function getLogin()
    {
        if ($this->guard()->check()) {
            if ($portalReturnUrl = app(PortalReturnUrlService::class)->pull(request())) return redirect()->to($portalReturnUrl);
            return redirect($this->redirectPath());
        }
        return view($this->loginView, ['captcha' => $this->captcha->publicConfiguration()]);
    }

    public function postLogin(Request $request)
    {
        $this->ensureIsNotRateLimited($request); $this->loginValidator($request->all())->validate();
        if (!$this->captcha->verify($request)) { $this->hitRateLimiter($request); throw ValidationException::withMessages(['captcha' => __('configuration.login.captcha_failed')]); }
        $guard = $this->guard();
        if (method_exists($guard, 'setRememberDuration')) $guard->setRememberDuration($this->settings->loginExpirationMinutes());
        if ($guard->attempt($request->only([$this->username(), 'password']), $request->boolean('remember'))) {
            $user = $guard->user();
            if ($user && isset($user->status) && (int) $user->status !== AdminUser::STATUS_ACTIVE) { $guard->logout(); $this->hitRateLimiter($request); return back()->withInput()->withErrors([$this->username() => trans('admin.login_banned')]); }
            $request->session()->put(EnforceAdminLoginExpiration::LOGIN_ISSUED_AT_KEY, now()->timestamp); RateLimiter::clear($this->throttleKey($request)); return $this->sendLoginResponse($request);
        }
        $this->hitRateLimiter($request); return back()->withInput()->withErrors([$this->username() => $this->getFailedLoginMessage()]);
    }

    public function tencentCaptchaOptions(): JsonResponse
    {
        $options = $this->captcha->tencentCaptchaClientOptions(); abort_if($options === null, 404);
        return response()->json($options)->header('Cache-Control', 'no-store, private, max-age=0')->header('Pragma', 'no-cache');
    }

    protected function sendLoginResponse(Request $request)
    {
        admin_toastr(trans('admin.login_successful')); $request->session()->regenerate();
        if ($portalReturnUrl = app(PortalReturnUrlService::class)->pull($request)) return redirect()->to($portalReturnUrl);
        return redirect()->intended($this->redirectPath());
    }
    protected function loginValidator(array $data) { return Validator::make($data, [$this->username() => ['required','string','max:190'], 'password' => ['required','string','max:255']], [$this->username().'.required' => __('configuration.login.username_required'), 'password.required' => __('configuration.login.password_required')]); }
    private function ensureIsNotRateLimited(Request $request): void { $key=$this->throttleKey($request); if (RateLimiter::tooManyAttempts($key,$this->captcha->maxAttempts())) throw ValidationException::withMessages([$this->username()=>__('configuration.login.rate_limited',['seconds'=>RateLimiter::availableIn($key)])]); }
    private function hitRateLimiter(Request $request): void { RateLimiter::hit($this->throttleKey($request), $this->captcha->decaySeconds()); }
    private function throttleKey(Request $request): string { return 'admin-login:'.sha1(Str::lower((string)$request->input($this->username())).'|'.$request->ip()); }
}
