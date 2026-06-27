<?php

use App\Http\Controllers\SetupController;
use App\Http\Middleware\RedirectIfInstalled;
use Illuminate\Support\Facades\Route;

Route::middleware([RedirectIfInstalled::class])->prefix('setup')->name('setup.')->group(function (): void {
    Route::get('/', [SetupController::class, 'show'])->name('show');
    Route::post('/database/test', [SetupController::class, 'testDatabase'])->name('database.test');
    Route::post('/install', [SetupController::class, 'install'])->name('install');
});
