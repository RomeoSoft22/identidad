<?php

use App\Http\Controllers\DescubrimientoController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Servidor a servidor y público: sin sesión ni CSRF.
            Route::prefix('api')->group(function () {
                Route::post('/oauth/token', [AccessTokenController::class, 'issueToken'])
                    ->middleware('throttle:60,1')
                    ->name('passport.token');
                Route::get('/jwks.json', [DescubrimientoController::class, 'jwks'])->name('jwks');
                Route::get('/.well-known/openid-configuration', [DescubrimientoController::class, 'configuracion'])->name('openid');
            });
            Route::get('/', fn () => response()->json(['servicio' => 'identidad', 'documentacion' => '/api/.well-known/openid-configuration']));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn () => true); // es una API: siempre JSON
    })->create();
