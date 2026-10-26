<?php

/**
 * Indica si el modo mantenimiento está habilitado en los ajustes de la plataforma.
 */
function modo_mantenimiento_activo(array $ajustes): bool
{
    return (int)($ajustes['mantenimiento'] ?? 0) === 1;
}

/**
 * El personal debe conservar acceso para administrar y verificar el despliegue.
 */
function rol_exento_de_mantenimiento(?string $rol): bool
{
    return in_array($rol, ['admin', 'docente', 'tesoreria', 'administrativo'], true);
}

/**
 * Mantiene disponibles el acceso del personal y el cierre de sesión.
 */
function ruta_exenta_de_mantenimiento(string $scriptName): bool
{
    $archivo = strtolower(basename(str_replace('\\', '/', $scriptName)));
    $rutaSinExtension = pathinfo($archivo, PATHINFO_FILENAME);

    return in_array($rutaSinExtension, ['login', 'logout'], true);
}

/**
 * Los procesos de migración y reparación ejecutados por consola no deben bloquearse.
 */
function debe_bloquear_por_mantenimiento(
    array $ajustes,
    array $sesion,
    string $scriptName,
    ?string $sapi = null
): bool {
    $sapi = $sapi ?? PHP_SAPI;

    if ($sapi === 'cli' || $sapi === 'phpdbg') {
        return false;
    }

    if (!modo_mantenimiento_activo($ajustes)) {
        return false;
    }

    if (rol_exento_de_mantenimiento($sesion['user_rol'] ?? null)) {
        return false;
    }

    return !ruta_exenta_de_mantenimiento($scriptName);
}

function solicitud_mantenimiento_es_json(): bool
{
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    $requestedWith = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    $scriptName = strtolower(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''));

    return strpos($accept, 'application/json') !== false
        || $requestedWith === 'xmlhttprequest'
        || strpos($scriptName, '/api/') !== false
        || preg_match('/_api(?:\.php)?$/', $scriptName) === 1;
}

/**
 * Detiene la petición con una respuesta 503 cuando corresponde.
 */
function aplicar_modo_mantenimiento(array $ajustes): void
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

    if (!debe_bloquear_por_mantenimiento($ajustes, $_SESSION ?? [], $scriptName)) {
        return;
    }

    http_response_code(503);
    header('Retry-After: 900');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if (solicitud_mantenimiento_es_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'maintenance',
            'message' => 'La plataforma se encuentra temporalmente en mantenimiento.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require __DIR__ . '/../resources/views/mantenimiento.php';
    exit;
}
