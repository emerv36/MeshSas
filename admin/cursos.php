<?php
require_once __DIR__ . '/admin_auth.php';
require_rol(['admin', 'docente']);
require_once __DIR__ . '/../helpers/upload_helper.php';
require_once __DIR__ . '/../helpers/html_sanitizer.php';
require_once __DIR__ . '/../helpers/imagen_helper.php';
require_once __DIR__ . '/../helpers/quiz_calificacion_helper.php';

$mensaje = '';

// AJAX Handlers
// (Eliminados ya que se procesará todo al guardar el formulario principal)

// Guardar Curso
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($rol !== 'admin') {
        http_response_code(403);
        echo "<div style='padding:2rem; font-family:sans-serif;'><h2>Error 403</h2><p>Acceso denegado: Solo los administradores pueden modificar cursos.</p></div>";
        exit;
    }

    if (isset($_POST['guardar_curso'])) {
        $titulo    = trim($_POST['titulo']);
        $desc      = trim($_POST['descripcion']);
        $duracion  = trim($_POST['duracion']);
        $nivel     = trim($_POST['nivel']);
        $precio    = floatval($_POST['precio']);
        $precio_oferta = !empty($_POST['precio_oferta']) ? floatval($_POST['precio_oferta']) : null;
        $estado    = $_POST['estado'] ?? 'activo';
        $categoria = isset($_POST['categorias']) ? implode(' ', $_POST['categorias']) : '';
        $que_aprenderas = trim($_POST['que_aprenderas'] ?? '');
        $requisitos = trim($_POST['requisitos'] ?? '');
        $publico_objetivo = trim($_POST['publico_objetivo'] ?? '');
        $enlace_externo = trim($_POST['enlace_externo'] ?? '');
        $modulos_externos = intval($_POST['modulos_externos'] ?? 0);
        $docentes_asig = $_POST['docentes'] ?? [];
        $firmante_id = !empty($_POST['firmante_id']) ? intval($_POST['firmante_id']) : null;

        if (isset($_POST['id']) && $_POST['id']) {
            $pdo->prepare("UPDATE cursos SET titulo=?, descripcion=?, duracion=?, nivel=?, precio=?, precio_oferta=?, estado=?, categoria=?, que_aprenderas=?, requisitos=?, publico_objetivo=?, enlace_externo=?, modulos_externos=?, firmante_id=? WHERE id=?")
                ->execute([$titulo, $desc, $duracion, $nivel, $precio, $precio_oferta, $estado, $categoria, $que_aprenderas, $requisitos, $publico_objetivo, $enlace_externo, $modulos_externos, $firmante_id, $_POST['id']]);
            $id_curso = $_POST['id'];
        } else {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $titulo)));
            $pdo->prepare("INSERT INTO cursos (titulo, slug, descripcion, duracion, nivel, precio, precio_oferta, estado, categoria, que_aprenderas, requisitos, publico_objetivo, enlace_externo, modulos_externos, firmante_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$titulo, $slug, $desc, $duracion, $nivel, $precio, $precio_oferta, $estado, $categoria, $que_aprenderas, $requisitos, $publico_objetivo, $enlace_externo, $modulos_externos, $firmante_id]);
            $id_curso = $pdo->lastInsertId();
        }

        if (hay_archivo_subido('imagen')) {
            try {
                $nombre_img = guardar_imagen_subida($_FILES['imagen'], __DIR__ . '/../uploads', 'curso_' . (int)$id_curso);
                $pdo->prepare("UPDATE cursos SET imagen=? WHERE id=?")->execute(["uploads/".$nombre_img, $id_curso]);
            } catch (RuntimeException $e) {
                error_log('Imagen de curso rechazada: ' . $e->getMessage());
            }
        }

        $pdo->prepare("DELETE FROM curso_docentes WHERE curso_id=?")->execute([$id_curso]);
        foreach ($docentes_asig as $d_id) {
            $pdo->prepare("INSERT INTO curso_docentes (curso_id, docente_id) VALUES (?,?)")->execute([$id_curso, $d_id]);
        }
        header("Location: cursos.php?msg=saved", true, 303); exit;
    }

    if (isset($_POST['del_curso'])) {
        $curso_id = (int)$_POST['del_curso'];
        $courseState = $pdo->prepare("SELECT estado FROM cursos WHERE id = ?");
        $courseState->execute([$curso_id]);
        $currentState = $courseState->fetchColumn();
        if ($currentState === false) {
            header("Location: cursos.php?msg=missing", true, 303);
            exit;
        }
        $relations = [
            "SELECT COUNT(*) FROM pagos WHERE curso_id = ? OR (producto_tipo = 'curso' AND producto_id = ?)" => [$curso_id, $curso_id],
            "SELECT COUNT(*) FROM inscripciones WHERE curso_id = ?" => [$curso_id],
            "SELECT COUNT(*) FROM ruta_cursos WHERE curso_id = ?" => [$curso_id],
            "SELECT COUNT(*) FROM modulos WHERE curso_id = ?" => [$curso_id],
        ];
        $hasHistory = $currentState !== 'borrador';
        foreach ($relations as $sql => $params) {
            $check = $pdo->prepare($sql);
            $check->execute($params);
            if ((int)$check->fetchColumn() > 0) {
                $hasHistory = true;
                break;
            }
        }
        if ($hasHistory) {
            $pdo->prepare("UPDATE cursos SET estado = 'archivado' WHERE id = ?")->execute([$curso_id]);
            header("Location: cursos.php?msg=archived", true, 303);
        } else {
            $pdo->prepare("DELETE FROM cursos WHERE id = ? AND estado = 'borrador'")->execute([$curso_id]);
            header("Location: cursos.php?msg=deleted", true, 303);
        }
        exit;
    }

    // Restaurar un curso archivado: vuelve como borrador para revisarlo antes de publicarlo
    if (isset($_POST['restaurar_curso'])) {
        $pdo->prepare("UPDATE cursos SET estado = 'borrador' WHERE id = ? AND estado = 'archivado'")->execute([(int)$_POST['restaurar_curso']]);
        header("Location: cursos.php?estado=borradores&msg=restored", true, 303); exit;
    }

    // Desvincular docente
    if (isset($_POST['remove_docente']) && isset($_POST['curso_id'])) {
        $pdo->prepare("DELETE FROM curso_docentes WHERE curso_id=? AND docente_id=?")
            ->execute([$_POST['curso_id'], $_POST['remove_docente']]);
        header("Location: cursos.php?msg=removed", true, 303); exit;
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'saved') $mensaje = "Curso guardado correctamente.";
    if ($_GET['msg'] == 'deleted') $mensaje = "Curso eliminado.";
    if ($_GET['msg'] == 'archived') $mensaje = "El curso tiene historial y fue archivado para conservar su integridad.";
    if ($_GET['msg'] == 'missing') $mensaje = "El curso ya no existe.";
    if ($_GET['msg'] == 'removed') $mensaje = "Docente desvinculado del curso correctamente.";
    if ($_GET['msg'] == 'restored') $mensaje = "Curso restaurado como borrador.";
    if ($_GET['msg'] == 'selecciona_curso') $mensaje = "Selecciona un curso y haz clic en 'Módulos' para gestionar su contenido.";
}

