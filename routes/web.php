<?php

use App\Http\Controllers\Api\AutorizacionController;
use App\Http\Controllers\Api\SesionController;
use Illuminate\Support\Facades\Route;

/*
 * Endpoints que usa identidad-web (Angular) desde el navegador. Van en el grupo "web"
 * porque necesitan la cookie de sesión SSO y protección CSRF (cookie XSRF-TOKEN que Angular
 * reenvía en la cabecera X-XSRF-TOKEN). Responden siempre JSON.
 *
 * Los endpoints servidor a servidor (token, JWKS, descubrimiento) están en bootstrap/app.php, sin sesión.
 */
Route::prefix('api')->group(function () {
    Route::get('/sesion', [SesionController::class, 'show'])->name('sesion');
    Route::post('/login', [SesionController::class, 'login'])->name('login');
    Route::post('/logout', [SesionController::class, 'logout'])->name('logout');
    Route::get('/authorize', AutorizacionController::class)->name('authorize');
});
