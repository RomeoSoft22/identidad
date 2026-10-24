# Guía de despliegue en cPanel — identidad, panel, ISTQB y firma

Usuario de cPanel: `juarezc` · Carpeta personal: `/home/juarezc/`

Sigue los pasos **en orden** y marca cada casilla. Identidad va primero porque los demás sistemas inician sesión con ella.

---

## 0. Antes de empezar

- [ ] Ten a mano la carpeta del paquete (`paquetes/cpanel-<fecha>/`) con: los 4 `.zip`, `sql/`, `env/` y `CREDENCIALES.txt`.
- [ ] **Los `.sql`, los `env/*.env-agregar` y `CREDENCIALES.txt` de una misma carpeta van juntos** (los secretos de uno coinciden con los del otro). No mezcles archivos de paquetes distintos.
- [ ] En el Administrador de archivos de cPanel activa **Configuración → Mostrar archivos ocultos** (para ver `.env` y `.htaccess`).
- [ ] Respaldos (solo si ya tienes estos sistemas funcionando):
  - [ ] phpMyAdmin → `juarezc_istqb_exam` → **Exportar**.
  - [ ] Descarga `/home/juarezc/istqb-api-app/.env`.
  - [ ] Descarga `/home/juarezc/firma-api/.env`, `firma-api/database/database.sqlite` y `firma-api/storage/app/private/`.

---

## 1. Identidad — `https://identidad.juarezcampos.net`

### 1.1 Subdominio y PHP (una sola vez)
- [ ] **Dominios → Crear un nuevo dominio**: `identidad.juarezcampos.net`, raíz `/home/juarezc/identidad.juarezcampos.net`. Desmarca «Compartir la raíz con el dominio principal».
- [ ] **MultiPHP Manager**: `identidad.juarezcampos.net` → **PHP 8.3**.
- [ ] **Seleccionar versión de PHP → Extensiones**: marca `pdo_mysql`, `mbstring`, `openssl`, `sodium`, `curl`, `intl`, `fileinfo`. **Opciones**: `expose_php = Off`.
- [ ] **SSL/TLS Status → Run AutoSSL** (certificado HTTPS).

### 1.2 Base de datos `juarezc_panel`
- [ ] **Bases de datos MySQL**: crea la base `juarezc_panel`.
- [ ] Crea un usuario (por ejemplo `juarezc_panel`) con una contraseña larga y **agrégalo a la base con TODOS LOS PRIVILEGIOS**.
- [ ] **phpMyAdmin** → selecciona `juarezc_panel` (a la izquierda) → **Importar** → `sql/juarezc_panel.sql` → Continuar.
- [ ] Debe mostrar unas 21 tablas (`usuarios`, `sistemas`, `roles`, `matriculas`, `oauth_clients`…).

> **¿Hay que borrarla para volver a empezar?** No es necesario si la importación terminó bien.
> Si quieres empezar de cero: phpMyAdmin → `juarezc_panel` → marca **todas** las tablas → «Eliminar» → vuelve a importar **el mismo** `juarezc_panel.sql`.
> Si generas un paquete nuevo, importa el `.sql` de ese paquete nuevo (sus secretos cambian).

### 1.3 Correo para el código de desbloqueo
- [ ] **Cuentas de correo**: crea `no-responder@juarezcampos.net` (anota su contraseña).
- [ ] En **Conectar dispositivos** de esa cuenta revisa el servidor SMTP y el puerto SSL (normalmente `mail.juarezcampos.net`, puerto `465`).

### 1.4 Subir los archivos
- [ ] **Administrador de archivos** → entra a `/home/juarezc/` (no a `public_html`).
- [ ] **Cargar** `1-identidad-identidad.juarezcampos.net.zip` → clic derecho → **Extraer** en `/home/juarezc/`.
- [ ] Quedan dos carpetas: `identidad.juarezcampos.net/` (pública) e `identidad-api/` (privada).
- [ ] Borra el `.zip` del servidor.

### 1.5 Completar `identidad-api/.env`
Edita `/home/juarezc/identidad-api/.env` y cambia **solo** estas líneas:

