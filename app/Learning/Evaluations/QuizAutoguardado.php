<?php
namespace App\Learning\Evaluations;
require_once __DIR__ . '/QuizTiempo.php';
require_once __DIR__ . '/QuizSnapshot.php';

use PDO;
use Exception;

class QuizAutoguardado {
    private $pdo;
    private $quizCierre;

    public function __construct(PDO $pdo, QuizCierre $quizCierre) {
        $this->pdo = $pdo;
        $this->quizCierre = $quizCierre;
    }

    /**
     * Autoguarda la respuesta a una pregunta y valida el token.
     */
    public function guardarRespuesta(int $intentoId, string $sessionTokenCrudo, int $preguntaId, string $textoRespuesta, int $clienteRevision) {
        return $this->conIntentoActivo($intentoId, $sessionTokenCrudo, function (array $intento, string $ahoraUtc, array $reloj) use ($intentoId, $preguntaId, $textoRespuesta, $clienteRevision) {
            // Solo preguntas del quiz que se le presentó: la copia tomada al iniciar el intento o,
            // en intentos anteriores a ella, las preguntas actuales de la lección
            $preguntasIntento = QuizSnapshot::decodificar($intento['preguntas_snapshot'] ?? null)
                ?? (new QuizSnapshot($this->pdo))->capturar((int)$intento['leccion_id'])['preguntas'];
            if (!in_array($preguntaId, array_map('intval', array_column($preguntasIntento, 'id')), true)) {
                throw new Exception("La pregunta no pertenece a este intento.", 400);
            }

            // Guardar la respuesta con control optimista
            $stmtRespuesta = $this->pdo->prepare("SELECT * FROM quiz_respuestas WHERE intento_id = ? AND quiz_version_pregunta_id = ? FOR UPDATE");
            $stmtRespuesta->execute([$intentoId, $preguntaId]);
            $respuesta = $stmtRespuesta->fetch(PDO::FETCH_ASSOC);

            if ($respuesta) {
                if ($respuesta['texto_respuesta'] === $textoRespuesta) {
                    // Si el texto no cambió, no hacemos nada con la revisión
                    $nuevaRevision = $respuesta['revision'];
                } else {
                    if ($clienteRevision < $respuesta['revision']) {
                        throw new Exception("Conflicto de edición: Una versión más reciente de la respuesta ya fue guardada.", 409);
                    }
                    $nuevaRevision = $respuesta['revision'] + 1;
                    $stmtUpdate = $this->pdo->prepare("UPDATE quiz_respuestas SET texto_respuesta = ?, revision = ?, guardado_at = ? WHERE id = ?");
                    $stmtUpdate->execute([$textoRespuesta, $nuevaRevision, $ahoraUtc, $respuesta['id']]);
                }
            } else {
                $nuevaRevision = 1;
                // pregunta_id referencia la pregunta viva; si se borró durante el intento queda NULL
                // y la respuesta se sigue ubicando por quiz_version_pregunta_id (el id de la copia)
                $stmtExiste = $this->pdo->prepare("SELECT id FROM quiz_preguntas WHERE id = ?");
                $stmtExiste->execute([$preguntaId]);
                $preguntaViva = $stmtExiste->fetchColumn() ? $preguntaId : null;
                $stmtInsert = $this->pdo->prepare("INSERT INTO quiz_respuestas (intento_id, quiz_version_pregunta_id, pregunta_id, texto_respuesta, revision, guardado_at) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtInsert->execute([$intentoId, $preguntaId, $preguntaViva, $textoRespuesta, $nuevaRevision, $ahoraUtc]);
            }

            $this->pdo->prepare("UPDATE quiz_intentos SET revision = revision + 1 WHERE id = ?")->execute([$intentoId]);

            return [
                'status' => 'success',
                'message' => 'Autoguardado exitoso.',
                'server_revision' => $nuevaRevision,
                'segundos_restantes' => $reloj['restantes']
            ];
        });
    }

    /**
     * Pulso de actividad de la página abierta: mantiene el reloj al día y avisa si venció.
     */
    public function latido(int $intentoId, string $sessionTokenCrudo) {
        return $this->conIntentoActivo($intentoId, $sessionTokenCrudo, fn(array $intento, string $ahoraUtc, array $reloj) => [
            'status' => 'success',
            'segundos_restantes' => $reloj['restantes']
        ]);
    }

    /**
     * Bloquea el intento, valida token y estado, y registra el tiempo transcurrido.
     * Si el tiempo se agotó, cierra el intento sin aceptar lo que llega tarde.
     */
    private function conIntentoActivo(int $intentoId, string $sessionTokenCrudo, callable $accion) {
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->pdo->beginTransaction();

        try {
            $ahoraUtc = $this->obtenerHoraUtc();

            // 1. Bloquear intento
            $stmtIntento = $this->pdo->prepare("SELECT * FROM quiz_intentos WHERE id = ? FOR UPDATE");
            $stmtIntento->execute([$intentoId]);
            $intento = $stmtIntento->fetch(PDO::FETCH_ASSOC);

            if (!$intento) {
                throw new Exception("Intento no encontrado.", 404);
            }

            // 2. Validar Token de Sesión
            $sessionTokenHash = hash('sha256', $sessionTokenCrudo);
            if ($intento['attempt_session_token_hash'] !== $sessionTokenHash) {
                throw new Exception("Token de sesión inválido o expirado. El intento podría haber sido cerrado o continuado desde otro dispositivo.", 403);
            }

            // 3. Validar Estado
            if (!in_array($intento['estado'], ['in_progress'])) {
                throw new Exception("El intento no se encuentra en progreso.", 409);
            }

            // 4. Tiempo medido por el servidor (el que reporta el navegador no se usa)
            $reloj = (new QuizTiempo($this->pdo))->registrarActividad($intento, $ahoraUtc);
            if ($reloj['vencido']) {
                $this->quizCierre->cerrar($intentoId, 'expiracion_autosave');
                $this->pdo->commit();
                return ['status' => 'expired', 'message' => 'El tiempo ha terminado. Intento cerrado automáticamente.'];
            }

            $resultado = $accion($intento, $ahoraUtc, $reloj);
            $this->pdo->commit();
            return $resultado;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function obtenerHoraUtc() {
        return $this->pdo->query("SELECT UTC_TIMESTAMP() AS ahora_utc")->fetchColumn();
    }
}
