<?php

use App\Http\Controllers\Api\AutorizacionController;
use App\Http\Controllers\Api\GoogleController;
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
    Route::post('/login/verificar', [SesionController::class, 'verificarCodigo'])->name('login.verificar');
    Route::post('/logout', [SesionController::class, 'logout'])->name('logout');
    Route::get('/authorize', AutorizacionController::class)->name('authorize');

    // Ingreso con Google: navegación completa del navegador (redirecciones), no llamadas XHR.
    Route::get('/metodos', [GoogleController::class, 'metodos'])->name('metodos');
    Route::get('/google/redirect', [GoogleController::class, 'redirigir'])->middleware('throttle:20,1')->name('google.redirect');
    Route::get('/google/callback', [GoogleController::class, 'callback'])->middleware('throttle:30,1')->name('google.callback');
});
