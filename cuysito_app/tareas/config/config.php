<?php
/**
 * ============================================================
 * Tareas — Configuración del módulo (backend)
 * ============================================================
 * Punto único de configuración. Todo lo "ajustable" del módulo
 * vive aquí para no tener que tocar la lógica de negocio.
 *
 * Este archivo es 100% autocontenido: NO depende de ningún otro
 * módulo de la plataforma (gestion-tarjetas, wabo, etc.).
 * ============================================================
 */

declare(strict_types=1);

return [

    /*
     |--------------------------------------------------------------------------
     | Acceso por PIN (clave numérica)
     |--------------------------------------------------------------------------
     | El módulo se protege con una clave numérica de 5 dígitos. Para cambiarla
     | basta con editar 'pin' aquí: NO se guarda el PIN en texto plano del lado
     | del cliente, la verificación es 100% en el servidor (ver api/auth.php).
     |
     | - pin            : clave por defecto. Cámbiala cuando quieras.
     | - pin_length     : longitud esperada (dígitos).
     | - max_attempts   : intentos antes de bloquear temporalmente.
     | - lockout_seconds: duración del bloqueo tras agotar los intentos.
     */
    'pin'             => '77770',
    'pin_length'      => 5,
    'max_attempts'    => 5,
    'lockout_seconds' => 30,

    /*
     |--------------------------------------------------------------------------
     | Sesión
     |--------------------------------------------------------------------------
     | Nombre y alcance de la cookie de sesión. Se usa un nombre y un path
     | propios del módulo para quedar totalmente aislado del resto del sitio.
     */
    'session_name'        => 'TAREAS_SESS',
    'session_cookie_path' => '/tareas',
    // Vida de la sesión (segundos). 12 h por defecto.
    'session_lifetime'    => 12 * 60 * 60,

    /*
     |--------------------------------------------------------------------------
     | Base de datos
     |--------------------------------------------------------------------------
     | Mismas credenciales que el resto de la plataforma. El módulo crea sus
     | propias tablas con prefijo `tareas_` para no colisionar con nada.
     */
    'db' => [
        'host'    => 'localhost',
        'name'    => 'zkckhehj_pistas',
        'user'    => 'zkckhehj_pistas',
        'pass'    => 'Pistas2628',
        'charset' => 'utf8mb4',
    ],

    // Prefijo de tablas del módulo (no cambiar salvo migración manual).
    'table_prefix' => 'tareas_',
];
