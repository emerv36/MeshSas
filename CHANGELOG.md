# Historial de versiones

Versionado semántico (MAYOR.MENOR.PARCHE). La versión en uso está en `APP_VERSION` (`config.php`) y se muestra al pie del menú del panel de administración.

## [1.3.0] — 2026-10-26

### Cambiado
- **Nota mínima por defecto** (Configuración › Evaluación, antes "Nota de aprobación" en Apariencia): el valor se guardaba pero ningún código lo leía, porque la plataforma usaba un 70 fijo.
  - **Qué hace ahora:** es la nota con la que empiezan las lecciones calificables nuevas (quiz, ejercicios y H5P), y el docente la puede cambiar en cada lección.
  - **Si una lección no tiene nota propia,** se usa esta en la clase y en el cierre de quizzes.
  - **No es retroactiva:** las lecciones existentes conservan su nota, así que ningún estudiante gana ni pierde aprobaciones.
  - **Valores admitidos:** entre 1 y 100.
- **Logros:** las prácticas de Excel cuentan como aprobadas con la nota mínima de cada ejercicio, y no con un 70 fijo.

## [1.2.0] — 2026-10-26

Cierre de la revisión de UX del panel.

### Nuevo
- **Dashboard de tesorería:**
  - **Indicadores propios:** pagos pendientes, verificados y rechazados en los últimos 7 días, y lo recaudado en el mes.
  - **Accesos propios:** verificar pagos pendientes e historial de pagos.
  - Antes veía los indicadores y accesos del docente, que daban error 403.
- **Cursos con pestañas de estado:** Todos, Activos, Borradores y Archivados, cada una con su cantidad.
  - Los cursos archivados tienen un botón **Restaurar**, que los devuelve como borrador. Antes no había forma de restaurarlos desde el panel.

### Cambiado
- **Un solo listado de cursos:** el segundo listado ("Estructura de Contenidos" de Gestionar curso, al que llevaba la opción "Cursos archivados") redirige a Cursos con la pestaña equivalente. "Cursos archivados" sale del menú.
- **Favicon:** pasa de Sitio web (Estructuración) a Configuración › Identidad, junto al logo. Sitio web enlaza ahí.
- **Dashboard:**
  - Sin el acceso duplicado "Estructura Académica".
  - El docente tiene un acceso a Clases en vivo.
  - "Ver todo el historial" solo aparece donde lleva a una página: antes, para el docente, apuntaba a `#`.
- **Menú:** el conteo de quizzes pendientes ya no se consulta para tesorería.

## [1.1.0] — 2026-10-26

Calificación por curso y menú del panel reorganizado.

### Nuevo
- **Pestaña Evaluaciones de cada curso** (Gestionar curso): el docente califica ahí los quizzes con preguntas abiertas o de archivo de ese curso, con el contador de pendientes en la pestaña. Muestra también las actividades H5P con estudiantes y aprobados, y enlaza a sus resultados. Antes la pestaña existía pero abría una página vacía.
- **Pestaña Resultados Excel** de cada curso: los ejercicios de Excel interactivo con sus entregas. También abría una página vacía.
- **Cursos:** cada tarjeta avisa cuántos quizzes esperan calificación y lleva a la pestaña Evaluaciones.

### Cambiado
- **Menú del panel por grupos:** Académico (Cursos, Clases en vivo, Rutas, Categorías, Cursos archivados), Estudiantes y ventas (Usuarios, Actividad de estudiantes, Pagos, Prospectos), Sitio y sistema (Sitio web, Firmantes, Configuración) y Mi cuenta.
  - **Clases en vivo** también aparece para el docente: la página ya filtraba por sus cursos, pero no estaba en su menú.
  - **Nombres:** "Estructuración" pasa a "Sitio web", "Métricas Globales" a "Actividad de estudiantes" y "Archivados" a "Cursos archivados".
  - **Opción activa:** se resalta en todas las páginas, también en las subpáginas (por ejemplo, Gestionar curso resalta Cursos).
  - **Encabezado:** el del menú indica el panel según el rol (Administración, Panel docente, Tesorería).
- **Dashboard:** el acceso "Revisión de Quizzes" pasa a "Calificar evaluaciones" y lleva a Cursos.

