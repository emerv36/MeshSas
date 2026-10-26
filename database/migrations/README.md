# Migraciones de base de datos

Cada archivo `.sql` es **idempotente** (se puede ejecutar más de una vez) y queda registrado en la tabla `migraciones_sistema` con su nombre como `id`. Son compatibles con MariaDB 10.4 o superior.

## Base de partida

Una base de Mesh anterior a la versión 1.0.0 debe tener aplicada la actualización del 30 de julio de 2026: `database/deploy/20260730_production_upgrade.sql`, siguiendo `database/deploy/DEPLOYMENT_ORDER_20260730.md`. Ese upgrade ya incluye lo de `20260730_access_expiration.php` (tabla `accesos_productos` y `pagos.verificado_at`); ese script solo corre por CLI y no hace falta si el upgrade se aplicó. Sobre esa base se aplican las de la tabla siguiente.

## Orden

| # | Archivo | Qué hace |
|---|---|---|
| 1 | `20261019_migraciones_sistema.sql` | Crea `migraciones_sistema`, la tabla donde se registran las demás (faltaba en bases donde el upgrade del 30 de julio no se aplicó completo). |
| 2 | `20261020_seguridad.sql` | Crea `intentos_acceso` (límite de intentos de login, recuperación, verificación de certificados y formulario de leads). |
| 3 | `20261020_limpieza_tablas.sql` | Elimina `admin_users`, `quiz_respuestas_estudiante` y los respaldos `bak_20260730_*`; vacía los tokens antiguos de `password_resets`. No toca `bloques_home`. |
| 4 | `20261022_tareas_entregas.sql` | `tareas_entregas.archivo_ruta` y `fecha_envio` admiten NULL (fila «vista, sin entrega»); marca `progreso.completado = 1` en las tareas ya aprobadas. |
| 5 | `20261023_quiz_copia_preguntas.sql` | `quiz_intentos.preguntas_snapshot`: copia de las preguntas de cada intento; `quiz_respuestas.pregunta_id` admite NULL y su clave foránea pasa a `ON DELETE SET NULL` (borrar una pregunta ya no borra las respuestas). |
| 6 | `20261024_publicar_contenido.sql` | Pasa a `publicado` los módulos y lecciones existentes. Desde esta versión el estudiante solo ve y cuenta lo publicado. Solo actúa la primera vez. |
| 7 | `20261025_certificados_emitidos.sql` | Crea `certificados_emitidos` (el certificado queda fijo al emitirse: código, fecha de finalización, título, duración y firmante) y registra los cursos y rutas ya completados con el código antiguo que tienen impreso, para que sigan verificándose. Requiere la 6. Solo registra la primera vez. |
| 8 | `20261026_lecciones_h5p.sql` | Tipo de lección `h5p` (conserva `clase_vivo`) y tabla `h5p_resultados` (mejor nota, aprobado y envíos de cada estudiante en las actividades H5P). |

## Cómo aplicarlas

1. **Respaldo completo** de la base de datos (phpMyAdmin → Exportar → Rápido → SQL) y guardarlo fuera del servidor.
2. phpMyAdmin → la base de Mesh → pestaña **SQL** (o **Importar**) → ejecutar los archivos **en el orden de la tabla**.
3. Comprobar: `SELECT * FROM migraciones_sistema;` debe mostrar las ocho migraciones.

Para saber cuáles faltan: las que no aparezcan en `migraciones_sistema`.

## Reglas

- Progreso: una lección está completada solo con `progreso.completado = 1`. `dashboard/track_time.php` crea filas con `completado = 0` para el tiempo de estudio, así que toda migración que cuente o marque progreso debe usar esa columna.
- No borrar `bloques_home`.
- Una migración nueva se llama `AAAAMMDD_descripcion.sql`, usa `IF EXISTS` / `IF NOT EXISTS` y termina con su `INSERT IGNORE INTO migraciones_sistema`.
- Probarla primero en local sobre una copia de la base (`mesh_lms_db_prueba`).
- Esta carpeta está bloqueada por `.htaccess` (no se sirve por URL).
