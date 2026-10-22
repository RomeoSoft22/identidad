#!/usr/bin/env bash
# Genera los paquetes para cPanel (Apache + PHP 8.3, usuario juarezc) de todo el ecosistema:
#
#   identidad.juarezcampos.net  identidad-web (Angular) + API de identidad (Laravel fuera de la web)
#   panel.juarezcampos.net      panel-web (Angular)
#   juarezcampos.net/istqb      istqb-web (Angular) + código de /home/juarezc/istqb-api-app
#   firma.juarezcampos.net      firma-web (Angular) + firma-api (Laravel fuera de la web)
#
# Además crea juarezc_panel.sql (tablas + sistemas PANEL/ISTQB/FIRMA + roles + administrador),
# istqb-agregar-identidad.sql y CREDENCIALES.txt (contraseña inicial y secretos: NUNCA al repositorio).
#
# Uso (Git Bash, con los repos clonados uno al lado del otro):
#   ADMIN_CORREO=romeosoftjuarez@gmail.com ADMIN_NOMBRES="Jean Piere" ADMIN_APELLIDOS="Juarez Campos" \
#     bash identidad/despliegue/empaquetar-cpanel.sh
#
# Requiere: php 8.3 (sodium, gd, pdo_mysql), composer (o COMPOSER_CMD="php /ruta/composer.phar"),
# node/npx, un MySQL local para armar el .sql (MYSQL_BIN, por defecto el de Laragon) y tar.exe de Windows.
set -euo pipefail
trap 'echo "ERROR en la línea $LINENO: $BASH_COMMAND" >&2' ERR

BASE="$(cd "$(dirname "$0")/../.." && pwd)"
FECHA="$(date +%Y%m%d-%H%M)"
SALIDA="${SALIDA:-$BASE/paquetes/cpanel-$FECHA}"
CPANEL_USUARIO="${CPANEL_USUARIO:-juarezc}"
DOM_IDENTIDAD="${DOM_IDENTIDAD:-identidad.juarezcampos.net}"
DOM_PANEL="${DOM_PANEL:-panel.juarezcampos.net}"
DOM_FIRMA="${DOM_FIRMA:-firma.juarezcampos.net}"
URL_ISTQB="${URL_ISTQB:-https://juarezcampos.net/istqb}"
ADMIN_CORREO="${ADMIN_CORREO:?Indica ADMIN_CORREO}"
ADMIN_NOMBRES="${ADMIN_NOMBRES:?Indica ADMIN_NOMBRES}"
ADMIN_APELLIDOS="${ADMIN_APELLIDOS:-}"
MYSQL_BIN="${MYSQL_BIN:-/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin}"
COMPOSER_CMD="${COMPOSER_CMD:-composer}"
TAR="${TAR:-/c/Windows/System32/tar.exe}"
AGENTE_SETUP="${AGENTE_SETUP:-}"   # AgenteFirmaSetup.exe ya compilado (opcional)
export NG_CLI_ANALYTICS=false

