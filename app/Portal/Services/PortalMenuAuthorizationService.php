<?php

namespace App\Portal\Services;

use App\Models\AdminUser;
use App\Models\Portal;
use App\Models\PortalMenu;
use App\Services\DepartmentAuthorizationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PortalMenuAuthorizationService
{
    public function __construct(private readonly DepartmentAuthorizationService $authorization)
    {
    }

    public function canAccess(AdminUser $user, PortalMenu $menu): bool
    {
        if ($user->isAdministrator() || !$menu->permission_slug) {
            return true;
        }

        return $this->authorization->hasPermission($user, $menu->permission_slug);
    }

    public function visibleMenus(Portal $portal, AdminUser $user): Collection
    {
        $menus = $portal->menus;
        if ($user->isAdministrator()) {
            return $menus;
        }

        $byParent = $menus->groupBy(fn (PortalMenu $menu) => (int) ($menu->parent_id ?: 0));
        $visibleIds = collect();
        $walk = function (PortalMenu $menu) use (&$walk, $byParent, $user, $visibleIds): bool {
            $hasVisibleChild = false;
            foreach ($byParent->get((int) $menu->getKey(), collect()) as $child) {
                $hasVisibleChild = $walk($child) || $hasVisibleChild;
            }
            $visible = $this->canAccess($user, $menu) || $hasVisibleChild;
            if ($visible) {
                $visibleIds->push((int) $menu->getKey());
            }
            return $visible;
        };

        foreach ($byParent->get(0, collect()) as $root) {
            $walk($root);
        }

        return $menus->whereIn('id', $visibleIds->unique()->all())->values();
    }

    public function menuForRequest(Portal $portal, string $requestPath): ?PortalMenu
    {
        $currentPath = '/'.trim($requestPath, '/');

        return $portal->menus
            ->where('is_enabled', true)
            ->filter(function (PortalMenu $menu) use ($currentPath): bool {
                $path = trim((string) $menu->path);
                if ($path === '' || $path === '#' || Str::startsWith($path, ['http://', 'https://'])) {
                    return false;
                }
                $menuPath = '/'.trim(parse_url($path, PHP_URL_PATH) ?: $path, '/');
                return $menuPath !== '/'
                    && ($currentPath === $menuPath || Str::startsWith($currentPath, rtrim($menuPath, '/').'/'));
            })
            ->sortByDesc(fn (PortalMenu $menu) => strlen((string) $menu->path))
            ->first();
    }
}
