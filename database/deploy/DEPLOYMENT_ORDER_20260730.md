# Despliegue Mesh LMS 2026-07-30

## Archivos que nunca deben reemplazarse

- `.env`
- El bloque de PHP/cPanel dentro de `.htaccess`
- `uploads/`
- `comprobantes/`
- `storage/`
- `vendor/`, salvo que se ejecute Composer expresamente
- `.well-known/`
- `cgi-bin/`
- `scc/`
- Archivos productivos que no estén en el manifiesto de esta versión

No subir archivos `scratch*`, `test*`, `debug*`, `dump*`, `auto_login.php`,
`db_update.php`, `fix_estado.php`, respaldos ZIP/SQL ni comprobantes locales.

## Orden

1. Habilitar mantenimiento.
2. Descargar un respaldo completo de la base y de `public_html`.
3. Guardar copias separadas de `.env`, `.htaccess`, `uploads/` y
   `comprobantes/`.
4. Ejecutar `20260730_production_upgrade.sql` desde phpMyAdmin o consola.
5. Confirmar que los dos controles de huérfanos del PRECHECK sean cero y
   revisar el POSTCHECK.
6. Subir primero los archivos nuevos que todavía no son invocados:
   - `admin/admin_auth.php`
   - `app/Commerce/Payments/PaymentService.php`
   - `app/Learning/Access/AccessService.php`
   - `app/Learning/Evaluations/QuizStart.php`
   - `app/Learning/Evaluations/QuizCierre.php`
   - `app/Learning/Evaluations/QuizAutoguardado.php`
   - `app/Learning/Evaluations/QuizOutboxConsumer.php`
   - `dashboard/quiz_api.php`
   - `js/quiz_engine.js`
   - `admin/firmantes.php`
7. Subir los helpers y pantallas administrativas:
   - `helpers/acceso_helper.php`
   - `admin/admin_header.php`
   - `admin/calificar_quizzes.php`
   - `admin/categorias.php`
   - `admin/configuracion.php`
   - `admin/cursos.php`
   - `admin/login.php`
   - `admin/modulos.php`
   - `admin/pagos.php`
   - `admin/quiz.php`
   - `admin/ruta_cursos.php`
   - `admin/rutas.php`
   - `admin/usuarios.php`
   - `admin/ver_excel.php`
   - `admin/verificar_pago.php`
8. Subir las pantallas del estudiante:
   - `dashboard/certificado.php`
   - `dashboard/certificado_ruta.php`
   - `dashboard/clase.php`
   - `dashboard/index.php`
   - `dashboard/logros.php`
   - `dashboard/perfil.php`
   - `dashboard/quiz_submit.php`
   - `dashboard/ruta.php`
9. Subir los puntos de entrada al final:
   - `config.php`
   - `mail_helper.php`
   - `pago.php`
   - `procesar_pago.php`
   - `ruta.php`
   - `rutas.php`
10. Añadir manualmente a `.env`:

    ```dotenv
    ACCESS_EXPIRATION_ENABLED=true
    ACCESS_DURATION_DAYS=30
    ```

11. Fusionar en `.htaccess`, sin sustituirlo, esta regla inmediatamente
    después de `RewriteEngine On`:

    ```apache
    RewriteRule ^verificar/([a-zA-Z0-9]+)$ verificar.php?codigo=$1 [L,QSA]
    ```

12. Limpiar caché/opcache desde el panel del hosting si está habilitado.
13. Ejecutar las pruebas de aceptación bajo mantenimiento.
14. Retirar el SQL del directorio público y desactivar mantenimiento.

## Controles de aceptación

- Inicio de sesión de admin, tesorería, docente y estudiante.
- Listados de cursos, rutas, usuarios, pagos e inscripciones.
- Visualización de comprobantes existentes.
- Verificación de un pago controlado.
- Creación conjunta de inscripción y acceso.
- Acceso a curso individual y a curso adquirido mediante ruta.
- Vigencia visible y bloqueo al vencer.
- Quiz automático aprobado y reprobado.
- Quiz manual pendiente y aprobación docente.
- Persistencia de los intentos y respuestas históricos.
- Progreso, logros y certificados.
- Ausencia de errores nuevos en `error_log`.

## Reversión

Mientras la plataforma continúe en mantenimiento, restaurar los archivos
anteriores si falla cualquier prueba. La base puede conservar el esquema
expandido porque las tablas y columnas antiguas no se eliminan. Si hubiera
escrituras incorrectas, restaurar el respaldo completo; no reconstruir tablas
manualmente.
