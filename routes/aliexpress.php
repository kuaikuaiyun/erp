<?php

use App\Portals\AliExpress\Http\Controllers\ProcurementQueueController;
use App\Portals\AliExpress\Http\Controllers\SelectionPoolController;
use Illuminate\Support\Facades\Route;

Route::prefix('aliexpress')->name('aliexpress.')->group(function (): void {
    Route::get('selection', [SelectionPoolController::class, 'browse'])->name('selection.browse');
    Route::get('selection/manage', [SelectionPoolController::class, 'index'])->name('selection.index');
    Route::get('selection/manage/lookup', [SelectionPoolController::class, 'lookup'])->name('selection.lookup');
    Route::get('selection/manage/create', [SelectionPoolController::class, 'create'])->name('selection.create');
    Route::post('selection/manage', [SelectionPoolController::class, 'store'])->name('selection.store');
    Route::get('selection/manage/{product}/edit', [SelectionPoolController::class, 'edit'])->whereNumber('product')->name('selection.edit');
    Route::put('selection/manage/{product}', [SelectionPoolController::class, 'update'])->whereNumber('product')->name('selection.update');
    Route::get('procurement', [ProcurementQueueController::class, 'index'])->name('procurement.index');
});
