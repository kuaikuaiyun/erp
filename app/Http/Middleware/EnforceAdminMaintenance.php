<?php

namespace App\Http\Middleware;

use App\Services\SystemSettingService;
use Closure;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceAdminMaintenance
{
    public function __construct(private readonly SystemSettingService $settings)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $maintenance = $this->settings->maintenance();
        $administrator = Admin::user();

        if (!$maintenance['enabled'] || !$administrator || $administrator->isAdministrator()) {
            return $next($request);
        }

        return response()->view('admin.maintenance', [
            'message' => $maintenance['message'] !== ''
                ? $maintenance['message']
                : __('configuration.maintenance.default_message'),
        ], 503);
    }
}


