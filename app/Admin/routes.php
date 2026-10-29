<?php

use App\Admin\Controllers\AuthController;
use App\Admin\Controllers\HomeController;
use App\Admin\Controllers\ConfigurationCenter\BasicConfigurationController;
use App\Admin\Controllers\ConfigurationCenter\CaptchaController;
use App\Admin\Controllers\ConfigurationCenter\WatermarkController;
use App\Admin\Controllers\Organization\AccountController;
use App\Admin\Controllers\Organization\AccountPermissionController;
use App\Admin\Controllers\Organization\DepartmentController;
use App\Admin\Controllers\Organization\DepartmentPermissionController;
use App\Admin\Controllers\Portal\PortalController;
use App\Http\Middleware\RequireSystemAdministrator;
use Illuminate\Routing\Router;
use Ladmin\Controllers\HandleController;
use Ladmin\Controllers\LogController;
use Ladmin\Controllers\MenuController;

Route::group([
    'prefix' => config('admin.route.prefix'),
    'middleware' => config('admin.route.middleware'),
    'as' => config('admin.route.prefix').'.',
], function (Router $router): void {
    $router->resource('organization/accounts', AccountController::class)->except(['show'])->middleware('admin.permission:check,organization.accounts')->names('organization.accounts');
    $router->get('organization/accounts/{account}/permissions', [AccountPermissionController::class, 'edit'])->whereNumber('account')->middleware(['admin.permission:check,organization.accounts', RequireSystemAdministrator::class])->name('organization.accounts.permissions.edit');
    $router->put('organization/accounts/{account}/permissions', [AccountPermissionController::class, 'update'])->whereNumber('account')->middleware(['admin.permission:check,organization.accounts', RequireSystemAdministrator::class])->name('organization.accounts.permissions.update');
    $router->get('organization/departments/bindings', [DepartmentController::class, 'bindings'])->middleware(['admin.permission:check,organization.departments', RequireSystemAdministrator::class])->name('organization.departments.bindings');
    $router->put('organization/departments/bindings', [DepartmentController::class, 'updateBindings'])->middleware(['admin.permission:check,organization.departments', RequireSystemAdministrator::class])->name('organization.departments.bindings.update');
    $router->resource('organization/departments', DepartmentController::class)->except(['show'])->middleware('admin.permission:check,organization.departments')->names('organization.departments');
    $router->get('organization/departments/{department}/dissolution', [DepartmentController::class, 'dissolution'])->whereNumber('department')->middleware(['admin.permission:check,organization.departments', RequireSystemAdministrator::class])->name('organization.departments.dissolution');
    $router->post('organization/departments/{department}/dissolution', [DepartmentController::class, 'dissolve'])->whereNumber('department')->middleware(['admin.permission:check,organization.departments', RequireSystemAdministrator::class])->name('organization.departments.dissolve');
    $router->get('organization/departments/{department}/permissions', [DepartmentPermissionController::class, 'edit'])->whereNumber('department')->middleware(['admin.permission:check,organization.departments', RequireSystemAdministrator::class])->name('organization.departments.permissions.edit');
    $router->put('organization/departments/{department}/permissions', [DepartmentPermissionController::class, 'update'])->whereNumber('department')->middleware(['admin.permission:check,organization.departments', RequireSystemAdministrator::class])->name('organization.departments.permissions.update');

    $router->redirect('auth/users', 'organization/accounts')->middleware('admin.permission:check,organization.accounts');
    $router->redirect('auth/roles', 'organization/departments')->middleware('admin.permission:check,organization.departments');
    $router->redirect('auth/permissions', 'organization/departments');
    $router->redirect('organization/department-permissions', 'organization/departments');

    $router->resource('auth/menu', MenuController::class, ['except' => ['create']])->names('auth.menu');
    $router->resource('auth/logs', LogController::class, ['only' => ['index', 'destroy']])->names('auth.logs');
    $router->post('_handle_form_', [HandleController::class, 'handleForm'])->name('handle-form');
    $router->post('_handle_action_', [HandleController::class, 'handleAction'])->name('handle-action');
    $router->get('_handle_selectable_', [HandleController::class, 'handleSelectable'])->name('handle-selectable');
    $router->get('_handle_renderable_', [HandleController::class, 'handleRenderable'])->name('handle-renderable');

    $authController = config('admin.auth.controller', AuthController::class);
    $router->get('auth/login', [$authController, 'getLogin'])->name('login');
    $router->post('auth/login', [$authController, 'postLogin']);
    $router->get('auth/logout', [$authController, 'getLogout'])->name('logout');
    $router->get('auth/setting', [$authController, 'getSetting'])->name('setting');
    $router->put('auth/setting', [$authController, 'putSetting']);
    $router->get('auth/tencent-captcha/options', [AuthController::class, 'tencentCaptchaOptions'])->middleware('throttle:30,1')->name('auth.tencent-captcha.options');

    $router->get('/', [HomeController::class, 'index'])->name('home');

    $router->resource('portal/portals', PortalController::class)->except(['show', 'destroy'])->middleware(['admin.permission:check,portal.management', RequireSystemAdministrator::class])->names('portal.portals');
    $router->get('portal/portals/{portal}/authorization-history', [PortalController::class, 'authorizationHistory'])->whereNumber('portal')->middleware(['admin.permission:check,portal.management', RequireSystemAdministrator::class])->name('portal.portals.authorization-history');
    $router->post('portal/portals/{portal}/status', [PortalController::class, 'updateStatus'])->whereNumber('portal')->middleware(['admin.permission:check,portal.management', RequireSystemAdministrator::class])->name('portal.portals.status');

    $router->get('configuration/captcha', [CaptchaController::class, 'index'])->middleware('admin.permission:check,configuration.captcha')->name('configuration.captcha');
    $router->post('configuration/captcha', [CaptchaController::class, 'save'])->middleware('admin.permission:check,configuration.captcha')->name('configuration.captcha.save');
    $router->post('configuration/captcha/reveal/{secret}', [CaptchaController::class, 'revealSecret'])->where('secret', 'turnstile|recaptcha_v2|tencent_captcha_app_secret_key|tencent_captcha_secret_id|tencent_captcha_secret_key')->middleware('admin.permission:check,configuration.captcha')->name('configuration.captcha.reveal');
    $router->get('configuration/watermark', [WatermarkController::class, 'index'])->middleware('admin.permission:check,configuration.watermark')->name('configuration.watermark');
    $router->post('configuration/watermark', [WatermarkController::class, 'save'])->middleware('admin.permission:check,configuration.watermark')->name('configuration.watermark.save');
    $router->get('configuration/basic', [BasicConfigurationController::class, 'index'])->middleware('admin.permission:check,configuration.basic')->name('configuration.basic');
    $router->post('configuration/basic', [BasicConfigurationController::class, 'save'])->middleware('admin.permission:check,configuration.basic')->name('configuration.basic.save');
});
