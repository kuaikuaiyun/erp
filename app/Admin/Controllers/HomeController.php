<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Portal\Services\PortalAccessService;
use App\Portal\Services\PortalDomainService;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;

class HomeController extends Controller
{
    public function __construct(
        private readonly PortalAccessService $access,
        private readonly PortalDomainService $domains,
    ) {
    }

    public function index(Content $content)
    {
        /** @var AdminUser $administrator */
        $administrator = Admin::user();
        $hour = now('Asia/Shanghai')->hour;
        $greetingKey = $hour < 12
            ? 'admin.greeting.morning'
            : ($hour < 18 ? 'admin.greeting.afternoon' : 'admin.greeting.evening');
        $systems = $this->access
            ->accessiblePortalsFor($administrator)
            ->map(function ($portal) use ($administrator): array {
                return [
                    'name' => $portal->name,
                    'remark' => $portal->remark,
                    'status' => $portal->status,
                    'url' => $this->domains->portalUrl($portal),
                    'can_enter' => $portal->isEnabled()
                        || ($portal->isMaintenance() && $administrator->isAdministrator()),
                ];
            });

        return $content
            ->title(__('portal.home.title'))
            ->description(__('portal.home.description'))
            ->body(view('admin.portal.dashboard', [
                'administrator' => $administrator,
                'greeting' => trans($greetingKey, ['name' => $administrator->name]),
                'today' => now('Asia/Shanghai')->format('Y-m-d'),
                'systems' => $systems,
            ])->render());
    }
}