paso() { printf '\n\033[36m== %s\033[0m\n' "$*"; }
aleatorio() { php -r 'echo rtrim(strtr(base64_encode(random_bytes('"$1"')), "+/", "xy"), "=");'; }
# Copia un proyecto Laravel sin lo que no va al servidor (dependencias, pruebas, secretos, datos).
copiar_laravel() {
  local origen="$1" destino="$2"
  mkdir -p "$destino"
  (cd "$origen" && git ls-files -z | grep -zv -E '^(tests/|public/|\.github/|phpunit\.xml|\.editorconfig|\.gitattributes|README\.md|AGENTS\.md|CLAUDE\.md)' \
    | xargs -0 -I{} cp --parents "{}" "$destino/")
  # storage/: solo la estructura vacía
  find "$destino/storage" -type f ! -name .gitignore -delete 2>/dev/null || true
}
llaves_al_env() {
  (cd "$1" && php -r '
    $priv = trim(file_get_contents("storage/oauth-private.key"));
    $pub = trim(file_get_contents("storage/oauth-public.key"));
    file_put_contents(".env", "\n# Llaves RSA de Passport (firman los JWT). Confidenciales.\nPASSPORT_PRIVATE_KEY=\"$priv\"\nPASSPORT_PUBLIC_KEY=\"$pub\"\n", FILE_APPEND);
    unlink("storage/oauth-private.key");
    unlink("storage/oauth-public.key");')
}
composer_produccion() { (cd "$1" && $COMPOSER_CMD install --no-dev --optimize-autoloader --no-interaction --no-progress >/dev/null); }
compilar_angular() {
  (cd "$BASE/$1" && { [ -d node_modules ] || npm ci --no-audit --no-fund; } && npx ng build --configuration production "${@:2}" >/dev/null)
}
# tar.exe (bsdtar de Windows) genera .zip con rutas "/", compatibles con el extractor de cPanel.
zip_de() { local zip; zip="$(cygpath -w "$1" 2>/dev/null || echo "$1")"; shift; (cd "$SALIDA/contenido" && "$TAR" -a -c -f "$zip" "$@"); }

mkdir -p "$SALIDA/contenido" "$SALIDA/sql" "$SALIDA/env"
C="$SALIDA/contenido"

# ------------------------------------------------------------------ 1. Base juarezc_panel
paso '1/6 Base juarezc_panel (tablas, sistemas, roles y administrador)'
TMPDB="juarezc_panel_empaque_$$"
"$MYSQL_BIN/mysql" -uroot -e "CREATE DATABASE $TMPDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
trap '"$MYSQL_BIN/mysql" -uroot -e "DROP DATABASE IF EXISTS $TMPDB" 2>/dev/null || true' EXIT
ADMIN_CLAVE="$(aleatorio 15)"
art() { (cd "$BASE/identidad" && DB_DATABASE="$TMPDB" php artisan "$@"); }
art migrate --force -q
registrar() { art identidad:sistema "$@" | awk -F'|' '/client_id|client_secret/ {gsub(/ /,"",$2); gsub(/ /,"",$3); print $2"="$3}'; }
PANEL="$(registrar PANEL 'Panel de administración' --publico --url="https://$DOM_PANEL" --redirect="https://$DOM_PANEL/callback")"
ISTQB="$(registrar ISTQB 'Simulador ISTQB' --url="$URL_ISTQB" --redirect="$URL_ISTQB/callback")"
FIRMA="$(registrar FIRMA 'Firma digital' --url="https://$DOM_FIRMA" --redirect="https://$DOM_FIRMA/callback")"
valor() { echo "$1" | sed -n "s/^$2=//p"; }
PANEL_ID="$(valor "$PANEL" client_id)"
ISTQB_ID="$(valor "$ISTQB" client_id)"; ISTQB_SECRETO="$(valor "$ISTQB" client_secret)"
FIRMA_ID="$(valor "$FIRMA" client_id)"; FIRMA_SECRETO="$(valor "$FIRMA" client_secret)"
for r in "PANEL ADMIN Administrador" "ISTQB ADMIN Administrador" "ISTQB GESTOR Gestor_de_preguntas" "ISTQB AUDITOR Auditor" "FIRMA ADMIN Administrador"; do
  set -- $r; art identidad:rol "$1" "$2" "${3//_/ }" -q
done
art identidad:usuario "$ADMIN_CORREO" "$ADMIN_NOMBRES" "$ADMIN_APELLIDOS" --password="$ADMIN_CLAVE" -q
for s in PANEL ISTQB FIRMA; do art identidad:matricular "$ADMIN_CORREO" "$s" ADMIN -q; done
{
  echo "-- juarezc_panel: base de identidad y del panel. Importar en phpMyAdmin dentro de la base juarezc_panel (vacía)."
  echo "SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0;"
  "$MYSQL_BIN/mysqldump" -uroot --no-tablespaces --set-gtid-purged=OFF --skip-comments --default-character-set=utf8mb4 "$TMPDB"
  echo "SET FOREIGN_KEY_CHECKS=1;"
} > "$SALIDA/sql/juarezc_panel.sql"
grep -q "utf8mb4_0900" "$SALIDA/sql/juarezc_panel.sql" && { echo "El .sql usa una intercalación que MariaDB no admite"; exit 1; }

cat > "$SALIDA/sql/istqb-agregar-identidad.sql" <<'SQL'
-- juarezc_istqb_exam: vincula los usuarios de ISTQB con identidad (ejecutar UNA vez en phpMyAdmin).
-- Equivale a: php artisan migrate --force   (migración 2026_10_18_000100_add_identidad_sub_to_users)
ALTER TABLE `users` ADD COLUMN `identidad_sub` CHAR(36) NULL AFTER `id`, ADD UNIQUE KEY `users_identidad_sub_unique` (`identidad_sub`);
INSERT INTO `migrations` (`migration`, `batch`) SELECT '2026_10_18_000100_add_identidad_sub_to_users', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
SQL

# ------------------------------------------------------------------ 2. Identidad
paso "2/6 identidad ($DOM_IDENTIDAD)"
compilar_angular identidad-web
mkdir -p "$C/$DOM_IDENTIDAD"
cp -r "$BASE/identidad-web/dist/identidad-web/browser/." "$C/$DOM_IDENTIDAD/"
cp "$BASE/identidad/despliegue/cpanel/publico.htaccess" "$C/$DOM_IDENTIDAD/.htaccess"
cp "$BASE/identidad/despliegue/cpanel/laravel.php" "$C/$DOM_IDENTIDAD/laravel.php"
copiar_laravel "$BASE/identidad" "$C/identidad-api"
rm -rf "$C/identidad-api/despliegue"
cp "$BASE/identidad/despliegue/cpanel/api.htaccess" "$C/identidad-api/.htaccess"
composer_produccion "$C/identidad-api"
sed -e "s|^APP_ENV=.*|APP_ENV=production|" -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
    -e "s|^APP_URL=.*|APP_URL=https://$DOM_IDENTIDAD|" -e "s|^LOG_LEVEL=.*|LOG_LEVEL=warning|" \
    -e "s|^DB_DATABASE=.*|DB_DATABASE=${CPANEL_USUARIO}_panel|" -e "s|^DB_USERNAME=.*|DB_USERNAME=${CPANEL_USUARIO}_CAMBIAR|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=CAMBIAR|" \
    -e "s|^IDENTIDAD_ISSUER=.*|IDENTIDAD_ISSUER=https://$DOM_IDENTIDAD|" \
    -e "s|^IDENTIDAD_API_URL=.*|IDENTIDAD_API_URL=https://$DOM_IDENTIDAD/api|" \
    -e "s|^IDENTIDAD_CORS_ORIGENES=.*|IDENTIDAD_CORS_ORIGENES=https://$DOM_PANEL|" \
    -e "s|^MAIL_MAILER=.*|MAIL_MAILER=smtp|" -e "s|^MAIL_SCHEME=.*|MAIL_SCHEME=smtps|" \
    -e "s|^MAIL_HOST=.*|MAIL_HOST=mail.juarezcampos.net|" -e "s|^MAIL_PORT=.*|MAIL_PORT=465|" \
    -e "s|^MAIL_USERNAME=.*|MAIL_USERNAME=no-responder@juarezcampos.net|" -e "s|^MAIL_PASSWORD=.*|MAIL_PASSWORD=CAMBIAR|" \
    -e "s|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=\"no-responder@juarezcampos.net\"|" \
    "$BASE/identidad/.env.example" > "$C/identidad-api/.env"
printf '\nSESSION_SECURE_COOKIE=true\n' >> "$C/identidad-api/.env"
(cd "$C/identidad-api" && php artisan key:generate --force -q && php artisan passport:keys --force -q)
# Llaves RSA dentro del .env: en Linux, Passport rechaza archivos de llave con permisos 644 (los que deja
# el extractor de cPanel) y /authorize fallaría con error 500. Como texto en el .env no se revisan permisos.
llaves_al_env "$C/identidad-api"
find "$C/identidad-api/storage/logs" -type f ! -name .gitignore -delete

# ------------------------------------------------------------------ 3. Panel
paso "3/6 panel ($DOM_PANEL)"
compilar_angular panel-web
mkdir -p "$C/$DOM_PANEL"
cp -r "$BASE/panel-web/dist/panel-web/browser/." "$C/$DOM_PANEL/"
cp "$BASE/panel-web/public/.htaccess" "$C/$DOM_PANEL/.htaccess"
printf '{\n  "identidadUrl": "https://%s",\n  "identidadApiUrl": "https://%s/api",\n  "clientId": "%s"\n}\n' \
  "$DOM_IDENTIDAD" "$DOM_IDENTIDAD" "$PANEL_ID" > "$C/$DOM_PANEL/config.json"

# ------------------------------------------------------------------ 4. ISTQB
paso "4/6 ISTQB ($URL_ISTQB)"
(cd "$BASE/istqb-web" && MSYS_NO_PATHCONV=1 npx ng build --configuration production --base-href /istqb/ >/dev/null)
mkdir -p "$C/public_html/istqb"
cp -r "$BASE/istqb-web/dist/web/browser/." "$C/public_html/istqb/"
cp "$BASE/istqb-web/public/.htaccess" "$C/public_html/istqb/.htaccess"
copiar_laravel "$BASE/istqb-api" "$C/istqb-api-app"
rm -rf "$C/istqb-api-app/storage"   # en el servidor quedan las imágenes de preguntas y los logs
composer_produccion "$C/istqb-api-app"
cat > "$SALIDA/env/istqb-api-app.env-agregar" <<EOF
# Agregar (o reemplazar) al final de /home/$CPANEL_USUARIO/istqb-api-app/.env
IDENTIDAD_URL=https://$DOM_IDENTIDAD
IDENTIDAD_API_URL=https://$DOM_IDENTIDAD/api
IDENTIDAD_CLIENT_ID=$ISTQB_ID
IDENTIDAD_CLIENT_SECRET=$ISTQB_SECRETO
IDENTIDAD_REDIRECT_URI=$URL_ISTQB/callback
IDENTIDAD_POST_LOGOUT_URI=$URL_ISTQB/
EOF

# ------------------------------------------------------------------ 5. Firma
paso "5/6 firma ($DOM_FIRMA)"
compilar_angular firma-web
mkdir -p "$C/$DOM_FIRMA"
cp -r "$BASE/firma-web/dist/front/browser/." "$C/$DOM_FIRMA/"
cp "$BASE/firma-servicio/despliegue/cpanel/publico.htaccess" "$C/$DOM_FIRMA/.htaccess"
cp "$BASE/firma-servicio/despliegue/cpanel/laravel.php" "$C/$DOM_FIRMA/laravel.php"
if [ -n "$AGENTE_SETUP" ] && [ -f "$AGENTE_SETUP" ]; then
  mkdir -p "$C/$DOM_FIRMA/descargas"
  cp "$AGENTE_SETUP" "$C/$DOM_FIRMA/descargas/AgenteFirmaSetup.exe"
  [ -f "$(dirname "$AGENTE_SETUP")/agente-version.json" ] && cp "$(dirname "$AGENTE_SETUP")/agente-version.json" "$C/$DOM_FIRMA/descargas/"
fi
copiar_laravel "$BASE/firma-api" "$C/firma-api"
rm -rf "$C/firma-api/storage" "$C/firma-api/database/database.sqlite"   # en el servidor están los PDFs y la base
cp "$BASE/firma-servicio/despliegue/cpanel/api.htaccess" "$C/firma-api/.htaccess"
composer_produccion "$C/firma-api"
cat > "$SALIDA/env/firma-api.env-agregar" <<EOF
# Agregar al final de /home/$CPANEL_USUARIO/firma-api/.env y BORRAR las líneas ADMIN_CLAVE, ADMIN_SESION_MINUTOS,
# ADMIN_INTENTOS, ADMIN_BLOQUEO_MINUTOS y ADMIN_INTENTOS_GLOBALES (ya no se usan).
IDENTIDAD_URL=https://$DOM_IDENTIDAD
IDENTIDAD_API_URL=https://$DOM_IDENTIDAD/api
IDENTIDAD_CLIENT_ID=$FIRMA_ID
IDENTIDAD_CLIENT_SECRET=$FIRMA_SECRETO
IDENTIDAD_REDIRECT_URI=https://$DOM_FIRMA/callback
IDENTIDAD_POST_LOGOUT_URI=https://$DOM_FIRMA/
IDENTIDAD_ROLES_ADMIN=ADMIN
EOF

# ------------------------------------------------------------------ 6. Comprimir y credenciales
paso '6/6 Comprimiendo'
zip_de "$SALIDA/1-identidad-$DOM_IDENTIDAD.zip" "$DOM_IDENTIDAD" identidad-api
zip_de "$SALIDA/2-panel-$DOM_PANEL.zip" "$DOM_PANEL"
zip_de "$SALIDA/3-istqb-juarezcampos.net.zip" public_html istqb-api-app
zip_de "$SALIDA/4-firma-$DOM_FIRMA.zip" "$DOM_FIRMA" firma-api
cp "$BASE/identidad/despliegue/LEEME-cpanel.md" "$SALIDA/LEEME.md"

cat > "$SALIDA/CREDENCIALES.txt" <<EOF
CONFIDENCIAL — no subir a GitHub ni compartir. Borrar del equipo después de desplegar.

Administrador (identidad / panel / ISTQB / firma)
  Correo:     $ADMIN_CORREO
  Contraseña: $ADMIN_CLAVE   (cámbiala desde el panel: Usuarios > tu usuario > Cambiar contraseña)

Sistemas registrados en juarezc_panel.sql
  PANEL  client_id $PANEL_ID (público, sin secreto; ya está en panel/config.json)
  ISTQB  client_id $ISTQB_ID   secreto: $ISTQB_SECRETO   (en env/istqb-api-app.env-agregar)
  FIRMA  client_id $FIRMA_ID   secreto: $FIRMA_SECRETO   (en env/firma-api.env-agregar)

identidad-api/.env (dentro del .zip) trae APP_KEY y las llaves RSA de firma de tokens en storage/.
Falta completar en ese .env: DB_USERNAME, DB_PASSWORD y MAIL_PASSWORD.
EOF

printf '\n\033[32mPaquetes listos en %s\033[0m\n' "$SALIDA"
ls -1 "$SALIDA"
