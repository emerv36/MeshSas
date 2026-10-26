-- Migración 20261026_lecciones_h5p
-- Nuevo tipo de lección 'h5p': el docente sube un paquete .h5p que se reproduce incrustado en la
-- clase (h5p-standalone). Los datos del paquete van en lecciones.contenido (JSON) y el resultado
-- de cada estudiante en h5p_resultados, igual que respuestas_excel para el Excel interactivo.
-- La lección se completa al aprobar (nota >= puntaje_minimo) o, si el docente la configura
-- como "solo completar", al terminar la actividad.
-- Idempotente: se puede ejecutar más de una vez sin efectos adicionales.
-- Compatible con MariaDB 10.4+.

-- MODIFY con la lista completa: repetirlo no cambia nada.
-- Mesh conserva su tipo 'clase_vivo' (Zoom/Jitsi): quitarlo de la lista vaciaría esas lecciones.
ALTER TABLE `lecciones`
  MODIFY `tipo` enum('video','pdf','quiz','texto','hoja_calculo','arrastrar','excel_interactivo','tarea','clase_vivo','h5p') NOT NULL DEFAULT 'video';

CREATE TABLE IF NOT EXISTS `h5p_resultados` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `leccion_id` int(11) NOT NULL,
  `puntaje` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Mejor porcentaje obtenido (0-100)',
  `puntaje_raw` decimal(10,2) DEFAULT NULL COMMENT 'Puntos del envío con el mejor porcentaje',
  `puntaje_max` decimal(10,2) DEFAULT NULL,
  `completado` tinyint(1) NOT NULL DEFAULT 0,
  `aprobado` tinyint(1) NOT NULL DEFAULT 0,
  `intentos` int(11) NOT NULL DEFAULT 0 COMMENT 'Envíos de resultado recibidos',
  `ultimo_envio` text DEFAULT NULL COMMENT 'Último resultado recibido (verbo, raw, max), para auditar',
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_h5p_alumno_leccion` (`usuario_id`,`leccion_id`),
  KEY `idx_h5p_leccion` (`leccion_id`),
  CONSTRAINT `fk_h5p_resultados_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_h5p_resultados_leccion` FOREIGN KEY (`leccion_id`) REFERENCES `lecciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migraciones_sistema` (`id`, `aplicada_at`, `detalle`)
VALUES ('20261026_lecciones_h5p', NOW(), 'Tipo de lección h5p y tabla h5p_resultados');
