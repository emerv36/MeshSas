-- Migración 20261020_seguridad
-- Registro de intentos de acceso para limitar ataques de fuerza bruta
-- (login y solicitudes de recuperación de contraseña).
-- Idempotente: se puede ejecutar más de una vez sin efectos adicionales.
-- Compatible con MariaDB 10.4+ (local) y 11.4 (producción).

CREATE TABLE IF NOT EXISTS `intentos_acceso` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo` varchar(20) NOT NULL COMMENT 'login | recuperar',
  `identificador` varchar(191) NOT NULL COMMENT 'email en minúsculas',
  `ip` varchar(45) NOT NULL,
  `creado_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ia_tipo_ip_fecha` (`tipo`, `ip`, `creado_at`),
  KEY `idx_ia_tipo_ident_fecha` (`tipo`, `identificador`, `creado_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migraciones_sistema` (`id`, `aplicada_at`, `detalle`)
VALUES ('20261020_seguridad', NOW(), 'Tabla intentos_acceso para limitar intentos de login y recuperación');
