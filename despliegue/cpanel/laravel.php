<?php

/*
 * Controlador frontal de la API de identidad publicado en https://identidad.juarezcampos.net/api.
 * El .htaccess envía aquí todas las rutas /api/*; el código de Laravel vive FUERA de la carpeta
 * pública (por defecto en ../identidad-api), así .env, las llaves RSA y los logs nunca son
 * accesibles desde la web.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$api = getenv('IDENTIDAD_API_PATH') ?: dirname(__DIR__).'/identidad-api';

if (! is_file($api.'/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['message' => 'El servicio no está disponible en este momento.']);
    exit;
}

if (is_file($mantenimiento = $api.'/storage/framework/maintenance.php')) {
    require $mantenimiento;
}

require $api.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once $api.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->handleRequest(Request::capture());
