<?php

use Illuminate\Support\Facades\Route;

// In the protected admin routes section, add:
Route::prefix('fup')->middleware('permission:view fup')->group(function () {
    Route::get('/logs', [\App\Http\Controllers\Api\FupController::class, 'index']);
    Route::get('/status/{account_id}', [\App\Http\Controllers\Api\FupController::class, 'status']);
    Route::post('/reset/{account_id}', [\App\Http\Controllers\Api\FupController::class, 'reset'])->middleware('permission:edit fup');
    Route::get('/stats', [\App\Http\Controllers\Api\FupController::class, 'stats']);
});
