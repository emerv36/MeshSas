<?php
require_once '../config.php';
require_once '../app/Learning/Evaluations/QuizStart.php';
require_once '../app/Learning/Evaluations/QuizCierre.php';
require_once '../app/Learning/Evaluations/QuizAutoguardado.php';
require_once '../app/Learning/Evaluations/QuizTiempo.php';

use App\Learning\Evaluations\QuizStart;
use App\Learning\Evaluations\QuizCierre;
use App\Learning\Evaluations\QuizAutoguardado;
use App\Learning\Evaluations\QuizTiempo;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/../helpers/csrf_helper.php';
if (!csrf_token_valido(csrf_token_de_peticion())) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Token CSRF inválido']);
    exit;
}

$usuarioId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// El motor de quizzes trabaja en UTC (quiz_intentos.fecha es TIMESTAMP y se lee según esta zona)
$pdo->exec("SET time_zone = '+00:00'");

try {
    switch ($action) {
        case 'start':
            $leccionId = intval($_POST['leccion_id'] ?? 0);
            $startHash = $_POST['start_idempotency_key_hash'] ?? '';

            if (!$leccionId || !$startHash) {
                throw new Exception("Parámetros incompletos.", 400);
            }

            // Solo con acceso vigente al curso de la lección (y sin bloqueo secuencial)
            require_once __DIR__ . '/../helpers/acceso_helper.php';
            $stmtCurso = $pdo->prepare("SELECT m.curso_id FROM lecciones l JOIN modulos m ON l.modulo_id = m.id WHERE l.id = ? AND l.tipo = 'quiz' AND l.estado = 'publicado' AND m.estado = 'publicado'");
            $stmtCurso->execute([$leccionId]);
            $cursoQuiz = (int)$stmtCurso->fetchColumn();
            if (!$cursoQuiz
                || !obtenerEstadoAccesoCurso($usuarioId, $cursoQuiz, $pdo)['active']
                || !verificarBloqueoSecuencial($usuarioId, $cursoQuiz, $pdo)) {
                throw new Exception("No tienes acceso a este quiz.", 403);
            }

            $quizStart = new QuizStart($pdo);
            $resultado = $quizStart->iniciar($usuarioId, $leccionId, $startHash);

            echo json_encode($resultado);
            break;

        case 'autosave':
            $intentoId = intval($_POST['intento_id'] ?? 0);
            $sessionToken = $_POST['session_token'] ?? '';
            $preguntaId = intval($_POST['pregunta_id'] ?? 0);
            $textoRespuesta = $_POST['texto_respuesta'] ?? '';
            $clienteRevision = intval($_POST['revision'] ?? 0);

            if (!$intentoId || !$sessionToken || !$preguntaId) {
                throw new Exception("Parámetros de autoguardado incompletos.", 400);
            }

            $quizCierre = new QuizCierre($pdo);
            $quizAutoSave = new QuizAutoguardado($pdo, $quizCierre);

            $resultado = $quizAutoSave->guardarRespuesta($intentoId, $sessionToken, $preguntaId, $textoRespuesta, $clienteRevision);

            echo json_encode($resultado);
            break;

        case 'latido':
            $intentoId = intval($_POST['intento_id'] ?? 0);
            $sessionToken = $_POST['session_token'] ?? '';
            if (!$intentoId || !$sessionToken) {
                throw new Exception("Parámetros del latido incompletos.", 400);
            }
            $quizAutoSave = new QuizAutoguardado($pdo, new QuizCierre($pdo));
            echo json_encode($quizAutoSave->latido($intentoId, $sessionToken));
            break;

        case 'submit':
            $intentoId = intval($_POST['intento_id'] ?? 0);
            $sessionToken = $_POST['session_token'] ?? ''; // Para validación de seguridad si se requiere, o validamos por estado
            $submissionHash = $_POST['submission_idempotency_key_hash'] ?? null;

            if (!$intentoId) {
                throw new Exception("ID de intento no especificado.", 400);
            }

            // Validar que el usuario es el dueño del intento
            $stmt = $pdo->prepare("SELECT usuario_id, attempt_session_token_hash FROM quiz_intentos WHERE id = ?");
            $stmt->execute([$intentoId]);
            $intento = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$intento || $intento['usuario_id'] != $usuarioId) {
                throw new Exception("Intento inválido o no autorizado.", 403);
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT * FROM quiz_intentos WHERE id = ? FOR UPDATE");
                $stmt->execute([$intentoId]);
                $intento = $stmt->fetch(PDO::FETCH_ASSOC);

                // Un intento en curso solo lo entrega la sesión vigente, y su tiempo lo mide el
                // servidor: si ya se agotó, se califica lo guardado a tiempo y queda registrado.
                // Un intento ya cerrado pasa directo a cerrar(), que responde a los reintentos.
                $metodoCierre = 'entrega_manual';
                if ($intento['estado'] === 'in_progress') {
                    if (!is_string($sessionToken) || $sessionToken === ''
                        || !hash_equals((string)$intento['attempt_session_token_hash'], hash('sha256', $sessionToken))) {
                        throw new Exception("Token de sesión inválido.", 403);
                    }
                    $ahoraUtc = $pdo->query("SELECT UTC_TIMESTAMP()")->fetchColumn();
                    if ((new QuizTiempo($pdo))->registrarActividad($intento, $ahoraUtc)['vencido']) {
                        $metodoCierre = 'expiracion_entrega';
                    }
                }

                $quizCierre = new QuizCierre($pdo);
                $resultado = $quizCierre->cerrar($intentoId, $metodoCierre, $submissionHash);
                $pdo->commit();
                echo json_encode($resultado);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        default:
            throw new Exception("Acción no válida.", 400);
    }
} catch (Exception $e) {
    // Los errores de BD no se muestran al cliente (pueden revelar consultas y columnas)
    if ($e instanceof PDOException) {
        error_log('quiz_api: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor.']);
        exit;
    }
    $code = (int)$e->getCode() ?: 500;
    if ($code < 100 || $code > 599) $code = 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
