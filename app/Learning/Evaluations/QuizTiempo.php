<?php
namespace App\Learning\Evaluations;

use PDO;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Tiempo de un intento de quiz, medido por el servidor (el navegador solo lo muestra).
 *
 * El reloj se pausa al cerrar la página: mientras la sesión del intento sigue abierta
 * (mismo token), todo el tiempo entre una petición y la siguiente se suma; al retomar
 * el intento, QuizStart emite un token nuevo y marca la actividad en ese momento, de
 * modo que el hueco desde la última actividad no cuenta.
 *
 * Fechas: ultima_actividad es DATETIME en UTC y fecha (inicio) es TIMESTAMP, así que la
 * conexión debe estar en '+00:00', como en el resto del motor, al leer el intento.
 */
final class QuizTiempo
{
    /** Margen por latencia de red antes de dar el intento por vencido. */
    public const TOLERANCIA_SEGUNDOS = 60;

    public function __construct(private PDO $pdo) {}

    /** Límite del intento en segundos (0 = sin límite). */
    public function limiteSegundos(array $intento): int
    {
        $minutos = $intento['tiempo_limite_aplicado'] ?? null;
        if ($minutos === null) {
            // Intentos iniciados antes de guardar el límite aplicado: rige la configuración actual
            $stmt = $this->pdo->prepare('SELECT tiempo_limite_minutos FROM quiz_configuracion WHERE leccion_id = ?');
            $stmt->execute([(int)$intento['leccion_id']]);
            $minutos = $stmt->fetchColumn();
        }
        return max(0, (int)$minutos) * 60;
    }

    /**
     * Suma el tiempo transcurrido desde la última actividad del intento y la marca ahora.
     * Se llama con el intento bloqueado (FOR UPDATE) y el token de sesión ya validado.
     *
     * @return array{consumido:int, limite:int, restantes:?int, vencido:bool}
     */
    public function registrarActividad(array $intento, string $ahoraUtc): array
    {
        $desde = $intento['ultima_actividad'] ?: $intento['fecha'];
        $consumido = (int)$intento['tiempo_consumido_segundos']
            + max(0, $this->timestamp($ahoraUtc) - $this->timestamp($desde));

        $this->pdo->prepare('UPDATE quiz_intentos SET tiempo_consumido_segundos = ?, ultima_actividad = ? WHERE id = ?')
            ->execute([$consumido, $ahoraUtc, (int)$intento['id']]);

        return $this->estado($intento, $consumido);
    }

    /**
     * Estado del reloj sin registrar actividad (intento pausado).
     *
     * @return array{consumido:int, limite:int, restantes:?int, vencido:bool}
     */
    public function estado(array $intento, ?int $consumido = null): array
    {
        $consumido ??= (int)$intento['tiempo_consumido_segundos'];
        $limite = $this->limiteSegundos($intento);
        return [
            'consumido' => $consumido,
            'limite' => $limite,
            'restantes' => $limite > 0 ? max(0, $limite - $consumido) : null,
            'vencido' => $limite > 0 && $consumido > $limite + self::TOLERANCIA_SEGUNDOS,
        ];
    }

    private function timestamp(string $fechaUtc): int
    {
        return (new DateTimeImmutable($fechaUtc, new DateTimeZone('UTC')))->getTimestamp();
    }
}
