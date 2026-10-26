-- Migración 20261025_certificados_emitidos
-- Hasta ahora ningún certificado quedaba registrado: el código se recalculaba y verificar.php
-- recorría todas las inscripciones y pagos comprobando además que el curso siguiera al 100%.
-- Publicar una lección nueva invalidaba certificados ya entregados y la fecha impresa era la
-- de la descarga. Desde esta versión el certificado se registra al emitirse y no cambia.
-- Registra como emitidos los cursos y rutas ya completados, con el código del formato
-- antiguo (el que ya tienen impreso) y como fecha la de la última lección completada.
-- Requiere 20261024_publicar_contenido (cuenta solo lo publicado). Solo registra la primera
-- vez: después los certificados los emite la aplicación con el formato nuevo.
-- Idempotente: se puede ejecutar más de una vez sin efectos adicionales.
-- Compatible con MariaDB 10.4+ (local) y 11.4 (producción).

-- Las fechas de progreso se leen en la hora de Colombia, igual que en la aplicación
SET time_zone = '-05:00';

CREATE TABLE IF NOT EXISTS `certificados_emitidos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` enum('curso','ruta') NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `codigo` varchar(32) NOT NULL,
  `formato` enum('legado','hmac') NOT NULL,
  `fecha_finalizacion` datetime NOT NULL COMMENT 'Última lección completada: la fecha impresa',
  `emitido_at` datetime NOT NULL DEFAULT current_timestamp(),
  `titulo` varchar(255) NOT NULL COMMENT 'Copia al emitir',
  `duracion` varchar(100) DEFAULT NULL,
  `nivel` varchar(100) DEFAULT NULL,
  `firmante_nombre` varchar(100) DEFAULT NULL,
  `firmante_cargo` varchar(100) DEFAULT NULL,
  `firmante_firma_img` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cert_producto` (`tipo`,`usuario_id`,`producto_id`),
  KEY `idx_cert_codigo` (`codigo`),
  KEY `idx_cert_usuario` (`usuario_id`),
  CONSTRAINT `certificados_emitidos_usuario_fk` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cursos completados por usuario (todas sus lecciones publicadas) y fecha de la última
DROP TEMPORARY TABLE IF EXISTS `tmp_cursos_completos`;
CREATE TEMPORARY TABLE `tmp_cursos_completos` AS
SELECT x.`usuario_id`, x.`curso_id`, x.`ultima`
FROM (
    SELECT p.`usuario_id`, m.`curso_id`, COUNT(DISTINCT p.`leccion_id`) AS hechas, MAX(p.`fecha`) AS ultima
    FROM `progreso` p
    JOIN `lecciones` l ON l.`id` = p.`leccion_id`
    JOIN `modulos` m ON m.`id` = l.`modulo_id`
    WHERE p.`completado` = 1 AND l.`estado` = 'publicado' AND m.`estado` = 'publicado'
    GROUP BY p.`usuario_id`, m.`curso_id`
) x
WHERE x.hechas = (
    SELECT COUNT(*) FROM `lecciones` l2 JOIN `modulos` m2 ON m2.`id` = l2.`modulo_id`
    WHERE m2.`curso_id` = x.`curso_id` AND l2.`estado` = 'publicado' AND m2.`estado` = 'publicado'
);

-- Cursos: los que verificar.php validaba (inscripción o pago verificado del curso)
INSERT IGNORE INTO `certificados_emitidos`
    (`tipo`, `usuario_id`, `producto_id`, `codigo`, `formato`, `fecha_finalizacion`, `titulo`, `duracion`, `nivel`, `firmante_nombre`, `firmante_cargo`, `firmante_firma_img`)
SELECT 'curso', cc.`usuario_id`, cc.`curso_id`,
       UPPER(LEFT(MD5(CONCAT(cc.`usuario_id`, cc.`curso_id`, 'WSA2026')), 12)), 'legado', cc.`ultima`,
       c.`titulo`, c.`duracion`, c.`nivel`, f.`nombre`, f.`cargo`, f.`firma_img`
FROM `tmp_cursos_completos` cc
JOIN `cursos` c ON c.`id` = cc.`curso_id`
JOIN `usuarios` u ON u.`id` = cc.`usuario_id`
LEFT JOIN `firmantes` f ON f.`id` = c.`firmante_id`
WHERE (EXISTS (SELECT 1 FROM `inscripciones` i WHERE i.`usuario_id` = cc.`usuario_id` AND i.`curso_id` = cc.`curso_id`)
       OR EXISTS (SELECT 1 FROM `pagos` pg WHERE pg.`usuario_id` = cc.`usuario_id` AND pg.`producto_tipo` = 'curso'
                  AND pg.`producto_id` = cc.`curso_id` AND pg.`estado` = 'verificado'))
  AND NOT EXISTS (SELECT 1 FROM `migraciones_sistema` WHERE `id` = '20261025_certificados_emitidos');

-- Rutas: pago verificado de la ruta y todos sus cursos completos
INSERT IGNORE INTO `certificados_emitidos`
    (`tipo`, `usuario_id`, `producto_id`, `codigo`, `formato`, `fecha_finalizacion`, `titulo`, `duracion`, `nivel`, `firmante_nombre`, `firmante_cargo`, `firmante_firma_img`)
SELECT 'ruta', pr.`usuario_id`, pr.`ruta_id`,
       UPPER(LEFT(MD5(CONCAT(pr.`usuario_id`, pr.`ruta_id`, 'RUTA2026')), 12)), 'legado', MAX(cc.`ultima`),
       r.`titulo`, r.`duracion_estimada`, r.`nivel`, f.`nombre`, f.`cargo`, f.`firma_img`
FROM (
    SELECT DISTINCT `usuario_id`, `producto_id` AS ruta_id FROM `pagos`
    WHERE `producto_tipo` = 'ruta' AND `estado` = 'verificado' AND `usuario_id` IS NOT NULL
) pr
JOIN `rutas_aprendizaje` r ON r.`id` = pr.`ruta_id`
JOIN `usuarios` u ON u.`id` = pr.`usuario_id`
JOIN `ruta_cursos` rc ON rc.`ruta_id` = pr.`ruta_id`
LEFT JOIN `tmp_cursos_completos` cc ON cc.`usuario_id` = pr.`usuario_id` AND cc.`curso_id` = rc.`curso_id`
LEFT JOIN `firmantes` f ON f.`id` = r.`firmante_id`
WHERE NOT EXISTS (SELECT 1 FROM `migraciones_sistema` WHERE `id` = '20261025_certificados_emitidos')
GROUP BY pr.`usuario_id`, pr.`ruta_id`, r.`titulo`, r.`duracion_estimada`, r.`nivel`, f.`nombre`, f.`cargo`, f.`firma_img`
HAVING COUNT(*) = COUNT(cc.`curso_id`);

DROP TEMPORARY TABLE IF EXISTS `tmp_cursos_completos`;

INSERT IGNORE INTO `migraciones_sistema` (`id`, `aplicada_at`, `detalle`)
VALUES ('20261025_certificados_emitidos', NOW(), 'Tabla certificados_emitidos y registro de los cursos y rutas ya completados (código antiguo, fecha de finalización)');
