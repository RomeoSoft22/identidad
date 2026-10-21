<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SesionController;
use App\Mail\CodigoDesbloqueo;
use App\Models\Acceso;
use App\Models\CodigoVerificacion;
use App\Models\Dispositivo;
use App\Models\EventoAuditoria;
use App\Models\Matricula;
use App\Models\Rol;
use App\Models\SesionSso;
use App\Models\Sistema;
use App\Models\Usuario;
use App\Support\Bloqueo;
use App\Support\Llaves;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

class FlujoIdentidadTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://istqb.juarezcampos.net/callback';

    private const CLAVE = 'Clave-Segura-123';

    private Sistema $istqb;

    private string $secreto;

    private Usuario $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $cliente = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Simulador ISTQB', [self::CALLBACK]);
        $this->secreto = $cliente->plainSecret;
        $this->istqb = Sistema::create([
            'codigo' => 'ISTQB', 'nombre' => 'Simulador ISTQB',
            'url_base' => 'https://istqb.juarezcampos.net', 'oauth_client_id' => $cliente->id,
        ]);
        $admin = Rol::create(['sistema_id' => $this->istqb->id, 'codigo' => 'ADMIN', 'nombre' => 'Administrador']);

        $this->usuario = Usuario::create(['nombres' => 'Ana', 'apellidos' => 'Pérez', 'correo' => 'ana@juarezcampos.net', 'contrasena' => self::CLAVE]);
        Matricula::create(['usuario_id' => $this->usuario->id, 'sistema_id' => $this->istqb->id, 'rol_id' => $admin->id]);
    }

    public function test_flujo_completo_entrega_jwt_rs256_con_aud_y_roles_del_sistema(): void
    {
        $tokens = $this->ingresarYCanjear();

        [$cabecera, $claims] = $this->verificarJwt($tokens['access_token']);
        $this->assertSame('RS256', $cabecera['alg']);
        $this->assertSame(Llaves::kid(), $cabecera['kid']);
        $this->assertSame(config('identidad.issuer'), $claims['iss']);
        $this->assertSame('ISTQB', is_array($claims['aud']) ? $claims['aud'][0] : $claims['aud']);
        $this->assertSame($this->usuario->uuid, $claims['sub']);
        $this->assertSame(['ADMIN'], $claims['roles']);
        $this->assertSame('ana@juarezcampos.net', $claims['email']);
        $this->assertEqualsWithDelta(time() + 15 * 60, $claims['exp'], 5);
        $this->assertNotEmpty($tokens['refresh_token']);

        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::LOGIN_EXITOSO, 'usuario_id' => $this->usuario->id]);
        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::TOKEN_EMITIDO, 'sistema_id' => $this->istqb->id]);
    }

    public function test_sin_sesion_authorize_pide_login_e_indica_el_sistema(): void
    {
        [$url] = $this->urlAutorizacion();

        $this->getJson($url)->assertUnauthorized()
            ->assertJsonPath('codigo', 'login_requerido')
            ->assertJsonPath('sistema.nombre', 'Simulador ISTQB');
    }

    public function test_con_sesion_sso_activa_no_vuelve_a_pedir_contrasena(): void
    {
        $this->ingresarYCanjear();

        [$url] = $this->urlAutorizacion();
        $this->assertStringStartsWith(self::CALLBACK.'?code=', $this->getJson($url)->assertOk()->json('redirect_to'));
    }

    public function test_endpoint_de_sesion(): void
    {
        $this->getJson('/api/sesion')->assertUnauthorized()->assertJsonPath('codigo', 'sin_sesion');
        $this->login()->assertOk();
        $this->getJson('/api/sesion')->assertOk()->assertJsonPath('usuario.email', 'ana@juarezcampos.net');
    }

    public function test_usuario_sin_matricula_recibe_sin_acceso_y_ningun_codigo(): void
    {
        Matricula::query()->delete();
        $this->login()->assertOk();

        [$url] = $this->urlAutorizacion();
        $this->getJson($url)->assertForbidden()
            ->assertJsonPath('codigo', 'sin_acceso')
            ->assertJsonPath('sistema.nombre', 'Simulador ISTQB')
            ->assertJsonPath('usuario.email', 'ana@juarezcampos.net')
            ->assertJsonMissingPath('redirect_to');
        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::ACCESO_DENEGADO, 'usuario_id' => $this->usuario->id]);
    }

    public function test_redirect_uri_no_registrada_es_rechazada(): void
    {
        $this->login()->assertOk();
        [$url] = $this->urlAutorizacion(['redirect_uri' => 'https://atacante.example/callback']);

        $this->getJson($url)->assertStatus(400)
            ->assertJsonPath('codigo', 'solicitud_invalida')
            ->assertJsonMissingPath('redirect_to');
    }

    public function test_cuenta_se_bloquea_al_decimo_fallo_sin_revelarlo_y_el_administrador_puede_desbloquear(): void
    {
        Mail::fake();
        for ($i = 1; $i <= 10; $i++) {
            // Siempre el mismo mensaje: un atacante no sabe cuándo se bloqueó la cuenta.
            $this->login('incorrecta')->assertStatus(422)->assertJsonPath('message', SesionController::ERROR_GENERICO);
        }
        $this->assertSame(Usuario::BLOQUEADO, $this->usuario->fresh()->estado);
        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::CUENTA_BLOQUEADA, 'usuario_id' => $this->usuario->id]);

        // Bloqueada + contraseña incorrecta: mismo mensaje genérico y ningún código.
        $this->login('incorrecta')->assertStatus(422)->assertJsonPath('message', SesionController::ERROR_GENERICO);
        Mail::assertNothingSent();

        $this->artisan('identidad:desbloquear', ['correo' => 'ana@juarezcampos.net'])->assertSuccessful();
        $this->assertSame(0, $this->usuario->fresh()->intentos_fallidos);

        $this->login()->assertOk();
        $this->assertAuthenticatedAs($this->usuario->fresh());
    }

    public function test_cuenta_bloqueada_con_contrasena_correcta_se_desbloquea_con_codigo_del_correo(): void
    {
        Mail::fake();
        $this->bloquear();

        $this->login(extra: ['zona_horaria' => 'America/Lima'])->assertStatus(423)
            ->assertJsonPath('codigo', 'verificacion_requerida')
            ->assertJsonPath('correo', 'a***a@juarezcampos.net');
        $this->assertGuest();

        $codigo = null;
        Mail::assertSent(CodigoDesbloqueo::class, function (CodigoDesbloqueo $m) use (&$codigo) {
            $codigo = $m->codigo;

            return $m->hasTo('ana@juarezcampos.net') && preg_match('/^\d{6}$/', $m->codigo) === 1;
        });
        $this->assertNotSame($codigo, CodigoVerificacion::sole()->codigo_hash, 'El código no se guarda en claro');

        $errado = $codigo === '000000' ? '111111' : '000000';
        $this->postJson('/api/login/verificar', ['codigo' => $errado])->assertStatus(422)->assertJsonPath('codigo', 'codigo_incorrecto');
        $this->assertGuest();

        $this->postJson('/api/login/verificar', ['codigo' => $codigo])->assertOk()->assertJsonPath('usuario.email', 'ana@juarezcampos.net');
        $this->assertAuthenticatedAs($this->usuario->fresh());
        $this->assertSame(Usuario::ACTIVO, $this->usuario->fresh()->estado);
        $this->assertSame('America/Lima', SesionSso::sole()->zona_horaria);
        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::CUENTA_DESBLOQUEADA, 'usuario_id' => $this->usuario->id]);

        // El código es de un solo uso.
        $this->postJson('/api/logout');
        $this->postJson('/api/login/verificar', ['codigo' => $codigo])->assertStatus(410);
    }

    public function test_codigo_se_invalida_tras_cinco_intentos_y_vence(): void
    {
        Mail::fake();
        $this->bloquear();
        $this->login()->assertStatus(423);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login/verificar', ['codigo' => '999999'])->assertStatus(422);
        }
        $this->postJson('/api/login/verificar', ['codigo' => '999999'])->assertStatus(410)->assertJsonPath('codigo', 'codigo_vencido');
        $this->assertSame(Usuario::BLOQUEADO, $this->usuario->fresh()->estado);

        // Un código nuevo vencido tampoco sirve.
        $this->login()->assertStatus(423);
        CodigoVerificacion::query()->update(['expira_en' => now()->subMinute()]);
        $this->postJson('/api/login/verificar', ['codigo' => '123456'])->assertStatus(410);
        $this->assertGuest();
    }

    public function test_limite_de_codigos_por_hora(): void
    {
        Mail::fake();
        config(['identidad.codigo_por_hora' => 2]);
        $this->bloquear();

        $this->login()->assertStatus(423);
        $this->login()->assertStatus(423);
        $this->login()->assertStatus(429)->assertJsonPath('codigo', 'limite');
        Mail::assertSentCount(2);
    }

    public function test_codigo_de_otro_navegador_no_sirve(): void
    {
        Mail::fake();
        $this->bloquear();
        $this->postJson('/api/login/verificar', ['codigo' => '123456'])->assertStatus(410);
    }

    public function test_cuenta_bloqueada_con_sesion_abierta_vuelve_a_pedir_login(): void
    {
        $this->login()->assertOk();
        $this->usuario->forceFill(['estado' => Usuario::BLOQUEADO])->save();
        $this->app['auth']->forgetGuards();

        [$url] = $this->urlAutorizacion();
        $this->getJson($url)->assertUnauthorized()->assertJsonPath('codigo', 'login_requerido');
    }

    public function test_ingreso_correcto_reinicia_el_contador_de_fallos(): void
    {
        $this->login('incorrecta');
        $this->login('incorrecta');
        $this->login()->assertOk();

        $this->assertSame(0, $this->usuario->fresh()->intentos_fallidos);
    }

    public function test_correo_inexistente_responde_igual_que_contrasena_errada(): void
    {
        $this->postJson('/api/login', ['email' => 'nadie@juarezcampos.net', 'password' => 'loquesea'])
            ->assertStatus(422)->assertJsonPath('codigo', 'credenciales')->assertJsonPath('message', SesionController::ERROR_GENERICO);
    }

    public function test_campos_vacios_explican_que_falta(): void
    {
        $this->postJson('/api/login', [])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Escribe tu correo electrónico.')
            ->assertJsonPath('errors.password.0', 'Escribe tu contraseña.');
    }

    public function test_limite_de_intentos_por_ip(): void
    {
        config(['identidad.login_por_minuto_ip' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/login', ['email' => "otro{$i}@juarezcampos.net", 'password' => 'x']);
        }
        $this->login()->assertStatus(429)->assertJsonPath('codigo', 'limite');
        $this->assertGuest();
        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::LIMITE_IP]);
    }

    public function test_refresh_token_renueva_y_rota(): void
    {
        $tokens = $this->ingresarYCanjear();

        $renovado = $this->post('/api/oauth/token', $this->credenciales([
            'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
        ]))->assertOk()->json();

        $this->assertNotSame($tokens['refresh_token'], $renovado['refresh_token']);
        [, $claims] = $this->verificarJwt($renovado['access_token']);
        $this->assertSame(['ADMIN'], $claims['roles']);

        // El refresh token anterior ya no sirve (rotación).
        $this->post('/api/oauth/token', $this->credenciales([
            'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
        ]))->assertStatus(400);
    }

    public function test_bloqueo_revoca_tokens_y_matricula_suspendida_impide_renovar(): void
    {
        $tokens = $this->ingresarYCanjear();
        Matricula::query()->update(['estado' => Matricula::SUSPENDIDA]);

        $this->post('/api/oauth/token', $this->credenciales([
            'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
        ]))->assertStatus(401)->assertJsonPath('error', 'access_denied');

        Matricula::query()->update(['estado' => Matricula::ACTIVA]);
        $this->postJson('/api/logout');
        $tokens = $this->ingresarYCanjear();
        Bloqueo::revocarTokens($this->usuario);

        $this->post('/api/oauth/token', $this->credenciales([
            'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
        ]))->assertStatus(400);
    }

    public function test_jwks_y_descubrimiento(): void
    {
        $jwks = $this->getJson('/api/jwks.json')->assertOk()->json('keys.0');
        $this->assertSame('RSA', $jwks['kty']);
        $this->assertSame('RS256', $jwks['alg']);
        $this->assertSame(Llaves::kid(), $jwks['kid']);

        $this->getJson('/api/.well-known/openid-configuration')->assertOk()
            ->assertJsonPath('issuer', config('identidad.issuer'))
            ->assertJsonPath('authorization_endpoint', config('identidad.issuer').'/authorize')
            ->assertJsonPath('token_endpoint', config('identidad.api_url').'/oauth/token')
            ->assertJsonPath('jwks_uri', config('identidad.api_url').'/jwks.json');
    }

    public function test_logout_cierra_sesion_y_solo_redirige_a_sistemas_registrados(): void
    {
        $this->ingresarYCanjear();

        $this->postJson('/api/logout', ['post_logout_redirect_uri' => 'https://istqb.juarezcampos.net/'])
            ->assertOk()->assertJsonPath('redirect_to', 'https://istqb.juarezcampos.net/');
        $this->assertGuest();
        $this->assertSame(0, Passport::token()->newQuery()->where('revoked', false)->count());

        $this->postJson('/api/logout', ['post_logout_redirect_uri' => 'https://atacante.example/'])
            ->assertOk()->assertJsonPath('redirect_to', null);
    }

    public function test_no_quedan_pantallas_web(): void
    {
        $this->get('/login')->assertNotFound();
        $this->get('/oauth/authorize')->assertNotFound();
        $this->get('/')->assertOk()->assertJsonPath('servicio', 'identidad');
    }

    // ----------------------------------------------------------------- auditoría de ingresos

    private const UA_CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    private const UA_SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    public function test_ingreso_registra_sesion_dispositivo_accesos_y_ultima_visita(): void
    {
        $this->withHeaders(['User-Agent' => self::UA_CHROME_WINDOWS]);
        $this->login(extra: ['zona_horaria' => 'America/Lima', 'idioma' => 'es-PE'])->assertOk();

        $sesion = SesionSso::sole();
        $this->assertSame('127.0.0.1', $sesion->ip);
        $this->assertSame('America/Lima', $sesion->zona_horaria);
        $this->assertSame('es-PE', $sesion->idioma);
        $this->assertNull($sesion->cerrada_en);
        $this->assertTrue($sesion->expira_en->isFuture());

        $dispositivo = $sesion->dispositivo;
        $this->assertSame('Chrome', $dispositivo->navegador);
        $this->assertSame('Windows', $dispositivo->sistema_operativo);
        $this->assertSame('escritorio', $dispositivo->tipo);

        $usuario = $this->usuario->fresh();
        $this->assertNotNull($usuario->ultimo_ingreso_en);
        $this->assertSame('127.0.0.1', $usuario->ultima_ip);

        $evento = EventoAuditoria::where('evento', EventoAuditoria::LOGIN_EXITOSO)->sole();
        $this->assertSame(EventoAuditoria::EXITO, $evento->resultado);
        $this->assertSame($sesion->id, $evento->sesion_id);
        $this->assertSame($dispositivo->id, $evento->dispositivo_id);
        $this->assertStringStartsWith('Chrome', $evento->navegador);
        $this->assertStringStartsWith('Windows', $evento->sistema_operativo);
        $this->assertSame('POST /api/login', $evento->metodo_ruta);

        // Primer ingreso al sistema (login) y uno posterior sin contraseña (sso).
        [$url] = $this->urlAutorizacion();
        $this->getJson($url)->assertOk();
        $this->getJson($url)->assertOk();
        $this->assertSame([Acceso::LOGIN, Acceso::SSO], Acceso::orderBy('id')->pluck('tipo')->all());
        $this->assertNotNull(Matricula::sole()->ultimo_acceso_en);
        $this->assertSame(2, EventoAuditoria::where('evento', EventoAuditoria::ACCESO_SISTEMA)->where('sesion_id', $sesion->id)->count());
    }

    public function test_dispositivo_nuevo_queda_registrado(): void
    {
        $this->withHeaders(['User-Agent' => self::UA_CHROME_WINDOWS]);
        $this->login()->assertOk();
        $this->postJson('/api/logout')->assertOk();

        $this->withHeaders(['User-Agent' => self::UA_SAFARI_IPHONE]);
        $this->login()->assertOk();

        $this->assertSame(2, Dispositivo::count());
        $movil = Dispositivo::where('tipo', 'movil')->sole();
        $this->assertSame('iOS', $movil->sistema_operativo);
        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::DISPOSITIVO_NUEVO, 'dispositivo_id' => $movil->id]);

        // Volver con un dispositivo conocido no genera aviso.
        $this->postJson('/api/logout');
        $this->login()->assertOk();
        $this->assertSame(1, EventoAuditoria::where('evento', EventoAuditoria::DISPOSITIVO_NUEVO)->count());
    }

    public function test_logout_cierra_la_sesion_sso_con_motivo(): void
    {
        $this->login()->assertOk();
        $this->postJson('/api/logout')->assertOk();

        $sesion = SesionSso::sole();
        $this->assertNotNull($sesion->cerrada_en);
        $this->assertSame(SesionSso::CIERRE_LOGOUT, $sesion->motivo_cierre);
    }

    public function test_bloqueo_cierra_las_sesiones_abiertas_del_usuario(): void
    {
        $this->login()->assertOk();
        for ($i = 0; $i < 10; $i++) {
            $this->login('incorrecta');
        }

        $sesion = SesionSso::sole();
        $this->assertSame(SesionSso::CIERRE_BLOQUEO, $sesion->motivo_cierre);
        $this->assertSame(Bloqueo::MOTIVO_INTENTOS, $this->usuario->fresh()->bloqueado_motivo);
        $this->getJson('/api/sesion')->assertUnauthorized();
    }

    public function test_sesion_sso_vencida_vuelve_a_pedir_login(): void
    {
        $this->login()->assertOk();
        SesionSso::query()->update(['expira_en' => now()->subMinute()]);

        [$url] = $this->urlAutorizacion();
        $this->getJson($url)->assertUnauthorized()->assertJsonPath('codigo', 'login_requerido');
        $this->assertSame(SesionSso::CIERRE_EXPIRADA, SesionSso::sole()->motivo_cierre);
    }

    public function test_renovacion_de_token_queda_auditada(): void
    {
        $tokens = $this->ingresarYCanjear();
        $this->post('/api/oauth/token', $this->credenciales([
            'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
        ]))->assertOk();

        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::TOKEN_RENOVADO, 'sistema_id' => $this->istqb->id]);
    }

    public function test_zona_horaria_invalida_es_rechazada(): void
    {
        $this->login(extra: ['zona_horaria' => 'Marte/Olympus'])->assertStatus(422)->assertJsonValidationErrors('zona_horaria');
        $this->assertGuest();
    }

    public function test_depuracion_conserva_solo_los_ultimos_12_meses(): void
    {
        $this->login()->assertOk();
        EventoAuditoria::query()->update(['creado_en' => now()->subMonths(13)]);
        SesionSso::query()->update(['cerrada_en' => now()->subMonths(13)]);
        $reciente = EventoAuditoria::create(['evento' => EventoAuditoria::LOGOUT, 'resultado' => EventoAuditoria::INFO]);

        $this->artisan('identidad:depurar-auditoria')->assertSuccessful();

        $this->assertSame([$reciente->id], EventoAuditoria::pluck('id')->all());
        $this->assertSame(0, SesionSso::count());
    }

    // ----------------------------------------------------------------- utilidades

    private function bloquear(): void
    {
        $this->usuario->forceFill(['estado' => Usuario::BLOQUEADO, 'bloqueado_en' => now(), 'intentos_fallidos' => 10])->save();
    }

    private function login(string $password = self::CLAVE, array $extra = [])
    {
        return $this->postJson('/api/login', ['email' => 'ana@juarezcampos.net', 'password' => $password] + $extra);
    }

    /** @return array{0: string, 1: string} URL de /api/authorize y code_verifier */
    private function urlAutorizacion(array $extra = []): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $query = http_build_query(array_merge([
            'client_id' => $this->istqb->oauth_client_id,
            'redirect_uri' => self::CALLBACK,
            'response_type' => 'code',
            'scope' => '',
            'state' => 'estado-123',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], $extra));

        return ['/api/authorize?'.$query, $verifier];
    }

    private function ingresarYCanjear(): array
    {
        [$url, $verifier] = $this->urlAutorizacion();

        $this->getJson($url)->assertUnauthorized();
        $this->login()->assertOk();

        $destino = $this->getJson($url)->assertOk()->json('redirect_to');
        $this->assertStringStartsWith(self::CALLBACK.'?', $destino);
        parse_str(parse_url($destino, PHP_URL_QUERY), $params);
        $this->assertSame('estado-123', $params['state']);

        return $this->post('/api/oauth/token', $this->credenciales([
            'grant_type' => 'authorization_code',
            'redirect_uri' => self::CALLBACK,
            'code' => $params['code'],
            'code_verifier' => $verifier,
        ]))->assertOk()->json();
    }

    private function credenciales(array $datos): array
    {
        return $datos + ['client_id' => $this->istqb->oauth_client_id, 'client_secret' => $this->secreto];
    }

    /** Verifica la firma con la llave publicada en JWKS, como lo hará cada sistema cliente. */
    private function verificarJwt(string $jwt): array
    {
        [$h, $p, $s] = explode('.', $jwt);
        $decode = fn (string $v) => base64_decode(strtr($v, '-_', '+/'));

        $pem = file_get_contents(Passport::keyPath('oauth-public.key'));
        $this->assertSame(Llaves::jwk()['n'], rtrim(strtr(base64_encode(openssl_pkey_get_details(openssl_pkey_get_public($pem))['rsa']['n']), '+/', '-_'), '='));
        $this->assertSame(1, openssl_verify("$h.$p", $decode($s), $pem, OPENSSL_ALGO_SHA256), 'Firma RS256 inválida');

        return [json_decode($decode($h), true), json_decode($decode($p), true)];
    }
}
