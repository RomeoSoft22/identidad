<?php

use App\Http\Controllers\DescubrimientoController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\LogoutController;
use App\Http\Middleware\VerificarMatricula;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AuthorizationController;

Route::redirect('/', '/login');

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::match(['get', 'post'], '/logout', LogoutController::class)->name('logout');

// Solo los endpoints OAuth que usa la arquitectura (Passport::ignoreRoutes() en AppServiceProvider).
// POST /oauth/token se registra en bootstrap/app.php, fuera del grupo web (sin sesión ni CSRF).
Route::get('/oauth/authorize', [AuthorizationController::class, 'authorize'])
    ->middleware(VerificarMatricula::class)
    ->name('passport.authorizations.authorize');

Route::get('/.well-known/jwks.json', [DescubrimientoController::class, 'jwks'])->name('jwks');
Route::get('/.well-known/openid-configuration', [DescubrimientoController::class, 'configuracion'])->name('openid');