### Retirado
- `admin/calificar_quizzes.php`: la página global de calificación. Su lógica pasa a `helpers/quiz_calificacion_helper.php` y se usa desde la pestaña Evaluaciones de cada curso. El archivo se movió al respaldo, no se borró.

## [1.0.1] — 2026-10-26

### Corregido
- **Métricas Globales no se podían desplazar**: la página armaba su propio documento HTML con `body { height: 100vh; overflow: hidden; }` y dentro incluía el del panel, así que quedaban dos `<html>` anidados y el contenido que no cabía en pantalla no se veía. Ahora usa el encabezado y el pie comunes, como el resto del panel, y la tabla se desplaza en horizontal en pantallas angostas.

## [1.0.0] — 2026-10-26

Primera versión numerada de Mesh LMS. Incorpora las correcciones de seguridad, quizzes, tareas y certificación de la plataforma de la que salió Mesh, más las lecciones H5P, sin perder lo propio de Mesh: temas y bloques de inicio, prospectos, Zoom y Jitsi, métricas, tiempo de estudio, políticas legales, vista previa de PDF y Drive, y certificado configurable. La etiqueta `pre-zenthali` marca el estado anterior.

### Para instalar o actualizar
- **PHP 8.1 o superior** con las extensiones `pdo_mysql`, `gd`, `mbstring` y `zip`. `vendor/` va incluido en el repositorio: no hace falta `composer install`.
- **Migraciones:** las ocho de `database/migrations/`, en el orden de su `README.md`, antes de usar el código nuevo.
- **`.env`:**
  - `CERT_SECRET` nuevo, obligatorio. Sin él los certificados se emiten con el código antiguo. Generarlo con `php -r "echo bin2hex(random_bytes(32));"` y no cambiarlo después, porque invalidaría los códigos ya emitidos.
  - `BASE_URL` con el dominio real: de ahí salen el enlace y el QR de verificación del certificado.
  - Opcionales: `APP_TIMEZONE`, `LOG_DIR`, `APP_DEBUG`, `ACCESS_EXPIRATION_ENABLED` y `ACCESS_DURATION_DAYS` (ver `.env.example`).
- **Hosting:** `upload_max_filesize` y `post_max_size` suficientes para los paquetes H5P (hasta 100 MB), y `contenido_h5p/` con permisos de escritura.

### Seguridad
- **`.htaccess`:** sin listado de directorios; bloquea `.env`, volcados, zips, logs y archivos de configuración; cierra las carpetas internas (`app`, `helpers`, `database`, `config`, `storage`, `contenido_h5p`…); añade cabeceras de seguridad. Corrige el bucle de `.php.php` que daba error 500 en URLs como `/dashboard/perfil/x` (ahora dan 404).
- **Subidas:** los archivos de `uploads/` nunca se ejecutan; comprobantes de pago y tareas solo se sirven a través de PHP, tras comprobar permisos. Las imágenes subidas se validan con `helpers/upload_helper.php` (tipo real del archivo, tamaño y nombre aleatorio), también en la estructuración del sitio, que antes aceptaba cualquier archivo; comprobantes, tareas, fuentes y paquetes H5P tienen su propia validación.
- **CSRF** en todos los formularios y peticiones que modifican datos, incluidas las páginas propias de Mesh: prospectos, estructuración, formulario de leads, tiempo de estudio y firma de Zoom.
- **Límite de intentos** (`intentos_acceso`) en login, recuperación de contraseña, verificación de certificados, checkout y formulario de leads.
- **Permisos:**
  - Prospectos y estructuración del sitio, solo para administradores.
  - Métricas con el login común del panel.
  - La firma de anfitrión de Zoom, solo para admin y docente.
- **XSS** en `dashboard/zoom_sala.php`: el parámetro `pass` de la URL se imprimía sin escapar.
- **Retirados de la web** (movidos a un respaldo, no borrados): `remove_bg.php`, `remove_bg.py`, `autocrop.php` y `dashboard/quiz_submit.php`.
- **Errores:** nunca se muestran en pantalla; van a un log fuera de la carpeta pública y el usuario ve una página 500 con un código de referencia. Páginas 403, 404 y 500 propias.
- `.env`, el volcado de la base y `uploads/` ya no se versionan.

