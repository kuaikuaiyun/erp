<?php

namespace App\Admin\Controllers\ConfigurationCenter;

use App\Http\Controllers\Controller;
use App\Services\SystemSettingService;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CaptchaController extends Controller
{
    public function __construct(private readonly SystemSettingService $settings)
    {
    }

    /**
     * Display the human-verification configuration page.
     */
    public function index(Content $content): Content
    {
        return $content
            ->title(__('configuration.menu.configuration_center'))
            ->description(__('configuration.menu.login_captcha'))
            ->body(view('admin.configuration.captcha', [
                'settings' => $this->settings->loginCaptcha(),
                'changes' => $this->settings->recentLoginCaptchaChanges(),
                'saveUrl' => admin_url('configuration/captcha'),
                'revealUrlTemplate' => admin_url('configuration/captcha/reveal/__secret__'),
            ]));
    }

    /**
     * Save login human-verification settings.
     */
    public function save(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'enabled' => ['nullable', 'boolean'],
            'provider' => ['required', Rule::in(['turnstile', 'recaptcha_v2', 'tencent_captcha'])],
            'turnstile_site_key' => ['nullable', 'string', 'max:255'],
            'turnstile_secret_key' => ['nullable', 'string', 'max:2048'],
            'clear_turnstile_secret' => ['nullable', 'boolean'],
            'recaptcha_v2_site_key' => ['nullable', 'string', 'max:255'],
            'recaptcha_v2_secret_key' => ['nullable', 'string', 'max:2048'],
            'clear_recaptcha_v2_secret' => ['nullable', 'boolean'],
            'tencent_captcha_app_id' => ['nullable', 'string', 'regex:/^\d{1,20}$/', 'max:64'],
            'tencent_captcha_app_secret_key' => ['nullable', 'string', 'max:2048'],
            'clear_tencent_captcha_app_secret_key' => ['nullable', 'boolean'],
            'tencent_captcha_secret_id' => ['nullable', 'string', 'max:2048'],
            'clear_tencent_captcha_secret_id' => ['nullable', 'boolean'],
            'tencent_captcha_secret_key' => ['nullable', 'string', 'max:2048'],
            'clear_tencent_captcha_secret_key' => ['nullable', 'boolean'],
            'max_attempts' => ['required', 'integer', 'between:1,20'],
            'decay_seconds' => ['required', 'integer', 'between:30,3600'],
        ], [
            'provider.required' => __('configuration.captcha.validation.provider_required'),
            'provider.in' => __('configuration.captcha.validation.provider_invalid'),
            'tencent_captcha_app_id.regex' => __('configuration.captcha.validation.tencent_app_id_numeric'),
            'max_attempts.between' => __('configuration.captcha.validation.max_attempts_between'),
            'decay_seconds.between' => __('configuration.captcha.validation.decay_seconds_between'),
        ]);

        if ($validator->fails()) {
            return $this->validationFailure($request, $validator->errors()->toArray());
        }

        $payload = $validator->validated();
        $payload['enabled'] = $request->boolean('enabled');

        foreach ([
            'clear_turnstile_secret',
            'clear_recaptcha_v2_secret',
            'clear_tencent_captcha_app_secret_key',
            'clear_tencent_captcha_secret_id',
            'clear_tencent_captcha_secret_key',
        ] as $field) {
            $payload[$field] = $request->boolean($field);
        }

        if ($payload['enabled']) {
            $errors = $this->selectedProviderConfigurationErrors($payload);

            if ($errors !== []) {
                return $this->validationFailure($request, $errors);
            }
        }

        /** @var \Encore\Admin\Auth\Database\Administrator $administrator */
        $administrator = Admin::user();
        $this->settings->saveLoginCaptcha(
            $payload,
            $administrator,
            $request->ip(),
            $request->userAgent(),
        );

        admin_success(
            __('configuration.captcha.notifications.saved_title'),
            __('configuration.captcha.notifications.saved_message'),
        );

        return redirect(admin_url('configuration/captcha'));
    }

    /**
     * Reveal one encrypted secret on demand. This is deliberately a POST-only,
     * no-store response and the actual value is never written to operation logs.
     */
    public function revealSecret(Request $request, string $secret): JsonResponse
    {
        $value = $this->settings->loginCaptchaSecret($secret);

        if ($value === '') {
            abort(404);
        }

        /** @var \Encore\Admin\Auth\Database\Administrator $administrator */
        $administrator = Admin::user();
        $this->settings->recordLoginCaptchaSecretReveal(
            $secret,
            $administrator,
            $request->ip(),
            $request->userAgent(),
        );

        return response()
            ->json(['value' => $value])
            ->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    private function selectedProviderConfigurationErrors(array $payload): array
    {
        $provider = $payload['provider'];

        if ($provider === 'tencent_captcha') {
            return $this->tencentConfigurationErrors($payload);
        }

        $siteKeyField = $provider.'_site_key';
        $secretField = $provider.'_secret_key';
        $clearField = 'clear_'.$provider.'_secret';
        $storedSecretKey = 'auth.login_captcha.'.$provider.'.secret_key';
        $errors = [];

        if (blank($payload[$siteKeyField] ?? null)) {
            $errors[$siteKeyField] = __('configuration.captcha.validation.selected_site_key_required');
        }

        if (
            blank($payload[$secretField] ?? null)
            && (!$this->settings->hasStoredValue($storedSecretKey) || !empty($payload[$clearField]))
        ) {
            $errors[$secretField] = __('configuration.captcha.validation.selected_secret_required');
        }

        return $errors;
    }

    private function tencentConfigurationErrors(array $payload): array
    {
        $fields = [
            'tencent_captcha_app_id' => 'auth.login_captcha.tencent_captcha.app_id',
            'tencent_captcha_app_secret_key' => 'auth.login_captcha.tencent_captcha.app_secret_key',
            'tencent_captcha_secret_id' => 'auth.login_captcha.tencent_captcha.secret_id',
            'tencent_captcha_secret_key' => 'auth.login_captcha.tencent_captcha.secret_key',
        ];
        $errors = [];

        foreach ($fields as $field => $settingKey) {
            if ($field === 'tencent_captcha_app_id') {
                if (blank($payload[$field] ?? null)) {
                    $errors[$field] = __('configuration.captcha.validation.tencent_app_id_required');
                }

                continue;
            }

            $clearField = 'clear_'.$field;

            if (
                blank($payload[$field] ?? null)
                && (!$this->settings->hasStoredValue($settingKey) || !empty($payload[$clearField]))
            ) {
                $errors[$field] = __('configuration.captcha.validation.tencent_secret_required');
            }
        }

        return $errors;
    }

    private function validationFailure(Request $request, array $errors)
    {
        return back()
            ->withErrors($errors)
            ->withInput($request->except([
                'turnstile_secret_key',
                'recaptcha_v2_secret_key',
                'tencent_captcha_app_secret_key',
                'tencent_captcha_secret_id',
                'tencent_captcha_secret_key',
            ]));
    }
}


