<?php
// Solo admin; admin_auth valida el token CSRF de todo POST
require_once __DIR__ . '/admin_auth.php';
require_rol('admin');
require_once 'admin_header.php';
require_once __DIR__ . '/../helpers/upload_helper.php';
require_once __DIR__ . '/../helpers/plantillas_helper.php';

$msg = '';

// Imagen subida: MIME real con finfo, extensión derivada del MIME y nombre aleatorio (upload_helper).
// Si no hay archivo o no es una imagen válida, se conserva el valor actual.
function processImageUpload($fileField, $currentValue) {
    global $msg;
    if (!hay_archivo_subido($fileField)) return $currentValue;
    try {
        return 'uploads/' . guardar_imagen_subida($_FILES[$fileField], __DIR__ . '/../uploads', $fileField);
    } catch (RuntimeException $e) {
        $msg = 'Imagen no válida (' . $fileField . '): ' . $e->getMessage();
        return $currentValue;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'estructurales') {
        $btn1      = trim($_POST['btn_principal_texto']);
        $btn2      = trim($_POST['btn_secundario_texto']);
        $img_feat  = processImageUpload('img_features', $_POST['img_features_actual']);
        $img_cta   = processImageUpload('img_cta', $_POST['img_cta_actual']);
        // El favicon se configura en Configuración > Identidad, junto al logo

        $pdo->prepare("UPDATE banner SET btn_principal_texto=?, btn_secundario_texto=?, img_features=?, img_cta=? WHERE id=1")
            ->execute([$btn1, $btn2, $img_feat, $img_cta]);
        $msg = "Imágenes estructurales actualizadas.";
    }
    elseif ($action === 'textos_globales') {
        $f_tit = trim($_POST['features_titulo']);
        $f_sub = trim($_POST['features_subtitulo']);
        $c_tit = trim($_POST['cta_titulo']);
        $c_sub = trim($_POST['cta_subtitulo']);
        $footer = trim($_POST['footer_leyenda']);

        $pdo->prepare("UPDATE banner SET features_titulo=?, features_subtitulo=?, cta_titulo=?, cta_subtitulo=?, footer_leyenda=? WHERE id=1")
            ->execute([$f_tit, $f_sub, $c_tit, $c_sub, $footer]);
        $msg = "Textos globales actualizados.";
    }
    elseif ($action === 'legales') {
        $privacidad = trim($_POST['politica_privacidad']);
        $cookies = trim($_POST['politica_cookies']);
        
        $pdo->prepare("UPDATE ajustes SET politica_privacidad=?, politica_cookies=? WHERE id=1")
            ->execute([$privacidad, $cookies]);
        $msg = "Textos legales actualizados.";
    }
    elseif ($action === 'add_slide' || $action === 'edit_slide') {
        $titulo = trim($_POST['titulo']);
        $subtitulo = trim($_POST['subtitulo']);
        $orden = (int)$_POST['orden'];
        $id = $_POST['id'] ?? null;
        $imagen = processImageUpload('imagen_fondo', $_POST['imagen_actual'] ?? '');

        if ($action === 'add_slide') {
            $pdo->prepare("INSERT INTO banner_slides (titulo, subtitulo, imagen_fondo, orden) VALUES (?, ?, ?, ?)")->execute([$titulo, $subtitulo, $imagen, $orden]);
            $msg = "Slide añadida correctamente.";
        } else {
            $pdo->prepare("UPDATE banner_slides SET titulo=?, subtitulo=?, imagen_fondo=?, orden=? WHERE id=?")->execute([$titulo, $subtitulo, $imagen, $orden, $id]);
            $msg = "Slide actualizada.";
        }
    }
    elseif ($action === 'delete_slide') {
        $pdo->prepare("DELETE FROM banner_slides WHERE id=?")->execute([$_POST['id']]);
        $msg = "Slide eliminada.";
    }
    elseif ($action === 'add_testimonio' || $action === 'edit_testimonio') {
        $quote = trim($_POST['quote']);
        $author_name = trim($_POST['author_name']);
        $author_position = trim($_POST['author_position']);
        $orden = (int)$_POST['orden'];
        $id = $_POST['id'] ?? null;
        $image_url = processImageUpload('image_url', $_POST['imagen_actual'] ?? '');

        if ($action === 'add_testimonio') {
            $pdo->prepare("INSERT INTO testimonios (quote, author_name, author_position, image_url, orden) VALUES (?, ?, ?, ?, ?)")->execute([$quote, $author_name, $author_position, $image_url, $orden]);
            $msg = "Testimonio añadido correctamente.";
        } else {
            $pdo->prepare("UPDATE testimonios SET quote=?, author_name=?, author_position=?, image_url=?, orden=? WHERE id=?")->execute([$quote, $author_name, $author_position, $image_url, $orden, $id]);
            $msg = "Testimonio actualizado.";
        }
    }
    elseif ($action === 'delete_testimonio') {
        $pdo->prepare("DELETE FROM testimonios WHERE id=?")->execute([$_POST['id']]);
        $msg = "Testimonio eliminado.";
    }
    elseif ($action === 'add_caract' || $action === 'edit_caract') {
        $icono = trim($_POST['icono']);
        $titulo = trim($_POST['titulo']);
        $descripcion = trim($_POST['descripcion']);
        $orden = (int)$_POST['orden'];
        $id = $_POST['id'] ?? null;

        if ($action === 'add_caract') {
            $pdo->prepare("INSERT INTO caracteristicas (icono, titulo, descripcion, orden) VALUES (?, ?, ?, ?)")->execute([$icono, $titulo, $descripcion, $orden]);
            $msg = "Característica añadida correctamente.";
        } else {
            $pdo->prepare("UPDATE caracteristicas SET icono=?, titulo=?, descripcion=?, orden=? WHERE id=?")->execute([$icono, $titulo, $descripcion, $orden, $id]);
            $msg = "Característica actualizada.";
        }
    }
    elseif ($action === 'delete_caract') {
        $pdo->prepare("DELETE FROM caracteristicas WHERE id=?")->execute([$_POST['id']]);
        $msg = "Característica eliminada.";
    }
    elseif ($action === 'update_plantilla') {
        $plantilla = (int)($_POST['plantilla_activa'] ?? 0);
        if (in_array($plantilla, PLANTILLAS_DISPONIBLES, true)) {
            $pdo->prepare("UPDATE ajustes SET plantilla_activa=? WHERE id=1")->execute([$plantilla]);
            $ajustes['plantilla_activa'] = $plantilla;
            $msg = "Plantilla actualizada.";
        } else {
            $msg = "Esa plantilla no está disponible.";
        }
    }
    elseif ($action === 'update_bloques') {
        $ordenes = $_POST['orden'] ?? [];
        $visibles = $_POST['visible'] ?? [];
        foreach ($ordenes as $id => $orden) {
            $vis = isset($visibles[$id]) ? 1 : 0;
            $pdo->prepare("UPDATE bloques_home SET orden=?, visible=? WHERE id=?")->execute([$orden, $vis, $id]);
        }
        $msg = "Orden y visibilidad de los bloques actualizados.";
    }
}

