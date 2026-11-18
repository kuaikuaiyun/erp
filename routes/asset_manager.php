<?php

use App\Portals\AssetManager\Http\Controllers\AssetManagerController;
use App\Portals\AssetManager\Http\Controllers\ComputerDepartmentBindingController;
use App\Portals\AssetManager\Http\Controllers\DisciplineController;
use Illuminate\Support\Facades\Route;

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
