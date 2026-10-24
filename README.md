# Identidad Juarez Campos

API del proveedor de identidad central (`identidad.juarezcampos.net`). Autentica a los usuarios una sola vez (SSO), verifica que estén matriculados en el sistema que piden y emite un JWT RS256 que cada sistema valida con la llave pública. Incluye la API de administración que usa el panel (`panel.juarezcampos.net`).

Laravel 13 + Laravel Passport 13 · OAuth 2.0 Authorization Code + PKCE. Las pantallas viven en `identidad-web` (login) y `panel-web` (administración).

Base de datos: MySQL `juarezc_panel`, con tablas en español (ver *Modelo de datos*).

## Endpoints

| Ruta | Uso |
|---|---|
| `GET /api/sesion` · `POST /api/login` · `POST /api/login/verificar` · `POST /api/logout` | Los usa identidad-web (cookie de sesión SSO + CSRF). `verificar` recibe el código de 6 dígitos enviado al correo. |
| `GET /api/metodos` · `GET /api/google/redirect` · `GET /api/google/callback` | Ingreso con Google (navegación completa, no XHR). Quien no tiene cuenta queda como solicitud **pendiente** hasta que un administrador la apruebe en el panel. |
| `GET /api/authorize` | Equivalente JSON de `/oauth/authorize`: devuelve a dónde volver con el código, o pide login, o responde "sin acceso". |
| `POST /api/oauth/token` | Canje del código o del refresh token. Clientes con secreto (ISTQB, FIRMA) o públicos con PKCE (PANEL). |
| `GET /api/jwks.json` · `GET /api/.well-known/openid-configuration` | Llave pública (JWK) y documento de descubrimiento. |
| `/api/admin/*` | API del panel: resumen, sistemas, roles, usuarios, matrículas y auditoría. Exige el JWT del sistema `PANEL` con rol `ADMIN`. |

## Contenido del JWT

```json
{ "iss": "https://identidad.juarezcampos.net", "aud": "ISTQB", "sub": "<uuid del usuario>",
  "roles": ["ADMIN"], "name": "…", "email": "…", "exp": "<+15 min>", "jti": "…" }
```

Cabecera: `alg: RS256` y `kid` (huella RFC 7638 de la llave; cambia sola al rotarla).

## Reglas de seguridad

- Tras 10 fallos de contraseña consecutivos la cuenta se bloquea y se revocan sus tokens y sesiones. **El atacante no se entera**: recibe siempre el mismo mensaje genérico.
- Si una cuenta bloqueada ingresa la contraseña correcta, se envía un **código de 6 dígitos al correo** (vence en 10 minutos, 5 intentos, máximo 5 envíos por hora y solo se guarda su hash). Con el código la cuenta se desbloquea y se completa el ingreso. Un administrador también puede desbloquearla desde el panel.
- **Ingreso con Google**: solo correos verificados por Google. Si el correo no existe, se crea la cuenta **pendiente** (deshabilitada hasta que un administrador la apruebe en el panel) y se avisa a los administradores; al aprobarla se avisa a la persona. Una cuenta **registrada en el sistema** (panel o consola) no entra con Google: debe usar su contraseña. Solo las cuentas nacidas con Google y aprobadas entran con Google. Configuración: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` (ver `despliegue/LEEME-cpanel.md`, sección 8).
- Límite de 20 intentos de login por IP y por minuto. El mensaje de error no revela si el correo existe.
- Cada emisión de token (canje y renovación) vuelve a comprobar cuenta activa y matrícula vigente. Los refresh tokens rotan en cada uso.
- **Errores**: ninguna respuesta incluye SQL, tablas, clases, motor de base de datos ni framework, aun con `APP_DEBUG=true` (`app/Exceptions/ErroresSeguros.php`). El usuario ve un mensaje claro y una referencia (`E-XXXXXXXX`); el detalle queda en `storage/logs` con esa referencia.
- CORS solo para los orígenes de `IDENTIDAD_CORS_ORIGENES` (el panel) y solo en `/api/admin/*`, `/api/oauth/token` y JWKS.

## Auditoría

Cada ingreso guarda IP, IP reenviada (`X-Forwarded-For`), navegador y versión, sistema operativo y versión, tipo de equipo, zona horaria e idioma del navegador:

- `sesiones_sso`: cada ingreso con contraseña, su dispositivo, última actividad y motivo de cierre (logout, expirada, revocada, bloqueo).
- `dispositivos`: equipos y navegadores conocidos por usuario (avisa con `dispositivo_nuevo`).
- `accesos`: cada entrada a un sistema (`login` o `sso`); `matriculas.ultimo_acceso_en` guarda la última visita a cada sistema.
- `eventos_auditoria`: todo evento con su resultado y, en acciones del panel, el administrador (`actor_id`).

Se conserva 12 meses (`IDENTIDAD_RETENCION_AUDITORIA_MESES`); `identidad:depurar-auditoria` corre a diario.

## Puesta en marcha local

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan passport:keys
php artisan migrate
php artisan serve --port=8001
```

Requiere las extensiones PHP `sodium`, `openssl` y `pdo_mysql`. En local el correo va al log (`MAIL_MAILER=log`): el código de 6 dígitos aparece en `storage/logs/laravel.log`.

## Administración por consola

Lo normal es usar el panel. Para el primer administrador o una emergencia:

```bash
php artisan identidad:usuario admin@juarezcampos.net "Nombres" "Apellidos"
php artisan identidad:sistema PANEL "Panel de administración" --publico --url=https://panel.juarezcampos.net --redirect=https://panel.juarezcampos.net/callback
php artisan identidad:rol PANEL ADMIN Administrador
php artisan identidad:matricular admin@juarezcampos.net PANEL ADMIN
php artisan identidad:sistema FIRMA "Firma digital" --url=https://firma.juarezcampos.net --redirect=https://firma.juarezcampos.net/callback
php artisan identidad:desbloquear admin@juarezcampos.net
```

`identidad:sistema` muestra el `client_secret` una sola vez (salvo `--publico`, que no tiene secreto): guárdalo en el `.env` del sistema cliente.

## Pruebas

Usan la base MySQL `identidad_test`.

```bash
php artisan test
```

## Producción

Paquetes para cPanel de todo el ecosistema (identidad, panel, ISTQB y firma), con su `.sql` y guía paso a paso:

```bash
ADMIN_CORREO=admin@juarezcampos.net ADMIN_NOMBRES="Nombres" ADMIN_APELLIDOS="Apellidos" \
  bash identidad/despliegue/empaquetar-cpanel.sh
```

Genera `paquetes/cpanel-<fecha>/` junto a los repos (nunca dentro de uno: lleva secretos). Ver `despliegue/LEEME-cpanel.md`.

- `APP_DEBUG=false`, `IDENTIDAD_ISSUER=https://identidad.juarezcampos.net`, `IDENTIDAD_API_URL=https://identidad.juarezcampos.net/api`, `IDENTIDAD_CORS_ORIGENES=https://panel.juarezcampos.net`.
- **Correo SMTP** (`MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`) para enviar el código de desbloqueo. Una cuenta de correo de cPanel sirve.
- Las llaves `storage/oauth-private.key` y `storage/oauth-public.key` no van al repositorio. Genéralas en el servidor con `php artisan passport:keys` o cárgalas en `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY`.
- Programar `php artisan schedule:run` cada minuto (purga de tokens y de auditoría).
- `SESSION_SECURE_COOKIE=true` con HTTPS.
