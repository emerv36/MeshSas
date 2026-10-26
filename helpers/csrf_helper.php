<?php
/**
 * Token CSRF por sesión, compartido por el panel admin y la zona del estudiante.
 * Requiere que la sesión ya esté iniciada (config.php).
 *
 * - Formularios:  <?= csrf_input() ?>
 * - AJAX:         cabecera X-CSRF-Token con get_csrf_token()
 * - Enlaces GET que modifican datos: "?del=1&" . csrf_query()
 */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function get_csrf_token(): string
{
    return $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_query(): string
{
    return 'csrf=' . urlencode(get_csrf_token());
}

function csrf_token_valido($token): bool
{
    return is_string($token) && $token !== '' && hash_equals(get_csrf_token(), $token);
}

/** Token enviado en el cuerpo POST (csrf_token) o en la cabecera X-CSRF-Token. */
function csrf_token_de_peticion(): string
{
    return (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
}

function verify_csrf_get(): void
{
    if (!csrf_token_valido($_GET['csrf'] ?? '')) {
        http_response_code(403);
        echo "<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body style='font-family:sans-serif;padding:2rem;'><h2>Error 403</h2><p>Petición rechazada por seguridad (CSRF inválido).</p><a href='index'>Volver al inicio</a></body></html>";
        exit;
    }
}
