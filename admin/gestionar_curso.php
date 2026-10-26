<?php
// =====================================================================
// IMPORTANTE: Todo el procesamiento POST y redirect debe ir ANTES
// de require_once 'admin_header.php' para que header() funcione.
// =====================================================================
require_once '../config.php';
require_once __DIR__ . '/../helpers/upload_helper.php';
require_once __DIR__ . '/../helpers/h5p_helper.php';
require_once __DIR__ . '/../helpers/quiz_calificacion_helper.php';

require_once __DIR__ . '/admin_auth.php';
require_rol(['admin', 'docente']);

function verify_curso_docente($pdo, $curso_id, $user_id, $rol) {
    if ($rol === 'admin') return true;
    $stmt = $pdo->prepare("SELECT 1 FROM curso_docentes WHERE curso_id = ? AND docente_id = ?");
    $stmt->execute([$curso_id, $user_id]);
    return (bool) $stmt->fetchColumn();
}

// --- PROCESAR MÓDULOS (antes de enviar HTML) ---
if (isset($_POST['guardar_modulo'])) {
    $titulo    = trim($_POST['titulo']);
    $curso_id  = intval($_POST['curso_id']);
    $orden     = intval($_POST['orden'] ?? 0);
    $estado    = ($_POST['estado'] ?? '') === 'publicado' ? 'publicado' : 'borrador';
    $descripcion = $_POST['descripcion'] ?? '';
    $objetivos   = $_POST['objetivos'] ?? '';

    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    if ($id) {
        $stmt_mod = $pdo->prepare("SELECT curso_id FROM modulos WHERE id=?");
        $stmt_mod->execute([$id]);
        $original_curso_id = $stmt_mod->fetchColumn();
        if ($original_curso_id === false) { http_response_code(404); exit; }

        if ($rol === 'docente') {
            if (!verify_curso_docente($pdo, $original_curso_id, $user_id, $rol)) { http_response_code(403); exit; }
            $curso_id = $original_curso_id;
        } else {
            if ($curso_id != $original_curso_id) {
                $check_curso = $pdo->prepare("SELECT 1 FROM cursos WHERE id=?");
                $check_curso->execute([$curso_id]);
                if (!$check_curso->fetchColumn()) { http_response_code(404); exit; }
            }
        }
        $pdo->prepare("UPDATE modulos SET titulo=?, curso_id=?, orden=?, estado=?, descripcion=?, objetivos=? WHERE id=?")
            ->execute([$titulo, $curso_id, $orden, $estado, $descripcion, $objetivos, $id]);
    } else {
        if ($rol === 'docente') {
            if (!verify_curso_docente($pdo, $curso_id, $user_id, $rol)) { http_response_code(403); exit; }
        } else {
            $check_curso = $pdo->prepare("SELECT 1 FROM cursos WHERE id=?");
            $check_curso->execute([$curso_id]);
            if (!$check_curso->fetchColumn()) { http_response_code(404); exit; }
        }
        if ($orden <= 0) {
            $stmt = $pdo->prepare("SELECT MAX(orden) FROM modulos WHERE curso_id = ?");
            $stmt->execute([$curso_id]);
            $orden = intval($stmt->fetchColumn()) + 1;
        }
        $pdo->prepare("INSERT INTO modulos (titulo, curso_id, orden, estado, descripcion, objetivos) VALUES (?,?,?,?,?,?)")
            ->execute([$titulo, $curso_id, $orden, $estado, $descripcion, $objetivos]);
    }

    $rp = [];
    if (isset($_GET['id'])) $rp['id'] = intval($_GET['id']);
    if (isset($_GET['view']))  $rp['view']  = $_GET['view'];
    $rp['msg'] = 'modulo_ok';
    header('Location: gestionar_curso.php?' . http_build_query($rp), true, 303);
    exit;
}

