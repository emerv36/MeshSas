<?php
namespace App\Learning\Evaluations;

use PDO;

/**
 * Copia de las preguntas y opciones de un quiz tomada al iniciar cada intento
 * (quiz_intentos.preguntas_snapshot).
 *
 * El editor de quizzes vuelve a crear las opciones al guardar una pregunta (con ids nuevos)
 * y puede borrar preguntas; sin esta copia, un intento se calificaría y revisaría contra un
 * quiz distinto del que respondió el alumno. Incluye las respuestas correctas: es solo para
 * el servidor y nunca se envía completa al navegador.
 *
 * Los intentos anteriores a la copia no la tienen: para ellos rige el quiz actual.
 */
final class QuizSnapshot
{
    public const VERSION = 1;

    public function __construct(private PDO $pdo) {}

    /**
     * Preguntas actuales del quiz con sus opciones, en el orden en que se muestran.
     *
     * @return array{version:int, preguntas:list<array{id:int, tipo:string, pregunta:string, orden:int,
     *               opciones:list<array{id:int, texto:string, es_correcta:bool}>}>}
     */
    public function capturar(int $leccionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tipo, pregunta, orden FROM quiz_preguntas WHERE leccion_id = ? ORDER BY orden, id'
        );
        $stmt->execute([$leccionId]);
        $preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $opciones = [];
        if ($preguntas !== []) {
            $ids = array_map('intval', array_column($preguntas, 'id'));
            $marcadores = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT id, pregunta_id, texto, es_correcta FROM quiz_opciones WHERE pregunta_id IN ($marcadores) ORDER BY id"
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $opciones[(int)$o['pregunta_id']][] = [
                    'id' => (int)$o['id'],
                    'texto' => $o['texto'],
                    'es_correcta' => (bool)$o['es_correcta'],
                ];
            }
        }

        return [
            'version' => self::VERSION,
            'preguntas' => array_map(static fn(array $p): array => [
                'id' => (int)$p['id'],
                'tipo' => $p['tipo'],
                'pregunta' => $p['pregunta'],
                'orden' => (int)$p['orden'],
                'opciones' => $opciones[(int)$p['id']] ?? [],
            ], $preguntas),
        ];
    }

    public function capturarJson(int $leccionId): string
    {
        return json_encode($this->capturar($leccionId), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Preguntas copiadas en el intento, o null si el intento no tiene copia (anterior a ella).
     *
     * @return list<array>|null
     */
    public function deIntento(int $intentoId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT preguntas_snapshot FROM quiz_intentos WHERE id = ?');
        $stmt->execute([$intentoId]);
        return self::decodificar($stmt->fetchColumn());
    }

    /** @return list<array>|null */
    public static function decodificar(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $datos = json_decode($json, true);
        return is_array($datos) && ($datos['version'] ?? null) === self::VERSION && is_array($datos['preguntas'] ?? null)
            ? $datos['preguntas']
            : null;
    }
}
