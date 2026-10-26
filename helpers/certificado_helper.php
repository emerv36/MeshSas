<?php
/**
 * Certificados: emisión, registro y códigos de verificación.
 *
 * Un certificado se registra en certificados_emitidos la primera vez que el curso o la
 * ruta está completa y cumple los requisitos; desde entonces no cambia (código, fecha de
 * finalización, título, duración y firmante quedan fijos; el nombre del estudiante es
 * siempre el actual). Los códigos nuevos son un HMAC-SHA256 con la clave CERT_SECRET
 * (.env). Los del formato antiguo (md5 con sal fija) solo existen en los certificados que
 * la migración 20261025_certificados_emitidos registró como ya emitidos.
 */

require_once __DIR__ . '/acceso_helper.php';

const CERT_SAL_LEGADO = ['curso' => 'WSA2026', 'ruta' => 'RUTA2026'];

function codigo_certificado(string $tipo, int $usuario_id, int $producto_id): string
{
    $secreto = (string)($_ENV['CERT_SECRET'] ?? '');
    if ($secreto === '') {
        // Sin clave configurada se mantiene el formato antiguo para no romper la emisión
        error_log('CERT_SECRET no está definido en .env: se emiten códigos de certificado con el formato antiguo.');
        return codigo_certificado_legado($tipo, $usuario_id, $producto_id);
    }
    return strtoupper(substr(hash_hmac('sha256', $tipo . '|' . $usuario_id . '|' . $producto_id, $secreto), 0, 12));
}

function codigo_certificado_legado(string $tipo, int $usuario_id, int $producto_id): string
{
    return strtoupper(substr(md5($usuario_id . $producto_id . CERT_SAL_LEGADO[$tipo]), 0, 12));
}

