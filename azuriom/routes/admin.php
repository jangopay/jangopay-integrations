<?php

use Illuminate\Support\Facades\Route;
use Azuriom\Plugin\JangoPay\Controllers\AdminController;

Route::get('/payments', [AdminController::class, 'index'])->name('payments.index');
Route::post('/payments/{attempt}/reverify', [AdminController::class, 'reverify'])->middleware('throttle:10,1')->name('payments.reverify');
Route::post('/test-connection', [AdminController::class, 'testConnection'])->middleware('throttle:4,1')->name('test');
