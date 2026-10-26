<?php
require_once __DIR__ . '/config.php';

$error_info = [
    'ref_id'   => 'ERR-' . strtoupper(substr(md5((string)time()), 0, 8)),
    'es_debug' => false,
    'mensaje'  => 'Inconveniente interno del servidor.',
    'archivo'  => '',
    'linea'    => 0,
    'trace'    => ''
];

http_response_code(500);
require __DIR__ . '/resources/views/500.php';
