<?php
namespace App\Learning\Evaluations;

use PDO;
use Exception;

class QuizCancelacion {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Cancela administrativamente un intento de quiz.
     */
    public function cancelar(int $intentoId, int $actorId, string $motivo, int $consumeIntento = 1) {
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->pdo->beginTransaction();

        try {
            // 1. Bloquear el intento
            $stmtIntento = $this->pdo->prepare("SELECT * FROM quiz_intentos WHERE id = ? FOR UPDATE");
            $stmtIntento->execute([$intentoId]);
            $intento = $stmtIntento->fetch(PDO::FETCH_ASSOC);

            if (!$intento) {
                throw new Exception("Intento no encontrado.", 404);
            }

            if ($intento['estado'] === 'cancelled') {
                throw new Exception("El intento ya se encuentra cancelado.", 409);
            }
            if ($intento['estado'] === 'graded') {
                // Dependiendo de las reglas de negocio, un intento calificado podría requerir otro proceso para anular nota.
                // Aquí permitiremos cancelarlo administrativamente siempre que se informe el motivo.
            }

            // 2. Liberar control bloqueante si aplica
            $stmtControl = $this->pdo->prepare("UPDATE quiz_usuario_leccion_control SET intento_bloqueante_id = NULL WHERE intento_bloqueante_id = ?");
            $stmtControl->execute([$intentoId]);

            // 3. Cancelar
            $ahoraUtc = $this->obtenerHoraUtc();

            $stmtUpdate = $this->pdo->prepare("
                UPDATE quiz_intentos
                SET estado = 'cancelled',
                    metodo_cierre = 'cancelacion_administrativa',
                    procesado_at = ?,
                    consume_intento = ?,
                    attempt_session_token_hash = NULL
                WHERE id = ?
            ");
            $stmtUpdate->execute([$ahoraUtc, $consumeIntento, $intentoId]);

            // 4. Auditoría
            $stmtAuditoria = $this->pdo->prepare("
                INSERT INTO quiz_auditoria_eventos
                (intento_id, actor_id, actor_tipo, evento, fuente, ocurrido_at, datos_nuevos)
                VALUES (?, ?, 'admin', 'quiz_cancelacion', 'QuizCancelacion', UTC_TIMESTAMP(), ?)
            ");
            $datos = json_encode(['estado' => 'cancelled', 'motivo' => $motivo, 'consume_intento' => $consumeIntento]);
            $stmtAuditoria->execute([$intentoId, $actorId, $datos]);

            $this->pdo->commit();

            return [
                'status' => 'success',
                'message' => 'Intento cancelado exitosamente.',
                'intento_id' => $intentoId
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function obtenerHoraUtc() {
        return $this->pdo->query("SELECT UTC_TIMESTAMP() AS ahora_utc")->fetchColumn();
    }
}
