<?php
/**
 * Mesh LMS Bootstrap
 * 
 * Prepara el entorno global sin romper el flujo actual legacy.
 */

define('ADINEX_START', microtime(true));

// Cargar Composer si existe en el futuro
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Cargar helpers de la nueva arquitectura
if (file_exists(__DIR__ . '/app/Shared/Support/config.php')) {
    require_once __DIR__ . '/app/Shared/Support/config.php';
}

// Aquí se pueden cargar configuraciones globales o registrar
// servicios sin iniciar sesión ni romper sesiones existentes.
