<?php

use App\Portals\AssetManager\Http\Controllers\AssetManagerController;
use Illuminate\Support\Facades\Route;

Route::prefix('asset-manager')->name('asset-manager.')->controller(AssetManagerController::class)->group(function () {
    Route::post('/ipfoxy/accounts', 'accountSave')->name('accounts.store');
    Route::put('/ipfoxy/accounts/{account}', 'accountSave')->whereNumber('account')->name('accounts.update');
    Route::delete('/ipfoxy/accounts/{account}', 'accountDelete')->whereNumber('account')->name('accounts.delete');
    Route::post('/ipfoxy/accounts/{account}/sync', 'sync')->whereNumber('account')->name('accounts.sync');
    Route::post('/ipfoxy/sync/{sync}', 'syncStep')->whereUuid('sync')->name('sync.step');
    Route::get('/{kind}/export', 'export')->whereIn('kind', ['computers', 'resources', 'codex-renewals', 'ipfoxy'])->name('export');
    Route::post('/{kind}/import/preview', 'preview')->whereIn('kind', ['computers', 'resources', 'codex-renewals'])->name('preview');
    Route::post('/{kind}/import', 'import')->whereIn('kind', ['computers', 'resources', 'codex-renewals'])->name('import');
    Route::get('/{kind}', 'index')->whereIn('kind', ['computers', 'resources', 'codex-renewals', 'ipfoxy'])->name('index');
    Route::post('/{kind}', 'save')->whereIn('kind', ['computers', 'resources', 'codex-renewals'])->name('store');
    Route::get('/{kind}/{record}', 'show')->whereNumber('record')->whereIn('kind', ['computers', 'resources', 'codex-renewals'])->name('show');
    Route::put('/{kind}/{record}', 'save')->whereNumber('record')->whereIn('kind', ['computers', 'resources', 'codex-renewals'])->name('update');
    Route::delete('/{kind}/{record}', 'destroy')->whereNumber('record')->whereIn('kind', ['computers', 'resources', 'codex-renewals'])->name('delete');
});
