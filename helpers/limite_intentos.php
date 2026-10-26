<?php
/**
 * Límite de intentos (fuerza bruta) para login, recuperación de contraseña,
 * verificación de certificados e inscripción.
 * Usa la tabla intentos_acceso (migración 20261020_seguridad). Si la tabla no
 * existe todavía, no bloquea a nadie y lo deja anotado en el log.
 */

const LIMITE_INTENTOS = [
    // tipo => [máx. por email, máx. por IP, ventana en minutos]
    'login'     => [5, 20, 15],
    'recuperar' => [3, 10, 60],
    // verificar.php: códigos inexistentes; el identificador es la propia IP
    'verificar' => [20, 20, 10],
    // procesar_pago.php: cada envío del formulario de inscripción (por correo o usuario)
    'checkout'  => [5, 10, 60],
    // submit_lead.php: formulario público de prospectos (por correo e IP)
    'lead'      => [3, 10, 60],
];

function ip_cliente(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Devuelve los minutos de espera si se superó el límite, o 0 si puede continuar. */
function intentos_bloqueado(PDO $pdo, string $tipo, string $email): int
{
    [$max_email, $max_ip, $ventana] = LIMITE_INTENTOS[$tipo];
    try {
        $stmt = $pdo->prepare("
            SELECT
                SUM(identificador = ?) AS por_email,
                SUM(ip = ?) AS por_ip
            FROM intentos_acceso
            WHERE tipo = ? AND creado_at > (NOW() - INTERVAL ? MINUTE) AND (identificador = ? OR ip = ?)
        ");
        $email = mb_strtolower(trim($email));
        $ip = ip_cliente();
        $stmt->execute([$email, $ip, $tipo, $ventana, $email, $ip]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ((int)($r['por_email'] ?? 0) >= $max_email || (int)($r['por_ip'] ?? 0) >= $max_ip) ? $ventana : 0;
    } catch (PDOException $e) {
        error_log('limite_intentos: ' . $e->getMessage());
        return 0;
    }
}

function intentos_registrar(PDO $pdo, string $tipo, string $email): void
{
    try {
        $pdo->prepare("INSERT INTO intentos_acceso (tipo, identificador, ip) VALUES (?, ?, ?)")
            ->execute([$tipo, mb_substr(mb_strtolower(trim($email)), 0, 191), ip_cliente()]);
        // Limpieza ocasional de registros viejos
        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM intentos_acceso WHERE creado_at < (NOW() - INTERVAL 1 DAY)");
        }
    } catch (PDOException $e) {
        error_log('limite_intentos: ' . $e->getMessage());
    }
}

function intentos_limpiar(PDO $pdo, string $tipo, string $email): void
{
    try {
        $pdo->prepare("DELETE FROM intentos_acceso WHERE tipo = ? AND identificador = ?")
            ->execute([$tipo, mb_strtolower(trim($email))]);
    } catch (PDOException $e) {
        error_log('limite_intentos: ' . $e->getMessage());
    }
}
