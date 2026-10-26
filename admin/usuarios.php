<?php
require_once __DIR__ . '/admin_auth.php';
require_rol('admin');
require_once __DIR__ . '/../helpers/upload_helper.php';
require_once __DIR__ . '/../helpers/imagen_helper.php';
require_once __DIR__ . '/admin_header.php';

// Solo el Administrador puede gestionar usuarios
if ($rol !== 'admin') { header("Location: index"); exit; }

$mensaje = '';
$rol_filter = $_GET['rol'] ?? 'todos';

// Procesar Guardar/Editar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_usuario'])) {
    $id       = intval($_POST['id'] ?? 0);
    $nombre   = trim($_POST['nombre']);
    $email    = trim($_POST['email']);
    $telefono = trim($_POST['telefono']);
    $rol_u    = $_POST['rol_u'] ?? 'estudiante';
    if (!in_array($rol_u, ['estudiante', 'docente', 'admin', 'tesoreria', 'administrativo'], true)) {
        $rol_u = 'estudiante';
    }
    $bio      = trim($_POST['bio'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($id) {
        // Editar
        if (!empty($password)) {
            $pass_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE usuarios SET nombre=?, email=?, telefono=?, rol=?, bio=?, password=? WHERE id=?");
            $stmt->execute([$nombre, $email, $telefono, $rol_u, $bio, $pass_hash, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE usuarios SET nombre=?, email=?, telefono=?, rol=?, bio=? WHERE id=?");
            $stmt->execute([$nombre, $email, $telefono, $rol_u, $bio, $id]);
        }
        $mensaje = "Usuario actualizado correctamente.";
    } else {
        // Crear Nuevo
        // Sin contraseña indicada se genera una aleatoria (se envía por correo con las credenciales)
        $pass_plana = $password ?: substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12);
        $pass_hash = password_hash($pass_plana, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, email, telefono, rol, bio, password) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$nombre, $email, $telefono, $rol_u, $bio, $pass_hash]);
        $id = $pdo->lastInsertId();
        $mensaje = "Nuevo usuario creado.";

        // Enviar Correo de Bienvenida
        require_once '../helpers/mailer.php';
        enviarCredenciales(['nombre' => $nombre, 'email' => $email], $pass_plana);
    }

    // Manejo de Foto de Perfil
    if (hay_archivo_subido('foto')) {
        try {
            $nombre_foto = guardar_imagen_subida($_FILES['foto'], __DIR__ . '/../uploads/perfiles', 'perfil_' . (int)$id);
            $pdo->prepare("UPDATE usuarios SET foto=? WHERE id=?")->execute(["uploads/perfiles/".$nombre_foto, $id]);
        } catch (RuntimeException $e) {
            $mensaje .= ' La foto no se guardó: ' . $e->getMessage();
        }
    }
}

// Eliminar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_usuario'])) {
    $uid = intval($_POST['eliminar_usuario']);
    // No permitir que el admin se elimine a sí mismo
    if ($uid != $user_id) {
        $pdo->prepare("DELETE FROM usuarios WHERE id=?")->execute([$uid]);
        $mensaje = "Usuario eliminado.";
    }
}

// Obtener estadísticas por rol
$stats_query = $pdo->query("SELECT rol, COUNT(*) as total FROM usuarios GROUP BY rol")->fetchAll(PDO::FETCH_KEY_PAIR);
$stats = [
    'admin'          => $stats_query['admin'] ?? 0,
    'docente'        => $stats_query['docente'] ?? 0,
    'estudiante'     => $stats_query['estudiante'] ?? 0,
    'tesoreria'      => $stats_query['tesoreria'] ?? 0,
    'administrativo' => $stats_query['administrativo'] ?? 0,
];
$total_all = array_sum($stats);

// Filtrar usuarios
$query = "SELECT u.*, (SELECT COUNT(*) FROM inscripciones WHERE usuario_id=u.id) AS cursos_inscritos FROM usuarios u";
if ($rol_filter !== 'todos') {
    $query .= " WHERE u.rol = " . $pdo->quote($rol_filter);
}
$query .= " ORDER BY u.fecha_registro DESC";
$usuarios = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

