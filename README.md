# Identidad Juarez Campos

Proveedor de identidad central (`identidad.juarezcampos.net`). Autentica a los usuarios una sola vez (SSO), verifica que estén matriculados en el sistema que piden y emite un JWT RS256 que cada sistema valida con la llave pública.

Laravel 13 + Laravel Passport 13 · OAuth 2.0 Authorization Code + PKCE.

## Endpoints

| Ruta | Uso |
|---|---|
| `GET /oauth/authorize` | Inicio del flujo. Muestra login si no hay sesión; si el usuario no está matriculado responde 403. |
| `POST /oauth/token` | Servidor a servidor: canjea el código o el refresh token por el access token. |
| `GET /.well-known/jwks.json` | Llave pública (JWK) para verificar la firma. |
| `GET /.well-known/openid-configuration` | Documento de descubrimiento. |
| `GET /logout?post_logout_redirect_uri=…` | Cierra la sesión SSO y revoca los tokens del usuario. |
| `GET/POST /login` | Formulario de ingreso. |

## Contenido del JWT

```json
{ "iss": "https://identidad.juarezcampos.net", "aud": "ISTQB", "sub": "<uuid del usuario>",
  "roles": ["ADMIN"], "name": "…", "email": "…", "exp": "<+15 min>", "jti": "…" }
```

Cabecera: `alg: RS256` y `kid` (huella RFC 7638 de la llave; cambia sola al rotarla).

## Reglas de seguridad

- La cuenta se bloquea al 10.º fallo de contraseña consecutivo y se revocan sus tokens. Solo un administrador la desbloquea.
- Límite de 20 intentos de login por IP y por minuto.
- El mensaje de error no revela si el correo existe.
- Cada emisión de token (canje y renovación) vuelve a comprobar que la cuenta esté activa y la matrícula vigente.
- Los refresh tokens rotan en cada uso.
- Todo queda en `eventos_auditoria`: `login_exitoso`, `login_fallido`, `cuenta_bloqueada`, `ingreso_cuenta_bloqueada`, `cuenta_desbloqueada`, `acceso_denegado`, `limite_ip`, `token_emitido`, `logout`.

## Puesta en marcha local

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan passport:keys
php artisan migrate
php artisan serve --port=8001
```

## Administración por consola (hasta que exista el panel)

```bash
php artisan identidad:usuario admin@juarezcampos.net "Nombre Apellido"
php artisan identidad:sistema ISTQB "Simulador ISTQB" --url=https://istqb.juarezcampos.net --redirect=https://istqb.juarezcampos.net/callback
php artisan identidad:rol ISTQB ADMIN Administrador
php artisan identidad:matricular admin@juarezcampos.net ISTQB ADMIN
php artisan identidad:desbloquear admin@juarezcampos.net
```

`identidad:sistema` muestra el `client_secret` una sola vez: guárdalo en el `.env` del sistema cliente.

## Pruebas

Usan la base MySQL `identidad_test`.

```bash
php artisan test
```

## Producción

- `IDENTIDAD_ISSUER=https://identidad.juarezcampos.net` y `APP_URL` igual.
- Las llaves `storage/oauth-private.key` y `storage/oauth-public.key` no van al repositorio. Genéralas en el servidor con `php artisan passport:keys` o cárgalas en `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY`.
- Programar `php artisan schedule:run` cada minuto (purga diaria de tokens vencidos).
- `SESSION_SECURE_COOKIE=true` con HTTPS.
