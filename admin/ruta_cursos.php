<?php
require_once '../config.php';
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../helpers/imagen_helper.php';
require_rol('admin');

$ruta_id = isset($_GET['ruta_id']) ? intval($_GET['ruta_id']) : 0;

// Procesar Ajax (Reordenar)
if (isset($_POST['action']) && $_POST['action'] === 'reorder') {
    $ids = $_POST['ids']; // array de IDs de ruta_cursos
    foreach($ids as $index => $id) {
        $orden = $index + 1;
        $pdo->prepare("UPDATE ruta_cursos SET orden = ? WHERE id = ?")->execute([$orden, $id]);
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success']);
    exit;
}

// Procesar Ajax (Toggle Bloqueo)
if (isset($_POST['action']) && $_POST['action'] === 'toggle_bloqueo') {
    $id = intval($_POST['id']);
    $valor = intval($_POST['valor']);
    $pdo->prepare("UPDATE ruta_cursos SET bloqueado_hasta_completar_anterior = ? WHERE id = ?")->execute([$valor, $id]);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success']);
    exit;
}

// Procesar Agregar Curso a Ruta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_curso'])) {
    $curso_id = intval($_POST['curso_id']);
    
    // Obtener último orden
    $stmt = $pdo->prepare("SELECT MAX(orden) FROM ruta_cursos WHERE ruta_id = ?");
    $stmt->execute([$ruta_id]);
    $orden = intval($stmt->fetchColumn()) + 1;

    try {
        $pdo->prepare("INSERT INTO ruta_cursos (ruta_id, curso_id, orden) VALUES (?, ?, ?)")->execute([$ruta_id, $curso_id, $orden]);
        header("Location: ruta_cursos.php?ruta_id=$ruta_id&msg=agregado", true, 303);
        exit;
    } catch (PDOException $e) {
        // Ignorar duplicados (UNIQUE constraint)
        header("Location: ruta_cursos.php?ruta_id=$ruta_id&error=duplicado", true, 303);
        exit;
    }
}

// Eliminar Curso de Ruta
if (isset($_POST['del_rc'])) {
    $id_del = intval($_POST['del_rc']);
    $pdo->prepare("DELETE FROM ruta_cursos WHERE id = ? AND ruta_id = ?")->execute([$id_del, $ruta_id]);
    header("Location: ruta_cursos.php?ruta_id=$ruta_id&msg=eliminado", true, 303);
    exit;
}

require_once 'admin_header.php';

$stmt = $pdo->prepare("SELECT * FROM rutas_aprendizaje WHERE id = ?");
$stmt->execute([$ruta_id]);
$ruta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ruta) {
    echo "<h2>Ruta no encontrada</h2>";
    require_once 'admin_footer.php';
    exit;
}

