# MESH LMS
**M**odular **E**ducational **S**ystem **H**ub - **L**earning **M**anagement **S**ystem

Mesh LMS es una plataforma integral de gestión de aprendizaje (LMS) diseñada para ofrecer, administrar y certificar cursos virtuales y rutas de aprendizaje bajo un modelo SaaS y de arquitectura escalable.

---

## 1. Características Principales

### 👥 Gestión de Usuarios y Roles (RBAC)
La plataforma incorpora un modelo robusto de Control de Acceso Basado en Roles (RBAC), dividiendo las responsabilidades en 5 perfiles:
- **Administrador**: Control total sobre la plataforma, ajustes del sistema, usuarios y módulos financieros y de aprendizaje.
- **Tesorero**: Especialista financiero encargado únicamente de revisar, aprobar o rechazar comprobantes de pago.
- **Docente**: Gestiona sus cursos asignados, lecciones y aprueba/rechaza evaluaciones (Quizzes) manuales de los estudiantes.
- **Administrativo**: Perfil de soporte al cliente con permisos de lectura para descargar certificados y guiar a los alumnos en cursos privados.
- **Estudiante**: Usuario final que consume cursos, sigue su progreso y obtiene certificados.

### 📚 Catálogo Educativo y Rutas de Aprendizaje
- **Cursos Individuales**: Lecciones de video, PDF, texto, quiz, ejercicios interactivos (hoja de cálculo, arrastrar, Excel), tareas, clases en vivo (Zoom/Jitsi) y actividades **H5P** incrustadas. Módulos y lecciones tienen estado borrador o publicado; el estudiante solo ve lo publicado. Los cursos pueden ser públicos, privados (solo por enlace/inscripción), inactivos o externos.
- **Rutas de Aprendizaje**: Agrupación lógica de múltiples cursos con prerequisitos y bloqueos secuenciales. Tienen su propio precio y su propio certificado de culminación.

### 💳 Sistema de Pagos y Acceso
- Pasarela manual donde el usuario sube un comprobante (transferencia, PSE, etc.). El pago entra en estado "Pendiente". 
- Tras la validación del Tesorero/Administrador, el estado cambia a "Verificado" y el acceso se concede automáticamente al usuario.

### 📝 Evaluaciones (Quizzes)
- Motor dinámico para crear exámenes al final de los módulos con preguntas de opción múltiple y preguntas abiertas.
- Las respuestas abiertas van a una bandeja que el docente califica posteriormente.

### 🎓 Certificados Dinámicos y Verificables
- **Generación en PDF al Vuelo**: El diploma se emite cuando el alumno completa el 100% de las lecciones publicadas (y, si el curso tiene precio, con pago verificado). Diseño configurable: plantilla de fondo, fuente, colores y textos.
- **Emisión registrada**: Al emitirse queda fijo en `certificados_emitidos` (código, fecha de finalización, título, duración y firmante).
- **Catálogo de Firmantes**: Los administradores pueden asignar diferentes firmantes a los cursos/rutas.
- **Verificador**: Cada certificado lleva un código HMAC (clave `CERT_SECRET`) y un QR que lleva a `/verificar/{codigo}` para validación de terceros.

### 📊 Tablero de Analíticas y Retención
- **Métricas en Tiempo Real**: Panel avanzado para administradores con estadísticas de estudiantes activos, ingresos, y completitud de cursos.
- **Prevención de Deserción**: Monitoreo nativo del tiempo invertido (`track_time.php`) y del riesgo de abandono de los estudiantes.
- **Reportes Exportables**: Generación de reportes detallados en Excel de calificaciones y progresos.

### 🏆 Gamificación y Perfil de Usuario
- Sistema de **Puntos de Experiencia (XP)** por lecciones y talleres.
- **Insignias Interactivas**: Tablero de medallas que se desbloquean en tiempo real.
- **Comunidad y Comunicación**: Foros de discusión nativos en cada clase y notificaciones internas.
- **Descargas Unificadas**: Un concentrador dentro del perfil para descargar directamente todos los diplomas PDF obtenidos.

### 🎨 Personalización y White-Label
- **Branding Dinámico**: Configuración centralizada de colores, textos y logos.
- **Caché Inteligente**: Sistema de control de versiones de recursos (`LOGO_VERSION`) que obliga a los navegadores a refrescar la interfaz instantáneamente cuando el administrador actualiza la marca.

---

## 2. Arquitectura del Sistema (Modular Monolith Headless Ready)

Mesh LMS utiliza un enfoque arquitectónico **Modular Monolith Headless Ready**:
- **Monolito Modular:** Todo el código reside en el mismo repositorio, pero la lógica de negocio se divide en dominios altamente cohesionados.
- **Headless Ready:** La lógica de repositorios y servicios está desacoplada de la interfaz visual.
- **Patrón MVC Moderno:** Estructura en capas (`Controller` -> `Service` -> `Repository`) evitando mezclar lógica SQL con HTML.

### Estructura de Dominio (`app/`)
- `Core`: Lógica central (Usuarios, Autenticación, Instituciones, Permisos).
- `Learning`: Dominio del aprendizaje (Cursos, Lecciones, Evaluaciones).
- `Commerce`: Dominio financiero (Ventas, Órdenes, Pagos).
- `Certification`: Dominio de certificaciones (Certificados, Firmantes).
- `Communication`: Interacción (Notificaciones, Emails).
- `Analytics`: Reportes globales.
- `Shared`: Código transversal (HTTP base, Helpers).

