<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\SystemSettingChangeLog;
use Encore\Admin\Auth\Database\Administrator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class SystemSettingService
{
    public const WATERMARK_KEYS = [
        'admin.watermark.enabled',
        'admin.watermark.content_mode',
        'admin.watermark.custom_content',
        'admin.watermark.opacity',
        'admin.watermark.rotation',
        'admin.watermark.spacing_x',
        'admin.watermark.spacing_y',
        'admin.watermark.font_size',
        'admin.watermark.color',
    ];

    public const LOGIN_CAPTCHA_KEYS = [
        'auth.login_captcha.enabled',
        'auth.login_captcha.provider',
        'auth.login_captcha.turnstile.site_key',
        'auth.login_captcha.turnstile.secret_key',
        'auth.login_captcha.recaptcha_v2.site_key',
        'auth.login_captcha.recaptcha_v2.secret_key',
        'auth.login_captcha.tencent_captcha.app_id',
        'auth.login_captcha.tencent_captcha.app_secret_key',
        'auth.login_captcha.tencent_captcha.secret_id',
        'auth.login_captcha.tencent_captcha.secret_key',
        'auth.login_captcha.max_attempts',
        'auth.login_captcha.decay_seconds',
    ];

    public const BASIC_CONFIGURATION_KEYS = [
        'auth.login_expiration_seconds',
        'system.maintenance.enabled',
        'system.maintenance.message',
        'ui.footer.copyright_text',
        'ui.footer.contact_information',
    ];

    public const LOGIN_EXPIRATION_SECONDS = [15, 86400, 259200, 604800];

    private const CACHE_KEY = 'erp:system-settings:all';

    /**
     * Return safe configuration for the configuration page and login service.
     */
    public function loginCaptcha(): array
    {
        $provider = (string) $this->get('auth.login_captcha.provider', 'turnstile');

        if (!in_array($provider, ['turnstile', 'recaptcha_v2', 'tencent_captcha'], true)) {
            $provider = 'turnstile';
        }

        return [
            'enabled' => $this->boolean('auth.login_captcha.enabled', false),
            'provider' => $provider,
            'turnstile' => [
                'site_key' => $this->string('auth.login_captcha.turnstile.site_key', 255),
                'has_secret' => $this->hasStoredValue('auth.login_captcha.turnstile.secret_key'),
            ],
            'recaptcha_v2' => [
                'site_key' => $this->string('auth.login_captcha.recaptcha_v2.site_key', 255),
                'has_secret' => $this->hasStoredValue('auth.login_captcha.recaptcha_v2.secret_key'),
            ],
            'tencent_captcha' => [
                'app_id' => $this->string('auth.login_captcha.tencent_captcha.app_id', 64),
                'has_app_secret_key' => $this->hasStoredValue('auth.login_captcha.tencent_captcha.app_secret_key'),
                'has_secret_id' => $this->hasStoredValue('auth.login_captcha.tencent_captcha.secret_id'),
                'has_secret_key' => $this->hasStoredValue('auth.login_captcha.tencent_captcha.secret_key'),
            ],
            'max_attempts' => $this->integer('auth.login_captcha.max_attempts', 5, 1, 20),
            'decay_seconds' => $this->integer('auth.login_captcha.decay_seconds', 60, 30, 3600),
        ];
    }

    /**
     * Return the safe configuration used to render administrator watermarks.
     */
    public function watermark(): array
    {
        $mode = (string) $this->get('admin.watermark.content_mode', 'template_2');

        if (!in_array($mode, ['custom', 'template_1', 'template_2'], true)) {
            $mode = 'template_2';
        }

        $color = strtoupper((string) $this->get('admin.watermark.color', '#64748B'));

        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            $color = '#64748B';
        }

        return [
            'enabled' => $this->boolean('admin.watermark.enabled', false),
            'content_mode' => $mode,
            'custom_content' => $this->string('admin.watermark.custom_content', 120),
            'opacity' => $this->integer('admin.watermark.opacity', 18, 5, 70),
            'rotation' => $this->integer('admin.watermark.rotation', -28, -90, 90),
            'spacing_x' => $this->integer('admin.watermark.spacing_x', 240, 80, 640),
            'spacing_y' => $this->integer('admin.watermark.spacing_y', 160, 80, 640),
            'font_size' => $this->integer('admin.watermark.font_size', 15, 10, 36),
            'color' => $color,
        ];
    }

    public function saveWatermark(
        array $attributes,
        Administrator $administrator,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $settings = [
            'admin.watermark.enabled' => !empty($attributes['enabled']) ? '1' : '0',
            'admin.watermark.content_mode' => (string) $attributes['content_mode'],
            'admin.watermark.custom_content' => trim((string) ($attributes['custom_content'] ?? '')),
            'admin.watermark.opacity' => (string) $attributes['opacity'],
            'admin.watermark.rotation' => (string) $attributes['rotation'],
            'admin.watermark.spacing_x' => (string) $attributes['spacing_x'],
            'admin.watermark.spacing_y' => (string) $attributes['spacing_y'],
            'admin.watermark.font_size' => (string) $attributes['font_size'],
            'admin.watermark.color' => strtoupper((string) $attributes['color']),
        ];

        foreach ($settings as $key => $value) {
            $this->set($key, $value, false, $administrator, $ipAddress, $userAgent);
        }
    }

    /**
     * Return all values displayed by the basic configuration page.
     */
    public function basicConfiguration(): array
    {
        return [
            'login_expiration_seconds' => $this->loginExpirationSeconds(),
            'maintenance_enabled' => $this->boolean('system.maintenance.enabled', false),
            'maintenance_message' => $this->string('system.maintenance.message', 500),
            'footer_copyright_text' => $this->string('ui.footer.copyright_text', 200),
            'footer_contact_information' => $this->string('ui.footer.contact_information', 500),
        ];
    }

    public function maintenance(): array
    {
        return [
            'enabled' => $this->boolean('system.maintenance.enabled', false),
            'message' => $this->string('system.maintenance.message', 500),
        ];
    }

    public function footer(): array
    {
        return [
            'copyright_text' => $this->string('ui.footer.copyright_text', 200),
            'contact_information' => $this->string('ui.footer.contact_information', 500),
        ];
    }

    public function loginExpirationSeconds(): int
    {
        $seconds = (int) $this->get('auth.login_expiration_seconds', 0);

        return in_array($seconds, self::LOGIN_EXPIRATION_SECONDS, true) ? $seconds : 86400;
    }

    public function loginExpirationMinutes(): int
    {
        return max(1, (int) ceil($this->loginExpirationSeconds() / 60));
    }

    public function saveBasicConfiguration(
        array $attributes,
        Administrator $administrator,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $settings = [
            'auth.login_expiration_seconds' => (string) $attributes['login_expiration_seconds'],
            'system.maintenance.enabled' => !empty($attributes['maintenance_enabled']) ? '1' : '0',
            'system.maintenance.message' => trim((string) ($attributes['maintenance_message'] ?? '')),
            'ui.footer.copyright_text' => trim((string) ($attributes['footer_copyright_text'] ?? '')),
            'ui.footer.contact_information' => trim((string) ($attributes['footer_contact_information'] ?? '')),
        ];

        foreach ($settings as $key => $value) {
            $this->set($key, $value, false, $administrator, $ipAddress, $userAgent);
        }
    }

    /**
     * Persist the selected provider and its values. Secret values are only
     * encrypted at rest and are never copied to the change-log table.
     */
    public function saveLoginCaptcha(
        array $attributes,
        Administrator $administrator,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $settings = [
            'auth.login_captcha.enabled' => !empty($attributes['enabled']) ? '1' : '0',
            'auth.login_captcha.provider' => (string) $attributes['provider'],
            'auth.login_captcha.turnstile.site_key' => trim((string) ($attributes['turnstile_site_key'] ?? '')),
            'auth.login_captcha.recaptcha_v2.site_key' => trim((string) ($attributes['recaptcha_v2_site_key'] ?? '')),
            'auth.login_captcha.tencent_captcha.app_id' => trim((string) ($attributes['tencent_captcha_app_id'] ?? '')),
            'auth.login_captcha.max_attempts' => (string) $attributes['max_attempts'],
            'auth.login_captcha.decay_seconds' => (string) $attributes['decay_seconds'],
        ];

        foreach ($settings as $key => $value) {
            $this->set($key, $value, false, $administrator, $ipAddress, $userAgent);
        }

        foreach (['turnstile', 'recaptcha_v2'] as $provider) {
            $input = $provider.'_secret_key';
            $key = 'auth.login_captcha.'.$provider.'.secret_key';

            if (!empty($attributes['clear_'.$provider.'_secret'])) {
                $this->set($key, '', true, $administrator, $ipAddress, $userAgent);
            } elseif (filled($attributes[$input] ?? null)) {
                $this->set($key, trim((string) $attributes[$input]), true, $administrator, $ipAddress, $userAgent);
            }
        }

        foreach ([
            'tencent_captcha_app_secret_key' => 'auth.login_captcha.tencent_captcha.app_secret_key',
            'tencent_captcha_secret_id' => 'auth.login_captcha.tencent_captcha.secret_id',
            'tencent_captcha_secret_key' => 'auth.login_captcha.tencent_captcha.secret_key',
        ] as $input => $key) {
            if (!empty($attributes['clear_'.$input])) {
                $this->set($key, '', true, $administrator, $ipAddress, $userAgent);
            } elseif (filled($attributes[$input] ?? null)) {
                $this->set($key, trim((string) $attributes[$input]), true, $administrator, $ipAddress, $userAgent);
            }
        }
    }

    /**
     * Retrieve one selected secret for server-side verification or an explicit
     * authorized reveal action. Arbitrary setting keys cannot be retrieved.
     */
    public function loginCaptchaSecret(string $identifier): string
    {
        $key = match ($identifier) {
            'turnstile' => 'auth.login_captcha.turnstile.secret_key',
            'recaptcha_v2' => 'auth.login_captcha.recaptcha_v2.secret_key',
            'tencent_captcha_app_secret_key' => 'auth.login_captcha.tencent_captcha.app_secret_key',
            'tencent_captcha_secret_id' => 'auth.login_captcha.tencent_captcha.secret_id',
            'tencent_captcha_secret_key' => 'auth.login_captcha.tencent_captcha.secret_key',
            default => null,
        };

        return $key === null ? '' : (string) $this->get($key, '');
    }

    /**
     * Record a secret-view operation without storing the secret itself.
     */
    public function recordLoginCaptchaSecretReveal(
        string $identifier,
        Administrator $administrator,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $key = $this->loginCaptchaKeyForSecret($identifier);

        if ($key === null) {
            return;
        }

        SystemSettingChangeLog::query()->create([
            'key' => $key,
            'action' => 'revealed',
            'old_value' => __('configuration.status.configured'),
            'new_value' => __('configuration.status.configured'),
            'is_encrypted' => true,
            'admin_user_id' => $administrator->id,
            'admin_username' => $administrator->username,
            'ip_address' => $ipAddress,
            'user_agent' => $this->limitUserAgent($userAgent),
        ]);
    }

    /**
     * Return a compact, non-sensitive history for the configuration page.
     */
    public function recentLoginCaptchaChanges(int $limit = 12): Collection
    {
        return SystemSettingChangeLog::query()
            ->whereIn('key', self::LOGIN_CAPTCHA_KEYS)
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function recentWatermarkChanges(int $limit = 12): Collection
    {
        return SystemSettingChangeLog::query()
            ->whereIn('key', self::WATERMARK_KEYS)
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function recentBasicConfigurationChanges(int $limit = 12): Collection
    {
        return SystemSettingChangeLog::query()
            ->whereIn('key', self::BASIC_CONFIGURATION_KEYS)
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function watermarkText(Administrator $administrator, ?array $settings = null): string
    {
        $separator = __('configuration.watermark.separator');
        $settings ??= $this->watermark();
        $name = trim((string) $administrator->name);
        $username = trim((string) $administrator->username);

        return match ($settings['content_mode']) {
            'custom' => $settings['custom_content'],
            'template_1' => implode($separator, array_filter([$name, $username], static fn (string $value): bool => $value !== '')),
            default => implode($separator, array_filter([
                $name,
                $username,
                now('Asia/Shanghai')->format('Y-m-d H:i'),
            ], static fn (string $value): bool => $value !== '')),
        };
    }

    public function hasStoredValue(string $key): bool
    {
        $setting = $this->all()->get($key);

        return $setting !== null && filled($setting['value'] ?? null);
    }

    private function set(
        string $key,
        string $value,
        bool $encrypted,
        Administrator $administrator,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $existing = SystemSetting::query()->where('key', $key)->first();
        $oldValue = $existing?->value;
        $oldEncrypted = (bool) ($existing?->is_encrypted ?? false);
        $storedValue = $encrypted && filled($value) ? Crypt::encryptString($value) : ($value === '' ? null : $value);

        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $storedValue,
                'is_encrypted' => $encrypted,
                'updated_by_admin_user_id' => $administrator->id,
            ],
        );

        Cache::forget(self::CACHE_KEY);

        SystemSettingChangeLog::query()->create([
            'key' => $key,
            'action' => 'updated',
            'old_value' => $this->auditValue($oldValue, $oldEncrypted),
            'new_value' => $this->auditValue($storedValue, $encrypted),
            'is_encrypted' => $encrypted,
            'admin_user_id' => $administrator->id,
            'admin_username' => $administrator->username,
            'ip_address' => $ipAddress,
            'user_agent' => $this->limitUserAgent($userAgent),
        ]);
    }

    private function get(string $key, mixed $default = null): mixed
    {
        $setting = $this->all()->get($key);

        if ($setting === null || blank($setting['value'] ?? null)) {
            return $default;
        }

        if (!$setting['is_encrypted']) {
            return $setting['value'];
        }

        try {
            return Crypt::decryptString($setting['value']);
        } catch (\Throwable) {
            return $default;
        }
    }

    private function all(): Collection
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): Collection {
            return SystemSetting::query()
                ->get(['key', 'value', 'is_encrypted'])
                ->mapWithKeys(static fn (SystemSetting $setting): array => [
                    $setting->key => [
                        'value' => $setting->value,
                        'is_encrypted' => $setting->is_encrypted,
                    ],
                ]);
        });
    }

    private function boolean(string $key, bool $default): bool
    {
        return filter_var($this->get($key, $default), FILTER_VALIDATE_BOOL);
    }

    private function integer(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = filter_var($this->get($key, $default), FILTER_VALIDATE_INT);

        return $value === false ? $default : min($maximum, max($minimum, $value));
    }

    private function string(string $key, int $maximum): string
    {
        return mb_substr(trim((string) $this->get($key, '')), 0, $maximum);
    }

    private function auditValue(?string $value, bool $encrypted): string
    {
        if ($encrypted) {
            return filled($value)
                ? __('configuration.status.configured')
                : __('configuration.status.not_configured');
        }

        return mb_substr((string) $value, 0, 500);
    }

    private function loginCaptchaKeyForSecret(string $identifier): ?string
    {
        return match ($identifier) {
            'turnstile' => 'auth.login_captcha.turnstile.secret_key',
            'recaptcha_v2' => 'auth.login_captcha.recaptcha_v2.secret_key',
            'tencent_captcha_app_secret_key' => 'auth.login_captcha.tencent_captcha.app_secret_key',
            'tencent_captcha_secret_id' => 'auth.login_captcha.tencent_captcha.secret_id',
            'tencent_captcha_secret_key' => 'auth.login_captcha.tencent_captcha.secret_key',
            default => null,
        };
    }

    private function limitUserAgent(?string $userAgent): ?string
    {
        return $userAgent === null ? null : mb_substr($userAgent, 0, 500);
    }
}




