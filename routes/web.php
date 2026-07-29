<?php

use App\Http\Controllers\InstallController;
use App\Http\Controllers\Web\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (serve the Vue SPA)
|--------------------------------------------------------------------------
| All non-API, non-install routes are handled by the Vue Router through
| this catch-all. The install wizard (InstallController) runs before the
| install guard: those routes are exempted from the lock check.
*/

// ---------- Installer (no install lock required) ----------
Route::middleware([])->prefix('install')->name('install.')->group(function () {
    Route::get('/',         [InstallController::class, 'welcome'])->name('welcome');
    Route::get('/database', [InstallController::class, 'database'])->name('database');
    Route::post('/database',[InstallController::class, 'databaseStore'])->name('database.store');
    Route::get('/app',      [InstallController::class, 'app'])->name('app');
    Route::post('/app',     [InstallController::class, 'appStore'])->name('app.store');
    Route::get('/run',      [InstallController::class, 'run'])->name('run');
    Route::post('/run',     [InstallController::class, 'runStore'])->name('run.store');
    Route::get('/done',     [InstallController::class, 'done'])->name('done');
});

// ---------- Main app (install guard required) ----------
Route::get('/', function () {
    return view('app');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/login', function () {
    return view('app');
})->name('login');

Route::view('/{any}', 'app')->where('any', '^(?!api).*$');