---

## 3. Arquitectura de Seguridad

La seguridad es un pilar fundamental en Mesh LMS, implementada a través de múltiples capas:
- **Autenticación (Hashing)**: Contraseñas almacenadas con algoritmo `bcrypt`.
- **Prevención SQLi**: Todas las consultas a base de datos son *Prepared Statements* a través de `PDO` exclusivo.
- **Protección CSRF**: Todos los formularios y peticiones que modifican datos validan un `csrf_token` (`helpers/csrf_helper.php`).
- **Límite de Intentos**: Login, recuperación de contraseña, verificación de certificados, checkout y formulario de leads (`helpers/limite_intentos.php`, tabla `intentos_acceso`).
- **Subida Segura**: Validación del tipo real del archivo (usando `finfo`), límite de tamaño, lista blanca de tipos y renombrado aleatorio (`helpers/upload_helper.php`). Comprobantes de pago, tareas y contenido H5P no se sirven por URL directa: los entrega PHP tras comprobar permisos.
- **Servidor**: El `.htaccess` bloquea `.env`, volcados, logs y carpetas internas, y añade cabeceras de seguridad. Los errores nunca se muestran en pantalla: van a un log fuera de la carpeta pública.
- **Variables de Entorno**: Las credenciales de BD y SMTP se aíslan en el archivo `.env` excluido del control de versiones.

---

## 4. Plan de Migración y Mapa Legacy

El sistema migra progresivamente de su estructura antigua acoplada hacia la arquitectura modular. **El proyecto debe seguir siendo 100% compatible hacia atrás durante el proceso.**

### Destino de Módulos Legacy:
- `admin/` ➔ `Core` / `Analytics`
- `dashboard/` ➔ `Learning` / `Core`
- `login.php` / `recuperar.php` / `reset_password.php` ➔ `Core/Auth`
- `pago.php` ➔ `Commerce/Payments`
- `confirmacion.php` ➔ `Commerce/Orders`
- `verificar.php` ➔ `Certification/Verification`
- `check_student.php` ➔ `Learning/Progress`
- `components/` ➔ `resources/components/`
- `css/`, `js/`, `img/` ➔ `public/assets/`

---

## 5. Análisis Competitivo y Roadmap Estratégico

Mesh LMS ha sido evaluado frente a soluciones líderes del mercado (como Thinkific, Kajabi o Moodle). Actualmente la plataforma se encuentra en un **92% de completitud empresarial**, destacando por ser **altamente competitiva** gracias a sus funciones nativas avanzadas (Rutas de aprendizaje, CMS integrado, gamificación y certificación dinámica) y su gran ventaja en UX (ligera y moderna frente a la rigidez de sistemas open-source antiguos).

### 🟢 Nivel 1: Esencial (100% Completado)
- Gestión de contenido (Módulos/Lecciones).
- Gestión de usuarios y perfiles.

### 🔵 Nivel 2: Avanzado y Diferenciador (85% Completado)
- Rutas de Aprendizaje (Learning Paths).
- Gamificación y XP (Logros).
- Certificados dinámicos con catálogo de firmantes.
- Evaluaciones y pruebas calificables (Quizzes).

### 🟡 Nivel 3: Marketing y E-Commerce (70% Completado)
- CMS para Landing Page (Estructuración del sitio totalmente gestionable, banner rotativo, características y testimonios).
- Bandeja CRM de prospectos (Captación de Leads nativa).
- Procesamiento manual/verificación de pagos.
- **Brechas a futuro**: Cupones de descuento, upsells y automatización de email marketing.

### 🟢 Nivel 4: Nivel Corporativo Complejo (80% Completado)
- **Logros Alcanzados:** 
  - **Entregables (Tareas):** Sistema donde el alumno puede subir archivos (PDF/Word) para ser calificados con retroalimentación escrita.
  - **Comunidad:** Foros o secciones de comentarios integrados nativamente dentro de la plataforma.
  - **Webinars Nativos:** Integración nativa y funcional con Zoom y Jitsi.
- **Brechas a futuro**: 
  - Tracking de video avanzado (minutos reproducidos, pausas).

---

## 6. Stack Tecnológico e Instalación

### Stack
- **Backend**: PHP 8.1+ (extensiones `pdo_mysql`, `gd`, `mbstring` y `zip`)
- **Base de Datos**: MySQL / MariaDB (vía PDO)
- **Frontend**: HTML5, Vanilla CSS, JavaScript Vanilla

### Cómo correr el proyecto
1. Clonar el repositorio en el servidor web (ej. `htdocs` en XAMPP o `/var/www/html`).
2. Copiar el archivo `.env.example` y renombrarlo a `.env`.
3. Configurar las variables en el `.env` (Credenciales DB, SMTP, URL Base y `CERT_SECRET`; ver los comentarios de `.env.example`).
4. Aplicar las migraciones de `database/migrations/` en el orden de su `README.md`.
5. Asegurarse de que las carpetas de subida (`uploads/`, `img/`, `contenido_h5p/`) tengan permisos de escritura.
6. El servidor web (ej. Apache) debe servir la carpeta raíz y permitir la lectura del archivo `.htaccess`. El archivo `bootstrap.php` es el punto de entrada principal para el nuevo código modular.

### Versiones
La versión en uso está en `APP_VERSION` (`config.php`) y se muestra al pie del menú del panel. El historial y lo necesario para actualizar cada versión están en `CHANGELOG.md`.
