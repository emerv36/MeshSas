-- Migración 20261022_tareas_entregas
-- Al abrir una lección de tarea se registra una fila "vista, aún sin entrega" (fecha_visto,
-- sin archivo). archivo_ruta era NOT NULL sin valor por defecto: con sql_mode estricto ese
-- INSERT fallaba y la lección no abría; sin modo estricto quedaba '' y fecha_envio = ahora.
-- Además, las tareas aprobadas deben constar en el progreso como completadas
-- (progreso.completado = 1; track_time puede haber creado la fila con completado = 0).
-- Idempotente: se puede ejecutar más de una vez sin efectos adicionales.
-- Compatible con MariaDB 10.4+.

ALTER TABLE `tareas_entregas`
  MODIFY `archivo_ruta` varchar(255) NULL DEFAULT NULL,
  MODIFY `fecha_envio` datetime NULL DEFAULT NULL;

-- Filas "vistas" creadas sin modo estricto: sin archivo no hay envío
UPDATE `tareas_entregas`
SET `archivo_ruta` = NULL, `fecha_envio` = NULL
WHERE `archivo_ruta` = '';

-- Progreso de las tareas ya aprobadas por el docente
INSERT INTO `progreso` (`usuario_id`, `leccion_id`, `completado`)
SELECT DISTINCT te.`usuario_id`, te.`leccion_id`, 1
FROM `tareas_entregas` te
JOIN `lecciones` l ON l.`id` = te.`leccion_id`
JOIN `usuarios` u ON u.`id` = te.`usuario_id`
WHERE te.`estado` = 'aprobado'
ON DUPLICATE KEY UPDATE `completado` = 1;

INSERT IGNORE INTO `migraciones_sistema` (`id`, `aplicada_at`, `detalle`)
VALUES ('20261022_tareas_entregas', NOW(), 'tareas_entregas: archivo_ruta y fecha_envio admiten NULL y progreso de tareas aprobadas');
