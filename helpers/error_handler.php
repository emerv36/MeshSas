<?php

/**
 * Sistema global de captura y gestión de errores y excepciones
 */

/**
 * Determina si la petición actual espera una respuesta JSON o fue realizada por AJAX.
 */
function es_solicitud_ajax_o_json(): bool
{
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    $requestedWith = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    $scriptName = strtolower(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''));

    return strpos($accept, 'application/json') !== false
        || strpos($contentType, 'application/json') !== false
        || $requestedWith === 'xmlhttprequest'
        || strpos($scriptName, '/api/') !== false
        || preg_match('/_api(?:\.php)?$/', $scriptName) === 1
        || (isset($_POST['action']) && in_array($_POST['action'], ['reorder', 'toggle_bloqueo', 'guardar_progreso', 'submit_tarea']));
}

/**
 * Registra el detalle del error en el log interno del servidor de forma privada y segura.
 */
function registrar_log_error(string $ref_id, $error): void
{
    $url = ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '');
    $user_id = $_SESSION['user_id'] ?? 'Anónimo';
    $user_rol = $_SESSION['user_rol'] ?? 'Invitado';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if ($error instanceof Throwable) {
        $tipo = get_class($error);
        $mensaje = $error->getMessage();
        $archivo = $error->getFile();
        $linea = $error->getLine();
        $trace = $error->getTraceAsString();
    } elseif (is_array($error)) {
        $tipo = 'FatalError (' . ($error['type'] ?? 'E_UNKNOWN') . ')';
        $mensaje = $error['message'] ?? 'Error desconocido';
        $archivo = $error['file'] ?? 'Desconocido';
        $linea = $error['line'] ?? 0;
        $trace = 'N/A (Shutdown fatal)';
    } else {
        $tipo = 'ErrorGenerico';
        $mensaje = (string)$error;
        $archivo = 'Desconocido';
        $linea = 0;
        $trace = 'N/A';
    }

    $log_entry = sprintf(
        "[%s] [REF: %s] %s: %s en %s:%d | Usuario: #%s (%s) | IP: %s | URL: %s\nStack trace:\n%s\n",
        date('Y-m-d H:i:s'),
        $ref_id,
        $tipo,
        $mensaje,
        $archivo,
        $linea,
        $user_id,
        $user_rol,
        $ip,
        $url,
        $trace
    );

    error_log($log_entry);
}

/**
 * Renderiza la respuesta de error 500 (JSON o Vista HTML amigable).
 */
function responder_error_500($error, array $ajustes = []): void
{
    // Limpiar buffers previos para que no se muestre HTML incompleto o roto
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $ref_id = 'ERR-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));
    registrar_log_error($ref_id, $error);

    http_response_code(500);

    // Si es AJAX/API/JSON -> Devolver JSON estructurado
    if (es_solicitud_ajax_o_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'status'  => 'error',
            'error'   => 'Ocurrió un error interno en el servidor. Por favor intenta nuevamente o contacta a soporte.',
            'ref_id'  => $ref_id
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Modo Depuración (para desarrollo local o administradores autorizados)
    $es_debug = filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN) || (isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'admin' && isset($_GET['debug']));

    // Datos del error para la vista
    $error_info = [
        'ref_id'   => $ref_id,
        'es_debug' => $es_debug,
        'mensaje'  => ($error instanceof Throwable) ? $error->getMessage() : ($error['message'] ?? 'Error interno del servidor'),
        'archivo'  => ($error instanceof Throwable) ? $error->getFile() : ($error['file'] ?? ''),
        'linea'    => ($error instanceof Throwable) ? $error->getLine() : ($error['line'] ?? 0),
        'trace'    => ($error instanceof Throwable) ? $error->getTraceAsString() : ''
    ];

    $view_path = __DIR__ . '/../resources/views/500.php';
    if (file_exists($view_path)) {
        require $view_path;
    } else {
        // Fallback ultra-seguro en caso de que la vista no exista
        echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>Error 500</title><style>body{font-family:sans-serif;text-align:center;padding:4rem 1rem;background:#0f172a;color:#fff;}</style></head><body><h1>Inconveniente Temporal</h1><p>Hemos registrado este evento. Código de referencia: <strong>{$ref_id}</strong></p><a href='/' style='color:#38bdf8;'>Volver al inicio</a></body></html>";
    }
    exit;
}

/**
 * Inicializa los manejadores globales en config.php
 */
function inicializar_manejador_errores(array $ajustes = []): void
{
    // 1. Manejador global de excepciones no capturadas (PDOException, Error, Throwable)
    set_exception_handler(function (Throwable $e) use ($ajustes) {
        responder_error_500($e, $ajustes);
    });

    // 2. Manejador de apagado para capturar Errores Fatales (E_ERROR, E_PARSE, E_COMPILE_ERROR)
    register_shutdown_function(function () use ($ajustes) {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            responder_error_500($error, $ajustes);
        }
    });
}
