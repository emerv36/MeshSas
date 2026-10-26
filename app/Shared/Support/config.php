<?php

if (!function_exists('adinex_config')) {
    /**
     * Obtiene un valor de configuración global de <?= htmlspecialchars(ACADEMY_NAME) ?>.
     * Ejemplo: adinex_config('app.name')
     *
     * @param string $key Clave de configuración, formato: 'archivo.clave'
     * @param mixed $default Valor por defecto si no se encuentra
     * @return mixed
     */
    function adinex_config($key, $default = null) {
        static $configs = [];
        
        $parts = explode('.', $key);
        $file = array_shift($parts);
        
        if (!isset($configs[$file])) {
            $path = dirname(__DIR__, 3) . "/config/{$file}.php";
            if (file_exists($path)) {
                $configs[$file] = require $path;
            } else {
                $configs[$file] = [];
            }
        }
        
        $value = $configs[$file];
        foreach ($parts as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } else {
                return $default;
            }
        }
        
        return $value;
    }
}