// Obtener Cursos
if ($rol === 'admin') {
    $stmt = $pdo->query("SELECT * FROM cursos ORDER BY id DESC");
} else {
    $stmt = $pdo->prepare("SELECT c.* FROM cursos c JOIN curso_docentes cd ON c.id = cd.curso_id WHERE cd.docente_id = ? ORDER BY c.id DESC");
    $stmt->execute([$user_id]);
}
$cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filtro por estado (pestañas). "Todos" incluye también privados, inactivos y externos.
$filtros_estado = ['todos' => 'Todos', 'activos' => 'Activos', 'borradores' => 'Borradores', 'archivados' => 'Archivados'];
$estado_de_filtro = ['activos' => 'activo', 'borradores' => 'borrador', 'archivados' => 'archivado'];
$filtro = array_key_exists($_GET['estado'] ?? '', $filtros_estado) ? $_GET['estado'] : 'todos';
$conteo_estado = ['todos' => count($cursos)];
foreach ($estado_de_filtro as $clave => $estado_bd) {
    $conteo_estado[$clave] = count(array_filter($cursos, fn($c) => $c['estado'] === $estado_bd));
}
if ($filtro !== 'todos') {
    $cursos = array_values(array_filter($cursos, fn($c) => $c['estado'] === $estado_de_filtro[$filtro]));
}

