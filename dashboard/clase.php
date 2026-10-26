<?php
require_once '../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$roles_permitidos = ['estudiante', 'administrativo'];
// Permitir a docentes y admins acceder como anfitriones de clases en vivo
if (isset($_GET['as_host']) && $_GET['as_host'] == '1') {
    $roles_permitidos = array_merge($roles_permitidos, ['docente', 'admin']);
}
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], $roles_permitidos)) {
    header('Location: ../login'); exit;
}
$usuario_id = $_SESSION['user_id'];
$curso_id   = intval($_GET['curso'] ?? 0);
$leccion_id = intval($_GET['leccion'] ?? 0);

require_once '../helpers/acceso_helper.php';

// Verificar vigencia individual y bloqueo secuencial (solo para estudiantes, no para anfitriones)
$is_host_access = isset($_GET['as_host']) && $_GET['as_host'] == '1' && in_array($_SESSION['user_rol'], ['docente', 'admin']);
if (!$is_host_access) {
    $estado_acceso = obtenerEstadoAccesoCurso($usuario_id, $curso_id, $pdo);
    if (!$estado_acceso['active']) {
        $msg = $estado_acceso['status'] === 'vencido' ? 'acceso_vencido' : 'sin_pago';
        header("Location: index?msg={$msg}");
        exit; 
    }

    $curso_faltante_titulo = null;
    if (!verificarBloqueoSecuencial($usuario_id, $curso_id, $pdo, $curso_faltante_titulo)) {
        header("Location: index?msg=bloqueo_secuencial&curso=" . urlencode($curso_faltante_titulo));
        exit;
    }
}

require_once '../helpers/csrf_helper.php';
require_once '../helpers/html_sanitizer.php';
require_once '../helpers/imagen_helper.php';
require_once '../helpers/h5p_helper.php';

// Las acciones AJAX de esta página exigen token CSRF y una lección que pertenezca a este curso
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_valido(csrf_token_de_peticion())) {
        http_response_code(403);
        echo "csrf_invalido"; exit;
    }
    $stmt_lp = $pdo->prepare("SELECT l.tipo, l.contenido, l.puntaje_minimo FROM lecciones l JOIN modulos m ON l.modulo_id = m.id WHERE l.id = ? AND m.curso_id = ? AND l.estado = 'publicado' AND m.estado = 'publicado'");
    $stmt_lp->execute([intval($_POST['leccion_id'] ?? 0), $curso_id]);
    $leccion_post = $stmt_lp->fetch(PDO::FETCH_ASSOC);
    if ($leccion_post === false) {
        http_response_code(403);
        echo "leccion_invalida"; exit;
    }
    $tipo_leccion_post = $leccion_post['tipo'];
}

// El contenido se completa al verlo; las actividades (quiz, tarea, arrastrar, excel) solo al
// aprobarlas: el quiz en QuizCierre, la tarea cuando el docente la aprueba y los ejercicios
// al calificarlos aquí mismo en el servidor. Un tipo nuevo queda bloqueado por defecto.
const TIPOS_LECCION_CONTENIDO = ['video', 'texto', 'pdf', 'hoja_calculo'];
// La clase en vivo (Zoom/Jitsi) la marca el estudiante al asistir, con el botón de su vista
const TIPOS_LECCION_MARCABLES = ['video', 'texto', 'pdf', 'hoja_calculo', 'clase_vivo'];

/** Porcentaje mínimo para aprobar un ejercicio (lecciones.puntaje_minimo; si no está definido, el de Configuración). */
function puntaje_minimo_leccion(array $leccion): int {
    $minimo = (int)($leccion['puntaje_minimo'] ?? 0);
    return ($minimo > 0 && $minimo <= 100) ? $minimo : PASSING_GRADE;
}

function responder_json(array $datos): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($datos);
    exit;
}

// Procesar progreso (Petición AJAX)
if (isset($_POST['marcar_completada']) && isset($_POST['leccion_id'])) {
    if (!in_array($tipo_leccion_post, TIPOS_LECCION_MARCABLES, true)) {
        http_response_code(403);
        echo "actividad_requiere_aprobacion"; exit;
    }
    $lid = intval($_POST['leccion_id']);
    $stmt = $pdo->prepare("INSERT INTO progreso (usuario_id, leccion_id, completado) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE completado = 1");
    $stmt->execute([$usuario_id, $lid]);
    echo "ok"; exit;
}

