<?php
/**
 * Plantillas (temas) de la portada: components/theme-N/ y css/theme-N.css.
 *
 * Solo se ofrecen las terminadas. Las 3, 4 y 5 tienen solo un CSS de pocas líneas y
 * ningún componente propio (la 5 deja textos ilegibles), así que quedan ocultas hasta
 * completarlas. Para habilitar una, añadirla aquí.
 */

const PLANTILLAS_DISPONIBLES = [1, 2];

/** Número de la plantilla en uso; si la guardada no está disponible, la 1. */
function plantilla_activa(array $ajustes): int
{
    $plantilla = (int)($ajustes['plantilla_activa'] ?? 1);
    return in_array($plantilla, PLANTILLAS_DISPONIBLES, true) ? $plantilla : 1;
}