// --- PROCESAR LECCIONES (antes de enviar HTML) ---
if (isset($_POST['guardar_leccion'])) {
    $id          = intval($_POST['id'] ?? 0);
    $titulo_lec  = trim($_POST['titulo']);
    $modulo_id   = intval($_POST['modulo_id']);
    $tipo_l      = $_POST['lec_tipo'];
    $duracion    = trim($_POST['duracion']);
    $orden_lec   = intval($_POST['orden']);
    $contenido   = $_POST['contenido'];
    $estado_lec  = ($_POST['estado'] ?? '') === 'publicado' ? 'publicado' : 'borrador';
    $dificultad  = $_POST['dificultad'] ?? 'basico';
    $desc_lec    = $_POST['descripcion'] ?? '';
    $rec_titulo  = $_POST['recurso_titulo'] ?? '';
    $rec_url     = $_POST['recurso_url'] ?? '';
    $t_formatos  = $_POST['tarea_formatos'] ?? '';
    $t_dias      = intval($_POST['tarea_dias_limite'] ?? 7);
    $t_puntaje   = intval($_POST['puntaje_minimo'] ?? 0);
    $t_intentos  = intval($_POST['max_intentos'] ?? 0);

    // Campos Clase en Vivo
    $plat_video  = $_POST['plataforma_video'] ?? null;
    $zm_id       = $_POST['zoom_meeting_id'] ?? null;
    $zm_pass     = $_POST['zoom_passcode'] ?? null;
    $fecha_prog  = !empty($_POST['fecha_programada']) ? $_POST['fecha_programada'] : null;

    if ($id) {
        $stmt_lec = $pdo->prepare("SELECT l.modulo_id, l.tipo, l.contenido, m.curso_id FROM lecciones l JOIN modulos m ON l.modulo_id = m.id WHERE l.id=?");
        $stmt_lec->execute([$id]);
        $lec_orig = $stmt_lec->fetch(PDO::FETCH_ASSOC);
        if (!$lec_orig) { http_response_code(404); exit; }

        if ($rol === 'docente') {
            if (!verify_curso_docente($pdo, $lec_orig['curso_id'], $user_id, $rol)) { http_response_code(403); exit; }
            if ($modulo_id != $lec_orig['modulo_id']) {
                $check_mod = $pdo->prepare("SELECT curso_id FROM modulos WHERE id=?");
                $check_mod->execute([$modulo_id]);
                $new_c_id = $check_mod->fetchColumn();
                if (!$new_c_id || !verify_curso_docente($pdo, $new_c_id, $user_id, $rol)) {
                    http_response_code(403); exit;
                }
            }
        } else {
            if ($modulo_id != $lec_orig['modulo_id']) {
                $check_mod = $pdo->prepare("SELECT 1 FROM modulos WHERE id=?");
                $check_mod->execute([$modulo_id]);
                if (!$check_mod->fetchColumn()) { http_response_code(404); exit; }
            }
        }
    } else {
        $lec_orig = null;
        $check_mod = $pdo->prepare("SELECT curso_id FROM modulos WHERE id=?");
        $check_mod->execute([$modulo_id]);
        $c_id_target = $check_mod->fetchColumn();
        if ($c_id_target === false) { http_response_code(404); exit; }

        if ($rol === 'docente') {
            if (!verify_curso_docente($pdo, $c_id_target, $user_id, $rol)) { http_response_code(403); exit; }
        }
    }

    $rp = [];
    if (isset($_GET['id'])) $rp['id'] = intval($_GET['id']);
    if (isset($_GET['view']))  $rp['view']  = $_GET['view'];

    // H5P: el contenido lo arma el servidor (paquete validado), no el formulario
    $plan_h5p = null;
    if ($tipo_l === 'h5p') {
        try {
            $plan_h5p = h5p_antes_de_guardar($lec_orig, $_POST);
        } catch (RuntimeException $e) {
            $_SESSION['h5p_error'] = $e->getMessage();
            header('Location: gestionar_curso.php?' . http_build_query($rp), true, 303);
            exit;
        }
        $contenido = $plan_h5p['contenido'];
        $t_puntaje = $plan_h5p['puntaje'];
    }

    if ($tipo_l === 'arrastrar' && hay_archivo_subido('arr_img_file')) {
        try {
            $fname = guardar_imagen_subida($_FILES['arr_img_file'], __DIR__ . '/../uploads/diagramas', 'diagrama', array_keys(UPLOAD_IMAGENES_MIME));
            $data = json_decode($contenido, true) ?: [];
            $data['imagen'] = '../uploads/diagramas/' . $fname;
            $contenido = json_encode($data);
        } catch (RuntimeException $e) {
            error_log('Imagen de diagrama rechazada: ' . $e->getMessage());
        }
    }

    if ($id) {
        $pdo->prepare("UPDATE lecciones SET titulo=?, modulo_id=?, tipo=?, duracion=?, orden=?, contenido=?, estado=?, dificultad=?, descripcion=?, recurso_titulo=?, recurso_url=?, tarea_formatos=?, tarea_dias_limite=?, puntaje_minimo=?, max_intentos=?, plataforma_video=?, zoom_meeting_id=?, zoom_passcode=?, fecha_programada=? WHERE id=?")
            ->execute([$titulo_lec, $modulo_id, $tipo_l, $duracion, $orden_lec, $contenido, $estado_lec, $dificultad, $desc_lec, $rec_titulo, $rec_url, $t_formatos, $t_dias, $t_puntaje, $t_intentos, $plat_video, $zm_id, $zm_pass, $fecha_prog, $id]);
    } else {
        $pdo->prepare("INSERT INTO lecciones (titulo, modulo_id, tipo, duracion, orden, contenido, estado, dificultad, descripcion, recurso_titulo, recurso_url, tarea_formatos, tarea_dias_limite, puntaje_minimo, max_intentos, plataforma_video, zoom_meeting_id, zoom_passcode, fecha_programada) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$titulo_lec, $modulo_id, $tipo_l, $duracion, $orden_lec, $contenido, $estado_lec, $dificultad, $desc_lec, $rec_titulo, $rec_url, $t_formatos, $t_dias, $t_puntaje, $t_intentos, $plat_video, $zm_id, $zm_pass, $fecha_prog]);
        $id = (int)$pdo->lastInsertId();
    }
    try {
        h5p_despues_de_guardar($pdo, $id, $plan_h5p, $lec_orig);
    } catch (RuntimeException $e) {
        if (!empty($plan_h5p['preparado'])) h5p_descartar_preparado($plan_h5p['preparado']);
        $_SESSION['h5p_error'] = 'La lección se guardó, pero el paquete no: ' . $e->getMessage();
    }
    $rp['msg'] = 'leccion_ok';
    header('Location: gestionar_curso.php?' . http_build_query($rp), true, 303);
    exit;
}

// --- ACCIONES DE ESTADO (Archivar/Restaurar/Publicar) ---
if (isset($_POST['set_estado']) && isset($_POST['id'])) {
    if ($rol !== 'admin') { http_response_code(403); exit; }
    $new_estado = $_POST['set_estado'];
    $c_id = intval($_POST['id']);
    $pdo->prepare("UPDATE cursos SET estado = ? WHERE id = ?")->execute([$new_estado, $c_id]);
    $mensaje = "Estado del curso actualizado a " . strtoupper($new_estado);
}


// --- CALIFICAR TAREAS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calificar_tarea'])) {
    $tarea_id = filter_var($_POST['tarea_id'] ?? null, FILTER_VALIDATE_INT);
    $estado = $_POST['estado_tarea'] ?? '';
    $puntaje_txt = trim((string)($_POST['puntaje'] ?? ''));
    $puntaje = $puntaje_txt === '' ? null : filter_var($puntaje_txt, FILTER_VALIDATE_FLOAT);
    $feedback = trim((string)($_POST['feedback'] ?? ''));

    if (!$tarea_id || !in_array($estado, ['aprobado', 'rechazado'], true) || $puntaje === false || ($puntaje !== null && ($puntaje < 0 || $puntaje > 100))) {
        http_response_code(422); exit;
    }

    $pdo->beginTransaction();
    try {
        $stmt_info = $pdo->prepare("SELECT te.usuario_id, te.leccion_id, te.archivo_ruta, m.curso_id
                                    FROM tareas_entregas te
                                    JOIN lecciones l ON te.leccion_id = l.id
                                    JOIN modulos m ON l.modulo_id = m.id
                                    WHERE te.id = ? FOR UPDATE");
        $stmt_info->execute([$tarea_id]);
        $info = $stmt_info->fetch(PDO::FETCH_ASSOC);
        if (!$info) { $pdo->rollBack(); http_response_code(404); exit; }
        if (!verify_curso_docente($pdo, $info['curso_id'], $user_id, $rol)) { $pdo->rollBack(); http_response_code(403); exit; }
        // Una fila sin archivo solo indica que el estudiante abrió la lección: no hay nada que calificar
        if (empty($info['archivo_ruta'])) { $pdo->rollBack(); http_response_code(409); exit; }

        $pdo->prepare("UPDATE tareas_entregas SET estado=?, puntaje=?, feedback_docente=?, fecha_calificacion=NOW() WHERE id=?")
            ->execute([$estado, $puntaje, $feedback, $tarea_id]);

        // El progreso refleja la calificación vigente: la lección cuenta mientras haya una entrega aprobada
        $stmt_aprob = $pdo->prepare("SELECT 1 FROM tareas_entregas WHERE usuario_id = ? AND leccion_id = ? AND estado = 'aprobado' LIMIT 1");
        $stmt_aprob->execute([$info['usuario_id'], $info['leccion_id']]);
        if ($stmt_aprob->fetchColumn()) {
            $pdo->prepare("INSERT INTO progreso (usuario_id, leccion_id, completado) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE completado = 1")
                ->execute([$info['usuario_id'], $info['leccion_id']]);
        } else {
            // Sin entrega aprobada la lección deja de contar; la fila se conserva por el tiempo de estudio (track_time)
            $pdo->prepare("UPDATE progreso SET completado = 0 WHERE usuario_id = ? AND leccion_id = ?")
                ->execute([$info['usuario_id'], $info['leccion_id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    header("Location: gestionar_curso.php?id={$info['curso_id']}&tab=tareas&msg=calificada", true, 303);
    exit;
}

// --- CALIFICAR QUIZZES (preguntas abiertas o de archivo) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calificar_quiz'])) {
    $intento_id = filter_var($_POST['intento_id'] ?? null, FILTER_VALIDATE_INT);
    try {
        $curso_quiz = quiz_calificar_intento($pdo, $intento_id ?: 0, (string)($_POST['nuevo_estado'] ?? ''), $rol, (int)$user_id);
        header("Location: gestionar_curso.php?id={$curso_quiz}&tab=evaluaciones&msg=quiz_calificado", true, 303);
        exit;
    } catch (Exception $e) {
        $code = in_array($e->getCode(), [403, 404, 409, 422], true) ? $e->getCode() : 500;
        if ($code === 500) {
            error_log('Calificar quiz ' . (int)$intento_id . ': ' . $e->getMessage());
        }
        http_response_code($code);
        $mensajes = [
            403 => "Acceso denegado: el intento no pertenece a sus cursos.",
            404 => "Intento no encontrado.",
            409 => "El intento ya fue calificado.",
            422 => "Parámetros inválidos.",
            500 => "Error interno del servidor.",
        ];
        echo "<div style='padding:2rem; font-family:sans-serif;'><h2>Error {$code}</h2><p>{$mensajes[$code]}</p><a href='javascript:history.back()'>Volver</a></div>";
        exit;
    }
}

// --- ELIMINAR ---
if (isset($_POST['del_mod'])) {
    $c_id_stmt = $pdo->prepare("SELECT curso_id FROM modulos WHERE id=?");
    $c_id_stmt->execute([$_POST['del_mod']]);
    $c_id = $c_id_stmt->fetchColumn();
    if ($c_id === false) { http_response_code(404); exit; }
    if (!verify_curso_docente($pdo, $c_id, $user_id, $rol)) { http_response_code(403); exit; }
    
    $pdo->beginTransaction();
    $stmtLecs = $pdo->prepare("SELECT id FROM lecciones WHERE modulo_id = ?");
    $stmtLecs->execute([$_POST['del_mod']]);
    $lecIds = $stmtLecs->fetchAll(PDO::FETCH_COLUMN);
    $stmtH5p = $pdo->prepare("SELECT contenido FROM lecciones WHERE modulo_id = ? AND tipo = 'h5p'");
    $stmtH5p->execute([$_POST['del_mod']]);
    $paquetes_h5p = $stmtH5p->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($lecIds)) {
        $inClause = implode(',', array_fill(0, count($lecIds), '?'));
        $pdo->prepare("DELETE FROM progreso WHERE leccion_id IN ($inClause)")->execute($lecIds);
        $pdo->prepare("DELETE FROM tareas_entregas WHERE leccion_id IN ($inClause)")->execute($lecIds);
        $pdo->prepare("DELETE FROM lecciones WHERE id IN ($inClause)")->execute($lecIds);
    }
    $pdo->prepare("DELETE FROM modulos WHERE id=?")->execute([$_POST['del_mod']]);
    $pdo->commit();
    foreach ($paquetes_h5p as $contenido_h5p) {
        h5p_eliminar_paquete(h5p_datos_leccion($contenido_h5p)['carpeta'] ?? null);
    }
    header("Location: gestionar_curso.php?id=" . $c_id . "&msg=modulo_ok", true, 303); exit;
}
if (isset($_POST['del_lec'])) {
    $c_id_stmt = $pdo->prepare("SELECT m.curso_id, l.tipo, l.contenido FROM lecciones l JOIN modulos m ON l.modulo_id = m.id WHERE l.id=?");
    $c_id_stmt->execute([$_POST['del_lec']]);
    $lec_borrar = $c_id_stmt->fetch(PDO::FETCH_ASSOC);
    if ($lec_borrar === false) { http_response_code(404); exit; }
    $c_id = $lec_borrar['curso_id'];
    if (!verify_curso_docente($pdo, $c_id, $user_id, $rol)) { http_response_code(403); exit; }

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM progreso WHERE leccion_id = ?")->execute([$_POST['del_lec']]);
    $pdo->prepare("DELETE FROM tareas_entregas WHERE leccion_id = ?")->execute([$_POST['del_lec']]);
    $pdo->prepare("DELETE FROM lecciones WHERE id=?")->execute([$_POST['del_lec']]);
    $pdo->commit();
    if ($lec_borrar['tipo'] === 'h5p') {
        h5p_eliminar_paquete(h5p_datos_leccion($lec_borrar['contenido'])['carpeta'] ?? null);
    }
    header("Location: gestionar_curso.php?id=" . $c_id . "&msg=leccion_ok", true, 303); exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'reorder') {
    header('Content-Type: application/json; charset=UTF-8');

    $type = $_POST['type'] ?? '';
    $ids_raw = $_POST['ids'] ?? null;

    if ($type !== 'modulo' && $type !== 'leccion') {
        http_response_code(422); echo json_encode(['error' => 'Invalid type']); exit;
    }
    if (!is_array($ids_raw) || empty($ids_raw) || count($ids_raw) > 200) {
        http_response_code(422); echo json_encode(['error' => 'Invalid or empty IDs, or limit exceeded']); exit;
    }

    $ids = [];
    foreach ($ids_raw as $id) {
        $id_int = filter_var($id, FILTER_VALIDATE_INT);
        if ($id_int === false || $id_int <= 0) {
            http_response_code(422); echo json_encode(['error' => 'Invalid ID format']); exit;
        }
        $ids[] = $id_int;
    }

    $unique_ids = array_unique($ids);
    if (count($unique_ids) !== count($ids)) {
        http_response_code(422); echo json_encode(['error' => 'Duplicate IDs']); exit;
    }

    try {
        $pdo->beginTransaction();

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($type === 'modulo') {
            $stmt = $pdo->prepare("SELECT curso_id FROM modulos WHERE id IN ($placeholders) FOR UPDATE");
            $stmt->execute($ids);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($ids)) {
                throw new Exception("Count mismatch", 422);
            }
            $cursos = array_unique(array_column($rows, 'curso_id'));
            if (count($cursos) !== 1) {
                throw new Exception("Mixed parents", 422);
            }
            $c_id = $cursos[0];
            if (!verify_curso_docente($pdo, $c_id, $user_id, $rol)) {
                throw new Exception("Unauthorized", 403);
            }

            $update_stmt = $pdo->prepare("UPDATE modulos SET orden = ? WHERE id = ?");
            foreach ($ids as $index => $id) {
                $update_stmt->execute([$index + 1, $id]);
            }
        } else {
            $stmt = $pdo->prepare("SELECT l.modulo_id, m.curso_id FROM lecciones l JOIN modulos m ON l.modulo_id = m.id WHERE l.id IN ($placeholders) FOR UPDATE");
            $stmt->execute($ids);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($ids)) {
                throw new Exception("Count mismatch", 422);
            }
            $modulos = array_unique(array_column($rows, 'modulo_id'));
            if (count($modulos) !== 1) {
                throw new Exception("Mixed parents", 422);
            }
            $cursos = array_unique(array_column($rows, 'curso_id'));
            $c_id = $cursos[0];
            if (!verify_curso_docente($pdo, $c_id, $user_id, $rol)) {
                throw new Exception("Unauthorized", 403);
            }

            $update_stmt = $pdo->prepare("UPDATE lecciones SET orden = ? WHERE id = ?");
            foreach ($ids as $index => $id) {
                $update_stmt->execute([$index + 1, $id]);
            }
        }

        $pdo->commit();
        echo json_encode(['status' => 'success']);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $code = $e->getCode();
        if (!in_array($code, [403, 404, 422])) {
            $code = 500;
        }
        http_response_code($code);
        echo json_encode(['error' => 'Error processing request']);
        exit;
    }
}

$view     = $_GET['view'] ?? 'activos';

$focus_id = isset($_GET['id']) ? intval($_GET['id']) : null;

$has_excel = false;
$excel_lecciones = [];
if ($focus_id) {
    $stmt_check = $pdo->prepare("SELECT l.id, l.titulo, COUNT(re.id) as total_entregas 
                                 FROM lecciones l 
                                 JOIN modulos m ON l.modulo_id = m.id 
                                 LEFT JOIN respuestas_excel re ON l.id = re.leccion_id
                                 WHERE m.curso_id = ? AND l.tipo = 'excel_interactivo'
                                 GROUP BY l.id");
    $stmt_check->execute([$focus_id]);
    $excel_lecciones = $stmt_check->fetchAll(PDO::FETCH_ASSOC);
    $has_excel = count($excel_lecciones) > 0;
}

// Evaluaciones del curso: quizzes por calificar y actividades H5P con sus resultados
$quizzes_pendientes = [];
$h5p_lecciones = [];
if ($focus_id) {
    $quizzes_pendientes = quiz_pendientes_curso($pdo, $focus_id);
    $stmt_h5p = $pdo->prepare("SELECT l.id, l.titulo, COUNT(r.id) AS estudiantes, COALESCE(SUM(r.aprobado), 0) AS aprobados
                               FROM lecciones l
                               JOIN modulos m ON l.modulo_id = m.id
                               LEFT JOIN h5p_resultados r ON r.leccion_id = l.id
                               WHERE m.curso_id = ? AND l.tipo = 'h5p'
                               GROUP BY l.id ORDER BY m.orden, l.orden");
    $stmt_h5p->execute([$focus_id]);
    $h5p_lecciones = $stmt_h5p->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Tareas
$tareas_entregadas = [];
if ($focus_id) {
    $stmt_tareas = $pdo->prepare("SELECT te.*, l.titulo as leccion_titulo, u.nombre as alumno_nombre, u.email as alumno_email 
                                  FROM tareas_entregas te
                                  JOIN lecciones l ON te.leccion_id = l.id
                                  JOIN modulos m ON l.modulo_id = m.id
                                  JOIN usuarios u ON te.usuario_id = u.id
                                  WHERE m.curso_id = ? AND te.archivo_ruta IS NOT NULL AND te.archivo_ruta != ''
                                  ORDER BY te.estado = 'pendiente' DESC, te.fecha_envio DESC");
    $stmt_tareas->execute([$focus_id]);
    $tareas_entregadas = $stmt_tareas->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Metricas del Curso
$metricas_estudiantes = [];
$total_horas_curso = 0;
if ($focus_id) {
    // Estudiantes inscritos (la inscripción se crea al conceder el acceso al curso o a una ruta que lo incluye)
    $stmt_metricas = $pdo->prepare("
        SELECT u.id as usuario_id, u.nombre, u.email, u.telefono,
               SUM(p.segundos_vistos) as total_segundos,
               MAX(p.fecha) as ultima_actividad
        FROM usuarios u
        JOIN inscripciones i ON u.id = i.usuario_id
        LEFT JOIN progreso p ON u.id = p.usuario_id AND p.leccion_id IN (
            SELECT id FROM lecciones WHERE modulo_id IN (SELECT id FROM modulos WHERE curso_id = ?)
        )
        WHERE i.curso_id = ?
        GROUP BY u.id
        ORDER BY total_segundos DESC
    ");
    $stmt_metricas->execute([$focus_id, $focus_id]);
    $metricas_estudiantes = $stmt_metricas->fetchAll(PDO::FETCH_ASSOC);

    foreach($metricas_estudiantes as $est) {
        $total_horas_curso += (int)$est['total_segundos'];
    }
}

// Fetch Clases en Vivo
$clases_vivo_upcoming = [];
$clases_vivo_past = [];
if ($focus_id) {
    $stmt_vivo_upcoming = $pdo->prepare("
        SELECT l.*, m.titulo as modulo_titulo 
        FROM lecciones l
        JOIN modulos m ON l.modulo_id = m.id
        WHERE m.curso_id = ? AND l.tipo = 'clase_vivo' 
        AND (l.fecha_programada IS NULL OR l.fecha_programada >= DATE_SUB(NOW(), INTERVAL 4 HOUR))
        ORDER BY l.fecha_programada ASC
    ");
    $stmt_vivo_upcoming->execute([$focus_id]);
    $clases_vivo_upcoming = $stmt_vivo_upcoming->fetchAll(PDO::FETCH_ASSOC);

    $stmt_vivo_past = $pdo->prepare("
        SELECT l.*, m.titulo as modulo_titulo 
        FROM lecciones l
        JOIN modulos m ON l.modulo_id = m.id
        WHERE m.curso_id = ? AND l.tipo = 'clase_vivo' 
        AND l.fecha_programada IS NOT NULL 
        AND l.fecha_programada < DATE_SUB(NOW(), INTERVAL 4 HOUR)
        ORDER BY l.fecha_programada DESC
    ");
    $stmt_vivo_past->execute([$focus_id]);
    $clases_vivo_past = $stmt_vivo_past->fetchAll(PDO::FETCH_ASSOC);
}

function formatSegundos($segs) {
    if (!$segs) return '0m';
    $horas = floor($segs / 3600);
    $mins = floor(($segs % 3600) / 60);
    if ($horas > 0) return "{$horas}h {$mins}m";
    return "{$mins}m";
}





// Sin curso enfocado: el listado de cursos está en cursos.php (con sus pestañas de estado).
// Los módulos y lecciones solo se gestionan en contexto de un curso.
if (!$focus_id) {
    $filtro_cursos = ['activos' => 'activos', 'borrador' => 'borradores', 'archivados' => 'archivados', 'todos' => 'todos'][$_GET['view'] ?? ''] ?? 'todos';
    header('Location: cursos.php?estado=' . $filtro_cursos, true, 303);
    exit;
}

if ($focus_id) {
    $check_curso = $pdo->prepare("SELECT 1 FROM cursos WHERE id = ?");
    $check_curso->execute([$focus_id]);
    if (!$check_curso->fetchColumn()) {
        http_response_code(404);
        require_once 'admin_header.php';
        echo "<div style='padding:2rem; font-family:sans-serif;'><h2>Error 404</h2><p>Curso inexistente.</p></div>";
        exit;
    }
    if (!verify_curso_docente($pdo, $focus_id, $user_id, $rol)) {
        http_response_code(403);
        require_once 'admin_header.php';
        echo "<div style='padding:2rem; font-family:sans-serif;'><h2>Error 403</h2><p>Acceso denegado al curso enfocado.</p></div>";
        exit;
    }
}

// A partir de aquí ya se puede enviar HTML
require_once 'admin_header.php';

$mensaje = '';
if (isset($_GET['msg'])) {
    $msgs = [
        'modulo_ok'  => 'Módulo guardado correctamente.',
        'leccion_ok' => 'Lección guardada correctamente.',
    ];
    $mensaje = $msgs[$_GET['msg']] ?? '';
}

// --- OBTENER CURSOS SEGÚN VISTA ---
$sql_cursos = "SELECT c.* FROM cursos c";
$params = [];
$conditions = [];

if ($rol === 'docente') {
    $sql_cursos .= " JOIN curso_docentes cd ON c.id = cd.curso_id";
    $conditions[] = "cd.docente_id = ?";
    $params[] = $user_id;
}

if ($focus_id) {
    $conditions[] = "c.id = ?";
    $params[] = $focus_id;
} elseif ($view === 'activos') {
    $conditions[] = "c.estado = 'activo'";
} elseif ($view === 'archivados') {
    $conditions[] = "c.estado = 'archivado'";
} elseif ($view !== 'todos') {
    $conditions[] = "c.estado = ?";
    $params[] = $view;
}

if (!empty($conditions)) {
    $sql_cursos .= " WHERE " . implode(" AND ", $conditions);
}
$sql_cursos .= " ORDER BY c.updated_at DESC";

$stmt_c = $pdo->prepare($sql_cursos);
$stmt_c->execute($params);
$cursos_raw = $stmt_c->fetchAll(PDO::FETCH_ASSOC);

$datos_academia = [];
foreach ($cursos_raw as $c) {
    $stmt_m = $pdo->prepare("SELECT * FROM modulos WHERE curso_id = ? ORDER BY orden");
    $stmt_m->execute([$c['id']]);
    $m_list = $stmt_m->fetchAll(PDO::FETCH_ASSOC);
    foreach ($m_list as $key => $m) {
        $stmt_l = $pdo->prepare("SELECT * FROM lecciones WHERE modulo_id = ? ORDER BY orden");
        $stmt_l->execute([$m['id']]);
        $m_list[$key]['lecciones'] = $stmt_l->fetchAll(PDO::FETCH_ASSOC);
    }
    $datos_academia[] = [ 'id' => $c['id'], 'titulo' => $c['titulo'], 'estado' => $c['estado'], 'modulos' => $m_list, 'updated_at' => $c['updated_at'] ];
}

// Estadísticas para KPIs
$stats = [];
if ($rol === 'docente') {
    $q_base = " FROM cursos c JOIN curso_docentes cd ON c.id = cd.curso_id WHERE cd.docente_id = " . intval($user_id);
    $stats = [
        'activos' => $pdo->query("SELECT COUNT(*) $q_base AND c.estado='activo'")->fetchColumn(),
        'borrador' => $pdo->query("SELECT COUNT(*) $q_base AND c.estado='borrador'")->fetchColumn(),
        'archivados' => $pdo->query("SELECT COUNT(*) $q_base AND c.estado='archivado'")->fetchColumn(),
        'modulos' => $pdo->query("SELECT COUNT(m.id) FROM modulos m JOIN cursos c ON m.curso_id=c.id JOIN curso_docentes cd ON c.id=cd.curso_id WHERE cd.docente_id=".intval($user_id))->fetchColumn(),
        'lecciones' => $pdo->query("SELECT COUNT(l.id) FROM lecciones l JOIN modulos m ON l.modulo_id=m.id JOIN cursos c ON m.curso_id=c.id JOIN curso_docentes cd ON c.id=cd.curso_id WHERE cd.docente_id=".intval($user_id))->fetchColumn(),
    ];
} else {
    $stats = [
        'activos' => $pdo->query("SELECT COUNT(*) FROM cursos WHERE estado='activo'")->fetchColumn(),
        'borrador' => $pdo->query("SELECT COUNT(*) FROM cursos WHERE estado='borrador'")->fetchColumn(),
        'archivados' => $pdo->query("SELECT COUNT(*) FROM cursos WHERE estado='archivado'")->fetchColumn(),
        'modulos' => $pdo->query("SELECT COUNT(*) FROM modulos")->fetchColumn(),
        'lecciones' => $pdo->query("SELECT COUNT(*) FROM lecciones")->fetchColumn(),
    ];
}
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

<div class="nav-tabs-modern" style="display: <?php echo $focus_id ? "flex" : "none" ?>;">
    <a href="gestionar_curso.php?id=<?= $focus_id ?>&tab=contenido" class="tab-item <?= (!isset($_GET['tab']) || $_GET['tab']==='contenido') ? 'active' : '' ?>"><i class="fa-solid fa-list-ul"></i> Contenido</a>
    <a href="gestionar_curso.php?id=<?= $focus_id ?>&tab=evaluaciones" class="tab-item <?= (isset($_GET['tab']) && $_GET['tab']==='evaluaciones') ? 'active' : '' ?>"><i class="fa-solid fa-file-signature"></i> Evaluaciones<?php if (count($quizzes_pendientes)): ?> <span class="tab-badge" title="Quizzes por calificar"><?= count($quizzes_pendientes) ?></span><?php endif; ?></a>
    <a href="gestionar_curso.php?id=<?= $focus_id ?>&tab=tareas" class="tab-item <?= (isset($_GET['tab']) && $_GET['tab']==='tareas') ? 'active' : '' ?>"><i class="fa-solid fa-cloud-arrow-up"></i> Entregables</a>
    <a href="gestionar_curso.php?id=<?= $focus_id ?>&tab=metricas" class="tab-item <?= (isset($_GET['tab']) && $_GET['tab']==='metricas') ? 'active' : '' ?>"><i class="fa-solid fa-chart-line"></i> Métricas</a>
    <a href="gestionar_curso.php?id=<?= $focus_id ?>&tab=clases" class="tab-item <?= (isset($_GET['tab']) && $_GET['tab']==='clases') ? 'active' : '' ?>"><i class="fa-solid fa-video"></i> Clases en Vivo</a>

    <?php if($has_excel): ?>
        <a href="gestionar_curso.php?id=<?= $focus_id ?>&tab=excel" class="tab-item <?= (isset($_GET['tab']) && $_GET['tab']==='excel') ? 'active' : '' ?>"><i class="fa-solid fa-table"></i> Resultados Excel</a>
    <?php endif; ?>
</div>

<div id="tab-tareas" class="tab-content <?= (isset($_GET['tab']) && $_GET['tab']==='tareas') ? 'active' : '' ?>" style="display: <?= (isset($_GET['tab']) && $_GET['tab']==='tareas') ? 'block' : 'none' ?>;">
    <div class="header-controls" style="margin-bottom: 2rem;">
        <h2 style="margin:0;"><i class="fa-solid fa-cloud-arrow-up" style="color:var(--primary);"></i> Entregables de Tareas</h2>
    </div>

    <?php if(empty($tareas_entregadas)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-inbox"></i>
            <p>No hay entregas registradas en este curso aún.</p>
        </div>
    <?php else: ?>
        <!-- Filtros de Entregables -->
        <div style="background: white; padding: 1rem 1.5rem; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 250px; position: relative;">
                <i class="fa-solid fa-search" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-dim);"></i>
                <input type="text" id="filtro_alumno" placeholder="Buscar por alumno o correo..." style="width: 100%; padding: 0.6rem 1rem 0.6rem 2.5rem; border-radius: 8px; border: 1px solid var(--border); outline: none;">
            </div>
            <div style="min-width: 200px;">
                <select id="filtro_leccion" style="width: 100%; padding: 0.6rem 1rem; border-radius: 8px; border: 1px solid var(--border); outline: none; background: white;">
                    <option value="todas">Todas las Lecciones</option>
                    <?php
                    $lecciones_unicas = array_unique(array_column($tareas_entregadas, 'leccion_titulo'));
                    foreach($lecciones_unicas as $lec_un) {
                        echo '<option value="'.htmlspecialchars($lec_un).'">'.htmlspecialchars($lec_un).'</option>';
                    }
                    ?>
                </select>
            </div>
            <div style="min-width: 180px;">
                <select id="filtro_estado" style="width: 100%; padding: 0.6rem 1rem; border-radius: 8px; border: 1px solid var(--border); outline: none; background: white;">
                    <option value="todos">Todos los Estados</option>
                    <option value="pendiente">Pendientes</option>
                    <option value="aprobado">Aprobados</option>
                    <option value="rechazado">Rechazados</option>
                </select>
            </div>
            <div style="font-size: 0.85rem; color: var(--text-dim); font-weight: 600; text-align: right; min-width: 150px;">
                Mostrando <span id="contador_entregas" style="color: var(--primary);"><?= count($tareas_entregadas) ?></span> entregas
            </div>
        </div>

        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <?php foreach($tareas_entregadas as $t): ?>
                <?php
                $is_pdf = strtolower(pathinfo($t['archivo_ruta'], PATHINFO_EXTENSION)) === 'pdf';
                $is_img = in_array(strtolower(pathinfo($t['archivo_ruta'], PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp']);
                
                $badge_color = '#6b7280';
                $icon = 'fa-file-lines';
                if($t['estado'] === 'pendiente') { $badge_color = '#f59e0b'; $icon = 'fa-clock'; }
                if($t['estado'] === 'aprobado') { $badge_color = '#10b981'; $icon = 'fa-check'; }
                if($t['estado'] === 'rechazado') { $badge_color = '#ef4444'; $icon = 'fa-xmark'; }
                ?>
                <div class="entrega-item" data-alumno="<?= strtolower(htmlspecialchars($t['alumno_nombre'] . ' ' . $t['alumno_email'])) ?>" data-leccion="<?= htmlspecialchars($t['leccion_titulo']) ?>" data-estado="<?= htmlspecialchars($t['estado']) ?>" style="display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.5rem; background: white; border-radius: 12px; border: 1px solid var(--border); box-shadow: 0 2px 4px rgba(0,0,0,0.02); gap: 1.5rem; transition: 0.2s;">
                    <div style="display: flex; align-items: center; gap: 1.5rem; flex: 1;">
                        <div style="width: 48px; height: 48px; border-radius: 10px; background: <?= $badge_color ?>15; color: <?= $badge_color ?>; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0;">
                            <i class="fa-solid <?= $icon ?>"></i>
                        </div>
                        <div>
                            <h4 style="margin: 0 0 0.2rem 0; font-size: 1.05rem; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem;">
                                <?= htmlspecialchars($t['alumno_nombre']) ?>
                                <span style="background: <?= $badge_color ?>22; color: <?= $badge_color ?>; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase;">
                                    <?= htmlspecialchars($t['estado']) ?>
                                </span>
                            </h4>
                            <p style="margin: 0; font-size: 0.85rem; color: var(--text-dim);">
                                <strong style="color:var(--text-dark);"><?= htmlspecialchars($t['leccion_titulo']) ?></strong> &bull; 
                                <i class="fa-regular fa-calendar" style="margin-left:0.2rem;"></i> <?= date('d M, Y', strtotime($t['fecha_envio'])) ?> &bull; 
                                <i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($t['alumno_email']) ?>
                            </p>
                        </div>
                    </div>
                    <div>
                        <button class="btn-action" style="background: var(--primary); color: white; padding: 0.6rem 1.2rem; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem;" 
                                onclick="abrirModalEvaluar(<?= htmlspecialchars(json_encode([
                                    'id' => $t['id'],
                                    'alumno' => $t['alumno_nombre'],
                                    'leccion' => $t['leccion_titulo'],
                                    'archivo' => 'ver_tarea.php?f=' . urlencode($t['archivo_ruta']),
                                    'comentario' => $t['comentarios_alumno'],
                                    'estado' => $t['estado'],
                                    'puntaje' => $t['puntaje'],
                                    'feedback' => $t['feedback_docente'],
                                    'is_previewable' => ($is_pdf || $is_img)
                                ])) ?>)">
                            <i class="fa-solid fa-magnifying-glass"></i> Evaluar
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const inputSearch = document.getElementById('filtro_alumno');
        const selectLeccion = document.getElementById('filtro_leccion');
        const selectEstado = document.getElementById('filtro_estado');
        const items = document.querySelectorAll('.entrega-item');
        const contador = document.getElementById('contador_entregas');

        function filtrarEntregas() {
            if(!inputSearch) return;
            const searchVal = inputSearch.value.toLowerCase();
            const leccionVal = selectLeccion.value;
            const estadoVal = selectEstado.value;
            let visibles = 0;

            items.forEach(item => {
                const matchSearch = item.getAttribute('data-alumno').includes(searchVal);
                const matchLeccion = leccionVal === 'todas' || item.getAttribute('data-leccion') === leccionVal;
                const matchEstado = estadoVal === 'todos' || item.getAttribute('data-estado') === estadoVal;

                if (matchSearch && matchLeccion && matchEstado) {
                    item.style.display = 'flex';
                    visibles++;
                } else {
                    item.style.display = 'none';
                }
            });

            if(contador) contador.textContent = visibles;
        }

        if(inputSearch && selectLeccion && selectEstado) {
            inputSearch.addEventListener('input', filtrarEntregas);
            selectLeccion.addEventListener('change', filtrarEntregas);
            selectEstado.addEventListener('change', filtrarEntregas);
        }
    });
    </script>
</div>

<!-- Modal Evaluar Tarea -->
<dialog id="modalEvaluarTarea" style="padding: 0; border: none; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); width: 95%; max-width: 900px; max-height: 90vh; background: white;">
    <div style="display: flex; height: 100%; max-height: 90vh; flex-direction: column;">
        <div style="padding: 1.5rem 2rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border-radius: 16px 16px 0 0;">
            <h3 style="margin:0; color: var(--text-dark);"><i class="fa-solid fa-file-signature" style="color:var(--primary);"></i> Evaluando Entrega</h3>
            <button type="button" onclick="document.getElementById('modalEvaluarTarea').close()" style="background:transparent; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-dim);">&times;</button>
        </div>
        
        <div style="display: flex; flex: 1; overflow: hidden;">
            <!-- Left Side: Preview -->
            <div style="flex: 1.5; border-right: 1px solid var(--border); background: #e2e8f0; display:flex; flex-direction: column; position: relative;">
                <div id="preview-container" style="flex:1; overflow: hidden; display:flex; align-items:center; justify-content:center;">
                    <!-- Injected dynamically -->
                </div>
                <div style="padding: 1rem; background: white; border-top: 1px solid var(--border); text-align: center;">
                    <a id="download-link" href="#" target="_blank" class="btn-action" style="background: var(--text-dark); color: white; display: inline-flex; margin:0 auto;">
                        <i class="fa-solid fa-download"></i> Descargar Archivo Original
                    </a>
                </div>
            </div>

            <!-- Right Side: Form -->
            <div style="flex: 1; overflow-y: auto; padding: 2rem;">
                <form action="gestionar_curso.php?id=<?= $focus_id ?>&tab=tareas" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="calificar_tarea" value="1">
                    <input type="hidden" name="tarea_id" id="eval_tarea_id" value="">
                    
                    <div style="margin-bottom: 1.5rem;">
                        <label style="display:block; font-size:0.85rem; font-weight:600; color:var(--text-dim); margin-bottom:0.3rem;">Alumno</label>
                        <div id="eval_alumno" style="font-weight:700; color:var(--text-dark); font-size:1.1rem;"></div>
                    </div>
                    
                    <div style="margin-bottom: 1.5rem;">
                        <label style="display:block; font-size:0.85rem; font-weight:600; color:var(--text-dim); margin-bottom:0.3rem;">Tarea / Lección</label>
                        <div id="eval_leccion" style="font-weight:600; color:var(--primary);"></div>
                    </div>

                    <div style="margin-bottom: 2rem; background:#f1f5f9; padding: 1rem; border-radius: 8px;">
                        <label style="display:block; font-size:0.85rem; font-weight:600; color:var(--text-dim); margin-bottom:0.5rem;"><i class="fa-solid fa-comment-dots"></i> Comentarios del Alumno:</label>
                        <div id="eval_comentario" style="font-size:0.95rem; color:var(--text-dark); font-style: italic;"></div>
                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <label class="form-label" style="font-weight:600;">Estado de Evaluación *</label>
                        <select name="estado_tarea" id="eval_estado" class="form-control" required style="font-weight:600;">
                            <option value="pendiente">⏳ Pendiente</option>
                            <option value="aprobado">✅ Aprobado</option>
                            <option value="rechazado">❌ Rechazado (Requiere Cambios)</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <label class="form-label">Puntaje Numérico (Opcional)</label>
                        <input type="number" step="0.1" name="puntaje" id="eval_puntaje" class="form-control" placeholder="Ej: 100, 9.5...">
                    </div>

                    <div style="margin-bottom: 2rem;">
                        <label class="form-label">Feedback para el Alumno</label>
                        <textarea name="feedback" id="eval_feedback" class="form-control" rows="4" placeholder="Escribe tus observaciones y recomendaciones aquí..."></textarea>
                    </div>

                    <button type="submit" class="btn-action" style="width: 100%; justify-content: center; padding: 1rem; background: var(--primary); color: white; font-size: 1.1rem;">
                        <i class="fa-solid fa-check-double"></i> Guardar Calificación
                    </button>
                </form>
            </div>
        </div>
    </div>
</dialog>

<script>
function abrirModalEvaluar(data) {
    document.getElementById('eval_tarea_id').value = data.id;
    document.getElementById('eval_alumno').innerText = data.alumno;
    document.getElementById('eval_leccion').innerText = data.leccion;
    document.getElementById('eval_comentario').innerText = data.comentario || 'Ningún comentario adjunto.';
    document.getElementById('eval_estado').value = data.estado;
    document.getElementById('eval_puntaje').value = data.puntaje || '';
    document.getElementById('eval_feedback').value = data.feedback || '';
    document.getElementById('download-link').href = data.archivo;
    
    const previewContainer = document.getElementById('preview-container');
    if (data.is_previewable) {
        if (data.archivo.toLowerCase().endsWith('.pdf')) {
            previewContainer.innerHTML = `<iframe src="${data.archivo}" style="width:100%; height:100%; border:none;"></iframe>`;
        } else {
            previewContainer.innerHTML = `<img src="${data.archivo}" style="max-width:100%; max-height:100%; object-fit:contain;">`;
        }
    } else {
        previewContainer.innerHTML = `
            <div style="text-align:center; padding: 2rem;">
                <i class="fa-solid fa-file-arrow-down" style="font-size: 4rem; color: #9ca3af; margin-bottom: 1rem;"></i>
                <h4 style="margin:0 0 0.5rem 0; color: var(--text-dark);">Archivo no previsualizable</h4>
                <p style="color: var(--text-dim); margin:0;">Usa el botón inferior para descargarlo y revisarlo en tu dispositivo.</p>
            </div>
        `;
    }

    document.getElementById('modalEvaluarTarea').showModal();
}
</script>

<div id="tab-metricas" class="tab-content <?= (isset($_GET['tab']) && $_GET['tab']==='metricas') ? 'active' : '' ?>" style="display: <?= (isset($_GET['tab']) && $_GET['tab']==='metricas') ? 'block' : 'none' ?>;">
    
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
        <div>
            <h3 style="margin:0; font-family:'Poppins';">Métricas de Retención</h3>
            <p style="color:var(--text-dim); margin-top:0.2rem;">Tiempo de estudio invertido por los alumnos matriculados en este curso.</p>
        </div>
        <div style="background:var(--bg-surface); padding:0.8rem 1.5rem; border-radius:12px; font-weight:700; display:flex; align-items:center; gap:0.5rem; border:1px solid var(--border);">
            <i class="fa-solid fa-clock" style="color:var(--primary);"></i> Total Acumulado: <?= formatSegundos($total_horas_curso) ?>
        </div>
    </div>

    <?php if(empty($metricas_estudiantes)): ?>
        <div style="text-align:center; padding:4rem 2rem; background:white; border-radius:16px; border:1px solid var(--border);">
            <i class="fa-solid fa-ghost" style="font-size:4rem; color:var(--border); margin-bottom:1rem;"></i>
            <h4 style="color:var(--text-dim); margin:0;">Aún no hay estudiantes activos o métricas registradas en este curso.</h4>
        </div>
    <?php else: ?>
        <div style="background:white; border-radius:16px; border:1px solid var(--border); overflow:hidden; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
            <table style="width:100%; border-collapse:collapse; text-align:left;">
                <thead style="background:var(--bg-body); border-bottom:1px solid var(--border);">
                    <tr>
                        <th style="padding:1rem 1.5rem; color:var(--text-dim); font-weight:600; font-size:0.85rem;">Estudiante</th>
                        <th style="padding:1rem 1.5rem; color:var(--text-dim); font-weight:600; font-size:0.85rem;">Tiempo Invertido</th>
                        <th style="padding:1rem 1.5rem; color:var(--text-dim); font-weight:600; font-size:0.85rem;">Última Actividad</th>
                        <th style="padding:1rem 1.5rem; color:var(--text-dim); font-weight:600; font-size:0.85rem; text-align:center;">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($metricas_estudiantes as $est): 
                        $dias_inactivo = 0;
                        if ($est['ultima_actividad']) {
                            $ultima = new DateTime($est['ultima_actividad']);
                            $ahora = new DateTime();
                            $dias_inactivo = $ahora->diff($ultima)->days;
                        }
                    ?>
                    <tr style="border-bottom:1px solid var(--border-glass);">
                        <td style="padding:1rem 1.5rem;">
                            <div style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($est['nombre']) ?></div>
                            <div style="font-size:0.8rem; color:var(--text-dim);"><?= htmlspecialchars($est['email']) ?></div>
                        </td>
                        <td style="padding:1rem 1.5rem; font-family:monospace; font-size:1.1rem; color:var(--primary); font-weight:700;">
                            <?= formatSegundos($est['total_segundos']) ?>
                        </td>
                        <td style="padding:1rem 1.5rem; font-size:0.9rem; color:var(--text-dim);">
                            <?= $est['ultima_actividad'] ? date('d M Y', strtotime($est['ultima_actividad'])) : '-' ?>
                            <?php if($est['ultima_actividad']): ?>
                                <br><small><?= date('H:i', strtotime($est['ultima_actividad'])) ?></small>
                            <?php endif; ?>
                        </td>
                        <td style="padding:1rem 1.5rem; text-align:center;">
                            <?php if(!$est['ultima_actividad']): ?>
                                <span style="background:#f3f4f6; color:#4b5563; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600;">Sin Iniciar</span>
                            <?php elseif($dias_inactivo > 14): ?>
                                <span style="background:#fee2e2; color:#b91c1c; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> Riesgo Deserción</span>
                            <?php else: ?>
                                <span style="background:#dcfce7; color:#16a34a; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600;"><i class="fa-solid fa-fire"></i> Activo</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div id="tab-clases" class="tab-content <?= (isset($_GET['tab']) && $_GET['tab']==='clases') ? 'active' : '' ?>" style="display: <?= (isset($_GET['tab']) && $_GET['tab']==='clases') ? 'block' : 'none' ?>;">
    
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
        <div>
            <h3 style="margin:0; font-family:'Poppins';"><i class="fa-solid fa-video" style="color:var(--primary);"></i> Clases en Vivo Programadas</h3>
            <p style="color:var(--text-dim); margin-top:0.2rem;">Administra las sesiones sincrónicas de este curso.</p>
        </div>
    </div>

    <!-- Pestañas Internas (Sub-Tabs) -->
    <div class="nav-tabs-modern" style="margin-bottom:2rem; display:flex; gap:1rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0; background:transparent; border-top:none; border-left:none; border-right:none; border-radius:0;">
        <button onclick="switchClasesTab('proximas')" id="clases-tab-proximas" class="tab-item active" style="background:none; border:none; padding:1rem; font-weight:600; cursor:pointer; color:var(--primary); border-bottom:3px solid var(--accent); border-radius:0;">Próximas Clases (<?= count($clases_vivo_upcoming) ?>)</button>
        <button onclick="switchClasesTab('finalizadas')" id="clases-tab-finalizadas" class="tab-item" style="background:none; border:none; padding:1rem; font-weight:600; cursor:pointer; color:var(--text-dim); border-bottom:3px solid transparent; border-radius:0;">Clases Finalizadas (<?= count($clases_vivo_past) ?>)</button>
    </div>

    <!-- PRÓXIMAS CLASES -->
    <div id="clases-content-proximas" style="display:block;">
        <?php if(empty($clases_vivo_upcoming)): ?>
            <div style="text-align:center; padding: 4rem; background:white; border-radius:12px; border:1px dashed #cbd5e1;">
                <i class="fa-solid fa-calendar-check" style="font-size:3rem; color:#cbd5e1; margin-bottom:1rem;"></i>
                <h4 style="color:#64748b; margin-bottom:0;">No hay próximas clases programadas en este curso</h4>
                <p style="color:#94a3b8;">Usa el botón "+ Nueva Lección" en la pestaña Contenido para programar una.</p>
            </div>
        <?php endif; ?>

        <?php foreach($clases_vivo_upcoming as $lec): 
            $fecha = $lec['fecha_programada'] ? date('d M Y - h:i A', strtotime($lec['fecha_programada'])) : 'Sin fecha';
        ?>
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.5rem; background: white; border-radius: 12px; margin-bottom: 0.75rem; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
            <div style="display: flex; align-items: center; gap: 1.5rem; flex: 1;">
                <div style="width: 48px; height: 48px; border-radius: 10px; background: #e0e7ff; color: #4338ca; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                    <i class="fa-solid fa-video"></i>
                </div>
                <div>
                    <h4 style="margin: 0 0 0.2rem 0; font-size: 1.1rem; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem;">
                        <?= htmlspecialchars($lec['titulo']) ?>
                        <span style="font-size: 0.7rem; background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 12px; font-weight: 700; text-transform: uppercase;"><?= ucfirst($lec['plataforma_video']) ?></span>
                    </h4>
                    <p style="margin: 0; font-size: 0.85rem; color: var(--text-dim);">
                        <strong>Módulo:</strong> <?= htmlspecialchars($lec['modulo_titulo']) ?> &bull; 
                        <i class="fa-regular fa-clock" style="color: var(--accent); margin-left: 0.2rem;"></i> <?= $fecha ?>
                    </p>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <a href="../dashboard/clase?curso=<?= $focus_id ?>&leccion=<?= $lec['id'] ?>&as_host=1" target="_blank" class="btn btn-primary" style="padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.9rem;"><i class="fa-solid fa-play"></i> Iniciar</a>
                <button onclick="editarLeccion(<?= htmlspecialchars(json_encode($lec)) ?>)" class="btn btn-outline-dark" style="padding: 0.5rem; border-radius: 8px;" title="Editar Clase"><i class="fa-solid fa-pen"></i></button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- CLASES FINALIZADAS -->
    <div id="clases-content-finalizadas" style="display:none;">
        <?php if(empty($clases_vivo_past)): ?>
            <div style="text-align:center; padding: 4rem; background:white; border-radius:12px; border:1px dashed #cbd5e1;">
                <i class="fa-solid fa-box-archive" style="font-size:3rem; color:#cbd5e1; margin-bottom:1rem;"></i>
                <h4 style="color:#64748b; margin-bottom:0;">No hay clases en el historial de este curso</h4>
            </div>
        <?php endif; ?>

        <?php foreach($clases_vivo_past as $lec): 
            $fecha = $lec['fecha_programada'] ? date('d M Y - h:i A', strtotime($lec['fecha_programada'])) : 'Sin fecha';
        ?>
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.5rem; background: #f8fafc; border-radius: 12px; margin-bottom: 0.75rem; border: 1px solid #e2e8f0; opacity: 0.8;">
            <div style="display: flex; align-items: center; gap: 1.5rem; flex: 1;">
                <div style="width: 48px; height: 48px; border-radius: 10px; background: #e2e8f0; color: #64748b; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                    <i class="fa-solid fa-check"></i>
                </div>
                <div>
                    <h4 style="margin: 0 0 0.2rem 0; font-size: 1.1rem; color: #64748b; text-decoration: line-through;">
                        <?= htmlspecialchars($lec['titulo']) ?>
                    </h4>
                    <p style="margin: 0; font-size: 0.85rem; color: #94a3b8;">
                        <strong>Módulo:</strong> <?= htmlspecialchars($lec['modulo_titulo']) ?> &bull; 
                        <i class="fa-regular fa-clock" style="margin-left: 0.2rem;"></i> <?= $fecha ?>
                    </p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <script>
    function switchClasesTab(tab) {
        document.getElementById('clases-tab-proximas').style.borderColor = 'transparent';
        document.getElementById('clases-tab-proximas').style.color = 'var(--text-dim)';
        document.getElementById('clases-tab-finalizadas').style.borderColor = 'transparent';
        document.getElementById('clases-tab-finalizadas').style.color = 'var(--text-dim)';
        
        document.getElementById('clases-tab-' + tab).style.borderColor = 'var(--accent)';
        document.getElementById('clases-tab-' + tab).style.color = 'var(--primary)';
        
        document.getElementById('clases-content-proximas').style.display = 'none';
        document.getElementById('clases-content-finalizadas').style.display = 'none';
        
        document.getElementById('clases-content-' + tab).style.display = 'block';
    }
    </script>
</div>

<div id="tab-evaluaciones" class="tab-content <?= (isset($_GET['tab']) && $_GET['tab']==='evaluaciones') ? 'active' : '' ?>" style="display: <?= (isset($_GET['tab']) && $_GET['tab']==='evaluaciones') ? 'block' : 'none' ?>;">
    <div class="header-controls" style="margin-bottom: 1.5rem;">
        <div>
            <h2 style="margin:0;"><i class="fa-solid fa-file-signature" style="color:var(--primary);"></i> Quizzes por calificar</h2>
            <p style="color:var(--text-dim); margin:0.3rem 0 0;">Intentos con preguntas abiertas o de archivo que esperan tu revisión. Aprobar marca la lección como completada.</p>
        </div>
    </div>

    <?php if (($_GET['msg'] ?? '') === 'quiz_calificado'): ?>
        <div style="background:#dcfce7; color:#166534; padding:1rem; border-radius:12px; margin-bottom:1.5rem; font-weight:600;"><i class="fa-solid fa-check-circle"></i> Intento calificado correctamente.</div>
    <?php endif; ?>

    <?php if (empty($quizzes_pendientes)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-check-double"></i>
            <p>No hay quizzes pendientes de calificación en este curso.</p>
        </div>
    <?php else: ?>
        <?php foreach ($quizzes_pendientes as $p): ?>
        <div style="background:white; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05); margin-bottom:1.5rem; overflow:hidden; border:1px solid var(--border);">
            <div style="background:var(--primary); color:white; padding:1rem 1.5rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
                <div>
                    <strong style="font-size:1.05rem;"><?= htmlspecialchars($p['alumno']) ?></strong><br>
                    <small style="opacity:0.8;">Lección: <?= htmlspecialchars($p['leccion_titulo']) ?></small>
                </div>
                <div style="text-align:right;">
                    <small><?= date('d/m/Y H:i', strtotime($p['fecha'])) ?></small><br>
                    <span style="background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:4px; font-size:0.75rem;">Pendiente</span>
                </div>
            </div>

            <div style="padding:1.5rem;">
                <h4 style="margin-top:0; color:var(--primary); border-bottom:1px solid #eee; padding-bottom:0.5rem;">Respuestas a revisar:</h4>
                <?php foreach ($p['respuestas'] as $r): ?>
                <div style="margin-bottom:1.25rem; background:#f8fafc; padding:1rem; border-radius:8px;">
                    <p style="font-weight:600; margin:0 0 0.5rem;"><?= htmlspecialchars($r['pregunta']) ?></p>
                    <?php if ($r['tipo'] === 'abierta'): ?>
                        <div style="background:white; padding:0.8rem; border:1px solid #e2e8f0; border-radius:6px; color:#334155; white-space:pre-wrap;"><?= htmlspecialchars((string)$r['respuesta_texto']) ?></div>
                    <?php else: ?>
                        <div style="display:flex; align-items:center; gap:1rem;">
                            <i class="fa-solid fa-file-arrow-down" style="font-size:1.5rem; color:var(--accent);"></i>
                            <a href="../<?= htmlspecialchars((string)$r['archivo_ruta']) ?>" target="_blank" class="btn btn-sm btn-outline-dark">Descargar archivo entregado</a>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if (empty($p['respuestas'])): ?>
                    <p style="color:var(--text-dim);">El intento no tiene respuestas abiertas registradas.</p>
                <?php endif; ?>

                <div style="background:#fff7ed; padding:1rem; border-radius:8px; border:1px solid #ffedd5; display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
                    <p style="margin:0; font-size:0.9rem; color:#9a3412;">Puntaje automático: <strong><?= htmlspecialchars((string)$p['puntaje']) ?>%</strong></p>
                    <form method="POST" action="gestionar_curso.php?id=<?= (int)$focus_id ?>&tab=evaluaciones" style="display:flex; gap:0.5rem;" onsubmit="this.querySelectorAll('button').forEach(function (b) { b.disabled = true; });">
                        <?= csrf_input() ?>
                        <input type="hidden" name="calificar_quiz" value="1">
                        <input type="hidden" name="intento_id" value="<?= (int)$p['id'] ?>">
                        <input type="hidden" name="nuevo_estado" value="">
                        <button type="submit" class="btn" style="background:#dc2626; color:white;" onclick="this.form.nuevo_estado.value = 'reprobado';"><i class="fa-solid fa-xmark"></i> Reprobar</button>
                        <button type="submit" class="btn" style="background:#16a34a; color:white;" onclick="this.form.nuevo_estado.value = 'aprobado';"><i class="fa-solid fa-check"></i> Aprobar</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($h5p_lecciones)): ?>
    <h2 style="margin:2.5rem 0 1rem;"><i class="fa-solid fa-puzzle-piece" style="color:var(--primary);"></i> Actividades H5P</h2>
    <div style="background:white; border-radius:12px; border:1px solid var(--border); overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc; text-align:left; font-size:0.85rem; color:var(--text-dim);">
                    <th style="padding:0.9rem 1.2rem;">Actividad</th>
                    <th style="padding:0.9rem 1.2rem; text-align:center;">Estudiantes</th>
                    <th style="padding:0.9rem 1.2rem; text-align:center;">Aprobados</th>
                    <th style="padding:0.9rem 1.2rem;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($h5p_lecciones as $hl): ?>
                <tr style="border-top:1px solid var(--border);">
                    <td style="padding:0.9rem 1.2rem; font-weight:600;"><?= htmlspecialchars($hl['titulo']) ?></td>
                    <td style="padding:0.9rem 1.2rem; text-align:center;"><?= (int)$hl['estudiantes'] ?></td>
                    <td style="padding:0.9rem 1.2rem; text-align:center;"><?= (int)$hl['aprobados'] ?></td>
                    <td style="padding:0.9rem 1.2rem; text-align:right;"><a href="ver_h5p?leccion=<?= (int)$hl['id'] ?>" class="btn btn-sm btn-primary">Ver resultados</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($has_excel): ?>
<div id="tab-excel" class="tab-content <?= (isset($_GET['tab']) && $_GET['tab']==='excel') ? 'active' : '' ?>" style="display: <?= (isset($_GET['tab']) && $_GET['tab']==='excel') ? 'block' : 'none' ?>;">
    <div class="header-controls" style="margin-bottom: 1.5rem;">
        <h2 style="margin:0;"><i class="fa-solid fa-table" style="color:var(--primary);"></i> Resultados de Excel interactivo</h2>
    </div>
    <div style="background:white; border-radius:12px; border:1px solid var(--border); overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc; text-align:left; font-size:0.85rem; color:var(--text-dim);">
                    <th style="padding:0.9rem 1.2rem;">Ejercicio</th>
                    <th style="padding:0.9rem 1.2rem; text-align:center;">Entregas</th>
                    <th style="padding:0.9rem 1.2rem;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($excel_lecciones as $el): ?>
                <tr style="border-top:1px solid var(--border);">
                    <td style="padding:0.9rem 1.2rem; font-weight:600;"><?= htmlspecialchars($el['titulo']) ?></td>
                    <td style="padding:0.9rem 1.2rem; text-align:center;"><?= (int)$el['total_entregas'] ?></td>
                    <td style="padding:0.9rem 1.2rem; text-align:right;"><a href="ver_excel?leccion=<?= (int)$el['id'] ?>" class="btn btn-sm btn-primary">Ver resultados</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>

<style>
    :root {
        /* Heredados dinámicamente */
        --st-video: #6366f1;
        --card-bg: #ffffff;
        --text-main: #1e293b;
        --text-dim: #64748b;
        --border: #e2e8f0;
        --radius: 16px;
        /* Colores de Estado */
        --st-activo: #10b981;
        --st-borrador: #94a3b8;
        --st-archivado: #2A3547;
        --st-finalizado: #8b5cf6;
        --st-pausado: #f59e0b;
        --st-privado: #ef4444;
    }

    body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text-main); }
    .badge-borrador { font-size:0.65rem; font-weight:700; text-transform:uppercase; color:#92400e; background:#fef3c7; padding:2px 8px; border-radius:6px; margin-left:6px; }

    /* KPI Grid Premium */
    .kpi-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
    .kpi-mini-card { background: white; padding: 1.2rem; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
    .kpi-mini-card h4 { margin: 0; font-size: 1.2rem; font-weight: 700; color: var(--primary); }
    .kpi-mini-card p { margin: 0.2rem 0 0; font-size: 0.75rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.5px; }

    /* Navigation Tabs */
    .nav-tabs-modern { display: flex; gap: 0.5rem; background: #fff; padding: 0.5rem; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 2rem; overflow-x: auto; }
    .tab-item { padding: 0.6rem 1.2rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; color: var(--text-dim); text-decoration: none; transition: 0.2s; white-space: nowrap; }
    .tab-item:hover { background: #f1f5f9; color: var(--primary); }
    .tab-item.active { background: var(--primary); color: white; }
    .tab-badge { display: inline-block; min-width: 1.3rem; padding: 0 0.35rem; margin-left: 0.25rem; border-radius: 999px; background: var(--accent); color: white; font-size: 0.7rem; line-height: 1.3rem; text-align: center; }

    /* Search & Header */
    .header-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; gap: 1rem; }
    .search-box { flex: 1; position: relative; max-width: 400px; }
    .search-box input { width: 100%; padding: 0.8rem 1rem 0.8rem 2.8rem; border-radius: 12px; border: 1px solid var(--border); outline: none; transition: 0.3s; }
    .search-box i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-dim); }

    /* Course Cards (Hierarchy Level 1) */
    .course-builder-card { background: white; border-radius: 20px; border: 1px solid var(--border); margin-bottom: 1.5rem; overflow: hidden; transition: 0.3s; }
    .course-builder-header { padding: 1.5rem; display: flex; align-items: center; justify-content: space-between; cursor: pointer; }
    .course-builder-header:hover { background: #fcfcfc; }
    .course-main-info { display: flex; align-items: center; gap: 1.2rem; flex: 1; }
    .course-icon-box { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; background: #f8fafc; color: var(--accent); }
    .course-meta { display: flex; flex-direction: column; gap: 0.2rem; }
    .course-meta h3 { margin: 0; font-size: 1.15rem; font-weight: 700; color: var(--text-main); line-height: 1.2; }
    .course-meta p { margin: 0; font-size: 0.85rem; color: var(--text-dim); font-weight: 500; line-height: 1.2; }

    .badge-status { padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
    .st-activo { background: #dcfce7; color: #166534; }
    .st-borrador { background: #f1f5f9; color: #475569; }
    .st-archivado { background: #e2e8f0; color: #1e293b; }

    /* Modules (Hierarchy Level 2) */
    .modules-container { padding: 1.5rem; background: #f8fafc; border-top: 1px solid var(--border); display: none; }
    .module-item { background: white; border-radius: 14px; border: 1px solid var(--border); margin-bottom: 1rem; overflow: hidden; }
    .module-header-pro { padding: 1rem 1.5rem; display: flex; align-items: center; justify-content: space-between; cursor: pointer; }
    .module-header-pro:hover { background: #fdfdfd; }

    /* Lessons (Hierarchy Level 3) */
    .lessons-list-pro { padding: 0.5rem 1.5rem 1.5rem 3.5rem; border-left: 2px solid #e2e8f0; margin-left: 2.2rem; display: none; }
    .lesson-item-pro { display: flex; align-items: center; justify-content: space-between; padding: 0.7rem 1rem; background: white; border-radius: 10px; margin-bottom: 0.4rem; border: 1px solid transparent; transition: 0.2s; }
    .lesson-item-pro:hover { border-color: var(--accent); background: #fffcf9; }

    .lesson-type-icon { width: 30px; height: 30px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; }
    .type-video { background: #fee2e2; color: #ef4444; }
    .type-pdf { background: #e0f2fe; color: #0ea5e9; }
    .type-quiz { background: #f3e8ff; color: #a855f7; }
    .type-texto { background: #f1f5f9; color: #64748b; }
    .type-excel_interactivo { background: #dcfce7; color: #22c55e; }
    .type-h5p { background: #e0e7ff; color: #6366f1; }

    /* Quick Actions Hover */
    .actions-hover { opacity: 0; transition: 0.2s; display: flex; gap: 0.4rem; }
    .course-builder-header:hover .actions-hover,
    .module-header-pro:hover .actions-hover,
    .lesson-item-pro:hover .actions-hover { opacity: 1; }

    .btn-circle { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 1px solid var(--border); background: white; color: var(--text-dim); transition: 0.2s; font-size: 0.8rem; }
    .btn-circle:hover { background: var(--primary); color: white; border-color: var(--primary); }

    /* Focus Mode Overlay */
    .focus-badge { background: var(--accent); color: white; padding: 4px 12px; border-radius: 8px; font-size: 0.7rem; font-weight: 800; margin-right: 1rem; }

    /* Animations */
    @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    .animate-in { animation: slideDown 0.3s ease-out; }
</style>



<div id="tab-contenido" class="tab-content" style="display: <?= (!isset($_GET['tab']) || $_GET['tab']==='contenido') ? 'block' : 'none' ?>;">
<div class="header-controls">
    <div>
        <h2 style="margin:0; font-weight:800;"><?= $focus_id ? 'Focus Mode: Gestionar Curso' : 'Estructura de Contenidos' ?></h2>
        <p style="color:var(--text-dim); margin:0.2rem 0 0;"><?= $focus_id ? 'Estás editando un curso específico para evitar distracciones.' : 'Administra tus cursos, módulos y lecciones de forma eficiente.' ?></p>
    </div>
    <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
        <?php if($focus_id): ?>
            <!-- Cambio B: Volver ahora apunta a la lista de cursos -->
            <a href="cursos" class="btn btn-outline-dark" style="border-radius:8px; padding:0.45rem 1rem; font-weight:600; font-size:0.82rem; display:flex; align-items:center; gap:0.4rem; text-decoration:none;"><i class="fa-solid fa-arrow-left" style="font-size:0.75rem;"></i> Volver a Cursos</a>
        <?php endif; ?>
        <?php
        // Cambio C: Calcular si hay al menos un módulo en este curso
        $total_modulos_focus = 0;
        if ($focus_id && !empty($datos_academia)) {
            $total_modulos_focus = count($datos_academia[0]['modulos']);
        }
        $leccion_disabled = $focus_id && $total_modulos_focus === 0;
        ?>
        <?php if($leccion_disabled): ?>
            <button class="btn btn-dark" disabled title="Crea al menos un módulo primero" style="background:#94a3b8; border:none; color:white; border-radius:8px; padding:0.45rem 1rem; font-weight:600; font-size:0.82rem; display:flex; align-items:center; gap:0.4rem; cursor:not-allowed; opacity:0.6;"><i class="fa-solid fa-lock" style="font-size:0.75rem;"></i> Nueva Lección</button>
        <?php else: ?>
            <button class="btn btn-dark" style="background:var(--accent); border:none; color:white; border-radius:8px; padding:0.45rem 1rem; font-weight:600; font-size:0.82rem; display:flex; align-items:center; gap:0.4rem;" onclick="resetLeccion(); switchPanel('nueva_leccion')"><i class="fa-solid fa-plus" style="font-size:0.75rem;"></i> Nueva Lección</button>
        <?php endif; ?>
        <button class="btn btn-outline-dark" style="border-radius:8px; padding:0.45rem 1rem; font-weight:600; font-size:0.82rem; display:flex; align-items:center; gap:0.4rem;" onclick="resetModulo(); switchPanel('nuevo_modulo')"><i class="fa-solid fa-folder-plus" style="font-size:0.75rem;"></i> Nuevo Módulo</button>
    </div>
</div>

<div class="kpi-container">
    <?php if($focus_id && !empty($datos_academia)): ?>
        <?php 
        $curso_focus = $datos_academia[0];
        $tot_modulos = count($curso_focus['modulos']);
        $tot_lecciones = array_reduce($curso_focus['modulos'], function($c,$m){return $c+count($m['lecciones']);}, 0);
        ?>
        <div class="kpi-mini-card" style="border-left: 4px solid var(--accent);">
            <h4 style="text-transform: capitalize;"><?= htmlspecialchars($curso_focus['estado']) ?></h4>
            <p>Estado del Curso</p>
        </div>
        <div class="kpi-mini-card">
            <h4><?= $tot_modulos ?></h4>
            <p>Módulos</p>
        </div>
        <div class="kpi-mini-card">
            <h4><?= $tot_lecciones ?></h4>
            <p>Lecciones</p>
        </div>
        <div class="kpi-mini-card">
            <h4><?= date('d M Y', strtotime($curso_focus['updated_at'])) ?></h4>
            <p>Última Actualización</p>
        </div>
    <?php else: ?>
        <div class="kpi-mini-card"><h4><?= $stats['activos'] ?></h4><p>Activos</p></div>
        <div class="kpi-mini-card"><h4><?= $stats['borrador'] ?></h4><p>Borradores</p></div>
        <div class="kpi-mini-card"><h4><?= $stats['archivados'] ?></h4><p>Archivados</p></div>
        <div class="kpi-mini-card"><h4><?= $stats['modulos'] ?></h4><p>Módulos</p></div>
        <div class="kpi-mini-card"><h4><?= $stats['lecciones'] ?></h4><p>Lecciones</p></div>
    <?php endif; ?>
</div>

<?php if(!$focus_id): ?>
<div class="nav-tabs-modern">
    <a href="?view=activos" class="tab-item <?= $view==='activos'?'active':'' ?>">Activos</a>
    <a href="?view=borrador" class="tab-item <?= $view==='borrador'?'active':'' ?>">Borradores</a>
    <a href="?view=archivados" class="tab-item <?= $view==='archivados'?'active':'' ?>">Archivados</a>
    <a href="?view=todos" class="tab-item <?= $view==='todos'?'active':'' ?>">Todos</a>
    <div class="search-box" style="margin-left:auto;">
        <i class="fa-solid fa-search"></i>
        <input type="text" id="courseSearch" placeholder="Buscar curso, módulo o lección..." onkeyup="filterCourses()">
    </div>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['h5p_error'])): ?>
<div style="background:#fee2e2; color:#991b1b; padding:1rem; border-radius:12px; margin-bottom:1.5rem; font-weight:600; display:flex; align-items:center; gap:1rem;">
    <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($_SESSION['h5p_error'], ENT_QUOTES, 'UTF-8') ?>
</div>
<?php unset($_SESSION['h5p_error']); endif; ?>
<?php if($mensaje): ?>
<div style="background:#dcfce7; color:#166534; padding:1rem; border-radius:12px; margin-bottom:1.5rem; font-weight:600; display:flex; align-items:center; gap:1rem;">
    <i class="fa-solid fa-check-circle"></i> <?= $mensaje ?>
</div>
<?php endif; ?>

<div id="builder-container">
    <?php if(empty($datos_academia)): ?>
        <div style="text-align:center; padding:5rem; background:white; border-radius:20px; border:1px dashed #cbd5e1;">
            <i class="fa-solid fa-folder-open" style="font-size:3rem; color:#cbd5e1; margin-bottom:1.5rem;"></i>
            <h3 style="color:#64748b;">No hay cursos en esta vista</h3>
            <p style="color:#94a3b8;">Cambia de filtro o crea un nuevo curso desde la sección de Cursos.</p>
        </div>
    <?php endif; ?>

    <?php foreach($datos_academia as $curso): ?>
    <div class="course-builder-card animate-in" data-search="<?= strtolower($curso['titulo']) ?>">
        <div class="course-builder-header" onclick="toggleBranch('course', <?= $curso['id'] ?>)">
            <div class="course-main-info">
                <?php if($focus_id): ?><span class="focus-badge">FOCUS MODE</span><?php endif; ?>
                <div class="course-icon-box"><i class="fa-solid fa-graduation-cap"></i></div>
                <div class="course-meta">
                    <h3><?= htmlspecialchars($curso['titulo']) ?></h3>
                    <p><?= count($curso['modulos']) ?> módulos • <?= array_reduce($curso['modulos'], function($c,$m){return $c+count($m['lecciones']);}, 0) ?> lecciones • Act. <?= date('d M', strtotime($curso['updated_at'])) ?></p>
                </div>
                <span class="badge-status st-<?= $curso['estado'] ?>"><?= $curso['estado'] ?></span>
            </div>
            <div style="display:flex; align-items:center; gap:1rem;">
                <div class="actions-hover">
                    <a href="?id=<?= $curso['id'] ?>" class="btn-circle" title="Gestionar (Focus Mode)"><i class="fa-solid fa-expand"></i></a>
                    <?php if($rol === 'admin'): ?>
                    <form method="POST" style="margin:0;" title="<?= $curso['estado']==='archivado'?'Restaurar':'Archivar' ?>">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="id" value="<?= $curso['id'] ?>">
                        <button type="submit" name="set_estado" value="<?= $curso['estado']==='archivado'?'borrador':'archivado' ?>" class="btn-circle" style="border:1px solid var(--border);"><i class="fa-solid <?= $curso['estado']==='archivado'?'fa-rotate-left':'fa-box-archive' ?>"></i></button>
                    </form>
                    <?php endif; ?>
                    <a href="cursos" class="btn-circle" title="Configuración"><i class="fa-solid fa-cog"></i></a>
                </div>
                <i class="fa-solid fa-chevron-down" id="chevron-course-<?= $curso['id'] ?>" style="transition:0.3s; color:#cbd5e1;"></i>
            </div>
        </div>

        <div class="modules-container" id="container-course-<?= $curso['id'] ?>" style="<?= $focus_id ? 'display:block;' : '' ?>">
            <div class="modules-sortable" data-course-id="<?= $curso['id'] ?>">
                <?php foreach($curso['modulos'] as $m): ?>
                <div class="module-item" data-id="<?= $m['id'] ?>" data-search="<?= strtolower($m['titulo']) ?>">
                    <div class="module-header-pro" onclick="toggleBranch('module', <?= $m['id'] ?>)">
                        <div style="display:flex; align-items:center; gap:1rem;">
                            <i class="fa-solid fa-grip-vertical" style="color:#cbd5e1; cursor:grab;"></i>
                            <div style="width:32px; height:32px; background:#f1f5f9; border-radius:8px; display:flex; align-items:center; justify-content:center; color:var(--primary); font-size:0.8rem;"><i class="fa-solid fa-folder"></i></div>
                            <div style="font-weight:600; font-size:0.95rem;">Módulo <?= $m['orden'] ?>: <?= htmlspecialchars($m['titulo']) ?><?php if(($m['estado'] ?? '') !== 'publicado'): ?> <span class="badge-borrador">Borrador</span><?php endif; ?></div>
                        </div>
                        <div style="display:flex; align-items:center; gap:1rem;">
                            <span style="font-size:0.75rem; color:var(--text-dim);"><?= count($m['lecciones']) ?> lecciones</span>
                            <div class="actions-hover">
                                <button onclick="event.stopPropagation(); editarModulo(<?= htmlspecialchars(json_encode($m)) ?>)" class="btn-circle"><i class="fa-solid fa-pen"></i></button>
                                <form method="POST" style="margin:0;" onsubmit="return confirm('¿Eliminar módulo?')" onclick="event.stopPropagation();">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <button type="submit" name="del_mod" value="<?= $m['id'] ?>" class="btn-circle" style="border:1px solid var(--border);"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </div>
                            <i class="fa-solid fa-chevron-right" id="chevron-module-<?= $m['id'] ?>" style="transition:0.3s; color:#cbd5e1; font-size:0.8rem;"></i>
                        </div>
                    </div>

                    <div class="lessons-list-pro" id="container-module-<?= $m['id'] ?>">
                        <div class="lessons-sortable" data-modulo-id="<?= $m['id'] ?>">
                            <?php foreach($m['lecciones'] as $l): ?>
                            <div class="lesson-item-pro" data-id="<?= $l['id'] ?>" data-search="<?= strtolower($l['titulo']) ?>">
                                <div style="display:flex; align-items:center; gap:0.8rem;">
                                    <i class="fa-solid fa-grip-vertical" style="color:#e2e8f0; cursor:grab; font-size:0.7rem;"></i>
                                    <div class="lesson-type-icon type-<?= $l['tipo'] ?>"><i class="fa-solid <?= $l['tipo']=='video'?'fa-play':($l['tipo']=='quiz'?'fa-vial':($l['tipo']=='hoja_calculo'?'fa-table':($l['tipo']=='arrastrar'?'fa-bullseye':($l['tipo']=='excel_interactivo'?'fa-table-cells':($l['tipo']=='h5p'?'fa-puzzle-piece':'fa-file'))))) ?>"></i></div>
                                    <div style="font-size:0.85rem; font-weight:500;"><?= htmlspecialchars($l['titulo']) ?><?php if(($l['estado'] ?? '') !== 'publicado'): ?> <span class="badge-borrador">Borrador</span><?php endif; ?> <span style="font-size:0.7rem; color:var(--text-dim); margin-left:10px;"><?= htmlspecialchars((string)$l['duracion']) ?></span></div>
                                </div>
                                <div class="actions-hover">
                                    <?php if($l['tipo']==='excel_interactivo'): ?> <a href="ver_excel?leccion=<?= $l['id'] ?>" class="btn-circle" title="Ver Resultados"><i class="fa-solid fa-table-cells"></i></a> <?php endif; ?>
                                    <?php if($l['tipo']==='h5p'): ?> <a href="ver_h5p?leccion=<?= $l['id'] ?>" class="btn-circle" title="Ver Resultados"><i class="fa-solid fa-puzzle-piece"></i></a> <?php endif; ?>
                                    <?php if($l['tipo']==='quiz'): ?> <a href="quiz?leccion=<?= $l['id'] ?>" class="btn-circle" title="Gestionar Quiz"><i class="fa-solid fa-vial"></i></a> <?php endif; ?>
                                    <button onclick="editarLeccion(<?= htmlspecialchars(json_encode($l)) ?>)" class="btn-circle"><i class="fa-solid fa-edit"></i></button>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('¿Eliminar lección?');">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <button type="submit" name="del_lec" value="<?= $l['id'] ?>" class="btn-circle" style="border:1px solid var(--border);"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
</div> <!-- Cierra tab-contenido -->

<!-- Modales/Paneles de Edición (Mantengo la lógica pero mejoro el estilo) -->
<div id="panel-nuevo_modulo" class="panel-admin" style="display:none; background:white; padding:2rem; border-radius:20px; box-shadow:0 20px 40px rgba(0,0,0,0.1); position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:90%; max-width:500px; z-index:1000; max-height:90vh; overflow-y:auto;">
    <h3 id="mod-titulo-txt">Gestionar Módulo</h3>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="id" id="mod_id">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
            <div class="form-group"><label>Curso</label>
                <select name="curso_id" id="mod_curso_id" required style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                    <?php foreach($cursos_raw as $c): ?> <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['titulo']) ?></option> <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Orden (0 = Automático)</label><input type="number" name="orden" id="mod_orden" value="0" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;"></div>
        </div>
        <div class="form-group" style="margin-top:1rem;"><label>Título</label><input type="text" name="titulo" id="mod_nombre" required autocomplete="off" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;"></div>
        <div class="form-group" style="margin-top:1rem;"><label>Estado</label>
            <select name="estado" id="mod_estado" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                <option value="publicado" selected>Publicado: visible para los estudiantes</option>
                <option value="borrador">Borrador: oculto, no cuenta para el progreso</option>
            </select>
        </div>
        <div class="form-group" style="margin-top:1rem;">
            <label>Objetivos del Módulo</label>
            <div id="quill_mod_objetivos" style="height:150px; background:white; border-radius:0 0 10px 10px;"></div>
            <textarea name="objetivos" id="mod_objetivos" style="display:none;"></textarea>
        </div>
        <div style="display:flex; gap:1rem; margin-top:2rem;">
            <button type="button" onclick="switchPanel('lista')" class="btn btn-outline-dark" style="flex:1;">Cancelar</button>
            <button type="submit" name="guardar_modulo" onclick="prepararModuloContenido()" class="btn btn-dark" style="flex:1; background:var(--primary);">Guardar</button>
        </div>
    </form>
</div>

<div id="panel-nueva_leccion" class="panel-admin" style="display:none; background:white; padding:2rem; border-radius:20px; box-shadow:0 20px 40px rgba(0,0,0,0.1); position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:90%; max-width:600px; z-index:1000; max-height:90vh; overflow-y:auto;">
    <h3 id="lec-titulo-txt">Gestionar Lección</h3>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="id" id="lec_id">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
            <div class="form-group"><label>Título</label><input type="text" name="titulo" id="lec_nombre" required style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;"></div>
            <div class="form-group" id="lec_modulo_field">
                <?php if ($focus_id): ?>
                    <label>Módulo del Curso Actual</label>
                    <select name="modulo_id" id="lec_modulo_id" required style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                        <?php foreach($datos_academia[0]['modulos'] as $m): ?>
                            <option value="<?= $m['id'] ?>">M<?= $m['orden'] ?>: <?= htmlspecialchars($m['titulo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <!-- Vista global: mostrar el select normal agrupado por curso -->
                    <label>Módulo</label>
                    <select name="modulo_id" id="lec_modulo_id" required style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                        <?php foreach($datos_academia as $c): ?>
                            <optgroup label="<?= htmlspecialchars($c['titulo']) ?>">
                            <?php foreach($c['modulos'] as $m): ?> <option value="<?= $m['id'] ?>">M<?= $m['orden'] ?>: <?= htmlspecialchars($m['titulo']) ?></option> <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; margin-top:1rem;">
            <div class="form-group"><label>Tipo</label>
                <select name="lec_tipo" id="lec_tipo" onchange="switchContenido(this.value)" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                    <option value="video">🎥 Video</option>
                    <option value="pdf">📄 PDF</option>
                    <option value="quiz">🧠 Quiz</option>
                    <option value="texto">📝 Texto</option>
                    <option value="hoja_calculo">📊 Hoja de Cálculo (OneDrive)</option>
                    <option value="arrastrar">🎯 Arrastrar y Soltar</option>
                    <option value="excel_interactivo">📊 Excel Interactivo</option>
                    <option value="h5p">🧩 H5P interactivo</option>
                    <option value="tarea">📤 Tarea / Entregable</option>
                    <option value="clase_vivo">🔴 Clase en Vivo (Zoom/Jitsi)</option>
                </select>
            </div>
            <div class="form-group"><label>Duración</label><input type="text" name="duracion" id="lec_duracion" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;"></div>
            <div class="form-group"><label>Orden</label><input type="number" name="orden" id="lec_orden" value="1" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;"></div>
        </div>
        <div class="form-group" style="margin-top:1rem;"><label>Estado</label>
            <select name="estado" id="lec_estado" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                <option value="publicado" selected>Publicado: visible para los estudiantes</option>
                <option value="borrador">Borrador: oculto, no cuenta para el progreso</option>
            </select>
        </div>

        <div class="form-group" style="margin-top:1rem;"><label>Mensaje Motivacional</label><textarea name="descripcion" id="lec_descripcion" placeholder="Frase para motivar al estudiante..." style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd; min-height:60px;"></textarea></div>

        <div style="background:#f8fafc; padding:1.5rem; border-radius:12px; margin-top:1.5rem; border:1px solid #e2e8f0;">
            <label style="font-weight:700; color:var(--primary); margin-bottom:1rem; display:block;"><i class="fa-solid fa-paperclip"></i> Recurso Descargable (Opcional)</label>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                <div class="form-group"><input type="text" name="recurso_titulo" id="lec_recurso_titulo" placeholder="Ej: Diapositivas de la clase" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;"></div>
                <div class="form-group"><input type="text" name="recurso_url" id="lec_recurso_url" placeholder="URL del archivo (PDF, ZIP...)" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;"></div>
            </div>
        </div>

        <!-- Campo dinámico de contenido -->
        
        <div id="tarea_config_wrapper" style="display:none; background:#f8fafc; padding:1.5rem; border-radius:12px; margin-top:1.5rem; border:1px solid #e2e8f0;">
            <label style="font-weight:700; color:var(--primary); margin-bottom:1rem; display:block;"><i class="fa-solid fa-gear"></i> Configuración de la Tarea</label>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                <div class="form-group">
                    <label>Formatos permitidos (separados por coma)</label>
                    <input type="text" name="tarea_formatos" id="lec_tarea_formatos" placeholder="Ej: pdf, docx, zip" value="pdf, docx, zip" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                </div>
                <div class="form-group">
                    <label>Días límite de entrega</label>
                    <input type="number" name="tarea_dias_limite" id="lec_tarea_dias_limite" value="7" min="1" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                </div>
                <div class="form-group">
                    <label>Intentos permitidos (0 = ilimitados)</label>
                    <input type="number" name="max_intentos" id="lec_max_intentos" value="3" min="0" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                </div>
                <div class="form-group">
                    <label>Puntaje Mínimo para Aprobar</label>
                    <input type="number" name="puntaje_minimo" id="lec_puntaje_minimo" value="<?= PASSING_GRADE ?>" min="0" max="100" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                </div>
            </div>
            <p style="color:var(--text-dim); font-size:0.85rem; margin-top:1rem;">* Las instrucciones principales de la tarea se escriben en el editor de "Contenido" de abajo.</p>
        </div>

        <div class="form-group" style="margin-top:1rem;">
            <label id="label_contenido">Contenido de la Lección</label>

            <!-- Ayuda para OneDrive -->
            <div id="ayuda_onedrive" style="display:none; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:0.8rem 1rem; margin-bottom:0.5rem; font-size:0.82rem; color:#1d4ed8;">
                <strong>📌 Cómo obtener el link de embed:</strong><br>
                En OneDrive → Abrir el archivo → <em>Archivo → Compartir → Insertar</em> → copiar la URL del <code>src="..."</code> del iframe generado.
            </div>

            <!-- Contenedor para URL (Video, PDF) o ID (Quiz) -->
            <input type="text" id="input_url" placeholder="URL del video, PDF o ID del Quiz..." style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd; display:block;">

            <!-- Contenedor para Clase en Vivo (Zoom/Jitsi) -->
            <div id="live_class_wrapper" style="display:none; background:#f8fafc; padding:1.5rem; border-radius:12px; margin-top:0.5rem; border:1px solid #e2e8f0;">
                <label style="font-weight:700; color:var(--primary); margin-bottom:1rem; display:block;"><i class="fa-solid fa-video"></i> Configuración de Clase en Vivo</label>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
                    <div class="form-group">
                        <label>Plataforma *</label>
                        <select name="plataforma_video" id="lec_plataforma_video" onchange="toggleZoomFields(this.value)" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                            <option value="">Selecciona la plataforma...</option>
                            <option value="jitsi">Jitsi Meet (Gratis / Sin límites)</option>
                            <option value="zoom">Zoom SDK (Requiere API Key)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fecha y Hora Programada *</label>
                        <input type="datetime-local" name="fecha_programada" id="lec_fecha_programada" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                    </div>
                </div>
                <div id="zoom_fields_wrapper" style="display:none; grid-template-columns:1fr 1fr; gap:1rem;">
                    <div class="form-group">
                        <label>Zoom Meeting ID * <span style="font-size:0.75rem; color:#64748b; font-weight:normal;">(Sin espacios ni guiones)</span></label>
                        <input type="text" name="zoom_meeting_id" id="lec_zoom_meeting_id" oninput="this.value = this.value.replace(/\D/g, '')" placeholder="Ej: 81234567890" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                    </div>
                    <div class="form-group">
                        <label>Zoom Passcode *</label>
                        <input type="text" name="zoom_passcode" id="lec_zoom_passcode" placeholder="Código de acceso" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                    </div>
                </div>
                <p style="color:var(--text-dim); font-size:0.85rem; margin-top:1rem;">* Jitsi genera la sala automáticamente. Zoom requiere una reunión programada.</p>
            </div>
            <!-- Contenedor para H5P -->
            <div id="h5p_wrapper" style="display:none; background:#f8fafc; padding:1.5rem; border-radius:12px; margin-top:0.5rem; border:1px solid #e2e8f0;">
                <div id="h5p_actual" style="display:none; background:white; border:1px solid #e2e8f0; border-radius:10px; padding:0.8rem 1rem; margin-bottom:1rem; font-size:0.85rem;">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem;">
                        <div><i class="fa-solid fa-puzzle-piece" style="color:var(--primary);"></i> <strong id="h5p_actual_titulo"></strong><br><small id="h5p_actual_detalle" style="color:#64748b;"></small></div>
                        <a id="h5p_actual_descargar" href="#" class="btn-circle" title="Descargar paquete .h5p"><i class="fa-solid fa-download"></i></a>
                    </div>
                </div>
                <div class="form-group">
                    <label id="h5p_file_label">Paquete .h5p</label>
                    <input type="file" name="h5p_file" id="h5p_file" accept=".h5p" onchange="h5pValidarTamano(this)" style="width:100%; padding:0.6rem; border-radius:10px; border:1px solid #ddd; background:white;">
                    <small style="color:#64748b;">Máximo <?= round(h5p_limite_subida() / 1048576) ?> MB. Expórtalo con las librerías incluidas (por ejemplo desde Lumi o H5P.org).</small>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-top:1rem;">
                    <div class="form-group">
                        <label>Para completar la lección</label>
                        <select name="h5p_modo" id="h5p_modo" onchange="h5pToggleModo()" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                            <option value="nota">Obtener la nota mínima</option>
                            <option value="completar">Solo terminar la actividad</option>
                        </select>
                    </div>
                    <div class="form-group" id="h5p_puntaje_group">
                        <label>Nota mínima (%)</label>
                        <input type="number" name="h5p_puntaje_minimo" id="h5p_puntaje_minimo" value="<?= PASSING_GRADE ?>" min="1" max="100" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #ddd;">
                    </div>
                </div>
                <p style="color:#64748b; font-size:0.8rem; margin-top:0.8rem;"><i class="fa-solid fa-circle-info"></i> La nota la calcula el navegador del estudiante: úsalo para actividades y práctica. Las evaluaciones que certifican van mejor en un Quiz.</p>
            </div>

            <!-- Contenedor para Texto Enriquecido -->
            <div id="quill_wrapper" style="display:none; margin-top: 0.5rem; background: white; border-radius: 8px; border: 1px solid #ddd; overflow: hidden;">
                <div id="quill_editor" style="height: 250px;"></div>
            </div>

            <!-- Contenedor para Arrastrar y Soltar -->
            <div id="arrastrar_wrapper" style="display:none; margin-top:0.5rem;">
                <!-- Subir imagen -->
                <div style="margin-bottom:1rem;">
                    <label style="font-weight:600; color:var(--primary); display:block; margin-bottom:0.4rem;">Imagen del Diagrama</label>
                    <div style="display:flex; gap:0.5rem; align-items:center;">
                        <label for="arr_img_file" style="flex-shrink:0; background:var(--primary); color:white; padding:0.6rem 1rem; border-radius:8px; cursor:pointer; font-size:0.82rem; white-space:nowrap;">
                            <i class="fa-solid fa-upload"></i> Subir imagen
                        </label>
                        <input type="file" id="arr_img_file" name="arr_img_file" accept="image/*" onchange="previewArrImg(this)" style="display:none;">
                        <input type="text" id="arr_imagen" placeholder="O pega una URL externa..." onchange="updateArrPreview(this.value)" style="flex:1; padding:0.7rem; border-radius:8px; border:1px solid #ddd; font-size:0.85rem;">
                    </div>
                    <div id="arr_img_preview_wrap" style="display:none; margin-top:1rem; position:relative; border: 2px solid var(--primary); border-radius:12px; overflow:hidden; background:#000;">
                        <img id="arr_img_preview" src="" style="width:100%; display:block; cursor:crosshair;" onclick="getCoords(event, this)">
                        <div style="position:absolute; top:10px; right:10px; background:rgba(0,0,0,0.8); color:white; padding:5px 12px; border-radius:20px; font-size:0.7rem; font-weight:700; pointer-events:none; border:1px solid rgba(255,255,255,0.2);">
                            <i class="fa-solid fa-mouse-pointer"></i> Haz clic para obtener X / Y
                        </div>
                        <div id="coord_indicator" style="display:none; position:absolute; width:20px; height:20px; background:var(--accent); border-radius:50%; transform:translate(-50%,-50%); border:2px solid white; box-shadow:0 0 10px rgba(0,0,0,0.5); pointer-events:none;"></div>
                    </div>
                    <div id="last_coords" style="display:none; margin-top:0.8rem; background:#f0f9ff; border:1px solid #bae6fd; padding:0.8rem; border-radius:8px; display:flex; align-items:center; gap:1rem; justify-content:center;">
                        <span style="font-size:0.85rem; color:#0369a1;">Última posición:</span>
                        <div style="display:flex; gap:0.5rem;">
                            <span style="background:white; padding:4px 10px; border-radius:5px; border:1px solid #ddd; font-family:monospace; font-weight:700; color:var(--primary);">X: <span id="val_x">--</span>%</span>
                            <span style="background:white; padding:4px 10px; border-radius:5px; border:1px solid #ddd; font-family:monospace; font-weight:700; color:var(--primary);">Y: <span id="val_y">--</span>%</span>
                        </div>
                        <small style="color:#666;">(Copia estos valores abajo)</small>
                    </div>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                    <label style="font-weight:700; color:var(--primary);">Zonas y Etiquetas</label>
                    <button type="button" onclick="agregarZona()" style="background:var(--accent);color:white;border:none;border-radius:8px;padding:0.4rem 0.9rem;cursor:pointer;font-size:0.82rem;">+ Añadir Zona</button>
                </div>
                <div style="font-size:0.75rem;color:#92400e;background:#fffbeb;padding:0.5rem 0.8rem;border-radius:6px;border-left:3px solid #f59e0b;margin-bottom:0.75rem;">
                    💡 X% = posición horizontal (0=izq, 100=der) &nbsp;|&nbsp; Y% = posición vertical (0=arriba, 100=abajo)
                </div>
                <div style="display:grid;grid-template-columns:50px 1fr 65px 65px 28px;gap:0.4rem;margin-bottom:0.3rem;font-size:0.72rem;color:#888;font-weight:700;padding:0 2px;">
                    <span>N°</span><span>Etiqueta</span><span>X %</span><span>Y %</span><span></span>
                </div>
                <div id="zonas_container"></div>
            </div>

            <!-- Excel Interactivo Builder -->
            <div id="excel_wrapper" style="display:none; margin-top:0.5rem;">
                <div style="margin-bottom:1rem;">
                    <label style="font-weight:600;color:var(--primary);display:block;margin-bottom:0.4rem;">📋 Instrucciones para el estudiante</label>
                    <textarea id="excel_instrucciones" placeholder="Ej: Calcula el total en C2 usando SUMA..." style="width:100%;padding:0.8rem;border-radius:8px;border:1px solid #ddd;min-height:55px;font-size:0.875rem;"></textarea>
                </div>
                <div style="display:flex;gap:1rem;margin-bottom:0.75rem;align-items:center;flex-wrap:wrap;">
                    <label style="font-size:0.85rem;font-weight:600;color:var(--primary);">Filas: <input type="number" id="excel_filas" value="5" min="1" max="20" onchange="renderExcelBuilder()" style="width:60px;padding:0.4rem;border-radius:6px;border:1px solid #ddd;margin-left:0.3rem;"></label>
                    <label style="font-size:0.85rem;font-weight:600;color:var(--primary);">Columnas: <input type="number" id="excel_cols" value="5" min="1" max="10" onchange="renderExcelBuilder()" style="width:60px;padding:0.4rem;border-radius:6px;border:1px solid #ddd;margin-left:0.3rem;"></label>
                    <button type="button" onclick="renderExcelBuilder()" style="background:var(--primary);color:white;border:none;border-radius:8px;padding:0.4rem 0.9rem;cursor:pointer;font-size:0.8rem;">↺ Actualizar</button>
                </div>
                <div style="display:flex;gap:1rem;margin-bottom:0.5rem;font-size:0.72rem;flex-wrap:wrap;">
                    <span><span style="display:inline-block;width:12px;height:12px;background:#fff;border:1px solid #cbd5e1;border-radius:2px;"></span> Vacía</span>
                    <span><span style="display:inline-block;width:12px;height:12px;background:#dbeafe;border:2px solid #3b82f6;border-radius:2px;"></span> Fija (bloqueada)</span>
                    <span><span style="display:inline-block;width:12px;height:12px;background:#dcfce7;border:2px solid #22c55e;border-radius:2px;"></span> Evaluable (alumno llena)</span>
                </div>
                <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px;background:white;">
                    <div id="excel_grid_builder" style="padding:0.4rem;"></div>
                </div>
                <p style="font-size:0.72rem;color:#94a3b8;margin-top:0.4rem;"><i class="fa-solid fa-hand-pointer"></i> Haz clic en una celda para configurarla.</p>
                <!-- Panel config celda -->
                <div id="excel_cell_config" style="display:none;margin-top:0.75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:1rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
                        <strong style="font-size:0.9rem;color:var(--primary);">⚙️ Celda <span id="excel_cell_ref" style="color:var(--accent);"></span></strong>
                        <button type="button" onclick="cerrarCeldaConfig()" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:1rem;">✕</button>
                    </div>
                    <div style="display:flex;gap:0.5rem;margin-bottom:0.75rem;flex-wrap:wrap;">
                        <button type="button" id="tipo_btn_vacia" onclick="setCellType('vacia')" style="padding:0.4rem 0.8rem;border-radius:8px;border:2px solid #e2e8f0;font-size:0.8rem;font-weight:600;cursor:pointer;background:white;">🔲 Vacía</button>
                        <button type="button" id="tipo_btn_fija" onclick="setCellType('fija')" style="padding:0.4rem 0.8rem;border-radius:8px;border:2px solid #e2e8f0;font-size:0.8rem;font-weight:600;cursor:pointer;background:white;">🔒 Fija</button>
                        <button type="button" id="tipo_btn_evaluable" onclick="setCellType('evaluable')" style="padding:0.4rem 0.8rem;border-radius:8px;border:2px solid #e2e8f0;font-size:0.8rem;font-weight:600;cursor:pointer;background:white;">✅ Evaluable</button>
                    </div>
                    <div id="config_fija" style="display:none;">
                        <label style="font-size:0.8rem;font-weight:600;color:#475569;">Valor de la celda</label>
                        <input type="text" id="cell_valor_fijo" placeholder="Ej: Ventas, 1500, Total..." style="width:100%;padding:0.5rem;border-radius:7px;border:1px solid #ddd;margin-top:0.25rem;font-size:0.85rem;">
                    </div>
                    <div id="config_evaluable" style="display:none;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.6rem;">
                            <div><label style="font-size:0.8rem;font-weight:600;color:#475569;">Valor esperado *</label><input type="text" id="cell_valor_esperado" placeholder="Ej: 1500" style="width:100%;padding:0.5rem;border-radius:7px;border:1px solid #ddd;margin-top:0.2rem;font-size:0.85rem;"></div>
                            <div><label style="font-size:0.8rem;font-weight:600;color:#475569;">Puntaje (pts)</label><input type="number" id="cell_puntaje" value="10" min="1" max="100" style="width:100%;padding:0.5rem;border-radius:7px;border:1px solid #ddd;margin-top:0.2rem;font-size:0.85rem;"></div>
                            <div><label style="font-size:0.8rem;font-weight:600;color:#475569;">Fórmula esperada (opcional)</label><input type="text" id="cell_formula_esperada" placeholder="=SUMA(A1:A5)" style="width:100%;padding:0.5rem;border-radius:7px;border:1px solid #ddd;margin-top:0.2rem;font-size:0.85rem;font-family:monospace;"></div>
                            <div><label style="font-size:0.8rem;font-weight:600;color:#475569;">Pista para el alumno</label><input type="text" id="cell_pista" placeholder="Usa la función SUMA" style="width:100%;padding:0.5rem;border-radius:7px;border:1px solid #ddd;margin-top:0.2rem;font-size:0.85rem;"></div>
                        </div>
                        <label style="display:flex;align-items:center;gap:0.5rem;margin-top:0.6rem;font-size:0.8rem;font-weight:600;color:#475569;cursor:pointer;">
                            <input type="checkbox" id="cell_acepta_equiv" checked> Solo verificar resultado (acepta fórmulas equivalentes)
                        </label>
                    </div>
                    <button type="button" onclick="guardarCeldaConfig()" style="margin-top:0.75rem;background:var(--accent);color:white;border:none;border-radius:8px;padding:0.5rem 1rem;cursor:pointer;font-weight:600;font-size:0.82rem;">💾 Guardar celda</button>
                </div>
            </div>

            <input type="hidden" name="contenido" id="contenido_final">
        </div>
        <div style="display:flex; gap:1rem; margin-top:2rem;">
            <button type="button" onclick="switchPanel('lista')" class="btn btn-outline-dark" style="flex:1;">Cancelar</button>
            <button type="submit" name="guardar_leccion" onclick="prepararContenido()" class="btn btn-dark" style="flex:1; background:var(--primary);">Guardar</button>
        </div>
    </form>
</div>

<script>
// Lógica de Acordeones Inteligentes (Solo uno abierto a la vez)
function toggleBranch(type, id) {
    const container = document.getElementById(`container-${type}-${id}`);
    const chevron = document.getElementById(`chevron-${type}-${id}`);
    const isVisible = container.style.display === 'block';

    if (type === 'course') {
        // Cerrar otros cursos si no estamos en focus mode
        if (!window.location.search.includes('focus=')) {
            document.querySelectorAll('.modules-container').forEach(el => el.style.display = 'none');
            document.querySelectorAll('[id^="chevron-course-"]').forEach(el => el.style.transform = 'rotate(0deg)');
        }
    } else {
        // Cerrar otros módulos dentro del mismo curso
        const parent = container.closest('.modules-container');
        parent.querySelectorAll('.lessons-list-pro').forEach(el => { if(el !== container) el.style.display = 'none'; });
        parent.querySelectorAll('[id^="chevron-module-"]').forEach(el => { if(el !== chevron) el.style.transform = 'rotate(0deg)'; });
    }

    container.style.display = isVisible ? 'none' : 'block';
    chevron.style.transform = isVisible ? 'rotate(0deg)' : (type === 'course' ? 'rotate(180deg)' : 'rotate(90deg)');
}

function filterCourses() {
    const query = document.getElementById('courseSearch').value.toLowerCase();
    document.querySelectorAll('.course-builder-card').forEach(card => {
        const text = card.getAttribute('data-search') + card.innerText.toLowerCase();
        card.style.display = text.includes(query) ? 'block' : 'none';
    });
}

function switchPanel(p) {
    document.querySelectorAll('.panel-admin').forEach(el => el.style.display = 'none');
    if(p !== 'lista') document.getElementById('panel-'+p).style.display = 'block';
}

let quillEditor;
let quillEditorMod;

document.addEventListener('DOMContentLoaded', function() {
    quillEditor = new Quill('#quill_editor', {
        theme: 'snow',
        placeholder: 'Escribe el contenido enriquecido aquí...',
        modules: {
            toolbar: [
                [{ 'header': [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                ['link', 'blockquote', 'code-block'],
                ['clean']
            ]
        }
    });

    quillEditorMod = new Quill('#quill_mod_objetivos', {
        theme: 'snow',
        placeholder: '¿Qué aprenderá el estudiante en este módulo?',
        modules: {
            toolbar: [
                [{ 'header': [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                ['link', 'clean']
            ]
        }
    });

    document.querySelectorAll('.modules-sortable').forEach(container => {
        new Sortable(container, { animation:150, handle:'.fa-grip-vertical', onEnd: function(){ updateOrder('modulo', Array.from(container.children).map(el=>el.dataset.id)); } });
    });
    document.querySelectorAll('.lessons-sortable').forEach(container => {
        new Sortable(container, { animation:150, handle:'.fa-grip-vertical', onEnd: function(){ updateOrder('leccion', Array.from(container.children).map(el=>el.dataset.id)); } });
    });
});

function switchContenido(t) {
    const inputUrl = document.getElementById('input_url');
    const quillWrapper = document.getElementById('quill_wrapper');
    const label = document.getElementById('label_contenido');

    if (t === 'texto') {
        inputUrl.style.display = 'none';
        quillWrapper.style.display = 'block';
        label.innerText = 'Contenido (Texto Enriquecido)';
    } else if (t === 'video') {
        inputUrl.style.display = 'block';
        quillWrapper.style.display = 'none';
        inputUrl.placeholder = 'URL del Video (YouTube, Vimeo, MP4...)';
        label.innerText = 'Enlace del Video';
    } else if (t === 'pdf') {
        inputUrl.style.display = 'block';
        quillWrapper.style.display = 'none';
        inputUrl.placeholder = 'URL del archivo PDF...';
        label.innerText = 'Enlace del PDF';
    } else if (t === 'quiz') {
        inputUrl.style.display = 'block';
        quillWrapper.style.display = 'none';
        inputUrl.placeholder = 'ID del Quiz a enlazar...';
        label.innerText = 'ID del Quiz';
    } else if (t === 'hoja_calculo') {
        inputUrl.style.display = 'block';
        quillWrapper.style.display = 'none';
        inputUrl.placeholder = 'https://onedrive.live.com/embed?resid=...&authkey=...&em=2';
        label.innerText = 'URL de Embed de OneDrive';
    } else if (t === 'arrastrar') {
        inputUrl.style.display = 'none';
        quillWrapper.style.display = 'none';
        document.getElementById('arrastrar_wrapper').style.display = 'block';
        label.innerText = 'Ejercicio de Arrastrar y Soltar';
    } else if (t === 'excel_interactivo') {
        inputUrl.style.display = 'none';
        quillWrapper.style.display = 'none';
        document.getElementById('excel_wrapper').style.display = 'block';
        label.innerText = 'Excel Interactivo';
        renderExcelBuilder();
    } else if (t === 'clase_vivo') {
        inputUrl.style.display = 'none';
        quillWrapper.style.display = 'none';
        document.getElementById('live_class_wrapper').style.display = 'block';
        label.innerText = 'Clase en Vivo';
    } else if (t === 'tarea') {
        inputUrl.style.display = 'none';
        quillWrapper.style.display = 'block';
        document.getElementById('tarea_config_wrapper').style.display = 'block';
        label.innerText = 'Instrucciones de la Tarea';
    }
    // Mostrar/ocultar ayuda OneDrive
    const ayuda = document.getElementById('ayuda_onedrive');
    if (ayuda) ayuda.style.display = (t === 'hoja_calculo') ? 'block' : 'none';
    // Mostrar/ocultar panel arrastrar
    const arrW = document.getElementById('arrastrar_wrapper');
    if (arrW && t !== 'arrastrar') arrW.style.display = 'none';
    // Mostrar/ocultar panel excel
    // Mostrar/ocultar panel H5P
    const h5pW = document.getElementById('h5p_wrapper');
    if (h5pW) h5pW.style.display = (t === 'h5p') ? 'block' : 'none';
    if (t === 'h5p') {
        inputUrl.style.display = 'none';
        quillWrapper.style.display = 'none';
        label.innerText = 'Actividad H5P';
    }
    const excelW = document.getElementById('excel_wrapper');
    if (excelW && t !== 'excel_interactivo') excelW.style.display = 'none';
    // Mostrar/ocultar panel clase vivo
    const liveW = document.getElementById('live_class_wrapper');
    if (liveW && t !== 'clase_vivo') liveW.style.display = 'none';
    // Mostrar/ocultar panel tarea
    const tareaW = document.getElementById('tarea_config_wrapper');
    if (tareaW && t !== 'tarea') tareaW.style.display = 'none';
}

function toggleZoomFields(val) {
    const zoomFields = document.getElementById('zoom_fields_wrapper');
    if (val === 'zoom') {
        zoomFields.style.display = 'grid';
    } else {
        zoomFields.style.display = 'none';
    }
}

function prepararModuloContenido() {
    document.getElementById('mod_objetivos').value = quillEditorMod.root.innerHTML;
}

function editarModulo(m) {
    switchPanel('nuevo_modulo');
    document.getElementById('mod_id').value = m.id;
    document.getElementById('mod_curso_id').value = m.curso_id;
    document.getElementById('mod_orden').value = m.orden;
    document.getElementById('mod_nombre').value = m.titulo;
    document.getElementById('mod_estado').value = m.estado === 'publicado' ? 'publicado' : 'borrador';
    quillEditorMod.root.innerHTML = m.objetivos || '';
}

function resetModulo() {
    // Limpia todo el formulario de módulo con el método nativo del navegador
    const formMod = document.querySelector('#panel-nuevo_modulo form');
    if (formMod) formMod.reset();
    // Asegurar que el ID oculto quede vacío (no lo resetea form.reset())
    document.getElementById('mod_id').value = '';
    document.getElementById('mod_orden').value = '0';
    quillEditorMod.root.innerHTML = '';
}
function editarLeccion(l) {
    switchPanel('nueva_leccion');
    document.getElementById('lec_id').value = l.id;
    document.getElementById('lec_nombre').value = l.titulo;
    document.getElementById('lec_modulo_id').value = l.modulo_id;
    document.getElementById('lec_tipo').value = l.tipo;
    document.getElementById('lec_duracion').value = l.duracion;
    document.getElementById('lec_orden').value = l.orden;
    document.getElementById('lec_estado').value = l.estado === 'publicado' ? 'publicado' : 'borrador';
    document.getElementById('lec_descripcion').value = l.descripcion || '';
    document.getElementById('lec_recurso_titulo').value = l.recurso_titulo || '';
    document.getElementById('lec_recurso_url').value = l.recurso_url || '';
    
    document.getElementById('lec_tarea_formatos').value = l.tarea_formatos || 'pdf, docx, zip';
    document.getElementById('lec_tarea_dias_limite').value = l.tarea_dias_limite || '7';
    document.getElementById('lec_puntaje_minimo').value = l.puntaje_minimo || '<?= PASSING_GRADE ?>';
    document.getElementById('lec_max_intentos').value = l.max_intentos || '3';

    // Clase en vivo
    if (document.getElementById('lec_plataforma_video')) {
        document.getElementById('lec_plataforma_video').value = l.plataforma_video || '';
        document.getElementById('lec_zoom_meeting_id').value = l.zoom_meeting_id || '';
        document.getElementById('lec_zoom_passcode').value = l.zoom_passcode || '';
        document.getElementById('lec_fecha_programada').value = (l.fecha_programada ? l.fecha_programada.replace(' ', 'T').slice(0, 16) : '');
        toggleZoomFields(l.plataforma_video || '');
    }

    switchContenido(l.tipo);
    if (l.tipo === 'texto') {
        quillEditor.root.innerHTML = l.contenido;
        document.getElementById('input_url').value = '';
    } else if (l.tipo === 'arrastrar') {
        try {
            const data = JSON.parse(l.contenido || '{}');
            document.getElementById('arr_imagen').value = data.imagen || '';
            const wrap = document.getElementById('arr_img_preview_wrap');
            const prev = document.getElementById('arr_img_preview');
            if (data.imagen) { prev.src = data.imagen; wrap.style.display = 'inline-block'; }
            else { wrap.style.display = 'none'; }
            document.getElementById('zonas_container').innerHTML = '';
            (data.zonas || []).forEach(z => agregarZona(z.numero, z.texto, z.x, z.y));
        } catch(e) {}
    } else if (l.tipo === 'h5p') {
        h5pMostrarPaquete(l);
    } else if (l.tipo === 'excel_interactivo') {
        try {
            const data = JSON.parse(l.contenido || '{}');
            document.getElementById('excel_instrucciones').value = data.instrucciones || '';
            document.getElementById('excel_filas').value = data.filas || 5;
            document.getElementById('excel_cols').value = data.columnas || 5;
            excelCeldas = { fijas: data.celdas_fijas || {}, evaluables: data.celdas_evaluables || {} };
            renderExcelBuilder();
        } catch(e) {}
    } else {
        document.getElementById('input_url').value = l.contenido;
        quillEditor.root.innerHTML = '';
    }
    // Cambio D: actualizar badge del módulo si estamos en Focus Mode
    updateModuloBadge(l.modulo_id);
}

function updateModuloBadge(moduloId) {
    // Si estamos en Focus Mode, la lista es visible, solo asignamos el valor
    const sel = document.getElementById('lec_modulo_id');
    if (sel) {
        sel.value = moduloId;
    }
}

function resetLeccion() {
    switchPanel('nueva_leccion');
    document.getElementById('lec_id').value = '';
    document.getElementById('lec_nombre').value = '';
    document.getElementById('lec_duracion').value = '';
    document.getElementById('lec_estado').value = 'publicado';
    document.getElementById('lec_descripcion').value = '';
    document.getElementById('lec_recurso_titulo').value = '';
    document.getElementById('lec_recurso_url').value = '';
    document.getElementById('input_url').value = '';
    quillEditor.root.innerHTML = '';
    h5pMostrarPaquete(null);
    switchContenido(document.getElementById('lec_tipo').value);
    
    // Seleccionar el primer módulo por defecto si lo hay
    const sel = document.getElementById('lec_modulo_id');
    if (sel && sel.options.length > 0) {
        updateModuloBadge(sel.options[0].value);
    }
}

function prepararContenido() {
    const t = document.getElementById('lec_tipo').value;
    if (t === 'texto') {
        document.getElementById('contenido_final').value = quillEditor.root.innerHTML;
    } else if (t === 'arrastrar') {
        const imagen = document.getElementById('arr_imagen').value.trim();
        const zonas = [];
        document.querySelectorAll('.zona-row').forEach((row, idx) => {
            const num = row.querySelector('.zona-num').value;
            const texto = row.querySelector('.zona-texto').value.trim();
            const x = parseFloat(row.querySelector('.zona-x').value) || 0;
            const y = parseFloat(row.querySelector('.zona-y').value) || 0;
            if (texto) zonas.push({ id: 'z' + idx, numero: parseInt(num) || (idx+1), texto, x, y });
        });
        document.getElementById('contenido_final').value = JSON.stringify({ imagen, zonas });
    } else if (t === 'h5p') {
        document.getElementById('contenido_final').value = '{}';
    } else if (t === 'excel_interactivo') {
        document.getElementById('contenido_final').value = JSON.stringify({
            instrucciones: document.getElementById('excel_instrucciones').value.trim(),
            filas: parseInt(document.getElementById('excel_filas').value) || 5,
            columnas: parseInt(document.getElementById('excel_cols').value) || 5,
            celdas_fijas: excelCeldas.fijas || {},
            celdas_evaluables: excelCeldas.evaluables || {}
        });
    } else {
        document.getElementById('contenido_final').value = document.getElementById('input_url').value;
    }
}

// --- H5P ---
const H5P_LIMITE_BYTES = <?= h5p_limite_subida() ?>;
function h5pValidarTamano(input) {
    if (input.files[0] && input.files[0].size > H5P_LIMITE_BYTES) {
        alert('El paquete supera el máximo de ' + Math.round(H5P_LIMITE_BYTES / 1048576) + ' MB.');
        input.value = '';
    }
}
function h5pToggleModo() {
    document.getElementById('h5p_puntaje_group').style.display = document.getElementById('h5p_modo').value === 'completar' ? 'none' : 'block';
}
// Datos del paquete instalado (lecciones.contenido) o formulario vacío para una lección nueva
function h5pMostrarPaquete(l) {
    let d = {};
    if (l && l.tipo === 'h5p') { try { d = JSON.parse(l.contenido || '{}') || {}; } catch (e) {} }
    document.getElementById('h5p_file').value = '';
    document.getElementById('h5p_modo').value = d.modo === 'completar' ? 'completar' : 'nota';
    document.getElementById('h5p_puntaje_minimo').value = (l && l.tipo === 'h5p' && l.puntaje_minimo) ? l.puntaje_minimo : <?= PASSING_GRADE ?>;
    const caja = document.getElementById('h5p_actual');
    if (d.carpeta) {
        document.getElementById('h5p_actual_titulo').textContent = d.titulo || 'Paquete H5P';
        document.getElementById('h5p_actual_detalle').textContent = [d.libreria, d.bytes ? (d.bytes / 1048576).toFixed(1) + ' MB' : '', d.subido_at ? 'subido ' + d.subido_at : ''].filter(Boolean).join(' · ');
        document.getElementById('h5p_actual_descargar').href = 'h5p_descargar?leccion=' + encodeURIComponent(l.id);
        document.getElementById('h5p_file_label').textContent = 'Reemplazar paquete .h5p (opcional)';
        caja.style.display = 'block';
    } else {
        document.getElementById('h5p_file_label').textContent = 'Paquete .h5p';
        caja.style.display = 'none';
    }
    h5pToggleModo();
}

function previewArrImg(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('arr_img_preview').src = e.target.result;
            document.getElementById('arr_img_preview_wrap').style.display = 'block';
            document.getElementById('arr_imagen').value = ''; // Limpiar URL si se sube archivo
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function updateArrPreview(url) {
    if (url.trim()) {
        document.getElementById('arr_img_preview').src = url;
        document.getElementById('arr_img_preview_wrap').style.display = 'block';
    }
}

function getCoords(e, img) {
    const rect = img.getBoundingClientRect();
    const x = ((e.clientX - rect.left) / rect.width * 100).toFixed(1);
    const y = ((e.clientY - rect.top) / rect.height * 100).toFixed(1);

    document.getElementById('val_x').innerText = x;
    document.getElementById('val_y').innerText = y;
    document.getElementById('last_coords').style.display = 'flex';

    const indicator = document.getElementById('coord_indicator');
    indicator.style.display = 'block';
    indicator.style.left = x + '%';
    indicator.style.top = y + '%';
}

let zonaCount = 0;
function agregarZona(num='', texto='', x='', y='') {
    zonaCount++;
    const row = document.createElement('div');
    row.className = 'zona-row';
    row.style = 'display:grid;grid-template-columns:50px 1fr 65px 65px 28px;gap:0.4rem;margin-bottom:0.4rem;align-items:center;';
    row.innerHTML = `
        <input type='number' class='zona-num' placeholder='N°' value='${num}' min='1' style='padding:0.5rem;border:1px solid #ddd;border-radius:6px;width:100%;text-align:center;'>
        <input type='text' class='zona-texto' placeholder='Ej: Barra de título' value='${texto}' style='padding:0.5rem;border:1px solid #ddd;border-radius:6px;width:100%;'>
        <input type='number' class='zona-x' placeholder='X%' value='${x}' min='0' max='100' step='0.5' style='padding:0.5rem;border:1px solid #ddd;border-radius:6px;width:100%;'>
        <input type='number' class='zona-y' placeholder='Y%' value='${y}' min='0' max='100' step='0.5' style='padding:0.5rem;border:1px solid #ddd;border-radius:6px;width:100%;'>
        <button type='button' onclick='this.parentElement.remove()' style='background:#fee2e2;color:#dc2626;border:none;border-radius:6px;width:28px;height:28px;cursor:pointer;font-weight:bold;'>✕</button>
    `;
    document.getElementById('zonas_container').appendChild(row);
}

function updateOrder(type, ids) {
    const fd = new FormData(); fd.append('action', 'reorder'); fd.append('csrf_token', '<?= $_SESSION["csrf_token"] ?>'); fd.append('type', type); ids.forEach(id => fd.append('ids[]', id));
    fetch('gestionar_curso.php', { method:'POST', body:fd });
}

// ========== EXCEL INTERACTIVO BUILDER ==========
let excelCeldas = { fijas: {}, evaluables: {} };
let excelCeldaActiva = null;
const COL_LETTERS = ['A','B','C','D','E','F','G','H','I','J'];

function renderExcelBuilder() {
    const filas = parseInt(document.getElementById('excel_filas').value) || 5;
    const cols  = parseInt(document.getElementById('excel_cols').value) || 5;
    const grid  = document.getElementById('excel_grid_builder');
    if (!grid) return;
    let html = '<table style="border-collapse:collapse;width:100%;min-width:350px;">';
    html += '<tr><th style="width:32px;background:#f1f5f9;border:1px solid #e2e8f0;font-size:0.68rem;color:#94a3b8;"></th>';
    for (let c = 0; c < cols; c++) {
        html += `<th style="background:#f1f5f9;padding:5px 10px;border:1px solid #e2e8f0;font-size:0.7rem;color:#64748b;font-weight:700;text-align:center;">${COL_LETTERS[c]}</th>`;
    }
    html += '</tr>';
    for (let r = 1; r <= filas; r++) {
        html += `<tr><td style="background:#f1f5f9;padding:4px;border:1px solid #e2e8f0;font-size:0.7rem;color:#94a3b8;text-align:center;width:32px;">${r}</td>`;
        for (let c = 0; c < cols; c++) {
            const ref = COL_LETTERS[c] + r;
            const esFija = excelCeldas.fijas[ref];
            const esEval = excelCeldas.evaluables[ref];
            let bg = '#fff', border = '1px solid #e2e8f0', inner = '';
            if (esFija) { bg = '#dbeafe'; border = '2px solid #3b82f6'; inner = `<span style="font-size:0.68rem;color:#1d4ed8;font-weight:600;">${esFija.valor}</span>`; }
            else if (esEval) { bg = '#dcfce7'; border = '2px solid #22c55e'; inner = `<span style="font-size:0.65rem;color:#166534;">✏️${esEval.valor_esperado}</span>`; }
            html += `<td onclick="configurarCelda('${ref}')" style="background:${bg};border:${border};padding:4px 6px;cursor:pointer;min-width:72px;height:28px;text-align:center;transition:0.1s;" title="${ref}">${inner}</td>`;
        }
        html += '</tr>';
    }
    html += '</table>';
    grid.innerHTML = html;
    document.getElementById('excel_cell_config').style.display = 'none';
    excelCeldaActiva = null;
}

function configurarCelda(ref) {
    excelCeldaActiva = ref;
    document.getElementById('excel_cell_ref').textContent = ref;
    document.getElementById('cell_valor_fijo').value = '';
    document.getElementById('cell_valor_esperado').value = '';
    document.getElementById('cell_puntaje').value = 10;
    document.getElementById('cell_formula_esperada').value = '';
    document.getElementById('cell_pista').value = '';
    document.getElementById('cell_acepta_equiv').checked = true;
    const esFija = excelCeldas.fijas[ref];
    const esEval = excelCeldas.evaluables[ref];
    if (esFija) {
        setCellType('fija');
        document.getElementById('cell_valor_fijo').value = esFija.valor || '';
    } else if (esEval) {
        setCellType('evaluable');
        document.getElementById('cell_valor_esperado').value = esEval.valor_esperado !== undefined ? esEval.valor_esperado : '';
        document.getElementById('cell_puntaje').value = esEval.puntaje || 10;
        document.getElementById('cell_formula_esperada').value = esEval.formula_esperada || '';
        document.getElementById('cell_pista').value = esEval.pista || '';
        document.getElementById('cell_acepta_equiv').checked = esEval.acepta_equivalentes !== false;
    } else {
        setCellType('vacia');
    }
    document.getElementById('excel_cell_config').style.display = 'block';
}

function setCellType(tipo) {
    document.getElementById('config_fija').style.display     = tipo === 'fija'      ? 'block' : 'none';
    document.getElementById('config_evaluable').style.display = tipo === 'evaluable' ? 'block' : 'none';
    ['vacia','fija','evaluable'].forEach(t => {
        const btn = document.getElementById('tipo_btn_' + t);
        if (!btn) return;
        btn.style.borderColor = t === tipo ? 'var(--accent)' : '#e2e8f0';
        btn.style.background  = t === tipo ? '#fff7ed'        : 'white';
    });
    document.getElementById('excel_cell_config').dataset.tipo = tipo;
}

function guardarCeldaConfig() {
    if (!excelCeldaActiva) return;
    const tipo = document.getElementById('excel_cell_config').dataset.tipo || 'vacia';
    const ref  = excelCeldaActiva;
    delete excelCeldas.fijas[ref];
    delete excelCeldas.evaluables[ref];
    if (tipo === 'fija') {
        excelCeldas.fijas[ref] = { valor: document.getElementById('cell_valor_fijo').value.trim() };
    } else if (tipo === 'evaluable') {
        const vEsperado = document.getElementById('cell_valor_esperado').value.trim();
        if (!vEsperado) { alert('El valor esperado es requerido.'); return; }
        excelCeldas.evaluables[ref] = {
            valor_esperado:      isNaN(vEsperado) ? vEsperado : parseFloat(vEsperado),
            puntaje:             parseInt(document.getElementById('cell_puntaje').value) || 10,
            formula_esperada:    document.getElementById('cell_formula_esperada').value.trim(),
            pista:               document.getElementById('cell_pista').value.trim(),
            acepta_equivalentes: document.getElementById('cell_acepta_equiv').checked
        };
    }
    renderExcelBuilder();
    configurarCelda(ref);
}

function cerrarCeldaConfig() {
    document.getElementById('excel_cell_config').style.display = 'none';
    excelCeldaActiva = null;
}
</script>

<?php require_once 'admin_footer.php'; ?>