| Línea | Valor |
| --- | --- |
| `DB_USERNAME=` | el usuario MySQL del paso 1.2 (ej. `juarezc_panel`) |
| `DB_PASSWORD=` | su contraseña |
| `MAIL_PASSWORD=` | la contraseña de `no-responder@juarezcampos.net` |
| `MAIL_HOST=` / `MAIL_PORT=` | solo si en 1.3 viste otros valores |

No toques `APP_KEY`, `PASSPORT_PRIVATE_KEY` ni `PASSPORT_PUBLIC_KEY`: son las llaves que firman los tokens.

Deja también `DB_TIMEZONE=+00:00` tal cual. Hace que la base trabaje en UTC, igual que la aplicación. Si falta o cambia, las fechas de acceso se leen corridas y aparece «No tienes acceso» aunque estés matriculado. Si la línea no estuviera en el `.env`, Laravel usa `+00:00` igual (es el valor por defecto en `config/database.php`).

### 1.6 Tarea programada
- [ ] **Cron Jobs** → «Una vez por minuto» → comando:
  `cd /home/juarezc/identidad-api && php artisan schedule:run >> /dev/null 2>&1`

### 1.7 Comprobar
| Abre | Debes ver |
| --- | --- |
| `https://identidad.juarezcampos.net/login` | Pantalla de ingreso **con estilos** (botón azul) |
| `https://identidad.juarezcampos.net/api/jwks.json` | Un JSON con `"keys"` |
| `https://identidad.juarezcampos.net/api/sesion` | `{"codigo":"sin_sesion",...}` (significa que la base y la sesión funcionan) |
| `https://identidad.juarezcampos.net/.env` | Error 403 |

---

## 2. Panel — `https://panel.juarezcampos.net`

- [ ] Crea el dominio `panel.juarezcampos.net` con raíz `/home/juarezc/panel.juarezcampos.net` y corre **AutoSSL**.
- [ ] Sube y extrae `2-panel-panel.juarezcampos.net.zip` en `/home/juarezc/`.
- [ ] Abre `https://panel.juarezcampos.net` → te lleva a identidad → ingresa con el correo y la contraseña de `CREDENCIALES.txt`.
- [ ] **Cambia tu contraseña**: Usuarios → tu usuario → Cambiar contraseña.

---

## 3. ISTQB — `https://juarezcampos.net/istqb`

### 3.1 Base de datos (una sola vez)
- [ ] phpMyAdmin → `juarezc_istqb_exam` → **Importar** `sql/istqb-agregar-identidad.sql`.

> **¿Qué valor lleva `identidad_sub`?** Ninguno: se crea **vacía (NULL) a propósito**.
> La primera vez que cada administrador entra a ISTQB por identidad, ISTQB lo busca por su correo y llena
> `identidad_sub` solo (es el UUID de su usuario en identidad). Por eso tu correo en identidad debe ser el mismo
> que tenía tu usuario en ISTQB (`romeosoftjuarez@gmail.com`). Si ya existe la columna, no la vuelvas a agregar.

### 3.2 Archivos
- [ ] Sube y extrae `3-istqb-juarezcampos.net.zip` en `/home/juarezc/`: reemplaza `public_html/istqb/` y el código de `istqb-api-app/` (cuando pregunte, **sobrescribir**). No trae `.env` ni `storage/`, así que tus datos e imágenes no se tocan.
- [ ] Abre `/home/juarezc/istqb-api-app/.env` y pega **al final** todo el contenido de `env/istqb-api-app.env-agregar`.
- [ ] Vacía la carpeta `istqb-api-app/bootstrap/cache/` (borra todo menos `.gitignore`).

### 3.3 Comprobar
- [ ] `https://juarezcampos.net/istqb/` abre el simulacro sin pedir ingreso.
- [ ] `https://juarezcampos.net/istqb/admin` te lleva a identidad y vuelve al panel de ISTQB.

---

## 4. Firma — `https://firma.juarezcampos.net`