### Certificados
- **Emisión registrada** en `certificados_emitidos`: al emitirse quedan fijos el código, la fecha de finalización, el título, la duración y el firmante, aunque el curso cambie después.
- **Código de verificación** con HMAC-SHA256 y `CERT_SECRET`. El código antiguo (md5 con sal fija) se podía calcular para cualquier usuario y curso. Los certificados ya entregados se registran con su código antiguo y siguen verificándose.
- **`/verificar/{codigo}`** busca en el registro (antes recalculaba el código de todos los pares usuario/curso), muestra la fecha de finalización y limita las consultas por IP.
- **QR de verificación** en el PDF de curso y de ruta, a la derecha de la firma, sobre un recuadro blanco. El diseño configurable de Mesh (plantilla de fondo, fuente, colores y textos) se conserva.
- El enlace impreso usa `BASE_URL` (antes, `meshlms.com` fijo). El certificado de curso muestra la fecha de finalización en lugar de la de descarga.
- **Ruta de aprendizaje:** el certificado exige pago verificado, no acceso vigente: quien terminó la ruta puede descargarlo aunque su acceso haya vencido.

### Aprendizaje
- **Lecciones H5P:** el docente sube un `.h5p` en Gestionar curso y la actividad se muestra incrustada en la clase (h5p-standalone 3.8.2, en `js/vendor/`).
  - **Para completarla:** nota mínima o solo terminar la actividad, según configure el docente.
  - **Resultados:** se guardan en `h5p_resultados` con la mejor nota, y el docente los consulta en `admin/ver_h5p.php`.
  - **Paquetes:** se validan antes de instalarse y su contenido solo se entrega a quien tiene acceso al curso.
- **Publicación:** módulos y lecciones tienen estado borrador o publicado. El estudiante solo ve y cuenta lo publicado.
- **Progreso:** una lección cuenta como completada solo con `progreso.completado = 1`. Antes, abrirla bastaba, porque el tiempo de estudio crea filas sin completar.
- **Quizzes:**
  - Cada intento guarda una copia de sus preguntas, y borrar una pregunta ya no borra respuestas.
  - El tiempo límite se controla en el servidor y hay autoguardado.
  - La revisión del intento tiene página propia.
  - El cierre de intentos vencidos lo hace `cron/quiz_tareas.php`.
- **Tareas:**
  - El rechazo deja la lección sin completar pero conserva el tiempo de estudio.
  - Las tareas ya aprobadas cuentan como completadas.
- **Pagos y acceso:** `accesos_productos` es la fuente única del acceso; verificación del comprobante en `admin/ver_comprobante.php`.

### Corregido
- **Clases en vivo:**
  - El botón "Marcar completada" nunca funcionaba: enviaba un parámetro con otro nombre.
  - `admin/clases_vivo.php` no traía el curso de cada clase: enlaces rotos y avisos de PHP en las tarjetas.
- **Certificado:** el nombre de la fuente se usaba como ruta de archivo sin validar; ahora se limita a letras y números.
- **Quizzes:** al aprobar, el registro del progreso fallaba porque el `INSERT` tenía 3 columnas y 2 valores.
- **Plantillas de la portada:**
  - Solo se ofrecen las terminadas, la 1 y la 2 (`helpers/plantillas_helper.php`). Las 3, 4 y 5 eran apenas un CSS de pocas líneas, sin componentes propios, y la 5 dejaba textos ilegibles. Si la guardada no está disponible, la portada usa la 1.
  - **Plantilla 2:**
    - El bloque de cursos consultaba columnas y un estado inexistentes (`imagen_portada`, `publicado`), así que activarlo daba error 500. También enlazaba a `curso.php`, que no existe.
    - La llamada a la acción había perdido el formulario de leads; ahora lo incluye.
    - Los textos fijos en inglés pasan a español.
    - La fuente Inter no cargaba por una URL mal escrita, y el estilo del menú no se aplicaba.
    - El hero heredaba una altura de 85vh que dejaba franjas vacías.
    - Los testimonios mostraban la foto de fondo de la plantilla 1 y comillas dobles.

### Otros
- **Zona horaria** configurable (`APP_TIMEZONE`, por defecto `America/Bogota`): PHP y MariaDB usan la misma.
- **Logros e insignias** calculados sobre el progreso completado.
- **Imágenes que faltan:** imagen de respaldo en cursos y rutas.
