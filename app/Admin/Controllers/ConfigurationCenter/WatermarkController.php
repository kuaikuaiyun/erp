<?php

namespace App\Admin\Controllers\ConfigurationCenter;

use App\Http\Controllers\Controller;
use App\Services\SystemSettingService;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WatermarkController extends Controller
{
    public function __construct(private readonly SystemSettingService $settings)
    {
    }

    public function index(Content $content): Content
    {
        $settings = $this->settings->watermark();
        /** @var \Encore\Admin\Auth\Database\Administrator $administrator */
        $administrator = Admin::user();

        return $content
            ->title(__('configuration.menu.configuration_center'))
            ->description(__('configuration.menu.watermark'))
            ->body(view('admin.configuration.watermark', [
                'settings' => $settings,
                'previewText' => $this->settings->watermarkText($administrator, $settings),
                'changes' => $this->settings->recentWatermarkChanges(),
                'labels' => $this->labels(),
                'translations' => [
                    'customPreviewFallback' => __('configuration.watermark.preview.custom_fallback'),
                    'emptyValue' => __('configuration.watermark.changes.empty_value'),
                    'enabled' => __('configuration.status.enabled'),
                    'disabled' => __('configuration.status.disabled'),
                ],
                'saveUrl' => admin_url('configuration/watermark'),
            ]));
    }

    public function save(Request $request)
    {
        $payload = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'content_mode' => ['required', Rule::in(['custom', 'template_1', 'template_2'])],
            'custom_content' => ['nullable', 'string', 'max:120'],
            'opacity' => ['required', 'integer', 'between:5,70'],
            'rotation' => ['required', 'integer', 'between:-90,90'],
            'spacing_x' => ['required', 'integer', 'between:80,640'],
            'spacing_y' => ['required', 'integer', 'between:80,640'],
            'font_size' => ['required', 'integer', 'between:10,36'],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], __('configuration.watermark.validation'));

        if ($payload['content_mode'] === 'custom' && blank($payload['custom_content'] ?? null)) {
            throw ValidationException::withMessages([
                'custom_content' => __('configuration.watermark.validation.custom_content.required_for_custom'),
            ]);
        }

        $payload['enabled'] = $request->boolean('enabled');
        /** @var \Encore\Admin\Auth\Database\Administrator $administrator */
        $administrator = Admin::user();
        $this->settings->saveWatermark($payload, $administrator, $request->ip(), $request->userAgent());

        admin_success(
            __('configuration.watermark.notifications.saved_title'),
            __('configuration.watermark.notifications.saved_message'),
        );

        return redirect(admin_url('configuration/watermark'));
    }

    private function labels(): array
    {
        return [
            'admin.watermark.enabled' => __('configuration.watermark.fields.enabled'),
            'admin.watermark.content_mode' => __('configuration.watermark.fields.content_mode'),
            'admin.watermark.custom_content' => __('configuration.watermark.fields.custom_content'),
            'admin.watermark.opacity' => __('configuration.watermark.fields.opacity'),
            'admin.watermark.rotation' => __('configuration.watermark.fields.rotation'),
            'admin.watermark.spacing_x' => __('configuration.watermark.fields.spacing_x'),
            'admin.watermark.spacing_y' => __('configuration.watermark.fields.spacing_y'),
            'admin.watermark.font_size' => __('configuration.watermark.fields.font_size'),
            'admin.watermark.color' => __('configuration.watermark.fields.color'),
        ];
    }
}


