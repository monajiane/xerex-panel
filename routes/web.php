<?php

use App\Http\Controllers\Web\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (serve the Vue SPA)
|--------------------------------------------------------------------------
| All non-API routes are handled by the Vue Router through this catch-all.
*/

Route::get('/', function () {
    return view('app');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/login', function () {
    return view('app');
})->name('login');

Route::view('/{any}', 'app')->where('any', '^(?!api).*$');
