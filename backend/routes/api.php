<?php

use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\FragranceController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\TrackOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:120,1')->group(function () {
        Route::get('/brands', BrandController::class);
        Route::get('/fragrances', [FragranceController::class, 'index']);
        Route::get('/fragrances/{slug}', [FragranceController::class, 'show']);
        Route::get('/meta', MetaController::class);
    });

    // public write + public lookup: deliberately tighter than catalog reads
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/orders/track', TrackOrderController::class)->middleware('throttle:20,1');
});
