<?php
// Mapea la configuración actual sin romper config.php existente
if (file_exists(dirname(__DIR__) . '/config.php')) {
    // Aquí se podrían cargar variables de config.php legacy si se requiere.
}

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'mesh_lms_db',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ],
    ],
];