- [ ] Sube y extrae `4-firma-firma.juarezcampos.net.zip` en `/home/juarezc/` (sobrescribir). No trae `.env`, base SQLite ni `storage/`: tus PDFs y registros se conservan.
- [ ] En `/home/juarezc/firma-api/.env` **borra** las líneas `ADMIN_CLAVE`, `ADMIN_SESION_MINUTOS`, `ADMIN_INTENTOS`, `ADMIN_BLOQUEO_MINUTOS`, `ADMIN_INTENTOS_GLOBALES` y pega al final el contenido de `env/firma-api.env-agregar`.
- [ ] Vacía `firma-api/bootstrap/cache/` (menos `.gitignore`).
- [ ] `https://firma.juarezcampos.net/api/acceso/config` responde un JSON con `authorize_endpoint` (si dice «El recurso solicitado no existe.», el código de `firma-api/` no se reemplazó).
- [ ] `https://firma.juarezcampos.net/historial` te lleva a identidad. Firmar y Verificar siguen sin pedir ingreso.

---

## 5. Si algo falla

Las pantallas nunca muestran detalles técnicos: muestran una **referencia** como `E-HBK8FZIB`.

1. Administrador de archivos → `/home/juarezc/identidad-api/storage/logs/laravel.log` (o `istqb-api-app/…`, `firma-api/…`).
2. Clic derecho → **Editar** (o Ver) y busca la referencia con Ctrl+F.
3. Esa línea dice la causa real. Envíala a quien te dé soporte.

| Síntoma | Causa habitual | Solución |
| --- | --- | --- |
| «No pudimos iniciar el ingreso» con referencia al abrir el panel | Llaves de Passport con permisos `644` (paquetes anteriores) | Ver sección 6 |
| «No tienes acceso a Panel de administración» con tu usuario ya creado | Fecha `vigente_desde` en el futuro por la zona horaria (paquetes anteriores) | Ver sección 7 |
| Firma: «El recurso solicitado no existe.» al pulsar «Ingresar con mi cuenta» | `firma-api/` sigue con el código anterior (el zip 4 no lo sobrescribió o se extrajo en otra carpeta) | Comprueba que exista `firma-api/app/Support/Identidad.php`; extrae el zip 4 en `/home/juarezc/` sobrescribiendo y vacía `firma-api/bootstrap/cache/`. `https://firma.juarezcampos.net/api/acceso/config` debe responder un JSON con `authorize_endpoint` |
| El log dice `Access denied for user` | `DB_USERNAME`/`DB_PASSWORD` mal o el usuario no está agregado a la base | Revisa 1.2 y 1.5 |
| El log dice `Table ... doesn't exist` | No se importó `juarezc_panel.sql` o se importó en otra base | Revisa 1.2 |
| El log dice `Permission denied` en `storage` | PHP no puede escribir | Permisos `755` en `identidad-api/storage` y `bootstrap/cache` |
| La pantalla de ingreso sale sin estilos | Front de un paquete anterior | Ver sección 6 |
| No llega el código de 6 dígitos | Datos SMTP | Revisa 1.3 y `MAIL_*` en el `.env`; mira el log |

---

## 6. Si ya subiste el paquete anterior (sin estilos / error al abrir el panel)

El paquete anterior tenía dos fallas:
- el login se cargaba sin estilos;
- las llaves `storage/oauth-*.key` quedaban con permisos `644`, que la librería OAuth rechaza en Linux, y el ingreso fallaba con una referencia `E-…`.

Corrígelo así, **sin tocar la base**:

- [ ] **Llaves** (elige una):
  - **Rápido**: Administrador de archivos → `identidad-api/storage/` → clic derecho en `oauth-private.key` → **Change Permissions** → `600`. Haz lo mismo con `oauth-public.key`.
  - **Definitivo**: del `.env` del zip nuevo copia el bloque `PASSPORT_PRIVATE_KEY="…"` y `PASSPORT_PUBLIC_KEY="…"` (varias líneas cada uno, con sus comillas) al final de tu `identidad-api/.env`, y borra `storage/oauth-private.key` y `storage/oauth-public.key`. Son las mismas llaves de esos archivos (vienen del mismo paquete), solo cambia dónde se guardan.
- [ ] **Estilos**: borra el contenido de `/home/juarezc/identidad.juarezcampos.net/` **excepto** `laravel.php`, y sube ahí el contenido de la carpeta `identidad.juarezcampos.net/` del zip nuevo. Haz lo mismo con `panel.juarezcampos.net/`, conservando tu `config.json`.
- [ ] Vuelve a probar `https://panel.juarezcampos.net`.

