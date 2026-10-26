-- Migración 20261024_publicar_contenido
-- Módulos y lecciones tienen `estado` (borrador/publicado), pero el panel nunca lo enviaba:
-- todo quedó en 'borrador' y el estudiante lo veía igual. Desde esta versión el estudiante
-- solo ve y cuenta lo publicado, así que todo lo existente (lo que hoy ya ve) se publica.
-- Solo actúa la primera vez: una vez registrada la migración, volver a ejecutarla no publica
-- los borradores creados después.
-- Idempotente: se puede ejecutar más de una vez sin efectos adicionales.
-- Compatible con MariaDB 10.4+.

UPDATE `modulos`
SET `estado` = 'publicado'
WHERE (`estado` IS NULL OR `estado` <> 'publicado')
  AND NOT EXISTS (SELECT 1 FROM `migraciones_sistema` WHERE `id` = '20261024_publicar_contenido');

UPDATE `lecciones`
SET `estado` = 'publicado'
WHERE (`estado` IS NULL OR `estado` <> 'publicado')
  AND NOT EXISTS (SELECT 1 FROM `migraciones_sistema` WHERE `id` = '20261024_publicar_contenido');

INSERT IGNORE INTO `migraciones_sistema` (`id`, `aplicada_at`, `detalle`)
VALUES ('20261024_publicar_contenido', NOW(), 'Módulos y lecciones existentes pasan a publicado; el estudiante solo ve lo publicado');