// Guardar Apuntes (Petición AJAX)
if (isset($_POST['guardar_apuntes'])) {
    $lid = intval($_POST['leccion_id']);
    $txt = trim($_POST['contenido']);
    $stmt = $pdo->prepare("INSERT INTO apuntes (usuario_id, leccion_id, contenido) VALUES (?, ?, ?) 
                           ON DUPLICATE KEY UPDATE contenido = VALUES(contenido)");
    $stmt->execute([$usuario_id, $lid, $txt]);
    echo "guardado"; exit;
}

// Enviar Comentario (Petición AJAX)
if (isset($_POST['enviar_comentario']) && isset($_POST['leccion_id']) && !empty(trim($_POST['comentario']))) {
    $lid = intval($_POST['leccion_id']);
    $txt = trim($_POST['comentario']);
    $stmt = $pdo->prepare("INSERT INTO comentarios_leccion (leccion_id, usuario_id, comentario) VALUES (?, ?, ?)");
    $stmt->execute([$lid, $usuario_id, $txt]);
    echo "ok"; exit;
}

// Calificar ejercicio de arrastrar (Petición AJAX). El navegador solo envía qué etiqueta
// puso en cada zona; las respuestas correctas nunca salen del servidor.
if (isset($_POST['verificar_arrastrar'])) {
    if ($tipo_leccion_post !== 'arrastrar') {
        http_response_code(403);
        responder_json(['ok' => false, 'error' => 'leccion_invalida']);
    }
    $lid = intval($_POST['leccion_id']);
    $zonas = json_decode($leccion_post['contenido'] ?? '{}', true)['zonas'] ?? [];
    $colocaciones = json_decode((string)($_POST['colocaciones'] ?? ''), true);
    $colocaciones = is_array($colocaciones) ? $colocaciones : [];

    $correctas = 0;
    $resultado_zonas = [];
    foreach ($zonas as $z) {
        $id_zona = (string)($z['id'] ?? '');
        $puesta = $colocaciones[$id_zona] ?? null;
        $ok = is_string($puesta) && trim($puesta) === trim((string)($z['texto'] ?? ''));
        $resultado_zonas[$id_zona] = $ok;
        if ($ok) $correctas++;
    }
    $total = count($zonas);
    $pct = $total > 0 ? (int)round($correctas / $total * 100) : 0;
    $minimo = puntaje_minimo_leccion($leccion_post);
    $aprobado = $total > 0 && $pct >= $minimo;
    if ($aprobado) {
        $pdo->prepare("INSERT INTO progreso (usuario_id, leccion_id, completado) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE completado = 1")->execute([$usuario_id, $lid]);
    }
    responder_json(['ok' => true, 'pct' => $pct, 'correctas' => $correctas, 'total' => $total,
                    'minimo' => $minimo, 'aprobado' => $aprobado, 'zonas' => $resultado_zonas]);
}

// Calificar y guardar ejercicio de Excel (Petición AJAX). El navegador calcula cada celda con
// HyperFormula y envía lo escrito y el valor resultante; la comparación con lo esperado y el
// puntaje se hacen aquí. Basta con el resultado correcto, salvo que la celda exija la fórmula.
if (isset($_POST['guardar_resultado_excel'])) {
    if ($tipo_leccion_post !== 'excel_interactivo') {
        http_response_code(403);
        responder_json(['ok' => false, 'error' => 'leccion_invalida']);
    }
    $lid = intval($_POST['leccion_id']);
    $evaluables = json_decode($leccion_post['contenido'] ?? '{}', true)['celdas_evaluables'] ?? [];
    $celdas = json_decode((string)($_POST['celdas'] ?? ''), true);
    $celdas = is_array($celdas) ? $celdas : [];

    $respuestas = [];
    $resultados = [];
    $correctas = 0;
    $total_pts = 0;
    $obtenido_pts = 0;
    foreach ($evaluables as $ref => $cfg) {
        $entrada = trim((string)($celdas[$ref]['entrada'] ?? ''));
        $valor = $celdas[$ref]['valor'] ?? '';
        $valor = is_bool($valor) ? ($valor ? 'true' : 'false') : (is_scalar($valor) ? trim((string)$valor) : '');
        $esperado = trim((string)($cfg['valor_esperado'] ?? ''));
        $pts = (float)($cfg['puntaje'] ?? 0) ?: 10;
        // Las celdas vacías también cuentan en el total: dejarlas en blanco no sube la nota
        $total_pts += $pts;
        $respuestas[$ref] = $entrada;

        $ok = false;
        if ($entrada !== '') {
            if (($cfg['acepta_equivalentes'] ?? true) !== false) {
                $ok = (is_numeric($valor) && is_numeric($esperado))
                    ? abs((float)$valor - (float)$esperado) < 0.001
                    : mb_strtolower($valor) === mb_strtolower($esperado);
            } else {
                $formula = mb_strtoupper(trim((string)($cfg['formula_esperada'] ?? '')));
                $ok = ($formula !== '' && mb_strtoupper($entrada) === $formula) || $valor === $esperado;
            }
        }
        $resultados[$ref] = ['ok' => $ok, 'valor' => $entrada === '' ? '' : $valor, 'esperado' => $cfg['valor_esperado'] ?? ''];
        if ($ok) { $correctas++; $obtenido_pts += $pts; }
    }
    $total = count($evaluables);
    $pct = $total > 0 ? (int)round($obtenido_pts / max($total_pts, 1) * 100) : 0;
    $minimo = puntaje_minimo_leccion($leccion_post);
    $aprobado = $total > 0 && $pct >= $minimo;

    $stmt = $pdo->prepare("INSERT INTO respuestas_excel (usuario_id, leccion_id, respuestas, resultados, puntaje, intentos)
                           VALUES (?, ?, ?, ?, ?, 1)
                           ON DUPLICATE KEY UPDATE
                           respuestas = VALUES(respuestas),
                           resultados = VALUES(resultados),
                           puntaje = VALUES(puntaje),
                           intentos = intentos + 1");
    $stmt->execute([$usuario_id, $lid, json_encode($respuestas), json_encode($resultados), $pct]);
    if ($aprobado) {
        $pdo->prepare("INSERT INTO progreso (usuario_id, leccion_id, completado) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE completado = 1")->execute([$usuario_id, $lid]);
    }
    responder_json(['ok' => true, 'pct' => $pct, 'correctas' => $correctas, 'total' => $total, 'minimo' => $minimo,
                    'aprobado' => $aprobado, 'celdas' => array_map(fn($r) => $r['ok'], $resultados)]);
}

// Resultado de una actividad H5P (Petición AJAX). A diferencia de los ejercicios anteriores, la
// nota la calcula el reproductor en el navegador (xAPI): aquí se valida, se conserva la mejor y,
// si aprueba (o la lección es de "solo completar"), se registra el progreso.
if (isset($_POST['guardar_resultado_h5p'])) {
    if ($tipo_leccion_post !== 'h5p') {
        http_response_code(403);
        responder_json(['ok' => false, 'error' => 'leccion_invalida']);
    }
    $lid = intval($_POST['leccion_id']);
    $datos_h5p = h5p_datos_leccion($leccion_post['contenido']);
    $verbo = (string)($_POST['verbo'] ?? '');
    $raw = filter_var($_POST['raw'] ?? '', FILTER_VALIDATE_FLOAT);
    $max = filter_var($_POST['max'] ?? '', FILTER_VALIDATE_FLOAT);
    $tiene_nota = $raw !== false && $max !== false && $max > 0;
    if (!$datos_h5p || !in_array($verbo, ['completed', 'answered', 'passed', 'failed'], true)
        || ($tiene_nota && ($raw < 0 || $raw > $max || $max > 1000000))) {
        http_response_code(422);
        responder_json(['ok' => false, 'error' => 'resultado_invalido']);
    }
    $modo = h5p_modo($datos_h5p);
    $minimo = puntaje_minimo_leccion($leccion_post);

    // Un envío por segundo como máximo (el reproductor puede emitir varios eventos seguidos)
    $stmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, updated_at, NOW()) FROM h5p_resultados WHERE usuario_id = ? AND leccion_id = ?");
    $stmt->execute([$usuario_id, $lid]);
    $segundos = $stmt->fetchColumn();
    if ($segundos !== false && (int)$segundos < 1) {
        http_response_code(429);
        responder_json(['ok' => false, 'error' => 'demasiados_envios']);
    }

    if ($modo === 'nota' && !$tiene_nota) {
        // Evento sin nota en una actividad calificada: no cuenta
        responder_json(['ok' => true, 'contado' => false]);
    }
    $pct = $tiene_nota ? round($raw / $max * 100, 2) : 0.0;
    $aprobado = $modo === 'completar' || $pct >= $minimo;

    // Se conserva la mejor nota: un intento peor no la baja ni quita el aprobado
    $pdo->prepare("INSERT INTO h5p_resultados (usuario_id, leccion_id, puntaje, puntaje_raw, puntaje_max, completado, aprobado, intentos, ultimo_envio, ip)
                   VALUES (?, ?, ?, ?, ?, 1, ?, 1, ?, ?)
                   ON DUPLICATE KEY UPDATE
                       puntaje_raw = IF(VALUES(puntaje) > puntaje OR puntaje_max IS NULL, VALUES(puntaje_raw), puntaje_raw),
                       puntaje_max = IF(VALUES(puntaje) > puntaje OR puntaje_max IS NULL, VALUES(puntaje_max), puntaje_max),
                       puntaje = GREATEST(puntaje, VALUES(puntaje)),
                       completado = 1,
                       aprobado = GREATEST(aprobado, VALUES(aprobado)),
                       intentos = intentos + 1,
                       ultimo_envio = VALUES(ultimo_envio),
                       ip = VALUES(ip)")
        ->execute([$usuario_id, $lid, $pct, $tiene_nota ? $raw : null, $tiene_nota ? $max : null, $aprobado ? 1 : 0,
                   json_encode(['verbo' => $verbo, 'raw' => $tiene_nota ? $raw : null, 'max' => $tiene_nota ? $max : null]),
                   substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);

    $stmt = $pdo->prepare("SELECT puntaje, puntaje_max, aprobado FROM h5p_resultados WHERE usuario_id = ? AND leccion_id = ?");
    $stmt->execute([$usuario_id, $lid]);
    $mejor = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($mejor['aprobado']) {
        $pdo->prepare("INSERT INTO progreso (usuario_id, leccion_id, completado) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE completado = 1")->execute([$usuario_id, $lid]);
    }
    responder_json(['ok' => true, 'contado' => true, 'modo' => $modo, 'pct' => $tiene_nota ? $pct : null,
                    'mejor' => $mejor['puntaje_max'] !== null ? (float)$mejor['puntaje'] : null,
                    'minimo' => $minimo, 'aprobado' => (bool)$mejor['aprobado']]);
}

// Datos del curso e Instructor
$stmt = $pdo->prepare("SELECT c.*, u.nombre AS instructor_nombre, u.foto AS instructor_foto, u.bio AS instructor_bio 
                       FROM cursos c 
                       LEFT JOIN curso_docentes cd ON c.id = cd.curso_id 
                       LEFT JOIN usuarios u ON cd.docente_id = u.id 
                       WHERE c.id=? LIMIT 1");
$stmt->execute([$curso_id]);
$curso = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$curso) { header('Location: index'); exit; }

// Módulos y lecciones publicados, con progreso (los borradores no se ven ni cuentan)
$stmt = $pdo->prepare("SELECT * FROM modulos WHERE curso_id=? AND estado='publicado' ORDER BY orden");
$stmt->execute([$curso_id]);
$modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$todas_lecciones = [];
foreach ($modulos as &$m) {
    $stmt = $pdo->prepare("SELECT l.*, IF(p.completado = 1, 1, 0) AS completada FROM lecciones l LEFT JOIN progreso p ON p.leccion_id=l.id AND p.usuario_id=? WHERE l.modulo_id=? AND l.estado='publicado' ORDER BY l.orden");
    $stmt->execute([$usuario_id, $m['id']]);
    $m['lecciones'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calcular progreso del módulo
    $total_m = count($m['lecciones']);
    $comp_m = count(array_filter($m['lecciones'], fn($l) => $l['completada']));
    $m['progreso'] = $total_m > 0 ? round(($comp_m / $total_m) * 100) : 0;

    foreach ($m['lecciones'] as $l) { $todas_lecciones[] = $l; }
}
unset($m);

// Lección activa
if (!$leccion_id && !empty($todas_lecciones)) {
    foreach ($todas_lecciones as $l) { if (!$l['completada']) { $leccion_id = $l['id']; break; } }
    if (!$leccion_id) $leccion_id = $todas_lecciones[0]['id'];
}

$leccion_activa = null;
foreach ($todas_lecciones as $l) { 
    if ($l['id'] == $leccion_id) { 
        $leccion_activa = $l; 
        break; 
    } 
}

if (!$leccion_activa) {
    if (!empty($todas_lecciones)) {
        header("Location: clase?curso=$curso_id&leccion=" . $todas_lecciones[0]['id']);
        exit;
    } else {
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?= htmlspecialchars($curso['titulo']) ?> | <?= htmlspecialchars(ACADEMY_NAME) ?></title>
            <link rel="icon" type="image/png" href="../img/logo.png?v=<?= LOGO_VERSION ?>">
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
            <style>
                body { margin:0; font-family:'Inter',sans-serif; background:#030712; color:#f9fafb; display:flex; align-items:center; justify-content:center; height:100vh; }
                .empty-card { background:#111827; padding:4rem; border-radius:24px; text-align:center; border:1px solid rgba(255,255,255,0.08); box-shadow:0 25px 50px -12px rgba(0,0,0,0.5); max-width:500px; width:90%; }
                .empty-card i { font-size:4rem; color:<?= $ajustes['color_acento'] ?? '#6366f1' ?>; margin-bottom:1.5rem; display:inline-block; }
                .empty-card h2 { margin:0 0 1rem; font-size:1.8rem; }
                .empty-card p { color:#9ca3af; margin-bottom:2.5rem; line-height:1.6; }
                .btn { display:inline-flex; align-items:center; gap:0.5rem; background:<?= $ajustes['color_acento'] ?? '#6366f1' ?>; color:white; text-decoration:none; padding:0.8rem 1.5rem; border-radius:10px; font-weight:600; transition:0.3s; }
                .btn:hover { filter: brightness(1.1); transform:translateY(-2px); box-shadow:0 10px 20px -5px rgba(99,102,241,0.4); }
            </style>
        </head>
        <body>
            <div class="empty-card">
                <i class="fa-solid fa-person-digging"></i>
                <h2>Curso en Construcción</h2>
                <p>Este curso aún no tiene lecciones publicadas. El instructor está trabajando para agregar contenido muy pronto.</p>
                <a href="index" class="btn"><i class="fa-solid fa-arrow-left"></i> Volver al Dashboard</a>
            </div>
        
<script>
    // --- TIME TRACKING (Permanencia en Plataforma) ---
    (function() {
        let accumulatedSeconds = 0;
        let idleTimer = null;
        let isIdle = false;
        let trackingInterval = null;
        const AFK_LIMIT_MS = 5 * 60 * 1000; // 5 minutos exactos
        const PING_INTERVAL_MS = 5 * 60 * 1000; // Enviar cada 5 minutos
        const LECCION_ID = <?= intval($leccion_id) ?>;

        function resetIdleTimer() {
            isIdle = false;
            clearTimeout(idleTimer);
            idleTimer = setTimeout(() => {
                isIdle = true;
            }, AFK_LIMIT_MS);
        }

        // Listeners supersensibles para no marcar AFK
        window.addEventListener("mousemove", resetIdleTimer);
        window.addEventListener("keydown", resetIdleTimer);
        window.addEventListener("scroll", resetIdleTimer);
        window.addEventListener("click", resetIdleTimer);
        window.addEventListener("touchstart", resetIdleTimer);
        window.addEventListener("wheel", resetIdleTimer);

        resetIdleTimer();

        // El cronómetro local
        trackingInterval = setInterval(() => {
            if (!isIdle && document.visibilityState === "visible") {
                accumulatedSeconds++;
            }
        }, 1000);

        // Ping periódico (cada 5 minutos)
        setInterval(() => {
            sendTimePing();
        }, PING_INTERVAL_MS);

        function sendTimePing(useBeacon = false) {
            if (accumulatedSeconds <= 0) return;
            
            const secsToSend = accumulatedSeconds;
            accumulatedSeconds = 0; // Reset local
            
            const fd = new FormData();
            fd.append("leccion_id", LECCION_ID);
            fd.append("segundos", secsToSend);
            fd.append("csrf_token", <?= json_encode(get_csrf_token()) ?>);

            if (useBeacon && navigator.sendBeacon) {
                navigator.sendBeacon("track_time.php", fd);
            } else {
                fetch("track_time.php", { method: "POST", body: fd }).catch(e => console.error(e));
            }
        }

        // Cierre de Ciclo
        document.addEventListener("visibilitychange", () => {
            if (document.visibilityState === "hidden") {
                sendTimePing(true);
            } else {
                resetIdleTimer();
            }
        });
        
        window.addEventListener("beforeunload", () => {
            sendTimePing(true);
        });
    })();
    // --- FIN TIME TRACKING ---
</script>




<script>
async function enviarTarea(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const btn = document.getElementById("btn_enviar_tarea");
    
    if(btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Subiendo...';
    }

    try {
        const response = await fetch("submit_tarea.php", {
            method: "POST",
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            Swal.fire({
                icon: "success",
                title: "¡Tarea Enviada!",
                text: "Tu archivo ha sido subido con éxito y está pendiente de revisión.",
                confirmButtonColor: "#10b981"
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: "error",
                title: "Error",
                text: result.error || "Ocurrió un problema al enviar la tarea."
            });
            if(btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Enviar Tarea';
            }
        }
    } catch (error) {
        console.error(error);
        Swal.fire({
            icon: "error",
            title: "Error de conexión",
            text: "No se pudo conectar con el servidor."
        });
        if(btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Enviar Tarea';
        }
    }
}
</script>
</body>
        </html>
        <?php
        exit;
    }
}

// Obtener objetivos del módulo y apuntes existentes
$modulo_act_id = $leccion_activa['modulo_id'];
$stmt_mod = $pdo->prepare("SELECT objetivos FROM modulos WHERE id=?");
$stmt_mod->execute([$modulo_act_id]);
$objetivos_modulo = $stmt_mod->fetchColumn();

$stmt_apunte = $pdo->prepare("SELECT contenido FROM apuntes WHERE usuario_id=? AND leccion_id=?");
$stmt_apunte->execute([$usuario_id, $leccion_id]);
$apunte_existente = $stmt_apunte->fetchColumn() ?: '';

// Cargar comentarios
$stmt_comentarios = $pdo->prepare("SELECT c.*, u.nombre, u.foto FROM comentarios_leccion c JOIN usuarios u ON c.usuario_id=u.id WHERE c.leccion_id=? ORDER BY c.fecha DESC");
$stmt_comentarios->execute([$leccion_id]);
$comentarios = $stmt_comentarios->fetchAll(PDO::FETCH_ASSOC);

// Progreso general
$total_lecciones = count($todas_lecciones);
$completadas = count(array_filter($todas_lecciones, fn($l) => $l['completada']));
$pct = $total_lecciones > 0 ? round(($completadas/$total_lecciones)*100) : 0;

// Gamificación: XP y racha reales del estudiante
require_once '../helpers/logros_helper.php';
$user_xp = xp_usuario($pdo, (int)$usuario_id)['xp']; // El mismo XP que en Mis Logros
$stmt_streak = $pdo->prepare("SELECT racha_actual FROM usuarios WHERE id = ?");
$stmt_streak->execute([$usuario_id]);
$user_streak = (int)$stmt_streak->fetchColumn();

// Cargar quiz y verificar intentos
$quiz_preguntas = [];
$intentos_maximos = 0;
$intentos_consumidos = 0;
$puede_intentar = true;
$intento_en_curso = false;
$quiz_aprobado = false;
$quiz_revision = null;
$lecciones_repaso = [];

// --- Clase en Vivo: Detectar si el usuario es anfitrión ---
$is_host = false;
if (isset($_GET['as_host']) && $_GET['as_host'] == '1') {
    // Verificar que realmente es docente o admin del curso
    $stmt_host = $pdo->prepare("SELECT 1 FROM curso_docentes WHERE curso_id = ? AND docente_id = ?");
    $stmt_host->execute([$curso_id, $usuario_id]);
    if ($stmt_host->fetchColumn()) {
        $is_host = true;
    }
    // También permitir admins
    $stmt_admin = $pdo->prepare("SELECT rol FROM usuarios WHERE id = ?");
    $stmt_admin->execute([$usuario_id]);
    $admin_rol = $stmt_admin->fetchColumn();
    if ($admin_rol === 'admin' || $admin_rol === 'docente') {
        $is_host = true;
    }
}

$entrega_tarea = null;
if ($leccion_activa['tipo'] === 'tarea') {
    $stmt = $pdo->prepare("SELECT * FROM tareas_entregas WHERE leccion_id = ? AND usuario_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$leccion_id, $usuario_id]);
    $entrega_tarea = $stmt->fetch(PDO::FETCH_ASSOC);

    // Update fecha_visto if not set
    if (!$entrega_tarea) {
        // We will create a blank record just to track fecha_visto, OR we can just use the progreso table.
        // Actually, creating a blank record with estado 'pendiente' is better to track the deadline precisely.
        $stmt_check = $pdo->prepare("SELECT 1 FROM tareas_entregas WHERE leccion_id = ? AND usuario_id = ?");
        $stmt_check->execute([$leccion_id, $usuario_id]);
        if (!$stmt_check->fetchColumn()) {
            $pdo->prepare("INSERT INTO tareas_entregas (leccion_id, usuario_id, fecha_visto, estado) VALUES (?, ?, NOW(), 'pendiente')")->execute([$leccion_id, $usuario_id]);
            // Re-fetch
            $stmt->execute([$leccion_id, $usuario_id]);
            $entrega_tarea = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } else if (empty($entrega_tarea['fecha_visto'])) {
        $pdo->prepare("UPDATE tareas_entregas SET fecha_visto = NOW() WHERE id = ?")->execute([$entrega_tarea['id']]);
        $entrega_tarea['fecha_visto'] = date('Y-m-d H:i:s');
    }
}

if ($leccion_activa['tipo'] === 'quiz') {
    // Verificar intentos en la BD para este usuario y lección
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM quiz_intentos WHERE usuario_id = ? AND leccion_id = ? AND consume_intento = 1");
    $stmtCount->execute([$usuario_id, $leccion_id]);
    $intentos_consumidos = $stmtCount->fetchColumn();

    require_once '../app/Learning/Evaluations/QuizCierre.php';
    require_once '../app/Learning/Evaluations/QuizRevision.php';
    require_once '../app/Learning/Evaluations/QuizSnapshot.php';

    // Un intento en curso (pausado al cerrar la página) siempre se puede continuar
    $stmtEnCurso = $pdo->prepare("SELECT id, preguntas_snapshot FROM quiz_intentos WHERE usuario_id = ? AND leccion_id = ? AND estado = 'in_progress' ORDER BY id DESC LIMIT 1");
    $stmtEnCurso->execute([$usuario_id, $leccion_id]);
    $fila_en_curso = $stmtEnCurso->fetch(PDO::FETCH_ASSOC);
    $intento_en_curso = (bool)$fila_en_curso;

    // Obtener config
    $stmtConf = $pdo->prepare("SELECT max_intentos FROM quiz_configuracion WHERE leccion_id = ?");
    $stmtConf->execute([$leccion_id]);
    $conf = $stmtConf->fetch(PDO::FETCH_ASSOC);
    if ($conf) {
        $intentos_maximos = (int)$conf['max_intentos'];
    }

    if ($intentos_maximos > 0 && $intentos_consumidos >= $intentos_maximos && !$intento_en_curso) {
        $puede_intentar = false;
    }

    // Un intento en curso se muestra con la copia de preguntas que se tomó al iniciarlo (el
    // quiz pudo editarse después); si no, el quiz actual. Las respuestas correctas no salen de aquí.
    $preguntas_fuente = ($fila_en_curso ? App\Learning\Evaluations\QuizSnapshot::decodificar($fila_en_curso['preguntas_snapshot']) : null)
        ?? (new App\Learning\Evaluations\QuizSnapshot($pdo))->capturar($leccion_id)['preguntas'];
    $quiz_preguntas = array_map(fn(array $p) => [
        'id' => (int)$p['id'],
        'tipo' => $p['tipo'],
        'pregunta' => $p['pregunta'],
        'opciones' => array_map(fn(array $o) => ['id' => (int)$o['id'], 'texto' => $o['texto']], $p['opciones'] ?? []),
    ], $preguntas_fuente);

    // Retroalimentación del último intento (revisión progresiva, ver QuizRevision)
    $quiz_revision = (new App\Learning\Evaluations\QuizRevision($pdo))->resumen($usuario_id, $leccion_id);

    // Aprobado: no hay intentos nuevos (QuizStart también lo impide); uno en curso sí se termina
    $quiz_aprobado = $quiz_revision && $quiz_revision['ya_aprobo'];
    if ($quiz_aprobado && !$intento_en_curso) {
        $puede_intentar = false;
    }

    // Para repasar: el contenido del módulo anterior al quiz o, si el quiz abre su
    // módulo (p. ej. una evaluación final), el contenido del módulo previo.
    $lecciones_repaso = [];
    if ($quiz_revision && !$quiz_revision['aprobado']) {
        $contenido_por_modulo = [];
        foreach ($modulos as $m) {
            $antes_del_quiz = [];
            foreach ($m['lecciones'] as $l) {
                if ($l['id'] == $leccion_id) {
                    $lecciones_repaso = $antes_del_quiz ?: (end($contenido_por_modulo) ?: []);
                    break 2;
                }
                if (in_array($l['tipo'], TIPOS_LECCION_CONTENIDO, true)) {
                    $antes_del_quiz[] = $l;
                }
            }
            if ($antes_del_quiz) {
                $contenido_por_modulo[] = $antes_del_quiz;
            }
        }
    }
}

// Lección H5P: paquete instalado y mejor resultado del estudiante
$h5p_datos = [];
$h5p_resultado = null;
if ($leccion_activa['tipo'] === 'h5p') {
    $h5p_datos = h5p_datos_leccion($leccion_activa['contenido']);
    $stmt_h = $pdo->prepare("SELECT puntaje, puntaje_max, aprobado, intentos FROM h5p_resultados WHERE usuario_id = ? AND leccion_id = ?");
    $stmt_h->execute([$usuario_id, $leccion_id]);
    $h5p_resultado = $stmt_h->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($leccion_activa['titulo']) ?> | <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <link rel="icon" type="image/png" href="../img/logo.png?v=<?= LOGO_VERSION ?>">
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/hyperformula/dist/hyperformula.full.min.js"></script>
      <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root {
            --bg-base: #030712;
            --bg-surface: #111827;
            --bg-glass: rgba(31, 41, 55, 0.4);
            --primary: <?= $ajustes['color_acento'] ?? '#6366f1' ?>; /* Usamos el acento para botones en este tema oscuro */
            --primary-hover: <?= $ajustes['color_acento'] ?? '#4f46e5' ?>;
            --accent: <?= $ajustes['color_acento'] ?? '#f59e0b' ?>;
            --text-main: #f9fafb;
            --text-dim: #9ca3af;
            --border-glass: rgba(255, 255, 255, 0.08);
            --sidebar-width: 380px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: var(--bg-base); 
            color: var(--text-main); 
            height: 100vh; 
            overflow: hidden; 
            display: flex; 
            flex-direction: column;
        }

        /* --- Scrollbar --- */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }

        /* --- Navigation --- */
        .top-bar {
            height: 64px;
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border-glass);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            z-index: 50;
            backdrop-filter: blur(12px);
        }

        .breadcrumb { display: flex; align-items: center; gap: 0.75rem; font-size: 0.875rem; color: var(--text-dim); }
        .breadcrumb a { color: var(--text-dim); text-decoration: none; transition: 0.2s; }
        .breadcrumb a:hover { color: white; }
        .breadcrumb i { font-size: 0.7rem; }
        .course-badge { background: rgba(99, 102, 241, 0.1); color: var(--primary); padding: 4px 10px; border-radius: 6px; font-weight: 600; font-size: 0.75rem; }

        .gamification-status { display: flex; align-items: center; gap: 1.5rem; }
        .streak-count { display: flex; align-items: center; gap: 0.5rem; color: var(--accent); font-weight: 700; font-size: 0.9rem; cursor: pointer; transition: transform 0.2s; }
        .streak-count:hover { transform: scale(1.08); }
        .xp-pill { background: rgba(255, 255, 255, 0.05); border: 1px solid var(--border-glass); padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem; }
        .xp-pill span { color: var(--accent); font-weight: 700; }

        /* --- Layout --- */
        .workspace { display: flex; flex: 1; overflow: hidden; }

        /* --- Main Content --- */
        .content-area {
            flex: 1;
            overflow-y: auto;
            scroll-behavior: smooth;
            padding: 0;
            background: radial-gradient(circle at top right, rgba(99, 102, 241, 0.05), transparent 40%);
        }

        .player-wrapper {
            background: #000;
            width: 100%;
            aspect-ratio: 16 / 9;
            max-height: 72vh;
            position: relative;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        }
        .player-wrapper iframe { width: 100%; height: 100%; border: none; }

        .lesson-body { max-width: 1000px; margin: 0 auto; padding: 3rem 2rem; }
        .lesson-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2.5rem; }
        .lesson-title h1 { font-family: 'Poppins', sans-serif; font-size: 2.2rem; margin-bottom: 0.5rem; }
        .lesson-meta { display: flex; align-items: center; gap: 1rem; color: var(--text-dim); font-size: 0.9rem; }
        .lesson-meta span { display: flex; align-items: center; gap: 0.4rem; }

        /* --- Estilos para la Lectura (Texto Enriquecido) --- */
        .reading-wrapper { text-align: left; overflow-wrap: break-word; }
        .reading-wrapper p { margin-bottom: 1.5rem; line-height: 1.8; }
        .reading-wrapper ul { margin-bottom: 1.5rem; padding-left: 2rem; list-style-type: disc; }
        .reading-wrapper ol { margin-bottom: 1.5rem; padding-left: 2rem; list-style-type: decimal; }
        .reading-wrapper li { margin-bottom: 0.5rem; }
        .reading-wrapper h1, .reading-wrapper h2, .reading-wrapper h3 { color: white; margin-top: 2rem; margin-bottom: 1rem; font-family: 'Poppins', sans-serif; }
        .reading-wrapper h1 { font-size: 2rem; }
        .reading-wrapper h2 { font-size: 1.6rem; }
        .reading-wrapper h3 { font-size: 1.3rem; }
        .reading-wrapper strong, .reading-wrapper b { color: white; font-weight: 700; }
        .reading-wrapper a { color: var(--primary); text-decoration: underline; }
        .reading-wrapper blockquote { border-left: 4px solid var(--primary); padding-left: 1rem; margin-bottom: 1.5rem; color: var(--text-dim); font-style: italic; background: rgba(255,255,255,0.02); padding: 1rem; border-radius: 0 10px 10px 0; }
        .reading-wrapper pre { background: #000; padding: 1.5rem; border-radius: 12px; margin-bottom: 1.5rem; overflow-x: auto; color: #a5b4fc; font-family: monospace; border: 1px solid var(--border-glass); }
        .reading-wrapper code { background: rgba(255,255,255,0.1); padding: 0.2rem 0.4rem; border-radius: 4px; color: #a5b4fc; font-family: monospace; font-size: 0.9em; }
        .reading-wrapper img { max-width: 100%; border-radius: 12px; margin-bottom: 1.5rem; box-shadow: 0 4px 15px rgba(0,0,0,0.3); }

        /* --- Tabs --- */
        .tabs-nav { display: flex; gap: 2rem; border-bottom: 1px solid var(--border-glass); margin-bottom: 2rem; }
        .tab-trigger { padding: 1rem 0; color: var(--text-dim); cursor: pointer; position: relative; font-weight: 500; transition: 0.2s; }
        .tab-trigger:hover { color: white; }
        .tab-trigger.active { color: var(--primary); }
        .tab-trigger.active::after { content: ''; position: absolute; bottom: -1px; left: 0; width: 100%; height: 2px; background: var(--primary); border-radius: 2px; }

        .tab-content { display: none; line-height: 1.7; animation: fadeIn 0.4s ease-out; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* --- Sidebar --- */
        .navigator {
            width: var(--sidebar-width);
            background: var(--bg-surface);
            border-left: 1px solid var(--border-glass);
            display: flex;
            flex-direction: column;
            z-index: 40;
        }

        .navigator-header { padding: 1.5rem; border-bottom: 1px solid var(--border-glass); }
        .navigator-header h3 { font-family: 'Poppins', sans-serif; font-size: 1.1rem; margin-bottom: 1rem; }
        
        .progress-container { background: rgba(255,255,255,0.05); height: 8px; border-radius: 4px; overflow: hidden; margin-bottom: 0.5rem; }
        .progress-bar { height: 100%; background: var(--primary); border-radius: 4px; transition: width 1s cubic-bezier(0.4, 0, 0.2, 1); }

        .module-list { flex: 1; overflow-y: auto; padding: 1rem 0; }
        .module-group { margin-bottom: 0.5rem; }
        
        .module-header {
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: 0.2s;
            background: rgba(255,255,255,0.02);
            border-bottom: 1px solid rgba(255,255,255,0.03);
        }
        .module-header:hover { background: rgba(255,255,255,0.05); }
        .module-info { display: flex; flex-direction: column; gap: 0.2rem; }
        .module-info strong { font-size: 0.9rem; }
        .module-info span { font-size: 0.75rem; color: var(--text-dim); }

        .lesson-items { display: none; padding: 0.5rem 0; }
        .module-group.expanded .lesson-items { display: block; }
        .module-group.expanded .chevron { transform: rotate(180deg); }
        .chevron { font-size: 0.7rem; transition: 0.3s; color: var(--text-dim); }

        .lesson-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.85rem 1.5rem;
            text-decoration: none;
            color: var(--text-dim);
            font-size: 0.85rem;
            transition: 0.2s;
            position: relative;
        }
        .lesson-item:hover { color: white; background: rgba(255,255,255,0.03); }
        .lesson-item.active { background: rgba(99, 102, 241, 0.1); color: var(--primary); font-weight: 600; }
        .lesson-item.active::before { content: ''; position: absolute; left: 0; top: 0; height: 100%; width: 3px; background: var(--primary); }
        
        .status-icon { width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; border-radius: 50%; border: 2px solid rgba(255,255,255,0.1); font-size: 0.7rem; }
        .lesson-item.completed .status-icon { background: var(--10b981); border-color: var(--10b981); color: white; background: #10b981; }

        /* --- Components --- */
        .btn-complete { background: var(--primary); color: white; border: none; padding: 12px 24px; border-radius: 10px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: 0.3s; }
        .btn-complete:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 10px 20px -5px rgba(99, 102, 241, 0.4); }

        .instructor-card {
            background: var(--bg-glass);
            border: 1px solid var(--border-glass);
            padding: 2rem;
            border-radius: 20px;
            display: flex;
            gap: 2rem;
            align-items: center;
            margin-top: 4rem;
        }
        .instructor-avatar { width: 80px; height: 80px; border-radius: 50%; border: 3px solid var(--primary); object-fit: cover; flex-shrink: 0; }
        .instructor-avatar-iniciales { display: flex; align-items: center; justify-content: center; background: var(--primary); color: #fff; font-size: 1.8rem; font-weight: 700; font-family: 'Poppins', sans-serif; }
        
        .resource-card {
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border-glass);
            padding: 1.5rem;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            transition: 0.2s;
        }
        .resource-card:hover { transform: scale(1.01); background: rgba(255,255,255,0.05); }

        .quiz-container { background: var(--bg-glass); border: 1px solid var(--border-glass); padding: 3rem; border-radius: 24px; }
        .opcion-label {
            display: block;
            padding: 1.2rem 1.5rem;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border-glass);
            border-radius: 12px;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: 0.2s;
        }
        .opcion-label:hover { border-color: var(--primary); background: rgba(99, 102, 241, 0.05); }

        .quiz-revision { padding: 2rem; background: rgba(255,255,255,0.03); border: 1px solid var(--border-glass); border-radius: 12px; margin-bottom: 2rem; }
        .quiz-revision h3 { margin: 0 0 1rem; font-size: 1.1rem; }
        .quiz-revision-nota { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.5rem 1.5rem; margin-bottom: 1rem; }
        .quiz-revision-nota strong { font-size: 2rem; }
        .quiz-revision-aviso { color: var(--text-dim); font-size: 0.9rem; margin: 0 0 1rem; line-height: 1.5; }
        .quiz-revision-lista { list-style: none; padding: 0; margin: 0 0 1.5rem; }
        .quiz-revision-lista > li { padding: 0.9rem 1rem; border: 1px solid var(--border-glass); border-radius: 10px; margin-bottom: 0.6rem; line-height: 1.5; }
        .quiz-revision-lista .fa-circle-check { color: #22c55e; }
        .quiz-revision-lista .fa-circle-xmark { color: #ef4444; }
        .quiz-revision-lista .fa-circle-minus { color: #f59e0b; }
        .quiz-revision-opciones { list-style: none; padding: 0; margin: 0.6rem 0 0 1.6rem; font-size: 0.92rem; color: var(--text-dim); }
        .quiz-revision-opciones li { padding: 0.2rem 0; }
        .quiz-revision-opciones .correcta { color: #22c55e; font-weight: 600; }
        .quiz-revision-opciones .elegida-incorrecta { color: #ef4444; }
        .quiz-revision-repaso a { color: var(--primary); text-decoration: none; }
        .quiz-revision-repaso a:hover { text-decoration: underline; }
        .quiz-nav { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; margin-bottom: 2.5rem; }
        .quiz-nav-num { width: 2.25rem; height: 2.25rem; border-radius: 8px; border: 1px solid var(--border-glass); background: rgba(255,255,255,0.03); color: var(--text-dim); font-weight: 600; cursor: pointer; transition: 0.2s; }
        .quiz-nav-num:hover { border-color: var(--primary); }
        .quiz-nav-num.respondida { background: var(--primary); border-color: var(--primary); color: white; }
        .quiz-nav-num.actual { outline: 2px solid white; outline-offset: 2px; }
        .quiz-nav-resumen { margin-left: auto; color: var(--text-dim); font-size: 0.9rem; }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }

        /* --- Responsive --- */
        @media (max-width: 1024px) {
            .navigator { display: none; }
            .lesson-body { padding: 2rem 1rem; }
        }
    </style>
</head>
<body>

<header class="top-bar">
    <div class="breadcrumb">
        <a href="index"><img src="../img/logo.png?v=<?= LOGO_VERSION ?>" alt="<?= htmlspecialchars(ACADEMY_NAME) ?> Logo" style="height: 25px; margin-right: 10px; vertical-align: middle;"></a>
        <a href="index">Dashboard</a>
        <i class="fa-solid fa-chevron-right"></i>
        <span class="course-badge"><?= htmlspecialchars($curso['nivel']) ?></span>
        <span style="color: white; font-weight: 500;"><?= htmlspecialchars($curso['titulo']) ?></span>
    </div>

    <div class="gamification-status">
        <div class="streak-count" title="Tu racha de estudio" onclick="showCustomAlert('Racha de Estudio', '¡Llevas una racha de <?= $user_streak ?> días de estudio! Mantén tu racha activa ingresando a tus clases todos los días para no perder el impulso.', 'fa-solid fa-fire', '#ef4444')">
            <i class="fa-solid fa-fire"></i>
            <span><?= $user_streak ?> Días</span>
        </div>
        <div class="xp-pill">
            <i class="fa-solid fa-star"></i>
            XP: <span><?= $user_xp ?></span>
        </div>
        <a href="../logout" style="color: var(--text-dim); text-decoration: none;"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
</header>

<div class="workspace">
    <main class="content-area">
        <?php if($leccion_activa['tipo'] === 'video'): 
            $url = trim($leccion_activa['contenido']);
            $video_id = $url;
            $is_vimeo = false;
            $is_mp4 = (substr(strtolower($url), -4) === '.mp4' || strpos(strtolower($url), '.mp4?') !== false);
            
            // Extraer ID de YouTube
            if (preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/\s]{11})%i', $url, $match)) {
                $video_id = $match[1];
            } 
            // Extraer ID de Vimeo
            elseif (preg_match('/vimeo\.com\/(?:.*#|.*\/videos\/)?([0-9]+)/i', $url, $match)) {
                $video_id = $match[1];
                $is_vimeo = true;
            }
        ?>
        <div class="player-wrapper">
            <?php if ($is_mp4): ?>
                <video controls style="width:100%; height:100%; background:#000; outline:none;">
                    <source src="<?= htmlspecialchars($url) ?>" type="video/mp4">
                    Tu navegador no soporta videos HTML5.
                </video>
            <?php elseif ($is_vimeo): ?>
                <iframe src="https://player.vimeo.com/video/<?= htmlspecialchars($video_id) ?>" allowfullscreen></iframe>
            <?php else: ?>
                <iframe src="https://www.youtube-nocookie.com/embed/<?= htmlspecialchars($video_id) ?>?rel=0&modestbranding=1" allowfullscreen></iframe>
            <?php endif; ?>
        </div>

        <?php elseif($leccion_activa['tipo'] === 'clase_vivo'): ?>
        <div style="display:flex; flex-direction:column; height:100%;">
            <div style="display:flex; justify-content:space-between; align-items:center; padding:1rem 2rem; background:var(--bg-surface); border-bottom:1px solid var(--border-glass);">
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <span style="background:<?= $is_host ? '#10b981' : '#ef4444' ?>; width:10px; height:10px; border-radius:50%; display:inline-block; animation: pulse 2s infinite;"></span>
                    <span style="font-weight:600; color:white;">Clase en Vivo</span>
                    <?php if($is_host): ?>
                    <span style="background:rgba(16,185,129,0.15); color:#10b981; padding:2px 10px; border-radius:12px; font-size:0.75rem; font-weight:700;">ANFITRIÓN</span>
                    <?php endif; ?>
                </div>
                <?php if(!$is_host && !$leccion_activa['completada']): ?>
                <button onclick="marcarClaseCompleta()" class="btn-complete" style="padding:0.4rem 1rem; font-size:0.85rem; border-radius:8px;"><i class="fa-solid fa-check"></i> Marcar Completada</button>
                <script>
                function marcarClaseCompleta() {
                    fetch('clase.php?curso=<?= $curso_id ?>&leccion=<?= $leccion_id ?>', {
                        method: 'POST',
                        headers: {'Content-Type':'application/x-www-form-urlencoded'},
                        body: 'marcar_completada=1&leccion_id=<?= $leccion_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>'
                    }).then(r => r.text()).then(txt => { if(txt.trim() === 'ok') location.reload(); });
                }
                </script>
                <?php endif; ?>
            </div>

            <div class="player-wrapper" style="border-radius: 0; flex:1; height:auto; max-height:none;">
                <?php if($leccion_activa['plataforma_video'] === 'jitsi'): ?>
                    <div id="jitsi-meet-container" style="width: 100%; height: 100%;"></div>
                    <script src="https://meet.jit.si/external_api.js"></script>
                    <script>
                        (function initJitsi() {
                            if (typeof JitsiMeetExternalAPI === 'undefined') {
                                setTimeout(initJitsi, 200);
                                return;
                            }
                            const domain = 'meet.jit.si';
                            const roomName = 'MeshLMS_Curso_<?= $curso_id ?>_Clase_<?= $leccion_id ?>_' + btoa('<?= $_SERVER['SERVER_NAME'] ?>').substring(0,8);
                            const options = {
                                roomName: roomName,
                                width: '100%',
                                height: '100%',
                                parentNode: document.querySelector('#jitsi-meet-container'),
                                userInfo: {
                                    displayName: '<?= htmlspecialchars($_SESSION['user_name'] ?? 'Usuario', ENT_QUOTES, "UTF-8") . ($is_host ? ' (Profesor)' : '') ?>'
                                },
                                configOverwrite: { 
                                    startWithAudioMuted: <?= $is_host ? 'false' : 'true' ?>,
                                    startWithVideoMuted: false,
                                    prejoinPageEnabled: false
                                }
                            };
                            const api = new JitsiMeetExternalAPI(domain, options);
                        })();
                    </script>

                <?php elseif($leccion_activa['plataforma_video'] === 'zoom'): ?>
                    <?php
                        // Para estudiantes: Web SDK incrustado
                        $zoom_url_estudiante = 'zoom_sala.php?meeting=' . urlencode($leccion_activa['zoom_meeting_id']) 
                                             . '&pass=' . urlencode($leccion_activa['zoom_passcode']) 
                                             . '&role=0';
                        
                        // Para el host: URL nativa de Zoom para que abra su aplicación de escritorio
                        $zoom_url_host = 'https://zoom.us/j/' . urlencode(preg_replace('/[^0-9]/', '', $leccion_activa['zoom_meeting_id'])) 
                                       . '?pwd=' . urlencode($leccion_activa['zoom_passcode']);
                    ?>
                    <?php if($is_host): ?>
                        <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; color:white;">
                            <i class="fa-solid fa-video" style="font-size:4rem; color:#2d8cff; margin-bottom:1rem;"></i>
                            <h3 style="margin-bottom:0.5rem;">Iniciar Reunión de Zoom</h3>
                            <p style="color:#9ca3af; margin-bottom:0.5rem;">Meeting ID: <?= htmlspecialchars($leccion_activa['zoom_meeting_id']) ?></p>
                            <p style="color:#6b7280; font-size:0.85rem; margin-bottom:2rem;">Se abrirá en tu aplicación de escritorio de Zoom para garantizar controles de Anfitrión.</p>
                            <a href="<?= $zoom_url_host ?>" target="_blank" class="btn-complete" style="padding:1rem 3rem; font-size:1.1rem; border-radius:30px; background:#10b981; color:white; text-decoration:none; display:inline-flex; align-items:center; gap:0.5rem;">
                                <i class="fa-solid fa-rocket"></i> Arrancar Clase (App Zoom)
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="video-container" style="height: 100vh;">
                            <iframe src="<?= $zoom_url_estudiante ?>" allow="camera; microphone; display-capture" allowfullscreen></iframe>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <style>@keyframes pulse { 0%, 100% { opacity:1; } 50% { opacity:0.4; } }</style>

        <?php elseif($leccion_activa['tipo'] === 'tarea'): ?>
        <div class="reading-wrapper" style="background: var(--bg-surface); padding: 4rem; margin: 2rem; border-radius: 20px; border: 1px solid var(--border-glass); box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
            
            <h2 style="margin-bottom: 2rem; font-family: 'Poppins'; font-size: 2rem;"><i class="fa-solid fa-cloud-arrow-up" style="color:var(--accent);"></i> Entregable de la Lección</h2>
            
            <div style="background: rgba(255,255,255,0.03); border-radius: 12px; padding: 2rem; margin-bottom: 2rem; font-size: 1.1rem; color: #e2e8f0; line-height: 1.6; border: 1px solid rgba(255,255,255,0.05);">
                <h4 style="margin-top:0; color:var(--primary); margin-bottom:1rem;">Instrucciones:</h4>
                <?= limpiar_html($leccion_activa['contenido']) ?>
            </div>

            <?php if($leccion_activa['recurso_url']): ?>
            <div style="margin-bottom:2rem; padding:1.5rem; background:rgba(99,102,241,0.05); border:1px solid rgba(99,102,241,0.2); border-radius:12px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <strong style="display:block; color:white;"><i class="fa-solid fa-paperclip"></i> Plantilla / Recurso Adjunto</strong>
                    <small style="color:var(--text-dim);"><?= htmlspecialchars($leccion_activa['recurso_titulo']) ?></small>
                </div>
                <a href="<?= htmlspecialchars($leccion_activa['recurso_url']) ?>" target="_blank" class="btn-complete" style="width:auto; padding:0.6rem 1.2rem; font-size:0.9rem;"><i class="fa-solid fa-download"></i> Descargar</a>
            </div>
            <?php endif; ?>

            <?php
            // Plazo sugerido, contado desde que este estudiante abrió la lección (cada uno avanza a su
            // ritmo). Es orientativo: no impide entregar después.
            $dias_limite = intval($leccion_activa['tarea_dias_limite'] ?: 7);
            $fecha_visto = new DateTime($entrega_tarea['fecha_visto'] ?? 'now');
            $fecha_limite = clone $fecha_visto;
            $fecha_limite->modify("+$dias_limite days");
            $ahora = new DateTime();
            $diferencia = $ahora->diff($fecha_limite);
            
            $dias_restantes = $diferencia->invert ? -$diferencia->days : $diferencia->days;
            $color_reloj = $dias_restantes <= 2 ? '#f59e0b' : '#10b981';
            $texto_reloj = $dias_restantes < 0 ? 'Plazo sugerido superado hace ' . abs($dias_restantes) . ' días — aún puedes entregar' : 'Plazo sugerido: ' . $dias_restantes . ' días';
            ?>

            <div style="background:var(--bg-glass); border:1px solid var(--border-glass); border-radius:16px; padding:2rem; text-align:center;">
                
                <?php if(!$entrega_tarea || (empty($entrega_tarea['archivo_ruta']) && $entrega_tarea['estado'] === 'pendiente') || ($entrega_tarea['estado'] === 'rechazado')): ?>
                    
                    <?php if($entrega_tarea && $entrega_tarea['estado'] === 'rechazado'): ?>
                        <div style="background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); border-radius:12px; padding:1.5rem; margin-bottom:2rem; text-align:left;">
                            <h4 style="color:#ef4444; margin-top:0;"><i class="fa-solid fa-circle-xmark"></i> Tarea Rechazada</h4>
                            <p style="margin-bottom:0.5rem; color:var(--text-dim);">El profesor ha solicitado correcciones.</p>
                            <div style="background:rgba(0,0,0,0.2); padding:1rem; border-radius:8px; color:white; font-style:italic;">
                                "<?= htmlspecialchars($entrega_tarea['feedback_docente']) ?>"
                            </div>
                        </div>
                    <?php endif; ?>

                    <h4 style="margin-top:0; margin-bottom:0.5rem;">Sube tu respuesta</h4>
                    <p style="color: <?= $color_reloj ?>; font-weight:600; margin-bottom:1.5rem;"><i class="fa-regular fa-clock"></i> <?= $texto_reloj ?> (Sugerido: <?= $fecha_limite->format('d M Y') ?>)</p>
                    
                    <form id="formTarea" onsubmit="enviarTarea(event)">
                        <input type="hidden" name="leccion_id" value="<?= $leccion_id ?>">
                        <?= csrf_input() ?>
                        
                        <div style="display:flex; justify-content:center; margin-bottom:1.5rem;">
                            <label for="archivo_tarea" style="background:rgba(255,255,255,0.05); border:2px dashed var(--primary); border-radius:16px; padding:3rem; width:100%; cursor:pointer; transition:0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background='rgba(255,255,255,0.05)'">
                                <i class="fa-solid fa-cloud-arrow-up" style="font-size:3rem; color:var(--primary); margin-bottom:1rem;"></i>
                                <h4 style="margin:0;">Haz clic para seleccionar tu archivo</h4>
                                <p style="color:var(--text-dim); margin-top:0.5rem; font-size:0.9rem;">Formatos permitidos: <?= htmlspecialchars($leccion_activa['tarea_formatos'] ?: 'pdf, docx') ?> (Max 5MB)</p>
                            </label>
                            <input type="file" id="archivo_tarea" name="archivo_tarea" style="display:none;" required onchange="document.getElementById('nombre_archivo').innerText = this.files[0].name;">
                        </div>
                        <p id="nombre_archivo" style="color:var(--accent); font-weight:600; margin-bottom:1.5rem;"></p>

                        <textarea name="comentarios" placeholder="Añade un comentario opcional para el profesor..." style="width:100%; background:rgba(0,0,0,0.2); border:1px solid var(--border-glass); border-radius:12px; color:white; padding:1rem; font-family:inherit; resize:vertical; min-height:80px; margin-bottom:1.5rem; outline:none;"></textarea>

                        <button type="submit" id="btn_enviar_tarea" class="btn-complete" style="width:auto; padding:1rem 3rem;"><i class="fa-solid fa-paper-plane"></i> Enviar Tarea</button>
                    </form>
                
                <?php else: ?>
                    
                    <!-- ESTADO DE LA TAREA ENVIADA -->
                    <?php if($entrega_tarea['estado'] === 'pendiente'): ?>
                        <i class="fa-solid fa-hourglass-half" style="font-size:4rem; color:#f59e0b; margin-bottom:1rem;"></i>
                        <h3 style="color:#f59e0b;">Tarea en Revisión</h3>
                        <p style="color:var(--text-dim); max-width:400px; margin:0 auto;">Tu archivo ha sido enviado exitosamente y está a la espera de calificación por parte del docente.</p>
                    <?php elseif($entrega_tarea['estado'] === 'aprobado'): ?>
                        <i class="fa-solid fa-circle-check" style="font-size:4rem; color:#10b981; margin-bottom:1rem;"></i>
                        <h3 style="color:#10b981;">¡Tarea Aprobada!</h3>
                        <div style="background:rgba(16,185,129,0.1); border:1px solid rgba(16,185,129,0.3); border-radius:12px; padding:1.5rem; margin-top:1.5rem; text-align:left;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                                <strong>Calificación:</strong>
                                <span style="background:#10b981; color:white; padding:4px 12px; border-radius:20px; font-weight:700;"><?= floatval($entrega_tarea['puntaje']) ?>/100</span>
                            </div>
                            <?php if($entrega_tarea['feedback_docente']): ?>
                            <strong>Feedback del profesor:</strong>
                            <p style="color:var(--text-dim); margin-top:0.5rem; font-style:italic;">"<?= htmlspecialchars($entrega_tarea['feedback_docente']) ?>"</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($entrega_tarea['archivo_ruta'])): ?>
                      <div style="margin-top:2rem; padding-top:2rem; border-top:1px solid var(--border-glass);">
                          <p style="color:var(--text-dim); font-size:0.9rem; margin-bottom:0.5rem;">Archivo entregado el <?= date('d M Y', strtotime($entrega_tarea['fecha_envio'])) ?>:</p>
                          <a href="../<?= htmlspecialchars($entrega_tarea['archivo_ruta']) ?>" target="_blank" class="btn btn-outline-dark" style="display:inline-flex; align-items:center; gap:0.5rem;"><i class="fa-solid fa-file-arrow-down"></i> Ver mi archivo enviado</a>
                      </div>
                    <?php endif; ?>

                <?php endif; ?>
            </div>
        </div>

        <?php elseif($leccion_activa['tipo'] === 'texto'): ?>
        <div class="reading-wrapper" style="background: var(--bg-surface); padding: 4rem; margin: 2rem; border-radius: 20px; border: 1px solid var(--border-glass); font-size: 1.15rem; color: #e2e8f0; min-height: 50vh; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
            <?php 
                $contenido = $leccion_activa['contenido'];
                // Si el texto fue creado antes del editor enriquecido y no tiene etiquetas HTML de bloque
                if(strpos($contenido, '<p>') === false && strpos($contenido, '<h') === false && strpos($contenido, '<ul>') === false) {
                    echo nl2br(htmlspecialchars($contenido));
                } else {
                    echo limpiar_html($contenido);
                }
            ?>
        </div>
        <?php elseif($leccion_activa['tipo'] === 'quiz'): ?>
        <div class="reading-wrapper" style="background: var(--bg-surface); padding: 4rem; margin: 2rem; border-radius: 20px; border: 1px solid var(--border-glass); box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
            <div class="quiz-container" style="background: transparent; border: none; padding: 0;">
                <h2 style="margin-bottom: 2rem; font-family: 'Poppins';">Evaluación Interactiva</h2>

                <?php if($quiz_revision): $rv = $quiz_revision; $fmt = fn($n) => rtrim(rtrim(number_format((float)$n, 2, ',', ''), '0'), ','); ?>
                <section id="quiz-revision" class="quiz-revision" aria-labelledby="quiz-revision-titulo">
                    <h3 id="quiz-revision-titulo"><?= $rv['ya_aprobo'] ? 'Tu intento aprobado' : 'Resultado de tu último intento' ?><?= $rv['intento_numero'] ? ' (intento ' . (int)$rv['intento_numero'] . ')' : '' ?></h3>

                    <?php if(in_array($rv['estado'], ['pending_review', 'pendiente'], true)): ?>
                        <p class="quiz-revision-aviso"><i class="fa-solid fa-hourglass-half"></i> Tus respuestas están pendientes de revisión por el instructor.</p>
                    <?php else: ?>
                        <div class="quiz-revision-nota">
                            <?php if($rv['mostrar_puntaje']): ?><strong><?= $fmt($rv['puntaje']) ?>%</strong><?php endif; ?>
                            <?php if($rv['aprobado']): ?>
                                <span style="color:#22c55e; font-weight:600;"><i class="fa-solid fa-circle-check"></i> Aprobado</span>
                            <?php else: ?>
                                <span style="color:#ef4444; font-weight:600;"><i class="fa-solid fa-circle-xmark"></i> No aprobado</span>
                            <?php endif; ?>
                            <span style="color: var(--text-dim);">Nota mínima: <?= $fmt($rv['minimo']) ?>%</span>
                        </div>

                        <?php if(!$rv['aprobado'] && $rv['intentos_restantes'] !== null): ?>
                            <p class="quiz-revision-aviso">
                                <?= $rv['intentos_restantes'] > 0
                                    ? 'Te ' . ($rv['intentos_restantes'] === 1 ? 'queda 1 intento' : 'quedan ' . (int)$rv['intentos_restantes'] . ' intentos') . '.'
                                    : 'Ya usaste todos tus intentos.' ?>
                            </p>
                        <?php endif; ?>

                        <?php if($rv['preguntas']): ?>
                            <p class="quiz-revision-aviso">
                                <?= $rv['revelar']
                                    ? 'Revisa cada pregunta: la respuesta correcta está marcada en verde.'
                                    : 'Repasa las preguntas que fallaste antes de tu próximo intento. Las respuestas correctas se mostrarán cuando apruebes o uses todos tus intentos.' ?>
                            </p>
                            <ol class="quiz-revision-lista">
                                <?php foreach($rv['preguntas'] as $i => $p):
                                    [$icono, $etiqueta] = match($p['resultado']) {
                                        'correcta' => ['fa-circle-check', 'Correcta'],
                                        'incorrecta' => ['fa-circle-xmark', 'Incorrecta'],
                                        default => ['fa-circle-minus', 'Sin responder'],
                                    }; ?>
                                <li>
                                    <i class="fa-solid <?= $icono ?>" aria-hidden="true"></i>
                                    <span class="sr-only"><?= $etiqueta ?>:</span>
                                    <?= ($i + 1) ?>. <?= htmlspecialchars($p['texto'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if($p['resultado'] === 'sin_responder'): ?><small style="color: var(--text-dim);">(sin responder)</small><?php endif; ?>
                                    <?php if($p['opciones']): ?>
                                    <ul class="quiz-revision-opciones">
                                        <?php foreach($p['opciones'] as $op): ?>
                                        <li class="<?= $op['correcta'] ? 'correcta' : ($op['elegida'] ? 'elegida-incorrecta' : '') ?>">
                                            <?= $op['correcta'] ? '<i class="fa-solid fa-check" aria-hidden="true"></i> ' : '' ?><?= htmlspecialchars($op['texto'], ENT_QUOTES, 'UTF-8') ?>
                                            <?= $op['correcta'] ? '<span class="sr-only">(respuesta correcta)</span>' : '' ?>
                                            <?= $op['elegida'] ? '<small>— tu respuesta</small>' : '' ?>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if($lecciones_repaso && !in_array($rv['estado'], ['pending_review', 'pendiente'], true)): ?>
                    <div class="quiz-revision-repaso">
                        <p class="quiz-revision-aviso" style="margin-bottom: 0.5rem;"><i class="fa-solid fa-book-open"></i> Para repasar:</p>
                        <ul style="margin: 0; padding-left: 1.4rem; line-height: 1.8;">
                            <?php foreach($lecciones_repaso as $lr): ?>
                            <li><a href="clase?curso=<?= (int)$curso_id ?>&leccion=<?= (int)$lr['id'] ?>"><?= htmlspecialchars($lr['titulo'], ENT_QUOTES, 'UTF-8') ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </section>
                <?php endif; ?>

                <div id="quiz-start-screen" style="text-align:center; padding: 2rem; background: rgba(255,255,255,0.03); border: 1px solid var(--border-glass); border-radius: 12px; margin-bottom: 2rem;">
                    <?php if(!$puede_intentar && $quiz_aprobado): ?>
                        <h3 style="margin-bottom: 1rem; color: #22c55e;"><i class="fa-solid fa-circle-check"></i> Ya aprobaste esta evaluación</h3>
                        <p style="color: var(--text-dim); margin-bottom: 2rem;">No necesitas presentarla de nuevo.<?= !empty($quiz_revision['preguntas']) ? ' Arriba puedes revisar tus respuestas.' : '' ?></p>
                        <a href="index" class="btn-complete" style="background: rgba(255,255,255,0.1); color:white; text-decoration: none;">Volver al curso</a>
                    <?php elseif(!$puede_intentar): ?>
                        <h3 style="margin-bottom: 1rem; color: #ef4444;"><i class="fa-solid fa-lock"></i> Límite de intentos alcanzado</h3>
                        <p style="color: var(--text-dim); margin-bottom: 2rem;">Has consumido tus <?= $intentos_maximos ?> intentos permitidos para esta evaluación.</p>
                        <a href="index" class="btn-complete" style="background: rgba(255,255,255,0.1); color:white; text-decoration: none;">Volver al curso</a>
                    <?php elseif($intento_en_curso): ?>
                        <h3 style="margin-bottom: 1rem;">Tienes un intento en curso</h3>
                        <p style="color: var(--text-dim); margin-bottom: 2rem;">Su tiempo está en pausa. Al continuar, el reloj sigue desde donde lo dejaste y recuperas tus respuestas guardadas.</p>
                        <button id="btn-iniciar-quiz" type="button" class="btn-complete" style="background: var(--primary);" onclick="window.quizEngineInstance.iniciarQuiz()">Continuar intento</button>
                    <?php else: ?>
                        <h3 style="margin-bottom: 1rem;">¿Estás listo para iniciar?</h3>
                        <p style="color: var(--text-dim); margin-bottom: 1rem;">El tiempo comenzará a correr inmediatamente. Si cierras la página, se pausa y continúa cuando vuelvas.</p>
                        <?php if($intentos_maximos > 0): ?>
                        <p style="color: var(--text-dim); margin-bottom: 2rem; font-size: 0.9rem;">
                            <i class="fa-solid fa-circle-info"></i> Llevas <?= $intentos_consumidos ?> de <?= $intentos_maximos ?> intentos permitidos.
                        </p>
                        <?php endif; ?>
                        <button id="btn-iniciar-quiz" type="button" class="btn-complete" style="background: var(--primary);" onclick="window.quizEngineInstance.iniciarQuiz()">Comenzar Intento</button>
                    <?php endif; ?>
                </div>

                <div id="quiz-exam-screen" style="display:none;">
                    <div style="display: flex; justify-content: flex-end; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                        <small style="color: var(--text-dim); text-align: right; max-width: 320px;">El tiempo se pausa si cierras esta página. Si la dejas abierta, sigue corriendo.</small>
                        <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); padding: 0.5rem 1.5rem; border-radius: 8px;">
                            <span style="color: #ef4444; font-weight: bold; font-family: monospace; font-size: 1.5rem;" id="quiz-timer">--:--</span>
                        </div>
                    </div>

                    <form id="quizForm" enctype="multipart/form-data" onsubmit="event.preventDefault(); window.quizEngineInstance.solicitarEntrega();">
                        <input type="hidden" name="leccion_id" value="<?= $leccion_activa['id'] ?>">

                    <?php $total_q = count($quiz_preguntas); if($total_q > 0): ?>
                    <div style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
                        <span id="quiz-progress-text" style="color: var(--text-dim); font-weight: 500;">Pregunta 1 de <?= $total_q ?></span>
                        <div style="flex: 1; margin-left: 1rem; background: rgba(255,255,255,0.05); height: 8px; border-radius: 4px; overflow: hidden;">
                            <div id="quiz-progress-bar" style="height: 100%; width: <?= (1/$total_q)*100 ?>%; background: var(--primary); transition: 0.3s;"></div>
                        </div>
                    </div>
                    <nav class="quiz-nav" aria-label="Preguntas del quiz">
                        <?php for($n = 0; $n < $total_q; $n++): ?>
                        <button type="button" class="quiz-nav-num<?= $n === 0 ? ' actual' : '' ?>" onclick="navQuiz(<?= $n ?>)" aria-label="Pregunta <?= $n + 1 ?>, sin responder"<?= $n === 0 ? ' aria-current="step"' : '' ?>><?= $n + 1 ?></button>
                        <?php endfor; ?>
                        <span id="quiz-respondidas" class="quiz-nav-resumen" aria-live="polite">0 de <?= $total_q ?> respondidas</span>
                    </nav>
                    <?php endif; ?>

                    <?php foreach($quiz_preguntas as $idx => $q): ?>
                    <div class="quiz-step" id="quiz-step-<?= $idx ?>" style="display: <?= $idx === 0 ? 'block' : 'none' ?>; animation: fadeIn 0.4s;">
                        <div style="margin-bottom: 3rem;">
                            <h4 style="margin-bottom: 1.5rem; font-size: 1.15rem; color: white; line-height: 1.5;"><?= ($idx+1) ?>. <?= htmlspecialchars($q['pregunta']) ?></h4>
                            <?php if($q['tipo'] === 'unica' || $q['tipo'] === 'multiple' || $q['tipo'] === 'falso_verdadero'): ?>
                                <?php foreach($q['opciones'] as $op): ?>
                                <label class="opcion-label">
                                    <input type="<?= $q['tipo']==='multiple'?'checkbox':'radio' ?>" name="respuesta[<?= $q['id'] ?>]<?= $q['tipo']==='multiple'?'[]':'' ?>" value="<?= $op['id'] ?>">
                                    <?= htmlspecialchars($op['texto']) ?>
                                </label>
                                <?php endforeach; ?>
                            <?php elseif($q['tipo'] === 'abierta'): ?>
                                <textarea name="respuesta_abierta[<?= $q['id'] ?>]" rows="4" style="width:100%; background:rgba(255,255,255,0.03); border:1px solid var(--border-glass); border-radius:12px; color:white; padding:1.2rem;" placeholder="Escribe tu respuesta..."></textarea>
                            <?php elseif($q['tipo'] === 'archivo'): ?>
                                <div style="background:rgba(255,255,255,0.03); border:2px dashed var(--border-glass); border-radius:12px; padding:2rem; text-align:center;">
                                    <i class="fa-solid fa-cloud-arrow-up" style="font-size:2rem; color:var(--primary); margin-bottom:1rem; display:block;"></i>
                                    <input type="file" name="archivo[<?= $q['id'] ?>]" style="color:var(--text-dim);">
                                    <p style="font-size:0.8rem; color:var(--text-dim); margin-top:1rem;">Formatos aceptados: PDF, ZIP, JPG, PNG.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div style="display: flex; gap: 1rem; justify-content: <?= $idx === 0 ? 'flex-end' : 'space-between' ?>;">
                            <?php if($idx > 0): ?>
                            <button type="button" class="btn-complete" style="background: rgba(255,255,255,0.05); color: white;" onclick="navQuiz(<?= $idx - 1 ?>)">Anterior</button>
                            <?php endif; ?>
                            
                            <?php if($idx < $total_q - 1): ?>
                            <button type="button" class="btn-complete" onclick="navQuiz(<?= $idx + 1 ?>)">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
                            <?php else: ?>
                            <button id="btn-entregar-quiz" type="submit" class="btn-complete" style="background: var(--10b981);">Enviar respuestas</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </form>
                </div>
            </div>
        </div>
        <?php elseif($leccion_activa['tipo'] === 'hoja_calculo'): ?>
        <div style="width:100%; background:#0f172a; padding:1rem 2rem 0;">
            <div style="display:flex; align-items:center; gap:0.75rem; padding:0.75rem 0; border-bottom:1px solid rgba(255,255,255,0.08); margin-bottom:1rem;">
                <i class="fa-solid fa-table" style="color:#10b981; font-size:1.1rem;"></i>
                <span style="color:#e2e8f0; font-weight:600; font-size:0.95rem;">Hoja de Cálculo Interactiva</span>
                <span style="font-size:0.75rem; color:#64748b; margin-left:auto;">Powered by Microsoft OneDrive</span>
            </div>
            <iframe 
                src="<?= htmlspecialchars($leccion_activa['contenido']) ?>"
                width="100%" 
                height="600" 
                frameborder="0" 
                scrolling="no"
                style="border-radius:12px; display:block;"
                allowfullscreen>
            </iframe>
            <p style="font-size:0.75rem; color:#475569; text-align:center; padding:0.75rem 0;">
                <i class="fa-solid fa-info-circle"></i> 
                Si el archivo no carga, verifica que esté configurado como público en OneDrive.
            </p>
        </div>
        <?php elseif($leccion_activa['tipo'] === 'h5p'):
            $h5p_fmt = fn($n) => rtrim(rtrim(number_format((float)$n, 2, ',', ''), '0'), ',');
            $h5p_completar = h5p_modo($h5p_datos) === 'completar';
            $h5p_minimo = puntaje_minimo_leccion($leccion_activa);
        ?>
        <style>
            .h5p-wrapper { padding: 1.5rem 2rem 1rem; background: #0f172a; }
            .h5p-barra { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; padding: 0.75rem 0; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 1rem; }
            .h5p-estado { margin-left: auto; font-size: 0.85rem; font-weight: 600; padding: 4px 12px; border-radius: 20px; border: 1px solid var(--border-glass); color: var(--text-dim); }
            .h5p-estado.ok { color: #22c55e; border-color: rgba(34,197,94,0.4); }
            #h5p-container { background: #fff; border-radius: 12px; overflow: hidden; min-height: 200px; }
            @media (max-width: 640px) { .h5p-wrapper { padding: 1rem; } }
        </style>
        <div class="h5p-wrapper">
            <div class="h5p-barra">
                <i class="fa-solid fa-puzzle-piece" style="color:var(--primary); font-size:1.1rem;"></i>
                <span style="color:#e2e8f0; font-weight:600; font-size:0.95rem;">Actividad interactiva</span>
                <span style="font-size:0.8rem; color:#64748b;">
                    <?= $h5p_completar ? 'Se completa al terminar la actividad' : 'Nota mínima: ' . $h5p_minimo . '%' ?>
                </span>
                <span id="h5p-estado" class="h5p-estado<?= ($h5p_resultado['aprobado'] ?? 0) ? ' ok' : '' ?>">
                    <?php if ($h5p_resultado && $h5p_resultado['aprobado']): ?>
                        <i class="fa-solid fa-circle-check"></i> Aprobado<?= $h5p_resultado['puntaje_max'] !== null ? ' · ' . $h5p_fmt($h5p_resultado['puntaje']) . '%' : '' ?>
                    <?php elseif ($h5p_resultado && $h5p_resultado['puntaje_max'] !== null): ?>
                        Mejor nota: <?= $h5p_fmt($h5p_resultado['puntaje']) ?>% · Sigue intentando
                    <?php else: ?>
                        Pendiente
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($h5p_datos): ?>
                <div id="h5p-container"></div>
            <?php else: ?>
                <div style="text-align:center; padding:4rem 1rem; color:var(--text-dim);">
                    <i class="fa-solid fa-puzzle-piece" style="font-size:3rem; opacity:0.4; display:block; margin-bottom:1rem;"></i>
                    El docente aún no ha cargado esta actividad.
                </div>
            <?php endif; ?>
        </div>
        <?php if ($h5p_datos): ?>
        <script src="../js/vendor/h5p-standalone/main.bundle.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const el = document.getElementById('h5p-container');
            const leccionId = <?= (int)$leccion_activa['id'] ?>;
            const vendor = new URL('../js/vendor/h5p-standalone/', location.href).href;
            let enviado = false;

            function pintarEstado(res) {
                const estado = document.getElementById('h5p-estado');
                const nota = res.mejor !== null && res.mejor !== undefined ? ' · ' + String(res.mejor).replace('.', ',') + '%' : '';
                estado.classList.toggle('ok', !!res.aprobado);
                estado.innerHTML = res.aprobado
                    ? '<i class="fa-solid fa-circle-check"></i> Aprobado' + nota
                    : 'Mejor nota' + nota + ' · Sigue intentando';
            }

            new H5PStandalone.H5P(el, {
                id: 'leccion-' + leccionId,
                h5pJsonPath: new URL('h5p_archivo.php/' + leccionId, location.href).href,
                frameJs: vendor + 'frame.bundle.js',
                frameCss: vendor + 'styles/h5p.css',
                frame: true,
                fullScreen: true,
                icon: false,
                export: false,
                copyright: false,
                xAPIObjectIRI: location.origin + location.pathname + '?leccion=' + leccionId
            }).then(function () {
                H5P.externalDispatcher.on('xAPI', function (event) {
                    const st = event.data && event.data.statement;
                    if (!st) return;
                    // Solo el contenido principal: las subpreguntas también emiten eventos
                    const ctx = st.context && st.context.contextActivities;
                    const ext = st.object && st.object.definition && st.object.definition.extensions;
                    if ((ctx && ctx.parent) || (ext && ext['http://h5p.org/x-api/h5p-subContentId'])) return;
                    const verbo = String((st.verb && st.verb.id) || '').split('/').pop();
                    if (!['completed', 'answered', 'passed', 'failed'].includes(verbo)) return;

                    const datos = { guardar_resultado_h5p: 1, leccion_id: leccionId, verbo: verbo };
                    const score = (st.result && st.result.score) || {};
                    if (typeof score.raw === 'number' && typeof score.max === 'number') {
                        datos.raw = score.raw;
                        datos.max = score.max;
                    }
                    $.post('clase.php?curso=<?= (int)$curso_id ?>', datos, null, 'json').done(function (res) {
                        if (!res.ok || !res.contado) return;
                        pintarEstado(res);
                        if (enviado) return;
                        enviado = true;
                        if (res.aprobado) {
                            const nota = res.pct !== null ? ` Obtuviste ${String(res.pct).replace('.', ',')}%.` : '';
                            showCustomAlert('¡Excelente!', `Actividad completada.${nota} ¡Lección completada!`, 'fa-solid fa-trophy', '#10b981', () => location.reload());
                        } else {
                            showCustomAlert('Sigue intentando', `Obtuviste ${String(res.pct).replace('.', ',')}%. Necesitas al menos ${res.minimo}% para completar la lección.`, 'fa-solid fa-circle-xmark', '#ef4444', () => { enviado = false; });
                        }
                    }).fail(function (xhr) {
                        if (xhr.status === 429) return; // evento repetido del mismo envío
                        showCustomAlert('Error', 'No se pudo guardar tu resultado. Intenta de nuevo.', 'fa-solid fa-triangle-exclamation', '#ef4444', null);
                    });
                });
            }).catch(function (err) {
                console.error(err);
                el.innerHTML = '<p style="padding:2rem; text-align:center; color:#64748b;">No se pudo cargar la actividad. Recarga la página o avisa al docente.</p>';
            });
        });
        </script>
        <?php endif; ?>
        <?php elseif($leccion_activa['tipo'] === 'arrastrar'):
            $arr = json_decode($leccion_activa['contenido'] ?? '{}', true);
            $arr_img   = $arr['imagen'] ?? '';
            $arr_zonas = $arr['zonas'] ?? [];
            $shuffled  = $arr_zonas; shuffle($shuffled);
            // Al navegador solo llega dónde está cada zona; qué etiqueta va en cuál se califica en el servidor
            $arr_zonas_publicas = array_map(fn($z) => ['id' => $z['id'], 'numero' => $z['numero']], $arr_zonas);
        ?>
        <div style="background:var(--bg-surface); margin:2rem; border-radius:20px; border:1px solid var(--border-glass); overflow:hidden;">
            <!-- Header -->
            <div style="padding:1.5rem 2rem; border-bottom:1px solid var(--border-glass); display:flex; align-items:center; gap:1rem;">
                <i class="fa-solid fa-bullseye" style="color:var(--accent); font-size:1.4rem;"></i>
                <div>
                    <h3 style="margin:0; font-family:'Poppins';">Arrastra cada etiqueta a su lugar</h3>
                    <p style="margin:0.2rem 0 0; font-size:0.8rem; color:var(--text-dim);">Haz clic en una etiqueta y luego en el número correspondiente del diagrama.</p>
                </div>
                <div id="arr-score-badge" style="margin-left:auto; display:none; background:rgba(99,102,241,0.15); border:1px solid var(--primary); border-radius:20px; padding:0.4rem 1.2rem; font-weight:700; color:var(--primary); font-size:0.9rem;"></div>
            </div>
            <!-- Label Pool -->
            <div style="padding:1.2rem 2rem; border-bottom:1px solid var(--border-glass);">
                <p style="font-size:0.75rem; color:var(--text-dim); margin:0 0 0.75rem;">Etiquetas disponibles — selecciona una y luego haz clic en un número del diagrama:</p>
                <div id="label-pool" style="display:flex; flex-wrap:wrap; gap:0.6rem; min-height:44px; background:rgba(0,0,0,0.15); padding:0.75rem; border-radius:12px; border:2px dashed rgba(255,255,255,0.08);">
                    <?php foreach($shuffled as $i => $z): // id por posición: no revela a qué zona corresponde ?>
                    <div class="arr-label" id="lbl-e<?= $i ?>" data-id="e<?= $i ?>" data-texto="<?= htmlspecialchars($z['texto']) ?>"
                         onclick="selectLabel(this)"
                         draggable="true" ondragstart="handleDragStart(event, this)"
                         style="background:rgba(99,102,241,0.15); border:2px solid rgba(99,102,241,0.4); color:#c7d2fe; padding:0.45rem 1.1rem; border-radius:20px; cursor:grab; font-size:0.83rem; font-weight:600; transition:0.2s; white-space:nowrap; user-select:none;">
                        <?= htmlspecialchars($z['texto']) ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Image + Drop Zones -->
            <div style="padding:1.5rem 2rem;">
                <div id="arr-img-wrap" style="position:relative; display:inline-block; width:100%;">
                    <?php if($arr_img): ?>
                    <img src="<?= htmlspecialchars($arr_img) ?>" style="width:100%; border-radius:12px; display:block; pointer-events:none; user-select:none;">
                    <?php else: ?>
                    <div style="background:rgba(255,255,255,0.03); border:2px dashed rgba(255,255,255,0.1); border-radius:12px; height:250px; display:flex; align-items:center; justify-content:center; color:var(--text-dim);">Sin imagen</div>
                    <?php endif; ?>
                    <?php foreach($arr_zonas as $z): ?>
                    <div class="arr-zone" id="zone-<?= $z['id'] ?>"
                         data-id="<?= htmlspecialchars($z['id']) ?>" data-num="<?= htmlspecialchars($z['numero']) ?>"
                         onclick="placeLabel(this)"
                         ondragover="event.preventDefault()" ondrop="handleDrop(event, this)"
                         style="position:absolute; left:<?= $z['x'] ?>%; top:<?= $z['y'] ?>%; transform:translate(-50%,-50%); min-width:30px; height:30px; background:rgba(99,102,241,0.85); border-radius:15px; display:flex; align-items:center; justify-content:center; color:white; font-weight:700; font-size:0.75rem; border:2px solid rgba(255,255,255,0.5); cursor:pointer; z-index:10; padding:0 8px; transition:0.2s; white-space:nowrap;">
                        <?= $z['numero'] ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <!-- Botones -->
                <div style="display:flex; gap:1rem; margin-top:1.5rem; align-items:center;">
                    <button onclick="verificarArrastrar(<?= $leccion_activa['id'] ?>)" class="btn-complete" style="background:var(--primary);">
                        <i class="fa-solid fa-check-double"></i> Verificar respuestas
                    </button>
                    <button onclick="reiniciarArrastrar()" class="btn-complete" style="background:rgba(255,255,255,0.05); color:white; font-size:0.85rem;">
                        <i class="fa-solid fa-rotate-left"></i> Reiniciar
                    </button>
                </div>
            </div>
        </div>
        <!-- Datos para JS -->
        <script>
        const arrZonas = <?= json_encode($arr_zonas_publicas) ?>;
        let arrSelected = null;  // etiqueta actualmente seleccionada
        const arrPlacements = {}; // { zoneId: { id, texto } }

        function handleDragStart(e, el) {
            if (el.dataset.placed === 'true') { e.preventDefault(); return; }
            e.dataTransfer.setData('text/plain', el.id);
            arrSelected = el;
            el.style.opacity = '0.5';
        }

        function handleDrop(e, zone) {
            e.preventDefault();
            const id = e.dataTransfer.getData('text/plain');
            const el = document.getElementById(id);
            if (el) {
                el.style.opacity = '1';
                arrSelected = el;
                placeLabel(zone);
            }
        }

        document.addEventListener('dragend', (e) => {
            if (arrSelected) arrSelected.style.opacity = '1';
        });

        function selectLabel(el) {
            if (el.dataset.placed === 'true') return;
            document.querySelectorAll('.arr-label').forEach(l => l.style.cssText = l.style.cssText.replace('box-shadow:0 0 0 3px #818cf8;',''));
            if (arrSelected === el) { arrSelected = null; return; }
            arrSelected = el;
            el.style.boxShadow = '0 0 0 3px #818cf8';
        }

        function placeLabel(zone) {
            const zId = zone.dataset.id;
            if (!arrSelected) {
                // Clic en zona con etiqueta → devolver al pool
                if (arrPlacements[zId]) {
                    const prev = arrPlacements[zId];
                    const lbl = document.getElementById('lbl-' + prev.id);
                    if (lbl) { lbl.style.display = ''; lbl.dataset.placed = 'false'; }
                    delete arrPlacements[zId];
                    const num = zone.dataset.num;
                    zone.innerHTML = num;
                    zone.style.background = 'rgba(99,102,241,0.85)';
                    zone.style.padding = '0 8px';
                    zone.style.borderRadius = '15px';
                }
                return;
            }
            // Devolver etiqueta previa de esta zona si la hay
            if (arrPlacements[zId]) {
                const prev = arrPlacements[zId];
                const lbl = document.getElementById('lbl-' + prev.id);
                if (lbl) { lbl.style.display = ''; lbl.dataset.placed = 'false'; }
            }
            // Devolver etiqueta seleccionada a su zona anterior si venía de otra
            for (const [zz, placed] of Object.entries(arrPlacements)) {
                if (placed.id === arrSelected.dataset.id) {
                    const oldZone = document.getElementById('zone-' + zz);
                    if (oldZone) { oldZone.innerHTML = oldZone.dataset.num; oldZone.style.background='rgba(99,102,241,0.85)'; oldZone.style.borderRadius='15px'; }
                    delete arrPlacements[zz];
                    break;
                }
            }
            // Colocar
            arrPlacements[zId] = { id: arrSelected.dataset.id, texto: arrSelected.dataset.texto };
            arrSelected.style.display = 'none';
            arrSelected.dataset.placed = 'true';
            const numSpan = document.createElement('span');
            numSpan.style.cssText = 'font-size:0.65rem;opacity:0.7;margin-right:3px;';
            numSpan.textContent = zone.dataset.num;
            zone.replaceChildren(numSpan, document.createTextNode(arrSelected.dataset.texto));
            zone.style.background = 'rgba(99,102,241,0.9)';
            zone.style.borderRadius = '20px';
            zone.style.padding = '0 10px';
            arrSelected.style.boxShadow = '';
            arrSelected = null;
        }

        function reiniciarArrastrar() {
            document.querySelectorAll('.arr-label').forEach(l => { l.style.display = ''; l.dataset.placed = 'false'; l.style.background='rgba(99,102,241,0.15)'; l.style.borderColor='rgba(99,102,241,0.4)'; });
            arrZonas.forEach(z => {
                const zone = document.getElementById('zone-' + z.id);
                if (zone) { zone.innerHTML = z.numero; zone.style.background='rgba(99,102,241,0.85)'; zone.style.borderRadius='15px'; zone.style.padding='0 8px'; }
            });
            for (const k in arrPlacements) delete arrPlacements[k];
            arrSelected = null;
            document.getElementById('arr-score-badge').style.display = 'none';
        }

        // La calificación la hace el servidor: se envía solo qué texto quedó en cada zona
        function verificarArrastrar(leccionId) {
            const colocaciones = {};
            for (const [zId, placed] of Object.entries(arrPlacements)) colocaciones[zId] = placed.texto;

            $.post('clase.php?curso=<?= $curso_id ?>', {
                verificar_arrastrar: 1, leccion_id: leccionId, colocaciones: JSON.stringify(colocaciones)
            }, null, 'json').done(res => {
                arrZonas.forEach(z => {
                    const zone = document.getElementById('zone-' + z.id);
                    if (!zone) return;
                    const ok = res.zonas && res.zonas[z.id];
                    zone.style.background = ok ? '#065f46' : '#7f1d1d';
                    zone.style.border = ok ? '2px solid #10b981' : '2px solid #ef4444';
                });
                const badge = document.getElementById('arr-score-badge');
                badge.style.display = 'block';
                badge.textContent = `${res.pct}% (${res.correctas}/${res.total})`;
                badge.style.borderColor = res.aprobado ? '#10b981' : '#ef4444';
                badge.style.color = res.aprobado ? '#10b981' : '#ef4444';
                if (res.aprobado) {
                    showCustomAlert('¡Excelente!', `Has colocado ${res.correctas} de ${res.total} correctamente. (${res.pct}%) ¡Lección completada!`, 'fa-solid fa-trophy', '#10b981', () => location.reload());
                } else {
                    showCustomAlert('Sigue intentando', `${res.correctas} de ${res.total} correctas. Necesitas al menos ${res.minimo}% para completar.`, 'fa-solid fa-circle-xmark', '#ef4444', null);
                }
            }).fail(() => {
                showCustomAlert('Error', 'No se pudo verificar el ejercicio. Intenta de nuevo.', 'fa-solid fa-triangle-exclamation', '#ef4444', null);
            });
        }
        </script>
        <?php elseif($leccion_activa['tipo'] === 'excel_interactivo'):
            $ex = json_decode($leccion_activa['contenido'] ?? '{}', true);
            $ex_instrucciones  = htmlspecialchars($ex['instrucciones'] ?? '');
            $ex_filas          = intval($ex['filas'] ?? 5);
            $ex_cols           = intval($ex['columnas'] ?? 5);
            $ex_fijas          = $ex['celdas_fijas'] ?? [];
            $ex_evaluables     = $ex['celdas_evaluables'] ?? [];
            // Al navegador solo llega la pista de cada celda; lo esperado se compara en el servidor
            $ex_evaluables_publicas = array_map(fn($cfg) => ['pista' => $cfg['pista'] ?? ''], $ex_evaluables);
            $col_ltrs          = ['A','B','C','D','E','F','G','H','I','J'];
        ?>
        <style>
            .ex-table { border-collapse: collapse; width: 100%; }
            .ex-table th { background: #1e2a3a; color: #94a3b8; font-size: 0.72rem; padding: 5px 10px; border: 1px solid rgba(255,255,255,0.06); text-align: center; }
            .ex-table td { border: 1px solid rgba(255,255,255,0.06); min-width: 90px; height: 34px; vertical-align: middle; }
            .ex-cell-hdr { background: #1e2a3a; color: #94a3b8; font-size: 0.7rem; text-align: center; width: 36px; }
            .ex-cell-fixed { background: rgba(30,64,175,0.15); color: #93c5fd; font-size: 0.85rem; text-align: center; padding: 4px 8px; font-weight: 600; }
            .ex-cell-eval input {
                width: 100%; height: 100%; background: rgba(34,197,94,0.08); border: 2px solid rgba(34,197,94,0.35);
                color: #86efac; font-family: 'Courier New', monospace; font-size: 0.83rem;
                padding: 4px 8px; outline: none; transition: 0.2s;
            }
            .ex-cell-eval input:focus { border-color: #22c55e; background: rgba(34,197,94,0.15); box-shadow: 0 0 0 3px rgba(34,197,94,0.1); }
            .ex-cell-eval.correct input { border-color: #22c55e; background: rgba(34,197,94,0.2); }
            .ex-cell-eval.wrong   input { border-color: #ef4444; background: rgba(239,68,68,0.1); color: #fca5a5; }
            .ex-cell-empty { background: transparent; }
            .ex-formula-bar { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; padding: 6px 12px;
                font-family: monospace; font-size: 0.85rem; color: #94a3b8; margin-bottom: 0.5rem; min-height: 32px; }
        </style>
        <div style="background:var(--bg-surface); margin:2rem; border-radius:20px; border:1px solid var(--border-glass); overflow:hidden;">
            <!-- Header -->
            <div style="padding:1.2rem 2rem; border-bottom:1px solid var(--border-glass); display:flex; align-items:center; gap:1rem;">
                <i class="fa-solid fa-table-cells" style="color:#22c55e; font-size:1.3rem;"></i>
                <div>
                    <h3 style="margin:0; font-family:'Poppins'; font-size:1.1rem;">Ejercicio de Hoja de Cálculo</h3>
                    <p style="margin:0.15rem 0 0; font-size:0.78rem; color:var(--text-dim);">Completa las celdas verdes — puedes usar fórmulas como en Excel</p>
                </div>
                <div id="ex-score-badge" style="margin-left:auto; display:none; padding:0.4rem 1rem; border-radius:20px; font-weight:700; font-size:0.88rem; border:1px solid #22c55e; color:#22c55e;"></div>
            </div>
            <!-- Instructions -->
            <?php if($ex_instrucciones): ?>
            <div style="padding:0.75rem 2rem; background:rgba(99,102,241,0.06); border-bottom:1px solid var(--border-glass); display:flex; align-items:flex-start; gap:0.75rem;">
                <i class="fa-solid fa-circle-info" style="color:var(--primary); margin-top:2px;"></i>
                <p style="margin:0; font-size:0.875rem; color:#c7d2fe; line-height:1.6;"><?= $ex_instrucciones ?></p>
            </div>
            <?php endif; ?>
            <!-- Formula bar -->
            <div style="padding:0.6rem 2rem; border-bottom:1px solid var(--border-glass);">
                <div style="display:flex; align-items:center; gap:0.75rem;">
                    <span id="ex-active-ref" style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.08); border-radius:6px; padding:4px 12px; font-family:monospace; font-size:0.8rem; color:#94a3b8; min-width:48px; text-align:center;">—</span>
                    <div class="ex-formula-bar" id="ex-formula-bar" style="flex:1;">Selecciona una celda</div>
                </div>
            </div>
            <!-- Legend -->
            <div style="padding:0.5rem 2rem; display:flex; gap:1.5rem; font-size:0.72rem; color:var(--text-dim); border-bottom:1px solid var(--border-glass);">
                <span><span style="display:inline-block;width:10px;height:10px;background:rgba(30,64,175,0.3);border:1px solid #3b82f6;border-radius:2px;"></span> Celda fija (dato)</span>
                <span><span style="display:inline-block;width:10px;height:10px;background:rgba(34,197,94,0.15);border:2px solid rgba(34,197,94,0.4);border-radius:2px;"></span> Celda a completar</span>
            </div>
            <!-- Grid -->
            <div style="padding:1rem 2rem; overflow-x:auto;">
                <table class="ex-table" id="ex-grid">
                    <thead>
                        <tr>
                            <th class="ex-table" style="background:#1e2a3a; width:36px;"></th>
                            <?php for($c=0; $c<$ex_cols; $c++): ?>
                            <th><?= $col_ltrs[$c] ?></th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for($r=1; $r<=$ex_filas; $r++): ?>
                        <tr>
                            <td class="ex-cell-hdr"><?= $r ?></td>
                            <?php for($c=0; $c<$ex_cols; $c++):
                                $ref = $col_ltrs[$c] . $r;
                                $esFija = isset($ex_fijas[$ref]);
                                $esEval = isset($ex_evaluables[$ref]);
                                $pista  = $esEval ? ($ex_evaluables[$ref]['pista'] ?? '') : '';
                            ?>
                            <?php if($esFija): ?>
                            <td class="ex-cell-fixed" data-ref="<?= $ref ?>"><?= htmlspecialchars($ex_fijas[$ref]['valor'] ?? '') ?></td>
                            <?php elseif($esEval): ?>
                            <td class="ex-cell-eval" id="td-<?= $ref ?>" data-ref="<?= $ref ?>" title="<?= $pista ? 'Pista: '.$pista : '' ?>">
                                <input type="text" id="cell-<?= $ref ?>" data-ref="<?= $ref ?>"
                                       placeholder="<?= $pista ? htmlspecialchars($pista) : '=' ?>"
                                       oninput="exOnInput(this)" onfocus="exOnFocus(this)">
                            </td>
                            <?php else: ?>
                            <td class="ex-cell-empty" data-ref="<?= $ref ?>"></td>
                            <?php endif; ?>
                            <?php endfor; ?>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <!-- Buttons -->
            <div style="padding:1rem 2rem 1.5rem; display:flex; gap:1rem; align-items:center; flex-wrap:wrap;">
                <button onclick="exVerificar(<?= $leccion_activa['id'] ?>)" class="btn-complete" style="background:#22c55e;">
                    <i class="fa-solid fa-check-double"></i> Verificar respuestas
                </button>
                <button onclick="exReiniciar()" class="btn-complete" style="background:rgba(255,255,255,0.05); color:white;">
                    <i class="fa-solid fa-rotate-left"></i> Reiniciar
                </button>
                <span id="ex-hint-text" style="font-size:0.78rem; color:var(--text-dim);"></span>
            </div>
        </div>
        <!-- JS data & logic -->
        <script>
        const EX_FIJAS      = <?= json_encode($ex_fijas) ?>;
        const EX_EVALUABLES = <?= json_encode($ex_evaluables_publicas) ?>;
        const EX_FILAS      = <?= $ex_filas ?>;
        const EX_COLS       = <?= $ex_cols ?>;
        const EX_COL_LTRS   = <?= json_encode(array_slice($col_ltrs, 0, $ex_cols)) ?>;

        // Build initial sheet data for HyperFormula
        function buildSheetData() {
            const rows = [];
            for (let r = 1; r <= EX_FILAS; r++) {
                const row = [];
                for (let ci = 0; ci < EX_COLS; ci++) {
                    const ref = EX_COL_LTRS[ci] + r;
                    if (EX_FIJAS[ref] !== undefined) {
                        const v = EX_FIJAS[ref].valor;
                        row.push(isNaN(v) || v === '' ? v : Number(v));
                    } else if (EX_EVALUABLES[ref] !== undefined) {
                        const inp = document.getElementById('cell-' + ref);
                        row.push(inp ? inp.value.trim() || '' : '');
                    } else {
                        row.push('');
                    }
                }
                rows.push(row);
            }
            return rows;
        }

        function getHF() {
            const sheetData = buildSheetData();
            const hf = HyperFormula.buildFromArray(sheetData, { licenseKey: 'gpl-v3' });
            return hf;
        }

        function refToCoords(ref) {
            const col = EX_COL_LTRS.indexOf(ref[0]);
            const row = parseInt(ref.slice(1)) - 1;
            return { row, col };
        }

        function exOnFocus(inp) {
            const ref = inp.dataset.ref;
            document.getElementById('ex-active-ref').textContent = ref;
            document.getElementById('ex-formula-bar').textContent = inp.value || '';
            // Show hint
            const pista = EX_EVALUABLES[ref]?.pista || '';
            document.getElementById('ex-hint-text').textContent = pista ? '💡 ' + pista : '';
        }

        function exOnInput(inp) {
            document.getElementById('ex-formula-bar').textContent = inp.value;
            // Clear result state
            const td = inp.closest('td');
            td.classList.remove('correct','wrong');
        }

        function exReiniciar() {
            document.querySelectorAll('.ex-cell-eval input').forEach(inp => {
                inp.value = '';
                inp.closest('td').classList.remove('correct','wrong');
            });
            document.getElementById('ex-score-badge').style.display = 'none';
            document.getElementById('ex-formula-bar').textContent = 'Selecciona una celda';
            document.getElementById('ex-active-ref').textContent = '—';
            document.getElementById('ex-hint-text').textContent = '';
        }

        // HyperFormula calcula el valor de cada celda; el servidor lo compara con lo esperado,
        // calcula el puntaje y, si aprueba, registra el progreso
        function exVerificar(leccionId) {
            const hf = getHF();
            const celdas = {};

            for (const ref of Object.keys(EX_EVALUABLES)) {
                const inp = document.getElementById('cell-' + ref);
                if (!inp) continue;
                const entrada = inp.value.trim();
                let valor = '';
                if (entrada) {
                    const coords = refToCoords(ref);
                    try {
                        const cellVal = hf.getCellValue({ sheet: 0, row: coords.row, col: coords.col });
                        valor = (cellVal !== null && cellVal !== undefined) ? cellVal : '';
                    } catch(e) { valor = ''; }
                    if (typeof valor === 'object') valor = '';  // errores de fórmula (#DIV/0!, etc.)
                }
                celdas[ref] = { entrada, valor };
            }

            const fd = new FormData();
            fd.append('guardar_resultado_excel', '1');
            fd.append('leccion_id', leccionId);
            fd.append('celdas', JSON.stringify(celdas));

            fetch('clase.php?curso=<?= $curso_id ?>', {
                method: 'POST',
                headers: { 'X-CSRF-Token': window.CSRF_TOKEN },
                body: fd
            }).then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(res => {
                for (const ref of Object.keys(EX_EVALUABLES)) {
                    const td = document.getElementById('td-' + ref);
                    if (!td) continue;
                    const ok = !!(res.celdas && res.celdas[ref]);
                    td.classList.toggle('correct', ok);
                    td.classList.toggle('wrong', !ok);
                }
                const badge = document.getElementById('ex-score-badge');
                badge.style.display = 'block';
                badge.textContent   = `${res.pct}% (${res.correctas}/${res.total})`;
                badge.style.borderColor = res.aprobado ? '#22c55e' : '#ef4444';
                badge.style.color       = res.aprobado ? '#22c55e' : '#ef4444';
                if (res.aprobado) {
                    showCustomAlert('¡Excelente trabajo!', `Has acertado ${res.correctas} de ${res.total} celdas (${res.pct}%). ¡Lección completada!`, 'fa-solid fa-trophy', '#22c55e', null);
                } else {
                    showCustomAlert('Sigue intentando', `${res.correctas} de ${res.total} celdas correctas (${res.pct}%). Necesitas al menos ${res.minimo}%.`, 'fa-solid fa-circle-xmark', '#ef4444', null);
                }
            }).catch(() => {
                showCustomAlert('Error', 'No se pudo verificar el ejercicio. Intenta de nuevo.', 'fa-solid fa-triangle-exclamation', '#ef4444', null);
            });
        }
        </script>
        <?php else: ?>

        <div class="player-wrapper">
            <?php if($leccion_activa['tipo']==='pdf'): 
                $pdf_url = strip_tags($leccion_activa['contenido']);
                if (strpos($pdf_url, 'drive.google.com/file/d/') !== false) {
                    $pdf_url = str_replace(['/view', '?usp=sharing'], ['/preview', ''], $pdf_url);
                }
            ?>
                  <iframe src="<?= htmlspecialchars($pdf_url) ?>" style="width:100%; height:100%; border:none;"></iframe>
              <?php else: ?>
                  <div style="height:100%; display:flex; align-items:center; justify-content:center; background:#111827; flex-direction:column; gap:1rem;">
                      <i class="fa-solid fa-vial" style="font-size:8rem; color:var(--primary); opacity:0.3;"></i>
                  </div>
              <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="lesson-body">
            <div class="lesson-header">
                <div class="lesson-title">
                    <h1><?= htmlspecialchars($leccion_activa['titulo']) ?></h1>
                    <div class="lesson-meta">
                        <span><i class="fa-regular fa-clock"></i> <?= htmlspecialchars((string)$leccion_activa['duracion']) ?></span>
                        <span><i class="fa-solid fa-bookmark"></i> <?= ucfirst($leccion_activa['tipo']) ?></span>
                        <span style="color: var(--10b981);"><i class="fa-solid fa-circle-check"></i> <?= $leccion_activa['completada'] ? 'Completada' : 'Pendiente' ?></span>
                    </div>
                </div>
                <?php if(!$leccion_activa['completada'] && in_array($leccion_activa['tipo'], TIPOS_LECCION_CONTENIDO, true)): ?>
                <button onclick="marcarCompletada(<?= $leccion_activa['id'] ?>)" class="btn-complete">
                    <i class="fa-solid fa-check-double"></i>
                    Completar lección
                </button>
                <?php endif; ?>
            </div>

            <nav class="tabs-nav">
                <div class="tab-trigger active" onclick="switchTab(event, 'tab-overview')">Resumen</div>
                <div class="tab-trigger" onclick="switchTab(event, 'tab-resources')">Recursos</div>
                <div class="tab-trigger" onclick="switchTab(event, 'tab-notes')">Apuntes</div>
                <div class="tab-trigger" onclick="switchTab(event, 'tab-discuss')">Discusión</div>
            </nav>

            <!-- Tab: Overview -->
            <div id="tab-overview" class="tab-content active">
                <div style="background: var(--bg-glass); padding: 2rem; border-radius: 20px; border: 1px solid var(--border-glass);">
                    
                    <?php
                    // El certificado se emite al completar el curso (con pago verificado si tiene
                    // precio, del curso o de una ruta que lo incluya) y se conserva después
                    require_once '../helpers/certificado_helper.php';
                    $certificado_curso = $pct >= 100
                        ? certificado_emitir($pdo, 'curso', (int)$usuario_id, (int)$curso_id)
                        : certificado_emitido($pdo, 'curso', (int)$usuario_id, (int)$curso_id);
                    if($pct >= 100 || $certificado_curso):
                        $tiene_pago_verificado = (bool)$certificado_curso;
                    ?>
                    <div style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(99, 102, 241, 0.2)); border: 1px solid var(--primary); padding: 2.5rem; border-radius: 20px; text-align: center; margin-bottom: 2.5rem; animation: pulse 2s infinite;">
                        <i class="fa-solid fa-trophy" style="font-size: 4rem; color: var(--accent); margin-bottom: 1.5rem; display: block;"></i>
                        <h2 style="font-family: 'Poppins'; margin-bottom: 0.5rem;">¡Felicidades, lo lograste!</h2>
                        <p style="color: var(--text-dim); margin-bottom: 2rem;">Has completado el 100% de este curso. Tu esfuerzo y dedicación han dado frutos.</p>
                        
                        <?php if($tiene_pago_verificado): ?>
                        <a href="certificado?curso=<?= $curso_id ?>" class="btn-complete" style="display: inline-flex; width: auto; margin: 0 auto; background: var(--accent);">
                            <i class="fa-solid fa-download"></i> Descargar mi Certificado
                        </a>
                        <?php else: ?>
                        <button class="btn-complete" style="display: inline-flex; width: auto; margin: 0 auto; background: #64748b; cursor: not-allowed;" disabled>
                            <i class="fa-solid fa-clock"></i> Certificado en Verificación
                        </button>
                        <p style="margin-top:1rem; font-size:0.85rem; color:var(--text-dim);">Tu pago o inscripción está siendo verificada. El certificado estará disponible pronto.</p>
                        <?php endif; ?>
                    </div>
                    <style>
                        @keyframes pulse {
                            0% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.4); }
                            70% { box-shadow: 0 0 0 15px rgba(99, 102, 241, 0); }
                            100% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0); }
                        }
                    </style>
                    <?php endif; ?>

                    <h3 style="margin-bottom: 1.5rem; color: var(--primary);">Objetivos del módulo</h3>
                    <div style="color: var(--text-dim); line-height: 1.6;">
                        <?php
                        // Los objetivos vienen del editor Quill (HTML); los antiguos pueden ser texto plano
                        if (extracto_texto($objetivos_modulo) === '') {
                            echo 'El docente no ha definido objetivos específicos para este módulo.';
                        } elseif ($objetivos_modulo !== strip_tags($objetivos_modulo)) {
                            echo limpiar_html($objetivos_modulo);
                        } else {
                            echo nl2br(htmlspecialchars($objetivos_modulo, ENT_QUOTES, 'UTF-8'));
                        }
                        ?>
                    </div>

                    <div style="margin-top: 2.5rem; padding-top: 2.5rem; border-top: 1px solid var(--border-glass);">
                        <span style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; color: var(--accent); font-weight: 700;">Mensaje Motivacional</span>
                        <p style="font-size: 1.1rem; color: var(--text-main); font-style: italic; margin-top: 0.5rem;">
                            "<?= $leccion_activa['descripcion'] ? htmlspecialchars($leccion_activa['descripcion']) : 'El éxito es la suma de pequeños esfuerzos repetidos día tras día.' ?>"
                        </p>
                    </div>
                    

                </div>
            </div>

            <!-- Tab: Resources -->
            <div id="tab-resources" class="tab-content">
                <?php if(!empty($leccion_activa['recurso_url'])): ?>
                <div class="resource-card" style="background:var(--bg-glass); border:1px solid var(--border-glass); padding:1.5rem; border-radius:16px; display:flex; justify-content:space-between; align-items:center;">
                    <div style="display: flex; gap: 1.5rem; align-items: center;">
                        <div style="width:50px; height:50px; border-radius:12px; background:rgba(248, 113, 113, 0.1); display:flex; align-items:center; justify-content:center;">
                            <i class="fa-solid fa-file-arrow-down" style="font-size: 1.8rem; color: #f87171;"></i>
                        </div>
                        <div>
                            <strong style="display: block; font-size:1.1rem;"><?= htmlspecialchars($leccion_activa['recurso_titulo'] ?: 'Material de la lección') ?></strong>
                            <small style="color: var(--text-dim);">Recurso adjunto por el docente</small>
                        </div>
                    </div>
                    <a href="<?= htmlspecialchars($leccion_activa['recurso_url']) ?>" target="_blank" class="btn-complete" style="padding: 10px 20px; font-size: 0.85rem; background:var(--primary); width:auto; text-decoration:none;"><i class="fa-solid fa-download"></i> Descargar</a>
                </div>
                <?php elseif($leccion_activa['tipo'] === 'pdf'): ?>
                <div class="resource-card" style="background:var(--bg-glass); border:1px solid var(--border-glass); padding:1.5rem; border-radius:16px; display:flex; justify-content:space-between; align-items:center;">
                    <div style="display: flex; gap: 1.5rem; align-items: center;">
                        <i class="fa-solid fa-file-pdf" style="font-size: 2.5rem; color: #f87171;"></i>
                        <div>
                            <strong style="display: block;">Documento PDF de la Clase</strong>
                            <small style="color: var(--text-dim);">Material principal de esta lección</small>
                        </div>
                    </div>
                    <a href="<?= htmlspecialchars(url_segura($leccion_activa['contenido'])) ?>" target="_blank" rel="noopener noreferrer" class="btn-complete" style="padding: 10px 20px; font-size: 0.8rem; width:auto;">Abrir PDF</a>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 4rem; background:rgba(255,255,255,0.02); border-radius:20px; border:1px dashed var(--border-glass);">
                    <i class="fa-solid fa-folder-open" style="font-size:3rem; color:var(--text-dim); opacity:0.5; margin-bottom:1rem; display:block;"></i>
                    <p style="color: var(--text-dim);">No hay recursos descargables adicionales adjuntos a esta lección.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tab: Apuntes -->
            <div id="tab-notes" class="tab-content">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <span style="font-size: 0.8rem; color: var(--text-dim);">Tus apuntes se guardan automáticamente en tu perfil.</span>
                    <span id="saveStatus" style="font-size: 0.8rem; color: var(--primary); opacity: 0;">Guardado <i class="fa-solid fa-check"></i></span>
                </div>
                <textarea id="userNotes" placeholder="Escribe aquí tus apuntes personales sobre esta lección... " style="width: 100%; height: 350px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); border-radius: 20px; color: white; padding: 2rem; font-family: inherit; font-size: 1.1rem; line-height: 1.6; outline: none; transition: 0.3s;"><?= htmlspecialchars($apunte_existente) ?></textarea>
            </div>

            <!-- Tab: Discuss -->
            <div id="tab-discuss" class="tab-content">
                <div style="background:var(--bg-glass); border:1px solid var(--border-glass); border-radius:20px; padding:2rem;">
                    <h4 style="margin-bottom:1.5rem; color:white; font-size:1.1rem;">Comunidad de Aprendizaje</h4>
                    
                    <div style="display:flex; gap:1rem; margin-bottom:3rem;">
                        <div style="width:45px; height:45px; border-radius:50%; background:var(--primary); color:white; display:flex; align-items:center; justify-content:center; font-weight:bold; flex-shrink:0;">
                            <?= htmlspecialchars(mb_substr($_SESSION['user_name'] ?? '', 0, 1)) ?>
                        </div>
                        <div style="flex:1;">
                            <textarea id="nuevoComentario" placeholder="¿Tienes alguna duda o quieres compartir algo sobre la clase?" style="width:100%; background:rgba(0,0,0,0.2); border:1px solid var(--border-glass); border-radius:12px; color:white; padding:1rem; font-family:inherit; resize:vertical; min-height:80px; margin-bottom:1rem; outline:none;"></textarea>
                            <button onclick="enviarComentario()" class="btn-complete" style="width:auto; padding:0.6rem 1.5rem; border-radius:8px; font-size:0.9rem;"><i class="fa-solid fa-paper-plane"></i> Publicar comentario</button>
                        </div>
                    </div>

                    <div id="listaComentarios">
                        <?php if(empty($comentarios)): ?>
                        <p style="color:var(--text-dim); text-align:center; padding:2rem;">Sé el primero en iniciar la conversación.</p>
                        <?php else: ?>
                            <?php foreach($comentarios as $c): ?>
                            <div style="display:flex; gap:1rem; margin-bottom:1.5rem; padding-bottom:1.5rem; border-bottom:1px solid rgba(255,255,255,0.05);">
                                <div style="width:40px; height:40px; border-radius:50%; background:#374151; color:white; display:flex; align-items:center; justify-content:center; font-weight:bold; flex-shrink:0;">
                                    <?= htmlspecialchars(mb_substr($c['nombre'] ?? '', 0, 1)) ?>
                                </div>
                                <div>
                                    <div style="margin-bottom:0.3rem;">
                                        <strong style="color:#e5e7eb; font-size:0.95rem;"><?= htmlspecialchars($c['nombre']) ?></strong>
                                        <span style="color:var(--text-dim); font-size:0.75rem; margin-left:0.5rem;"><?= date('d M, Y', strtotime($c['fecha'])) ?></span>
                                    </div>
                                    <p style="color:#d1d5db; font-size:0.9rem; line-height:1.5; margin:0;"><?= nl2br(htmlspecialchars($c['comentario'])) ?></p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Instructor Card -->
            <div class="instructor-card">
                <?php // Sin foto (o el archivo ya no existe): avatar con las iniciales del instructor ?>
                <?php if (imagen_disponible($curso['instructor_foto'])): ?>
                <img src="../<?= htmlspecialchars($curso['instructor_foto'], ENT_QUOTES, 'UTF-8') ?>" class="instructor-avatar" alt="Instructor">
                <?php else: $iniciales = iniciales_nombre($curso['instructor_nombre']); ?>
                <div class="instructor-avatar instructor-avatar-iniciales" role="img" aria-label="Instructor">
                    <?= $iniciales !== '' ? htmlspecialchars($iniciales, ENT_QUOTES, 'UTF-8') : '<i class="fa-solid fa-user"></i>' ?>
                </div>
                <?php endif; ?>
                <div>
                    <h4 style="font-size: 1.2rem; margin-bottom: 0.4rem;"><?= htmlspecialchars($curso['instructor_nombre'] ?: 'Equipo ' . ACADEMY_NAME) ?></h4>
                    <p style="color: var(--text-dim); font-size: 0.9rem; line-height: 1.6;"><?= htmlspecialchars($curso['instructor_bio'] ?: 'Experto certificado con amplia trayectoria en la industria, enfocado en impartir conocimientos prácticos y efectivos.') ?></p>
                </div>
            </div>
        </div>
    </main>

    <aside class="navigator">
        <div class="navigator-header">
            <h3>Contenido del Curso</h3>
            <div class="progress-container">
                <div class="progress-bar" style="width: <?= $pct ?>%"></div>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.75rem; color: var(--text-dim);">
                <span><?= $completadas ?> / <?= $total_lecciones ?> completadas</span>
                <span><?= $pct ?>%</span>
            </div>
        </div>

        <div class="module-list">
            <?php foreach($modulos as $m): 
                $is_expanded = ($m['id'] == $leccion_activa['modulo_id']);
            ?>
            <div class="module-group <?= $is_expanded ? 'expanded' : '' ?>">
                <div class="module-header" onclick="this.parentElement.classList.toggle('expanded')">
                    <div class="module-info">
                        <strong><?= htmlspecialchars($m['titulo']) ?></strong>
                        <span><?= count($m['lecciones']) ?> Lecciones • <?= $m['progreso'] ?>%</span>
                    </div>
                    <i class="fa-solid fa-chevron-down chevron"></i>
                </div>
                <div class="lesson-items">
                    <?php foreach($m['lecciones'] as $l): ?>
                    <a href="clase?curso=<?= $curso_id ?>&leccion=<?= $l['id'] ?>" class="lesson-item <?= $l['id']==$leccion_id?'active':'' ?> <?= $l['completada']?'completed':'' ?>">
                        <div class="status-icon">
                            <?php if($l['completada']): ?>
                                <i class="fa-solid fa-check"></i>
                            <?php else: ?>
                                <i class="fa-solid fa-play" style="font-size: 0.5rem; margin-left: 1px;"></i>
                            <?php endif; ?>
                        </div>
                        <div style="flex: 1;">
                            <?= htmlspecialchars($l['titulo']) ?>
                            <div style="font-size: 0.7rem; opacity: 0.6; margin-top: 2px;"><?= htmlspecialchars((string)$l['duracion']) ?></div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </aside>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // Token CSRF para todas las peticiones AJAX de esta página
    window.CSRF_TOKEN = <?= json_encode(get_csrf_token()) ?>;
    $.ajaxSetup({ headers: { 'X-CSRF-Token': window.CSRF_TOKEN } });
</script>
<script>
    function switchTab(e, tabId) {
        $('.tab-trigger').removeClass('active');
        $(e.target).addClass('active');
        $('.tab-content').removeClass('active');
        $('#' + tabId).addClass('active');
    }

    function marcarCompletada(lid) {
        $.post('clase.php?curso=<?= $curso_id ?>', { marcar_completada: 1, leccion_id: lid }, function(res) {
            location.reload();
        });
    }

    // Auto-save apuntes a la DB
    let timer;
    $('#userNotes').on('input', function() {
        const contenido = $(this).val();
        $('#saveStatus').css('opacity', 0.5).html('Escribiendo...');
        
        clearTimeout(timer);
        timer = setTimeout(function() {
            $.post('clase.php?curso=<?= $curso_id ?>', { 
                guardar_apuntes: 1, 
                leccion_id: '<?= $leccion_id ?>', 
                contenido: contenido 
            }, function(res) {
                $('#saveStatus').css('opacity', 1).html('Guardado en la nube <i class="fa-solid fa-cloud-check"></i>');
                setTimeout(() => $('#saveStatus').css('opacity', 0), 2000);
            });
        }, 1000); // Guardar 1 segundo después de dejar de escribir
    });

    function showCustomAlert(title, message, iconClass, colorHex, callback) {
        const overlay = $('<div style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); z-index:9999; display:flex; align-items:center; justify-content:center; opacity:0; transition:0.3s;"></div>');
        const modal = $('<div style="background:var(--bg-surface); border:1px solid var(--border-glass); padding:2.5rem; border-radius:24px; text-align:center; max-width:400px; transform:translateY(20px); transition:0.3s; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);"></div>');
        
        modal.append(`<i class="${iconClass}" style="font-size:3.5rem; color:${colorHex}; margin-bottom:1.5rem;"></i>`);
        modal.append(`<h3 style="font-family:\'Poppins\'; margin-bottom:1rem; font-size:1.4rem;">${title}</h3>`);
        modal.append(`<p style="color:var(--text-dim); margin-bottom:2rem; line-height:1.6;">${message}</p>`);
        
        const btn = $(`<button class="btn-complete" style="width:100%; justify-content:center; background:${colorHex}; border:none;">Aceptar</button>`);
        btn.on('click', function() {
            overlay.css('opacity', '0');
            modal.css('transform', 'translateY(20px)');
            setTimeout(() => {
                overlay.remove();
                if(callback) callback();
            }, 300);
        });
        
        modal.append(btn);
        overlay.append(modal);
        $('body').append(overlay);
        
        setTimeout(() => {
            overlay.css('opacity', '1');
            modal.css('transform', 'translateY(0)');
        }, 10);
    }

    function enviarComentario() {
        const txt = $('#nuevoComentario').val().trim();
        if(txt === '') return;
        
        $.post('', { enviar_comentario: 1, leccion_id: <?= $leccion_id ?>, comentario: txt }, function(res) {
            if(res.trim() === 'ok') {
                location.reload();
            } else {
                showCustomAlert('Error', 'Error al publicar el comentario.', 'fa-solid fa-triangle-exclamation', '#ef4444');
            }
        });
    }

    const totalQuizSteps = <?= count($quiz_preguntas) ?>;
    function navQuiz(stepIndex) {
        $('.quiz-step').hide();
        $('#quiz-step-' + stepIndex).fadeIn(300);
        
        let pct = ((stepIndex + 1) / totalQuizSteps) * 100;
        $('#quiz-progress-text').text('Pregunta ' + (stepIndex + 1) + ' de ' + totalQuizSteps);
        $('#quiz-progress-bar').css('width', pct + '%');
        $('.quiz-nav-num').removeClass('actual').removeAttr('aria-current');
        $('.quiz-nav-num').eq(stepIndex).addClass('actual').attr('aria-current', 'step');
    }
</script>

<?php if($leccion_activa['tipo'] === 'quiz'): ?>
<script src="../js/quiz_engine.js?v=2"></script>
<script>
    // quiz_api.php exige el token CSRF (cabecera X-CSRF-Token)
    window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
    document.addEventListener("DOMContentLoaded", () => {
        window.quizEngineInstance = new QuizEngine({
            leccionId: <?= $leccion_activa['id'] ?>,
            apiUrl: 'quiz_api.php',
            autosaveInterval: 30000
        });

        // Detectar cambios en las respuestas para el autoguardado optimista
        $('#quizForm input[type="radio"], #quizForm input[type="checkbox"]').on('change', function() {
            let name = $(this).attr('name');
            // name format: respuesta[pregunta_id] or respuesta[pregunta_id][]
            let match = name.match(/\[(\d+)\]/);
            if (match && match[1]) {
                let preguntaId = match[1];
                let val;
                if ($(this).is(':checkbox')) {
                    val = JSON.stringify(
                        $(`#quizForm input[name="respuesta[${preguntaId}][]"]:checked`)
                            .map(function() { return String($(this).val()); })
                            .get()
                    );
                } else {
                    val = $(this).val();
                }
                window.quizEngineInstance.registrarCambioRespuesta(preguntaId, val);
            }
        });

        $('#quizForm input[type="file"]').on('change', () => window.quizEngineInstance.actualizarIndicadorRespuestas());

        $('#quizForm textarea').on('input', function() {
            let name = $(this).attr('name');
            let match = name.match(/\[(\d+)\]/);
            if (match && match[1]) {
                let preguntaId = match[1];
                let val = $(this).val();
                window.quizEngineInstance.registrarCambioRespuesta(preguntaId, val);
            }
        });
    });
</script>
<?php endif; ?>


<script>
    // --- TIME TRACKING (Permanencia en Plataforma) ---
    (function() {
        let accumulatedSeconds = 0;
        let idleTimer = null;
        let isIdle = false;
        let trackingInterval = null;
        const AFK_LIMIT_MS = 5 * 60 * 1000; // 5 minutos exactos
        const PING_INTERVAL_MS = 5 * 60 * 1000; // Enviar cada 5 minutos
        const LECCION_ID = <?= intval($leccion_id) ?>;

        function resetIdleTimer() {
            isIdle = false;
            clearTimeout(idleTimer);
            idleTimer = setTimeout(() => {
                isIdle = true;
            }, AFK_LIMIT_MS);
        }

        // Listeners supersensibles para no marcar AFK
        window.addEventListener("mousemove", resetIdleTimer);
        window.addEventListener("keydown", resetIdleTimer);
        window.addEventListener("scroll", resetIdleTimer);
        window.addEventListener("click", resetIdleTimer);
        window.addEventListener("touchstart", resetIdleTimer);
        window.addEventListener("wheel", resetIdleTimer);

        resetIdleTimer();

        // El cronómetro local
        trackingInterval = setInterval(() => {
            if (!isIdle && document.visibilityState === "visible") {
                accumulatedSeconds++;
            }
        }, 1000);

        // Ping periódico (cada 5 minutos)
        setInterval(() => {
            sendTimePing();
        }, PING_INTERVAL_MS);

        function sendTimePing(useBeacon = false) {
            if (accumulatedSeconds <= 0) return;
            
            const secsToSend = accumulatedSeconds;
            accumulatedSeconds = 0; // Reset local
            
            const fd = new FormData();
            fd.append("leccion_id", LECCION_ID);
            fd.append("segundos", secsToSend);
            fd.append("csrf_token", <?= json_encode(get_csrf_token()) ?>);

            if (useBeacon && navigator.sendBeacon) {
                navigator.sendBeacon("track_time.php", fd);
            } else {
                fetch("track_time.php", { method: "POST", body: fd }).catch(e => console.error(e));
            }
        }

        // Cierre de Ciclo
        document.addEventListener("visibilitychange", () => {
            if (document.visibilityState === "hidden") {
                sendTimePing(true);
            } else {
                resetIdleTimer();
            }
        });
        
        window.addEventListener("beforeunload", () => {
            sendTimePing(true);
        });
    })();
    // --- FIN TIME TRACKING ---
</script>




<script>
async function enviarTarea(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const btn = document.getElementById("btn_enviar_tarea");
    
    if(btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Subiendo...';
    }

    try {
        const response = await fetch("submit_tarea.php", {
            method: "POST",
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            Swal.fire({
                icon: "success",
                title: "¡Tarea Enviada!",
                text: "Tu archivo ha sido subido con éxito y está pendiente de revisión.",
                confirmButtonColor: "#10b981"
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: "error",
                title: "Error",
                text: result.error || "Ocurrió un problema al enviar la tarea."
            });
            if(btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Enviar Tarea';
            }
        }
    } catch (error) {
        console.error(error);
        Swal.fire({
            icon: "error",
            title: "Error de conexión",
            text: "No se pudo conectar con el servidor."
        });
        if(btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Enviar Tarea';
        }
    }
}
</script>
</body>
</html>
