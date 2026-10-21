# Despliegue en cPanel — identidad, panel, ISTQB y firma

Paquetes generados con `identidad/despliegue/empaquetar-cpanel.sh`. Usuario de cPanel: `juarezc`.
Todo se sube a la carpeta personal `/home/juarezc/` (no dentro de `public_html`, salvo ISTQB que ya vive ahí).

| Archivo | Qué contiene | Dónde va |
| --- | --- | --- |
| `1-identidad-identidad.juarezcampos.net.zip` | `identidad.juarezcampos.net/` (pantalla de login + `laravel.php`) y `identidad-api/` (Laravel con `vendor/`, `.env`, llaves RSA) | `/home/juarezc/` |
| `2-panel-panel.juarezcampos.net.zip` | `panel.juarezcampos.net/` (panel + `config.json`) | `/home/juarezc/` |
| `3-istqb-juarezcampos.net.zip` | `public_html/istqb/` (front) e `istqb-api-app/` (código, **sin** `.env` ni `storage/`) | `/home/juarezc/` |
| `4-firma-firma.juarezcampos.net.zip` | `firma.juarezcampos.net/` y `firma-api/` (código, **sin** `.env`, base ni `storage/`) | `/home/juarezc/` |
| `sql/juarezc_panel.sql` | Tablas de identidad + sistemas PANEL, ISTQB, FIRMA + roles + tu usuario administrador | phpMyAdmin → base `juarezc_panel` |
| `sql/istqb-agregar-identidad.sql` | Columna `identidad_sub` en `users` | phpMyAdmin → base `juarezc_istqb_exam` |
| `env/*.env-agregar` | Líneas `IDENTIDAD_*` (con el secreto) para los `.env` que ya están en el servidor | se pegan en cada `.env` |
| `CREDENCIALES.txt` | Tu contraseña inicial y los secretos | **solo para ti**: no lo subas a ningún lado |

> `identidad-api/.env` dentro del zip 1 trae `APP_KEY` y las llaves que firman los tokens:
> trata ese `.zip` como confidencial y bórralo del servidor después de extraerlo.

## Orden recomendado

Identidad primero (los demás dependen de ella), luego el panel, y al final ISTQB y firma.

### 1. Identidad — https://identidad.juarezcampos.net

1. **Dominios → Crear un nuevo dominio**: `identidad.juarezcampos.net`, raíz `/home/juarezc/identidad.juarezcampos.net`
   (desmarca «Compartir la raíz con el dominio principal»).
2. **MultiPHP Manager**: PHP 8.3. **Seleccionar versión de PHP → Extensiones**: `pdo_mysql`, `mbstring`, `openssl`,
   `sodium`, `curl`, `intl`, `fileinfo`, `tokenizer`. Opción `expose_php = Off`.
3. **Bases de datos MySQL**: crea la base `juarezc_panel` y un usuario (por ejemplo `juarezc_panel`) con todos los privilegios sobre ella.
4. **phpMyAdmin** → base `juarezc_panel` → **Importar** → `sql/juarezc_panel.sql`.
5. **Cuentas de correo**: crea `no-responder@juarezcampos.net` (envía el código de 6 dígitos de desbloqueo).
6. **Administrador de archivos** → `/home/juarezc/` → Cargar el zip 1 → Extraer.
7. Edita `/home/juarezc/identidad-api/.env` (activa «Mostrar archivos ocultos») y completa:
   `DB_USERNAME`, `DB_PASSWORD` y `MAIL_PASSWORD`. Si tu servidor de correo no es `mail.juarezcampos.net:465`, ajusta `MAIL_HOST`/`MAIL_PORT`.
8. **SSL/TLS Status → Run AutoSSL** para el subdominio.
9. **Cron Jobs** (cada minuto): `cd /home/juarezc/identidad-api && php artisan schedule:run >> /dev/null 2>&1`
   (purga tokens vencidos y la auditoría de más de 12 meses).

Comprobar:

| Prueba | Esperado |
| --- | --- |
| `https://identidad.juarezcampos.net/login` | Pantalla de ingreso |
| `https://identidad.juarezcampos.net/api/jwks.json` | JSON con la llave pública |
| `https://identidad.juarezcampos.net/api/xyz` | `{"codigo":"error_solicitud","message":"No encontramos lo que buscas."}` sin datos del servidor |
| `https://identidad.juarezcampos.net/.env` | 403 |

### 2. Panel — https://panel.juarezcampos.net

1. Crea el dominio `panel.juarezcampos.net` con raíz `/home/juarezc/panel.juarezcampos.net` y corre AutoSSL.
2. Sube y extrae el zip 2 en `/home/juarezc/`. `config.json` ya trae el `client_id` de PANEL.
3. Abre el panel e ingresa con tu correo y la contraseña de `CREDENCIALES.txt`.
   **Cámbiala de inmediato**: Usuarios → tu usuario → Cambiar contraseña.

### 3. ISTQB — https://juarezcampos.net/istqb

1. **Respaldo** (antes de tocar nada): descarga `/home/juarezc/istqb-api-app/.env` y exporta `juarezc_istqb_exam` desde phpMyAdmin.
2. **phpMyAdmin** → `juarezc_istqb_exam` → Importar `sql/istqb-agregar-identidad.sql` (una sola vez).
   Con Terminal es lo mismo que `cd ~/istqb-api-app && php artisan migrate --force`.
3. Sube el zip 3 a `/home/juarezc/` y extrae: reemplaza el contenido de `public_html/istqb/` y el código de
   `istqb-api-app/`. **No** toca `.env` ni `storage/` (imágenes de preguntas y logs).
4. Pega al final de `/home/juarezc/istqb-api-app/.env` el contenido de `env/istqb-api-app.env-agregar`.
5. Con Terminal: `cd ~/istqb-api-app && php artisan optimize:clear`. Sin Terminal: borra los archivos de
   `istqb-api-app/bootstrap/cache/` excepto `.gitignore`.
6. Prueba `https://juarezcampos.net/istqb/admin`: debe llevarte a identidad y volver al panel de ISTQB.
   El simulacro público sigue sin pedir ingreso.

### 4. Firma — https://firma.juarezcampos.net

1. **Respaldo**: descarga `firma-api/.env`, `firma-api/database/database.sqlite` y `firma-api/storage/app/private/`.
2. Sube el zip 4 a `/home/juarezc/` y extrae: reemplaza el front y el código de `firma-api/`.
   **No** toca `.env`, la base SQLite ni `storage/` (PDFs y firmas). El instalador del agente va incluido en `descargas/`.
3. En `/home/juarezc/firma-api/.env` borra las líneas `ADMIN_CLAVE`, `ADMIN_SESION_MINUTOS`, `ADMIN_INTENTOS`,
   `ADMIN_BLOQUEO_MINUTOS`, `ADMIN_INTENTOS_GLOBALES` y pega el contenido de `env/firma-api.env-agregar`.
4. Limpia `firma-api/bootstrap/cache/` (o `php artisan optimize:clear`).
5. Prueba `https://firma.juarezcampos.net/historial`: debe llevarte a identidad. Firmar y Verificar siguen públicos.

## Dar acceso a más personas

Todo desde el panel: **Usuarios → Nuevo usuario** y en su ficha **Acceso a sistemas** → sistema + rol.
Sin acceso a un sistema, el usuario no entra aunque su contraseña sea correcta.

## Si algo falla

Los errores nunca muestran detalles técnicos: el usuario ve un mensaje y una referencia como `E-7KQ2M9XA`.
Búscala en `identidad-api/storage/logs/laravel.log` (o en `istqb-api-app/…` / `firma-api/…`) para ver el detalle.
