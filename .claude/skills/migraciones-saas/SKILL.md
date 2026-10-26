---
name: migraciones-saas
description: Cómo escribir, probar y aplicar migraciones SQL de MeshSas (columnas institucion_id, claves únicas por institución, índices, backfill) de forma idempotente y segura. Usar al cambiar el esquema de meshsas_db, sobre todo en la fase 1 de PLAN_SAAS.md.
---

# Migraciones de MeshSas

## Formato
- **Archivo:** `database/migrations/AAAAMMDD_descripcion.sql`, con un encabezado que diga qué hace, que es idempotente y para qué MariaDB es compatible (10.4 o superior).
- **Idempotencia:** ejecutarla dos veces seguidas no cambia nada ni da error. Usa `CREATE TABLE IF NOT EXISTS` y `ADD COLUMN IF NOT EXISTS`. MariaDB también admite `DROP INDEX IF EXISTS` y `ADD INDEX IF NOT EXISTS`. Para las claves foráneas, consulta `information_schema` dentro de un bloque preparado.
- **Registro:** al final, `INSERT IGNORE INTO migraciones_sistema (id, aplicada_at, detalle) VALUES ('AAAAMMDD_descripcion', NOW(), '...');`.
- **Lista:** agrégala a la tabla de `database/migrations/README.md`.

## Añadir `institucion_id` a una tabla con datos
1. `ADD COLUMN IF NOT EXISTS institucion_id INT NULL`. Al principio NULL, para no fallar con las filas que ya existen.
2. Rellena las filas: `UPDATE tabla SET institucion_id = 1 WHERE institucion_id IS NULL`. Si es una tabla hija, toma el valor del padre con un JOIN.
3. Pásala a obligatoria con `MODIFY institucion_id INT NOT NULL`.
4. Crea el índice, que empieza por `institucion_id`: por ejemplo `ADD INDEX IF NOT EXISTS idx_cursos_inst (institucion_id, estado)`.
5. Crea la clave foránea a `instituciones(id)` con `ON DELETE RESTRICT`, salvo que el usuario decida otra cosa.

## Claves únicas por institución
- Elimina la clave global y crea la compuesta. Por ejemplo: `usuarios.email` pasa a `UNIQUE(institucion_id, email)`, y `categorias.slug` y `rutas_aprendizaje.slug` pasan a `UNIQUE(institucion_id, slug)`.
- `rutas_aprendizaje` tiene **tres** índices únicos duplicados sobre `slug` (`slug`, `slug_2` y `slug_3`): bórralos todos.
- Antes de crear una clave única, comprueba que no haya duplicados dentro de la misma institución.

## Proceso
1. Saca un volcado de la base que vas a tocar en `Desktop\MeshSas Back\` (mysqldump con un archivo temporal de credenciales, sin mostrarlas).
2. Aplica la migración en `meshsas_db_prueba` **dos veces**. Las dos deben terminar sin error y la segunda no debe cambiar nada.
3. Comprueba conteos de filas antes y después, que no queden `institucion_id` NULL y que la clave foránea funcione: insertar con una institución inexistente debe fallar.
4. Recién entonces aplícala en `meshsas_db` y repite las comprobaciones.
5. Nunca toques `mesh_lms_db`, `mesh_lms_db_prueba` ni bases de otros proyectos.

## Cuidados
- Las tablas `quiz_*` tienen claves foráneas en cadena: rellena siempre de padre a hijo.
- `ajustes` y `banner` pasan de tener una fila a una por institución. Conserva la fila 1 como institución 1 y no borres datos.
- **Certificados:** no recalcules los códigos de `certificados_emitidos`. Se verifican por el código guardado.
