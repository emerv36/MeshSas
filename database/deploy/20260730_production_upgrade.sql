-- Mesh LMS - Actualización incremental de producción
-- Objetivo: conservar los datos productivos y habilitar quiz V10 + accesos por 30 días.
-- Motor validado: MariaDB 11.4.x
-- IMPORTANTE:
--   1. Ejecutar con la plataforma en mantenimiento.
--   2. Hacer antes un respaldo completo externo de base de datos y public_html.
--   3. No importar estructura_produccion.sql ni la base local sobre producción.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET @orbytas_cutover_utc = UTC_TIMESTAMP();
SELECT GET_LOCK('orbytas_20260730_upgrade', 10) AS bloqueo_migracion;
-- El valor anterior debe ser 1. Si devuelve 0 o NULL, no continuar.

-- ============================================================================
-- 0. PRECHECK DE SOLO LECTURA
-- ============================================================================

SELECT 'PRECHECK' AS etapa,
       (SELECT COUNT(*) FROM usuarios) AS usuarios,
       (SELECT COUNT(*) FROM cursos) AS cursos,
       (SELECT COUNT(*) FROM rutas_aprendizaje) AS rutas,
       (SELECT COUNT(*) FROM ruta_cursos) AS ruta_cursos,
       (SELECT COUNT(*) FROM inscripciones) AS inscripciones,
       (SELECT COUNT(*) FROM pagos) AS pagos,
       (SELECT COUNT(*) FROM quiz_intentos) AS quiz_intentos,
       (SELECT COUNT(*) FROM quiz_respuestas_estudiante) AS respuestas_legacy;

SELECT 'pagos_verificados_huerfanos_usuario' AS control, COUNT(*) AS total
FROM pagos p
LEFT JOIN usuarios u ON u.id = p.usuario_id
WHERE p.estado = 'verificado' AND u.id IS NULL
UNION ALL
SELECT 'pagos_verificados_huerfanos_producto', COUNT(*)
FROM pagos p
LEFT JOIN cursos c
  ON p.producto_tipo = 'curso'
 AND c.id = COALESCE(NULLIF(p.producto_id, 0), p.curso_id)
LEFT JOIN rutas_aprendizaje r
  ON p.producto_tipo = 'ruta'
 AND r.id = p.producto_id
WHERE p.estado = 'verificado'
  AND ((p.producto_tipo = 'curso' AND c.id IS NULL)
    OR (p.producto_tipo = 'ruta' AND r.id IS NULL));

-- Si cualquiera de los dos controles anteriores es mayor que cero, detenerse.

-- ============================================================================
-- 1. RESPALDOS INTERNOS DE LAS TABLAS QUE RECIBIRÁN DATOS O CAMBIOS
--    El respaldo externo completo sigue siendo obligatorio.
-- ============================================================================

CREATE TABLE IF NOT EXISTS bak_20260730_pagos LIKE pagos;
INSERT IGNORE INTO bak_20260730_pagos (
    id, producto_tipo, producto_id, usuario_id, curso_id, nombre_temp,
    email_temp, telefono_temp, monto, metodo_pago, referencia,
    transaccion_id, comprobante_ruta, estado, fecha
)
SELECT id, producto_tipo, producto_id, usuario_id, curso_id, nombre_temp,
       email_temp, telefono_temp, monto, metodo_pago, referencia,
       transaccion_id, comprobante_ruta, estado, fecha
FROM pagos
WHERE NOT EXISTS (SELECT 1 FROM bak_20260730_pagos LIMIT 1);

CREATE TABLE IF NOT EXISTS bak_20260730_inscripciones LIKE inscripciones;
INSERT IGNORE INTO bak_20260730_inscripciones (id, usuario_id, curso_id, fecha)
SELECT id, usuario_id, curso_id, fecha
FROM inscripciones
WHERE NOT EXISTS (SELECT 1 FROM bak_20260730_inscripciones LIMIT 1);

CREATE TABLE IF NOT EXISTS bak_20260730_quiz_intentos LIKE quiz_intentos;
INSERT IGNORE INTO bak_20260730_quiz_intentos (
    id, usuario_id, leccion_id, puntaje, aprobado, fecha, estado
)
SELECT id, usuario_id, leccion_id, puntaje, aprobado, fecha, estado
FROM quiz_intentos
WHERE NOT EXISTS (SELECT 1 FROM bak_20260730_quiz_intentos LIMIT 1);

