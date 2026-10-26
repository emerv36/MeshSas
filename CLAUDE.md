# MeshSas

Responde siempre en español.

MeshSas es la versión **multi-institución (SaaS)** de Mesh LMS: un solo código y una sola base de datos, y cada institución entra por su subdominio (`academia1.meshsas.com`) o por su propio dominio. Partió de una copia de Mesh LMS 1.3.0 hecha el 2026-10-26.

**La tarea en curso está en `PLAN_SAAS.md`.** Léelo completo antes de hacer nada y sigue sus fases, reglas y paradas.

## Proyecto
- **Carpeta:** `C:\xampp\htdocs\MeshSas`, servida en `http://localhost/MeshSas` con XAMPP en Windows. Durante el plan también se servirá en `*.meshsas.test`.
- **Base de datos:** `meshsas_db`, con `meshsas_db_prueba` para pruebas. Las credenciales están en `.env` y no se muestran nunca.
- **Stack:** PHP 8.1+ sin framework, PDO, MariaDB, Composer con `vendor/` versionado, FPDF y `chillerlan/php-qrcode`.
- **Git:** proyecto independiente de Mesh LMS, aunque nació de una copia suya. Se trabaja en `main`; el único remoto será el repo propio de MeshSas que crea el usuario. No se hace push sin su aprobación, y nunca a los repos de Mesh (`emerv36/MeshLms`) ni de Orbytas.

## Límites
- **No se toca Mesh LMS:** ni `C:\xampp\htdocs\Mesh Lms` ni las bases `mesh_lms_db` y `mesh_lms_db_prueba`. Se pueden leer como referencia.
- **No se toca Zenthali:** ni `C:\xampp\htdocs\zenthali`, ni sus bases, ni ningún otro proyecto.
- **No se borran archivos:** se mueven a `Desktop\MeshSas Back\retirados\`.
- **Antes de cambiar el esquema:** volcado en `Desktop\MeshSas Back\` y prueba en `meshsas_db_prueba`.

## Reglas del código
- **Aislamiento:** toda lectura o escritura de datos de una institución filtra por `institucion_id`, sin excepciones. Ante la duda, responde 404.
- **Seguridad:** PDO con prepared statements, `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`, CSRF en todo POST (`helpers/csrf_helper.php`), subidas con `helpers/upload_helper.php` y transacciones en las operaciones críticas.
- **Progreso:** una lección está completada solo con `progreso.completado = 1`.
- **Migraciones:** idempotentes, en `database/migrations/AAAAMMDD_*.sql`, y registradas en `migraciones_sistema`.
- **Rutas de archivos:** no se escriben fijas (`uploads/`, `img/logo.png`, `contenido_h5p/`); se usa el helper de rutas por institución de la fase 4.
- **Commits:** en español, una rama por fase y merge `--no-ff` a `main`.

## Herramientas
- **Skills:**
  - `.claude/skills/consultas-multiinstitucion`
  - `.claude/skills/migraciones-saas`
  - `.claude/skills/pruebas-aislamiento`
  - Las heredadas de Mesh: `.agents/skills/security-auditor` y `.agents/skills/ux-reviewer`.
- **Agentes** (`.claude/agents/`):
  - `auditor-aislamiento`: revisión sin cambios.
  - `adaptador-modulo`: adapta un módulo de la fase 3.
  - `experto-bd-saas`: esquema y migraciones.
