<?php

/*
 * CORS de identidad. Solo lo necesitan las aplicaciones de navegador que llaman a la API desde
 * otro dominio: hoy el panel (panel.juarezcampos.net), que canjea su código y usa /api/admin.
 * Login, sesión y authorize no se listan: identidad-web los llama desde su mismo dominio.
 */
return [

    'paths' => ['api/admin/*', 'api/oauth/token', 'api/jwks.json', 'api/.well-known/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('IDENTIDAD_CORS_ORIGENES', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,

];
