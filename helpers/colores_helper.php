<?php
/**
 * Colores de `ajustes`: se imprimen dentro de <style> y en los certificados, así que solo
 * se aceptan como #RGB o #RRGGBB. Un valor inválido se sustituye por el de reserva.
 */

const COLORES_AJUSTES = [
    'color_primario' => '#2A3547',
    'color_acento' => '#FF6B35',
    'cert_color_fondo' => '#2A3547',
    'cert_color_texto' => '#FFFFFF',
    'cert_color_acento' => '#FF6B35',
];

function color_hex_valido($valor, string $reserva): string
{
    $valor = trim((string)$valor);
    return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $valor) ? $valor : $reserva;
}

/** Sustituye los colores inválidos de los ajustes cargados (los que no están se dejan igual). */
function normalizar_colores_ajustes(array $ajustes): array
{
    foreach (COLORES_AJUSTES as $campo => $reserva) {
        if (array_key_exists($campo, $ajustes)) {
            $ajustes[$campo] = color_hex_valido($ajustes[$campo], $reserva);
        }
    }
    return $ajustes;
}