$ajustes = $pdo->query("SELECT * FROM ajustes LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$bloques = $pdo->query("SELECT * FROM bloques_home ORDER BY orden ASC")->fetchAll(PDO::FETCH_ASSOC);

$banner = $pdo->query("SELECT * FROM banner LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$slides = $pdo->query("SELECT * FROM banner_slides ORDER BY orden ASC")->fetchAll(PDO::FETCH_ASSOC);
$testimonios = $pdo->query("SELECT * FROM testimonios ORDER BY orden ASC")->fetchAll(PDO::FETCH_ASSOC);
$caracteristicas = $pdo->query("SELECT * FROM caracteristicas ORDER BY orden ASC")->fetchAll(PDO::FETCH_ASSOC);

// Guardar tab activa
$active_tab = $_POST['action'] ?? 'plantillas';
if (strpos($active_tab, 'slide') !== false) $active_tab = 'slides';
if (strpos($active_tab, 'testimonio') !== false) $active_tab = 'testimonios';
if (strpos($active_tab, 'caract') !== false) $active_tab = 'caracteristicas';
if ($active_tab === 'textos_globales') $active_tab = 'textos';
if ($active_tab === 'update_plantilla') $active_tab = 'plantillas';
if ($active_tab === 'update_bloques') $active_tab = 'bloques';
?>
<style>
    .tabs { display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem; border-bottom: 2px solid #e2e8f0; padding-bottom: 1rem; }
    .tab-btn { padding: 0.8rem 1.5rem; background: none; border: none; font-size: 1rem; font-weight: 600; color: #64748b; cursor: pointer; border-radius: 8px; transition: 0.2s; }
    .tab-btn:hover { background: #f1f5f9; color: var(--primary); }
    .tab-btn.active { background: var(--primary); color: white; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    .card-list { display: grid; gap: 1.5rem; }
    .card-item { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; gap: 1.5rem; align-items: flex-start; }
    .card-item img { width: 120px; height: 80px; object-fit: cover; border-radius: 8px; }
    .card-item.testimonio-card img { width: 80px; height: 80px; border-radius: 50%; }
</style>

<h2>Estructuración del Sitio</h2>
<p style="color:var(--text-dim); margin-bottom: 2rem;">Gestiona las imágenes estructurales, textos globales, carrusel y testimonios de la academia.</p>

<?php if($msg): ?><div style="background:#dcfce7;color:#166534;padding:1rem;border-radius:8px;margin-bottom:1.5rem;font-weight:600;"><i class="fa-solid fa-check-circle"></i> <?= $msg ?></div><?php endif; ?>

<div class="tabs">
    <button class="tab-btn <?= $active_tab==='plantillas'?'active':'' ?>" onclick="showTab('plantillas')"><i class="fa-solid fa-palette"></i> Plantillas</button>
    <button class="tab-btn <?= $active_tab==='bloques'?'active':'' ?>" onclick="showTab('bloques')"><i class="fa-solid fa-cubes"></i> Bloques</button>
    <button class="tab-btn <?= $active_tab==='estructurales'?'active':'' ?>" onclick="showTab('estructurales')"><i class="fa-solid fa-layer-group"></i> Estructurales</button>
    <button class="tab-btn <?= $active_tab==='textos'?'active':'' ?>" onclick="showTab('textos')"><i class="fa-solid fa-font"></i> Textos Globales</button>
    <button class="tab-btn <?= $active_tab==='slides'?'active':'' ?>" onclick="showTab('slides')"><i class="fa-solid fa-images"></i> Slides del Banner</button>
    <button class="tab-btn <?= $active_tab==='caracteristicas'?'active':'' ?>" onclick="showTab('caracteristicas')"><i class="fa-solid fa-list-check"></i> Características</button>
    <button class="tab-btn <?= $active_tab==='testimonios'?'active':'' ?>" onclick="showTab('testimonios')"><i class="fa-solid fa-quote-left"></i> Testimonios</button>
    <button class="tab-btn <?= $active_tab==='legales'?'active':'' ?>" onclick="showTab('legales')"><i class="fa-solid fa-scale-balanced"></i> Textos Legales</button>
</div>

<!-- TAB 0: PLANTILLAS -->
<div id="tab-plantillas" class="tab-content <?= $active_tab==='plantillas'?'active':'' ?>">
    <form method="POST">
<?= csrf_input() ?>
        <input type="hidden" name="action" value="update_plantilla">
        <div style="display:flex; gap:1.5rem; flex-wrap:wrap;">
            <?php $plantilla_en_uso = plantilla_activa($ajustes); ?>
            <?php foreach (PLANTILLAS_DISPONIBLES as $i): ?>
            <label class="plantilla-label" style="flex: 1; min-width: 250px; max-width: 420px; cursor: pointer; border: 2px solid <?= $plantilla_en_uso === $i ? 'var(--primary)' : 'transparent' ?>; border-radius: 12px; padding: 10px; background: white; text-align: center;">
                <input type="radio" name="plantilla_activa" value="<?= $i ?>" <?= $plantilla_en_uso === $i ? 'checked' : '' ?> style="display:none;" onchange="document.querySelectorAll('.plantilla-label').forEach(l => l.style.borderColor = 'transparent'); this.parentElement.style.borderColor = 'var(--primary)';">
                <img src="../img/theme-<?= $i ?>-preview.jpg" alt="Theme <?= $i ?>" style="max-width:100%; border-radius:8px; margin-bottom: 10px;">
                <h4 style="margin:0;">Plantilla <?= $i ?></h4>
            </label>
            <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top: 1.5rem;"><i class="fa-solid fa-save"></i> Guardar Plantilla</button>
    </form>
</div>

<!-- TAB 0.5: BLOQUES -->
<div id="tab-bloques" class="tab-content <?= $active_tab==='bloques'?'active':'' ?>">
    <div style="background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0;">
        <p style="color:var(--text-dim); margin-bottom:1.5rem;">Arrastra los bloques para cambiar su orden en la página principal, o utiliza el interruptor para ocultarlos temporalmente.</p>
        
        <form method="POST">
<?= csrf_input() ?>
            <input type="hidden" name="action" value="update_bloques">
            
            <div id="sortable-bloques" style="display: flex; flex-direction: column; gap: 1rem;">
                <?php foreach($bloques as $b): ?>
                <div class="bloque-item" style="display:flex; align-items:center; gap: 1rem; background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px solid #cbd5e1; cursor: grab;">
                    <i class="fa-solid fa-grip-vertical" style="color:#94a3b8; font-size: 1.2rem;"></i>
                    <div style="flex: 1;">
                        <h4 style="margin: 0; font-size: 1rem;"><?= htmlspecialchars($b['nombre_mostrar']) ?> <span style="font-size:0.8rem; color:#64748b; font-weight:normal;">(<?= htmlspecialchars($b['identificador']) ?>)</span></h4>
                    </div>
                    <div style="display:flex; align-items:center; gap: 0.5rem;">
                        <label style="display:flex; align-items:center; cursor:pointer;">
                            <span style="margin-right: 0.5rem; font-size: 0.9rem; color: #475569;">Visible</span>
                            <input type="checkbox" name="visible[<?= $b['id'] ?>]" value="1" <?= $b['visible'] ? 'checked' : '' ?> style="transform: scale(1.2);">
                        </label>
                        <!-- Campo hidden para el orden que SortableJS actualizará -->
                        <input type="hidden" name="orden[<?= $b['id'] ?>]" class="bloque-orden-input" value="<?= $b['orden'] ?>">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <button type="submit" class="btn btn-primary" style="margin-top: 1.5rem;"><i class="fa-solid fa-save"></i> Guardar Bloques</button>
        </form>
    </div>
</div>

<!-- TAB 1: ESTRUCTURALES -->
<div id="tab-estructurales" class="tab-content <?= $active_tab==='estructurales'?'active':'' ?>">
    <form method="POST" enctype="multipart/form-data">
<?= csrf_input() ?>
        <input type="hidden" name="action" value="estructurales">
        <!-- Campos de fav, features, cta omitidos para brevedad en esta reconstrucción, pegaré de tu index -->
        <p style="background:#f1f5f9; border:1px solid #e2e8f0; border-radius:10px; padding:0.9rem 1.2rem; color:#475569; font-size:0.9rem; margin:0 0 1.5rem;">
            <i class="fa-solid fa-circle-info"></i> El logo, el favicon y los colores de la marca están en <a href="configuracion" style="font-weight:600;">Configuración › Identidad y Apariencia</a>.
        </p>

        <div style="background:white; padding:1.5rem; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:1.5rem;">
            <h3><i class="fa-solid fa-computer-mouse"></i> Botones Globales del Hero</h3>
            <div style="display:flex;gap:1rem;">
                <div class="form-group" style="flex:1">
                    <label>Texto Botón Principal</label>
                    <input type="text" name="btn_principal_texto" value="<?= htmlspecialchars($banner['btn_principal_texto'] ?? '') ?>" class="form-control">
                </div>
                <div class="form-group" style="flex:1">
                    <label>Texto Botón Secundario</label>
                    <input type="text" name="btn_secundario_texto" value="<?= htmlspecialchars($banner['btn_secundario_texto'] ?? '') ?>" class="form-control">
                </div>
            </div>
        </div>

        <div style="background:white; padding:1.5rem; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:1.5rem;">
            <h3><i class="fa-solid fa-puzzle-piece"></i> Ilustraciones</h3>
            <div style="display:flex; gap:2rem; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:300px;">
                    <label><strong>Imagen "Por qué elegirnos"</strong></label>
                    <?php if ($banner['img_features'] ?? ''): ?>
                        <img src="<?= htmlspecialchars(strpos($banner['img_features'], 'http') === 0 ? $banner['img_features'] : '../' . $banner['img_features']) ?>" style="max-width:100%; height:150px; object-fit:cover; border-radius:8px; margin:10px 0 15px;">
                    <?php endif; ?>
                    <input type="hidden" name="img_features_actual" value="<?= htmlspecialchars($banner['img_features'] ?? '') ?>">
                    <input type="file" name="img_features" accept="image/*">
                </div>
                <div class="form-group" style="flex:1; min-width:300px;">
                    <label><strong>Imagen "Llamado a la acción" (CTA)</strong></label>
                    <?php if ($banner['img_cta'] ?? ''): ?>
                        <img src="<?= htmlspecialchars(strpos($banner['img_cta'], 'http') === 0 ? $banner['img_cta'] : '../' . $banner['img_cta']) ?>" style="max-width:100%; height:150px; object-fit:cover; border-radius:8px; margin:10px 0 15px;">
                    <?php endif; ?>
                    <input type="hidden" name="img_cta_actual" value="<?= htmlspecialchars($banner['img_cta'] ?? '') ?>">
                    <input type="file" name="img_cta" accept="image/*">
                </div>
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Guardar Estructurales</button>
    </form>
</div>

<!-- TAB 2: TEXTOS GLOBALES -->
<div id="tab-textos" class="tab-content <?= $active_tab==='textos'?'active':'' ?>">
    <form method="POST">
<?= csrf_input() ?>
        <input type="hidden" name="action" value="textos_globales">
        
        <div style="background:white; padding:1.5rem; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:1.5rem;">
            <h3><i class="fa-solid fa-star"></i> Sección "Por qué elegirnos"</h3>
            <div class="form-group">
                <label>Título</label>
                <input type="text" name="features_titulo" value="<?= htmlspecialchars($banner['features_titulo'] ?? '') ?>" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Subtítulo / Párrafo</label>
                <textarea name="features_subtitulo" class="form-control" rows="3"><?= htmlspecialchars($banner['features_subtitulo'] ?? '') ?></textarea>
            </div>
        </div>

        <div style="background:white; padding:1.5rem; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:1.5rem;">
            <h3><i class="fa-solid fa-envelope"></i> Sección "Más Información" (CTA Formulario)</h3>
            <div class="form-group">
                <label>Título</label>
                <input type="text" name="cta_titulo" value="<?= htmlspecialchars($banner['cta_titulo'] ?? '') ?>" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Subtítulo / Párrafo</label>
                <textarea name="cta_subtitulo" class="form-control" rows="2"><?= htmlspecialchars($banner['cta_subtitulo'] ?? '') ?></textarea>
            </div>
        </div>

        <div style="background:white; padding:1.5rem; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:1.5rem;">
            <h3><i class="fa-solid fa-rocket"></i> Leyenda del Footer</h3>
            <div class="form-group">
                <label>Párrafo descriptivo del pie de página</label>
                <textarea name="footer_leyenda" class="form-control" rows="2"><?= htmlspecialchars($banner['footer_leyenda'] ?? '') ?></textarea>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Guardar Textos</button>
    </form>
</div>

<!-- TAB 3: SLIDES -->
<div id="tab-slides" class="tab-content <?= $active_tab==='slides'?'active':'' ?>">
    <div style="margin-bottom: 2rem;">
        <button type="button" class="btn btn-primary" onclick="toggleForm('form-slide')"><i class="fa-solid fa-plus"></i> Añadir Slide</button>
    </div>

    <!-- Form Add/Edit Slide -->
    <div id="form-slide" style="display:none; background:white; padding:1.5rem; border-radius:12px; border:1px solid var(--primary); margin-bottom:2rem;">
        <h3 id="slide-form-title">Añadir Slide</h3>
        <form method="POST" enctype="multipart/form-data">
<?= csrf_input() ?>
            <input type="hidden" name="action" id="slide-action" value="add_slide">
            <input type="hidden" name="id" id="slide-id" value="">
            <input type="hidden" name="imagen_actual" id="slide-imagen-actual" value="">
            <div class="form-group">
                <label>Título</label>
                <input type="text" name="titulo" id="slide-titulo" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Subtítulo</label>
                <textarea name="subtitulo" id="slide-subtitulo" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label>Orden</label>
                <input type="number" name="orden" id="slide-orden" class="form-control" value="0" style="width:100px;">
            </div>
            <div class="form-group">
                <label>Imagen de Fondo</label>
                <input type="file" name="imagen_fondo" id="slide-file" accept="image/*">
            </div>
            <button type="submit" class="btn btn-primary">Guardar Slide</button>
            <button type="button" class="btn btn-outline" onclick="toggleForm('form-slide')">Cancelar</button>
        </form>
    </div>

    <div class="card-list">
        <?php foreach($slides as $s): ?>
        <div class="card-item">
            <img src="<?= htmlspecialchars(strpos($s['imagen_fondo'], 'http') === 0 ? $s['imagen_fondo'] : '../' . $s['imagen_fondo']) ?>" alt="Slide">
            <div style="flex:1;">
                <h4 style="margin:0 0 0.5rem 0;"><?= htmlspecialchars($s['titulo']) ?> (Orden: <?= $s['orden'] ?>)</h4>
                <p style="margin:0; color:#64748b; font-size:0.9rem;"><?= htmlspecialchars($s['subtitulo']) ?></p>
            </div>
            <div style="display:flex; gap:0.5rem;">
                <button type="button" class="btn btn-outline" style="padding:0.5rem;" onclick="editSlide(<?= htmlspecialchars(json_encode($s)) ?>)"><i class="fa-solid fa-pencil"></i></button>
                <form method="POST" onsubmit="return confirm('¿Eliminar esta slide?')">
<?= csrf_input() ?>
                    <input type="hidden" name="action" value="delete_slide">
                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <button type="submit" class="btn btn-danger" style="padding:0.5rem;"><i class="fa-solid fa-trash"></i></button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($slides)): ?><p>No hay slides registradas.</p><?php endif; ?>
    </div>
</div>

<!-- TAB 4: CARACTERÍSTICAS -->
<div id="tab-caracteristicas" class="tab-content <?= $active_tab==='caracteristicas'?'active':'' ?>">
    <div style="margin-bottom: 2rem;">
        <button type="button" class="btn btn-primary" onclick="toggleForm('form-caract')"><i class="fa-solid fa-plus"></i> Añadir Característica</button>
        <p style="color:#64748b; font-size:0.9rem; margin-top:0.5rem;"><i class="fa-solid fa-circle-info"></i> Se recomienda tener exactamente 3 o 6 características para no romper la estética de la cuadrícula en la página principal.</p>
    </div>

    <!-- Form Add/Edit Caract -->
    <div id="form-caract" style="display:none; background:white; padding:1.5rem; border-radius:12px; border:1px solid var(--primary); margin-bottom:2rem;">
        <h3 id="caract-form-title">Añadir Característica</h3>
        <form method="POST">
<?= csrf_input() ?>
            <input type="hidden" name="action" id="caract-action" value="add_caract">
            <input type="hidden" name="id" id="caract-id" value="">
            <div class="form-group">
                <label>Icono (Clase de FontAwesome)</label>
                <div style="display:flex; gap:1rem; align-items:center;">
                    <input type="text" name="icono" id="caract-icono" class="form-control" placeholder="Ej. fa-solid fa-star" required>
                    <a href="https://fontawesome.com/search?o=r&m=free" target="_blank" class="btn btn-outline-light" style="white-space:nowrap; border-color:#cbd5e1; color:#334155;"><i class="fa-solid fa-search"></i> Buscar Iconos</a>
                </div>
            </div>
            <div class="form-group">
                <label>Título</label>
                <input type="text" name="titulo" id="caract-titulo" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Descripción</label>
                <textarea name="descripcion" id="caract-descripcion" class="form-control" rows="2" required></textarea>
            </div>
            <div class="form-group">
                <label>Orden</label>
                <input type="number" name="orden" id="caract-orden" class="form-control" value="0" style="width:100px;">
            </div>
            <button type="submit" class="btn btn-primary">Guardar Característica</button>
            <button type="button" class="btn btn-outline" onclick="toggleForm('form-caract')">Cancelar</button>
        </form>
    </div>

    <div class="card-list">
        <?php foreach($caracteristicas as $c): ?>
        <div class="card-item" style="align-items:center;">
            <div style="font-size: 2rem; color: var(--primary); width: 60px; text-align:center;">
                <i class="<?= htmlspecialchars($c['icono']) ?>"></i>
            </div>
            <div style="flex:1;">
                <h4 style="margin:0 0 0.5rem 0;"><?= htmlspecialchars($c['titulo']) ?></h4>
                <p style="margin:0; color:#64748b; font-size:0.9rem;"><?= htmlspecialchars($c['descripcion']) ?></p>
                <span style="font-size:0.8rem; color:#cbd5e1;">Orden: <?= $c['orden'] ?> | Icono: <?= htmlspecialchars($c['icono']) ?></span>
            </div>
            <div style="display:flex; gap:0.5rem;">
                <button type="button" class="btn btn-outline" style="padding:0.5rem;" onclick="editCaract(<?= htmlspecialchars(json_encode($c)) ?>)"><i class="fa-solid fa-pencil"></i></button>
                <form method="POST" onsubmit="return confirm('¿Eliminar esta característica?')">
<?= csrf_input() ?>
                    <input type="hidden" name="action" value="delete_caract">
                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                    <button type="submit" class="btn btn-danger" style="padding:0.5rem;"><i class="fa-solid fa-trash"></i></button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($caracteristicas)): ?><p>No hay características registradas.</p><?php endif; ?>
    </div>
</div>

<!-- TAB 5: TESTIMONIOS -->
<div id="tab-testimonios" class="tab-content <?= $active_tab==='testimonios'?'active':'' ?>">
    <div style="margin-bottom: 2rem;">
        <button type="button" class="btn btn-primary" onclick="toggleForm('form-testimonio')"><i class="fa-solid fa-plus"></i> Añadir Testimonio</button>
    </div>

    <!-- Form Add/Edit Testimonio -->
    <div id="form-testimonio" style="display:none; background:white; padding:1.5rem; border-radius:12px; border:1px solid var(--primary); margin-bottom:2rem;">
        <h3 id="test-form-title">Añadir Testimonio</h3>
        <form method="POST" enctype="multipart/form-data">
<?= csrf_input() ?>
            <input type="hidden" name="action" id="test-action" value="add_testimonio">
            <input type="hidden" name="id" id="test-id" value="">
            <input type="hidden" name="imagen_actual" id="test-imagen-actual" value="">
            <div class="form-group">
                <label>Testimonio (Leyenda)</label>
                <textarea name="quote" id="test-quote" class="form-control" rows="3" required></textarea>
            </div>
            <div style="display:flex; gap:1rem;">
                <div class="form-group" style="flex:1">
                    <label>Nombre del Autor</label>
                    <input type="text" name="author_name" id="test-name" class="form-control" required>
                </div>
                <div class="form-group" style="flex:1">
                    <label>Cargo / Posición</label>
                    <input type="text" name="author_position" id="test-position" class="form-control" required>
                </div>
            </div>
            <div class="form-group">
                <label>Orden</label>
                <input type="number" name="orden" id="test-orden" class="form-control" value="0" style="width:100px;">
            </div>
            <div class="form-group">
                <label>Fotografía del Autor</label>
                <input type="file" name="image_url" id="test-file" accept="image/*">
            </div>
            <button type="submit" class="btn btn-primary">Guardar Testimonio</button>
            <button type="button" class="btn btn-outline" onclick="toggleForm('form-testimonio')">Cancelar</button>
        </form>
    </div>

    <div class="card-list">
        <?php foreach($testimonios as $t): ?>
        <div class="card-item testimonio-card">
            <img src="<?= htmlspecialchars(strpos($t['image_url'], 'http') === 0 ? $t['image_url'] : '../' . $t['image_url']) ?>" alt="Autor">
            <div style="flex:1;">
                <h4 style="margin:0 0 0.2rem 0;"><?= htmlspecialchars($t['author_name']) ?></h4>
                <p style="margin:0 0 0.5rem 0; color:var(--primary); font-size:0.85rem; font-weight:600;"><?= htmlspecialchars($t['author_position']) ?></p>
                <p style="margin:0; color:#64748b; font-style:italic;">"<?= htmlspecialchars($t['quote']) ?>"</p>
                <span style="font-size:0.8rem; color:#cbd5e1;">Orden: <?= $t['orden'] ?></span>
            </div>
            <div style="display:flex; gap:0.5rem;">
                <button type="button" class="btn btn-outline" style="padding:0.5rem;" onclick="editTestimonio(<?= htmlspecialchars(json_encode($t)) ?>)"><i class="fa-solid fa-pencil"></i></button>
                <form method="POST" onsubmit="return confirm('¿Eliminar testimonio?')">
<?= csrf_input() ?>
                    <input type="hidden" name="action" value="delete_testimonio">
                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                    <button type="submit" class="btn btn-danger" style="padding:0.5rem;"><i class="fa-solid fa-trash"></i></button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($testimonios)): ?><p>No hay testimonios registrados.</p><?php endif; ?>
    </div>
</div>

<!-- TAB 6: TEXTOS LEGALES -->
<div id="tab-legales" class="tab-content <?= $active_tab==='legales'?'active':'' ?>">
    <div style="background: white; padding: 2rem; border-radius: 12px; border: 1px solid #e2e8f0;">
        <h3 style="margin-top:0;">Textos Legales (GDPR)</h3>
        <p style="color:var(--text-dim); margin-bottom:1.5rem;">Estos textos se mostrarán en las páginas de políticas ubicadas en el footer. Puedes usar HTML básico.</p>
        <form method="POST">
<?= csrf_input() ?>
            <input type="hidden" name="action" value="legales">
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label style="font-weight:bold; display:block; margin-bottom:0.5rem;">Política de Privacidad</label>
                <textarea name="politica_privacidad" class="form-control" rows="12" style="width:100%; padding:0.8rem; border:1px solid #cbd5e1; border-radius:8px;"><?= htmlspecialchars($ajustes['politica_privacidad'] ?? '') ?></textarea>
            </div>
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label style="font-weight:bold; display:block; margin-bottom:0.5rem;">Política de Cookies</label>
                <textarea name="politica_cookies" class="form-control" rows="12" style="width:100%; padding:0.8rem; border:1px solid #cbd5e1; border-radius:8px;"><?= htmlspecialchars($ajustes['politica_cookies'] ?? '') ?></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Guardar Políticas</button>
        </form>
    </div>
</div>

<script>
function showTab(tabId) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.querySelector(`button[onclick="showTab('${tabId}')"]`).classList.add('active');
    document.getElementById(`tab-${tabId}`).classList.add('active');
}

function toggleForm(id) {
    const f = document.getElementById(id);
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
    if(f.style.display === 'none') {
        // Reset forms on hide
        if(id === 'form-slide') {
            document.getElementById('slide-action').value = 'add_slide';
            document.getElementById('slide-id').value = '';
            document.getElementById('slide-titulo').value = '';
            document.getElementById('slide-subtitulo').value = '';
            document.getElementById('slide-orden').value = '0';
            document.getElementById('slide-form-title').innerText = 'Añadir Slide';
            document.getElementById('slide-file').required = true;
        } else if(id === 'form-testimonio') {
            document.getElementById('test-action').value = 'add_testimonio';
            document.getElementById('test-id').value = '';
            document.getElementById('test-quote').value = '';
            document.getElementById('test-name').value = '';
            document.getElementById('test-position').value = '';
            document.getElementById('test-orden').value = '0';
            document.getElementById('test-form-title').innerText = 'Añadir Testimonio';
        } else if(id === 'form-caract') {
            document.getElementById('caract-action').value = 'add_caract';
            document.getElementById('caract-id').value = '';
            document.getElementById('caract-icono').value = '';
            document.getElementById('caract-titulo').value = '';
            document.getElementById('caract-descripcion').value = '';
            document.getElementById('caract-orden').value = '0';
            document.getElementById('caract-form-title').innerText = 'Añadir Característica';
        }
    }
}

function editSlide(slide) {
    document.getElementById('form-slide').style.display = 'block';
    document.getElementById('slide-action').value = 'edit_slide';
    document.getElementById('slide-id').value = slide.id;
    document.getElementById('slide-imagen-actual').value = slide.imagen_fondo;
    document.getElementById('slide-titulo').value = slide.titulo;
    document.getElementById('slide-subtitulo').value = slide.subtitulo;
    document.getElementById('slide-orden').value = slide.orden;
    document.getElementById('slide-file').required = false;
    document.getElementById('slide-form-title').innerText = 'Editar Slide';
    window.scrollTo(0, document.getElementById('form-slide').offsetTop - 50);
}

function editTestimonio(test) {
    document.getElementById('form-testimonio').style.display = 'block';
    document.getElementById('test-action').value = 'edit_testimonio';
    document.getElementById('test-id').value = test.id;
    document.getElementById('test-imagen-actual').value = test.image_url;
    document.getElementById('test-quote').value = test.quote;
    document.getElementById('test-name').value = test.author_name;
    document.getElementById('test-position').value = test.author_position;
    document.getElementById('test-orden').value = test.orden;
    document.getElementById('test-form-title').innerText = 'Editar Testimonio';
    window.scrollTo(0, document.getElementById('form-testimonio').offsetTop - 50);
}

function editCaract(caract) {
    document.getElementById('form-caract').style.display = 'block';
    document.getElementById('caract-action').value = 'edit_caract';
    document.getElementById('caract-id').value = caract.id;
    document.getElementById('caract-icono').value = caract.icono;
    document.getElementById('caract-titulo').value = caract.titulo;
    document.getElementById('caract-descripcion').value = caract.descripcion;
    document.getElementById('caract-orden').value = caract.orden;
    document.getElementById('caract-form-title').innerText = 'Editar Característica';
    window.scrollTo(0, document.getElementById('form-caract').offsetTop - 50);
}
</script>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var el = document.getElementById('sortable-bloques');
    if (el) {
        new Sortable(el, {
            animation: 150,
            ghostClass: 'bg-blue-100',
            onEnd: function (evt) {
                // Update hidden inputs with new index + 1
                var items = el.querySelectorAll('.bloque-item');
                items.forEach((item, index) => {
                    item.querySelector('.bloque-orden-input').value = index + 1;
                });
            }
        });
    }
});
</script>

<?php require_once 'admin_footer.php'; ?>
