<?php
// Resultados de las lecciones H5P: listado de lecciones y, por lección, el resultado de cada
// estudiante (h5p_resultados). El docente solo ve los cursos que tiene asignados.
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../helpers/h5p_helper.php';
require_rol(['admin', 'docente']);
require_once 'admin_header.php';

$leccion_id = (int)($_GET['leccion'] ?? 0);
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt = fn($n) => rtrim(rtrim(number_format((float)$n, 2, ',', ''), '0'), ',');

$sql = "SELECT l.id, l.titulo, l.contenido, l.puntaje_minimo, c.titulo AS curso, m.curso_id,
               COUNT(r.id) AS total, COALESCE(SUM(r.aprobado), 0) AS aprobados
        FROM lecciones l
        JOIN modulos m ON l.modulo_id = m.id
        JOIN cursos c ON m.curso_id = c.id
        LEFT JOIN h5p_resultados r ON r.leccion_id = l.id";
$params = [];
if ($rol === 'docente') {
    $sql .= " JOIN curso_docentes cd ON cd.curso_id = c.id AND cd.docente_id = ?";
    $params[] = $user_id;
}
$sql .= " WHERE l.tipo = 'h5p' GROUP BY l.id ORDER BY c.titulo, m.orden, l.orden";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$lecciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$leccion = null;
if ($leccion_id) {
    foreach ($lecciones as $l) {
        if ((int)$l['id'] === $leccion_id) $leccion = $l;
    }
    if (!$leccion) {
        echo "<div style='background:#fee2e2; color:#991b1b; padding:1rem; border-radius:8px; font-weight:600;'>La lección no existe o no pertenece a sus cursos.</div>";
        require_once 'admin_footer.php';
        exit;
    }
}
?>

<?php if ($leccion):
    $datos = h5p_datos_leccion($leccion['contenido']);
    $stmt = $pdo->prepare("SELECT r.*, u.nombre, u.email FROM h5p_resultados r JOIN usuarios u ON u.id = r.usuario_id
                           WHERE r.leccion_id = ? ORDER BY r.updated_at DESC");
    $stmt->execute([$leccion_id]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
    <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:2rem; flex-wrap:wrap;">
        <div>
            <h2 style="margin:0;"><i class="fa-solid fa-puzzle-piece"></i> <?= $h($leccion['titulo']) ?></h2>
            <p style="color:#64748b; margin:0.5rem 0 0;">
                Curso: <?= $h($leccion['curso']) ?> ·
                <?= h5p_modo($datos) === 'completar' ? 'Se completa al terminar la actividad' : 'Nota mínima: ' . (int)$leccion['puntaje_minimo'] . '%' ?>
            </p>
        </div>
        <div style="display:flex; gap:0.5rem;">
            <?php if ($datos): ?><a href="h5p_descargar?leccion=<?= (int)$leccion_id ?>" class="btn btn-outline-dark"><i class="fa-solid fa-download"></i> Paquete .h5p</a><?php endif; ?>
            <a href="ver_h5p" class="btn btn-outline-dark">Volver a lecciones</a>
        </div>
    </div>

    <div style="background:white; border-radius:16px; border:1px solid #e2e8f0; overflow-x:auto;">
        <table class="table" style="margin:0;">
            <thead class="table-light">
                <tr>
                    <th>Estudiante</th>
                    <th>Email</th>
                    <th class="text-center">Mejor nota</th>
                    <th class="text-center">Estado</th>
                    <th class="text-center">Envíos</th>
                    <th>Último envío</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$resultados): ?>
                <tr><td colspan="6" class="text-center py-4">Ningún estudiante ha enviado resultados todavía.</td></tr>
            <?php else: foreach ($resultados as $r): ?>
                <tr>
                    <td><strong><?= $h($r['nombre']) ?></strong></td>
                    <td><?= $h($r['email']) ?></td>
                    <td class="text-center">
                        <?= $r['puntaje_max'] !== null ? $fmt($r['puntaje']) . '%' : '—' ?>
                        <?php if ($r['puntaje_max'] !== null): ?><br><small style="color:#64748b;"><?= $fmt($r['puntaje_raw']) ?> / <?= $fmt($r['puntaje_max']) ?></small><?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($r['aprobado']): ?><span class="badge bg-success">Aprobado</span>
                        <?php elseif ($r['completado']): ?><span class="badge bg-warning text-dark">Terminado sin aprobar</span>
                        <?php else: ?><span class="badge bg-secondary">En curso</span><?php endif; ?>
                    </td>
                    <td class="text-center"><?= (int)$r['intentos'] ?></td>
                    <td><?= $h(date('d/m/Y H:i', strtotime($r['updated_at']))) ?><?php if ($r['ip']): ?><br><small style="color:#94a3b8;"><?= $h($r['ip']) ?></small><?php endif; ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

<?php else: ?>
    <div style="margin-bottom:2rem;">
        <h2 style="margin:0;"><i class="fa-solid fa-puzzle-piece"></i> Resultados H5P</h2>
        <p style="color:#64748b; margin:0.5rem 0 0;">Lecciones con actividades H5P y cuántos estudiantes las han aprobado.</p>
    </div>
    <div style="background:white; border-radius:16px; border:1px solid #e2e8f0; overflow-x:auto;">
        <table class="table" style="margin:0;">
            <thead class="table-light">
                <tr><th>Curso</th><th>Lección</th><th class="text-center">Estudiantes</th><th class="text-center">Aprobados</th><th class="text-end">Acción</th></tr>
            </thead>
            <tbody>
            <?php if (!$lecciones): ?>
                <tr><td colspan="5" class="text-center py-4">No hay lecciones H5P.</td></tr>
            <?php else: foreach ($lecciones as $l): ?>
                <tr>
                    <td><?= $h($l['curso']) ?></td>
                    <td><strong><?= $h($l['titulo']) ?></strong></td>
                    <td class="text-center"><?= (int)$l['total'] ?></td>
                    <td class="text-center"><?= (int)$l['aprobados'] ?></td>
                    <td class="text-end"><a href="ver_h5p?leccion=<?= (int)$l['id'] ?>" class="btn btn-sm btn-primary">Ver resultados</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>
