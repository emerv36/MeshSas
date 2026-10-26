<?php
/**
 * Puntos de experiencia (XP) del estudiante: los mismos en Mis Logros y en la lección.
 * 100 por lección completada (progreso.completado = 1; track_time guarda filas sin completar), 200 por quiz aprobado (uno por lección, sea calificación
 * automática o manual ('graded') o del sistema anterior ('aprobado')) y 200 por ejercicio
 * práctico aprobado.
 *
 * @return array{lecciones:int, quizzes:int, practicas:int, xp:int}
 */
function xp_usuario(PDO $pdo, int $usuario_id): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM progreso WHERE usuario_id = ? AND completado = 1");
    $stmt->execute([$usuario_id]);
    $lecciones = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT leccion_id) FROM quiz_intentos WHERE usuario_id = ? AND aprobado = 1 AND estado IN ('graded', 'aprobado')");
    $stmt->execute([$usuario_id]);
    $quizzes = (int)$stmt->fetchColumn();

    // Prácticas aprobadas: con la nota mínima de cada ejercicio (la de Configuración si no tiene)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM respuestas_excel re JOIN lecciones l ON l.id = re.leccion_id
                           WHERE re.usuario_id = ? AND re.puntaje >= COALESCE(NULLIF(l.puntaje_minimo, 0), ?)");
    $stmt->execute([$usuario_id, defined('PASSING_GRADE') ? PASSING_GRADE : 70]);
    $practicas = (int)$stmt->fetchColumn();

    return [
        'lecciones' => $lecciones,
        'quizzes' => $quizzes,
        'practicas' => $practicas,
        'xp' => $lecciones * 100 + $quizzes * 200 + $practicas * 200,
    ];
}