---

## 7. Si ya desplegaste y ves «No tienes acceso a …» estando matriculado

Los paquetes anteriores al 22/10/2026 tenían dos detalles de zona horaria:
- el `.sql` guardó las fechas de inicio de acceso (`vigente_desde`) unas horas en el futuro;
- la conexión a la base no fijaba UTC, así que MariaDB devolvía las fechas en la hora del servidor.

Haz los tres pasos (no se pierde ningún dato):

- [ ] **Datos**: phpMyAdmin → base `juarezc_panel` → pestaña **SQL** → ejecuta:
  ```sql
  UPDATE matriculas SET vigente_desde = NULL;
  ```
  `NULL` significa «vigente desde siempre»; no cambia sistemas, roles ni permisos.
- [ ] **`.env`**: en `/home/juarezc/identidad-api/.env`, justo debajo de `DB_PASSWORD=…`, agrega:
  ```
  # Zona horaria de la sesión de base de datos. Debe quedar en +00:00 (UTC), igual que la aplicación.
  DB_TIMEZONE=+00:00
  ```
- [ ] **Código**: reemplaza `/home/juarezc/identidad-api/config/database.php` por el del zip nuevo
  (`identidad-api/config/database.php`). Si prefieres editarlo a mano, en los bloques `'mysql' => [` y
  `'mariadb' => [`, debajo de `'strict' => true,` agrega:
  ```php
  'timezone' => env('DB_TIMEZONE', '+00:00'),
  ```
  `env('DB_TIMEZONE', '+00:00')` lee `DB_TIMEZONE` del `.env`; si no existe, usa `+00:00`.
- [ ] Vacía `identidad-api/bootstrap/cache/` (borra todo menos `.gitignore`) para que Laravel tome la nueva configuración.
- [ ] Recarga `https://panel.juarezcampos.net`.

Comprobación en phpMyAdmin (`juarezc_panel` → SQL): debe mostrar tus 3 accesos `activa` y `vigente_desde` vacío.
```sql
SELECT s.codigo AS sistema, r.codigo AS rol, m.estado, m.vigente_desde, m.vigente_hasta
FROM matriculas m JOIN sistemas s ON s.id = m.sistema_id JOIN roles r ON r.id = m.rol_id;
```

---

## 8. Ingreso con Google (actualización del 23/10/2026)

En el login aparece **«Continuar con Google»** (debajo de un separador «o»). Funciona así:

| Situación | Qué pasa |
| --- | --- |
| No tiene cuenta | Se crea la cuenta **deshabilitada** (pendiente), llega un correo a los administradores del panel y la persona ve «Tu cuenta fue registrada, pero todavía no está habilitada. Debe ser aprobada por el administrador del sistema». No entra a ningún sistema. |
| Ya tiene una cuenta registrada en el sistema (creada en el panel) | **No** entra con Google: ve «Ya tienes una cuenta registrada en el sistema con este correo. Ingresa con tu correo y contraseña». |
| Su cuenta nació con Google y ya fue aprobada | Entra con Google; solo verá los sistemas donde tenga matrícula. |
| Solicitud pendiente, rechazada, de baja o bloqueada | No entra; ve un mensaje claro. |

Las solicitudes se aprueban en el panel: **Resumen → Solicitudes por aprobar** (o Usuarios → filtro «Solicitudes pendientes») → abrir la persona → marcar los sistemas/roles → **Aprobar**. Al aprobar, se le avisa por correo.

### 8.1 Crear las credenciales en Google (una sola vez)

- [ ] Entra a <https://console.cloud.google.com> con la cuenta de Google que administrará esto y crea un proyecto (por ejemplo «Juarez Campos Identidad»).
- [ ] **Google Auth Platform → Branding** (o «Pantalla de consentimiento de OAuth»):
  - Nombre de la app: `Juarez Campos`. Correo de asistencia: el tuyo.
  - **Dominios autorizados**: `juarezcampos.net`.
  - Tipo de usuario / **Audience**: **Externo**.
