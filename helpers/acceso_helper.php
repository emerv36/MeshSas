<?php
require_once __DIR__ . '/../app/Learning/Access/AccessService.php';

/**
 * Verifica si el usuario actual es admin o docente y debe ignorar bloqueos en todo.
 * El personal administrativo solo está exento en los cursos internos (privados): lo
 * resuelve AccessService::courseIsExempt.
 */
function esPersonalExento() {
    return (isset($_SESSION['user_rol']) && in_array($_SESSION['user_rol'], ['admin', 'docente']));
}

/*
 * Fuente única del acceso: accesos_productos, a través de AccessService (el acceso de un
 * curso puede venir del propio curso o de una ruta que lo incluya). Con
 * ACCESS_EXPIRATION_ENABLED=false los accesos no vencen, pero la fuente es la misma.
 * Los pagos solo cuentan para el requisito del certificado (certificado_helper.php).
 */

/**
 * 1. Valida si un estudiante tiene acceso vigente a un curso (propio o por una ruta).
 * El personal administrativo entra sin acceso registrado a los cursos internos.
 */
function tieneAccesoCurso($usuario_id, $curso_id, $pdo) {
    if (esPersonalExento()) return true;
    return (new AccessService($pdo))->courseState((int)$usuario_id, (int)$curso_id)['active'];
}

/**
 * 2. Valida si un estudiante tiene acceso vigente a una ruta.
 */
function tieneAccesoRuta($usuario_id, $ruta_id, $pdo) {
    if (esPersonalExento()) return true;
    return (new AccessService($pdo))->routeState((int)$usuario_id, (int)$ruta_id)['active'];
}

/**
 * 3. Valida si el acceso vigente a un curso le llega por una ruta.
 */
function cursoPerteneceARutaComprada($usuario_id, $curso_id, $pdo) {
    $state = (new AccessService($pdo))->courseState((int)$usuario_id, (int)$curso_id);
    return $state['active'] && ($state['source'] ?? null) === 'ruta';
}

/**
 * 4. Verifica si un curso que requiere secuencialidad está bloqueado.
 * Retorna true si puede acceder (o está exento).
 * Retorna false si está bloqueado.
 */
function verificarBloqueoSecuencial($usuario_id, $curso_id, $pdo, &$curso_anterior_titulo = null) {
    if (esPersonalExento() || (new AccessService($pdo))->courseIsExempt((int)$usuario_id, (int)$curso_id)) return true;

    // Rutas con acceso vigente que incluyen este curso (sin vencimiento: basta con que haya empezado)
    $ahoraUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $expira = !defined('ACCESS_EXPIRATION_ENABLED') || ACCESS_EXPIRATION_ENABLED;
    $stmt = $pdo->prepare("
        SELECT DISTINCT rc.ruta_id, rc.orden, rc.bloqueado_hasta_completar_anterior
        FROM ruta_cursos rc
        JOIN accesos_productos a
          ON a.producto_tipo = 'ruta' AND a.producto_id = rc.ruta_id
        WHERE rc.curso_id = ?
          AND a.usuario_id = ?
          AND a.revocado_at IS NULL
          AND a.acceso_inicio <= ?
          AND (? = 0 OR a.acceso_fin > ?)
    ");
    $stmt->execute([$curso_id, $usuario_id, $ahoraUtc, $expira ? 1 : 0, $ahoraUtc]);
    $rutas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Si el usuario no tiene ninguna ruta para este curso (por ej: compró el curso suelto), no hay bloqueo de ruta.
    if (empty($rutas)) return true;

    // Iterar para ver si hay algún bloqueo en las rutas que posee
    foreach ($rutas as $ruta) {
        if ($ruta['bloqueado_hasta_completar_anterior']) {
            // Obtener el curso anterior en el orden de esta ruta
            $stmt_prev = $pdo->prepare("
                SELECT rc.curso_id, c.titulo 
                FROM ruta_cursos rc 
                JOIN cursos c ON rc.curso_id = c.id
                WHERE rc.ruta_id = ? AND rc.orden < ? 
                ORDER BY rc.orden DESC LIMIT 1
            ");
            $stmt_prev->execute([$ruta['ruta_id'], $ruta['orden']]);
            $prev = $stmt_prev->fetch(PDO::FETCH_ASSOC);

            if ($prev) {
                // Verificar si el estudiante ha completado el curso anterior
                if (!cursoCompletado($usuario_id, $prev['curso_id'], $pdo)) {
                    $curso_anterior_titulo = $prev['titulo'];
                    return false; // Está bloqueado
                }
            }
        }
    }

    return true; // Puede acceder
}

function obtenerEstadoAccesoCurso($usuario_id, $curso_id, $pdo) {
    if (esPersonalExento()) {
        return ['status' => 'exento', 'active' => true, 'starts_at' => null, 'ends_at' => null];
    }
    return (new AccessService($pdo))->courseState((int)$usuario_id, (int)$curso_id);
}

function obtenerEstadoAccesoRuta($usuario_id, $ruta_id, $pdo) {
    if (esPersonalExento()) {
        return ['status' => 'exento', 'active' => true, 'starts_at' => null, 'ends_at' => null];
    }
    return (new AccessService($pdo))->routeState((int)$usuario_id, (int)$ruta_id);
}

/**
 * 5. Verifica si un estudiante ha completado el 100% de un curso
 */
function cursoCompletado($usuario_id, $curso_id, $pdo) {
    $stmt_tot = $pdo->prepare("SELECT COUNT(*) FROM lecciones l JOIN modulos m ON l.modulo_id = m.id WHERE m.curso_id = ? AND l.estado = 'publicado' AND m.estado = 'publicado'");
    $stmt_tot->execute([$curso_id]);
    $total_lecciones = intval($stmt_tot->fetchColumn());

    // Si un curso no tiene lecciones, se asume no completado (o completado, depende de lógica. Lo dejamos false por seguridad).
    if ($total_lecciones === 0) return false;

    $stmt_comp = $pdo->prepare("
        SELECT COUNT(DISTINCT p.leccion_id) 
        FROM progreso p 
        JOIN lecciones l ON p.leccion_id = l.id 
        JOIN modulos m ON l.modulo_id = m.id 
        WHERE m.curso_id = ? AND p.usuario_id = ? AND p.completado = 1 AND l.estado = 'publicado' AND m.estado = 'publicado'
    ");
    $stmt_comp->execute([$curso_id, $usuario_id]);
    $completadas = intval($stmt_comp->fetchColumn());

    return ($completadas >= $total_lecciones);
}

/**
 * 6. Calcula el progreso de una ruta basándose en el número de cursos completados
 */
function calcularProgresoRuta($usuario_id, $ruta_id, $pdo) {
    $stmt_c = $pdo->prepare("SELECT curso_id FROM ruta_cursos WHERE ruta_id = ?");
    $stmt_c->execute([$ruta_id]);
    $cursos_ids = $stmt_c->fetchAll(PDO::FETCH_COLUMN);

    $total_cursos = count($cursos_ids);
    if ($total_cursos === 0) return ['progreso' => 0, 'completados' => 0, 'total' => 0];

    $completados = 0;
    foreach ($cursos_ids as $cid) {
        if (cursoCompletado($usuario_id, $cid, $pdo)) {
            $completados++;
        }
    }

    $progreso = round(($completados / $total_cursos) * 100);

    return [
        'progreso' => $progreso,
        'completados' => $completados,
        'total' => $total_cursos
    ];
}
