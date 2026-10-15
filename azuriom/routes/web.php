<?php

use Illuminate\Support\Facades\Route;
use Azuriom\Plugin\JangoPay\Controllers\CheckoutController;

Route::get('/payment/{attempt}/{token}', [CheckoutController::class, 'show'])->name('payment.show');
Route::post('/payment/{attempt}/{token}/coin', [CheckoutController::class, 'choose'])->middleware('throttle:8,1')->name('payment.coin');
Route::get('/payment/{attempt}/{token}/status', [CheckoutController::class, 'status'])->middleware('throttle:40,1')->name('payment.status');