/** Certificado ya emitido, o null. */
function certificado_emitido(PDO $pdo, string $tipo, int $usuario_id, int $producto_id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM certificados_emitidos WHERE tipo = ? AND usuario_id = ? AND producto_id = ?");
    $stmt->execute([$tipo, $usuario_id, $producto_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Certificados emitidos del usuario, indexados por "tipo:producto_id". */
function certificados_del_usuario(PDO $pdo, int $usuario_id): array
{
    $stmt = $pdo->prepare("SELECT * FROM certificados_emitidos WHERE usuario_id = ?");
    $stmt->execute([$usuario_id]);
    $r = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $r[$c['tipo'] . ':' . $c['producto_id']] = $c;
    }
    return $r;
}

/** Certificado con ese código (el más antiguo si un código antiguo se repite), o null. */
function certificado_por_codigo(PDO $pdo, string $codigo): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM certificados_emitidos WHERE codigo = ? ORDER BY id LIMIT 1");
    $stmt->execute([$codigo]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Devuelve el certificado del usuario; si aún no existe y hoy cumple los requisitos, lo
 * emite. Null si no cumple. Requisitos:
 * - curso: inscripción, pago verificado (del curso o de una ruta que lo incluya) si tiene
 *   precio, y todas sus lecciones publicadas completadas;
 * - ruta: pago verificado de la ruta (aunque el acceso haya vencido) y todos sus cursos completos.
 */
function certificado_emitir(PDO $pdo, string $tipo, int $usuario_id, int $producto_id): ?array
{
    $existente = certificado_emitido($pdo, $tipo, $usuario_id, $producto_id);
    if ($existente) return $existente;

    if ($tipo === 'curso') {
        $stmt = $pdo->prepare("SELECT c.titulo, c.duracion, c.nivel, c.precio, f.nombre, f.cargo, f.firma_img FROM cursos c LEFT JOIN firmantes f ON f.id = c.firmante_id WHERE c.id = ?");
        $stmt->execute([$producto_id]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$producto) return null;

        $stmt = $pdo->prepare("SELECT 1 FROM inscripciones WHERE usuario_id = ? AND curso_id = ?");
        $stmt->execute([$usuario_id, $producto_id]);
        if (!$stmt->fetchColumn()) return null;

        if ((float)$producto['precio'] > 0) {
            // Vale el pago del curso o el de una ruta que lo incluya (los pagos de ruta no llevan curso_id)
            $stmt = $pdo->prepare("
                SELECT 1 FROM pagos
                WHERE (usuario_id = ? OR email_temp = (SELECT email FROM usuarios WHERE id = ?))
                  AND estado = 'verificado'
                  AND ((producto_tipo = 'curso' AND producto_id = ?)
                       OR (producto_tipo = 'ruta' AND producto_id IN (SELECT ruta_id FROM ruta_cursos WHERE curso_id = ?)))
                LIMIT 1
            ");
            $stmt->execute([$usuario_id, $usuario_id, $producto_id, $producto_id]);
            if (!$stmt->fetchColumn()) return null;
        }

        if (!cursoCompletado($usuario_id, $producto_id, $pdo)) return null;
        $cursos = [$producto_id];
    } elseif ($tipo === 'ruta') {
        $stmt = $pdo->prepare("SELECT r.titulo, r.duracion_estimada AS duracion, r.nivel, f.nombre, f.cargo, f.firma_img FROM rutas_aprendizaje r LEFT JOIN firmantes f ON f.id = r.firmante_id WHERE r.id = ?");
        $stmt->execute([$producto_id]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$producto) return null;

        $stmt = $pdo->prepare("SELECT 1 FROM pagos WHERE usuario_id = ? AND producto_tipo = 'ruta' AND producto_id = ? AND estado = 'verificado' LIMIT 1");
        $stmt->execute([$usuario_id, $producto_id]);
        if (!$stmt->fetchColumn()) return null;

        if (calcularProgresoRuta($usuario_id, $producto_id, $pdo)['progreso'] < 100) return null;
        $stmt = $pdo->prepare("SELECT curso_id FROM ruta_cursos WHERE ruta_id = ?");
        $stmt->execute([$producto_id]);
        $cursos = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } else {
        return null;
    }

    // Fecha de finalización: la última lección publicada completada (de la ruta: de sus cursos)
    $marcas = implode(',', array_fill(0, count($cursos), '?'));
    $stmt = $pdo->prepare("
        SELECT MAX(p.fecha) FROM progreso p
        JOIN lecciones l ON l.id = p.leccion_id
        JOIN modulos m ON m.id = l.modulo_id
        WHERE p.usuario_id = ? AND p.completado = 1 AND m.curso_id IN ($marcas) AND l.estado = 'publicado' AND m.estado = 'publicado'
    ");
    $stmt->execute(array_merge([$usuario_id], $cursos));
    $fecha = $stmt->fetchColumn() ?: date('Y-m-d H:i:s');

    $codigo = codigo_certificado($tipo, $usuario_id, $producto_id);
    $formato = ($_ENV['CERT_SECRET'] ?? '') === '' ? 'legado' : 'hmac';
    $pdo->prepare("
        INSERT IGNORE INTO certificados_emitidos
            (tipo, usuario_id, producto_id, codigo, formato, fecha_finalizacion, titulo, duracion, nivel, firmante_nombre, firmante_cargo, firmante_firma_img)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$tipo, $usuario_id, $producto_id, $codigo, $formato, $fecha, $producto['titulo'], $producto['duracion'],
                 $producto['nivel'], $producto['nombre'], $producto['cargo'], $producto['firma_img']]);

    // Si otra petición lo emitió a la vez, INSERT IGNORE no duplica: se devuelve el registrado
    return certificado_emitido($pdo, $tipo, $usuario_id, $producto_id);
}

/** Fecha de finalización del certificado en formato d/m/Y. */
function certificado_fecha(array $certificado): string
{
    return date('d/m/Y', strtotime($certificado['fecha_finalizacion']));
}
