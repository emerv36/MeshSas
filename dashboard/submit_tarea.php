<?php
require_once '../config.php';

header('Content-Type: application/json; charset=UTF-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

require_once __DIR__ . '/../helpers/csrf_helper.php';
if (!csrf_token_valido(csrf_token_de_peticion())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido']);
    exit;
}

$usuario_id = $_SESSION['user_id'];
$leccion_id = filter_var($_POST['leccion_id'] ?? 0, FILTER_VALIDATE_INT);
$comentarios = trim($_POST['comentarios'] ?? '');

if (!$leccion_id) {
    echo json_encode(['success' => false, 'error' => 'ID de lección inválido']);
    exit;
}

// Verificar que la lección sea de tipo tarea
$stmt = $pdo->prepare("SELECT l.id, l.tipo, l.tarea_formatos, l.tarea_dias_limite, m.curso_id FROM lecciones l JOIN modulos m ON l.modulo_id = m.id WHERE l.id = ? AND l.estado = 'publicado' AND m.estado = 'publicado'");
$stmt->execute([$leccion_id]);
$leccion = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$leccion || $leccion['tipo'] !== 'tarea') {
    echo json_encode(['success' => false, 'error' => 'La lección no es de tipo tarea']);
    exit;
}

// El estudiante debe tener acceso vigente al curso de la lección
require_once __DIR__ . '/../helpers/acceso_helper.php';
if (!obtenerEstadoAccesoCurso($usuario_id, (int)$leccion['curso_id'], $pdo)['active']
    || !verificarBloqueoSecuencial($usuario_id, (int)$leccion['curso_id'], $pdo)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No tienes acceso a este curso']);
    exit;
}

/**
 * ¿Se puede entregar según la última fila del estudiante en esta lección?
 * - Sin fila, o rechazada: entrega nueva (se conserva el historial).
 * - Pendiente sin archivo: es la fila que crea clase.php al abrir la lección; se completa.
 * - Pendiente con archivo: está en revisión. Aprobada: cerrada.
 */
function error_entrega_tarea($entrega): ?string {
    if (!$entrega || $entrega['estado'] === 'rechazado') return null;
    if ($entrega['estado'] === 'aprobado') return 'Esta tarea ya fue aprobada.';
    if ($entrega['estado'] === 'pendiente' && empty($entrega['archivo_ruta'])) return null;
    return 'Tu entrega está en revisión. Podrás enviar otra si el docente la rechaza.';
}

$sql_ultima_entrega = "SELECT id, estado, archivo_ruta FROM tareas_entregas WHERE leccion_id = ? AND usuario_id = ? ORDER BY id DESC LIMIT 1";
$stmt = $pdo->prepare($sql_ultima_entrega);
$stmt->execute([$leccion_id, $usuario_id]);
$error_entrega = error_entrega_tarea($stmt->fetch(PDO::FETCH_ASSOC));
if ($error_entrega !== null) {
    echo json_encode(['success' => false, 'error' => $error_entrega]);
    exit;
}

if (!isset($_FILES['archivo_tarea']) || $_FILES['archivo_tarea']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Error al subir el archivo']);
    exit;
}

$file = $_FILES['archivo_tarea'];
$max_size = 5 * 1024 * 1024; // 5MB

if ($file['size'] > $max_size) {
    echo json_encode(['success' => false, 'error' => 'El archivo supera el límite de 5MB']);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

// Lista blanca global: ningún formato fuera de ella se acepta, aunque la lección diga '*'
$formatos_seguros = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'csv', 'zip', 'rar', '7z', 'jpg', 'jpeg', 'png', 'webp'];
$formatos_leccion = array_filter(array_map('trim', explode(',', strtolower($leccion['tarea_formatos'] ?: 'pdf,docx,zip,xlsx,rar'))));
$formatos_permitidos = in_array('*', $formatos_leccion, true)
    ? $formatos_seguros
    : array_values(array_intersect(array_map(fn($f) => ltrim($f, '.'), $formatos_leccion), $formatos_seguros));

if (!in_array($ext, $formatos_permitidos, true)) {
    echo json_encode(['success' => false, 'error' => 'Formato de archivo no permitido. Permitidos: ' . implode(', ', $formatos_permitidos)]);
    exit;
}

// En los formatos que el navegador puede abrir, el contenido real debe coincidir con la extensión
$mimes_por_extension = [
    'pdf' => ['application/pdf'],
    'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
    'png' => ['image/png'], 'webp' => ['image/webp'],
];
if (isset($mimes_por_extension[$ext])) {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $mimes_por_extension[$ext], true)) {
        echo json_encode(['success' => false, 'error' => 'El contenido del archivo no corresponde a su extensión']);
        exit;
    }
}

$upload_dir = '../uploads/tareas/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$filename = 'tarea_' . $leccion_id . '_' . $usuario_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$filepath = $upload_dir . $filename;
$db_path = 'uploads/tareas/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['success' => false, 'error' => 'No se pudo guardar el archivo en el servidor']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Se vuelve a leer con bloqueo: dos envíos simultáneos no deben crear dos entregas
    $stmt = $pdo->prepare($sql_ultima_entrega . " FOR UPDATE");
    $stmt->execute([$leccion_id, $usuario_id]);
    $ultima_entrega = $stmt->fetch(PDO::FETCH_ASSOC);
    $error_entrega = error_entrega_tarea($ultima_entrega);
    if ($error_entrega !== null) {
        throw new RuntimeException($error_entrega);
    }

    if ($ultima_entrega && $ultima_entrega['estado'] === 'pendiente') {
        $stmt = $pdo->prepare("UPDATE tareas_entregas SET archivo_ruta = ?, comentarios_alumno = ?, fecha_envio = NOW() WHERE id = ? AND (archivo_ruta IS NULL OR archivo_ruta = '')");
        $stmt->execute([$db_path, $comentarios, $ultima_entrega['id']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('La entrega cambió mientras se enviaba. Recarga la página.');
        }
    } else {
        $stmt = $pdo->prepare("INSERT INTO tareas_entregas (leccion_id, usuario_id, archivo_ruta, comentarios_alumno, estado, fecha_visto, fecha_envio) VALUES (?, ?, ?, ?, 'pendiente', NOW(), NOW())");
        $stmt->execute([$leccion_id, $usuario_id, $db_path, $comentarios]);
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @unlink($filepath);
    // PDOException también es RuntimeException: sus mensajes no se muestran al estudiante
    if ($e instanceof RuntimeException && !$e instanceof PDOException) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        error_log('submit_tarea: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'No se pudo registrar la entrega']);
    }
}
