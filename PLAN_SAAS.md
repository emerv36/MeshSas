# Plan: MeshSas multi-institución (SaaS con subdominios)

Responde siempre en español. Este documento lo ejecuta una sesión abierta en `C:\xampp\htdocs\MeshSas`. Avanza fase por fase, haz un commit por fase y **detente al final de cada una** para que el usuario apruebe antes de seguir.

## 1. Contexto

- MeshSas es una copia de **Mesh LMS 1.3.0** (`C:\xampp\htdocs\Mesh Lms`, repo `emerv36/MeshLms`), hecha el 2026-10-26 para convertirla en SaaS **sin tocar Mesh**.
- Mesh es una plataforma LMS en PHP 8.1+ sin framework, con PDO, MariaDB y XAMPP en Windows:
  - **Cursos y rutas:** módulos, lecciones de varios tipos y H5P.
  - **Evaluación:** motor de quizzes en `app/Learning/Evaluations`, tareas y Excel interactivo.
  - **Pagos y certificados:** pagos con comprobante, accesos con vencimiento y certificados con QR y código HMAC.
  - **Sitio y panel:** portada con plantillas y un panel por roles: admin, docente, tesorería, estudiante y administrativo.
- **Hoy es de una sola academia:** una base por instalación, una sola fila en `ajustes` y en `banner`, un solo `img/logo.png` y una sola carpeta `uploads/`.
- **Objetivo:** que cada institución entre por su subdominio (`academia1.meshsas.com`) o por su propio dominio, con **un solo código y una sola base de datos** en la que cada fila pertenece a una institución. Es la **opción C** (tenant por columna).

### Estado de MeshSas al empezar
- **Carpeta y URL:** `C:\xampp\htdocs\MeshSas`, servida en `http://localhost/MeshSas`.
- **Base de datos:** `meshsas_db`, copia de `mesh_lms_db` con las 8 migraciones de Mesh aplicadas.
- **`.env` propio:** `DB_NAME=meshsas_db`, `BASE_URL=http://localhost/MeshSas` y `LOG_DIR=C:/xampp/meshsas_logs`.
- **Git:**
  - Repositorio propio desde cero: rama `main`, cuyo primer commit es el código de partida (Mesh 1.3.0 adaptado a `/MeshSas/`, con este plan). No tiene historial, remotos, ramas ni etiquetas de Mesh. El usuario crea el repo de MeshSas en GitHub y lo conecta como `origin`.
  - El historial anterior se guardó, sin conexión con este repo, en `Desktop\MeshSas Back\git_historial_anterior`.
- **Documentación de Mesh:** `CHANGELOG.md`, `README.md` y `database/migrations/README.md` describen el sistema tal como está.

### Inventario medido (2026-10-26)
| Qué | Cantidad | Implicación |
|---|---|---|
| Consultas SQL (`prepare`, `query`, `exec`) | **429** en **66 archivos**: raíz 30, `admin/` 198, `dashboard/` 72, `app/` 95, `helpers/` 31 y `database/` 3 | Cada una debe filtrar o escribir por institución |
| Tablas | **45** | Ver la clasificación en la fase 1 |
| Claves únicas globales | `usuarios.email`, `categorias.slug`, `rutas_aprendizaje.slug` (3 índices duplicados: `slug`, `slug_2` y `slug_3`), `quiz_respuesta_archivos.storage_key` y `quiz_eventos_pendientes.deduplication_key` | Deben pasar a ser únicas **por institución**, salvo las técnicas |
| Archivos con rutas de disco fijas (`uploads/`, `img/logo.png`, `contenido_h5p/`, fuentes) | **35**. Solo `img/logo.png` aparece en 19 | Cada institución necesita su propia carpeta |
| Configuración de fila única | `config.php` (`SELECT * FROM ajustes LIMIT 1`, `banner LIMIT 1`), `admin/configuracion.php` y `admin/estructuracion.php` (`WHERE id=1`) | Pasa a ser una fila por institución |
| Cron | `cron/quiz_tareas.php` | Debe recorrer todas las instituciones |
| Esqueleto modular sin uso | `config/` (`database.php` tiene `mesh_lms_db` fijo, `paths.php`, `branding.php`) y `app/Core/Institutions` (vacío) | Se puede reutilizar para el contexto de institución, sin obligación |

## 2. Reglas