- [ ] **Data access / Permisos**: solo `openid`, `.../auth/userinfo.email` y `.../auth/userinfo.profile` (no requieren verificación de Google).
- [ ] **Audience → Publicar la app** (estado «En producción»). Si queda «En prueba», solo podrán entrar los correos que agregues como usuarios de prueba.
- [ ] **Clients / Credenciales → Crear ID de cliente OAuth** → tipo **Aplicación web**:
  - Orígenes de JavaScript autorizados: `https://identidad.juarezcampos.net`
  - **URI de redireccionamiento autorizados**: `https://identidad.juarezcampos.net/api/google/callback`
  - (Opcional, para pruebas en tu PC: `http://localhost:4300/api/google/callback`)
- [ ] Copia el **ID de cliente** y el **Secreto del cliente**.

### 8.2 Actualizar el servidor (sin perder datos)

Usa el paquete `actualizacion-google-<fecha>/`:

- [ ] **Base**: phpMyAdmin → `juarezc_panel` → SQL → ejecuta `sql/2026_10_23-ingreso-google.sql` (una sola vez). Solo agrega 4 columnas a `usuarios`: **no** vuelvas a importar `juarezc_panel.sql` ni se pierden tus datos. Para saber si ya se aplicó: `SHOW COLUMNS FROM usuarios LIKE 'google_sub';` (si devuelve una fila, ya está).
- [ ] **API**: sube y extrae `1-identidad-api.zip` en `/home/juarezc/` (sobrescribir). No trae `.env` ni `storage/`.
- [ ] **Pantallas**: sube y extrae `2-identidad-web.zip` y `3-panel-web.zip` en `/home/juarezc/` (sobrescribir). No traen `laravel.php` ni `config.json`, así que se conservan los tuyos.
- [ ] **`.env`**: al final de `/home/juarezc/identidad-api/.env` agrega (con tus valores de 8.1):
  ```
  GOOGLE_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
  GOOGLE_CLIENT_SECRET=xxxxxxxx
  GOOGLE_REDIRECT_URI=https://identidad.juarezcampos.net/api/google/callback
  IDENTIDAD_PANEL_URL=https://panel.juarezcampos.net
  ```
- [ ] **Obligatorio**: vacía `identidad-api/bootstrap/cache/` (todo menos `.gitignore`). Si no, Laravel sigue usando las rutas y la configuración anteriores y el botón responde «No encontramos lo que buscas».

### 8.3 Comprobar

- [ ] `https://identidad.juarezcampos.net/api/metodos` → `{"google":true}` (si dice `false`, falta algún `GOOGLE_*` en el `.env`).
- [ ] El login muestra el separador «o» y el botón **Continuar con Google**.
- [ ] Con un Gmail que **no** esté registrado: aparece «Recibimos tu solicitud…» y te llega el correo de aviso.
- [ ] En el panel la solicitud aparece en el Resumen; apruébala con un sistema y vuelve a entrar con ese Gmail.

| Síntoma | Causa | Solución |
| --- | --- | --- |
| Google muestra «Error 400: redirect_uri_mismatch» | La URI de 8.1 no coincide exactamente | Debe ser `https://identidad.juarezcampos.net/api/google/callback` (https, sin `/` final) en Google y en `GOOGLE_REDIRECT_URI` |
| Google dice «Acceso bloqueado: la app no completó la verificación» o solo entran algunos correos | La app sigue «En prueba» | Google Auth Platform → Audience → **Publicar app** |
| «No pudimos completar el ingreso con Google» | Secreto incorrecto o sesión vencida | Revisa `GOOGLE_CLIENT_SECRET`; busca la causa en `storage/logs/laravel.log` (línea «Google rechazó…») |
| Al pulsar el botón: `{"codigo":"error_solicitud","message":"No encontramos lo que buscas."}` en `/api/google/redirect` | El servidor sigue con las rutas anteriores: caché de Laravel o el zip 1 extraído en otra carpeta | Borra todo en `identidad-api/bootstrap/cache/` menos `.gitignore`; revisa que no exista `identidad-api/identidad-api/` y que `identidad-api/routes/web.php` contenga `google/redirect` |
| No llega el correo de aviso a los administradores | SMTP | Mismos datos `MAIL_*` del código de desbloqueo (sección 1.3) |
