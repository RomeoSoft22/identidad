<?php

namespace Tests\Feature;

use App\Models\EventoAuditoria;
use App\Models\Matricula;
use App\Models\Rol;
use App\Models\Sistema;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

/**
 * /api/admin/*: solo con el JWT del sistema PANEL y rol ADMIN vigente.
 * El panel es un cliente público (SPA con PKCE, sin secreto).
 */
class AdministracionPanelTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://panel.juarezcampos.net/callback';

    private const CLAVE = 'Clave-Segura-123';

    private Sistema $panel;

    private Usuario $admin;

    private Rol $rolAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $cliente = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Panel', [self::CALLBACK], confidential: false);
        $this->panel = Sistema::create(['codigo' => 'PANEL', 'nombre' => 'Panel', 'url_base' => 'https://panel.juarezcampos.net', 'oauth_client_id' => $cliente->id]);
        $this->rolAdmin = Rol::create(['sistema_id' => $this->panel->id, 'codigo' => 'ADMIN', 'nombre' => 'Administrador']);

        $this->admin = Usuario::create(['nombres' => 'Romeo', 'apellidos' => 'Juárez', 'correo' => 'admin@juarezcampos.net', 'contrasena' => self::CLAVE]);
        Matricula::create(['usuario_id' => $this->admin->id, 'sistema_id' => $this->panel->id, 'rol_id' => $this->rolAdmin->id]);
    }

    public function test_sin_token_o_con_token_ajeno_no_hay_acceso(): void
    {
        $this->getJson('/api/admin/resumen')->assertUnauthorized();
        $this->withToken('no-es-un-jwt')->getJson('/api/admin/resumen')->assertUnauthorized();
    }

    public function test_usuario_sin_rol_admin_en_panel_recibe_403(): void
    {
        $lector = Rol::create(['sistema_id' => $this->panel->id, 'codigo' => 'LECTOR', 'nombre' => 'Lector']);
        $jwt = $this->tokenPanel();
        Matricula::query()->update(['rol_id' => $lector->id]);

        $this->withToken($jwt)->getJson('/api/admin/resumen')->assertForbidden();
    }

    public function test_panel_publico_canjea_con_pkce_sin_secreto_y_ve_el_resumen(): void
    {
        $this->withToken($this->tokenPanel())->getJson('/api/admin/resumen')->assertOk()
            ->assertJsonPath('admin.correo', 'admin@juarezcampos.net')
            ->assertJsonPath('usuarios', 1);
    }

    public function test_crear_sistema_rol_usuario_y_matricularlo(): void
    {
        $jwt = $this->tokenPanel();

        $sistema = $this->withToken($jwt)->postJson('/api/admin/sistemas', [
            'codigo' => 'firma', 'nombre' => 'Firma digital', 'url_base' => 'https://firma.juarezcampos.net',
            'redirect_uris' => ['https://firma.juarezcampos.net/callback'], 'confidencial' => true,
        ])->assertCreated()->assertJsonPath('data.codigo', 'FIRMA')->json();
        $this->assertSame(40, strlen($sistema['client_secret']));

        $rol = $this->withToken($jwt)->postJson("/api/admin/sistemas/{$sistema['data']['id']}/roles", ['codigo' => 'ADMIN', 'nombre' => 'Administrador'])
            ->assertCreated()->json('data');

        $usuario = $this->withToken($jwt)->postJson('/api/admin/usuarios', [
            'nombres' => 'Lucía', 'apellidos' => 'Campos', 'correo' => 'Lucia@JuarezCampos.net', 'contrasena' => 'Otra-Clave-2026',
        ])->assertCreated()->assertJsonPath('data.correo', 'lucia@juarezcampos.net')->json('data');

        $this->withToken($jwt)->postJson('/api/admin/matriculas', ['usuario_id' => $usuario['id'], 'rol_id' => $rol['id']])->assertCreated();

        $this->withToken($jwt)->getJson("/api/admin/usuarios/{$usuario['id']}")->assertOk()
            ->assertJsonPath('data.matriculas.0.sistema.codigo', 'FIRMA')
            ->assertJsonPath('data.matriculas.0.rol.codigo', 'ADMIN');

        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::MATRICULA_ASIGNADA, 'actor_id' => $this->admin->id]);
        $this->assertDatabaseHas('eventos_auditoria', ['evento' => EventoAuditoria::SISTEMA_CREADO, 'actor_id' => $this->admin->id]);
        $this->assertSame('Lucía Campos', Usuario::where('correo', 'lucia@juarezcampos.net')->sole()->nombreCompleto());
    }

    public function test_validaciones_y_protecciones(): void
    {
        $jwt = $this->tokenPanel();

        $this->withToken($jwt)->postJson('/api/admin/sistemas', ['codigo' => '1 malo', 'nombre' => 'X', 'redirect_uris' => ['no-es-url']])
            ->assertStatus(422)->assertJsonValidationErrors(['codigo', 'redirect_uris.0']);
        $this->withToken($jwt)->postJson('/api/admin/usuarios', ['nombres' => 'X', 'correo' => 'admin@juarezcampos.net', 'contrasena' => 'corta'])
            ->assertStatus(422)->assertJsonValidationErrors(['correo', 'contrasena']);

        // No se puede desactivar el panel ni quitarse el propio acceso de administrador.
        $this->withToken($jwt)->putJson("/api/admin/sistemas/{$this->panel->id}", ['activo' => false])->assertStatus(422);
        $propia = Matricula::sole();
        $this->withToken($jwt)->putJson("/api/admin/matriculas/{$propia->id}", ['estado' => Matricula::SUSPENDIDA])->assertStatus(422);
        $this->withToken($jwt)->putJson("/api/admin/usuarios/{$this->admin->id}", ['estado' => Usuario::BAJA])->assertStatus(422);

        // Un rol con matrículas no se elimina.
        $this->withToken($jwt)->deleteJson("/api/admin/roles/{$this->rolAdmin->id}")->assertStatus(422);
    }

    public function test_desbloquear_desde_el_panel_registra_al_administrador(): void
    {
        $jwt = $this->tokenPanel();
        $bloqueado = Usuario::create(['nombres' => 'Ana', 'correo' => 'ana@juarezcampos.net', 'contrasena' => self::CLAVE]);
        $bloqueado->forceFill(['estado' => Usuario::BLOQUEADO, 'intentos_fallidos' => 10])->save();

        $this->withToken($jwt)->postJson("/api/admin/usuarios/{$bloqueado->id}/desbloquear")->assertOk()->assertJsonPath('data.estado', Usuario::ACTIVO);
        $this->assertDatabaseHas('eventos_auditoria', [
            'evento' => EventoAuditoria::CUENTA_DESBLOQUEADA, 'usuario_id' => $bloqueado->id, 'actor_id' => $this->admin->id,
        ]);
    }

    public function test_token_revocado_por_logout_deja_de_servir(): void
    {
        $jwt = $this->tokenPanel();
        $this->withToken($jwt)->getJson('/api/admin/sistemas')->assertOk();

        $this->postJson('/api/logout')->assertOk();
        $this->withToken($jwt)->getJson('/api/admin/sistemas')->assertUnauthorized();
    }

    public function test_cors_solo_para_el_origen_del_panel(): void
    {
        config(['cors.allowed_origins' => ['https://panel.juarezcampos.net']]);

        $this->call('OPTIONS', '/api/admin/sistemas', server: [
            'HTTP_ORIGIN' => 'https://panel.juarezcampos.net', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeader('Access-Control-Allow-Origin', 'https://panel.juarezcampos.net');

        $otro = $this->call('OPTIONS', '/api/admin/sistemas', server: [
            'HTTP_ORIGIN' => 'https://atacante.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertNotSame('https://atacante.example', $otro->headers->get('Access-Control-Allow-Origin'));
    }

    /** Recorre el flujo real del panel: login en identidad, authorize con PKCE y canje sin secreto. */
    private function tokenPanel(): string
    {
        $verifier = Str::random(64);
        $query = http_build_query([
            'client_id' => $this->panel->oauth_client_id, 'redirect_uri' => self::CALLBACK, 'response_type' => 'code',
            'scope' => '', 'state' => 'e', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        $this->postJson('/api/login', ['email' => 'admin@juarezcampos.net', 'password' => self::CLAVE])->assertOk();
        $destino = $this->getJson('/api/authorize?'.$query)->assertOk()->json('redirect_to');
        parse_str(parse_url($destino, PHP_URL_QUERY), $params);

        return $this->post('/api/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $this->panel->oauth_client_id,
            'redirect_uri' => self::CALLBACK, 'code' => $params['code'], 'code_verifier' => $verifier,
        ])->assertOk()->json('access_token');
    }
}
