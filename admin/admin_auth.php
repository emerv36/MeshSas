<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_rol'])) {
    header("Location: ../login");
    exit;
}

$user_id = $_SESSION['user_id'];
$rol = $_SESSION['user_rol'];

// El panel es solo para personal; estudiantes y administrativos usan /dashboard
if (!in_array($rol, ['admin', 'docente', 'tesoreria'], true)) {
    header("Location: ../dashboard/index");
    exit;
}

// Token CSRF persistente y funciones comunes (get_csrf_token, csrf_input, csrf_query, verify_csrf_get)
require_once __DIR__ . '/../helpers/csrf_helper.php';

// Función para validar CSRF
function verify_csrf() {
    if (!csrf_token_valido(csrf_token_de_peticion())) {
        http_response_code(403);
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                  || (isset($_POST['action']) && $_POST['action'] === 'reorder')
                  || (isset($_POST['action']) && $_POST['action'] === 'toggle_bloqueo');

        if ($isAjax || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'CSRF token inválido o expirado.']);
        } else {
            echo "<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body style='font-family:sans-serif;padding:2rem;'><h2>Error 403</h2><p>Petición rechazada por seguridad (CSRF inválido).</p><a href='index'>Volver al inicio</a></body></html>";
        }
        exit;
    }
}

// Validación CSRF automática para TODAS las peticiones POST (mutaciones)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

// Función para requerir roles
function require_rol($allowed_roles) {
    global $rol;
    if (!is_array($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }

    if (!in_array($rol, $allowed_roles, true)) {
        http_response_code(403);
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                  || (isset($_POST['action']) && $_POST['action'] === 'reorder')
                  || (isset($_POST['action']) && $_POST['action'] === 'toggle_bloqueo');

        if ($isAjax || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado (Rol insuficiente).']);
        } else {
            echo "<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body style='font-family:sans-serif;padding:2rem;'><h2>Error 403</h2><p>Acceso denegado (Rol insuficiente).</p><a href='index'>Volver al inicio</a></body></html>";
        }
        exit;
    }
}

// ¿Puede el usuario actual gestionar este curso? Admin siempre; docente solo si está asignado.
function puede_gestionar_curso(PDO $pdo, int $curso_id): bool {
    global $rol, $user_id;
    if ($rol === 'admin') {
        return true;
    }
    if ($rol !== 'docente' || $curso_id <= 0) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM curso_docentes WHERE curso_id = ? AND docente_id = ?");
    $stmt->execute([$curso_id, $user_id]);
    return (bool) $stmt->fetchColumn();
}
?>
