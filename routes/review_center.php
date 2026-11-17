<?php

use App\Portals\ReviewCenter\Http\Controllers\DepartmentBindingController;
use App\Portals\ReviewCenter\Http\Controllers\OperationTaskReviewController;
use Illuminate\Support\Facades\Route;

Route::prefix('review-center')->name('review-center.')->group(function (): void {
    Route::get('/department-bindings', [DepartmentBindingController::class, 'index'])->name('department-bindings.index');
    Route::put('/department-bindings', [DepartmentBindingController::class, 'update'])->name('department-bindings.update');
    Route::prefix('operation-tasks')->name('operation-tasks.')->controller(OperationTaskReviewController::class)->group(function (): void {
        Route::get('/reviews', 'reviews')->name('reviews.index');
        Route::post('/reviews/batch', 'reviewBatch')->name('reviews.batch.store');
        Route::post('/recaps/{recap}/reviews', 'reviewRecap')->whereNumber('recap')->name('recaps.reviews.store');
        Route::get('/tests/{task}', 'showTest')->whereNumber('task')->name('tests.show');
        Route::get('/assets/{asset}/file', 'assetFile')->whereNumber('asset')->name('assets.file');
    });
});
