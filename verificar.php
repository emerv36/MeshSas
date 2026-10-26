<?php
require_once 'config.php';
require_once 'helpers/certificado_helper.php';
require_once 'helpers/limite_intentos.php';

$codigo = '';
$resultado = null;
$mensaje = '';

if (isset($_GET['codigo']) && !empty($_GET['codigo'])) {
    $codigo = strtoupper(trim(preg_replace('/[^a-zA-Z0-9]/', '', $_GET['codigo'])));
} elseif (isset($_POST['codigo']) && !empty($_POST['codigo'])) {
    $codigo = strtoupper(trim(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['codigo'])));
}
$codigo = substr($codigo, 0, 32);

if ($codigo) {
    // Los códigos que no existen cuentan para el límite por IP (evita probar códigos en masa)
    if ($espera = intentos_bloqueado($pdo, 'verificar', ip_cliente())) {
        http_response_code(429);
        $mensaje = "Demasiadas consultas desde tu conexión. Intenta de nuevo en $espera minutos.";
    } elseif ($cert = certificado_por_codigo($pdo, $codigo)) {
        // Solo certificados registrados al emitirse: no depende del progreso actual del curso
        $s_u = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = ?");
        $s_u->execute([$cert['usuario_id']]);
        $resultado = [
            'tipo' => $cert['tipo'] === 'ruta' ? 'Ruta de Aprendizaje' : 'Curso',
            'alumno' => (string)$s_u->fetchColumn(),
            'titulo' => $cert['titulo'],
            'duracion' => (string)$cert['duracion'],
            'fecha' => certificado_fecha($cert),
        ];
    } else {
        intentos_registrar($pdo, 'verificar', ip_cliente());
        $mensaje = "El código ingresado no corresponde a un certificado válido.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de Certificados - <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <link rel="icon" href="<?= FAVICON_URL ?>">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        :root {
            --primary: <?= $ajustes['color_primario'] ?? '#2A3547' ?>;
            --accent: <?= $ajustes['color_acento'] ?? '#FF6B35' ?>;
        }
        body {
            background-color: #f8fafc;
            font-family: 'Open Sans', sans-serif;
            margin: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .header {
            background: var(--primary);
            padding: 1rem 5%;
            display: flex;
            justify-content: center;
        }
        .header img {
            max-height: 50px;
        }
        .verificar-container {
            max-width: 600px;
            margin: 4rem auto;
            background: white;
            padding: 3rem;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            text-align: center;
            flex: 1;
        }
        .verificar-container h1 {
            font-family: 'Poppins', sans-serif;
            color: var(--primary);
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        .verificar-container p {
            color: #64748b;
            margin-bottom: 2rem;
        }
        .input-group {
            display: flex;
            margin-bottom: 2rem;
        }
        .input-group input {
            flex: 1;
            padding: 1rem 1.5rem;
            font-size: 1.1rem;
            border: 2px solid #e2e8f0;
            border-right: none;
            border-radius: 8px 0 0 8px;
            outline: none;
            transition: border-color 0.3s;
            text-transform: uppercase;
        }
        .input-group input:focus {
            border-color: var(--accent);
        }
        .input-group button {
            background: var(--accent);
            color: white;
            border: none;
            padding: 0 2rem;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 0 8px 8px 0;
            cursor: pointer;
            transition: background 0.3s;
        }
        .input-group button:hover {
            opacity: 0.9;
        }
        .result-card {
            background: #f0fdf4;
            border: 2px solid #bbf7d0;
            padding: 2rem;
            border-radius: 12px;
            text-align: left;
            animation: fadeIn 0.5s ease-in-out;
        }
        .result-card h3 {
            color: #166534;
            margin: 0 0 1.5rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-family: 'Poppins', sans-serif;
        }
        .result-item {
            margin-bottom: 1rem;
        }
        .result-label {
            font-size: 0.85rem;
            color: #166534;
            opacity: 0.8;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .result-value {
            font-size: 1.1rem;
            color: #14532d;
            font-weight: 600;
        }
        .error-card {
            background: #fef2f2;
            border: 2px solid #fecaca;
            color: #b91c1c;
            padding: 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .footer {
            text-align: center;
            padding: 2rem;
            color: #94a3b8;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <header class="header">
        <a href="index">
            <img src="img/logo.png?v=<?= LOGO_VERSION ?>" alt="<?= htmlspecialchars(ACADEMY_NAME) ?> Logo">
        </a>
    </header>

    <div class="verificar-container">
        <h1>Validar Certificado</h1>
        <p>Ingresa el código de 12 caracteres que se encuentra en la parte inferior del certificado para verificar su autenticidad.</p>

        <form method="POST" action="verificar">
            <div class="input-group">
                <input type="text" name="codigo" value="<?= htmlspecialchars($codigo) ?>" placeholder="Ej: A1B2C3D4E5F6" required maxlength="32">
                <button type="submit"><i class="fa-solid fa-search"></i> Verificar</button>
            </div>
        </form>

        <?php if ($resultado): ?>
            <div class="result-card">
                <h3><i class="fa-solid fa-circle-check"></i> Certificado Válido</h3>
                
                <div class="result-item">
                    <div class="result-label">Otorgado a</div>
                    <div class="result-value"><?= htmlspecialchars($resultado['alumno']) ?></div>
                </div>
                
                <div class="result-item">
                    <div class="result-label">Programa completado</div>
                    <div class="result-value"><?= htmlspecialchars($resultado['titulo']) ?> <span style="font-size:0.8rem; background:#dcfce7; padding:2px 6px; border-radius:4px; margin-left:5px; vertical-align:middle;"><?= $resultado['tipo'] ?></span></div>
                </div>

                <div class="result-item">
                    <div class="result-label">Carga horaria</div>
                    <div class="result-value"><?= htmlspecialchars($resultado['duracion']) ?></div>
                </div>

                <div class="result-item" style="margin-bottom:0;">
                    <div class="result-label">Fecha de finalización</div>
                    <div class="result-value"><?= htmlspecialchars($resultado['fecha']) ?></div>
                </div>
            </div>
        <?php elseif ($mensaje): ?>
            <div class="error-card">
                <i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>
    </div>

    <footer class="footer">
        &copy; <?= date('Y') ?> <?= htmlspecialchars(ACADEMY_NAME) ?>. Todos los derechos reservados.
    </footer>
</body>
</html>
