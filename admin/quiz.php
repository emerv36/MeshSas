<?php
require_once __DIR__ . '/admin_auth.php';
require_rol(['admin', 'docente']);

$leccion_id = intval($_GET['leccion'] ?? 0);
$msg = (string)($_GET['msg'] ?? '');

if (!$leccion_id) {
    require_once 'admin_header.php';
    echo '<p>Lección no especificada.</p>';
    require_once 'admin_footer.php';
    exit;
}

// Solo admin o el docente asignado al curso de esta lección
$stmt_curso = $pdo->prepare("SELECT m.curso_id FROM lecciones l JOIN modulos m ON l.modulo_id = m.id WHERE l.id = ?");
$stmt_curso->execute([$leccion_id]);
$curso_id_quiz = (int)$stmt_curso->fetchColumn();
if (!puede_gestionar_curso($pdo, $curso_id_quiz)) {
    http_response_code(403);
    exit('Acceso denegado: no gestionas el curso de esta lección.');
}

// --- ACCIONES (Antes de cualquier salida HTML) ---

if (isset($_GET['del_preg'])) {
    verify_csrf_get();
    $pdo->prepare("DELETE FROM quiz_preguntas WHERE id=? AND leccion_id=?")->execute([intval($_GET['del_preg']), $leccion_id]);
    header("Location: quiz?leccion=$leccion_id&msg=" . urlencode("Pregunta eliminada."));
    exit;
}

if (isset($_POST['guardar_config'])) {
    $puntaje_minimo = floatval($_POST['puntaje_minimo']);
    $max_intentos = intval($_POST['max_intentos']);
    $tiempo_limite = $_POST['tiempo_limite_minutos'] !== '' ? intval($_POST['tiempo_limite_minutos']) : null;

    // Save attempts config to quiz_configuracion (FUENTE DE VERDAD)
    $stmt = $pdo->prepare("SELECT id FROM quiz_configuracion WHERE leccion_id = ?");
    $stmt->execute([$leccion_id]);
    if ($stmt->fetch()) {
        $pdo->prepare("UPDATE quiz_configuracion SET max_intentos=?, tiempo_limite_minutos=? WHERE leccion_id=?")
            ->execute([$max_intentos, $tiempo_limite, $leccion_id]);
    } else {
        $pdo->prepare("INSERT INTO quiz_configuracion (leccion_id, max_intentos, tiempo_limite_minutos) VALUES (?,?,?)")
            ->execute([$leccion_id, $max_intentos, $tiempo_limite]);
    }

    // Sincronizar puntaje mínimo en lecciones (no max_intentos, ese vive solo en quiz_configuracion)
    $pdo->prepare("UPDATE lecciones SET puntaje_minimo=? WHERE id=?")
        ->execute([$puntaje_minimo, $leccion_id]);

    header("Location: quiz?leccion=$leccion_id&msg=" . urlencode("Configuración guardada."));
    exit;
}

if (isset($_POST['publicar_quiz'])) {
    $puntaje_minimo = floatval($_POST['puntaje_minimo']);
    $max_intentos = intval($_POST['max_intentos']);
    $tiempo_limite = $_POST['tiempo_limite_minutos'] !== '' ? intval($_POST['tiempo_limite_minutos']) : null;

    // Actualizar configuración primero
    $stmt = $pdo->prepare("SELECT id FROM quiz_configuracion WHERE leccion_id = ?");
    $stmt->execute([$leccion_id]);
    if ($stmt->fetch()) {
        $pdo->prepare("UPDATE quiz_configuracion SET max_intentos=?, tiempo_limite_minutos=? WHERE leccion_id=?")
            ->execute([$max_intentos, $tiempo_limite, $leccion_id]);
    } else {
        $pdo->prepare("INSERT INTO quiz_configuracion (leccion_id, max_intentos, tiempo_limite_minutos) VALUES (?,?,?)")
            ->execute([$leccion_id, $max_intentos, $tiempo_limite]);
    }

    // Insert new version
    $pdo->prepare("INSERT INTO quiz_versiones (leccion_id, estado, puntaje_minimo_aprobacion) VALUES (?, 'publicada', ?)")
        ->execute([$leccion_id, $puntaje_minimo]);
    $nueva_version_id = $pdo->lastInsertId();

    // Update active version
    $pdo->prepare("UPDATE quiz_configuracion SET quiz_version_activa_id=? WHERE leccion_id=?")
        ->execute([$nueva_version_id, $leccion_id]);

    // Legacy update
    $pdo->prepare("UPDATE lecciones SET puntaje_minimo=?, max_intentos=? WHERE id=?")
        ->execute([$puntaje_minimo, $max_intentos, $leccion_id]);

    header("Location: quiz?leccion=$leccion_id&msg=" . urlencode("El quiz ha sido publicado con éxito."));
    exit;
}

