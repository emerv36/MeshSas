-- Migración 20261019_migraciones_sistema
-- Registro de migraciones aplicadas. En algunas bases de Mesh no existe porque
-- no se ejecutó database/deploy/20260730_production_upgrade.sql completo.
-- Debe ejecutarse antes que el resto de migraciones de esta carpeta.
-- Idempotente: se puede ejecutar más de una vez sin efectos adicionales.
-- Compatible con MariaDB 10.4+.

CREATE TABLE IF NOT EXISTS `migraciones_sistema` (
  `id` varchar(100) NOT NULL,
  `aplicada_at` datetime NOT NULL,
  `detalle` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migraciones_sistema` (`id`, `aplicada_at`, `detalle`)
VALUES ('20261019_migraciones_sistema', NOW(), 'Tabla de registro de migraciones');
