<?php

namespace Tests\Feature;

use App\Models\EventoAuditoria;
use App\Models\Matricula;
use App\Models\Rol;
use App\Models\Sistema;
use App\Models\User;
use App\Support\Bloqueo;
use App\Support\Llaves;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

class FlujoIdentidadTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://istqb.juarezcampos.net/callback';

    private Sistema $istqb;
    private string $secreto;
    private User $usuario;

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

        $this->usuario = User::create(['name' => 'Ana Pérez', 'email' => 'ana@juarezcampos.net', 'password' => 'Clave-Segura-123']);
        Matricula::create(['user_id' => $this->usuario->id, 'sistema_id' => $this->istqb->id, 'rol_id' => $admin->id]);
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

        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::LOGIN_EXITOSO, 'user_id' => $this->usuario->id]);
        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::TOKEN_EMITIDO, 'sistema_id' => $this->istqb->id]);
    }

    public function test_con_sesion_sso_activa_no_vuelve_a_pedir_contrasena(): void
    {
        $this->ingresarYCanjear();

        [$url] = $this->urlAutorizacion();
        $respuesta = $this->get($url);

        $respuesta->assertRedirect();
        $this->assertStringStartsWith(self::CALLBACK.'?code=', $respuesta->headers->get('Location'));
    }

    public function test_usuario_sin_matricula_ve_acceso_denegado_y_no_recibe_codigo(): void
    {
        Matricula::query()->delete();

        [$url] = $this->urlAutorizacion();
        $this->get($url)->assertRedirect('/login');
        $this->post('/login', ['email' => 'ana@juarezcampos.net', 'password' => 'Clave-Segura-123'])->assertRedirect($url);

        $this->get($url)->assertStatus(403)->assertSee('No tienes acceso a Simulador ISTQB');
        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::ACCESO_DENEGADO, 'user_id' => $this->usuario->id]);
    }

    public function test_redirect_uri_no_registrada_es_rechazada(): void
    {
        [$url] = $this->urlAutorizacion(['redirect_uri' => 'https://atacante.example/callback']);
        $respuesta = $this->get($url);

        $this->assertGreaterThanOrEqual(400, $respuesta->getStatusCode());
        $this->assertStringNotContainsString('atacante.example', (string) $respuesta->headers->get('Location'));
    }

    public function test_cuenta_se_bloquea_al_decimo_fallo_y_solo_se_desbloquea_por_administrador(): void
    {
        for ($i = 1; $i <= 9; $i++) {
            $this->post('/login', ['email' => 'ana@juarezcampos.net', 'password' => 'incorrecta'])
                ->assertSessionHasErrors(['email' => 'Correo o contraseña incorrectos.']);
        }
        $this->assertSame(User::ACTIVO, $this->usuario->fresh()->estado);
        $this->assertSame(9, $this->usuario->fresh()->intentos_fallidos);

        $this->post('/login', ['email' => 'ana@juarezcampos.net', 'password' => 'incorrecta'])
            ->assertSessionHasErrors(['email' => 'Tu cuenta fue bloqueada por intentos fallidos. Contacta al administrador.']);
        $this->assertSame(User::BLOQUEADO, $this->usuario->fresh()->estado);
        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::CUENTA_BLOQUEADA, 'user_id' => $this->usuario->id]);

        // Ni con la contraseña correcta puede entrar.
        $this->post('/login', ['email' => 'ana@juarezcampos.net', 'password' => 'Clave-Segura-123'])
            ->assertSessionHasErrors(['email' => 'Tu cuenta está bloqueada por intentos fallidos. Contacta al administrador.']);
        $this->assertGuest();

        $this->artisan('identidad:desbloquear', ['email' => 'ana@juarezcampos.net'])->assertSuccessful();
        $this->assertSame(0, $this->usuario->fresh()->intentos_fallidos);

        $this->post('/login', ['email' => 'ana@juarezcampos.net', 'password' => 'Clave-Segura-123'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->usuario->fresh());
    }

    public function test_ingreso_correcto_reinicia_el_contador_de_fallos(): void
    {
        $this->post('/login', ['email' => 'ana@juarezcampos.net', 'password' => 'incorrecta']);
        $this->post('/login', ['email' => 'ana@juarezcampos.net', 'password' => 'incorrecta']);
        $this->post('/login', ['email' => 'ana@juarezcampos.net', 'password' => 'Clave-Segura-123']);

        $this->assertSame(0, $this->usuario->fresh()->intentos_fallidos);
    }

    public function test_correo_inexistente_responde_igual_que_contrasena_errada(): void
    {
        $this->post('/login', ['email' => 'nadie@juarezcampos.net', 'password' => 'loquesea'])
            ->assertSessionHasErrors(['email' => 'Correo o contraseña incorrectos.']);
    }

    public function test_limite_de_intentos_por_ip(): void
    {
        config(['identidad.login_por_minuto_ip' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['email' => "otro{$i}@juarezcampos.net", 'password' => 'x']);
        }
        $this->post('/login', ['email' => 'ana@juarezcampos.net', 'password' => 'Clave-Segura-123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::LIMITE_IP]);
    }

    public function test_refresh_token_renueva_y_rota(): void
    {
        $tokens = $this->ingresarYCanjear();

        $renovado = $this->post('/oauth/token', $this->credenciales([
            'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
        ]))->assertOk()->json();

        $this->assertNotSame($tokens['refresh_token'], $renovado['refresh_token']);
        [, $claims] = $this->verificarJwt($renovado['access_token']);
        $this->assertSame(['ADMIN'], $claims['roles']);

        // El refresh token anterior ya no sirve (rotación).
        $this->post('/oauth/token', $this->credenciales([
            'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
        ]))->assertStatus(400);
    }

    public function test_bloqueo_revoca_tokens_y_matricula_suspendida_impide_renovar(): void
    {
        $tokens = $this->ingresarYCanjear();
        Matricula::query()->update(['estado' => Matricula::SUSPENDIDA]);

        $this->post('/oauth/token', $this->credenciales([
            'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
        ]))->assertStatus(401)->assertJsonPath('error', 'access_denied');

        Matricula::query()->update(['estado' => Matricula::ACTIVA]);
        $this->get('/logout');
        $tokens = $this->ingresarYCanjear();
        Bloqueo::revocarTokens($this->usuario);

        $this->post('/oauth/token', $this->credenciales([
            'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
        ]))->assertStatus(400);
    }

    public function test_jwks_y_descubrimiento(): void
    {
        $jwks = $this->get('/.well-known/jwks.json')->assertOk()->json('keys.0');
        $this->assertSame('RSA', $jwks['kty']);
        $this->assertSame('RS256', $jwks['alg']);
        $this->assertSame(Llaves::kid(), $jwks['kid']);

        $this->get('/.well-known/openid-configuration')->assertOk()
            ->assertJsonPath('issuer', config('identidad.issuer'))
            ->assertJsonPath('jwks_uri', config('identidad.issuer').'/.well-known/jwks.json');
    }

    public function test_logout_cierra_sesion_y_solo_redirige_a_sistemas_registrados(): void
    {
        $this->ingresarYCanjear();

        $this->get('/logout?post_logout_redirect_uri='.urlencode('https://istqb.juarezcampos.net/'))
            ->assertRedirect('https://istqb.juarezcampos.net/');
        $this->assertGuest();
        $this->assertSame(0, Passport::token()->newQuery()->where('revoked', false)->count());

        $this->get('/logout?post_logout_redirect_uri='.urlencode('https://atacante.example/'))
            ->assertOk()->assertSee('Cerraste sesión');
    }

    // ----------------------------------------------------------------- utilidades

    /** @return array{0: string, 1: string} URL de /oauth/authorize y code_verifier */
    private function urlAutorizacion(array $extra = []): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $params = array_merge([
            'client_id' => $this->istqb->oauth_client_id,
            'redirect_uri' => self::CALLBACK,
            'response_type' => 'code',
            'scope' => '',
            'state' => 'estado-123',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], $extra);
        ksort($params); // Laravel guarda la URL "intended" con los parámetros ordenados.

        return ['/oauth/authorize?'.http_build_query($params), $verifier];
    }

    private function ingresarYCanjear(): array
    {
        [$url, $verifier] = $this->urlAutorizacion();

        $this->get($url)->assertRedirect('/login');
        $this->post('/login', ['email' => 'ana@juarezcampos.net', 'password' => 'Clave-Segura-123'])->assertRedirect($url);

        $location = $this->get($url)->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::CALLBACK.'?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $params);
        $this->assertSame('estado-123', $params['state']);

        return $this->post('/oauth/token', $this->credenciales([
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