if (isset($_POST['nueva_pregunta'])) {
    $id       = intval($_POST['pregunta_id'] ?? 0);
    $pregunta = trim($_POST['pregunta']);
    $tipo     = in_array($_POST['tipo_p'] ?? '', ['unica', 'multiple', 'abierta', 'archivo', 'falso_verdadero'], true) ? $_POST['tipo_p'] : 'unica';
    $orden    = intval($_POST['orden_p']);

    if ($pregunta) {
        if ($id > 0) {
            // La pregunta debe pertenecer a esta lección
            $stmt_own = $pdo->prepare("SELECT 1 FROM quiz_preguntas WHERE id=? AND leccion_id=?");
            $stmt_own->execute([$id, $leccion_id]);
            if (!$stmt_own->fetchColumn()) {
                http_response_code(403);
                exit('La pregunta no pertenece a esta lección.');
            }
            $pdo->prepare("UPDATE quiz_preguntas SET pregunta=?, tipo=?, orden=? WHERE id=? AND leccion_id=?")
                ->execute([$pregunta, $tipo, $orden, $id, $leccion_id]);
            $preg_id = $id;
            $pdo->prepare("DELETE FROM quiz_opciones WHERE pregunta_id=?")->execute([$preg_id]);
            $msg_text = "Pregunta actualizada.";
        } else {
            $pdo->prepare("INSERT INTO quiz_preguntas (leccion_id, pregunta, tipo, orden) VALUES (?,?,?,?)")
                ->execute([$leccion_id, $pregunta, $tipo, $orden]);
            $preg_id = $pdo->lastInsertId();
            $msg_text = "Pregunta añadida.";
        }

        if ($tipo === 'unica' || $tipo === 'multiple') {
            $textos    = $_POST['opcion_texto'] ?? [];
            $correctas = $_POST['opcion_correcta'] ?? [];
            foreach ($textos as $i => $txt) {
                if (trim($txt)) {
                    $es_correcta = ($tipo === 'unica') ? ($i == intval($_POST['opcion_correcta_unica'] ?? -1) ? 1 : 0) : (in_array($i, $correctas) ? 1 : 0);
                    $pdo->prepare("INSERT INTO quiz_opciones (pregunta_id, texto, es_correcta) VALUES (?,?,?)")
                        ->execute([$preg_id, trim($txt), $es_correcta]);
                }
            }
        } elseif ($tipo === 'falso_verdadero') {
            $es_correcta_fv = intval($_POST['opcion_correcta_fv'] ?? -1);
            $pdo->prepare("INSERT INTO quiz_opciones (pregunta_id, texto, es_correcta) VALUES (?,?,?)")->execute([$preg_id, "Verdadero", ($es_correcta_fv === 1 ? 1 : 0)]);
            $pdo->prepare("INSERT INTO quiz_opciones (pregunta_id, texto, es_correcta) VALUES (?,?,?)")->execute([$preg_id, "Falso", ($es_correcta_fv === 0 ? 1 : 0)]);
        }
        header("Location: quiz?leccion=$leccion_id&msg=" . urlencode($msg_text));
        exit;
    }
}

// --- SALIDA HTML ---
require_once 'admin_header.php';

