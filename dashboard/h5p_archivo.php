<?php
/**
 * Entrega los archivos de un paquete H5P: dashboard/h5p_archivo.php/{leccion}/{ruta}
 *
 * La carpeta contenido_h5p/ no se sirve por URL; aquí se comprueba el acceso antes de cada
 * archivo. Estudiantes: lección y módulo publicados y acceso vigente al curso. Admin: todo.
 * Docente: los cursos que tiene asignados. Admite peticiones Range (para adelantar videos).
 */
require_once '../config.php';
require_once '../helpers/acceso_helper.php';
require_once '../helpers/h5p_helper.php';

function h5p_no_encontrado(int $codigo = 404): void
{
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=UTF-8');
    exit;
}

$usuario_id = (int)($_SESSION['user_id'] ?? 0);
$rol = (string)($_SESSION['user_rol'] ?? '');
// La sesión no se necesita más: liberarla para que el navegador pida los archivos en paralelo
session_write_close();

if (!$usuario_id) {
    h5p_no_encontrado(403);
}

$ruta = ltrim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
if (!preg_match('#^(\d+)/(.+)$#', $ruta, $m)) {
    h5p_no_encontrado();
}
$leccion_id = (int)$m[1];
$relativa = rawurldecode($m[2]);

$stmt = $pdo->prepare("SELECT l.contenido, l.estado, m.estado AS modulo_estado, m.curso_id
                       FROM lecciones l JOIN modulos m ON l.modulo_id = m.id
                       WHERE l.id = ? AND l.tipo = 'h5p'");
$stmt->execute([$leccion_id]);
$leccion = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$leccion) {
    h5p_no_encontrado();
}

if ($rol === 'admin') {
    $permitido = true;
} elseif ($rol === 'docente') {
    $stmt = $pdo->prepare("SELECT 1 FROM curso_docentes WHERE curso_id = ? AND docente_id = ?");
    $stmt->execute([$leccion['curso_id'], $usuario_id]);
    $permitido = (bool)$stmt->fetchColumn();
} elseif (in_array($rol, ['estudiante', 'administrativo'], true)) {
    $permitido = $leccion['estado'] === 'publicado' && $leccion['modulo_estado'] === 'publicado'
        && obtenerEstadoAccesoCurso($usuario_id, (int)$leccion['curso_id'], $pdo)['active'];
} else {
    $permitido = false;
}
if (!$permitido) {
    h5p_no_encontrado(403);
}

$datos = h5p_datos_leccion($leccion['contenido']);
$archivo = $datos ? h5p_resolver_archivo($datos['carpeta'], $relativa) : null;
if ($archivo === null) {
    h5p_no_encontrado();
}

$ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
$tamano = filesize($archivo);
$etag = '"' . md5($datos['carpeta'] . '|' . $relativa . '|' . filemtime($archivo) . '|' . $tamano) . '"';

header('Content-Type: ' . H5P_EXTENSIONES[$ext]);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);
header('Accept-Ranges: bytes');
if ($ext === 'svg') {
    // Un SVG abierto directamente en este dominio no debe poder ejecutar scripts
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
}

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

// Un solo rango "bytes=inicio-fin" (lo que piden los reproductores de video)
$inicio = 0;
$fin = $tamano - 1;
if (isset($_SERVER['HTTP_RANGE']) && $tamano > 0) {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $r) || ($r[1] === '' && $r[2] === '')) {
        header("Content-Range: bytes */{$tamano}");
        h5p_no_encontrado(416);
    }
    if ($r[1] === '') {
        $inicio = max(0, $tamano - (int)$r[2]); // los últimos N bytes
    } else {
        $inicio = (int)$r[1];
        $fin = $r[2] === '' ? $tamano - 1 : min((int)$r[2], $tamano - 1);
    }
    if ($inicio > $fin || $inicio >= $tamano) {
        header("Content-Range: bytes */{$tamano}");
        h5p_no_encontrado(416);
    }
    http_response_code(206);
    header("Content-Range: bytes {$inicio}-{$fin}/{$tamano}");
}

$longitud = $fin - $inicio + 1;
header('Content-Length: ' . $longitud);
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}

// config.php abre un búfer global: cerrarlo para no acumular videos enteros en memoria
while (ob_get_level() > 0) {
    ob_end_clean();
}
$fp = fopen($archivo, 'rb');
fseek($fp, $inicio);
while ($longitud > 0 && !feof($fp)) {
    $bloque = fread($fp, min(65536, $longitud));
    if ($bloque === false) {
        break;
    }
    echo $bloque;
    $longitud -= strlen($bloque);
    flush();
}
fclose($fp);
