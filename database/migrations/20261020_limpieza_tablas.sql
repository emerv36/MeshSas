-- Migración 20261020_limpieza_tablas
-- Elimina tablas sin uso en el código actual y datos obsoletos.
-- Idempotente: se puede ejecutar más de una vez sin efectos adicionales.
-- Compatible con MariaDB 10.4+.
--
-- ANTES de ejecutarla en producción: respaldo completo de la base de datos.
--
-- NO se toca `bloques_home`.

-- Vacía; el panel usa usuarios.rol
DROP TABLE IF EXISTS `admin_users`;

-- Respuestas del motor de quizzes anterior (si existe); ningún código la lee.
DROP TABLE IF EXISTS `quiz_respuestas_estudiante`;

-- Respaldos de la migración 20260730_production_upgrade (si se aplicó)
DROP TABLE IF EXISTS `bak_20260730_inscripciones`;
DROP TABLE IF EXISTS `bak_20260730_pagos`;
DROP TABLE IF EXISTS `bak_20260730_quiz_intentos`;
DROP TABLE IF EXISTS `bak_20260730_quiz_respuestas_estudiante`;

-- Los tokens de recuperación ahora se guardan como hash SHA-256; los antiguos
-- (en texto plano, válidos 1 hora) ya no pueden usarse.
DELETE FROM `password_resets`
WHERE NOT EXISTS (SELECT 1 FROM `migraciones_sistema` WHERE `id` = '20261020_limpieza_tablas');

INSERT IGNORE INTO `migraciones_sistema` (`id`, `aplicada_at`, `detalle`)
VALUES ('20261020_limpieza_tablas', NOW(), 'Elimina admin_users, quiz_respuestas_estudiante y respaldos bak_20260730_*; vacía tokens de recuperación antiguos');
