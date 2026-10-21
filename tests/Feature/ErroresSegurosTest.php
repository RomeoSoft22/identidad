<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErroresSegurosTest extends TestCase
{
    public function test_error_de_base_de_datos_no_revela_detalles_ni_en_modo_depuracion(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        Route::get('/api/prueba-error', fn () => DB::table('tabla_que_no_existe')->where('id', 5)->first());

        $respuesta = $this->getJson('/api/prueba-error')->assertStatus(500)->assertJsonPath('codigo', 'error_interno');

        $cuerpo = $respuesta->getContent();
        foreach (['SQLSTATE', 'tabla_que_no_existe', 'select', 'mysql', 'Connection', 'exception', 'trace', 'Illuminate', 'App\\'] as $prohibido) {
            $this->assertStringNotContainsStringIgnoringCase($prohibido, $cuerpo);
        }

        $referencia = $respuesta->json('referencia');
        $this->assertMatchesRegularExpression('/^E-[A-Z0-9]{8}$/', $referencia);
        $this->assertStringContainsString($referencia, $respuesta->json('message'));
        Log::shouldHaveReceived('error')->withArgs(fn ($mensaje) => str_contains($mensaje, $referencia) && str_contains($mensaje, 'tabla_que_no_existe'));
    }

    public function test_recurso_inexistente_no_revela_nombres_de_modelos(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/prueba-404', fn () => Usuario::findOrFail(999999));

        $cuerpo = $this->getJson('/api/prueba-404')->assertNotFound()->assertJsonPath('message', 'No encontramos lo que buscas.')->getContent();
        $this->assertStringNotContainsString('Usuario', $cuerpo);
        $this->assertStringNotContainsString('App\\', $cuerpo);
    }

    public function test_ruta_inexistente_y_metodo_no_permitido_tienen_mensajes_claros(): void
    {
        $this->getJson('/api/no-existe')->assertNotFound()->assertJsonPath('codigo', 'error_solicitud');
        $this->deleteJson('/api/jwks.json')->assertStatus(405)->assertJsonPath('message', 'Esta acción no está permitida.');
    }
}