// Obtener cursos asignados
$stmt_rc = $pdo->prepare("
    SELECT rc.*, c.titulo, c.imagen, c.estado 
    FROM ruta_cursos rc 
    JOIN cursos c ON rc.curso_id = c.id 
    WHERE rc.ruta_id = ? 
    ORDER BY rc.orden ASC
");
$stmt_rc->execute([$ruta_id]);
$cursos_asignados = $stmt_rc->fetchAll(PDO::FETCH_ASSOC);

// Obtener todos los cursos para el selector
$cursos_todos = $pdo->query("SELECT id, titulo FROM cursos ORDER BY titulo ASC")->fetchAll(PDO::FETCH_ASSOC);

?>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<style>
    .curso-row { background: white; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 1.5rem; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .curso-row.sortable-ghost { opacity: 0.4; background: #f8fafc; border: 1px dashed #cbd5e1; }
    .drag-handle { color: #94a3b8; cursor: grab; font-size: 1.2rem; padding: 0.5rem; }
    .drag-handle:active { cursor: grabbing; }
    
    /* Toggle Switch */
    .switch { position: relative; display: inline-block; width: 40px; height: 20px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .4s; border-radius: 34px; }
    .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 2px; bottom: 2px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked + .slider { background-color: #10b981; }
    input:checked + .slider:before { transform: translateX(20px); }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;">
    <div>
        <a href="rutas" style="color:#64748b; text-decoration:none; font-size:0.9rem; display:inline-block; margin-bottom:0.5rem;"><i class="fa-solid fa-arrow-left"></i> Volver a Rutas</a>
        <h2 style="margin:0; color:var(--primary);">Cursos de: <?= htmlspecialchars($ruta['titulo']) ?></h2>
    </div>
</div>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'agregado'): ?>
    <div style="background:#dcfce7; color:#166534; padding:1rem; border-radius:8px; margin-bottom:1.5rem;">✅ Curso agregado correctamente.</div>
<?php endif; ?>
<?php if(isset($_GET['msg']) && $_GET['msg'] === 'eliminado'): ?>
    <div style="background:#dcfce7; color:#166534; padding:1rem; border-radius:8px; margin-bottom:1.5rem;">✅ Curso retirado de la ruta.</div>
<?php endif; ?>
<?php if(isset($_GET['error']) && $_GET['error'] === 'duplicado'): ?>
    <div style="background:#fee2e2; color:#991b1b; padding:1rem; border-radius:8px; margin-bottom:1.5rem;">⚠️ Este curso ya pertenece a la ruta.</div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 350px; gap: 2rem; align-items: start;">
    
    <!-- Lista de cursos (Drag&Drop) -->
    <div>
        <div style="background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #e2e8f0;">
            <h3 style="margin-top:0; color:var(--primary); margin-bottom:1.5rem;">Orden y Configuración</h3>
            
            <?php if(empty($cursos_asignados)): ?>
                <div style="text-align:center; padding:3rem; color:#94a3b8; border:2px dashed #e2e8f0; border-radius:8px;">
                    Aún no hay cursos asignados a esta ruta. Utiliza el panel de la derecha para agregar.
                </div>
            <?php else: ?>
                <div id="sortable-list">
                    <?php foreach($cursos_asignados as $index => $rc): ?>
                        <div class="curso-row" data-id="<?= $rc['id'] ?>">
                            <div class="drag-handle"><i class="fa-solid fa-grip-vertical"></i></div>
                            <div style="width: 50px; height: 50px; border-radius: 8px; background: url('../<?= htmlspecialchars(imagen_curso($rc['imagen']), ENT_QUOTES, 'UTF-8') ?>') center/cover;"></div>
                            <div style="flex: 1;">
                                <h4 style="margin: 0 0 0.2rem 0; font-size: 1rem; color: var(--primary);">
                                    <span style="color:#94a3b8; font-weight:400; margin-right:5px;"><?= $index + 1 ?>.</span> <?= htmlspecialchars($rc['titulo']) ?>
                                </h4>
                                <span style="font-size: 0.75rem; color: #64748b; background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?= strtoupper($rc['estado']) ?></span>
                            </div>
                            
                            <div style="text-align: right;">
                                <div style="display: flex; align-items: center; gap: 0.5rem; justify-content: flex-end; margin-bottom: 0.3rem;">
                                    <label class="switch">
                                        <input type="checkbox" onchange="toggleBloqueo(<?= $rc['id'] ?>, this.checked)" <?= $rc['bloqueado_hasta_completar_anterior'] ? 'checked' : '' ?>>
                                        <span class="slider"></span>
                                    </label>
                                    <span style="font-size: 0.75rem; font-weight: 600; color: <?= $rc['bloqueado_hasta_completar_anterior'] ? '#10b981' : '#94a3b8' ?>;" id="label-bloq-<?= $rc['id'] ?>">
                                        <?= $rc['bloqueado_hasta_completar_anterior'] ? 'BLOQUEO ACTIVO' : 'SIN BLOQUEO' ?>
                                    </span>
                                </div>
                                <span style="font-size: 0.7rem; color: #94a3b8;">Requiere completar el anterior</span>
                            </div>

                            <div style="margin-left: 1rem; border-left: 1px solid #e2e8f0; padding-left: 1.5rem;">
                                <form method="POST" style="margin:0;" onsubmit="return confirm('¿Retirar este curso de la ruta?');">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="del_rc" value="<?= $rc['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Agregar Curso -->
    <div>
        <div style="background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #e2e8f0; position: sticky; top: 2rem;">
            <h3 style="margin-top:0; color:var(--primary); margin-bottom:1.5rem;">Agregar Curso</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group">
                    <label>Selecciona un curso</label>
                    <select name="curso_id" required style="width: 100%; padding: 0.8rem; border-radius: 8px; border: 1px solid #cbd5e1;">
                        <option value="">-- Seleccionar --</option>
                        <?php foreach($cursos_todos as $ct): ?>
                            <option value="<?= $ct['id'] ?>"><?= htmlspecialchars($ct['titulo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="agregar_curso" class="btn btn-primary btn-block">Asignar a la Ruta</button>
            </form>
            
            <div style="margin-top: 2rem; padding: 1rem; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px;">
                <h4 style="margin-top:0; color: #d97706; font-size:0.9rem;"><i class="fa-solid fa-lightbulb"></i> Regla de Bloqueo</h4>
                <p style="font-size:0.8rem; color: #92400e; margin-bottom:0; line-height:1.5;">
                    Si activas "BLOQUEO ACTIVO" en un curso, el estudiante no podrá acceder a él hasta que no haya completado al 100% el curso que está inmediatamente arriba en el orden.
                </p>
            </div>
        </div>
    </div>

</div>

<script>
    const el = document.getElementById('sortable-list');
    if (el) {
        new Sortable(el, {
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'sortable-ghost',
            onEnd: function () {
                const items = el.querySelectorAll('.curso-row');
                const ids = Array.from(items).map(item => item.getAttribute('data-id'));
                
                // Actualizar números visuales
                items.forEach((item, index) => {
                    item.querySelector('h4 span').textContent = (index + 1) + '.';
                });

                // Enviar a servidor
                fetch('ruta_cursos.php?ruta_id=<?= $ruta_id ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=reorder&csrf_token=<?= $_SESSION["csrf_token"] ?>&ids[]=' + ids.join('&ids[]=')
                })
                .then(res => res.json())
                .then(data => console.log('Reordenado', data))
                .catch(err => console.error(err));
            }
        });
    }

    function toggleBloqueo(id, isChecked) {
        const val = isChecked ? 1 : 0;
        const label = document.getElementById('label-bloq-' + id);
        if(isChecked) {
            label.textContent = 'BLOQUEO ACTIVO';
            label.style.color = '#10b981';
        } else {
            label.textContent = 'SIN BLOQUEO';
            label.style.color = '#94a3b8';
        }

        fetch('ruta_cursos.php?ruta_id=<?= $ruta_id ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=toggle_bloqueo&csrf_token=<?= $_SESSION["csrf_token"] ?>&id=${id}&valor=${val}`
        })
        .then(res => res.json())
        .then(data => console.log('Bloqueo actualizado', data));
    }
</script>

<?php require_once 'admin_footer.php'; ?>
