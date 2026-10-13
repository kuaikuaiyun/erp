<?php

namespace App\Services;

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Facades\Admin;

class AdminWatermarkService
{
    public function __construct(private readonly SystemSettingService $settings)
    {
    }

    /**
     * Register a non-interactive per-administrator watermark for backend pages.
     */
    public function registerForCurrentAdministrator(): void
    {
        $administrator = Admin::user();

        $script = $this->scriptForAdministrator($administrator);

        if ($script === null) {
            return;
        }

        Admin::script($script);
    }

    /**
     * Build the client-side watermark script for a signed-in administrator.
     *
     * This is shared by the main backend and every authenticated portal shell,
     * so they always render from the same system-level watermark settings.
     */
    public function scriptForAdministrator(?Administrator $administrator): ?string
    {

        if (!$administrator instanceof Administrator) {
            return null;
        }

        $settings = $this->settings->watermark();
        $text = $this->settings->watermarkText($administrator, $settings);

        if (!$settings['enabled'] || $text === '') {
            return null;
        }

        $payload = json_encode([
            'text' => $text,
            'opacity' => $settings['opacity'],
            'rotation' => $settings['rotation'],
            'spacingX' => $settings['spacing_x'],
            'spacingY' => $settings['spacing_y'],
            'fontSize' => $settings['font_size'],
            'color' => $settings['color'],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return null;
        }

        return <<<JS
;(function () {
    var config = {$payload};
    var mount = function () {
        var existing = document.getElementById('admin-watermark-layer');
        if (existing) {
            existing.remove();
        }

        var canvas = document.createElement('canvas');
        var scale = window.devicePixelRatio || 1;
        canvas.width = Math.round(config.spacingX * scale);
        canvas.height = Math.round(config.spacingY * scale);

        var context = canvas.getContext('2d');
        context.scale(scale, scale);
        context.globalAlpha = Math.max(0.05, Math.min(0.7, Number(config.opacity) / 100));
        context.fillStyle = config.color;
        context.font = Number(config.fontSize) + 'px sans-serif';
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.translate(config.spacingX / 2, config.spacingY / 2);
        context.rotate(Number(config.rotation) * Math.PI / 180);
        context.fillText(String(config.text).slice(0, 120), 0, 0);

        var layer = document.createElement('div');
        layer.id = 'admin-watermark-layer';
        layer.setAttribute('aria-hidden', 'true');
        layer.style.cssText = [
            'position:fixed', 'inset:0', 'pointer-events:none', 'z-index:9999',
            'background-image:url(' + canvas.toDataURL('image/png') + ')',
            'background-size:' + config.spacingX + 'px ' + config.spacingY + 'px'
        ].join(';');
        document.body.appendChild(layer);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount, {once: true});
    } else {
        mount();
    }
})();
JS;
    }
}


