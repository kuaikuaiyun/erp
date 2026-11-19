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
    $router->get('organization/accounts/{account}/dingtalk', [\App\Admin\Controllers\DingTalkBindingController::class,'options'])->whereNumber('account')->middleware([RequireSystemAdministrator::class, 'admin.permission:check,organization.accounts',\App\Http\Middleware\RequireDingTalkBindingAccess::class]);
    $router->post('organization/accounts/dingtalk/bindings', [\App\Admin\Controllers\DingTalkBindingController::class,'store'])->middleware([RequireSystemAdministrator::class, 'admin.permission:check,organization.accounts',\App\Http\Middleware\RequireDingTalkBindingAccess::class,'throttle:10,1']);
    $router->delete('organization/accounts/dingtalk/bindings/{binding}', [\App\Admin\Controllers\DingTalkBindingController::class,'destroy'])->whereNumber('binding')->middleware([RequireSystemAdministrator::class, 'admin.permission:check,organization.accounts',\App\Http\Middleware\RequireDingTalkBindingAccess::class]);
    $router->get('organization/accounts/dingtalk/directory', [\App\Admin\Controllers\DingTalkBindingController::class,'directory'])->middleware([RequireSystemAdministrator::class, 'admin.permission:check,organization.accounts',\App\Http\Middleware\RequireDingTalkBindingAccess::class,'throttle:60,1']);
    $router->get('organization/accounts/dingtalk/audits', [\App\Admin\Controllers\DingTalkBindingController::class,'audits'])->middleware([RequireSystemAdministrator::class, 'admin.permission:check,organization.accounts',\App\Http\Middleware\RequireDingTalkBindingAccess::class]);
    $router->get('organization/accounts/dingtalk/company-grants', [\App\Admin\Controllers\DingTalkCompanyGrantController::class,'index'])->middleware([RequireSystemAdministrator::class, \App\Http\Middleware\RequireDingTalkBindingOwner::class]);
    $router->post('organization/accounts/dingtalk/company-grants', [\App\Admin\Controllers\DingTalkCompanyGrantController::class,'store'])->middleware([RequireSystemAdministrator::class, \App\Http\Middleware\RequireDingTalkBindingOwner::class,'throttle:10,1']);
    $router->get('configuration/dingtalk', [\App\Admin\Controllers\ConfigurationCenter\DingTalkCompanyController::class,'index'])->middleware('admin.permission:check,configuration.dingtalk');
    $router->post('configuration/dingtalk/companies', [\App\Admin\Controllers\ConfigurationCenter\DingTalkCompanyController::class,'save'])->middleware('admin.permission:check,configuration.dingtalk');
    $router->delete('configuration/dingtalk/companies/{company}', [\App\Admin\Controllers\ConfigurationCenter\DingTalkCompanyController::class,'destroy'])->whereNumber('company')->middleware('admin.permission:check,configuration.dingtalk');
    $router->post('configuration/dingtalk/companies/{company}/sync', [\App\Admin\Controllers\ConfigurationCenter\DingTalkCompanyController::class,'sync'])->whereNumber('company')->middleware(['admin.permission:check,configuration.dingtalk','throttle:60,1']);
    $router->get('configuration/dingtalk/companies/{company}/directory', [\App\Admin\Controllers\ConfigurationCenter\DingTalkCompanyController::class,'directory'])->whereNumber('company')->middleware(['admin.permission:check,configuration.dingtalk','throttle:60,1']);
    $router->resource('organization/accounts', AccountController::class)->except(['show'])->middleware([RequireSystemAdministrator::class, 'admin.permission:check,organization.accounts'])->names('organization.accounts');
    $router->get('organization/accounts/{account}/permissions', [AccountPermissionController::class, 'edit'])->whereNumber('account')->middleware(['admin.permission:check,organization.accounts', RequireSystemAdministrator::class])->name('organization.accounts.permissions.edit');
    $router->put('organization/accounts/{account}/permissions', [AccountPermissionController::class, 'update'])->whereNumber('account')->middleware(['admin.permission:check,organization.accounts', RequireSystemAdministrator::class])->name('organization.accounts.permissions.update');
    $router->get('organization/departments/bindings', [DepartmentController::class, 'bindings'])->middleware(['admin.permission:check,organization.departments', RequireSystemAdministrator::class])->name('organization.departments.bindings');
    $router->put('organization/departments/bindings', [DepartmentController::class, 'updateBindings'])->middleware(['admin.permission:check,organization.departments', RequireSystemAdministrator::class])->name('organization.departments.bindings.update');
    $router->get('organization/departments/{department}/members', [DepartmentController::class, 'members'])->whereNumber('department')->middleware([RequireSystemAdministrator::class, 'admin.permission:check,organization.departments'])->name('organization.departments.members');
    $router->post('organization/departments/{department}/members', [DepartmentController::class, 'addMember'])->whereNumber('department')->middleware([RequireSystemAdministrator::class, 'admin.permission:check,organization.departments'])->name('organization.departments.members.add');
    $router->resource('organization/departments', DepartmentController::class)->except(['show'])->middleware([RequireSystemAdministrator::class, 'admin.permission:check,organization.departments'])->names('organization.departments');
    $router->get('organization/departments/{department}/dissolution', [DepartmentController::class, 'dissolution'])->whereNumber('department')->middleware(['admin.permission:check,organization.departments', RequireSystemAdministrator::class])->name('organization.departments.dissolution');
    $router->post('organization/departments/{department}/dissolution', [DepartmentController::class, 'dissolve'])->whereNumber('department')->middleware(['admin.permission:check,organization.departments', RequireSystemAdministrator::class])->name('organization.departments.dissolve');
    $router->get('organization/departments/{department}/permissions', [DepartmentPermissionController::class, 'edit'])->whereNumber('department')->middleware(['admin.permission:check,organization.departments', RequireSystemAdministrator::class])->name('organization.departments.permissions.edit');
    $router->put('organization/departments/{department}/permissions', [DepartmentPermissionController::class, 'update'])->whereNumber('department')->middleware(['admin.permission:check,organization.departments', RequireSystemAdministrator::class])->name('organization.departments.permissions.update');

    $router->redirect('auth/users', 'organization/accounts')->middleware([RequireSystemAdministrator::class, 'admin.permission:check,organization.accounts']);
    $router->redirect('auth/roles', 'organization/departments')->middleware([RequireSystemAdministrator::class, 'admin.permission:check,organization.departments']);
    $router->redirect('auth/permissions', 'organization/departments')->middleware(RequireSystemAdministrator::class);
    $router->redirect('organization/department-permissions', 'organization/departments')->middleware(RequireSystemAdministrator::class);

    $router->resource('auth/menu', MenuController::class, ['except' => ['create']])->middleware(RequireSystemAdministrator::class)->names('auth.menu');
    $router->resource('auth/logs', LogController::class, ['only' => ['index', 'destroy']])->middleware(RequireSystemAdministrator::class)->names('auth.logs');
    $router->post('_handle_form_', [HandleController::class, 'handleForm'])->name('handle-form');
    $router->post('_handle_action_', [HandleController::class, 'handleAction'])->name('handle-action');
    $router->get('_handle_selectable_', [HandleController::class, 'handleSelectable'])->name('handle-selectable');
    $router->get('_handle_renderable_', [HandleController::class, 'handleRenderable'])->name('handle-renderable');

    $authController = config('admin.auth.controller', AuthController::class);
    $router->get('auth/login', [$authController, 'getLogin'])->middleware('throttle:'.config('dingtalk.login_rate').',1')->name('login');
    $router->get('auth/login/{slug}', [$authController, 'getLogin'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->middleware('throttle:'.config('dingtalk.login_rate').',1')->name('login.company');
    $router->post('auth/login', [$authController, 'postLogin'])->middleware('throttle:10,1');
    $router->get('auth/logout', [$authController, 'getLogout'])->name('logout');
    $router->get('auth/setting', [$authController, 'getSetting'])->name('setting');
    $router->put('auth/setting', [$authController, 'putSetting']);
    $router->get('auth/tencent-captcha/options', [AuthController::class, 'tencentCaptchaOptions'])->middleware('throttle:30,1')->name('auth.tencent-captcha.options');

    $router->get('/', [HomeController::class, 'index'])->name('home');
    $router->post('portal/home-folders', [HomeController::class, 'storeFolder'])->middleware(RequireSystemAdministrator::class)->name('portal.home-folders.store');
    $router->patch('portal/home-folders/{folder}', [HomeController::class, 'updateFolder'])->whereNumber('folder')->middleware(RequireSystemAdministrator::class)->name('portal.home-folders.update');
    $router->delete('portal/home-folders/{folder}', [HomeController::class, 'destroyFolder'])->whereNumber('folder')->middleware(RequireSystemAdministrator::class)->name('portal.home-folders.destroy');
    $router->put('portal/home-folders/layout', [HomeController::class, 'saveFolderLayout'])->middleware(RequireSystemAdministrator::class)->name('portal.home-folders.layout');

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
