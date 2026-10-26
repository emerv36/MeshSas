<?php
namespace App\Learning\Evaluations;
require_once __DIR__ . '/../Access/AccessService.php';
require_once __DIR__ . '/QuizTiempo.php';
require_once __DIR__ . '/QuizCierre.php';
require_once __DIR__ . '/QuizSnapshot.php';

use PDO;
use Exception;

class QuizStart {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Inicia un nuevo intento de Quiz o retoma el que está en curso.
     * Implementa protección de concurrencia e idempotencia.
     */
    public function iniciar(int $usuarioId, int $leccionId, string $startIdempotencyKeyHash, array $opciones = []) {
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->pdo->beginTransaction();

        try {
            // 1. Obtener hora UTC actual
            $stmtTime = $this->pdo->query("SELECT UTC_TIMESTAMP() AS ahora_utc");
            $ahoraUtc = $stmtTime->fetch(PDO::FETCH_ASSOC)['ahora_utc'];

            // 2. Asegurar existencia de fila de control y bloquear
            $stmtControl = $this->pdo->prepare("SELECT * FROM quiz_usuario_leccion_control WHERE usuario_id = ? AND leccion_id = ? FOR UPDATE");
            $stmtControl->execute([$usuarioId, $leccionId]);
            $control = $stmtControl->fetch(PDO::FETCH_ASSOC);

            if (!$control) {
                $stmtInsertControl = $this->pdo->prepare("INSERT INTO quiz_usuario_leccion_control (usuario_id, leccion_id, ultimo_intento_numero) VALUES (?, ?, 0)");
                $stmtInsertControl->execute([$usuarioId, $leccionId]);

                // Volver a bloquear para asegurar
                $stmtControl->execute([$usuarioId, $leccionId]);
                $control = $stmtControl->fetch(PDO::FETCH_ASSOC);
            }

            // 3. Comprobar Idempotencia (¿Ya se inició con esta clave?)
            $intentoReanudarId = null;
            $stmtIdemp = $this->pdo->prepare("SELECT id, estado FROM quiz_intentos WHERE usuario_id = ? AND leccion_id = ? AND start_idempotency_key_hash = ?");
            $stmtIdemp->execute([$usuarioId, $leccionId, $startIdempotencyKeyHash]);
            $intentoExistente = $stmtIdemp->fetch(PDO::FETCH_ASSOC);

            if ($intentoExistente) {
                if ($intentoExistente['estado'] !== 'in_progress') {
                    // La clave pertenece a un intento ya cerrado: el navegador debe descartarla
                    $this->pdo->commit();
                    return [
                        'status' => 'finalizado',
                        'message' => 'Este intento ya terminó. Recarga la página para ver tu resultado.'
                    ];
                }
                // Misma clave y aún en curso: se trata como una reanudación
                $intentoReanudarId = (int)$intentoExistente['id'];
            }

            // 4. Comprobar intentos bloqueantes / Reanudar intento
            if (!$intentoReanudarId && !empty($control['intento_bloqueante_id'])) {
                $stmtVerif = $this->pdo->prepare("SELECT id, estado FROM quiz_intentos WHERE id = ?");
                $stmtVerif->execute([$control['intento_bloqueante_id']]);
                $intentoActivo = $stmtVerif->fetch(PDO::FETCH_ASSOC);

                // Solo reanudar si está en progreso (no enviado aún)
                if ($intentoActivo && $intentoActivo['estado'] === 'in_progress') {
                    $intentoReanudarId = $intentoActivo['id'];
                }
                // pending_review = ya fue entregado, no bloquear
            }

            if (!$intentoReanudarId) {
                // Alternativa: buscar solo intentos realmente en progreso (no enviados)
                $stmtCheckActivos = $this->pdo->prepare("SELECT id, estado FROM quiz_intentos WHERE usuario_id = ? AND leccion_id = ? AND estado = 'in_progress' ORDER BY id DESC LIMIT 1");
                $stmtCheckActivos->execute([$usuarioId, $leccionId]);
                $activoAlt = $stmtCheckActivos->fetch(PDO::FETCH_ASSOC);
                if ($activoAlt) {
                    $intentoReanudarId = $activoAlt['id'];
                }
            }

            // Un intento ya iniciado puede terminar; uno nuevo requiere acceso vigente.
            if (!$intentoReanudarId && defined('ACCESS_EXPIRATION_ENABLED') && ACCESS_EXPIRATION_ENABLED) {
                $stmtCurso = $this->pdo->prepare(
                    'SELECT m.curso_id
                     FROM lecciones l
                     JOIN modulos m ON m.id = l.modulo_id
                     WHERE l.id = ?'
                );
                $stmtCurso->execute([$leccionId]);
                $cursoId = (int)$stmtCurso->fetchColumn();
                if (!$cursoId) {
                    throw new Exception('La lección no pertenece a un curso válido.', 400);
                }
                $estadoAcceso = (new \AccessService($this->pdo))->courseState($usuarioId, $cursoId);
                if (!$estadoAcceso['active']) {
                    throw new Exception('Tu periodo de acceso al curso ha vencido.', 403);
                }
            }

            // 5. Validar Configuración y Versión Activa
            $stmtConf = $this->pdo->prepare("
                SELECT c.*, v.estado as version_estado
                FROM quiz_configuracion c
                JOIN quiz_versiones v ON c.quiz_version_activa_id = v.id
                WHERE c.leccion_id = ?
            ");
            $stmtConf->execute([$leccionId]);
            $config = $stmtConf->fetch(PDO::FETCH_ASSOC);

            if (!$config || $config['version_estado'] !== 'publicada') {
                throw new Exception("No hay una versión publicada disponible para este quiz.", 400);
            }

            // 6. Validar Fronteras Temporales
            if (!empty($config['fecha_fin_configuracion']) && $ahoraUtc >= $config['fecha_fin_configuracion']) {
                throw new Exception("El quiz ya no se encuentra disponible (fuera de fecha).", 403);
            }

            // 7. Reanudar: va antes del límite de intentos, porque el intento en curso ya cuenta
            // como consumido y, si era el último permitido, el alumno no podría volver a él.
            if ($intentoReanudarId) {
                $resultado = $this->reanudar((int)$intentoReanudarId, $ahoraUtc);
                $this->pdo->commit();
                return $resultado;
            }

            // 8. Un quiz aprobado no admite intentos nuevos: tras aprobar, la revisión muestra
            // las respuestas correctas y repetirlo sería copiarlas.
            if ($this->yaAprobo($usuarioId, $leccionId)) {
                throw new Exception("Ya aprobaste esta evaluación.", 403);
            }

            // Límite de intentos (solo para un intento nuevo)
            $stmtCount = $this->pdo->prepare("SELECT COUNT(*) FROM quiz_intentos WHERE usuario_id = ? AND leccion_id = ? AND consume_intento = 1");
            $stmtCount->execute([$usuarioId, $leccionId]);
            $intentosConsumidos = $stmtCount->fetchColumn();

            // Aquí se deberían sumar autorizaciones de intentos extra si existen.
            $maxIntentos = $config['max_intentos']; // + autorizaciones

            if ($maxIntentos > 0 && $intentosConsumidos >= $maxIntentos) {
                throw new Exception("Has superado el límite de intentos permitidos.", 403);
            }

            // 9. Crear el intento. El límite y la política vigentes se guardan en el intento,
            // para que un cambio posterior de la configuración no afecte a quien ya empezó.
            // El límite sale solo de quiz_configuracion (lecciones.duracion es texto informativo).
            $minutosLimite = max(0, (int)($config['tiempo_limite_minutos'] ?? 0));
            $nuevoIntentoNumero = $control['ultimo_intento_numero'] + 1;

            // Generar token
            $sessionTokenCrudo = bin2hex(random_bytes(32));
            $sessionTokenHash = hash('sha256', $sessionTokenCrudo);

            $stmtInsert = $this->pdo->prepare("
                INSERT INTO quiz_intentos (
                    usuario_id, leccion_id, intento_numero, puntaje, aprobado, fecha, estado,
                    start_idempotency_key_hash, attempt_session_token_hash, consume_intento,
                    tiempo_limite_aplicado, politica_cierre_aplicada, tiempo_consumido_segundos, ultima_actividad,
                    preguntas_snapshot
                ) VALUES (?, ?, ?, 0, 0, ?, 'in_progress', ?, ?, 1, ?, ?, 0, ?, ?)
            ");

            $stmtInsert->execute([
                $usuarioId,
                $leccionId,
                $nuevoIntentoNumero,
                $ahoraUtc,
                $startIdempotencyKeyHash,
                $sessionTokenHash,
                $minutosLimite,
                $config['politica_cierre'] ?? 'cortar',
                $ahoraUtc,
                // Copia de las preguntas: el intento se califica y revisa contra lo que respondió
                (new QuizSnapshot($this->pdo))->capturarJson($leccionId)
            ]);

            $nuevoIntentoId = $this->pdo->lastInsertId();

            // 10. Actualizar control
            $stmtUpdateControl = $this->pdo->prepare("UPDATE quiz_usuario_leccion_control SET ultimo_intento_numero = ?, intento_bloqueante_id = ? WHERE usuario_id = ? AND leccion_id = ?");
            $stmtUpdateControl->execute([$nuevoIntentoNumero, $nuevoIntentoId, $usuarioId, $leccionId]);

            // Snapshots se guardarían aquí (preguntas, configuración, etc.)

            $this->pdo->commit();

            return [
                'status' => 'success',
                'intento_id' => $nuevoIntentoId,
                'session_token' => $sessionTokenCrudo,
                'tiempo_limite_minutos' => $minutosLimite ?: null,
                'segundos_restantes' => $minutosLimite > 0 ? $minutosLimite * 60 : null,
                'respuestas_previas' => []
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Retoma un intento en curso: termina la pausa y emite un token nuevo (las pestañas o
     * dispositivos anteriores quedan revocados). Si el tiempo ya se había agotado, lo
     * cierra calificando las respuestas guardadas.
     */
    private function reanudar(int $intentoId, string $ahoraUtc): array {
        $stmtIntento = $this->pdo->prepare("SELECT * FROM quiz_intentos WHERE id = ? FOR UPDATE");
        $stmtIntento->execute([$intentoId]);
        $intento = $stmtIntento->fetch(PDO::FETCH_ASSOC);

        $reloj = (new QuizTiempo($this->pdo))->estado($intento);
        if ($reloj['vencido']) {
            (new QuizCierre($this->pdo))->cerrar($intentoId, 'expiracion_reanudacion');
            return [
                'status' => 'expired',
                'message' => 'El tiempo de este intento se había agotado. Se calificaron las respuestas guardadas.'
            ];
        }

        // El tiempo con la página cerrada no cuenta: la actividad se marca ahora
        $sessionTokenCrudo = bin2hex(random_bytes(32));
        $this->pdo->prepare("UPDATE quiz_intentos SET attempt_session_token_hash = ?, ultima_actividad = ? WHERE id = ?")
            ->execute([hash('sha256', $sessionTokenCrudo), $ahoraUtc, $intentoId]);

        // Cargar respuestas previas
        $stmtRespuestas = $this->pdo->prepare("SELECT quiz_version_pregunta_id, texto_respuesta, revision FROM quiz_respuestas WHERE intento_id = ?");
        $stmtRespuestas->execute([$intentoId]);
        $respuestasMap = [];
        foreach ($stmtRespuestas->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $respuestasMap[$r['quiz_version_pregunta_id']] = [
                'texto' => $r['texto_respuesta'],
                'revision' => (int)$r['revision']
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Intento reanudado.',
            'intento_id' => $intentoId,
            'session_token' => $sessionTokenCrudo,
            'tiempo_limite_minutos' => $reloj['limite'] > 0 ? intdiv($reloj['limite'], 60) : null,
            'segundos_restantes' => $reloj['restantes'],
            'respuestas_previas' => $respuestasMap
        ];
    }

    /**
     * ¿Tiene el alumno un intento aprobado de este quiz? Cuenta tanto la calificación
     * automática ('graded') como la manual y la del sistema anterior ('aprobado').
     */
    public function yaAprobo(int $usuarioId, int $leccionId): bool {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM quiz_intentos
             WHERE usuario_id = ? AND leccion_id = ? AND aprobado = 1 AND estado IN ('graded', 'aprobado')
             LIMIT 1"
        );
        $stmt->execute([$usuarioId, $leccionId]);
        return (bool)$stmt->fetchColumn();
    }
}
