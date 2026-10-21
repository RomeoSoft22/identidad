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

    // Código de 6 dígitos que se envía al correo para desbloquear la cuenta.
    'codigo_minutos' => (int) env('IDENTIDAD_CODIGO_MINUTOS', 10),
    'codigo_intentos' => (int) env('IDENTIDAD_CODIGO_INTENTOS', 5),
    'codigo_por_hora' => (int) env('IDENTIDAD_CODIGO_POR_HORA', 5), // envíos máximos por usuario y hora

    // Código del sistema del panel de administración: su rol ADMIN habilita /api/admin.
    'sistema_panel' => env('IDENTIDAD_SISTEMA_PANEL', 'PANEL'),

    // Meses que se conservan los eventos de auditoría, accesos y sesiones cerradas.
    'retencion_auditoria_meses' => (int) env('IDENTIDAD_RETENCION_AUDITORIA_MESES', 12),
];
