<?php
namespace App\Learning\Evaluations;
require_once __DIR__ . '/../Access/AccessService.php';
require_once __DIR__ . '/QuizTiempo.php';

use PDO;
use Exception;

class QuizCronExpiracion {
    private $pdo;
    private $quizCierre;

    public function __construct(PDO $pdo, QuizCierre $quizCierre) {
        $this->pdo = $pdo;
        $this->quizCierre = $quizCierre;
    }

    /**
     * Ejecuta el proceso de expiración de intentos vencidos.
     */
    public function ejecutar() {
        $this->pdo->exec("SET time_zone = '+00:00'");

        // 1. Obtener lock global para evitar que dos crons corran al mismo tiempo
        $stmtLock = $this->pdo->query("SELECT GET_LOCK('quiz_cron_expiracion', 0)");
        $lockAcquired = $stmtLock->fetchColumn();

        if (!$lockAcquired) {
            echo "El cron ya está en ejecución por otro proceso.\n";
            return false;
        }

        try {
            // 2. Recorrer los intentos en curso por lotes. El reloj se pausa con la página cerrada,
            // así que un intento puede seguir abierto días o semanas sin estar vencido: no hay
            // límite de reloj real. Se cierra (calificando lo guardado) cuando:
            //   - pasó la fecha de cierre configurada en el quiz,
            //   - su tiempo consumido ya superó el límite (p. ej. se cerró la página al final), o
            //   - venció el acceso del alumno al curso: ya no podría retomarlo.
            $stmtLote = $this->pdo->prepare("
                SELECT i.*, c.fecha_fin_configuracion, m.curso_id
                FROM quiz_intentos i
                JOIN lecciones l ON l.id = i.leccion_id
                JOIN modulos m ON m.id = l.modulo_id
                LEFT JOIN quiz_configuracion c ON c.leccion_id = i.leccion_id
                WHERE i.estado = 'in_progress' AND i.id > ?
                ORDER BY i.id
                LIMIT 100
            ");
            $ahoraUtc = $this->obtenerHoraUtc();
            $tiempo = new QuizTiempo($this->pdo);
            $accesos = new \AccessService($this->pdo);
            $controlarAcceso = defined('ACCESS_EXPIRATION_ENABLED') && ACCESS_EXPIRATION_ENABLED;
            $procesados = 0;
            $ultimoId = 0;

            do {
                $stmtLote->execute([$ultimoId]);
                $lote = $stmtLote->fetchAll(PDO::FETCH_ASSOC);

                foreach ($lote as $intento) {
                    $ultimoId = (int)$intento['id'];
                    $metodo = null;

                    if (!empty($intento['fecha_fin_configuracion']) && $ahoraUtc >= $intento['fecha_fin_configuracion']) {
                        $metodo = 'cron_expiracion';
                    } elseif ($tiempo->estado($intento)['vencido']) {
                        $metodo = 'cron_tiempo_agotado';
                    } elseif ($controlarAcceso && !$accesos->courseState((int)$intento['usuario_id'], (int)$intento['curso_id'])['active']) {
                        $metodo = 'cron_acceso_vencido';
                    }

                    if ($metodo) {
                        $this->cerrarIntentoExpirado($intento['id'], $metodo);
                        $procesados++;
                    }
                }
            } while (count($lote) === 100);

            echo "Cron de expiración finalizado. Intentos cerrados: $procesados\n";
            return true;

        } finally {
            // Liberar lock siempre
            $this->pdo->query("SELECT RELEASE_LOCK('quiz_cron_expiracion')");
        }
    }

    private function cerrarIntentoExpirado($intentoId, string $metodo) {
        $this->pdo->beginTransaction();
        try {
            $this->quizCierre->cerrar($intentoId, $metodo);

            // Limpiar tabla de fallos si existía
            $this->pdo->prepare("DELETE FROM quiz_cron_fallos WHERE intento_id = ?")->execute([$intentoId]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->registrarFallo($intentoId, $e->getMessage());
        }
    }

    private function registrarFallo($intentoId, $mensajeError) {
        // En un bloque try-catch aparte para no romper el cron
        try {
            $stmtCheck = $this->pdo->prepare("SELECT id, cantidad_fallos FROM quiz_cron_fallos WHERE intento_id = ?");
            $stmtCheck->execute([$intentoId]);
            $falloExistente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($falloExistente) {
                $nuevosFallos = $falloExistente['cantidad_fallos'] + 1;
                $proximoIntento = gmdate('Y-m-d H:i:s', strtotime('+ ' . ($nuevosFallos * 15) . ' minutes')); // Backoff (UTC, como el resto del motor)

                $stmtUpd = $this->pdo->prepare("
                    UPDATE quiz_cron_fallos
                    SET cantidad_fallos = ?, ultimo_error = ?, proximo_reintento_at = ?
                    WHERE id = ?
                ");
                $stmtUpd->execute([$nuevosFallos, substr($mensajeError, 0, 500), $proximoIntento, $falloExistente['id']]);
            } else {
                $proximoIntento = gmdate('Y-m-d H:i:s', strtotime('+ 15 minutes'));
                $stmtIns = $this->pdo->prepare("
                    INSERT INTO quiz_cron_fallos (intento_id, cantidad_fallos, ultimo_error, proximo_reintento_at)
                    VALUES (?, 1, ?, ?)
                ");
                $stmtIns->execute([$intentoId, substr($mensajeError, 0, 500), $proximoIntento]);
            }
        } catch (Exception $e) {
            // Ignorar para que el cron continúe
        }
    }

    private function obtenerHoraUtc() {
        return $this->pdo->query("SELECT UTC_TIMESTAMP() AS ahora_utc")->fetchColumn();
    }
}
