<?php
require_once __DIR__ . '/admin_auth.php';
require_once '../config.php';
require_rol(['admin', 'docente']);
require_once 'admin_header.php';

$leccion_id = isset($_GET['leccion']) ? intval($_GET['leccion']) : null;
$usuario_id = isset($_GET['usuario']) ? intval($_GET['usuario']) : null;

if (!function_exists('h_excel')) {
    function h_excel($val) {
        if ($val === null) return '';
        if (is_array($val) || is_object($val)) return ''; // Prevent array to string conversion errors securely
        return htmlspecialchars((string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if ($rol === 'docente') {
    if (!$leccion_id) {
        http_response_code(403);
        echo "<div class='admin-content'><div style='background:#fee2e2; color:#991b1b; padding:1rem; border-radius:8px; margin-bottom:1.5rem; font-weight:600;'>Error 403: Acceso global denegado. Revise los resultados directamente desde el Módulo correspondiente.</div></div>";
        require_once 'admin_footer.php';
        exit;
    }
    $stmt_check = $pdo->prepare("
        SELECT 1 FROM lecciones l
        JOIN modulos m ON l.modulo_id = m.id
        JOIN curso_docentes cd ON m.curso_id = cd.curso_id
        WHERE l.id = ? AND l.tipo = 'excel_interactivo' AND cd.docente_id = ?
    ");
    $stmt_check->execute([$leccion_id, $user_id]);
    if (!$stmt_check->fetchColumn()) {
        http_response_code(403);
        echo "<div class='admin-content'><div style='background:#fee2e2; color:#991b1b; padding:1rem; border-radius:8px; margin-bottom:1.5rem; font-weight:600;'>Error 403: La lección solicitada no le pertenece o no existe.</div></div>";
        require_once 'admin_footer.php';
        exit;
    }
}

// Vista de detalle de un estudiante específico
if ($leccion_id && $usuario_id) {
    $stmt = $pdo->prepare("
        SELECT re.*, u.nombre AS alumno, l.titulo AS leccion_titulo, l.contenido AS leccion_config
        FROM respuestas_excel re
        JOIN usuarios u ON re.usuario_id = u.id
        JOIN lecciones l ON re.leccion_id = l.id
        WHERE re.leccion_id = ? AND re.usuario_id = ?
    ");
    $stmt->execute([$leccion_id, $usuario_id]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resultado) {
        echo "<div class='alert alert-danger'>No se encontró el resultado solicitado.</div>";
        require_once 'admin_footer.php';
        exit;
    }

    $config = json_decode($resultado['leccion_config'] ?? '', true);
    if (!is_array($config)) $config = [];
    $respuestas = json_decode($resultado['respuestas'] ?? '', true);
    if (!is_array($respuestas)) $respuestas = [];
    $outcomes = json_decode($resultado['resultados'] ?? '', true);
    if (!is_array($outcomes)) $outcomes = [];
    
    $filas = isset($config['filas']) ? max(1, min(100, intval($config['filas']))) : 5;
    $cols = isset($config['columnas']) ? max(1, min(26, intval($config['columnas']))) : 5;
    $col_ltrs = range('A', 'Z');
?>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
        <div>
            <h2 style="margin:0;">Resultado de Excel: <?= h_excel($resultado['alumno']) ?></h2>
            <p style="color:#64748b; margin:0.5rem 0 0;">Lección: <?= h_excel($resultado['leccion_titulo']) ?> | Puntaje: <strong><?= intval($resultado['puntaje']) ?>%</strong></p>
        </div>
        <a href="ver_excel.php?leccion=<?= intval($leccion_id) ?>" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-left"></i> Volver al listado</a>
    </div>

    <div style="background:white; border-radius:16px; border:1px solid #e2e8f0; overflow:hidden; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);">
        <div style="padding:1.5rem; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; gap:2rem;">
            <div><span style="font-size:0.75rem; color:#64748b; text-transform:uppercase; font-weight:700;">Intentos</span><br><strong><?= intval($resultado['intentos']) ?></strong></div>
            <div><span style="font-size:0.75rem; color:#64748b; text-transform:uppercase; font-weight:700;">Última actualización</span><br><strong><?= h_excel(date('d/m/Y H:i', strtotime($resultado['updated_at'] ?? 'now'))) ?></strong></div>
        </div>
        
        <div style="padding:2rem; overflow-x:auto;">
            <table style="border-collapse:collapse; width:100%; min-width:600px;">
                <thead>
                    <tr>
                        <th style="width:40px; background:#f1f5f9; border:1px solid #e2e8f0;"></th>
                        <?php for($c=0; $c<$cols; $c++): ?>
                            <th style="background:#f1f5f9; padding:8px; border:1px solid #e2e8f0; font-size:0.8rem; text-align:center;"><?= $col_ltrs[$c] ?></th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php for($r=1; $r<=$filas; $r++): ?>
                        <tr>
                            <td style="background:#f1f5f9; text-align:center; font-size:0.8rem; font-weight:700; border:1px solid #e2e8f0; color:#64748b;"><?= $r ?></td>
                            <?php for($c=0; $c<$cols; $c++): 
                                $ref = $col_ltrs[$c] . $r;
                                $fija = (isset($config['celdas_fijas']) && is_array($config['celdas_fijas']) && isset($config['celdas_fijas'][$ref])) ? $config['celdas_fijas'][$ref] : null;
                                $eval = (isset($config['celdas_evaluables']) && is_array($config['celdas_evaluables']) && isset($config['celdas_evaluables'][$ref])) ? $config['celdas_evaluables'][$ref] : null;
                                $resp = $respuestas[$ref] ?? '';
                                $out  = (isset($outcomes[$ref]) && is_array($outcomes[$ref])) ? $outcomes[$ref] : null;
                                
                                $bg = 'white';
                                $color = '#1e293b';
                                $border = '1px solid #e2e8f0';
                                $title_attr = '';
                                $is_fija = false;
                                $val_fija = '';
                                $val_resp = h_excel($resp);

                                if (is_array($fija) && isset($fija['valor'])) {
                                    $bg = '#f1f5f9';
                                    $color = '#475569';
                                    $is_fija = true;
                                    $val_fija = h_excel($fija['valor']);
                                } elseif (is_array($eval)) {
                                    if (isset($eval['valor_esperado'])) {
                                        $title_attr = ' title="Esperado: ' . h_excel($eval['valor_esperado']) . '"';
                                    }
                                    if (!empty($out['ok'])) {
                                        $bg = '#dcfce7';
                                        $border = '2px solid #22c55e';
                                    } else {
                                        $bg = '#fee2e2';
                                        $border = '2px solid #ef4444';
                                    }
                                }
                            ?>
                                <td style="background:<?= $bg ?>; border:<?= $border ?>; padding:10px; min-width:100px; height:45px; text-align:center; vertical-align:middle; color:<?= $color ?>; position:relative;"<?= $title_attr ?>>
                                    <?php if ($is_fija): ?>
                                        <strong><?= $val_fija ?></strong>
                                    <?php else: ?>
                                        <?= $val_resp ?>
                                    <?php endif; ?>
                                    <?php if ($eval && $out): ?>
                                        <div style="position:absolute; top:2px; right:4px; font-size:0.65rem; opacity:0.6;">
                                            <?= !empty($out['ok']) ? '✅' : '❌' ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        
        <div style="padding:1.5rem; background:#f8fafc; border-top:1px solid #e2e8f0;">
            <h4 style="margin-top:0;">Leyenda y Detalles</h4>
            <div style="display:flex; gap:2rem; font-size:0.85rem;">
                <div style="display:flex; align-items:center; gap:0.5rem;"><span style="width:16px; height:16px; background:#f1f5f9; border:1px solid #e2e8f0; display:inline-block;"></span> Celda Fija</div>
                <div style="display:flex; align-items:center; gap:0.5rem;"><span style="width:16px; height:16px; background:#dcfce7; border:2px solid #22c55e; display:inline-block;"></span> Correcto</div>
                <div style="display:flex; align-items:center; gap:0.5rem;"><span style="width:16px; height:16px; background:#fee2e2; border:2px solid #ef4444; display:inline-block;"></span> Incorrecto / Pendiente</div>
            </div>
            <div style="margin-top:1.5rem;">
                <p><strong>Nota:</strong> Los valores en las celdas de colores son los ingresados por el estudiante. Si es una fórmula, se muestra el resultado final calculado.</p>
            </div>
        </div>
    </div>

<?php
} else {
    // Listado de lecciones con Excel o entregas
    $sql = "
        SELECT l.id, l.titulo, c.titulo AS curso, COUNT(re.id) AS total_entregas
        FROM lecciones l
        JOIN modulos m ON l.modulo_id = m.id
        JOIN cursos c ON m.curso_id = c.id
        LEFT JOIN respuestas_excel re ON l.id = re.leccion_id
    ";
    
    if ($rol === 'docente') {
        $sql .= " JOIN curso_docentes cd ON c.id = cd.curso_id WHERE cd.docente_id = ? AND l.tipo = 'excel_interactivo'";
        $stmt = $pdo->prepare($sql . " GROUP BY l.id ORDER BY curso, l.orden");
        $stmt->execute([$user_id]);
    } else {
        $sql .= " WHERE l.tipo = 'excel_interactivo'";
        $stmt = $pdo->prepare($sql . " GROUP BY l.id ORDER BY curso, l.orden");
        $stmt->execute();
    }
    
    $lecciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($leccion_id) {
        // Listado de alumnos para una lección específica
        $stmt = $pdo->prepare("
            SELECT re.*, u.nombre AS alumno, u.email
            FROM respuestas_excel re
            JOIN usuarios u ON re.usuario_id = u.id
            WHERE re.leccion_id = ?
            ORDER BY re.updated_at DESC
        ");
        $stmt->execute([$leccion_id]);
        $entregas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $leccion_info = array_filter($lecciones, fn($l) => $l['id'] == $leccion_id);
        $leccion_info = reset($leccion_info);
?>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
            <div>
                <h2 style="margin:0;">Entregas: <?= h_excel($leccion_info['titulo']) ?></h2>
                <p style="color:#64748b; margin:0.5rem 0 0;">Curso: <?= h_excel($leccion_info['curso']) ?></p>
            </div>
            <a href="ver_excel.php" class="btn btn-outline-dark">Volver a lecciones</a>
        </div>

        <div style="background:white; border-radius:16px; border:1px solid #e2e8f0; overflow:hidden;">
            <table class="table" style="margin:0;">
                <thead class="table-light">
                    <tr>
                        <th>Estudiante</th>
                        <th>Email</th>
                        <th class="text-center">Puntaje</th>
                        <th class="text-center">Intentos</th>
                        <th>Fecha</th>
                        <th class="text-end">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($entregas)): ?>
                        <tr><td colspan="6" class="text-center py-4">No hay entregas para esta lección aún.</td></tr>
                    <?php else: ?>
                        <?php foreach($entregas as $e): ?>
                            <tr>
                                <td><strong><?= h_excel($e['alumno']) ?></strong></td>
                                <td><?= h_excel($e['email']) ?></td>
                                <td class="text-center">
                                    <span class="badge <?= intval($e['puntaje']) >= 70 ? 'bg-success' : 'bg-danger' ?>" style="font-size:0.9rem;">
                                        <?= intval($e['puntaje']) ?>%
                                    </span>
                                </td>
                                <td class="text-center"><?= intval($e['intentos']) ?></td>
                                <td><?= h_excel(date('d/m/Y H:i', strtotime($e['updated_at'] ?? 'now'))) ?></td>
                                <td class="text-end">
                                    <a href="ver_excel.php?leccion=<?= intval($leccion_id) ?>&usuario=<?= intval($e['usuario_id']) ?>" class="btn btn-sm btn-primary">Ver detalle</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
<?php
    } else {
        // Listado general de lecciones con ejercicios de Excel
?>
        <h2>Ejercicios de Excel Interactivo</h2>
        <p style="color:#64748b; margin-bottom:2rem;">Selecciona una lección para ver las entregas de los estudiantes.</p>

        <div class="row">
            <?php foreach($lecciones as $l): ?>
                <div class="col-md-4 mb-4">
                    <div style="background:white; border-radius:16px; border:1px solid #e2e8f0; padding:1.5rem; transition:0.3s; height:100%; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
                        <div>
                            <span style="font-size:0.75rem; color:var(--accent); font-weight:800; text-transform:uppercase;"><?= h_excel($l['curso']) ?></span>
                            <h4 style="margin:0.5rem 0 1rem;"><?= h_excel($l['titulo']) ?></h4>
                            <div style="display:flex; align-items:center; gap:0.5rem; color:#64748b; font-size:0.9rem;">
                                <i class="fa-solid fa-users"></i>
                                <span><?= intval($l['total_entregas']) ?> entregas registradas</span>
                            </div>
                        </div>
                        <div style="margin-top:1.5rem;">
                            <a href="ver_excel.php?leccion=<?= intval($l['id']) ?>" class="btn btn-dark w-100" style="border-radius:10px;">Ver Entregas</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if(empty($lecciones)): ?>
                <div class="col-12 text-center py-5">
                    <i class="fa-solid fa-table-cells" style="font-size:3rem; color:#cbd5e1; margin-bottom:1rem;"></i>
                    <p style="color:#64748b;">No hay lecciones de tipo Excel Interactivo configuradas.</p>
                </div>
            <?php endif; ?>
        </div>
<?php
    }
}

require_once 'admin_footer.php';
?>
