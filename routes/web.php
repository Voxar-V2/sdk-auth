<?php

use Illuminate\Support\Facades\Route;
use Voxar\AuthSdk\Http\Controllers\SsoController;

Route::middleware('web')->group(function () {
    Route::get('login', [SsoController::class, 'login'])->name('login');
    Route::get('auth/callback', [SsoController::class, 'callback'])->middleware('throttle:30,1')->name('voxar-auth.callback');
    Route::post('logout', [SsoController::class, 'logout'])->name('logout');
});
