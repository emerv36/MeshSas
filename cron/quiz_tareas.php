<?php
/**
 * Tareas periódicas del motor de quizzes. Solo por línea de comandos (cron de cPanel,
 * cada 10 minutos, con el comando):
 *
 *   /usr/local/bin/php /home/ghosjrow/public_html/cron/quiz_tareas.php >/dev/null 2>&1
 *
 * - Cierra los intentos vencidos (tiempo agotado, pasada la fecha de cierre configurada o
 *   con el acceso del alumno al curso vencido), lo que además libera el bloqueo del alumno
 *   para un nuevo intento. El reloj de un intento se pausa con la página cerrada, así que
 *   no hay límite de reloj real.
 * - Procesa la cola de eventos pendientes (outbox), si los hubiera.
 *
 * QuizReconciliadorArchivos no se ejecuta: ningún flujo escribe todavía en
 * quiz_respuesta_archivos.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// config.php espera algunas variables de una petición web
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cron/quiz_tareas.php';

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/Learning/Evaluations/QuizCierre.php';
require_once __DIR__ . '/../app/Learning/Evaluations/QuizCronExpiracion.php';
require_once __DIR__ . '/../app/Learning/Evaluations/QuizOutboxConsumer.php';

use App\Learning\Evaluations\QuizCierre;
use App\Learning\Evaluations\QuizCronExpiracion;
use App\Learning\Evaluations\QuizOutboxConsumer;

// En CLI no hay salida HTML que capturar: los errores van al log y a STDERR, no a la página 500
while (ob_get_level() > 0) {
    ob_end_flush();
}
set_exception_handler(function (Throwable $e) {
    $detalle = get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine();
    error_log('cron quiz_tareas: ' . $detalle);
    fwrite(STDERR, $detalle . "\n");
    exit(1);
});

$codigo = 0;
foreach ([
    'expiración' => fn() => (new QuizCronExpiracion($pdo, new QuizCierre($pdo)))->ejecutar(),
    'outbox'     => fn() => (new QuizOutboxConsumer($pdo))->ejecutar(),
] as $nombre => $tarea) {
    try {
        $tarea();
    } catch (Throwable $e) {
        error_log("cron quiz_tareas ($nombre): " . $e->getMessage());
        fwrite(STDERR, "Error en $nombre: " . $e->getMessage() . "\n");
        $codigo = 1;
    }
}
exit($codigo);
