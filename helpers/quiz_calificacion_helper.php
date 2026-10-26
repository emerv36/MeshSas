<?php
/**
 * Calificación manual de quizzes (preguntas abiertas o de archivo), por curso.
 *
 * Se califica en la pestaña Evaluaciones de admin/gestionar_curso.php: el docente solo ve
 * y califica los intentos de sus cursos. Aprobar registra la lección como completada.
 */

require_once dirname(__DIR__) . '/app/Learning/Evaluations/QuizSnapshot.php';

use App\Learning\Evaluations\QuizSnapshot;

const QUIZ_ESTADOS_PENDIENTES = ['pendiente', 'pending_review'];

/**
 * Califica un intento pendiente. Devuelve el curso del intento.
 * Lanza Exception con código 403 (no es su curso), 404, 409 (ya calificado) o 422.
 */
function quiz_calificar_intento(PDO $pdo, int $intento_id, string $nuevo_estado, string $rol, int $user_id): int
{
    if ($intento_id <= 0 || !in_array($nuevo_estado, ['aprobado', 'reprobado'], true)) {
        throw new Exception('Parámetros inválidos', 422);
    }

    $pdo->beginTransaction();
    try {
        $stmt_lock = $pdo->prepare("
            SELECT i.id, i.estado, i.usuario_id, i.leccion_id, m.curso_id
            FROM quiz_intentos i
            JOIN lecciones l ON i.leccion_id = l.id
            JOIN modulos m ON l.modulo_id = m.id
            WHERE i.id = ? FOR UPDATE
        ");
        $stmt_lock->execute([$intento_id]);
        $intento = $stmt_lock->fetch(PDO::FETCH_ASSOC);

        if (!$intento) {
            throw new Exception('Not Found', 404);
        }
        if (!in_array($intento['estado'], QUIZ_ESTADOS_PENDIENTES, true)) {
            throw new Exception('Conflict', 409);
        }
        if ($rol === 'docente') {
            $stmt_curso = $pdo->prepare("SELECT 1 FROM curso_docentes WHERE curso_id = ? AND docente_id = ?");
            $stmt_curso->execute([$intento['curso_id'], $user_id]);
            if (!$stmt_curso->fetchColumn()) {
                throw new Exception('Forbidden', 403);
            }
        } elseif ($rol !== 'admin') {
            throw new Exception('Forbidden', 403);
        }

        // Mismo estado final que la calificación automática ('graded'); el resultado va en `aprobado`
        $stmt_upd = $pdo->prepare("UPDATE quiz_intentos SET estado = 'graded', aprobado = ? WHERE id = ? AND estado IN ('pendiente', 'pending_review')");
        $stmt_upd->execute([($nuevo_estado === 'aprobado' ? 1 : 0), $intento_id]);
        if ($stmt_upd->rowCount() !== 1) {
            throw new Exception('Conflict', 409);
        }

        if ($nuevo_estado === 'aprobado') {
            $pdo->prepare("INSERT INTO progreso (usuario_id, leccion_id, completado) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE completado = 1")
                ->execute([$intento['usuario_id'], $intento['leccion_id']]);
        }

        $pdo->commit();
        return (int)$intento['curso_id'];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Intentos pendientes de un curso, cada uno con sus respuestas abiertas o de archivo y la
 * pregunta que se le presentó (copia del intento; si no la tiene, el quiz actual).
 */
function quiz_pendientes_curso(PDO $pdo, int $curso_id): array
{
    $stmt = $pdo->prepare("SELECT i.*, u.nombre AS alumno, l.titulo AS leccion_titulo
                           FROM quiz_intentos i
                           JOIN usuarios u ON i.usuario_id = u.id
                           JOIN lecciones l ON i.leccion_id = l.id
                           JOIN modulos m ON l.modulo_id = m.id
                           WHERE m.curso_id = ? AND i.estado IN ('pendiente', 'pending_review')
                           ORDER BY i.fecha ASC");
    $stmt->execute([$curso_id]);
    $pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt_r = $pdo->prepare("SELECT COALESCE(quiz_version_pregunta_id, pregunta_id) AS pregunta_ref, COALESCE(texto_respuesta, respuesta_texto) AS respuesta_texto, archivo_ruta FROM quiz_respuestas WHERE intento_id = ? ORDER BY id");
    foreach ($pendientes as &$p) {
        $preguntas_intento = QuizSnapshot::decodificar($p['preguntas_snapshot'] ?? null)
            ?? (new QuizSnapshot($pdo))->capturar((int)$p['leccion_id'])['preguntas'];
        $stmt_r->execute([$p['id']]);
        $respuestas_por_pregunta = [];
        foreach ($stmt_r->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $respuestas_por_pregunta[(int)$fila['pregunta_ref']] = $fila;
        }
        $p['respuestas'] = [];
        foreach ($preguntas_intento as $pq) {
            if (in_array($pq['tipo'], ['abierta', 'archivo'], true) && isset($respuestas_por_pregunta[(int)$pq['id']])) {
                $p['respuestas'][] = $respuestas_por_pregunta[(int)$pq['id']] + ['pregunta' => $pq['pregunta'], 'tipo' => $pq['tipo']];
            }
        }
    }
    unset($p);
    return $pendientes;
}

/** Intentos pendientes por curso, [curso_id => cantidad]; el docente solo ve los de sus cursos. */
function quiz_pendientes_por_curso(PDO $pdo, string $rol, int $user_id): array
{
    $sql = "SELECT m.curso_id, COUNT(*) AS total
            FROM quiz_intentos i
            JOIN lecciones l ON i.leccion_id = l.id
            JOIN modulos m ON l.modulo_id = m.id
            WHERE i.estado IN ('pendiente', 'pending_review')";
    $params = [];
    if ($rol !== 'admin') {
        $sql .= " AND EXISTS (SELECT 1 FROM curso_docentes cd WHERE cd.curso_id = m.curso_id AND cd.docente_id = ?)";
        $params[] = $user_id;
    }
    $stmt = $pdo->prepare($sql . " GROUP BY m.curso_id");
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}