1. **No tocar Mesh:** nada de `C:\xampp\htdocs\Mesh Lms`, `mesh_lms_db` ni `mesh_lms_db_prueba`. Mesh solo se lee como referencia.
2. **Base de datos:**
   - Trabaja sobre `meshsas_db`.
   - Antes de cada fase que cambie el esquema, saca un volcado en `Desktop\MeshSas Back\`.
   - Prueba primero en `meshsas_db_prueba` (créala en la fase 0).
   - No muestres las credenciales del `.env`.
3. **Git:**
   - Una rama por fase (`fase-N-...`), merge `--no-ff` a `main` y un commit por fase con mensaje en español.
   - Los commits terminan con `Co-Authored-By: Claude <noreply@anthropic.com>`, o la firma que indique la sesión.
   - Push solo al repo propio de MeshSas (`origin`) y con la aprobación del usuario. Nunca a `emerv36/MeshLms` ni a Orbytas.
4. **No borrar archivos:** los que se retiren se mueven a `Desktop\MeshSas Back\retirados\`.
5. **Estándares:**
   - PDO con prepared statements, `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` y CSRF en todo POST, con los helpers que ya existen.
   - Transacciones en las operaciones críticas.
   - Migraciones idempotentes, registradas en `migraciones_sistema`.
   - Progreso: una lección está completada **solo** con `progreso.completado = 1`, porque `track_time` crea filas con 0.
6. **Aislamiento ante todo:** ninguna consulta de datos de una institución puede ejecutarse sin el filtro `institucion_id`. Un usuario de la institución A nunca debe ver, modificar ni adivinar datos de B, aunque cambie un `id` en la URL. Ante la duda, 404.
7. **Pruebas en cada fase:**
   - `php -l` en todo archivo tocado.
   - Recorrido HTTP con al menos dos instituciones.
   - Las pruebas de aislamiento de la fase 6, a medida que existan.
   - Si algo falla, informa el error real y no avances.

## 3. Decisiones que debes preguntarle al usuario antes de la fase 1

1. **Dominio raíz** del SaaS (por ejemplo `meshsas.com`) y dominio local de pruebas (se propone `meshsas.test`).
2. **Correos repetidos:** ¿el mismo correo puede tener cuentas en dos instituciones? Se propone **sí, como cuentas independientes**, con `UNIQUE(institucion_id, email)`.
3. **Superadministrador:** el dueño de la plataforma crea, suspende y configura instituciones. ¿Dónde vive su panel? Se propone `admin.meshsas.com`, o `/superadmin` en el dominio raíz.
4. **Página del dominio raíz** (`meshsas.com` sin subdominio): ¿página comercial, formulario de registro de instituciones o una redirección?
5. **Dominios propios** de las instituciones (`campus.cliente.com`): ¿en esta versión o después?
6. **Planes y cobro** a las instituciones: ¿fuera del alcance de esta versión? Se propone dejar solo un campo `plan` y `estado`.
7. **Hosting de producción:** ¿permite subdominio comodín y SSL comodín? Si no lo sabe, se deja documentado como requisito.
8. **Repo de GitHub propio:** confirma que el usuario ya lo creó y que `origin` apunta a él (`git remote -v`). Si no, pídele la URL.
9. **Datos actuales:** ¿los datos copiados de Mesh pasan a ser la institución 1 de ejemplo o se empieza en limpio?

## 4. Fases

### Fase 0: Entorno
- **Respaldo:** volcado de `meshsas_db` y copia de `meshsas_db_prueba`.
- **Subdominios locales:**
  - VirtualHost de Apache con `ServerAlias *.meshsas.test`, apuntando a `C:\xampp\htdocs\MeshSas`, en `C:\xampp\apache\conf\extra\httpd-vhosts.conf`.
  - Entradas en `C:\Windows\System32\drivers\etc\hosts` para `meshsas.test`, `demo1.meshsas.test`, `demo2.meshsas.test` y `admin.meshsas.test`. Como requieren permisos de administrador, dale al usuario los pasos exactos.
  - Mientras tanto, `localhost/MeshSas` sigue funcionando.
- **Otros cambios del entorno:**
  - `config/database.php`: quitar el nombre de base fijo, o documentar que no se usa.
  - Crear `Desktop\MeshSas Back\`.

### Fase 1: Modelo de datos
- **Tabla `instituciones`:** `id`, `slug` (subdominio, único), `dominio` (propio, único, NULL), `nombre`, `estado` (`activa`, `suspendida`), `plan`, `created_at` y `updated_at`.
- **Clasificación de tablas.** Confírmala leyendo el esquema:
  - **De la institución, con `institucion_id NOT NULL`, índice y clave foránea:** `usuarios`, `cursos`, `categorias`, `rutas_aprendizaje`, `firmantes`, `pagos`, `accesos_productos`, `inscripciones`, `ajustes`, `banner`, `banner_slides`, `bloques_home`, `caracteristicas`, `testimonios`, `prospectos`, `certificados_emitidos`, `intentos_acceso`, `password_resets` y `comentarios_leccion`.
  - **Hijas:** `modulos`, `lecciones`, `progreso`, `apuntes`, `tareas_entregas`, `respuestas_excel`, `h5p_resultados`, `ruta_cursos`, `curso_docentes` y todas las `quiz_*`. **Recomendación:** también llevan `institucion_id` (desnormalizado), para filtrar sin JOIN y para que una consulta olvidada no filtre datos de otra institución. Decídelo en la fase 1 y documéntalo.
  - **Globales:** `migraciones_sistema` y `quiz_cron_fallos`. Revisa también `quiz_eventos_pendientes`.
- **Migración idempotente `AAAAMMDD_instituciones.sql`:**
  - Crea `instituciones` con la institución 1.
  - Añade `institucion_id` con valor 1 en las filas existentes y luego `NOT NULL`.
  - Pasa las claves únicas a ser por institución, por ejemplo `UNIQUE(institucion_id, email)`.
  - Elimina los índices duplicados de `rutas_aprendizaje.slug`.
  - Añade los índices compuestos que empiezan por `institucion_id`.
- **`ajustes` y `banner`:** pasan de una fila global a una fila por institución.
- **Probar en `meshsas_db_prueba`** dos veces seguidas: debe ser idempotente.

### Fase 2: Resolución de la institución
- **`helpers/institucion.php`:**
  - `institucion_desde_host()` lee `HTTP_HOST`, busca por `dominio` o por `slug` (el subdominio) y, si no encuentra nada o la institución está suspendida, responde con una página propia.
  - `institucion_id()` es el contexto de la petición actual.
- **`config.php`:**
  - Resuelve la institución antes de cargar `ajustes`.
  - `ACADEMY_NAME`, `BASE_URL`, los colores, `PASSING_GRADE`, `FAVICON_URL` y `LOGO_VERSION` salen de la institución.
  - `BASE_URL` se construye con el host actual.
- **Sesión:**
  - Guarda `institucion_id` al iniciar sesión.
  - En cada petición, si la sesión pertenece a otra institución, se destruye y se manda al login.
  - La cookie queda ligada al host. No uses una cookie para `.meshsas.com`.
- **Entorno de desarrollo:** `?institucion=slug` solo si `APP_ENV=local`, para probar sin `hosts`. Nunca en producción.

### Fase 3: Consultas, módulo por módulo
Cada módulo es un commit dentro de la fase, en este orden:
1. **Autenticación:** `login.php`, `recuperar.php`, `reset_password.php`, `admin/admin_auth.php`, `helpers/limite_intentos.php` y el registro en `pago.php`/`procesar_pago.php`. El email se busca **dentro de la institución**.
2. **Sitio público:** `index.php`, `cursos.php`, `rutas.php`, `ruta.php`, `components/` y `submit_lead.php`.
3. **Pagos y accesos:** `procesar_pago.php`, `app/Commerce`, `helpers/acceso_helper.php` y las páginas de pagos de `admin/`.
4. **Aprendizaje:** `dashboard/` (`clase.php`, `index.php`, `logros.php`, `track_time.php`, tareas, H5P y Excel) y `helpers/logros_helper.php`.
5. **Quizzes:** `app/Learning/Evaluations/*`, `dashboard/quiz_api.php`, `helpers/quiz_calificacion_helper.php` y `cron/quiz_tareas.php`. El cron recorre todas las instituciones.
6. **Certificados:**
   - `helpers/certificado_helper.php`, `dashboard/certificado*.php` y `verificar.php`.
   - El código HMAC incluye `institucion_id` en el mensaje firmado.
   - `/verificar/{codigo}` busca solo en la institución del host.
7. **Panel:** todo `admin/`, incluidos `configuracion.php` y `estructuracion.php` (`WHERE id=1` pasa a `WHERE institucion_id=?`).

**Técnica:**
- **Inserciones:** cada `INSERT` lleva `institucion_id`.
- **Lecturas, actualizaciones y borrados:** cada `SELECT`, `UPDATE` y `DELETE` sobre tablas de la institución filtra por `institucion_id = ?`, aunque ya filtre por `id`.
- **Consultas con JOIN:** se filtran en la tabla principal y, si la hija no lleva la columna, se comprueba que el padre pertenezca a la institución.
- **Al terminar cada módulo,** usa el agente `auditor-aislamiento` sobre los archivos tocados.

### Fase 4: Archivos por institución
- **Estructura:**
  - `uploads/{institucion_id}/...`: imágenes, pagos, tareas, perfiles y diagramas.
  - `contenido_h5p/{institucion_id}/...`.
  - El logo, el favicon, la firma, la plantilla y las fuentes del certificado van en la carpeta de la institución.
- **Código:**
  - Un helper `ruta_institucion('uploads/pagos')` para no repetir rutas.
  - Reemplaza los usos fijos de `img/logo.png`: el logo pasa a `ajustes` (ruta por institución), con respaldo a un logo genérico de MeshSas.
  - Los archivos que sirve PHP (`ver_comprobante`, `ver_tarea`, `h5p_archivo`, descargas) comprueban que el archivo pertenezca a la institución.
- **Migración de archivos:** mueve los archivos actuales a la carpeta de la institución 1, mediante un script con modo simulación, y actualiza sus rutas en la base.

### Fase 5: Superadministrador y alta de instituciones
- **Rol y acceso:**
  - Rol `superadmin` con `institucion_id NULL`. Su login está separado del de las instituciones.
  - El panel va en el host elegido en la decisión 3.
- **Funciones:**
  - Listar, crear, editar, suspender y reactivar instituciones.
  - Al crear una: `slug` validado (minúsculas, guion, palabras reservadas como `admin`, `www`, `api` o `mail`), fila de `ajustes` y `banner` con valores por defecto, primer usuario admin con contraseña temporal y carpetas de archivos.
  - Ver indicadores básicos de cada institución: usuarios, cursos y estado.
- **Script CLI** `cron/crear_institucion.php`, equivalente para pruebas.

### Fase 6: Pruebas de aislamiento y seguridad
- **Script `tests/aislamiento.php`:**
  - Crea dos instituciones de prueba con datos.
  - Con sesión de A, intenta leer y modificar recursos de B por `id`: cursos, lecciones, quizzes, pagos, comprobantes, tareas, certificados, H5P y usuarios.
  - Todo debe dar 403 o 404 y nada debe cambiar en la base.
- **Sesiones y archivos:**
  - La sesión de A no sirve en el host de B.
  - Las rutas de archivos de B no son accesibles desde A.
- **Revisión de seguridad completa:** usa la skill `security-auditor` del proyecto y el agente `auditor-aislamiento` sobre todo el código.
- **Regresión:**
  - Los flujos de Mesh siguen funcionando dentro de una institución: login por rol, pago con comprobante, verificación, clase y progreso, quiz con pregunta abierta, tarea, H5P, certificado de curso y de ruta, y `/verificar`.
  - También las funciones propias: plantillas, prospectos, clases en vivo, métricas y tiempo de estudio.

### Fase 7: Cierre
- **Versión:** `APP_VERSION` 2.0.0 de MeshSas (cambio incompatible), con `CHANGELOG.md`.
- **Documentación:**
  - `database/migrations/README.md`.
  - Actualizar `README.md` y este plan con el estado final.
  - Guía de despliegue: DNS comodín `*.dominio`, SSL comodín (AutoSSL o Let's Encrypt con DNS), VirtualHost o subdominio comodín en cPanel, cron único y variables del `.env`.

## 5. Riesgos

- **Fuga entre instituciones por una consulta sin filtro.** Es el riesgo principal. Se mitiga con la columna también en las tablas hijas, la auditoría por módulo y el script de aislamiento.
- **Sesión reutilizada entre hosts** con dominios propios. Se mitiga validando `institucion_id` en cada petición.
- **Certificados ya emitidos:** cambiar el formato del HMAC invalidaría los códigos existentes. Los de la institución 1 conservan su código guardado en `certificados_emitidos`, porque la verificación busca por código guardado y no lo recalcula.
- **Rendimiento:** cada índice debe empezar por `institucion_id`. Revisa `EXPLAIN` en las consultas del panel y de la clase.
- **Hosting compartido sin subdominio comodín.** Queda documentado como requisito. Alternativa: crear cada subdominio a mano en cPanel apuntando a la misma carpeta.

## 6. Herramientas preparadas para esta tarea
- **Skills** (`.claude/skills/`):
  - `consultas-multiinstitucion`: cómo adaptar una consulta.
  - `migraciones-saas`: cómo escribir y probar migraciones.
  - `pruebas-aislamiento`: cómo probar con dos instituciones y subdominios locales.
- **Agentes** (`.claude/agents/`):
  - `auditor-aislamiento`: revisa, sin modificar, que todo acceso a datos filtre por institución.
  - `adaptador-modulo`: adapta las consultas de un módulo de la fase 3.
  - `experto-bd-saas`: esquema, índices y migraciones.
- **Skills heredadas de Mesh** (`.agents/skills/`): `security-auditor` y `ux-reviewer`.

**Empieza por leer este plan y el `README.md`, revisa el estado de git y haz las preguntas de la sección 3. No modifiques nada antes de tener las respuestas.**
