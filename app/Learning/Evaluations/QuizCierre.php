<?php
namespace App\Learning\Evaluations;

use PDO;
use Exception;

require_once __DIR__ . '/QuizSnapshot.php';

class QuizCierre {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Cierra un intento de quiz.
     * ATENCIÓN: Esta función no ejecuta BEGIN/COMMIT/ROLLBACK.
     * Asume que ya está siendo llamada dentro de una transacción activa
     * y el llamador controlará el rollback si falla.
     */
    public function cerrar(int $intentoId, string $metodoCierre, ?string $submissionIdempHash = null) {
        $ahoraUtc = $this->obtenerHoraUtc();

        // 1. Bloquear el intento
        $stmtIntento = $this->pdo->prepare("SELECT * FROM quiz_intentos WHERE id = ? FOR UPDATE");
        $stmtIntento->execute([$intentoId]);
        $intento = $stmtIntento->fetch(PDO::FETCH_ASSOC);

        if (!$intento) {
            throw new Exception("Intento no encontrado.", 404);
        }

        if (in_array($intento['estado'], ['graded', 'cancelled', 'pending_review'])) {
            if ($submissionIdempHash && $intento['submission_idempotency_key_hash'] === $submissionIdempHash) {
                return $this->devolverResultadoIdempotente($intento);
            }
            throw new Exception("El intento ya está cerrado o anulado.", 409);
        }

        // 2. Comprobar Idempotencia
        if ($submissionIdempHash) {
            if ($intento['submission_idempotency_key_hash'] && $intento['submission_idempotency_key_hash'] !== $submissionIdempHash) {
                throw new Exception("Conflicto de idempotencia en cierre.", 409);
            }
            // Guardar clave
            $stmtUpdIdemp = $this->pdo->prepare("UPDATE quiz_intentos SET submission_idempotency_key_hash = ? WHERE id = ?");
            $stmtUpdIdemp->execute([$submissionIdempHash, $intentoId]);
        }

        // 3. Calcular el resultado a partir de las respuestas realmente guardadas.
        $resultado = $this->calcularResultado((int)$intento['leccion_id'], $intentoId);
        $nuevoEstado = $resultado['necesita_revision'] ? 'pending_review' : 'graded';
        $puntajeAutomatico = $resultado['puntaje'];
        $aprobado = $nuevoEstado === 'graded' && $resultado['aprobado'] ? 1 : 0;

        // 4. Actualizar Intento
        // fecha_fin_efectiva es la hora académica en la que terminó. (Podría venir del parámetro de tiempo límite o ahora si se entrega antes).
        $fechaFinEfectiva = $ahoraUtc;

        // Liberar control de bloqueantes
        $stmtControl = $this->pdo->prepare("UPDATE quiz_usuario_leccion_control SET intento_bloqueante_id = NULL WHERE intento_bloqueante_id = ?");
        $stmtControl->execute([$intentoId]);

        $stmtUpdate = $this->pdo->prepare("
            UPDATE quiz_intentos
            SET estado = ?, puntaje = ?, aprobado = ?, fecha_fin_efectiva = ?, procesado_at = ?, metodo_cierre = ?, attempt_session_token_hash = NULL
            WHERE id = ?
        ");
        $stmtUpdate->execute([$nuevoEstado, $puntajeAutomatico, $aprobado, $fechaFinEfectiva, $ahoraUtc, $metodoCierre, $intentoId]);

        // El progreso académico forma parte del cierre y no depende de un cron.
        if ($aprobado) {
            $this->marcarProgreso((int)$intento['usuario_id'], (int)$intento['leccion_id']);
        }

        // 5. Insertar Auditoría
        $this->registrarAuditoria($intentoId, 'quiz_cierre', $nuevoEstado, $metodoCierre);

        // Devolver resultado
        $intento['estado'] = $nuevoEstado;
        $intento['puntaje'] = $puntajeAutomatico;
        $intento['aprobado'] = $aprobado;
        $intento['fecha_fin_efectiva'] = $fechaFinEfectiva;
        $intento['metodo_cierre'] = $metodoCierre;

        return $this->devolverResultadoIdempotente($intento);
    }

    /**
     * Recalifica un intento automático ya cerrado y repara su progreso.
     */
    public function recalificarCerrado(int $intentoId): array
    {
        $manageTransaction = !$this->pdo->inTransaction();
        if ($manageTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM quiz_intentos WHERE id = ? FOR UPDATE');
            $stmt->execute([$intentoId]);
            $intento = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$intento || $intento['estado'] !== 'graded') {
                throw new Exception('El intento no existe o no admite recalificación automática.', 409);
            }

            $resultado = $this->calcularResultado((int)$intento['leccion_id'], $intentoId);
            if ($resultado['necesita_revision']) {
                throw new Exception('El intento contiene preguntas que requieren revisión manual.', 409);
            }
            $aprobado = $resultado['aprobado'] ? 1 : 0;
            $update = $this->pdo->prepare('UPDATE quiz_intentos SET puntaje = ?, aprobado = ? WHERE id = ?');
            $update->execute([$resultado['puntaje'], $aprobado, $intentoId]);
            if ($aprobado) {
                $this->marcarProgreso((int)$intento['usuario_id'], (int)$intento['leccion_id']);
            }
            $this->registrarAuditoria($intentoId, 'quiz_recalificado', 'graded', 'reparacion');
            if ($manageTransaction) {
                $this->pdo->commit();
            }
            return ['puntaje' => $resultado['puntaje'], 'aprobado' => (bool)$aprobado];
        } catch (\Throwable $e) {
            if ($manageTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function calcularResultado(int $leccionId, int $intentoId): array
    {
        $totalAutomaticas = 0;
        $correctas = 0;
        $necesitaRevision = false;
        foreach ($this->corregirPreguntas($leccionId, $intentoId) as $pregunta) {
            if ($pregunta['correccion'] === 'manual') {
                $necesitaRevision = true;
            } elseif ($pregunta['correccion'] === 'automatica') {
                $totalAutomaticas++;
                $correctas += $pregunta['correcta'] ? 1 : 0;
            }
        }

        $puntaje = $totalAutomaticas > 0 ? round(($correctas / $totalAutomaticas) * 100, 2) : 0.0;
        $minimo = $this->puntajeMinimo($leccionId);

        return [
            'puntaje' => $puntaje,
            'aprobado' => $totalAutomaticas > 0 && $puntaje >= $minimo,
            'necesita_revision' => $necesitaRevision,
        ];
    }

    /**
     * Corrección pregunta por pregunta de un intento, con las mismas reglas que la nota.
     * Usa la copia de las preguntas tomada al iniciar el intento; los intentos anteriores a
     * ella se corrigen con el quiz actual.
     * correccion: 'automatica' (opciones), 'manual' (abierta/archivo) o 'ninguna' (tipo sin calificar).
     *
     * @return list<array{id:int, tipo:string, pregunta:string, correccion:string, correcta:?bool,
     *                    respondida:bool, seleccionadas:list<string>, esperadas:list<string>,
     *                    opciones:list<array{id:int, texto:string, es_correcta:bool}>}>
     */
    public function corregirPreguntas(int $leccionId, int $intentoId): array
    {
        $snapshot = new QuizSnapshot($this->pdo);
        $preguntas = $snapshot->deIntento($intentoId) ?? $snapshot->capturar($leccionId)['preguntas'];

        $stmtRespuesta = $this->pdo->prepare(
            'SELECT texto_respuesta
             FROM quiz_respuestas
             WHERE intento_id = ? AND (quiz_version_pregunta_id = ? OR pregunta_id = ?)
             ORDER BY id DESC LIMIT 1'
        );

        $detalle = [];
        foreach ($preguntas as $pregunta) {
            $opciones = $pregunta['opciones'] ?? [];
            $item = [
                'id' => (int)$pregunta['id'],
                'tipo' => $pregunta['tipo'],
                'pregunta' => $pregunta['pregunta'],
                'correccion' => 'ninguna',
                'correcta' => null,
                'respondida' => false,
                'seleccionadas' => [],
                'esperadas' => [],
                'opciones' => $opciones,
            ];
            if (in_array($pregunta['tipo'], ['abierta', 'archivo'], true)) {
                $item['correccion'] = 'manual';
            } elseif (in_array($pregunta['tipo'], ['unica', 'multiple', 'falso_verdadero'], true)) {
                $stmtRespuesta->execute([$intentoId, (int)$pregunta['id'], (int)$pregunta['id']]);
                $seleccionadas = $this->normalizarOpciones($stmtRespuesta->fetchColumn());
                $esperadas = array_map(
                    static fn(array $o): string => (string)$o['id'],
                    array_values(array_filter($opciones, static fn(array $o): bool => (bool)$o['es_correcta']))
                );
                sort($esperadas, SORT_NATURAL);
                $item['correccion'] = 'automatica';
                $item['respondida'] = $seleccionadas !== [];
                $item['seleccionadas'] = $seleccionadas;
                $item['esperadas'] = $esperadas;
                $item['correcta'] = $seleccionadas === $esperadas && $esperadas !== [];
            }
            $detalle[] = $item;
        }
        return $detalle;
    }

    /** Nota mínima de aprobación del quiz (versión activa, si no la de la lección, si no la de Configuración). */
    public function puntajeMinimo(int $leccionId): float
    {
        // El cron también cierra intentos: si no cargó config.php, se usa 70
        $porDefecto = \defined('PASSING_GRADE') ? (int)PASSING_GRADE : 70;
        $stmtMinimo = $this->pdo->prepare(
            'SELECT COALESCE(v.puntaje_minimo_aprobacion, l.puntaje_minimo, ?)
             FROM lecciones l
             LEFT JOIN quiz_configuracion c ON c.leccion_id = l.id
             LEFT JOIN quiz_versiones v ON v.id = c.quiz_version_activa_id
             WHERE l.id = ?'
        );
        $stmtMinimo->execute([$porDefecto, $leccionId]);
        return (float)($stmtMinimo->fetchColumn() ?: $porDefecto);
    }

    private function normalizarOpciones(mixed $valor): array
    {
        if ($valor === false || $valor === null || trim((string)$valor) === '') {
            return [];
        }
        $texto = trim((string)$valor);
        $decoded = json_decode($texto, true);
        $valores = is_array($decoded)
            ? $decoded
            : (str_contains($texto, ',') ? explode(',', $texto) : [$texto]);
        $valores = array_values(array_unique(array_filter(
            array_map(static fn($item): string => trim((string)$item), $valores),
            static fn(string $item): bool => $item !== ''
        )));
        sort($valores, SORT_NATURAL);
        return $valores;
    }

    private function marcarProgreso(int $usuarioId, int $leccionId): void
    {
        // track_time puede haber creado ya la fila con completado = 0 (clave única user_lesson)
        $stmt = $this->pdo->prepare(
            'INSERT INTO progreso (usuario_id, leccion_id, completado) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE completado = 1'
        );
        $stmt->execute([$usuarioId, $leccionId]);
    }

    private function obtenerHoraUtc() {
        return $this->pdo->query("SELECT UTC_TIMESTAMP() AS ahora_utc")->fetchColumn();
    }

    private function devolverResultadoIdempotente($intento) {
        return [
            'status' => 'success',
            'estado_final' => $intento['estado'],
            'puntaje' => $intento['puntaje'],
            'aprobado' => $intento['aprobado'] ?? 0,
            'fecha_efectiva_cierre' => $intento['fecha_fin_efectiva'],
            'metodo_cierre' => $intento['metodo_cierre'],
            'message' => 'Intento cerrado correctamente.'
        ];
    }

    private function registrarAuditoria($intentoId, $evento, $nuevoEstado, $metodo) {
        $stmt = $this->pdo->prepare("
            INSERT INTO quiz_auditoria_eventos (intento_id, actor_tipo, evento, fuente, ocurrido_at, datos_nuevos)
            VALUES (?, 'sistema', ?, ?, UTC_TIMESTAMP(), ?)
        ");
        $datos = json_encode(['estado' => $nuevoEstado, 'metodo' => $metodo]);
        $stmt->execute([$intentoId, $evento, 'QuizCierre', $datos]);
    }

}