foreach($cursos as &$c) {
    $s_docs = $pdo->prepare("SELECT u.id, u.nombre FROM usuarios u JOIN curso_docentes cd ON u.id = cd.docente_id WHERE cd.curso_id=?");
    $s_docs->execute([$c['id']]);
    $docs_info = $s_docs->fetchAll(PDO::FETCH_ASSOC);
    $c['docente_ids'] = array_column($docs_info, 'id');
    $c['docente_nombres'] = array_column($docs_info, 'nombre');

    // Obtener rutas informativas
    $s_rutas = $pdo->prepare("SELECT r.titulo FROM rutas_aprendizaje r JOIN ruta_cursos rc ON r.id = rc.ruta_id WHERE rc.curso_id=?");
    $s_rutas->execute([$c['id']]);
    $c['rutas'] = $s_rutas->fetchAll(PDO::FETCH_COLUMN);
}
unset($c);

// Lista de Docentes para el Admin
$docentes_lista = $pdo->query("SELECT id, nombre, email FROM usuarios WHERE rol='docente' ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

// Lista de Firmantes
$firmantes_lista = [];
try {
    $stmt_f = $pdo->query("SELECT id, nombre, cargo FROM firmantes ORDER BY nombre ASC");
    if ($stmt_f) {
        $firmantes_lista = $stmt_f->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {}

// Lista de Categorías
$categorias_lista = [];
try {
    $stmt_cats = $pdo->query("SELECT * FROM categorias ORDER BY nombre ASC");
    if ($stmt_cats) {
        $categorias_lista = $stmt_cats->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // La tabla categorias probablemente no existe aún
}

// Quizzes por calificar en cada curso (se califican en su pestaña Evaluaciones)
$quizzes_por_curso = quiz_pendientes_por_curso($pdo, $rol, (int)$user_id);

require_once 'admin_header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;">
    <h2>Gestión de Cursos</h2>
    <?php if($rol === 'admin'): ?>
    <button onclick="abrirModalNuevo()" class="btn btn-primary">+ Nuevo Curso</button>
    <?php endif; ?>
</div>

<?php if($mensaje): ?><div style="background:#d4edda;color:#155724;padding:1rem;border-radius:6px;margin-bottom:1.5rem;">✅ <?= $mensaje ?></div><?php endif; ?>

<div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1rem;">
    <?php foreach ($filtros_estado as $clave => $etiqueta): ?>
    <a href="cursos?estado=<?= $clave ?>" style="padding:0.5rem 1rem; border-radius:8px; font-size:0.85rem; font-weight:600; text-decoration:none; <?= $filtro === $clave ? 'background:var(--primary); color:white;' : 'background:white; color:#64748b; border:1px solid #e2e8f0;' ?>">
        <?= $etiqueta ?> <span style="opacity:0.7;">(<?= $conteo_estado[$clave] ?>)</span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Barra de búsqueda de Cursos -->
<div style="background: white; padding: 1rem 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
    <div style="flex: 1; min-width: 250px; position: relative;">
        <i class="fa-solid fa-search" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
        <input type="text" id="filtro_curso" placeholder="Buscar curso por título..." style="width: 100%; padding: 0.6rem 1rem 0.6rem 2.5rem; border-radius: 8px; border: 1px solid #e2e8f0; outline: none;">
    </div>
    <div style="font-size: 0.85rem; color: #64748b; font-weight: 600; text-align: right; min-width: 150px;">
        Mostrando <span id="contador_cursos" style="color: var(--primary);"><?= count($cursos) ?></span> cursos
    </div>
</div>

<div id="lista_cursos" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:1.5rem;">
    <?php foreach($cursos as $c): ?>
    <div class="curso-item" data-titulo="<?= htmlspecialchars(mb_strtolower($c['titulo'], 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>" style="background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,0.05);display:flex;flex-direction:column;border:1px solid #e2e8f0;">
        <div style="height:160px;background:#eee url('../<?= htmlspecialchars(imagen_curso($c['imagen']), ENT_QUOTES, 'UTF-8') ?>') center/cover no-repeat;"></div>
        <div style="padding:1.5rem;flex:1;display:flex;flex-direction:column;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.75rem;">
                <h3 style="margin:0;font-size:1.15rem;color:var(--primary);"><?= htmlspecialchars($c['titulo']) ?></h3>
                <span class="role-tag" style="background:<?= $c['estado']==='privado' ? '#fee2e2' : ($c['estado']==='activo'?'#dcfce7':'#fef9c3') ?>;color:<?= $c['estado']==='privado' ? '#991b1b' : ($c['estado']==='activo'?'#166534':'#854d0e') ?>"><?= $c['estado']==='privado' ? '<i class="fa-solid fa-lock" style="margin-right:4px;"></i>' : '' ?><?= $c['estado'] ?></span>
            </div>

            <div style="margin-bottom:1.2rem;">
                <p style="color:#64748b;font-size:0.85rem;line-height:1.4;margin:0;"><?= htmlspecialchars(extracto_texto($c['descripcion'], 100) ?: 'Sin descripción') ?></p>
            </div>

            <?php if(!empty($c['rutas'])): ?>
            <div style="margin-bottom:1.2rem; display:flex; flex-wrap:wrap; gap:0.4rem;">
                <?php foreach($c['rutas'] as $rt): ?>
                    <span style="font-size:0.7rem; background:#e0e7ff; color:#3730a3; padding:3px 8px; border-radius:4px; font-weight:600;"><i class="fa-solid fa-route" style="margin-right:4px;"></i> <?= htmlspecialchars($rt) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div style="background:#f8fafc;padding:0.75rem;border-radius:8px;margin-bottom:1.5rem;border:1px solid #f1f5f9;">
                <div style="font-size:0.8rem;color:#475569;font-weight:600;margin-bottom:0.5rem;"><i class="fa-solid fa-user-tie"></i> Docentes Asignados:</div>
                <div style="display:flex;flex-wrap:wrap;gap:0.4rem;">
                    <?php if(empty($c['docente_nombres'])): ?>
                        <span style="color:#94a3b8;font-size:0.85rem;font-style:italic;">Ninguno asignado</span>
                    <?php else: ?>
                        <?php 
                        $nombres = $c['docente_nombres'];
                        $ids = $c['docente_ids'];
                        for($i=0; $i<count($nombres); $i++): 
                        ?>
                        <div style="background:white;padding:3px 8px;border-radius:6px;font-size:0.75rem;border:1px solid #e2e8f0;display:flex;align-items:center;gap:6px;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                            <span style="color:var(--primary);font-weight:500;"><?= htmlspecialchars($nombres[$i]) ?></span>
                            <?php if($rol === 'admin'): ?>
                            <form method="POST" style="display:inline-block; margin:0;" onsubmit="return confirm('¿Desvincular a <?= addslashes($nombres[$i]) ?> de este curso?')">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="curso_id" value="<?= $c['id'] ?>">
                                <button type="submit" name="remove_docente" value="<?= $ids[$i] ?>" style="background:none; border:none; color:#ef4444; font-weight:700; font-size:1rem; line-height:1; cursor:pointer; padding:0;" title="Desvincular">×</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endfor; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($quizzes_por_curso[$c['id']])): ?>
            <a href="gestionar_curso.php?id=<?= $c['id'] ?>&tab=evaluaciones" style="display:flex; align-items:center; gap:0.5rem; margin:auto 0 0.75rem; padding:0.55rem 0.8rem; border-radius:8px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; font-size:0.85rem; font-weight:600; text-decoration:none;">
                <i class="fa-solid fa-file-signature"></i>
                <?= $quizzes_por_curso[$c['id']] === 1 ? '1 quiz por calificar' : $quizzes_por_curso[$c['id']] . ' quizzes por calificar' ?>
                <i class="fa-solid fa-arrow-right" style="margin-left:auto;"></i>
            </a>
            <?php endif; ?>
            <div style="display:flex;gap:0.75rem;margin-top:auto;">
                <?php if($rol === 'admin'): ?>
                <button onclick='editarCurso(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, "UTF-8") ?>)' class="btn btn-sm btn-outline-dark" style="flex:1;">Editar</button>
                <?php endif; ?>
                <a href="gestionar_curso.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-primary" style="flex:1;text-align:center;"><i class="fa-solid fa-layer-group"></i> Gestionar</a>
                <?php if($rol === 'admin' && $c['estado'] === 'archivado'): ?>
                <form method="POST" style="flex:1; display:flex;">
                    <?= csrf_input() ?>
                    <button type="submit" name="restaurar_curso" value="<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-dark" style="flex:1;" title="Vuelve como borrador"><i class="fa-solid fa-rotate-left"></i> Restaurar</button>
                </form>
                <?php elseif($rol === 'admin'): ?>
                <form method="POST" style="flex:1; display:flex;" onsubmit="return confirm('¿Eliminar curso?')">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <button type="submit" name="del_curso" value="<?= $c['id'] ?>" class="btn btn-sm btn-danger" style="width:100%;"><i class="fa-solid fa-trash"></i></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Modal -->
<div id="modal-curso" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:1000;overflow-y:auto;backdrop-filter:blur(4px);">
    <div style="background:white;max-width:650px;margin:3rem auto;padding:2.5rem;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
            <h3 id="modal-titulo" style="margin:0;color:var(--primary);font-size:1.5rem;">Nuevo Curso</h3>
            <button onclick="cerrarModal()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;">&times;</button>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="id" id="curso_id">
            
            <div class="form-group">
                <label>Título del Curso *</label>
                <input type="text" name="titulo" id="titulo" required placeholder="Ej: Excel Avanzado para Negocios">
            </div>

            <div class="form-group">
                <label>Descripción</label>
                <textarea name="descripcion" id="descripcion" rows="3" style="width:100%;padding:0.75rem;border:1px solid #cbd5e1;border-radius:8px;font-family:inherit;"></textarea>
            </div>

            <div class="form-group">
                <label>Lo que aprenderás</label>
                <textarea name="que_aprenderas" id="que_aprenderas" rows="2" style="width:100%;padding:0.75rem;border:1px solid #cbd5e1;border-radius:8px;font-family:inherit;" placeholder="Ej: Dominar herramientas avanzadas, crear dashboards..."></textarea>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
                <div class="form-group">
                    <label>Requisitos</label>
                    <textarea name="requisitos" id="requisitos" rows="2" style="width:100%;padding:0.75rem;border:1px solid #cbd5e1;border-radius:8px;font-family:inherit;" placeholder="Ej: Conocimientos básicos de computación."></textarea>
                </div>
                <div class="form-group">
                    <label>¿Para quién es?</label>
                    <textarea name="publico_objetivo" id="publico_objetivo" rows="2" style="width:100%;padding:0.75rem;border:1px solid #cbd5e1;border-radius:8px;font-family:inherit;" placeholder="Ej: Estudiantes y profesionales que busquen mejorar sus habilidades."></textarea>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
                <div class="form-group">
                    <label>Duración</label>
                    <input type="text" name="duracion" id="duracion" placeholder="Ej: 20 horas">
                </div>
                <div class="form-group">
                    <label>Nivel</label>
                    <input type="text" name="nivel" id="nivel" placeholder="Ej: Intermedio">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.5rem;">
                <div class="form-group">
                    <label>Precio Normal *</label>
                    <input type="number" name="precio" id="precio" required>
                </div>
                <div class="form-group">
                    <label>Precio Oferta</label>
                    <input type="number" name="precio_oferta" id="precio_oferta" placeholder="Opcional">
                </div>
                <div class="form-group">
                    <label>Estado</label>
                    <select name="estado" id="estado" style="width:100%;padding:0.75rem;border:1px solid #cbd5e1;border-radius:8px;" onchange="toggleExternoFields()">
                        <option value="activo">Activo (Visible en web)</option>
                        <option value="inactivo">⏳ Inactivo (Próximamente)</option>
                        <option value="borrador">Borrador (Solo admin)</option>
                        <option value="privado">🔒 Privado (Solo Personal Administrativo)</option>
                        <option value="archivado">📦 Archivado</option>
                        <option value="externo">🔗 Externo (Dummy / Solo Informativo)</option>
                    </select>
                </div>
            </div>

            <div id="externo_fields" style="display:none; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom: 1.5rem; background: #fffbeb; padding: 1rem; border-radius: 8px; border: 1px solid #fef3c7;">
                <div class="form-group" style="margin:0;">
                    <label>URL Externa</label>
                    <input type="url" name="enlace_externo" id="enlace_externo" placeholder="https://ejemplo.com/curso">
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Cantidad de Módulos (Manual)</label>
                    <input type="number" name="modulos_externos" id="modulos_externos" value="0">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label style="display:block; margin-bottom:0.5rem; font-weight:600; color:var(--primary);">Categorías</label>
                <div style="display:flex; gap:1rem; flex-wrap:wrap; padding: 0.5rem 0;">
                    <?php foreach($categorias_lista as $cat): ?>
                    <label style="display:flex; align-items:center; gap:0.5rem;">
                        <input type="checkbox" name="categorias[]" value="<?= htmlspecialchars($cat['slug']) ?>">
                        <?= htmlspecialchars($cat['nombre']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label>Firma del Certificado</label>
                <select name="firmante_id" id="firmante_id" style="width:100%;padding:0.75rem;border:1px solid #cbd5e1;border-radius:8px;">
                    <option value="">(Por Defecto) Firma Global del Sistema</option>
                    <?php foreach($firmantes_lista as $f): ?>
                        <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['nombre']) ?> - <?= htmlspecialchars($f['cargo']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p style="font-size:0.75rem; color:#64748b; margin-top:0.4rem;">Selecciona quién firma el certificado de este curso. Si dejas la opción por defecto, se usará la firma global configurada en Ajustes.</p>
            </div>

            <?php if($rol === 'admin'): ?>
            <div class="form-group" style="background:#f8fafc;padding:1.5rem;border-radius:12px;margin:1rem 0; border:1px solid #e2e8f0;">
                <label style="color:var(--primary);font-weight:700;display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                    <span><i class="fa-solid fa-user-tie"></i> Docentes Asignados</span>
                </label>
                
                <div id="lista-asignados" style="display:flex; flex-direction:column; gap:0.5rem; margin-bottom:1.5rem;">
                    <!-- Se llena vía JS -->
                </div>
                <div id="hidden-docentes-container"></div> <!-- Inputs ocultos para el form -->

                <label style="color:var(--primary);font-weight:700;display:flex;justify-content:space-between;align-items:center;margin-top:1.5rem; border-top:1px solid #e2e8f0; padding-top:1rem;">
                    <span><i class="fa-solid fa-user-plus"></i> Agregar Docente</span>
                </label>

                <div style="position:relative;margin:0.75rem 0;">
                    <i class="fa-solid fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:0.9rem;"></i>
                    <input type="text" id="busqueda-docente" onkeyup="buscarDocentes()" placeholder="Buscar docente para agregar..." style="padding-left:35px;font-size:0.9rem;border-color:#e2e8f0;background:white;">
                </div>

                <div id="resultados-busqueda" style="background:white;padding:0.5rem;border-radius:8px;max-height:150px;overflow-y:auto;border:1px solid #e2e8f0; display:none;">
                    <?php foreach($docentes_lista as $doc): ?>
                    <div class="item-busqueda" id="busq-doc-<?= $doc['id'] ?>" style="display:flex;align-items:center;padding:0.6rem;border-radius:8px;cursor:pointer;transition:0.2s;border-bottom:1px solid #f8fafc;">
                        <div style="flex:1;">
                            <span class="nombre-doc" style="font-weight:600;display:block;font-size:0.85rem;color:var(--primary);"><?= htmlspecialchars($doc['nombre']) ?></span>
                            <span class="email-doc" style="font-size:0.75rem;color:#64748b;"><?= htmlspecialchars($doc['email']) ?></span>
                        </div>
                        <button type="button" onclick="asignarDocente(<?= $doc['id'] ?>)" class="btn btn-sm btn-primary" style="padding:4px 10px; font-size:0.7rem;">Asignar</button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Imagen del Curso (Opcional)</label>
                <input type="file" name="imagen" accept="image/*">
            </div>

            <div style="display:flex;gap:1rem;margin-top:2rem;">
                <button type="button" onclick="cerrarModal()" class="btn btn-block" style="background:#f1f5f9;color:#475569;font-weight:600;">Cerrar</button>
                <button type="submit" name="guardar_curso" class="btn btn-primary btn-block" style="box-shadow:0 10px 15px -3px rgba(255,107,53,0.3);">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalNuevo() {
    limpiarForm();
    document.getElementById('modal-titulo').textContent = 'Crear Nuevo Curso';
    document.getElementById('modal-curso').style.display = 'block';
}

function cerrarModal() {
    document.getElementById('modal-curso').style.display = 'none';
}

function editarCurso(c) {
    limpiarForm();
    document.getElementById('modal-titulo').textContent = 'Editar Curso';
    document.getElementById('curso_id').value = c.id;
    document.getElementById('titulo').value = c.titulo;
    document.getElementById('descripcion').value = c.descripcion;
    document.getElementById('que_aprenderas').value = c.que_aprenderas || '';
    document.getElementById('requisitos').value = c.requisitos || '';
    document.getElementById('publico_objetivo').value = c.publico_objetivo || '';
    document.getElementById('duracion').value = c.duracion;
    document.getElementById('nivel').value = c.nivel;
    document.getElementById('precio').value = c.precio;
    document.getElementById('precio_oferta').value = c.precio_oferta;
    document.getElementById('estado').value = c.estado;
    document.getElementById('enlace_externo').value = c.enlace_externo || '';
    document.getElementById('modulos_externos').value = c.modulos_externos || 0;
    document.getElementById('firmante_id').value = c.firmante_id || '';
    toggleExternoFields();

    document.querySelectorAll('input[name="categorias[]"]').forEach(cb => cb.checked = false);
    if(c.categoria) {
        const cats = c.categoria.split(' ');
        cats.forEach(cat => {
            const cb = document.querySelector(`input[name="categorias[]"][value="${cat}"]`);
            if(cb) cb.checked = true;
        });
    }
    
    // Cargar docentes asignados
    if (c.docente_ids && c.docente_nombres) {
        c.docente_ids.forEach((id, index) => {
            renderDocenteAsignado(id, c.docente_nombres[index], ''); 
        });
    }
    document.getElementById('modal-curso').style.display = 'block';
}

function renderDocenteAsignado(id, nombre, email) {
    const container = document.getElementById('lista-asignados');
    const hiddenContainer = document.getElementById('hidden-docentes-container');
    
    if(!container || !hiddenContainer) return; 
    if(document.getElementById('pill-doc-' + id)) return; 

    const html = `
        <div class="docente-pill" id="pill-doc-${id}" style="display:flex; justify-content:space-between; align-items:center; background:white; padding:0.6rem 1rem; border-radius:10px; border:1px solid #e2e8f0; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
            <div>
                <strong style="font-size:0.85rem; color:var(--primary);">${nombre}</strong>
            </div>
            <button type="button" onclick="quitarDocente(${id})" style="background:none; border:none; color:#ef4444; font-size:1.2rem; cursor:pointer; line-height:1;">&times;</button>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
    
    hiddenContainer.insertAdjacentHTML('beforeend', `<input type="hidden" name="docentes[]" value="${id}" id="input-doc-${id}">`);

    const itemBusq = document.getElementById('busq-doc-' + id);
    if(itemBusq) itemBusq.style.display = 'none';
}

function asignarDocente(id) {
    const item = document.getElementById('busq-doc-' + id);
    if (!item) return;
    
    const nombre = item.querySelector('.nombre-doc').textContent;
    const email  = item.querySelector('.email-doc').textContent;

    renderDocenteAsignado(id, nombre, email);
    
    document.getElementById('busqueda-docente').value = '';
    document.getElementById('resultados-busqueda').style.display = 'none';
}

function quitarDocente(id) {
    const pill = document.getElementById('pill-doc-' + id);
    if(pill) pill.remove();
    
    const input = document.getElementById('input-doc-' + id);
    if(input) input.remove();

    const itemBusq = document.getElementById('busq-doc-' + id);
    if(itemBusq) itemBusq.style.display = 'flex';
}

function buscarDocentes() {
    const busq = document.getElementById('busqueda-docente').value.toLowerCase();
    const resBox = document.getElementById('resultados-busqueda');
    const items = document.querySelectorAll('.item-busqueda');
    
    if(busq.length < 2) { resBox.style.display = 'none'; return; }
    
    let encontrados = 0;
    items.forEach(item => {
        const nombre = item.querySelector('.nombre-doc').textContent.toLowerCase();
        const email  = item.querySelector('.email-doc').textContent.toLowerCase();
        const id = item.id.replace('busq-doc-', '');
        const yaAsignado = document.getElementById('pill-doc-' + id);

        if((nombre.includes(busq) || email.includes(busq)) && !yaAsignado) {
            item.style.display = 'flex';
            encontrados++;
        } else {
            item.style.display = 'none';
        }
    });
    
    resBox.style.display = encontrados > 0 ? 'block' : 'none';
}

function limpiarForm() {
    document.getElementById('curso_id').value = '';
    document.getElementById('titulo').value = '';
    document.getElementById('descripcion').value = '';
    document.getElementById('que_aprenderas').value = '';
    document.getElementById('requisitos').value = '';
    document.getElementById('publico_objetivo').value = '';
    document.getElementById('duracion').value = '';
    document.getElementById('nivel').value = '';
    document.getElementById('precio').value = '';
    document.getElementById('precio_oferta').value = '';
    document.getElementById('estado').value = 'activo';
    document.getElementById('enlace_externo').value = '';
    document.getElementById('modulos_externos').value = '0';
    document.getElementById('firmante_id').value = '';
    toggleExternoFields();
    document.querySelectorAll('input[name="categorias[]"]').forEach(cb => cb.checked = false);
    const listaAsignados = document.getElementById('lista-asignados');
    const hiddenContainer = document.getElementById('hidden-docentes-container');
    if(listaAsignados) listaAsignados.innerHTML = '';
    if(hiddenContainer) hiddenContainer.innerHTML = '';
    
    const busqDoc = document.getElementById('busqueda-docente');
    if(busqDoc) busqDoc.value = '';
    
    const resBusq = document.getElementById('resultados-busqueda');
    if(resBusq) resBusq.style.display = 'none';
}

function toggleExternoFields() {
    const estado = document.getElementById('estado').value;
    const fields = document.getElementById('externo_fields');
    if(estado === 'externo') {
        fields.style.display = 'grid';
    } else {
        fields.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const inputSearch = document.getElementById('filtro_curso');
    const items = document.querySelectorAll('.curso-item');
    const contador = document.getElementById('contador_cursos');

    function filtrarCursos() {
        if(!inputSearch) return;
        const searchVal = inputSearch.value.toLowerCase();
        let visibles = 0;

        items.forEach(item => {
            const matchSearch = item.getAttribute('data-titulo').includes(searchVal);

            if (matchSearch) {
                item.style.display = 'flex';
                visibles++;
            } else {
                item.style.display = 'none';
            }
        });

        if(contador) contador.textContent = visibles;
    }

    if(inputSearch) {
        inputSearch.addEventListener('input', filtrarCursos);
    }
});
</script>

<style>
.item-docente:hover { background: #f8fafc; }
.item-docente:has(input:checked) { background: #fff7ed; border-color: #ffedd5; }
</style>

<?php require_once 'admin_footer.php'; ?>
