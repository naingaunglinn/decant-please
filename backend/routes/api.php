<?php

use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CancelOrderController;
use App\Http\Controllers\Api\FragranceController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\TrackOrderController;
use App\Http\Controllers\Api\ValidatePromoController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:catalog')->group(function () {
        Route::get('/brands', BrandController::class);
        Route::get('/fragrances', [FragranceController::class, 'index']);
        Route::get('/fragrances/{slug}', [FragranceController::class, 'show']);
        Route::get('/meta', MetaController::class);
    });

    // public write + public lookup: deliberately tighter than catalog reads,
    // each with its own bucket (see AppServiceProvider) so one can't starve another
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:checkout');
    Route::get('/orders/track', TrackOrderController::class)->middleware('throttle:tracking');
    Route::post('/orders/cancel', CancelOrderController::class)->middleware('throttle:cancel');
    Route::post('/orders/validate-promo', ValidatePromoController::class)->middleware('throttle:promo');
});
