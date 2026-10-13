<?php

namespace App\Support;

/**
 * Compatibility bridge for business modules originally written against the
 * previous laravel-admin package. No package/vendor file is altered.
 */
final class LegacyAdminAliases
{
    public static function register(): void
    {
        $aliases = [
            'Encore\\Admin\\Admin' => \Ladmin\Admin::class,
            'Encore\\Admin\\Facades\\Admin' => \Ladmin\Facades\Admin::class,
            'Encore\\Admin\\Form' => \Ladmin\Form::class,
            'Encore\\Admin\\Form\\Tools' => \Ladmin\Form\Tools::class,
            'Encore\\Admin\\Grid' => \Ladmin\Grid::class,
            'Encore\\Admin\\Grid\\Filter' => \Ladmin\Grid\Filter::class,
            'Encore\\Admin\\Grid\\Tools' => \Ladmin\Grid\Tools::class,
            'Encore\\Admin\\Grid\\Tools\\BatchActions' => \Ladmin\Grid\Tools\BatchActions::class,
            'Encore\\Admin\\Grid\\Displayers\\Actions' => \Ladmin\Grid\Displayers\Actions::class,
            'Encore\\Admin\\Grid\\Displayers\\DropdownActions' => \Ladmin\Grid\Displayers\DropdownActions::class,
            'Encore\\Admin\\Layout\\Content' => \Ladmin\Layout\Content::class,
            'Encore\\Admin\\Show' => \Ladmin\Show::class,
            'Encore\\Admin\\Controllers\\AdminController' => \Ladmin\Controllers\AdminController::class,
            'Encore\\Admin\\Controllers\\AuthController' => \Ladmin\Controllers\AuthController::class,
            'Encore\\Admin\\Controllers\\HandleController' => \Ladmin\Controllers\HandleController::class,
            'Encore\\Admin\\Controllers\\LogController' => \Ladmin\Controllers\LogController::class,
            'Encore\\Admin\\Controllers\\MenuController' => \Ladmin\Controllers\MenuController::class,
            'Encore\\Admin\\Auth\\Database\\Administrator' => \Ladmin\Auth\Database\Administrator::class,
            'Encore\\Admin\\Auth\\Database\\Role' => \Ladmin\Auth\Database\Role::class,
            'Encore\\Admin\\Auth\\Database\\Permission' => \Ladmin\Auth\Database\Permission::class,
            'Encore\\Admin\\Auth\\Database\\Menu' => \Ladmin\Auth\Database\Menu::class,
        ];

        foreach ($aliases as $legacy => $target) {
            if (!class_exists($legacy, false) && class_exists($target)) {
                class_alias($target, $legacy);
            }
        }
    }
}
