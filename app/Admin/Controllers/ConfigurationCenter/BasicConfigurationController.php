<?php

namespace App\Admin\Controllers\ConfigurationCenter;

use App\Http\Controllers\Controller;
use App\Services\SystemSettingService;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BasicConfigurationController extends Controller
{
    public function __construct(private readonly SystemSettingService $settings)
    {
    }

    public function index(Content $content): Content
    {
        return $content
            ->title(__('configuration.menu.configuration_center'))
            ->description(__('configuration.menu.basic'))
            ->body(view('admin.configuration.basic', [
                'settings' => $this->settings->basicConfiguration(),
                'changes' => $this->settings->recentBasicConfigurationChanges(),
                'labels' => $this->labels(),
                'expirationOptions' => $this->expirationOptions(),
                'saveUrl' => admin_url('configuration/basic'),
            ]));
    }

    public function save(Request $request)
    {
        $payload = $request->validate([
            'login_expiration_seconds' => ['required', 'integer', Rule::in(SystemSettingService::LOGIN_EXPIRATION_SECONDS)],
            'maintenance_enabled' => ['nullable', 'boolean'],
            'maintenance_message' => ['nullable', 'string', 'max:500'],
            'footer_copyright_text' => ['nullable', 'string', 'max:200'],
            'footer_contact_information' => ['nullable', 'string', 'max:500'],
        ], __('configuration.basic.validation'));

        $payload['maintenance_enabled'] = $request->boolean('maintenance_enabled');
        /** @var \Encore\Admin\Auth\Database\Administrator $administrator */
        $administrator = Admin::user();
        $this->settings->saveBasicConfiguration($payload, $administrator, $request->ip(), $request->userAgent());

        admin_success(
            __('configuration.basic.notifications.saved_title'),
            __('configuration.basic.notifications.saved_message'),
        );

        return redirect(admin_url('configuration/basic'));
    }

    private function labels(): array
    {
        return [
            'auth.login_expiration_seconds' => __('configuration.basic.fields.login_expiration_seconds'),
            'system.maintenance.enabled' => __('configuration.basic.fields.maintenance_enabled'),
            'system.maintenance.message' => __('configuration.basic.fields.maintenance_message'),
            'ui.footer.copyright_text' => __('configuration.basic.fields.footer_copyright_text'),
            'ui.footer.contact_information' => __('configuration.basic.fields.footer_contact_information'),
        ];
    }

    private function expirationOptions(): array
    {
        return [
            15 => __('configuration.basic.expiration_options.test_15_seconds'),
            86400 => __('configuration.basic.expiration_options.days_1'),
            259200 => __('configuration.basic.expiration_options.days_3'),
            604800 => __('configuration.basic.expiration_options.days_7'),
        ];
    }
}


