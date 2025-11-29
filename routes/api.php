<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\HoldController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentWebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/
// for testing 
Route::get('/test', function () {
    return response()->json(['message' => 'API is working!']);
});

// 1. Product Endpoint
Route::get('/products/{id}', [ProductController::class, 'show']);

// 2. Create Hold
Route::post('/holds', [HoldController::class, 'store']);

// 3. Create Order
Route::post('/orders', [OrderController::class, 'store']);

// 4. Payment Webhook
Route::post('/payments/webhook', [PaymentWebhookController::class, 'handle']);
