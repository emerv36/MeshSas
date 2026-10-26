<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Esta migración solo puede ejecutarse por CLI.\n");
}

session_set_save_handler(
    static fn() => true, static fn() => true, static fn() => '',
    static fn() => true, static fn() => true, static fn() => true
);
session_start();
require_once dirname(__DIR__, 2) . '/config.php';
session_write_close();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS accesos_productos (
        id INT NOT NULL AUTO_INCREMENT,
        usuario_id INT NOT NULL,
        pago_id INT NULL,
        producto_tipo ENUM('curso','ruta') NOT NULL,
        producto_id INT NOT NULL,
        acceso_inicio DATETIME NOT NULL,
        acceso_fin DATETIME NOT NULL,
        origen ENUM('pago','gratis','administrativo','migracion') NOT NULL,
        revocado_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_acceso_pago (pago_id),
        KEY idx_acceso_usuario_vigencia (usuario_id, acceso_inicio, acceso_fin),
        KEY idx_acceso_producto (producto_tipo, producto_id),
        KEY idx_acceso_vencimiento (acceso_fin),
        CONSTRAINT accesos_productos_usuario_fk FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        CONSTRAINT accesos_productos_pago_fk FOREIGN KEY (pago_id) REFERENCES pagos(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$column = $pdo->query("SHOW COLUMNS FROM pagos LIKE 'verificado_at'")->fetch(PDO::FETCH_ASSOC);
if (!$column) $pdo->exec("ALTER TABLE pagos ADD COLUMN verificado_at DATETIME NULL AFTER estado");
echo "Migración de vigencia de accesos aplicada correctamente.\n";
