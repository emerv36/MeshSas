---
name: consultas-multiinstitucion
description: Cómo adaptar consultas SQL, inserciones, sesiones y archivos de MeshSas para que cada institución vea solo sus datos (filtro institucion_id). Usar al modificar cualquier archivo PHP que lea o escriba en la base o en disco durante las fases 2 a 4 de PLAN_SAAS.md.
---

# Consultas multi-institución en MeshSas

Cada petición pertenece a **una** institución, resuelta por el host en la fase 2 (`institucion_id()` en `helpers/institucion.php`). Ningún dato de otra institución puede leerse, modificarse ni deducirse.

## Reglas

1. **Lecturas, actualizaciones y borrados:** todo `SELECT`, `UPDATE` y `DELETE` sobre una tabla de la institución incluye `institucion_id = ?`, **aunque ya filtre por `id`**. Los `id` de la URL se manipulan.
   ```php
   // Antes
   $stmt = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
   $stmt->execute([$curso_id]);
   // Después
   $stmt = $pdo->prepare("SELECT * FROM cursos WHERE id = ? AND institucion_id = ?");
   $stmt->execute([$curso_id, institucion_id()]);
   ```
2. **Inserciones:** todo `INSERT` incluye la columna `institucion_id`. Nunca la tomes de `$_POST` ni de `$_GET`: viene siempre de `institucion_id()`.
3. **Consultas con JOIN:** filtra la tabla principal (`c.institucion_id = ?`). Si una tabla hija ya lleva la columna (decisión de la fase 1), puedes filtrarla también. Nunca relaciones por `id` con tablas de otra institución.
4. **Subconsultas, `EXISTS`, `IN (SELECT ...)` y `COUNT`:** también llevan el filtro. Ojo con los indicadores del panel (`index.php`, `metricas.php`) y con los contadores del menú (`admin_header.php`).
5. **`ON DUPLICATE KEY` e `INSERT IGNORE`:** las claves únicas son por institución, por ejemplo `UNIQUE(institucion_id, email)`. Comprueba que la clave compuesta exista.
6. **No encontrado:** un recurso que existe pero pertenece a otra institución responde **404**, igual que uno inexistente, para no revelar que existe.
7. **Configuración:** `ajustes` y `banner` se leen con `WHERE institucion_id = ?`. No se vuelven a usar `LIMIT 1` ni `WHERE id = 1`.
8. **Sesión:** al iniciar sesión se guarda `$_SESSION['institucion_id']`. `admin_auth.php` y las páginas del estudiante rechazan una sesión cuya institución no sea la del host.
9. **Archivos:**
   - Se guardan y leen con el helper de rutas por institución de la fase 4, nunca con `uploads/...` fijo.
   - Antes de servir un archivo (comprobante, tarea, H5P, descarga), comprueba que su fila en la base pertenezca a la institución.
10. **Procesos sin host:** los procesos CLI (cron, scripts) recorren las instituciones de forma explícita y fijan el contexto en cada iteración.

## Cómo trabajar un archivo
1. Lista sus consultas: `grep -nE "->(prepare|query|exec)\(" archivo.php`.
2. Clasifica cada tabla según la fase 1 (de la institución, hija o global).
3. Adapta las consultas y sus `execute([...])`, cuidando el orden de los parámetros.
4. Ejecuta `php -l archivo.php`.
5. Prueba con dos instituciones (skill `pruebas-aislamiento`): la de la sesión funciona y la otra da 404.
6. Al cerrar el módulo, pide la revisión al agente `auditor-aislamiento`.

## Errores típicos en este código
- Consultas armadas con concatenación, como en `gestionar_curso.php` (estadísticas con `intval($user_id)` concatenado) o en `clases_vivo.php`. Añade el filtro también ahí y, si puedes, pásalas a parámetros.
- `$pdo->query(...)` sin parámetros en los listados del admin: son los más fáciles de olvidar.
- Helpers que reciben un `id` y consultan sin contexto: `acceso_helper.php`, `certificado_helper.php`, `quiz_calificacion_helper.php`, `logros_helper.php` y `h5p_helper.php`.
- El motor de quizzes (`app/Learning/Evaluations/*`) recibe `$pdo` en el constructor. Decide si recibe también `institucion_id` o si lo toma del contexto, y aplícalo igual en todas sus clases.
