<?php

return [
    // URL pública de identidad-web (Angular). Es el "iss" de los JWT y donde viven /authorize y /logout.
    // En producción: https://identidad.juarezcampos.net
    'issuer' => rtrim(env('IDENTIDAD_ISSUER', 'http://localhost:4300'), '/'),

    // URL pública de esta API. En producción: https://identidad.juarezcampos.net/api
    'api_url' => rtrim(env('IDENTIDAD_API_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/api'), '/'),

    // Fallos de contraseña consecutivos antes de bloquear la cuenta.
    'max_intentos' => (int) env('IDENTIDAD_MAX_INTENTOS', 10),

    // Intentos de login permitidos por IP y por minuto (cualquier cuenta).
    'login_por_minuto_ip' => (int) env('IDENTIDAD_LOGIN_POR_MINUTO_IP', 20),

    // Duraciones en minutos.
    'access_token_minutos' => (int) env('IDENTIDAD_ACCESS_TOKEN_MINUTOS', 15),
    'refresh_token_minutos' => (int) env('IDENTIDAD_REFRESH_TOKEN_MINUTOS', 480),
];