$usuario_detalle = null;
$cursos_detalle = [];
if (!empty($_GET['ver_cursos'])) {
    $detalle_id = (int)$_GET['ver_cursos'];
    $stmt = $pdo->prepare('SELECT id, nombre, email FROM usuarios WHERE id = ?');
    $stmt->execute([$detalle_id]);
    $usuario_detalle = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($usuario_detalle) {
        $stmt = $pdo->prepare(
            "SELECT i.fecha, c.id AS curso_id, c.titulo,
                (SELECT p.id FROM pagos p
                 WHERE p.usuario_id = i.usuario_id AND p.estado = 'verificado'
                   AND ((p.producto_tipo = 'curso' AND COALESCE(NULLIF(p.producto_id, 0), p.curso_id) = i.curso_id)
                     OR (p.producto_tipo = 'ruta' AND EXISTS (
                         SELECT 1 FROM ruta_cursos rc WHERE rc.ruta_id = p.producto_id AND rc.curso_id = i.curso_id
                     )))
                 ORDER BY p.fecha DESC LIMIT 1) AS pago_id,
                (SELECT p.producto_tipo FROM pagos p
                 WHERE p.usuario_id = i.usuario_id AND p.estado = 'verificado'
                   AND ((p.producto_tipo = 'curso' AND COALESCE(NULLIF(p.producto_id, 0), p.curso_id) = i.curso_id)
                     OR (p.producto_tipo = 'ruta' AND EXISTS (
                         SELECT 1 FROM ruta_cursos rc WHERE rc.ruta_id = p.producto_id AND rc.curso_id = i.curso_id
                     )))
                 ORDER BY p.fecha DESC LIMIT 1) AS origen
                ,
                (SELECT p.estado FROM pagos p
                 WHERE p.usuario_id = i.usuario_id
                   AND ((p.producto_tipo = 'curso' AND COALESCE(NULLIF(p.producto_id, 0), p.curso_id) = i.curso_id)
                     OR (p.producto_tipo = 'ruta' AND EXISTS (
                         SELECT 1 FROM ruta_cursos rc WHERE rc.ruta_id = p.producto_id AND rc.curso_id = i.curso_id
                     )))
                 ORDER BY (p.estado = 'verificado') DESC, p.fecha DESC LIMIT 1) AS pago_estado
             FROM inscripciones i
             JOIN cursos c ON c.id = i.curso_id
             WHERE i.usuario_id = ?
             ORDER BY i.fecha DESC"
        );
        $stmt->execute([$detalle_id]);
        $cursos_detalle = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Asegurar carpeta de perfiles
if (!is_dir('../uploads/perfiles')) mkdir('../uploads/perfiles', 0777, true);
?>

<style>
    .user-dashboard-header { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 2.5rem; }
    .stat-card { background: white; padding: 1.5rem; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; display: flex; align-items: center; gap: 1rem; transition: 0.3s; text-decoration: none; color: inherit; }
    .stat-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); border-color: #6366f1; }
    .stat-card.active { border-color: #6366f1; background: #f5f3ff; }
    .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
    .stat-info h3 { margin: 0; font-size: 1.5rem; color: #1e293b; }
    .stat-info p { margin: 0; font-size: 0.85rem; color: #64748b; font-weight: 500; }

    .table-container { background: white; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); overflow: hidden; border: 1px solid #f1f5f9; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f8fafc; padding: 1rem 1.5rem; text-align: left; font-size: 0.75rem; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #f1f5f9; }
    td { padding: 1rem 1.5rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    tr:hover { background: #fcfdfe; }

    .user-profile { display: flex; align-items: center; gap: 1rem; }
    .user-avatar { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; border: 2px solid #f1f5f9; background: #eee; flex-shrink: 0; }
    .user-avatar-iniciales { display: flex; align-items: center; justify-content: center; background: #64748b; color: #fff; font-size: 0.85rem; font-weight: 700; }
    .user-details strong { display: block; color: #1e293b; font-size: 0.95rem; }
    .user-details span { font-size: 0.8rem; color: #64748b; }

    .role-badge { padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
    .role-admin { background: #fee2e2; color: #991b1b; }
    .role-docente { background: #e0e7ff; color: #3730a3; }
    .role-estudiante { background: #dcfce7; color: #166534; }
    .role-tesoreria { background: #fef3c7; color: #92400e; }
    .role-administrativo { background: #f0fdf4; color: #065f46; }

    .modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(4px); }
    .modal-card { background:white; width:95%; max-width:550px; border-radius:20px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); overflow:hidden; }
    .modal-header { padding: 1.5rem 2rem; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .modal-body-form { padding: 2rem; }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
    <h2 style="margin:0;"><i class="fa-solid fa-users-gear" style="color:#6366f1; margin-right:10px;"></i> Dashboard de Usuarios</h2>
    <button onclick="abrirModal()" class="btn btn-primary" style="padding: 0.7rem 1.5rem; border-radius: 10px; font-weight: 600;">
        <i class="fa-solid fa-user-plus"></i> Nuevo Usuario
    </button>
</div>

<div class="user-dashboard-header">
    <a href="?rol=todos" class="stat-card <?= $rol_filter === 'todos' ? 'active' : '' ?>">
        <div class="stat-icon" style="background:#f1f5f9; color:#475569;"><i class="fa-solid fa-users"></i></div>
        <div class="stat-info"><h3><?= $total_all ?></h3><p>Total Usuarios</p></div>
    </a>
    <a href="?rol=admin" class="stat-card <?= $rol_filter === 'admin' ? 'active' : '' ?>">
        <div class="stat-icon" style="background:#fef2f2; color:#ef4444;"><i class="fa-solid fa-shield-halved"></i></div>
        <div class="stat-info"><h3><?= $stats['admin'] ?></h3><p>Administradores</p></div>
    </a>
    <a href="?rol=docente" class="stat-card <?= $rol_filter === 'docente' ? 'active' : '' ?>">
        <div class="stat-icon" style="background:#eef2ff; color:#6366f1;"><i class="fa-solid fa-chalkboard-user"></i></div>
        <div class="stat-info"><h3><?= $stats['docente'] ?></h3><p>Docentes</p></div>
    </a>
    <a href="?rol=estudiante" class="stat-card <?= $rol_filter === 'estudiante' ? 'active' : '' ?>">
        <div class="stat-icon" style="background:#f0fdf4; color:#10b981;"><i class="fa-solid fa-graduation-cap"></i></div>
        <div class="stat-info"><h3><?= $stats['estudiante'] ?></h3><p>Estudiantes</p></div>
    </a>
    <a href="?rol=tesoreria" class="stat-card <?= $rol_filter === 'tesoreria' ? 'active' : '' ?>">
        <div class="stat-icon" style="background:#fffbeb; color:#d97706;"><i class="fa-solid fa-cash-register"></i></div>
        <div class="stat-info"><h3><?= $stats['tesoreria'] ?></h3><p>Tesorería</p></div>
    </a>
    <a href="?rol=administrativo" class="stat-card <?= $rol_filter === 'administrativo' ? 'active' : '' ?>">
        <div class="stat-icon" style="background:#ecfdf5; color:#065f46;"><i class="fa-solid fa-id-badge"></i></div>
        <div class="stat-info"><h3><?= $stats['administrativo'] ?></h3><p>Administrativos</p></div>
    </a>
</div>

<?php if($mensaje): ?>
<div style="background:#f0fdf4; color:#166534; padding:1rem; border-radius:12px; margin-bottom:1.5rem; border:1px solid #dcfce7; display:flex; align-items:center; gap:0.8rem;">
    <i class="fa-solid fa-circle-check"></i> <span><?= $mensaje ?></span>
</div>
<?php endif; ?>

<?php if ($usuario_detalle): ?>
<div style="background:white;padding:1.5rem;border-radius:14px;margin-bottom:1.5rem;border:1px solid #e2e8f0;">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <h3 style="margin:0;">Cursos de <?= htmlspecialchars($usuario_detalle['nombre']) ?></h3>
        <a href="?rol=<?= urlencode($rol_filter) ?>" class="btn btn-sm btn-outline-dark">Cerrar</a>
    </div>
    <?php if (!$cursos_detalle): ?>
        <p style="color:#64748b;">Este usuario no tiene inscripciones activas.</p>
    <?php else: ?>
        <table style="margin-top:1rem;">
            <thead><tr><th>Curso</th><th>Inscripción</th><th>Origen</th><th>Pago</th><th>Estado</th></tr></thead>
            <tbody>
            <?php foreach ($cursos_detalle as $curso): ?>
                <tr>
                    <td><?= htmlspecialchars($curso['titulo']) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($curso['fecha'])) ?></td>
                    <td><?= $curso['origen'] ? htmlspecialchars($curso['origen']) : 'Sin pago demostrable' ?></td>
                    <td><?= $curso['pago_id'] ? '#' . (int)$curso['pago_id'] : '—' ?></td>
                    <td><?= $curso['pago_estado'] ? htmlspecialchars($curso['pago_estado']) : 'Sin pago demostrable' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="table-container">
    <table style="table-layout: fixed;">
        <thead>
            <tr>
                <th style="width: 280px;">Usuario</th>
                <th style="width: 130px;">Rol</th>
                <th style="width: 180px;">Contacto</th>
                <th style="width: 100px; text-align:center;">Cursos</th>
                <th style="width: 130px;">Registro</th>
                <th style="width: 120px; text-align:center;">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($usuarios as $u): ?>
            <tr>
                <td>
                    <div class="user-profile">
                        <?php if (imagen_disponible($u['foto'])): ?>
                        <div class="user-avatar" style="background: url('../<?= htmlspecialchars($u['foto'], ENT_QUOTES, 'UTF-8') ?>') center/cover no-repeat;"></div>
                        <?php else: $iniciales = iniciales_nombre($u['nombre']); ?>
                        <div class="user-avatar user-avatar-iniciales"><?= $iniciales !== '' ? htmlspecialchars($iniciales, ENT_QUOTES, 'UTF-8') : '<i class="fa-solid fa-user"></i>' ?></div>
                        <?php endif; ?>
                        <div class="user-details">
                            <strong><?= htmlspecialchars($u['nombre']) ?></strong>
                            <span><?= htmlspecialchars($u['email']) ?></span>
                        </div>
                    </div>
                </td>
                <td>
                    <span class="role-badge role-<?= $u['rol'] ?>">
                        <?= $u['rol'] ? strtoupper($u['rol']) : 'SIN ROL' ?>
                    </span>
                </td>
                <td>
                    <div style="font-size:0.85rem; color:#475569;">
                        <i class="fa-solid fa-phone" style="width:16px; color:#94a3b8;"></i> <?= htmlspecialchars($u['telefono'] ?: 'N/A') ?>
                    </div>
                </td>
                <td style="text-align:center;">
                    <span style="background:#f1f5f9; color:#475569; padding:4px 10px; border-radius:12px; font-weight:700; font-size:0.8rem;">
                        <?= $u['cursos_inscritos'] ?>
                    </span>
                </td>
                <td style="color:#64748b; font-size:0.85rem;">
                    <i class="fa-regular fa-calendar-days" style="margin-right:5px;"></i> <?= date('d M Y', strtotime($u['fecha_registro'])) ?>
                </td>
                <td style="text-align:center;">
                    <div style="display:flex; gap:0.5rem; justify-content:center;">
                        <a href="?rol=<?= urlencode($rol_filter) ?>&ver_cursos=<?= (int)$u['id'] ?>" class="btn btn-sm btn-outline-dark" style="border-radius:6px;width:32px;height:32px;padding:0;display:flex;align-items:center;justify-content:center;" title="Ver cursos"><i class="fa-solid fa-book-open"></i></a>
                        <button onclick='editarUsuario(<?= json_encode($u) ?>)' class="btn btn-sm btn-outline-dark" style="border-radius:6px; width:32px; height:32px; padding:0;"><i class="fa-solid fa-pen"></i></button>
                        <?php if($u['id'] != $user_id): ?>
                        <form method="POST" onsubmit="return confirm('¿Eliminar usuario?')" style="margin:0;">
                            <?= csrf_input() ?>
                            <button type="submit" name="eliminar_usuario" value="<?= (int)$u['id'] ?>" class="btn btn-sm btn-danger" style="border-radius:6px;width:32px;height:32px;padding:0;"><i class="fa-solid fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal Formulario -->
<div id="modal-usuario" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-header">
            <h3 id="modal-titulo" style="margin:0;">Crear Usuario</h3>
            <button onclick="cerrarModal()" style="background:none; border:none; cursor:pointer; font-size:1.2rem; color:#94a3b8;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body-form">
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_input() ?>
                <input type="hidden" name="id" id="u_id">
                
                <div class="form-group">
                    <label style="font-weight:600; color:#475569; margin-bottom:0.5rem; display:block;">Nombre Completo *</label>
                    <input type="text" name="nombre" id="u_nombre" required style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #e2e8f0; outline:none;">
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-top:1rem;">
                    <div class="form-group">
                        <label style="font-weight:600; color:#475569; margin-bottom:0.5rem; display:block;">Email *</label>
                        <input type="email" name="email" id="u_email" required style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #e2e8f0; outline:none;">
                    </div>
                    <div class="form-group">
                        <label style="font-weight:600; color:#475569; margin-bottom:0.5rem; display:block;">Teléfono</label>
                        <input type="text" name="telefono" id="u_telefono" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #e2e8f0; outline:none;">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-top:1rem;">
                    <div class="form-group">
                        <label style="font-weight:600; color:#475569; margin-bottom:0.5rem; display:block;">Rol</label>
                        <select name="rol_u" id="u_rol" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #e2e8f0; outline:none; background:white;">
                            <option value="estudiante">Estudiante</option>
                            <option value="docente">Docente</option>
                            <option value="admin">Administrador</option>
                            <option value="tesoreria">&#128181; Tesorería / Contabilidad</option>
                            <option value="administrativo">&#127970; Personal Administrativo</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label id="label-pass" style="font-weight:600; color:#475569; margin-bottom:0.5rem; display:block;">Contraseña</label>
                        <input type="password" name="password" placeholder="Mínimo 6 caracteres" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #e2e8f0; outline:none;">
                    </div>
                </div>

                <div class="form-group" style="margin-top:1rem;">
                    <label style="font-weight:600; color:#475569; margin-bottom:0.5rem; display:block;">Biografía (Solo Docentes)</label>
                    <textarea name="bio" id="u_bio" rows="2" style="width:100%; padding:0.8rem; border-radius:10px; border:1px solid #e2e8f0; outline:none;" placeholder="Experiencia, especialidades..."></textarea>
                </div>

                <div class="form-group" style="margin-top:1rem;">
                    <label style="font-weight:600; color:#475569; margin-bottom:0.5rem; display:block;">Foto de Perfil</label>
                    <input type="file" name="foto" accept="image/*" style="font-size:0.8rem;">
                </div>

                <div style="display:flex; gap:1rem; margin-top:2rem;">
                    <button type="button" onclick="cerrarModal()" class="btn btn-block" style="background:#f1f5f9; color:#475569; font-weight:600;">Cancelar</button>
                    <button type="submit" name="guardar_usuario" class="btn btn-primary btn-block" style="font-weight:600;">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function abrirModal() {
    limpiarForm();
    document.getElementById('modal-titulo').textContent = 'Crear Nuevo Usuario';
    document.getElementById('label-pass').textContent = 'Contraseña (Default: 123456)';
    document.getElementById('modal-usuario').style.display = 'flex';
}

function cerrarModal() {
    document.getElementById('modal-usuario').style.display = 'none';
}

function editarUsuario(u) {
    limpiarForm();
    document.getElementById('modal-titulo').textContent = 'Editar Perfil de Usuario';
    document.getElementById('label-pass').textContent = 'Cambiar Contraseña';
    document.getElementById('u_id').value = u.id;
    document.getElementById('u_nombre').value = u.nombre;
    document.getElementById('u_email').value = u.email;
    document.getElementById('u_telefono').value = u.telefono;
    document.getElementById('u_rol').value = u.rol;
    document.getElementById('u_bio').value = u.bio;
    document.getElementById('modal-usuario').style.display = 'flex';
}

function limpiarForm() {
    document.getElementById('u_id').value = '';
    document.getElementById('u_nombre').value = '';
    document.getElementById('u_email').value = '';
    document.getElementById('u_telefono').value = '';
    document.getElementById('u_rol').value = 'estudiante';
    document.getElementById('u_bio').value = '';
}

// Cerrar modal al hacer click fuera
window.onclick = function(event) {
    if (event.target == document.getElementById('modal-usuario')) {
        cerrarModal();
    }
}
</script>

<?php require_once 'admin_footer.php'; ?>
