<?php
namespace App\Learning\Evaluations;

use PDO;

require_once __DIR__ . '/QuizStart.php';

/**
 * Retroalimentación al alumno sobre su último intento cerrado de un quiz.
 *
 * Revisión progresiva: siempre ve la nota y qué preguntas acertó o falló; las respuestas
 * correctas solo se revelan cuando ya aprobó o no le quedan intentos, para que el siguiente
 * intento no se convierta en copiar las respuestas.
 *
 * La corrección por pregunta es la de QuizCierre (mismas reglas que la nota). Los intentos
 * calificados a mano, los del sistema anterior y los anteriores a la copia de preguntas de
 * un quiz editado después no tienen una corrección por pregunta fiable: solo su resultado.
 */
final class QuizRevision
{
    public function __construct(private PDO $pdo) {}

    /**
     * Resume el último intento cerrado o, si ya aprobó, el último aprobado (después de
     * aprobar no hay intentos nuevos, pero sí puede haberlos del historial).
     *
     * @return array|null null si el alumno aún no tiene intentos cerrados en este quiz
     */
    public function resumen(int $usuarioId, int $leccionId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, intento_numero, estado, puntaje, aprobado,
                    EXISTS(SELECT 1 FROM quiz_auditoria_eventos a
                           WHERE a.intento_id = quiz_intentos.id AND a.evento = 'quiz_cierre'
                             AND JSON_UNQUOTE(JSON_EXTRACT(a.datos_nuevos, '$.estado')) = 'pending_review') AS revisado_manual
             FROM quiz_intentos
             WHERE usuario_id = ? AND leccion_id = ? AND estado NOT IN ('in_progress', 'cancelled')
             ORDER BY (aprobado = 1 AND estado IN ('graded', 'aprobado')) DESC, fecha DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute([$usuarioId, $leccionId]);
        $intento = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$intento) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(consume_intento = 1), 0) AS consumidos,
                    COALESCE(MAX(estado = 'in_progress'), 0) AS en_curso
             FROM quiz_intentos
             WHERE usuario_id = ? AND leccion_id = ?"
        );
        $stmt->execute([$usuarioId, $leccionId]);
        $totales = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $this->pdo->prepare('SELECT max_intentos FROM quiz_configuracion WHERE leccion_id = ?');
        $stmt->execute([$leccionId]);
        $maxIntentos = (int)$stmt->fetchColumn();
        $consumidos = (int)$totales['consumidos'];
        $restantes = $maxIntentos > 0 ? max(0, $maxIntentos - $consumidos) : null;

        $yaAprobo = (new QuizStart($this->pdo))->yaAprobo($usuarioId, $leccionId);
        $revelar = $yaAprobo || ($restantes === 0 && !$totales['en_curso']);

        $cierre = new QuizCierre($this->pdo);
        $resumen = [
            'intento_numero' => $intento['intento_numero'] !== null ? (int)$intento['intento_numero'] : null,
            'estado' => $intento['estado'],
            'puntaje' => (float)$intento['puntaje'],
            'aprobado' => (bool)$intento['aprobado'],
            // Calificados automáticamente o migrados del sistema anterior. Tras una revisión
            // manual el puntaje solo cuenta las preguntas automáticas y no refleja la nota.
            'mostrar_puntaje' => in_array($intento['estado'], ['graded', 'aprobado'], true) && !$intento['revisado_manual'],
            'minimo' => $cierre->puntajeMinimo($leccionId),
            'intentos_restantes' => $restantes,
            'ya_aprobo' => $yaAprobo,
            'revelar' => $revelar,
            'preguntas' => null,
        ];

        // Solo los intentos calificados automáticamente tienen corrección por pregunta
        if ($intento['estado'] !== 'graded') {
            return $resumen;
        }

        $preguntas = array_values(array_filter(
            $cierre->corregirPreguntas($leccionId, (int)$intento['id']),
            static fn(array $p): bool => $p['correccion'] === 'automatica'
        ));
        // Los intentos anteriores a la copia de preguntas se corrigen con el quiz actual: si se
        // editó después (preguntas u opciones nuevas), la corrección ya no reproduce la nota y
        // el detalle mostraría como falladas respuestas que se calificaron bien. Solo la nota.
        $acertadas = count(array_filter($preguntas, static fn(array $p): bool => (bool)$p['correcta']));
        $puntajeActual = $preguntas !== [] ? round($acertadas / count($preguntas) * 100, 2) : 0.0;
        if (abs($puntajeActual - $resumen['puntaje']) > 0.01) {
            return $resumen;
        }

        $resumen['preguntas'] = array_map(static function (array $p) use ($revelar): array {
            $item = [
                'texto' => $p['pregunta'],
                'resultado' => $p['correcta'] ? 'correcta' : ($p['respondida'] ? 'incorrecta' : 'sin_responder'),
                'opciones' => null,
            ];
            if ($revelar) {
                $item['opciones'] = array_map(static fn(array $o): array => [
                    'texto' => $o['texto'],
                    'correcta' => in_array((string)$o['id'], $p['esperadas'], true),
                    'elegida' => in_array((string)$o['id'], $p['seleccionadas'], true),
                ], $p['opciones']);
            }
            return $item;
        }, $preguntas);

        return $resumen;
    }
}
