-- Migración 20261023_quiz_copia_preguntas
-- Cada intento de quiz guarda una copia de sus preguntas y opciones al iniciarse
-- (quiz_intentos.preguntas_snapshot). El editor vuelve a crear las opciones al guardar una
-- pregunta (ids nuevos) y puede borrar preguntas: sin la copia, un intento se calificaba y
-- revisaba contra un quiz distinto del que respondió el alumno.
-- Además, borrar una pregunta borraba en cascada las respuestas de los alumnos; ahora la
-- respuesta se conserva (pregunta_id queda NULL y se sigue ubicando por
-- quiz_version_pregunta_id, que guarda el mismo id y no tiene clave foránea).
-- Los intentos anteriores quedan sin copia: para ellos rige el quiz actual.
-- Idempotente: se puede ejecutar más de una vez sin efectos adicionales.
-- Compatible con MariaDB 10.4+.

ALTER TABLE `quiz_intentos`
  ADD COLUMN IF NOT EXISTS `preguntas_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
    CHECK (json_valid(`preguntas_snapshot`)) AFTER `ultima_actividad`;

-- Respuestas: de ON DELETE CASCADE a ON DELETE SET NULL.
-- En las bases de Mesh la clave se creó con el nombre automático quiz_respuestas_ibfk_2.
ALTER TABLE `quiz_respuestas` DROP FOREIGN KEY IF EXISTS `quiz_respuestas_ibfk_2`;
ALTER TABLE `quiz_respuestas` DROP FOREIGN KEY IF EXISTS `fk_qr_pregunta`;

ALTER TABLE `quiz_respuestas` MODIFY `pregunta_id` int(11) NULL DEFAULT NULL;

ALTER TABLE `quiz_respuestas`
  ADD CONSTRAINT `fk_qr_pregunta` FOREIGN KEY (`pregunta_id`) REFERENCES `quiz_preguntas` (`id`) ON DELETE SET NULL;

INSERT IGNORE INTO `migraciones_sistema` (`id`, `aplicada_at`, `detalle`)
VALUES ('20261023_quiz_copia_preguntas', NOW(), 'quiz_intentos.preguntas_snapshot (copia de preguntas por intento); quiz_respuestas conserva las respuestas al borrar una pregunta');
