<?php

namespace Tests\Feature;

use App\Mail\AccesoAprobado;
use App\Mail\SolicitudAcceso;
use App\Models\EventoAuditoria;
use App\Models\Matricula;
use App\Models\Rol;
use App\Models\SesionSso;
use App\Models\Sistema;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as UsuarioGoogle;
use Mockery;
use Tests\TestCase;

class IngresoGoogleTest extends TestCase
{
    use RefreshDatabase;

    private const PANEL_CALLBACK = 'https://panel.juarezcampos.net/callback';

    private Sistema $panel;

    private Sistema $istqb;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google' => [
            'client_id' => 'cliente-google.apps.googleusercontent.com',
            'client_secret' => 'secreto-google',
            'redirect' => 'https://identidad.test/api/google/callback',
        ]]);

        $clientes = app(ClientRepository::class);
        $this->panel = Sistema::create(['codigo' => 'PANEL', 'nombre' => 'Panel', 'oauth_client_id' => $clientes->createAuthorizationCodeGrantClient('Panel', [self::PANEL_CALLBACK], confidential: false)->id]);
        $this->istqb = Sistema::create(['codigo' => 'ISTQB', 'nombre' => 'Simulador ISTQB', 'oauth_client_id' => $clientes->createAuthorizationCodeGrantClient('ISTQB', ['https://istqb.test/callback'])->id]);
        $adminPanel = Rol::create(['sistema_id' => $this->panel->id, 'codigo' => 'ADMIN', 'nombre' => 'Administrador']);
        Rol::create(['sistema_id' => $this->istqb->id, 'codigo' => 'GESTOR', 'nombre' => 'Gestor']);

        $this->admin = Usuario::create(['nombres' => 'Romeo', 'correo' => 'admin@juarezcampos.net', 'contrasena' => 'Clave-Segura-123']);
        Matricula::create(['usuario_id' => $this->admin->id, 'sistema_id' => $this->panel->id, 'rol_id' => $adminPanel->id]);
    }

    public function test_metodos_indica_si_google_esta_configurado(): void
    {
        $this->getJson('/api/metodos')->assertOk()->assertJsonPath('google', true);
        config(['services.google.client_id' => null]);
        $this->getJson('/api/metodos')->assertOk()->assertJsonPath('google', false);
    }

    public function test_redirect_va_a_google_y_guarda_solo_retornos_propios(): void
    {
        $r = $this->get('/api/google/redirect?retorno='.urlencode('/authorize?client_id=x&state=y'));
        $r->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth', $r->headers->get('Location'));
        $this->assertStringContainsString('client_id=cliente-google.apps.googleusercontent.com', $r->headers->get('Location'));
        $this->assertSame('/authorize?client_id=x&state=y', session('google_ingreso.retorno'));

        // Un retorno a otro dominio se descarta (evita redirecciones abiertas).
        $this->get('/api/google/redirect?retorno='.urlencode('https://atacante.example/login'));
        $this->assertSame('/login', session('google_ingreso.retorno'));
        $this->get('/api/google/redirect?retorno='.urlencode('//atacante.example'));
        $this->assertSame('/login', session('google_ingreso.retorno'));
    }

    public function test_correo_nuevo_queda_pendiente_y_avisa_a_los_administradores(): void
    {
        Mail::fake();
        $this->simularGoogle('g-111', 'lucia@gmail.com', 'Lucía', 'Campos');

        $this->get('/api/google/callback?code=c&state=s')->assertRedirect('/login?google=pendiente');
        $this->assertGuest();

        $lucia = Usuario::where('correo', 'lucia@gmail.com')->sole();
        $this->assertSame(Usuario::PENDIENTE, $lucia->estado);
        $this->assertSame(Usuario::ORIGEN_GOOGLE, $lucia->origen);
        $this->assertSame('g-111', $lucia->google_sub);
        $this->assertSame('Lucía Campos', $lucia->nombreCompleto());
        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::SOLICITUD_ACCESO, 'usuario_id' => $lucia->id]);
        Mail::assertSent(SolicitudAcceso::class, fn ($m) => $m->hasTo('admin@juarezcampos.net') && $m->correo === 'lucia@gmail.com');

        // Mientras siga pendiente no entra (ni con Google ni con contraseña).
        $this->get('/api/google/callback?code=c&state=s')->assertRedirect('/login?google=pendiente');
        $this->assertGuest();
        $this->assertSame(1, Usuario::where('correo', 'lucia@gmail.com')->count());
    }

    public function test_aprobado_desde_el_panel_entra_con_google_y_solo_a_sus_sistemas(): void
    {
        Mail::fake();
        $this->simularGoogle('g-222', 'ana@gmail.com', 'Ana', 'Pérez');
        $this->get('/api/google/callback?code=c&state=s');
        $ana = Usuario::where('correo', 'ana@gmail.com')->sole();

        // El administrador aprueba y asigna ISTQB/GESTOR en el mismo paso.
        $gestor = Rol::where('codigo', 'GESTOR')->sole();
        $this->withToken($this->tokenPanelAdmin())->postJson("/api/admin/usuarios/{$ana->id}/aprobar", ['roles' => [$gestor->id]])
            ->assertOk()->assertJsonPath('data.estado', Usuario::ACTIVO)->assertJsonPath('data.matriculas.0.sistema.codigo', 'ISTQB');
        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::USUARIO_APROBADO, 'usuario_id' => $ana->id, 'actor_id' => $this->admin->id]);
        Mail::assertSent(AccesoAprobado::class, fn ($m) => $m->hasTo('ana@gmail.com') && $m->sistemas === ['Simulador ISTQB']);
        $this->postJson('/api/logout');

        $this->simularGoogle('g-222', 'ana@gmail.com', 'Ana', 'Pérez');
        $this->get('/api/google/callback?code=c&state=s')->assertRedirect('/login');
        $this->assertAuthenticatedAs($ana->fresh());
        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::LOGIN_EXITOSO, 'usuario_id' => $ana->id]);
        $this->assertSame(['metodo' => 'google'], EventoAuditoria::where('evento', EventoAuditoria::LOGIN_EXITOSO)->where('usuario_id', $ana->id)->latest('id')->first()->detalle);
        $this->assertNotNull(SesionSso::where('usuario_id', $ana->id)->first());

        // Sin matrícula en PANEL: identidad responde "sin acceso" aunque haya entrado con Google.
        [$url] = $this->urlAutorizacion($this->panel);
        $this->getJson($url)->assertForbidden()->assertJsonPath('codigo', 'sin_acceso');
    }

    public function test_usuario_creado_en_el_panel_queda_vinculado_a_google_por_su_correo(): void
    {
        $this->simularGoogle('g-333', 'admin@juarezcampos.net', 'Romeo', null);

        $this->get('/api/google/callback?code=c&state=s')->assertRedirect('/login');
        $this->assertAuthenticatedAs($this->admin->fresh());
        $this->assertSame('g-333', $this->admin->fresh()->google_sub);
    }

    public function test_vuelve_a_la_pantalla_de_autorizacion_con_su_consulta(): void
    {
        $retorno = '/authorize?client_id=abc&state=xyz';
        $this->get('/api/google/redirect?retorno='.urlencode($retorno));
        $this->simularGoogle('g-333', 'admin@juarezcampos.net', 'Romeo', null);

        $this->get('/api/google/callback?code=c&state=s')->assertRedirect($retorno);
    }

    public function test_rechazos(): void
    {
        // Otra cuenta de Google con el mismo correo no se mezcla con la ya vinculada.
        $this->admin->forceFill(['google_sub' => 'g-original'])->save();
        $this->simularGoogle('g-otra', 'admin@juarezcampos.net', 'Romeo', null);
        $this->get('/api/google/callback?code=c&state=s')->assertRedirect('/login?google=error');
        $this->assertGuest();

        // Correo no verificado por Google.
        $this->simularGoogle('g-444', 'nuevo@gmail.com', 'Nuevo', null, verificado: false);
        $this->get('/api/google/callback?code=c&state=s')->assertRedirect('/login?google=correo_no_verificado');
        $this->assertDatabaseMissing('usuarios', ['correo' => 'nuevo@gmail.com']);

        // Cuenta bloqueada o de baja.
        $this->admin->forceFill(['google_sub' => 'g-555', 'estado' => Usuario::BLOQUEADO])->save();
        $this->simularGoogle('g-555', 'admin@juarezcampos.net', 'Romeo', null);
        $this->get('/api/google/callback?code=c&state=s')->assertRedirect('/login?google=bloqueada');
        $this->admin->forceFill(['estado' => Usuario::BAJA])->save();
        $this->get('/api/google/callback?code=c&state=s')->assertRedirect('/login?google=sin_acceso');
        $this->assertGuest();

        // El usuario canceló en la pantalla de Google.
        $this->get('/api/google/callback?error=access_denied')->assertRedirect('/login?google=cancelado');
    }

    public function test_rechazar_solicitud_desde_el_panel(): void
    {
        Mail::fake();
        $this->simularGoogle('g-666', 'spam@gmail.com', 'Spam', null);
        $this->get('/api/google/callback?code=c&state=s');
        $solicitud = Usuario::where('correo', 'spam@gmail.com')->sole();
        $jwt = $this->tokenPanelAdmin();

        $this->withToken($jwt)->getJson('/api/admin/resumen')->assertJsonPath('pendientes', 1);
        $this->withToken($jwt)->putJson("/api/admin/usuarios/{$solicitud->id}", ['estado' => Usuario::ACTIVO])->assertStatus(422);
        $this->withToken($jwt)->postJson("/api/admin/usuarios/{$solicitud->id}/rechazar")->assertOk()->assertJsonPath('data.estado', Usuario::BAJA);
        $this->withToken($jwt)->postJson("/api/admin/usuarios/{$solicitud->id}/aprobar")->assertStatus(422);

        $this->simularGoogle('g-666', 'spam@gmail.com', 'Spam', null);
        $this->get('/api/google/callback?code=c&state=s')->assertRedirect('/login?google=sin_acceso');
    }

    public function test_solicitud_pendiente_no_entra_con_contrasena(): void
    {
        $pendiente = Usuario::create(['nombres' => 'P', 'correo' => 'p@gmail.com', 'contrasena' => 'Clave-Segura-123']);
        $pendiente->forceFill(['estado' => Usuario::PENDIENTE])->save();

        $this->postJson('/api/login', ['email' => 'p@gmail.com', 'password' => 'Clave-Segura-123'])->assertStatus(422)->assertJsonPath('codigo', 'credenciales');
        $this->assertGuest();
    }

    // ----------------------------------------------------------------- utilidades

    private function simularGoogle(string $sub, string $correo, string $nombre, ?string $apellido, bool $verificado = true): void
    {
        $usuario = (new UsuarioGoogle)->setRaw([
            'sub' => $sub, 'email' => $correo, 'email_verified' => $verificado,
            'given_name' => $nombre, 'family_name' => $apellido, 'name' => trim($nombre.' '.$apellido),
        ])->map(['id' => $sub, 'email' => $correo, 'name' => trim($nombre.' '.$apellido)]);

        $proveedor = Mockery::mock(GoogleProvider::class);
        $proveedor->shouldReceive('user')->andReturn($usuario);
        // swap() reemplaza el simulacro anterior (varias simulaciones en una misma prueba).
        $fabrica = Mockery::mock(SocialiteFactory::class);
        $fabrica->shouldReceive('driver')->with('google')->andReturn($proveedor);
        Socialite::swap($fabrica);
    }

    /** JWT del panel del administrador (cliente público con PKCE), como en el flujo real. */
    private function tokenPanelAdmin(): string
    {
        $this->postJson('/api/logout');
        $this->postJson('/api/login', ['email' => 'admin@juarezcampos.net', 'password' => 'Clave-Segura-123'])->assertOk();
        [$url, $verifier] = $this->urlAutorizacion($this->panel);
        parse_str(parse_url($this->getJson($url)->assertOk()->json('redirect_to'), PHP_URL_QUERY), $p);

        return $this->post('/api/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $this->panel->oauth_client_id,
            'redirect_uri' => self::PANEL_CALLBACK, 'code' => $p['code'], 'code_verifier' => $verifier,
        ])->assertOk()->json('access_token');
    }

    /** @return array{0: string, 1: string} */
    private function urlAutorizacion(Sistema $sistema): array
    {
        $verifier = Str::random(64);

        return ['/api/authorize?'.http_build_query([
            'client_id' => $sistema->oauth_client_id, 'redirect_uri' => self::PANEL_CALLBACK, 'response_type' => 'code', 'scope' => '',
            'state' => 'e', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ]), $verifier];
    }
}
