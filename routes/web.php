<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MonitorController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::middleware('can:access-dashboard')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        Route::resource('monitors', MonitorController::class);
        Route::post('/monitors/{monitor}/pause', [MonitorController::class, 'pause'])->name('monitors.pause');
        Route::post('/monitors/{monitor}/resume', [MonitorController::class, 'resume'])->name('monitors.resume');
    });
});
