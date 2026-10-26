-- Migración idempotente documental.
-- Ejecutar solo después de revisar SHOW INDEX y el reporte de pagos huérfanos.
-- MariaDB no soporta CREATE INDEX IF NOT EXISTS en todas las versiones de XAMPP.

-- Índices recomendados:
-- CREATE INDEX idx_pagos_usuario ON pagos (usuario_id);
-- CREATE INDEX idx_pagos_producto_estado ON pagos (producto_tipo, producto_id, estado);
-- CREATE INDEX idx_pagos_curso_estado ON pagos (curso_id, estado);

-- El índice único requerido ya debe existir:
-- UNIQUE KEY unique_inscripcion (usuario_id, curso_id)

-- No se agrega una FK pagos.usuario_id -> usuarios.id porque existen pagos
-- históricos huérfanos que deben conservarse y clasificarse primero.
