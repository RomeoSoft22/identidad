<?php

use App\Exceptions\ErroresSeguros;
use App\Http\Controllers\Admin\MatriculaController;
use App\Http\Controllers\Admin\ResumenController;
use App\Http\Controllers\Admin\SistemaController;
use App\Http\Controllers\Admin\UsuarioController;
use App\Http\Controllers\DescubrimientoController;
use App\Http\Middleware\AdministradorPanel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
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
            // API de administración que consume el panel (panel.juarezcampos.net): JWT del sistema PANEL con rol ADMIN.
            Route::prefix('api/admin')->middleware(['throttle:120,1', AdministradorPanel::class, SubstituteBindings::class])->group(function () {
                Route::get('/resumen', [ResumenController::class, 'resumen']);
                Route::get('/auditoria', [ResumenController::class, 'auditoria']);

                Route::get('/sistemas', [SistemaController::class, 'index']);
                Route::post('/sistemas', [SistemaController::class, 'store']);
                Route::put('/sistemas/{sistema}', [SistemaController::class, 'update']);
                Route::post('/sistemas/{sistema}/secreto', [SistemaController::class, 'regenerarSecreto']);
                Route::post('/sistemas/{sistema}/roles', [SistemaController::class, 'crearRol']);
                Route::delete('/roles/{rol}', [SistemaController::class, 'eliminarRol']);

                Route::get('/usuarios', [UsuarioController::class, 'index']);
                Route::post('/usuarios', [UsuarioController::class, 'store']);
                Route::get('/usuarios/{usuario}', [UsuarioController::class, 'show']);
                Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update']);
                Route::post('/usuarios/{usuario}/desbloquear', [UsuarioController::class, 'desbloquear']);
                Route::post('/usuarios/{usuario}/contrasena', [UsuarioController::class, 'cambiarContrasena']);
                Route::post('/usuarios/{usuario}/cerrar-sesiones', [UsuarioController::class, 'cerrarSesiones']);

                Route::post('/matriculas', [MatriculaController::class, 'store']);
                Route::put('/matriculas/{matricula}', [MatriculaController::class, 'update']);
            });
            Route::get('/', fn () => response()->json(['servicio' => 'identidad', 'documentacion' => '/api/.well-known/openid-configuration']));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Es una API: siempre JSON y nunca con detalles internos (ver ErroresSeguros).
        ErroresSeguros::registrar($exceptions);
    })->create();
