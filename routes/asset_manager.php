<?php

use App\Portals\AssetManager\Http\Controllers\AccountController;
use App\Portals\AssetManager\Http\Controllers\AccountDingTalkController;
use App\Portals\AssetManager\Http\Controllers\AssetManagerController;
use App\Portals\AssetManager\Http\Controllers\ComputerDepartmentBindingController;
use App\Portals\AssetManager\Http\Controllers\DisciplineController;
use Illuminate\Support\Facades\Route;

Route::prefix('asset-manager/reminders')->name('asset-manager.reminders.')->controller(\App\Portals\AssetManager\Http\Controllers\ReminderController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/options', 'options')->name('options');
    Route::get('/attendance', 'attendance')->middleware('throttle:20,1')->name('attendance');
    Route::get('/runs', 'runs')->name('runs');
    Route::get('/rules/{id}/preview', 'preview')->whereNumber('id')->name('preview');
    Route::post('/rules/{id}/test', 'test')->whereNumber('id')->middleware('throttle:3,1')->name('test');
    Route::get('/{kind}/{id}', 'show')->whereIn('kind', ['groups', 'rules'])->whereNumber('id')->name('show');
    Route::post('/{kind}', 'save')->whereIn('kind', ['groups', 'rules'])->name('store');
    Route::put('/{kind}/{id}', 'save')->whereIn('kind', ['groups', 'rules'])->whereNumber('id')->name('update');
    Route::delete('/{kind}/{id}', 'remove')->whereIn('kind', ['groups', 'rules'])->whereNumber('id')->name('destroy');
});

Route::prefix('asset-manager/accounts')->name('asset-manager.accounts.')->group(function () {
    $accounts = AccountController::class;
    $ding = AccountDingTalkController::class;
    Route::get('/', [$accounts, 'index'])->name('index');
    Route::get('/options', [$accounts, 'options'])->name('options');
    Route::get('/dingtalk/directory', [$ding, 'directory'])->middleware('throttle:60,1')->name('dingtalk.directory');
    Route::get('/dingtalk/audits', [$ding, 'audits'])->name('dingtalk.audits');
    Route::get('/dingtalk/company-grants', [$ding, 'grants'])->name('dingtalk.grants');
    Route::post('/dingtalk/company-grants', [$ding, 'saveGrants'])->middleware('throttle:10,1')->name('dingtalk.grants.store');
    Route::post('/dingtalk/bindings', [$ding, 'store'])->middleware('throttle:10,1')->name('dingtalk.bindings.store');
    Route::delete('/dingtalk/bindings/{binding}', [$ding, 'destroy'])->whereNumber('binding')->name('dingtalk.bindings.destroy');
    Route::get('/{account}/dingtalk', [$ding, 'options'])->whereNumber('account')->name('dingtalk.options');
    Route::get('/{account}/permissions', [$accounts, 'permissions'])->whereNumber('account')->name('permissions');
    Route::put('/{account}/permissions', [$accounts, 'savePermissions'])->whereNumber('account')->name('permissions.update');
    Route::post('/', [$accounts, 'store'])->name('store');
    Route::get('/{account}', [$accounts, 'show'])->whereNumber('account')->name('show');
    Route::put('/{account}', [$accounts, 'update'])->whereNumber('account')->name('update');
    Route::delete('/{account}', [$accounts, 'destroy'])->whereNumber('account')->name('destroy');
});

Route::prefix('asset-manager/computer-department-bindings')->name('asset-manager.computer-department-bindings.')->controller(ComputerDepartmentBindingController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::put('/', 'update')->name('update');
});

Route::prefix('asset-manager/discipline')->name('asset-manager.discipline.')->controller(DisciplineController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/members', 'members')->name('members');
    Route::get('/export', 'export')->name('export');
    Route::get('/records', 'records')->name('records');
    Route::post('/records', 'store')->name('store');
    Route::get('/records/{record}', 'show')->whereNumber('record')->name('show');
    Route::put('/records/{record}', 'update')->whereNumber('record')->name('update');
    Route::delete('/records/{record}', 'destroy')->whereNumber('record')->name('destroy');
});

// Retired pages must not fall through to the generic portal page handler.
Route::any('/asset-manager/{kind}/{retired?}', [AssetManagerController::class, 'retired'])
    ->whereIn('kind', ['resources', 'codex-renewals', 'ipfoxy'])
    ->where('retired', '.*')->name('asset-manager.retired');

// Only computer equipment remains in the original portal.
Route::prefix('asset-manager')->name('asset-manager.')->controller(AssetManagerController::class)->group(function () {
    Route::get('/{kind}/export', 'export')->whereIn('kind', ['computers'])->name('export');
    Route::post('/{kind}/import/preview', 'preview')->whereIn('kind', ['computers'])->name('preview');
    Route::post('/{kind}/import', 'import')->whereIn('kind', ['computers'])->name('import');
    Route::get('/{kind}', 'index')->whereIn('kind', ['computers'])->name('index');
    Route::post('/{kind}', 'save')->whereIn('kind', ['computers'])->name('store');
    Route::get('/{kind}/{record}', 'show')->whereNumber('record')->whereIn('kind', ['computers'])->name('show');
    Route::put('/{kind}/{record}', 'save')->whereNumber('record')->whereIn('kind', ['computers'])->name('update');
    Route::delete('/{kind}/{record}', 'destroy')->whereNumber('record')->whereIn('kind', ['computers'])->name('delete');
});
