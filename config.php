<?php
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/vendor/autoload.php';

// Cargar variables de entorno
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Configuración de la base de datos
$db_host = $_ENV['DB_HOST'] ?? 'localhost';
$db_user = $_ENV['DB_USER'] ?? 'root';
$db_pass = $_ENV['DB_PASS'] ?? '';
$db_name = $_ENV['DB_NAME'] ?? 'mesh_lms_db';

// Zona horaria de la plataforma (APP_TIMEZONE en .env; por defecto Colombia, UTC-5).
// PHP y la sesión de MariaDB usan la misma para que date() y NOW() coincidan en cualquier
// servidor. La capa app/ (accesos, pagos, quizzes) guarda sus fechas en UTC de forma explícita.
if (!@date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Bogota')) {
    date_default_timezone_set('America/Bogota');
}

// Errores: nunca en pantalla; el log va fuera de la carpeta pública (public_html).
// LOG_DIR en .env permite cambiar la ubicación; por defecto, una carpeta hermana del proyecto.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Las trazas no incluyen argumentos (p. ej. la contraseña que recibe el constructor de PDO)
ini_set('zend.exception_ignore_args', '1');
$log_dir = rtrim($_ENV['LOG_DIR'] ?? (dirname(__DIR__) . '/mesh_logs'), '/\\');
if ((is_dir($log_dir) || @mkdir($log_dir, 0750, true)) && is_writable($log_dir)) {
    ini_set('error_log', $log_dir . '/error.log');
}

// Iniciar buffer de salida global para captura limpia de errores
if (ob_get_level() === 0) {
    ob_start();
}

// URL base
define('BASE_URL', rtrim($_ENV['BASE_URL'] ?? 'http://localhost/Mesh LMS', '/')); // sin barra final

// Manejador Global de Errores y Excepciones (antes de la conexión, para cubrir sus fallos)
require_once __DIR__ . '/helpers/error_handler.php';
require_once __DIR__ . '/helpers/colores_helper.php';

// Conexión PDO
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Offset fijo de la zona de PHP: no depende de que el servidor tenga cargadas las tablas de zonas horarias
    $pdo->exec("SET time_zone = '" . date('P') . "'");

    // Cargar Ajustes Dinámicos
    $stmt_ajustes = $pdo->query("SELECT * FROM ajustes LIMIT 1");
    $ajustes = normalizar_colores_ajustes($stmt_ajustes->fetch(PDO::FETCH_ASSOC) ?: []);

    // Logo cache buster
    $logo_path = __DIR__ . '/img/logo.png';
    $logo_version = file_exists($logo_path) ? filemtime($logo_path) : '2';

    // Cargar Favicon Global
    $stmt_fav = $pdo->query("SELECT img_favicon FROM banner LIMIT 1");
    $fav_row = $stmt_fav ? $stmt_fav->fetch(PDO::FETCH_ASSOC) : null;
    $global_fav = (!empty($fav_row['img_favicon'])) ? $fav_row['img_favicon'] : 'img/logo.png?v=' . $logo_version;
} catch(PDOException $e) {
    // Se registra el detalle en el log y el usuario solo ve la página 500 genérica
    responder_error_500($e);
}

define('FAVICON_URL', (strpos($global_fav, 'http') === 0) ? $global_fav : BASE_URL . '/' . $global_fav);

// Definir constante para que otros archivos la usen
define('LOGO_VERSION', $logo_version);

inicializar_manejador_errores($ajustes);

// Sesión
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configuración del correo (Gmail SMTP)
define('MAIL_HOST',     $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com');
define('MAIL_PORT',     $_ENV['MAIL_PORT'] ?? 587);
define('MAIL_USERNAME', $_ENV['MAIL_USERNAME'] ?? '');
define('MAIL_PASSWORD', $_ENV['MAIL_PASSWORD'] ?? '');
define('MAIL_FROM',     $_ENV['MAIL_FROM'] ?? '');
// Con ?: un valor vacío en `ajustes` usa el de reserva (con ?? se quedaba vacío y, por
// ejemplo, el aviso de nuevo pago al administrador se enviaba a una dirección en blanco)
define('MAIL_FROM_NAME', ($ajustes['nombre_academia'] ?? '') ?: ($_ENV['MAIL_FROM_NAME'] ?? 'Mesh LMS'));
define('MAIL_ADMIN',    ($ajustes['email_admin'] ?? '') ?: ($_ENV['MAIL_ADMIN'] ?? ''));

// WhatsApp
define('WHATSAPP_NUMBER', ($ajustes['whatsapp'] ?? '') ?: ($_ENV['WHATSAPP_NUMBER'] ?? ''));

// Versión de la plataforma (versionado semántico; historial en CHANGELOG.md)
define('APP_VERSION', '1.3.0');

// Global Academy Name
define('ACADEMY_NAME', ($ajustes['nombre_academia'] ?? '') ?: 'Mesh LMS');
// Nota mínima por defecto (%) de las lecciones calificables nuevas (Configuración › Evaluación).
// Cada lección guarda la suya en lecciones.puntaje_minimo; esta solo se usa al crearla o si falta.
define('PASSING_GRADE', min(100, max(1, (int)($ajustes['nota_aprobacion'] ?? 0) ?: 70)));
define('ACCESS_EXPIRATION_ENABLED', filter_var($_ENV['ACCESS_EXPIRATION_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('ACCESS_DURATION_DAYS', max(1, (int)($_ENV['ACCESS_DURATION_DAYS'] ?? 30)));

// Aplicar el modo mantenimiento después de iniciar la sesión y cargar los ajustes.
// La validación central evita que cada página implemente su propio bloqueo.
require_once __DIR__ . '/helpers/mantenimiento_helper.php';
aplicar_modo_mantenimiento($ajustes);