CREATE TABLE IF NOT EXISTS bak_20260730_quiz_respuestas_estudiante LIKE quiz_respuestas_estudiante;
INSERT IGNORE INTO bak_20260730_quiz_respuestas_estudiante (
    id, intento_id, pregunta_id, respuesta_texto, archivo_ruta
)
SELECT id, intento_id, pregunta_id, respuesta_texto, archivo_ruta
FROM quiz_respuestas_estudiante
WHERE NOT EXISTS (
    SELECT 1 FROM bak_20260730_quiz_respuestas_estudiante LIMIT 1
);

-- ============================================================================
-- 2. EXPANSIÓN DEL ESQUEMA DE PAGOS, ACCESOS Y CERTIFICADOS
-- ============================================================================

ALTER TABLE pagos
    ADD COLUMN IF NOT EXISTS verificado_at DATETIME NULL AFTER estado;

ALTER TABLE rutas_aprendizaje
    ADD COLUMN IF NOT EXISTS firmante_id INT NULL;

ALTER TABLE rutas_aprendizaje
    ADD INDEX IF NOT EXISTS idx_rutas_firmante (firmante_id);

SET @fk_rutas_firmante_exists = (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'fk_rutas_firmante'
);
SET @ddl_rutas_firmante = IF(
    @fk_rutas_firmante_exists = 0,
    'ALTER TABLE rutas_aprendizaje ADD CONSTRAINT fk_rutas_firmante FOREIGN KEY (firmante_id) REFERENCES firmantes(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt_rutas_firmante FROM @ddl_rutas_firmante;
EXECUTE stmt_rutas_firmante;
DEALLOCATE PREPARE stmt_rutas_firmante;

CREATE TABLE IF NOT EXISTS accesos_productos (
    id INT NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    pago_id INT NULL,
    producto_tipo ENUM('curso','ruta') NOT NULL,
    producto_id INT NOT NULL,
    acceso_inicio DATETIME NOT NULL,
    acceso_fin DATETIME NOT NULL,
    origen ENUM('pago','gratis','administrativo','migracion') NOT NULL,
    revocado_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_acceso_pago (pago_id),
    KEY idx_acceso_usuario_vigencia (usuario_id, acceso_inicio, acceso_fin),
    KEY idx_acceso_producto (producto_tipo, producto_id),
    KEY idx_acceso_vencimiento (acceso_fin),
    CONSTRAINT accesos_productos_usuario_fk
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT accesos_productos_pago_fk
        FOREIGN KEY (pago_id) REFERENCES pagos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. EXPANSIÓN DEL ESQUEMA DE EVALUACIONES
--    La tabla quiz_respuestas_estudiante se conserva intacta para rollback.
-- ============================================================================

CREATE TABLE IF NOT EXISTS quiz_versiones (
    id INT NOT NULL AUTO_INCREMENT,
    leccion_id INT NOT NULL,
    estado ENUM('borrador','publicada','archivada') NOT NULL DEFAULT 'borrador',
    puntaje_maximo DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    puntaje_minimo_aprobacion DECIMAL(5,2) NOT NULL DEFAULT 70.00,
    escala VARCHAR(20) NOT NULL DEFAULT 'porcentaje',
    penalizacion_incorrecta DECIMAL(4,3) NULL,
    formula_combinacion VARCHAR(30) NOT NULL DEFAULT 'suma',
    creado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qv_leccion_id (leccion_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_configuracion (
    id INT NOT NULL AUTO_INCREMENT,
    leccion_id INT NOT NULL,
    quiz_version_activa_id INT NULL,
    tiempo_limite_minutos INT NULL,
    max_intentos INT NOT NULL DEFAULT 1,
    politica_cierre VARCHAR(30) NOT NULL DEFAULT 'cortar',
    fecha_fin_configuracion DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_qc_leccion (leccion_id),
    KEY idx_qc_version_activa (leccion_id, quiz_version_activa_id),
    CONSTRAINT fk_qconf_version_activa
        FOREIGN KEY (leccion_id, quiz_version_activa_id)
        REFERENCES quiz_versiones (leccion_id, id)
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE quiz_intentos
    MODIFY COLUMN estado VARCHAR(20) NOT NULL DEFAULT 'in_progress',
    ADD COLUMN IF NOT EXISTS intento_numero INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS revision INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS start_idempotency_key_hash VARCHAR(128) NULL,
    ADD COLUMN IF NOT EXISTS submission_idempotency_key_hash VARCHAR(128) NULL,
    ADD COLUMN IF NOT EXISTS attempt_session_token_hash VARCHAR(128) NULL,
    ADD COLUMN IF NOT EXISTS fecha_fin_efectiva DATETIME NULL,
    ADD COLUMN IF NOT EXISTS procesado_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS metodo_cierre VARCHAR(40) NULL,
    ADD COLUMN IF NOT EXISTS consume_intento TINYINT(1) NULL,
    ADD COLUMN IF NOT EXISTS orden_preguntas_snapshot JSON NULL,
    ADD COLUMN IF NOT EXISTS orden_opciones_snapshot JSON NULL,
    ADD COLUMN IF NOT EXISTS aleatorizacion_semilla VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS cantidad_preguntas_aplicada INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS max_intentos_aplicado INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS tiempo_limite_aplicado INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS politica_cierre_aplicada VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS tiempo_consumido_segundos INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS ultima_actividad DATETIME NULL;

CREATE TABLE IF NOT EXISTS quiz_respuestas (
    id INT NOT NULL AUTO_INCREMENT,
    intento_id INT NOT NULL,
    pregunta_id INT NOT NULL,
    respuesta_texto TEXT NULL,
    archivo_ruta VARCHAR(255) NULL,
    quiz_version_pregunta_id INT NULL,
    texto_respuesta TEXT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 0,
    guardado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_qr_intento (intento_id),
    KEY idx_qr_pregunta (pregunta_id),
    CONSTRAINT fk_qr_intento
        FOREIGN KEY (intento_id) REFERENCES quiz_intentos(id) ON DELETE CASCADE,
    CONSTRAINT fk_qr_pregunta
        FOREIGN KEY (pregunta_id) REFERENCES quiz_preguntas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_respuesta_opciones (
    respuesta_id INT NOT NULL,
    quiz_version_opcion_id INT NOT NULL,
    seleccionada_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_qro_respuesta (respuesta_id),
    CONSTRAINT fk_qro_respuesta
        FOREIGN KEY (respuesta_id) REFERENCES quiz_respuestas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_calificaciones_manuales (
    id INT NOT NULL AUTO_INCREMENT,
    intento_id INT NOT NULL,
    version_calificacion INT UNSIGNED NOT NULL DEFAULT 1,
    estado VARCHAR(20) NOT NULL,
    es_vigente TINYINT(1) NOT NULL DEFAULT 0,
    docente_id INT NULL,
    docente_instantanea JSON NULL,
    motivo VARCHAR(255) NULL,
    creado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_qcm_intento (intento_id),
    CONSTRAINT fk_qcm_intento
        FOREIGN KEY (intento_id) REFERENCES quiz_intentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_calificacion_manual_detalles (
    id INT NOT NULL AUTO_INCREMENT,
    calificacion_manual_id INT NOT NULL,
    quiz_version_pregunta_id INT NOT NULL,
    puntaje DECIMAL(5,2) NOT NULL,
    puntaje_maximo DECIMAL(5,2) NOT NULL,
    retroalimentacion TEXT NULL,
    rubrica_aplicada JSON NULL,
    PRIMARY KEY (id),
    KEY idx_qcmd_calificacion (calificacion_manual_id),
    CONSTRAINT fk_qcmd_cabecera
        FOREIGN KEY (calificacion_manual_id)
        REFERENCES quiz_calificaciones_manuales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_respuesta_archivos (
    id INT NOT NULL AUTO_INCREMENT,
    respuesta_id INT NOT NULL,
    storage_key VARCHAR(128) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    mime_detectado VARCHAR(100) NOT NULL,
    tamano_bytes BIGINT UNSIGNED NOT NULL,
    hash_sha256 VARCHAR(64) NOT NULL,
    carga_iniciada_at DATETIME NOT NULL,
    recibido_at DATETIME NOT NULL,
    validado_at DATETIME NULL,
    confirmado_at DATETIME NULL,
    estado_antivirus VARCHAR(30) NULL,
    estado VARCHAR(30) NOT NULL DEFAULT 'uploading',
    reintentos INT UNSIGNED NOT NULL DEFAULT 0,
    proximo_reintento_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qra_storage_key (storage_key),
    KEY idx_qra_respuesta (respuesta_id),
    CONSTRAINT fk_qra_respuesta
        FOREIGN KEY (respuesta_id) REFERENCES quiz_respuestas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_usuario_leccion_control (
    usuario_id INT NOT NULL,
    leccion_id INT NOT NULL,
    ultimo_intento_numero INT UNSIGNED NOT NULL DEFAULT 0,
    revision INT UNSIGNED NOT NULL DEFAULT 0,
    intento_bloqueante_id INT NULL,
    PRIMARY KEY (usuario_id, leccion_id),
    KEY idx_qulc_intento (intento_bloqueante_id),
    CONSTRAINT fk_qulc_intento
        FOREIGN KEY (intento_bloqueante_id) REFERENCES quiz_intentos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_eventos_pendientes (
    id INT NOT NULL AUTO_INCREMENT,
    tipo VARCHAR(40) NOT NULL,
    entidad_tipo VARCHAR(40) NOT NULL,
    entidad_id INT NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    intentos INT UNSIGNED NOT NULL DEFAULT 0,
    proximo_reintento_at DATETIME NULL,
    bloqueado_at DATETIME NULL,
    bloqueado_por VARCHAR(64) NULL,
    procesado_at DATETIME NULL,
    deduplication_key VARCHAR(128) NULL,
    payload JSON NULL,
    ultimo_error_codigo VARCHAR(50) NULL,
    correlation_id VARCHAR(64) NULL,
    creado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qep_deduplication (deduplication_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS quiz_auditoria_eventos (
    id INT NOT NULL AUTO_INCREMENT,
    intento_id INT NULL,
    actor_id INT NULL,
    actor_tipo VARCHAR(40) NOT NULL,
    actor_nombre VARCHAR(100) NULL,
    actor_identificacion VARCHAR(50) NULL,
    actor_rol VARCHAR(40) NULL,
    ip VARCHAR(45) NULL,
    user_agent TEXT NULL,
    request_id VARCHAR(64) NULL,
    correlation_id VARCHAR(64) NULL,
    ejecucion_id VARCHAR(64) NULL,
    evento VARCHAR(60) NOT NULL,
    fuente VARCHAR(40) NOT NULL,
    ocurrido_at DATETIME NOT NULL,
    datos_anteriores JSON NULL,
    datos_nuevos JSON NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS quiz_cron_fallos (
    id INT NOT NULL AUTO_INCREMENT,
    intento_id INT NOT NULL,
    cantidad_fallos INT NOT NULL DEFAULT 1,
    ultimo_error TEXT NOT NULL,
    estado_operativo VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    proximo_reintento_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qcf_intento (intento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 4. MIGRACIÓN DE DATOS
-- ============================================================================

START TRANSACTION;

-- 4.1 Crear una versión publicada y configuración para cada quiz histórico.
INSERT INTO quiz_versiones (
    leccion_id, estado, puntaje_maximo, puntaje_minimo_aprobacion, escala
)
SELECT l.id,
       'publicada',
       100.00,
       COALESCE(NULLIF(l.puntaje_minimo, 0), 70),
       'porcentaje'
FROM lecciones l
WHERE l.tipo = 'quiz'
  AND NOT EXISTS (
      SELECT 1 FROM quiz_versiones v WHERE v.leccion_id = l.id
  );

INSERT INTO quiz_configuracion (
    leccion_id, quiz_version_activa_id, tiempo_limite_minutos,
    max_intentos, politica_cierre
)
SELECT l.id,
       v.id,
       NULL,
       CASE WHEN COALESCE(l.max_intentos, 0) > 0 THEN l.max_intentos ELSE 1 END,
       'cortar'
FROM lecciones l
JOIN (
    SELECT leccion_id, MAX(id) AS id
    FROM quiz_versiones
    WHERE estado = 'publicada'
    GROUP BY leccion_id
) v ON v.leccion_id = l.id
WHERE l.tipo = 'quiz'
  AND NOT EXISTS (
      SELECT 1 FROM quiz_configuracion c WHERE c.leccion_id = l.id
  );

UPDATE quiz_configuracion c
JOIN (
    SELECT leccion_id, MAX(id) AS id
    FROM quiz_versiones
    WHERE estado = 'publicada'
    GROUP BY leccion_id
) v ON v.leccion_id = c.leccion_id
SET c.quiz_version_activa_id = v.id
WHERE c.quiz_version_activa_id IS NULL;

-- 4.2 Normalizar metadatos de intentos sin cambiar sus estados, notas
--     ni aprobaciones históricas. Esto mantiene compatible el rollback.
UPDATE quiz_intentos qi
JOIN (
    SELECT id,
           ROW_NUMBER() OVER (
               PARTITION BY usuario_id, leccion_id
               ORDER BY fecha, id
           ) AS numero
    FROM quiz_intentos
) ordenados ON ordenados.id = qi.id
SET qi.intento_numero = COALESCE(qi.intento_numero, ordenados.numero),
    qi.revision = COALESCE(qi.revision, 0),
    qi.consume_intento = COALESCE(qi.consume_intento, 1),
    qi.fecha_fin_efectiva = COALESCE(qi.fecha_fin_efectiva, qi.fecha),
    qi.procesado_at = COALESCE(qi.procesado_at, qi.fecha),
    qi.metodo_cierre = COALESCE(qi.metodo_cierre, 'migracion_legacy'),
    qi.ultima_actividad = COALESCE(qi.ultima_actividad, qi.fecha);

-- 4.3 Copiar las respuestas antiguas; la tabla original permanece intacta.
INSERT INTO quiz_respuestas (
    id, intento_id, pregunta_id, respuesta_texto, archivo_ruta,
    quiz_version_pregunta_id, texto_respuesta, revision, guardado_at
)
SELECT r.id,
       r.intento_id,
       r.pregunta_id,
       r.respuesta_texto,
       r.archivo_ruta,
       r.pregunta_id,
       r.respuesta_texto,
       0,
       COALESCE(i.fecha, UTC_TIMESTAMP())
FROM quiz_respuestas_estudiante r
JOIN quiz_intentos i ON i.id = r.intento_id
ON DUPLICATE KEY UPDATE
    respuesta_texto = VALUES(respuesta_texto),
    archivo_ruta = VALUES(archivo_ruta),
    quiz_version_pregunta_id = VALUES(quiz_version_pregunta_id),
    texto_respuesta = VALUES(texto_respuesta);

-- 4.4 Inicializar control de concurrencia e intentos.
INSERT INTO quiz_usuario_leccion_control (
    usuario_id, leccion_id, ultimo_intento_numero, revision, intento_bloqueante_id
)
SELECT usuario_id,
       leccion_id,
       MAX(intento_numero),
       0,
       NULL
FROM quiz_intentos
GROUP BY usuario_id, leccion_id
ON DUPLICATE KEY UPDATE
    ultimo_intento_numero = GREATEST(
        quiz_usuario_leccion_control.ultimo_intento_numero,
        VALUES(ultimo_intento_numero)
    );

-- 4.5 Conservar y completar inscripciones derivadas de pagos verificados.
INSERT IGNORE INTO inscripciones (usuario_id, curso_id, fecha)
SELECT p.usuario_id,
       COALESCE(NULLIF(p.producto_id, 0), p.curso_id),
       p.fecha
FROM pagos p
JOIN usuarios u ON u.id = p.usuario_id
JOIN cursos c ON c.id = COALESCE(NULLIF(p.producto_id, 0), p.curso_id)
WHERE p.estado = 'verificado'
  AND p.producto_tipo = 'curso';

INSERT IGNORE INTO inscripciones (usuario_id, curso_id, fecha)
SELECT p.usuario_id,
       rc.curso_id,
       p.fecha
FROM pagos p
JOIN usuarios u ON u.id = p.usuario_id
JOIN rutas_aprendizaje r ON r.id = p.producto_id
JOIN ruta_cursos rc ON rc.ruta_id = r.id
WHERE p.estado = 'verificado'
  AND p.producto_tipo = 'ruta';

-- 4.6 Inferir el momento histórico de verificación.
UPDATE pagos p
SET p.verificado_at = COALESCE(
    CASE
        WHEN p.producto_tipo = 'curso' THEN (
            SELECT MIN(i.fecha)
            FROM inscripciones i
            WHERE i.usuario_id = p.usuario_id
              AND i.curso_id = COALESCE(NULLIF(p.producto_id, 0), p.curso_id)
              AND i.fecha >= p.fecha
        )
        WHEN p.producto_tipo = 'ruta' THEN (
            SELECT MIN(i.fecha)
            FROM inscripciones i
            JOIN ruta_cursos rc ON rc.curso_id = i.curso_id
            WHERE i.usuario_id = p.usuario_id
              AND rc.ruta_id = p.producto_id
              AND i.fecha >= p.fecha
        )
    END,
    p.fecha
)
WHERE p.estado = 'verificado'
  AND p.verificado_at IS NULL;

-- 4.7 Crear un acceso por pago.
--     Si el acceso histórico ya venció, se concede una transición de 30 días
--     desde este despliegue para no expulsar inmediatamente al estudiante.
INSERT INTO accesos_productos (
    usuario_id, pago_id, producto_tipo, producto_id,
    acceso_inicio, acceso_fin, origen
)
SELECT candidatos.usuario_id,
       candidatos.pago_id,
       candidatos.producto_tipo,
       candidatos.producto_id,
       candidatos.acceso_inicio,
       DATE_ADD(candidatos.acceso_inicio, INTERVAL 30 DAY),
       'migracion'
FROM (
    SELECT p.usuario_id,
           p.id AS pago_id,
           p.producto_tipo,
           CASE
               WHEN p.producto_tipo = 'curso'
                   THEN COALESCE(NULLIF(p.producto_id, 0), p.curso_id)
               ELSE p.producto_id
           END AS producto_id,
           CASE
               WHEN DATE_ADD(p.verificado_at, INTERVAL 30 DAY) <= @orbytas_cutover_utc
                   THEN @orbytas_cutover_utc
               ELSE p.verificado_at
           END AS acceso_inicio
    FROM pagos p
    JOIN usuarios u ON u.id = p.usuario_id
    LEFT JOIN cursos c
      ON p.producto_tipo = 'curso'
     AND c.id = COALESCE(NULLIF(p.producto_id, 0), p.curso_id)
    LEFT JOIN rutas_aprendizaje r
      ON p.producto_tipo = 'ruta'
     AND r.id = p.producto_id
    WHERE p.estado = 'verificado'
      AND ((p.producto_tipo = 'curso' AND c.id IS NOT NULL)
        OR (p.producto_tipo = 'ruta' AND r.id IS NOT NULL))
) candidatos
WHERE NOT EXISTS (
    SELECT 1
    FROM accesos_productos a
    WHERE a.pago_id = candidatos.pago_id
);

COMMIT;

-- Los índices únicos se agregan después de normalizar los registros históricos.
ALTER TABLE quiz_intentos
    ADD UNIQUE INDEX IF NOT EXISTS uq_qi_usuario_leccion_numero
        (usuario_id, leccion_id, intento_numero),
    ADD UNIQUE INDEX IF NOT EXISTS uq_qi_usuario_leccion_start_idemp
        (usuario_id, leccion_id, start_idempotency_key_hash),
    ADD UNIQUE INDEX IF NOT EXISTS uq_qi_submission_idemp
        (id, submission_idempotency_key_hash);

-- ============================================================================
-- 5. REGISTRO Y POSTCHECK
-- ============================================================================

CREATE TABLE IF NOT EXISTS migraciones_sistema (
    id VARCHAR(100) NOT NULL,
    aplicada_at DATETIME NOT NULL,
    detalle VARCHAR(255) NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migraciones_sistema (id, aplicada_at, detalle)
VALUES (
    '20260730_production_upgrade',
    UTC_TIMESTAMP(),
    'Quiz V10, pagos atómicos y accesos individuales de 30 días'
)
ON DUPLICATE KEY UPDATE
    aplicada_at = VALUES(aplicada_at),
    detalle = VALUES(detalle);

SELECT 'POSTCHECK' AS etapa,
       (SELECT COUNT(*) FROM usuarios) AS usuarios,
       (SELECT COUNT(*) FROM cursos) AS cursos,
       (SELECT COUNT(*) FROM rutas_aprendizaje) AS rutas,
       (SELECT COUNT(*) FROM ruta_cursos) AS ruta_cursos,
       (SELECT COUNT(*) FROM inscripciones) AS inscripciones,
       (SELECT COUNT(*) FROM pagos) AS pagos,
       (SELECT COUNT(*) FROM quiz_intentos) AS quiz_intentos,
       (SELECT COUNT(*) FROM quiz_respuestas_estudiante) AS respuestas_legacy,
       (SELECT COUNT(*) FROM quiz_respuestas) AS respuestas_nuevas,
       (SELECT COUNT(*) FROM accesos_productos) AS accesos;

SELECT estado, COUNT(*) AS total
FROM quiz_intentos
GROUP BY estado
ORDER BY estado;

SELECT estado, COUNT(*) AS total
FROM pagos
GROUP BY estado
ORDER BY estado;

SELECT
    SUM(CASE
        WHEN acceso_inicio <= @orbytas_cutover_utc
         AND acceso_fin > @orbytas_cutover_utc
         AND revocado_at IS NULL THEN 1 ELSE 0 END
    ) AS accesos_activos,
    SUM(CASE
        WHEN acceso_fin <= @orbytas_cutover_utc THEN 1 ELSE 0 END
    ) AS accesos_vencidos,
    COUNT(*) AS accesos_totales
FROM accesos_productos;

SELECT RELEASE_LOCK('orbytas_20260730_upgrade');
