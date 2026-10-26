<?php
namespace App\Learning\Evaluations;

use PDO;
use Exception;

class QuizOutboxConsumer {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function ejecutar() {
        $this->pdo->exec("SET time_zone = '+00:00'");

        $stmtLock = $this->pdo->query("SELECT GET_LOCK('quiz_outbox_consumer', 0)");
        if (!$stmtLock->fetchColumn()) {
            echo "El consumidor Outbox ya está en ejecución.\n";
            return false;
        }

        try {
            // Obtener eventos pendientes
            $sql = "
                SELECT * FROM quiz_eventos_pendientes
                WHERE estado = 'pendiente'
                OR (estado = 'fallido' AND intentos < 5 AND proximo_reintento_at <= UTC_TIMESTAMP())
                LIMIT 100
                FOR UPDATE SKIP LOCKED
            ";

            // Nota: FOR UPDATE SKIP LOCKED requiere MySQL 8.0+.
            // En versiones anteriores, se puede usar un UPDATE estado = 'procesando' con LIMIT.
            // Para seguridad genérica, usaremos una actualización inicial.

            $stmtReserve = $this->pdo->prepare("
                UPDATE quiz_eventos_pendientes
                SET estado = 'procesando', bloqueado_at = UTC_TIMESTAMP()
                WHERE id IN (
                    SELECT id FROM (
                        SELECT id FROM quiz_eventos_pendientes
                        WHERE estado = 'pendiente'
                        OR (estado = 'fallido' AND intentos < 5 AND proximo_reintento_at <= UTC_TIMESTAMP())
                        LIMIT 50
                    ) as tmp
                )
            ");
            $stmtReserve->execute();

            $reservados = $this->pdo->query("SELECT * FROM quiz_eventos_pendientes WHERE estado = 'procesando'")->fetchAll(PDO::FETCH_ASSOC);
            $procesados = 0;

            foreach ($reservados as $evento) {
                if ($this->procesarEvento($evento)) {
                    $procesados++;
                }
            }

            echo "Consumidor Outbox finalizado. Eventos procesados exitosamente: $procesados\n";
            return true;
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('quiz_outbox_consumer')");
        }
    }

    private function procesarEvento($evento) {
        try {
            // Lógica específica por tipo de evento
            if ($evento['tipo'] === 'recalcular_progreso') {
                $this->recalcularProgreso($evento['entidad_id']);
            }

            // Marcar completado
            $stmtCompletado = $this->pdo->prepare("UPDATE quiz_eventos_pendientes SET estado = 'completado', procesado_at = UTC_TIMESTAMP() WHERE id = ?");
            $stmtCompletado->execute([$evento['id']]);
            return true;

        } catch (Exception $e) {
            // Manejar fallo y programar reintento
            $intentos = $evento['intentos'] + 1;
            $estado = ($intentos >= 5) ? 'abandonado' : 'fallido';
            // En UTC: se compara con UTC_TIMESTAMP() al seleccionar reintentos
            $proximoReintento = gmdate('Y-m-d H:i:s', strtotime('+ ' . ($intentos * 10) . ' minutes'));

            $stmtFallo = $this->pdo->prepare("
                UPDATE quiz_eventos_pendientes
                SET estado = ?, intentos = ?, ultimo_error_codigo = ?, proximo_reintento_at = ?
                WHERE id = ?
            ");
            $stmtFallo->execute([$estado, $intentos, substr($e->getMessage(), 0, 50), $proximoReintento, $evento['id']]);
            return false;
        }
    }

    private function recalcularProgreso($intentoId) {
        $stmt = $this->pdo->prepare('SELECT usuario_id, leccion_id FROM quiz_intentos WHERE id = ?');
        $stmt->execute([(int)$intentoId]);
        $intento = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$intento) {
            return;
        }

        $stmtAprobado = $this->pdo->prepare(
            'SELECT 1 FROM quiz_intentos
             WHERE usuario_id = ? AND leccion_id = ? AND aprobado = 1
             LIMIT 1'
        );
        $stmtAprobado->execute([(int)$intento['usuario_id'], (int)$intento['leccion_id']]);
        if (!$stmtAprobado->fetchColumn()) {
            return;
        }

        // track_time puede haber creado ya la fila con completado = 0 (clave única user_lesson)
        $stmtProgreso = $this->pdo->prepare(
            'INSERT INTO progreso (usuario_id, leccion_id, completado) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE completado = 1'
        );
        $stmtProgreso->execute([
            (int)$intento['usuario_id'],
            (int)$intento['leccion_id'],
        ]);
    }
}