$stmt = $pdo->prepare("SELECT l.*, m.titulo AS modulo_titulo, c.titulo AS curso_titulo FROM lecciones l JOIN modulos m ON l.modulo_id=m.id JOIN cursos c ON m.curso_id=c.id WHERE l.id=?");
$stmt->execute([$leccion_id]);
$leccion = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$leccion || $leccion['tipo'] !== 'quiz') { echo '<p>Esta lección no es de tipo quiz.</p>'; require_once 'admin_footer.php'; exit; }

$stmtConf = $pdo->prepare("
    SELECT c.*, v.estado as version_estado
    FROM quiz_configuracion c
    LEFT JOIN quiz_versiones v ON c.quiz_version_activa_id = v.id
    WHERE c.leccion_id = ?
");
$stmtConf->execute([$leccion_id]);
$quizConf = $stmtConf->fetch(PDO::FETCH_ASSOC);
$is_publicada = ($quizConf && $quizConf['version_estado'] === 'publicada');

$preguntas = $pdo->prepare("SELECT * FROM quiz_preguntas WHERE leccion_id=? ORDER BY orden");
$preguntas->execute([$leccion_id]);
$preguntas = $preguntas->fetchAll(PDO::FETCH_ASSOC);
foreach ($preguntas as &$p) {
    $stmt = $pdo->prepare("SELECT * FROM quiz_opciones WHERE pregunta_id=?");
    $stmt->execute([$p['id']]);
    $p['opciones'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($p);
?>
<a href="gestionar_curso.php?id=<?= $curso_id_quiz ?>" style="color:var(--primary);font-weight:600;"><i class="fa-solid fa-arrow-left"></i> Volver al Curso</a>
<h2 style="margin:1rem 0 .25rem; display:flex; align-items:center; gap: 1rem;">
    Quiz: <?= htmlspecialchars($leccion['titulo']) ?>
    <?php if ($is_publicada): ?>
        <span style="font-size: 0.8rem; background: #22c55e; color: white; padding: 0.2rem 0.6rem; border-radius: 9999px; font-weight: bold;"><i class="fa-solid fa-check-circle"></i> Publicado</span>
    <?php else: ?>
        <span style="font-size: 0.8rem; background: #f59e0b; color: white; padding: 0.2rem 0.6rem; border-radius: 9999px; font-weight: bold;"><i class="fa-solid fa-pen"></i> Borrador</span>
    <?php endif; ?>
</h2>
<p style="color:#666;margin-bottom:2rem;"><small><?= htmlspecialchars($leccion['curso_titulo']) ?> → <?= htmlspecialchars($leccion['modulo_titulo']) ?></small></p>

<?php if($msg): ?><div style="background:#d4edda;color:#155724;padding:1rem;border-radius:6px;margin-bottom:1.5rem;">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div style="background:#f8fafc;border-radius:10px;padding:1.5rem;margin-bottom:2rem;border:1px solid #e2e8f0;">
    <h3 style="color:var(--primary);margin-top:0;">⚙️ Configuración del Quiz</h3>
    <form method="POST" style="display:flex;gap:2rem;align-items:flex-end;flex-wrap:wrap;">
        <?= csrf_input() ?>
        <div class="form-group">
            <label>Puntaje mínimo (%) <small style="color:#888;">(solo auto-calificables)</small></label>
            <input type="number" name="puntaje_minimo" value="<?= $leccion['puntaje_minimo'] ?>" min="1" max="100" style="width:120px;">
        </div>
        <div class="form-group">
            <label>Intentos máximos <small style="color:#888;">(0 = ilimitado)</small></label>
            <input type="number" name="max_intentos" value="<?= isset($quizConf['max_intentos']) ? $quizConf['max_intentos'] : $leccion['max_intentos'] ?>" min="0" style="width:120px;">
        </div>
        <div class="form-group">
            <label>Tiempo límite <small style="color:#888;">(Min. Vacío = usa duración lección)</small></label>
            <input type="number" name="tiempo_limite_minutos" value="<?= isset($quizConf['tiempo_limite_minutos']) ? $quizConf['tiempo_limite_minutos'] : '' ?>" min="1" style="width:120px;" placeholder="Ej: 30">
        </div>
        <div style="display:flex; gap: 1rem;">
            <button type="submit" name="guardar_config" class="btn btn-outline-dark">Guardar Configuración</button>
            <button type="button" class="btn btn-primary" style="background-color: #22c55e; border-color: #22c55e; color: white;" onclick="confirmarPublicacion(this)">
                <i class="fa-solid fa-upload"></i> Publicar Quiz
            </button>
        </div>
    </form>
</div>

<h3 style="color:var(--primary);">Preguntas (<?= count($preguntas) ?>)</h3>
<?php foreach($preguntas as $i => $p): ?>
<div style="background:white;border-radius:8px;padding:1.2rem;margin-bottom:1rem;box-shadow:0 2px 6px rgba(0,0,0,0.06);border-left:4px solid var(--primary);">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
        <div>
            <span style="font-size:0.7rem;text-transform:uppercase;font-weight:700;color:var(--accent);"><?= htmlspecialchars(str_replace('_',' ',$p['tipo'])) ?></span><br>
            <strong style="color:var(--primary);"><?= ($i+1) ?>. <?= htmlspecialchars($p['pregunta']) ?></strong>
        </div>
        <div style="display:flex;gap:0.8rem;">
            <button onclick='editarPregunta(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' style="color:var(--primary); background:none; border:none; cursor:pointer; font-size:0.85rem;" title="Editar"><i class="fa-solid fa-pen"></i></button>
            <a href="?leccion=<?= $leccion_id ?>&del_preg=<?= (int)$p['id'] ?>&<?= csrf_query() ?>" style="color:#dc3545;font-size:.85rem;" onclick="return confirmarEliminacion(event, this.href)" title="Eliminar"><i class="fa-solid fa-trash"></i></a>
        </div>
    </div>
    <?php if($p['tipo'] === 'unica' || $p['tipo'] === 'multiple' || $p['tipo'] === 'falso_verdadero'): ?>
    <ul style="margin:.75rem 0 0 1.5rem;font-size:0.9rem;">
    <?php foreach($p['opciones'] as $o): ?>
        <li style="margin:.3rem 0;<?= $o['es_correcta'] ? 'color:#16a34a;font-weight:700;' : 'color:#555;' ?>">
            <?= $o['es_correcta'] ? '✅ ' : '○ ' ?><?= htmlspecialchars($o['texto']) ?>
        </li>
    <?php endforeach; ?>
    </ul>
    <?php elseif($p['tipo'] === 'abierta'): ?>
    <p style="color:#666;font-size:0.85rem;margin-top:0.5rem;padding:0.5rem;background:#f1f5f9;border-radius:4px;border-left:3px solid #cbd5e1;">Pregunta de respuesta libre. Requiere calificación manual.</p>
    <?php else: ?>
    <p style="color:#666;font-size:0.85rem;margin-top:0.5rem;padding:0.5rem;background:#fdf2f2;border-radius:4px;border-left:3px solid #fca5a5;">El estudiante debe subir un archivo. Requiere revisión manual.</p>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<div id="form-container" style="background:white;border-radius:10px;padding:2rem;margin-top:2rem;box-shadow:0 2px 8px rgba(0,0,0,.07);border-top:3px solid var(--accent);">
    <h3 style="color:var(--primary);margin-top:0;" id="form-title">➕ Agregar Pregunta</h3>
    <form method="POST" id="form-pregunta">
        <?= csrf_input() ?>
        <input type="hidden" name="pregunta_id" id="pregunta_id" value="0">
        <div class="form-group">
            <label>Tipo de Pregunta</label>
            <select name="tipo_p" id="tipo_p" onchange="toggleOpciones(this.value)" style="width:100%;padding:.7rem;border:1px solid #e2e8f0;border-radius:6px;">
                <option value="unica">Opción Única (Solo una correcta)</option>
                <option value="multiple">Selección Múltiple (Varias correctas)</option>
                <option value="falso_verdadero">Falso / Verdadero</option>
                <option value="abierta">Pregunta Abierta (Texto libre)</option>
                <option value="archivo">Carga de Archivo (Tarea/Práctica)</option>
            </select>
        </div>
        <div class="form-group">
            <label>Enunciado *</label>
            <textarea name="pregunta" id="pregunta_txt" rows="2" required style="width:100%;padding:.8rem;border:1px solid #e2e8f0;border-radius:6px;" placeholder="Ej: Explica cómo funcionan las tablas dinámicas..."></textarea>
        </div>
        <div class="form-group" style="max-width:100px;">
            <label>Orden</label>
            <input type="number" name="orden_p" id="orden_p" value="<?= count($preguntas)+1 ?>" min="1">
        </div>

        <div id="seccion-opciones">
            <label style="font-weight:600;color:var(--primary);display:block;margin-bottom:.75rem;">Opciones de respuesta</label>
            <?php for($i=0; $i<4; $i++): ?>
            <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.75rem;">
                <div id="cont-check-<?= $i ?>" style="display:none;"><input type="checkbox" name="opcion_correcta[]" id="check-<?= $i ?>" value="<?= $i ?>"></div>
                <div id="cont-radio-<?= $i ?>"><input type="radio" name="opcion_correcta_unica" id="radio-<?= $i ?>" value="<?= $i ?>" <?= $i===0?'checked':'' ?>></div>
                <input type="text" name="opcion_texto[]" id="opcion-<?= $i ?>" style="flex:1;padding:.6rem;border:1px solid #e2e8f0;border-radius:6px;" placeholder="Opción <?= chr(65+$i) ?>">
            </div>
            <?php endfor; ?>
        </div>

        <div id="seccion-falso-verdadero" style="display:none; margin-top: 1rem;">
            <label style="font-weight:600;color:var(--primary);display:block;margin-bottom:.75rem;">¿Cuál es la respuesta correcta?</label>
            <div style="display:flex; gap: 2rem;">
                <label style="display:flex;align-items:center;gap:0.5rem;"><input type="radio" name="opcion_correcta_fv" id="fv-v" value="1" checked> Verdadero</label>
                <label style="display:flex;align-items:center;gap:0.5rem;"><input type="radio" name="opcion_correcta_fv" id="fv-f" value="0"> Falso</label>
            </div>
        </div>

        <div style="display:flex; gap:1rem; margin-top:1.5rem;">
            <button type="submit" name="nueva_pregunta" id="btn-submit" class="btn btn-primary">Añadir Pregunta</button>
            <button type="button" id="btn-cancel" onclick="resetForm()" class="btn btn-outline-dark" style="display:none;">Cancelar</button>
        </div>
    </form>
</div>

<script>
function confirmarPublicacion(btn) {
    showCustomConfirm(
        'Publicar Quiz',
        '¿Estás seguro de que deseas publicar esta versión del quiz? Los estudiantes verán las preguntas actuales.',
        'fa-solid fa-upload',
        '#22c55e',
        function() {
            let form = btn.closest('form');
            let input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'publicar_quiz';
            input.value = '1';
            form.appendChild(input);
            form.submit();
        }
    );
}

function confirmarEliminacion(e, url) {
    e.preventDefault();
    showCustomConfirm(
        'Eliminar Pregunta',
        '¿Estás seguro de que deseas eliminar esta pregunta? Esta acción no se puede deshacer.',
        'fa-solid fa-triangle-exclamation',
        '#dc3545',
        function() {
            window.location.href = url;
        }
    );
    return false;
}

function showCustomConfirm(title, message, iconClass, colorHex, callback) {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); z-index:9999; display:flex; align-items:center; justify-content:center; opacity:0; transition:0.3s;';

    const modal = document.createElement('div');
    modal.style.cssText = 'background:white; border:1px solid #e2e8f0; padding:2.5rem; border-radius:15px; text-align:center; max-width:400px; transform:translateY(20px); transition:0.3s; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5); font-family:sans-serif;';

    modal.innerHTML = `
        <i class="${iconClass}" style="font-size:3.5rem; color:${colorHex}; margin-bottom:1.5rem; display:block;"></i>
        <h3 style="margin-top:0; margin-bottom:1rem; font-size:1.4rem; color:#1e293b;">${title}</h3>
        <p style="color:#64748b; margin-bottom:2rem; line-height:1.6;">${message}</p>
        <div style="display:flex; gap:1rem; justify-content:center;">
            <button id="btn-cancel-modal" style="padding:0.6rem 1.5rem; border:1px solid #cbd5e1; background:white; color:#475569; border-radius:6px; cursor:pointer; font-weight:600; font-size:0.9rem;">Cancelar</button>
            <button id="btn-confirm-modal" style="padding:0.6rem 1.5rem; border:none; background:${colorHex}; color:white; border-radius:6px; cursor:pointer; font-weight:600; font-size:0.9rem;">Aceptar</button>
        </div>
    `;

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    // Trigger animations
    setTimeout(() => {
        overlay.style.opacity = '1';
        modal.style.transform = 'translateY(0)';
    }, 10);

    document.getElementById('btn-cancel-modal').onclick = function() {
        overlay.style.opacity = '0';
        modal.style.transform = 'translateY(20px)';
        setTimeout(() => overlay.remove(), 300);
    };

    document.getElementById('btn-confirm-modal').onclick = function() {
        overlay.style.opacity = '0';
        modal.style.transform = 'translateY(20px)';
        setTimeout(() => {
            overlay.remove();
            if(callback) callback();
        }, 300);
    };
}

function toggleOpciones(tipo) {
    const sec = document.getElementById('seccion-opciones');
    const fvSec = document.getElementById('seccion-falso-verdadero');

    if (tipo === 'abierta' || tipo === 'archivo') {
        sec.style.display = 'none';
        if (fvSec) fvSec.style.display = 'none';
    } else if (tipo === 'falso_verdadero') {
        sec.style.display = 'none';
        if (fvSec) fvSec.style.display = 'block';
    } else {
        sec.style.display = 'block';
        if (fvSec) fvSec.style.display = 'none';
        for(let i=0; i<4; i++) {
            document.getElementById('cont-radio-'+i).style.display = (tipo === 'unica') ? 'block' : 'none';
            document.getElementById('cont-check-'+i).style.display = (tipo === 'multiple') ? 'block' : 'none';
        }
    }
}

function editarPregunta(p) {
    document.getElementById('form-title').innerText = '📝 Editar Pregunta';
    document.getElementById('btn-submit').innerText = 'Actualizar Pregunta';
    document.getElementById('btn-cancel').style.display = 'block';
    document.getElementById('pregunta_id').value = p.id;
    document.getElementById('pregunta_txt').value = p.pregunta;
    document.getElementById('tipo_p').value = p.tipo;
    document.getElementById('orden_p').value = p.orden;

    toggleOpciones(p.tipo);

    // Limpiar opciones previas
    for(let i=0; i<4; i++) {
        document.getElementById('opcion-'+i).value = '';
        document.getElementById('radio-'+i).checked = false;
        document.getElementById('check-'+i).checked = false;
    }

    if (p.tipo === 'unica' || p.tipo === 'multiple') {
        p.opciones.forEach((o, i) => {
            if(i < 4) {
                document.getElementById('opcion-'+i).value = o.texto;
                if(o.es_correcta == 1) {
                    if(p.tipo === 'unica') document.getElementById('radio-'+i).checked = true;
                    else document.getElementById('check-'+i).checked = true;
                }
            }
        });
    } else if (p.tipo === 'falso_verdadero') {
        const v = p.opciones.find(o => o.texto === 'Verdadero');
        if(v && v.es_correcta == 1) document.getElementById('fv-v').checked = true;
        else document.getElementById('fv-f').checked = true;
    }

    document.getElementById('form-container').scrollIntoView({ behavior: 'smooth' });
}

function resetForm() {
    document.getElementById('form-title').innerText = '➕ Agregar Pregunta';
    document.getElementById('btn-submit').innerText = 'Añadir Pregunta';
    document.getElementById('btn-cancel').style.display = 'none';
    document.getElementById('pregunta_id').value = '0';
    document.getElementById('form-pregunta').reset();
    toggleOpciones('unica');
}
</script>
<?php require_once 'admin_footer.php'; ?>
